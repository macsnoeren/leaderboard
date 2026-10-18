"""Backup maken, vrije ruimte, bewaartermijn na een backup, backup uploaden."""
import json
import os
import shutil
import sqlite3
import tarfile
import threading
import unittest
from unittest import mock

from tests.helpers import FIXTURES, make_env, seed_game
from worker import backups, db
from worker.jobs import Reporter, UserError, run_job
from worker.tasks import backup as backup_task


class BackupTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.ids = seed_game(self.env)
        self.conn = db.connect(self.env.db_file)
        self.addCleanup(self.conn.close)

    def members(self, path):
        with tarfile.open(path, "r:gz") as tar:
            return {m.name: tar.extractfile(m).read() for m in tar.getmembers() if m.isfile()}

    def test_contents(self):
        path, meta = backup_task.create_backup(self.env.cfg, self.env.storage, "manual", created_by=1)
        files = self.members(path)
        self.assertIn("database/leaderboard.db", files)
        self.assertIn("htdocs/artifacts/game_1_assignment_1_1.pdf", files)
        self.assertIn("htdocs/uploads/game_1_logo_1.png", files)
        self.assertFalse([n for n in files if "config" in n or n.startswith("storage")])
        manifest = json.loads(files["manifest.json"])
        self.assertEqual((manifest["format"], manifest["format_version"], manifest["trigger"]), ("operationroom-backup", 1, "manual"))
        self.assertEqual(manifest["counts"]["games"], 1)
        self.assertEqual(manifest["counts"]["teams"], 2)
        self.assertEqual(manifest["counts"]["files"], 2)
        self.assertIn("worker_jobs", manifest["schema"])
        # De database in de backup is gezond
        db_copy = os.path.join(self.env.root, "kopie.db")
        with open(db_copy, "wb") as f:
            f.write(files["database/leaderboard.db"])
        self.assertEqual(sqlite3.connect(db_copy).execute("PRAGMA integrity_check").fetchone()[0], "ok")
        # Metadata naast het archief
        listed = backups.list_backups(self.env.storage)
        self.assertEqual([b["file"] for b in listed], [os.path.basename(path)])
        self.assertEqual(listed[0]["meta"]["counts"], manifest["counts"])
        self.assertFalse(listed[0]["pinned"])

    def test_missing_referenced_file_is_warning(self):
        os.remove(os.path.join(self.env.htdocs, "artifacts/game_1_assignment_1_1.pdf"))
        reporter = Reporter()
        backup_task.create_backup(self.env.cfg, self.env.storage, "manual", reporter=reporter)
        self.assertEqual(len(reporter.warnings), 1)
        self.assertIn("ontbreekt", reporter.warnings[0])

    def test_writes_during_backup(self):
        stop = threading.Event()

        def writer():
            conn = sqlite3.connect(self.env.db_file, timeout=30)
            while not stop.is_set():
                conn.execute("INSERT INTO audit_logs (event_type, description) VALUES ('X', 'tijdens backup')")
                conn.commit()
            conn.close()

        t = threading.Thread(target=writer)
        t.start()
        try:
            path, _ = backup_task.create_backup(self.env.cfg, self.env.storage, "manual")
        finally:
            stop.set()
            t.join()
        manifest, db_path, _ = backup_task.validate_backup(self.env.cfg, path, self.env.storage.make_tmp("check"))
        self.assertEqual(manifest["format"], "operationroom-backup")

    def test_low_disk_space(self):
        env = make_env(self, BACKUP_MIN_FREE_MB=500)
        with mock.patch("shutil.disk_usage", return_value=mock.Mock(free=100 * 1024 * 1024)):
            with self.assertRaisesRegex(UserError, "schijfruimte"):
                backup_task.create_backup(env.cfg, env.storage, "scheduled")
        self.assertEqual(os.listdir(env.storage.backups), [])

    def test_job_applies_retention(self):
        env = make_env(self, BACKUP_KEEP=2)
        conn = db.connect(env.db_file)
        self.addCleanup(conn.close)
        for i in range(3):
            name = f"operationroom-backup-2026010{i}-030000.tar.gz"
            open(os.path.join(env.storage.backups, name), "wb").close()
            backups.write_meta(env.storage, name, {"trigger": "scheduled", "created_at": f"2026-01-0{i + 1} 01:00:00"})
        db.create_job(conn, "backup", {"trigger": "scheduled"})
        job = run_job(conn, env.cfg, env.storage, db.claim_next(conn, "w"))
        row = db.get_job(conn, job.id)
        self.assertEqual(row["status"], "done", row["message"])
        self.assertIsNone(row["expires_at"])
        self.assertEqual(len([b for b in backups.list_backups(env.storage) if b["trigger"] == "scheduled"]), 2)

    def test_upload_valid(self):
        path, _ = backup_task.create_backup(self.env.cfg, self.env.storage, "manual")
        upload = os.path.join(self.env.storage.incoming, "u.tar.gz")
        shutil.copy(path, upload)
        db.create_job(self.conn, "backup_upload", {"upload": "incoming/u.tar.gz", "original_name": "van-elders.tar.gz"}, created_by=1)
        job = run_job(self.conn, self.env.cfg, self.env.storage, db.claim_next(self.conn, "w"))
        self.assertEqual(db.get_job(self.conn, job.id)["status"], "done", db.get_job(self.conn, job.id)["message"])
        triggers = sorted(b["trigger"] for b in backups.list_backups(self.env.storage))
        self.assertEqual(triggers, ["manual", "uploaded"])
        self.assertFalse(os.path.exists(upload))

    def upload_fails(self, source):
        upload = os.path.join(self.env.storage.incoming, "u.tar.gz")
        shutil.copy(source, upload)
        before = sorted(os.listdir(self.env.storage.backups))
        db.create_job(self.conn, "backup_upload", {"upload": "incoming/u.tar.gz"}, created_by=1)
        job = run_job(self.conn, self.env.cfg, self.env.storage, db.claim_next(self.conn, "w"))
        row = db.get_job(self.conn, job.id)
        self.assertEqual(row["status"], "failed")
        self.assertEqual(sorted(os.listdir(self.env.storage.backups)), before)
        self.assertFalse(os.path.exists(upload))
        return row["message"]

    def test_old_name_still_valid(self):
        """Een backup van vóór de naamswijziging (zebrawave-backup-…, format zebrawave-backup) blijft bruikbaar."""
        path, _ = backup_task.create_backup(self.env.cfg, self.env.storage, "manual")
        old = os.path.join(self.env.storage.backups, "zebrawave-backup-20260101-030000.tar.gz")
        with tarfile.open(path, "r:gz") as src, tarfile.open(old, "w:gz") as dst:
            for m in src.getmembers():
                data = src.extractfile(m).read()
                if m.name == "manifest.json":
                    data = json.dumps(dict(json.loads(data), format="zebrawave-backup")).encode("utf-8")
                    m.size = len(data)
                import io  # noqa: PLC0415
                dst.addfile(m, io.BytesIO(data))
        shutil.copy(path + ".json", old + ".json")
        self.assertIn("zebrawave-backup-20260101-030000.tar.gz", [b["file"] for b in backups.list_backups(self.env.storage)])
        manifest, _, _ = backup_task.validate_backup(self.env.cfg, old, os.path.join(self.env.root, "check"))
        self.assertEqual(manifest["format"], "zebrawave-backup")

    def test_upload_game_export_refused(self):
        self.assertIn("spel-export", self.upload_fails(os.path.join(FIXTURES, "legacy_v1.tar.gz")))

    def test_upload_corrupt_backup_refused(self):
        path, _ = backup_task.create_backup(self.env.cfg, self.env.storage, "manual")
        broken = os.path.join(self.env.root, "kapot.tar.gz")
        with tarfile.open(path, "r:gz") as src, tarfile.open(broken, "w:gz") as dst:
            for m in src.getmembers():
                data = src.extractfile(m).read()
                if m.name == "htdocs/artifacts/game_1_assignment_1_1.pdf":
                    data = b"gewijzigd!" + data[10:]
                import io  # noqa: PLC0415
                dst.addfile(m, io.BytesIO(data))
        self.assertIn("Controlegetal", self.upload_fails(broken))


if __name__ == "__main__":
    unittest.main()
