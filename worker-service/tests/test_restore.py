"""T4.3–T4.5: restore: eerst controleren, dan vervangen, en terugrollen bij een fout (migrate.php gemockt)."""
import hashlib
import io
import json
import os
import sqlite3
import tarfile
import tempfile
import unittest
from unittest import mock

from tests.helpers import make_env, seed_game
from tests.schema import add_team
from worker import backups, db
from worker.jobs import run_job
from worker.tasks import restore as restore_task
from worker.tasks.backup import create_backup


def rewrite_backup(src, dst, change_db=None):
    """Kopie van een backup waarin de database is aangepast (met kloppend manifest)."""
    with tarfile.open(src, "r:gz") as tar:
        files = {m.name: tar.extractfile(m).read() for m in tar.getmembers() if m.isfile()}
    if change_db:
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "x.db")
            with open(path, "wb") as f:
                f.write(files["database/leaderboard.db"])
            files["database/leaderboard.db"] = change_db(path)
    manifest = json.loads(files["manifest.json"])
    data = files["database/leaderboard.db"]
    manifest["files"]["database/leaderboard.db"] = {"size": len(data), "sha256": hashlib.sha256(data).hexdigest()}
    files["manifest.json"] = json.dumps(manifest).encode()
    with tarfile.open(dst, "w:gz") as tar:
        for name, data in files.items():
            info = tarfile.TarInfo(name)
            info.size = len(data)
            tar.addfile(info, io.BytesIO(data))


class RestoreTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.ids = seed_game(self.env)
        self.backup_path, _ = create_backup(self.env.cfg, self.env.storage, "manual")
        self.backup_name = os.path.basename(self.backup_path)
        self.htdocs = self.env.htdocs
        self.inodes = {d: os.stat(os.path.join(self.htdocs, d)).st_ino for d in ("artifacts", "uploads")}
        # Na de backup verandert er van alles
        c = self.env.conn
        c.execute("UPDATE games SET name = 'Gewijzigd' WHERE id = ?", (self.ids["game"],))
        add_team(c, self.ids["game"], "Nieuw team")
        os.remove(os.path.join(self.htdocs, "artifacts/game_1_assignment_1_1.pdf"))
        self.env.write_file("artifacts/nieuw.pdf", b"%PDF nieuw")

    def state(self):
        conn = sqlite3.connect(self.env.db_file)
        try:
            name = conn.execute("SELECT name FROM games WHERE id = ?", (self.ids["game"],)).fetchone()[0]
            teams = sorted(r[0] for r in conn.execute("SELECT team_name FROM teams"))
        finally:
            conn.close()
        return name, teams, sorted(os.listdir(os.path.join(self.htdocs, "artifacts")))

    def run_restore(self, params):
        conn = db.connect(self.env.db_file)
        db.create_job(conn, "restore", dict({"username": "beheer"}, **params), created_by=self.ids["teacher"])
        job = run_job(conn, self.env.cfg, self.env.storage, db.claim_next(conn, "w"))
        row = db.get_job(job.conn, job.id)
        if job.conn is not conn:
            job.conn.close()
        conn.close()
        return row

    # T4.3 -----------------------------------------------------------------
    def assert_refused(self, archive, fragment):
        before = self.state()
        upload = os.path.join(self.env.storage.incoming, "u.tar.gz")
        os.replace(archive, upload)
        row = self.run_restore({"upload": "incoming/u.tar.gz"})
        self.assertEqual(row["status"], "failed")
        self.assertIn(fragment, row["message"])
        self.assertEqual(self.state(), before)
        self.assertFalse(os.path.exists(self.env.storage.maintenance_file))
        self.assertFalse(os.path.exists(upload))

    def test_corrupt_database_refused(self):
        bad = os.path.join(self.env.root, "bad.tar.gz")
        rewrite_backup(self.backup_path, bad, change_db=lambda p: b"SQLite format 3\x00" + b"\x00" * 4000)
        self.assert_refused(bad, "beschadigd")

    def test_wrong_checksum_refused(self):
        bad = os.path.join(self.env.root, "bad.tar.gz")
        with tarfile.open(self.backup_path, "r:gz") as src, tarfile.open(bad, "w:gz") as dst:
            for m in src.getmembers():
                data = src.extractfile(m).read()
                if m.name.startswith("htdocs/uploads/"):
                    data = data[::-1]
                dst.addfile(m, io.BytesIO(data))
        self.assert_refused(bad, "Controlegetal")

    def test_newer_schema_refused(self):
        def add_column(path):
            conn = sqlite3.connect(path)
            conn.execute("ALTER TABLE teams ADD COLUMN toekomst TEXT")
            conn.commit()
            conn.close()
            return open(path, "rb").read()

        bad = os.path.join(self.env.root, "bad.tar.gz")
        rewrite_backup(self.backup_path, bad, change_db=add_column)
        self.assert_refused(bad, "nieuwere versie")

    # T4.4 -----------------------------------------------------------------
    def test_restore_replaces_everything(self):
        with mock.patch.object(restore_task, "run_migrate") as migrate:
            row = self.run_restore({"backup": self.backup_name})
        self.assertEqual(row["status"], "done", row["message"])
        migrate.assert_called_once()
        name, teams, artifacts = self.state()
        self.assertEqual(name, "Operatie Zebra")
        self.assertNotIn("Nieuw team", teams)
        self.assertEqual(artifacts, ["game_1_assignment_1_1.pdf"])
        self.assertEqual({d: os.stat(os.path.join(self.htdocs, d)).st_ino for d in ("artifacts", "uploads")}, self.inodes)
        self.assertFalse(os.path.exists(self.env.storage.maintenance_file))
        triggers = sorted(b["trigger"] for b in backups.list_backups(self.env.storage))
        self.assertEqual(triggers, ["manual", "pre_restore"])
        # De restore-taak staat als klaar in de teruggezette database, met een auditregel
        conn = sqlite3.connect(self.env.db_file)
        jobs = conn.execute("SELECT type, status FROM worker_jobs").fetchall()
        audit = conn.execute("SELECT description FROM audit_logs WHERE event_type = 'BACKUP_RESTORE'").fetchone()[0]
        conn.close()
        self.assertIn(("restore", "done"), jobs)
        self.assertIn("beheer", audit)
        self.assertEqual(os.listdir(self.env.storage.tmp), [])

    def test_backup_job_is_done_after_restoring_its_backup(self):
        conn = db.connect(self.env.db_file)
        db.create_job(conn, "backup", {"trigger": "manual"})
        job = run_job(conn, self.env.cfg, self.env.storage, db.claim_next(conn, "w"))
        conn.close()
        name = os.path.basename(job.result_path)
        with mock.patch.object(restore_task, "run_migrate"):
            row = self.run_restore({"backup": name})
        self.assertEqual(row["status"], "done", row["message"])
        restored = sqlite3.connect(self.env.db_file)
        status, result = restored.execute("SELECT status, result_file FROM worker_jobs WHERE id = ?", (job.id,)).fetchone()
        restored.close()
        self.assertEqual((status, result), ("done", f"backups/{name}"))

    def test_safety_backup_contains_state_before_restore(self):
        with mock.patch.object(restore_task, "run_migrate"):
            self.run_restore({"backup": self.backup_name})
        safety = [b for b in backups.list_backups(self.env.storage) if b["trigger"] == "pre_restore"][0]
        self.assertEqual(safety["meta"]["counts"]["teams"], 3)

    # T4.5 -----------------------------------------------------------------
    def test_rollback_after_failed_migrate(self):
        before = self.state()
        with mock.patch.object(restore_task, "run_migrate", side_effect=[RuntimeError("migrate.php mislukt: kapot"), None]):
            with self.assertLogs("worker.tasks.restore", level="ERROR"):
                row = self.run_restore({"backup": self.backup_name})
        self.assertEqual(row["status"], "failed")
        self.assertIn("vorige toestand is hersteld", row["message"])
        self.assertEqual(self.state(), before)
        self.assertFalse(os.path.exists(self.env.storage.maintenance_file))

    def test_rollback_fails_keeps_maintenance(self):
        with mock.patch.object(restore_task, "run_migrate", side_effect=RuntimeError("php ontbreekt")):
            with self.assertLogs("worker.tasks.restore", level="ERROR"):
                row = self.run_restore({"backup": self.backup_name})
        self.assertEqual(row["status"], "failed")
        with open(self.env.storage.maintenance_file) as f:
            info = json.load(f)
        self.assertIn("php ontbreekt", info["error"])
        self.assertIn("safety_backup", info)

    def test_run_migrate_detects_failure(self):
        env = self.env
        script = os.path.join(env.root, "fake-php")
        with open(script, "w") as f:
            f.write("#!/bin/sh\necho 'Migratie mislukt: database is locked'\nexit 1\n")
        os.chmod(script, 0o755)
        env.cfg.PHP_BIN = script
        with self.assertRaisesRegex(RuntimeError, "database is locked"):
            restore_task.run_migrate(env.cfg)
        with open(script, "w") as f:
            f.write("#!/bin/sh\necho 'Migratie succesvol uitgevoerd.'\n")
        restore_task.run_migrate(env.cfg)


if __name__ == "__main__":
    unittest.main()
