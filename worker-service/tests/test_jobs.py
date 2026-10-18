"""Takenregister en Job-context: eindstatus, foutmeldingen, waarschuwingen, voortgang."""
import os
import unittest

from tests.helpers import make_env
from worker import db, jobs


class JobsTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.conn = db.connect(self.env.db_file)
        self.addCleanup(self.conn.close)
        self.addCleanup(lambda: [jobs.HANDLERS.pop(k, None) for k in ("t_ok", "t_user", "t_crash", "t_warn")])

    def run_type(self, job_type):
        db.create_job(self.conn, job_type, {"x": 1}, game_id=1)
        row = db.claim_next(self.conn, "w1")
        jobs.run_job(self.conn, self.env.cfg, self.env.storage, row, worker_id="w1")
        return db.get_job(self.conn, row["id"])

    def test_success_with_result(self):
        @jobs.register("t_ok")
        def ok(job):
            path = os.path.join(self.env.storage.job_dir(job.id), "uit.txt")
            with open(path, "w") as f:
                f.write("hallo")
            job.set_result(path, "uit.txt")

        row = self.run_type("t_ok")
        self.assertEqual(row["status"], "done")
        self.assertEqual(row["result_file"], f"jobs/{row['id']}/uit.txt")
        self.assertEqual(row["result_size"], 5)
        self.assertEqual(row["result_name"], "uit.txt")
        days = self.conn.execute("SELECT julianday(expires_at) - julianday('now') FROM worker_jobs WHERE id = ?",
                                 (row["id"],)).fetchone()[0]
        self.assertAlmostEqual(days, self.env.cfg.RESULT_TTL_DAYS, places=2)

    def test_user_error_shows_text(self):
        @jobs.register("t_user")
        def user(job):
            raise jobs.UserError("Spel 99 bestaat niet")

        row = self.run_type("t_user")
        self.assertEqual(row["status"], "failed")
        self.assertEqual(row["message"], "Spel 99 bestaat niet")

    def test_crash_hides_details(self):
        @jobs.register("t_crash")
        def crash(job):
            raise KeyError("geheime_kolom")

        with self.assertLogs("worker.jobs", level="ERROR"):
            row = self.run_type("t_crash")
        self.assertEqual(row["status"], "failed")
        self.assertNotIn("geheime_kolom", row["message"])
        self.assertIn("Interne fout", row["message"])

    def test_warnings_in_message(self):
        @jobs.register("t_warn")
        def warn(job):
            job.warn("Bijlage x.pdf ontbreekt")
            job.summary = "2 opdrachten"

        with self.assertLogs("worker.jobs", level="WARNING"):
            row = self.run_type("t_warn")
        self.assertEqual(row["status"], "done")
        self.assertIn("Bijlage x.pdf ontbreekt", row["message"])
        self.assertIn("2 opdrachten", row["message"])

    def test_unknown_type_fails(self):
        row = self.run_type("bestaat_niet")
        self.assertEqual(row["status"], "failed")

    def test_progress_is_throttled(self):
        job_id = db.create_job(self.conn, "x", {}, status="running")
        now = [100.0]
        job = jobs.Job(self.conn, db.get_job(self.conn, job_id), self.env.cfg, self.env.storage, clock=lambda: now[0])
        job.progress(10, "eerste")
        job.progress(50, "tweede")  # binnen een seconde: niet naar de database
        self.assertEqual(db.get_job(self.conn, job_id)["progress"], 10)
        now[0] += 1.5
        job.progress(60, "derde")
        row = db.get_job(self.conn, job_id)
        self.assertEqual((row["progress"], row["message"]), (60, "derde"))

    def test_audit_on_end(self):
        @jobs.register("t_ok")
        def ok(job):
            pass

        self.run_type("t_ok")
        self.assertEqual(self.conn.execute("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'JOB_DONE' AND user_id IS NULL").fetchone()[0], 1)


if __name__ == "__main__":
    unittest.main()
