"""T1.5: wachtrij: atomaire claim, exclusieve restore, verweesde taken."""
import threading
import unittest

from tests.helpers import make_env
from worker import db


class DbTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.conn = db.connect(self.env.db_file)
        self.addCleanup(self.conn.close)

    def test_claim_marks_running(self):
        job = db.create_job(self.conn, "report", {"game_id": 1}, game_id=1)
        row = db.claim_next(self.conn, "w1")
        self.assertEqual(row["id"], job)
        self.assertEqual(row["status"], "running")
        self.assertEqual(row["worker_id"], "w1")
        self.assertIsNone(db.claim_next(self.conn, "w1"))

    def test_concurrent_claims_never_share_a_job(self):
        for i in range(20):
            db.create_job(self.conn, "report", {"i": i}, game_id=1)
        claimed = {"a": [], "b": []}

        def work(name):
            conn = db.connect(self.env.db_file)
            while True:
                row = db.claim_next(conn, name)
                if row is None:
                    break
                claimed[name].append(row["id"])
            conn.close()

        threads = [threading.Thread(target=work, args=(n,)) for n in claimed]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        ids = claimed["a"] + claimed["b"]
        self.assertEqual(len(ids), 20)
        self.assertEqual(len(set(ids)), 20)

    def test_queued_restore_blocks_other_jobs(self):
        restore = db.create_job(self.conn, "restore", {"backup": "x"})
        db.create_job(self.conn, "report", {}, game_id=1)
        running = db.create_job(self.conn, "game_export", {}, game_id=1, status="running")
        # Er draait iets: de restore wacht en de report mag ook niet voorkruipen
        self.assertIsNone(db.claim_next(self.conn, "w1"))
        db.finish_job(self.conn, running)
        row = db.claim_next(self.conn, "w1")
        self.assertEqual(row["id"], restore)

    def test_restore_not_claimed_while_other_job_runs(self):
        db.create_job(self.conn, "report", {}, game_id=1, status="running")
        db.create_job(self.conn, "restore", {"backup": "x"})
        self.assertIsNone(db.claim_next(self.conn, "w1"))

    def test_nothing_claimed_while_restore_runs(self):
        db.create_job(self.conn, "restore", {}, status="running")
        db.create_job(self.conn, "report", {}, game_id=1)
        self.assertIsNone(db.claim_next(self.conn, "w1"))

    def test_backup_waits_but_others_pass(self):
        db.create_job(self.conn, "report", {}, game_id=1, status="running")
        db.create_job(self.conn, "backup", {"trigger": "manual"})
        export = db.create_job(self.conn, "game_export", {}, game_id=1)
        self.assertEqual(db.claim_next(self.conn, "w1")["id"], export)

    def test_orphans(self):
        old = db.create_job(self.conn, "report", {}, game_id=1, status="running")
        recent = db.create_job(self.conn, "report", {}, game_id=1, status="running")
        self.conn.execute("UPDATE worker_jobs SET heartbeat_at = datetime('now', '-11 minutes') WHERE id = ?", (old,))
        self.conn.execute("UPDATE worker_jobs SET heartbeat_at = datetime('now', '-9 minutes') WHERE id = ?", (recent,))
        self.assertEqual(db.fail_orphans(self.conn), 1)
        self.assertEqual(db.get_job(self.conn, old)["status"], "failed")
        self.assertIn("onderbroken", db.get_job(self.conn, old)["message"].lower())
        self.assertEqual(db.get_job(self.conn, recent)["status"], "running")

    def test_finish_sets_expiry(self):
        job = db.create_job(self.conn, "report", {}, game_id=1, status="running")
        db.finish_job(self.conn, job, result_file="jobs/1/x.pdf", result_name="x.pdf", result_size=3, expires_days=7)
        row = self.conn.execute("SELECT status, julianday(expires_at) - julianday('now') AS days FROM worker_jobs WHERE id = ?",
                                (job,)).fetchone()
        self.assertEqual(row["status"], "done")
        self.assertAlmostEqual(row["days"], 7, places=2)

    def test_worker_heartbeat_upsert(self):
        db.worker_heartbeat(self.conn, "w1", "1.0")
        db.worker_heartbeat(self.conn, "w1", "1.0", current_job_id=5)
        rows = self.conn.execute("SELECT * FROM worker_status").fetchall()
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["current_job_id"], 5)
        db.unregister(self.conn, "w1")
        self.assertEqual(self.conn.execute("SELECT COUNT(*) FROM worker_status").fetchone()[0], 0)


if __name__ == "__main__":
    unittest.main()
