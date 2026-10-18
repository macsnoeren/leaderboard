"""Bewaartermijnen voor backups (tabelgestuurd) en het toepassen ervan."""
import types
import unittest
from datetime import datetime, timedelta, timezone

from tests.helpers import make_env
from worker import backups, retention

NOW = datetime(2026, 10, 18, 12, 0, tzinfo=timezone.utc)
CFG = types.SimpleNamespace(BACKUP_KEEP=14, BACKUP_MANUAL_DAYS=90, BACKUP_PRE_RESTORE_DAYS=30)


def b(name, trigger, days_ago, pinned=False):
    return {"file": name, "trigger": trigger, "created_at": NOW - timedelta(days=days_ago), "pinned": pinned}


class RetentionTest(unittest.TestCase):
    def test_keep_last_scheduled(self):
        items = [b(f"s{i:02d}", "scheduled", i) for i in range(16)]
        self.assertEqual(sorted(retention.to_delete(items, NOW, CFG)), ["s14", "s15"])

    def test_cases(self):
        cases = [
            ("handmatig 91 dagen weg", [b("new", "scheduled", 0), b("m", "manual", 91)], ["m"]),
            ("handmatig 89 dagen blijft", [b("new", "scheduled", 0), b("m", "manual", 89)], []),
            ("geüpload als handmatig", [b("new", "scheduled", 0), b("u", "uploaded", 91)], ["u"]),
            ("veiligheid 31 dagen weg", [b("new", "scheduled", 0), b("p", "pre_restore", 31)], ["p"]),
            ("veiligheid 29 dagen blijft", [b("new", "scheduled", 0), b("p", "pre_restore", 29)], []),
            ("vastgezet blijft", [b("new", "scheduled", 0), b("m", "manual", 365, pinned=True)], []),
            ("nieuwste blijft altijd", [b("m1", "manual", 200), b("m2", "manual", 300)], ["m2"]),
            ("leeg", [], []),
        ]
        for label, items, expected in cases:
            with self.subTest(label):
                self.assertEqual(sorted(retention.to_delete(items, NOW, CFG)), expected)

    def test_zero_means_never(self):
        cfg = types.SimpleNamespace(BACKUP_KEEP=0, BACKUP_MANUAL_DAYS=0, BACKUP_PRE_RESTORE_DAYS=0)
        items = [b(f"s{i}", "scheduled", i) for i in range(30)] + [b("m", "manual", 999), b("p", "pre_restore", 999)]
        self.assertEqual(retention.to_delete(items, NOW, cfg), [])

    def test_pinned_scheduled_counts_but_stays(self):
        cfg = types.SimpleNamespace(BACKUP_KEEP=2, BACKUP_MANUAL_DAYS=90, BACKUP_PRE_RESTORE_DAYS=30)
        items = [b("s0", "scheduled", 0), b("s1", "scheduled", 1, pinned=True), b("s2", "scheduled", 2), b("s3", "scheduled", 3)]
        self.assertEqual(sorted(retention.to_delete(items, NOW, cfg)), ["s2", "s3"])


class ApplyTest(unittest.TestCase):
    def test_apply_removes_files_and_audits(self):
        env = make_env(self, BACKUP_KEEP=2)
        for i in range(4):
            name = f"operationroom-backup-2026101{i}-030000.tar.gz"
            open(f"{env.storage.backups}/{name}", "wb").close()
            backups.write_meta(env.storage, name, {"trigger": "scheduled", "created_at": f"2026-10-1{i} 01:00:00"})
        removed = retention.apply(env.cfg, env.storage, env.conn, now=NOW)
        self.assertEqual(sorted(removed), ["operationroom-backup-20261010-030000.tar.gz", "operationroom-backup-20261011-030000.tar.gz"])
        self.assertEqual(len(backups.list_backups(env.storage)), 2)
        self.assertEqual(env.conn.execute("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'BACKUP_DELETE'").fetchone()[0], 2)


if __name__ == "__main__":
    unittest.main()
