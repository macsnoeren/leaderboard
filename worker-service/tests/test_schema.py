"""Het testschema maakt alle tabellen die de worker raakt."""
import os
import tempfile
import unittest

from tests import schema


class SchemaTest(unittest.TestCase):
    def test_tables(self):
        with tempfile.TemporaryDirectory() as d:
            conn = schema.fresh_db(os.path.join(d, "t.db"))
            tables = {r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type = 'table'")}
            conn.close()
        for table in ("games", "teams", "assignments", "completed_assignments", "team_messages", "ai_evaluations",
                      "audit_logs", "worker_jobs", "worker_status"):
            self.assertIn(table, tables)


if __name__ == "__main__":
    unittest.main()
