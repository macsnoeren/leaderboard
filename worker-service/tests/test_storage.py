"""T1.7: opslag: paden, veilige bestandsnamen en het opruimen van verlopen resultaten."""
import os
import unittest

from tests.helpers import make_env
from worker import db
from worker.storage import cleanup_results, safe_filename


class StorageTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.conn = db.connect(self.env.db_file)
        self.addCleanup(self.conn.close)

    def add_result(self, expires):
        job = db.create_job(self.conn, "game_export", {}, game_id=1, status="running")
        path = os.path.join(self.env.storage.job_dir(job), "x.tar.gz")
        with open(path, "wb") as f:
            f.write(b"x")
        db.finish_job(self.conn, job, result_file=self.env.storage.rel(path), result_name="x.tar.gz", result_size=1)
        self.conn.execute("UPDATE worker_jobs SET expires_at = datetime('now', ?) WHERE id = ?", (expires, job))
        return job, path

    def test_cleanup_expired_only(self):
        old, old_path = self.add_result("-1 minute")
        new, new_path = self.add_result("+1 day")
        self.assertEqual(cleanup_results(self.conn, self.env.storage), 1)
        self.assertFalse(os.path.exists(old_path))
        self.assertTrue(os.path.exists(new_path))
        self.assertIsNone(db.get_job(self.conn, old)["result_file"])
        self.assertEqual(db.get_job(self.conn, old)["status"], "done")
        self.assertIsNotNone(db.get_job(self.conn, new)["result_file"])

    def test_safe_filename(self):
        name = safe_filename("Operatie Ü/../x")
        self.assertNotIn("/", name)
        self.assertNotIn("..", name)
        self.assertEqual(name, "operatie-u-x")
        self.assertEqual(safe_filename(""), "spel")

    def test_abs_refuses_outside(self):
        with self.assertRaises(ValueError):
            self.env.storage.abs("../database/leaderboard.db")
        with self.assertRaises(ValueError):
            self.env.storage.abs("backups/x.tar.gz", under="incoming")
        self.assertTrue(self.env.storage.abs("incoming/a.tar.gz", under="incoming").endswith("incoming/a.tar.gz"))

    def test_clear_tmp(self):
        tmp = self.env.storage.make_tmp("job-1")
        open(os.path.join(tmp, "a"), "w").close()
        self.env.storage.clear_tmp()
        self.assertEqual(os.listdir(self.env.storage.tmp), [])


if __name__ == "__main__":
    unittest.main()
