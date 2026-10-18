"""De hoofdlus: één taak per ronde, heartbeat, stopvlag, opruimen bij de start."""
import os
import unittest

from main import Worker
from tests.helpers import make_env
from worker import db, jobs


class MainTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self, BACKUP_TIME="")
        self.worker = Worker(self.env.cfg, worker_id="w-test")
        self.ran = []

        @jobs.register("t_main")
        def handler(job):
            self.ran.append(job.id)

        self.addCleanup(lambda: jobs.HANDLERS.pop("t_main", None))

    def test_run_once_runs_one_job(self):
        a = db.create_job(self.env.conn, "t_main", {})
        b = db.create_job(self.env.conn, "t_main", {})
        self.assertTrue(self.worker.run_once())
        self.assertEqual(self.ran, [a])
        self.assertTrue(self.worker.run_once())
        self.assertEqual(self.ran, [a, b])
        self.assertFalse(self.worker.run_once())
        self.assertEqual(db.get_job(self.env.conn, a)["status"], "done")

    def test_heartbeat_row(self):
        self.worker.run_once()
        row = self.env.conn.execute("SELECT * FROM worker_status").fetchone()
        self.assertEqual(row["worker_id"], "w-test")
        self.worker.unregister()
        self.assertIsNone(self.env.conn.execute("SELECT * FROM worker_status").fetchone())

    def test_stop_flag_prevents_new_job(self):
        db.create_job(self.env.conn, "t_main", {})
        self.worker.stop_event.set()
        self.assertFalse(self.worker.run_once())
        self.assertEqual(self.ran, [])

    def test_startup_fails_orphans_and_clears_tmp(self):
        job = db.create_job(self.env.conn, "t_main", {}, status="running")
        self.env.conn.execute("UPDATE worker_jobs SET heartbeat_at = datetime('now', '-1 hour') WHERE id = ?", (job,))
        open(os.path.join(self.env.storage.tmp, "rest"), "w").close()
        self.worker.startup()
        self.assertEqual(db.get_job(self.env.conn, job)["status"], "failed")
        self.assertEqual(os.listdir(self.env.storage.tmp), [])

    def test_run_loop_stops(self):
        db.create_job(self.env.conn, "t_main", {})

        def stop_after(job):
            self.ran.append(job.id)
            self.worker.stop_event.set()

        jobs.HANDLERS["t_main"] = stop_after
        self.worker.run()
        self.assertEqual(len(self.ran), 1)
        self.assertIsNone(self.env.conn.execute("SELECT * FROM worker_status").fetchone())


if __name__ == "__main__":
    unittest.main()
