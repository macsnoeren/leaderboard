"""De commandoregel."""
import contextlib
import io
import os
import unittest
from unittest import mock

from tests.helpers import FIXTURES, make_env, seed_game
from worker import cli
from worker.tasks import restore as restore_task


class CliTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.ids = seed_game(self.env)

    def run_cli(self, *argv):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = cli.main(list(argv), cfg=self.env.cfg)
        return code, out.getvalue(), err.getvalue()

    def count(self, table):
        return self.env.conn.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]

    def test_inspect_formats_without_changes(self):
        before = (self.count("games"), self.count("worker_jobs"))
        for name, fmt in (("legacy_v1.tar.gz", "v1"), ("legacy_v2.tar.gz", "v2")):
            code, out, _ = self.run_cli("inspect", os.path.join(FIXTURES, name))
            self.assertEqual(code, 0)
            self.assertIn(f"Formaat: {fmt}", out)
            self.assertIn("Opdrachten: 2, bestanden: 1", out)
        target = os.path.join(self.env.root, "spel.tar.gz")
        self.assertEqual(self.run_cli("export-game", "--game", str(self.ids["game"]), "-o", target)[0], 0)
        before = (self.count("games"), self.count("worker_jobs"))
        code, out, _ = self.run_cli("inspect", target)
        self.assertIn("Formaat: v3", out)
        self.assertIn("Spel: Operatie Zebra", out)
        self.assertIn("Opdrachten: 2, bestanden: 2", out)
        self.assertEqual((self.count("games"), self.count("worker_jobs")), before)

    def test_export_unknown_game(self):
        code, _, err = self.run_cli("export-game", "--game", "99")
        self.assertEqual(code, 1)
        self.assertIn("spel 99 bestaat niet", err.lower())

    def test_export_writes_output_and_job_row(self):
        target = os.path.join(self.env.root, "uit.tar.gz")
        code, out, err = self.run_cli("export-game", "--game", str(self.ids["game"]), "--with-progress", "-o", target)
        self.assertEqual(code, 0, err)
        self.assertTrue(os.path.isfile(target))
        row = self.env.conn.execute("SELECT * FROM worker_jobs ORDER BY id DESC").fetchone()
        self.assertEqual((row["type"], row["status"], row["created_by"]), ("game_export", "done", None))
        self.assertEqual(self.count("worker_status"), 0)

    def test_import_keeps_original_file(self):
        src = os.path.join(FIXTURES, "legacy_v1.tar.gz")
        code, out, err = self.run_cli("import-game", src, "--name", "Uit de CLI")
        self.assertEqual(code, 0, err)
        self.assertTrue(os.path.isfile(src))
        self.assertIn("Nieuw spel: #", out)
        self.assertEqual(self.env.conn.execute("SELECT COUNT(*) FROM games WHERE name = 'Uit de CLI'").fetchone()[0], 1)

    def test_inspect_bad_file(self):
        bad = os.path.join(self.env.root, "kapot.tar.gz")
        with open(bad, "wb") as f:
            f.write(b"niets")
        code, _, err = self.run_cli("inspect", bad)
        self.assertEqual(code, 1)
        self.assertIn("geen geldig", err)

    def test_backup_and_restore(self):
        target = os.path.join(self.env.root, "backup.tar.gz")
        code, out, err = self.run_cli("backup", "-o", target)
        self.assertEqual(code, 0, err)
        self.assertTrue(os.path.isfile(target))
        code, out, _ = self.run_cli("inspect", target)
        self.assertIn("Formaat: backup", out)
        self.env.conn.execute("UPDATE games SET name = 'Na de backup'")
        # Zonder --yes verandert er niets
        code, _, err = self.run_cli("restore", target)
        self.assertEqual(code, 2)
        self.assertIn("--yes", err)
        self.assertEqual(self.env.conn.execute("SELECT name FROM games").fetchone()[0], "Na de backup")
        with mock.patch.object(restore_task, "run_migrate"):
            code, out, err = self.run_cli("restore", target, "--yes")
        self.assertEqual(code, 0, err)
        self.assertTrue(os.path.isfile(target))
        import sqlite3  # noqa: PLC0415
        self.assertEqual(sqlite3.connect(self.env.db_file).execute("SELECT name FROM games").fetchone()[0], "Operatie Zebra")


if __name__ == "__main__":
    unittest.main()
