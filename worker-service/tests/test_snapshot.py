"""T1.10: momentopname van de database."""
import os
import sqlite3
import threading
import time
import unittest

from tests.helpers import make_env
from worker.snapshot import open_snapshot, snapshot


class SnapshotTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.env.conn.execute("INSERT INTO games (name) VALUES ('Een')")

    def test_copy_has_same_rows_and_ignores_later_writes(self):
        with open_snapshot(self.env.db_file, self.env.storage.tmp) as snap:
            self.env.conn.execute("INSERT INTO games (name) VALUES ('Twee')")
            names = [r["name"] for r in snap.execute("SELECT name FROM games")]
        self.assertEqual(names, ["Een"])
        self.assertEqual(os.listdir(self.env.storage.tmp), [])

    def test_waits_for_open_write_transaction(self):
        writer = sqlite3.connect(self.env.db_file, isolation_level=None, check_same_thread=False)
        writer.execute("BEGIN EXCLUSIVE")
        writer.execute("INSERT INTO games (name) VALUES ('Tijdens')")

        def commit_later():
            time.sleep(0.5)
            writer.execute("COMMIT")
            writer.close()

        t = threading.Thread(target=commit_later)
        t.start()
        target = os.path.join(self.env.storage.tmp, "s.db")
        start = time.monotonic()
        snapshot(self.env.db_file, target)
        t.join()
        self.assertGreaterEqual(time.monotonic() - start, 0.4)
        names = [r[0] for r in sqlite3.connect(target).execute("SELECT name FROM games ORDER BY id")]
        self.assertEqual(names, ["Een", "Tijdens"])

    def test_gives_up_after_timeout(self):
        writer = sqlite3.connect(self.env.db_file, isolation_level=None)
        writer.execute("BEGIN EXCLUSIVE")
        try:
            with self.assertRaises((TimeoutError, sqlite3.OperationalError)):
                snapshot(self.env.db_file, os.path.join(self.env.storage.tmp, "s.db"), timeout=0.3)
        finally:
            writer.execute("ROLLBACK")
            writer.close()


if __name__ == "__main__":
    unittest.main()
