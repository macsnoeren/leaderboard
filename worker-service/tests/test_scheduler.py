"""Planner voor de dagelijkse backup."""
import unittest
from datetime import datetime, timedelta, timezone

from tests.helpers import make_env
from worker import backups, db, scheduler

TZ = timezone(timedelta(hours=2))


def at(h, m=0, day=18):
    return datetime(2026, 10, day, h, m, tzinfo=TZ)


class DueTest(unittest.TestCase):
    def test_cases(self):
        cases = [
            ("02:59 → nee", at(2, 59), "03:00", None, False),
            ("03:00 → ja", at(3, 0), "03:00", None, True),
            ("03:00 met een backup van 03:00 vandaag → nee", at(3, 0), "03:00", at(3, 0), False),
            ("14:00 zonder backup vandaag (herstart) → ja", at(14), "03:00", at(3, 0, day=17), True),
            ("backup van gisteren 23:00 telt niet voor vandaag", at(4), "03:00", at(23, day=17), True),
            ("leeg → nooit", at(14), "", None, False),
            ("ongeldig → nooit", at(14), "25:99", None, False),
        ]
        for label, now, time_, last, expected in cases:
            with self.subTest(label):
                self.assertEqual(scheduler.due(now, time_, last), expected)


class EnqueueTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self, BACKUP_TIME="03:00")
        self.conn = db.connect(self.env.db_file)
        self.addCleanup(self.conn.close)

    def test_enqueues_once(self):
        now = datetime.now(timezone.utc).astimezone().replace(hour=14)
        job = scheduler.maybe_enqueue(self.conn, self.env.cfg, self.env.storage, now_local=now)
        self.assertIsNotNone(job)
        self.assertEqual(db.job_params(db.get_job(self.conn, job)), {"trigger": "scheduled"})
        # Zelfde dag nog eens: de taak telt als "al gedaan", ook als hij mislukt
        db.fail_job(self.conn, job, "schijf vol")
        self.assertIsNone(scheduler.maybe_enqueue(self.conn, self.env.cfg, self.env.storage, now_local=now))

    def test_existing_scheduled_backup_counts(self):
        now = datetime.now(timezone.utc).astimezone().replace(hour=14)
        name = "operationroom-backup-x.tar.gz"
        open(f"{self.env.storage.backups}/{name}", "wb").close()
        created = now.replace(hour=3, minute=5).astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        backups.write_meta(self.env.storage, name, {"trigger": "scheduled", "created_at": created})
        self.assertIsNone(scheduler.maybe_enqueue(self.conn, self.env.cfg, self.env.storage, now_local=now))

    def test_not_while_backup_queued(self):
        db.create_job(self.conn, "backup", {"trigger": "manual"})
        now = datetime.now(timezone.utc).astimezone().replace(hour=14)
        self.assertIsNone(scheduler.maybe_enqueue(self.conn, self.env.cfg, self.env.storage, now_local=now))


if __name__ == "__main__":
    unittest.main()
