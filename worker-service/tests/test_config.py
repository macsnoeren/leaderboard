"""Configuratie met standaardwaarden en overschrijven via config.py."""
import os
import tempfile
import types
import unittest

from worker import config


class ConfigTest(unittest.TestCase):
    def test_defaults_without_config_file(self):
        with tempfile.TemporaryDirectory() as d:
            cfg = config.load(base_dir=d)
        self.assertEqual(cfg.POLL_INTERVAL, 5)
        self.assertEqual(cfg.RESULT_TTL_DAYS, 7)
        self.assertEqual(cfg.BACKUP_KEEP, 14)
        self.assertEqual(cfg.BACKUP_TIME, "03:00")
        self.assertEqual(cfg.DB_FILE, os.path.abspath(os.path.join(d, "../database/leaderboard.db")))

    def test_config_file_overrides(self):
        with tempfile.TemporaryDirectory() as d:
            with open(os.path.join(d, "config.py"), "w") as f:
                f.write("POLL_INTERVAL = 9\nSTORAGE_DIR = 'opslag'\n")
            cfg = config.load(base_dir=d)
            self.assertEqual(cfg.POLL_INTERVAL, 9)
            self.assertEqual(cfg.STORAGE_DIR, os.path.join(d, "opslag"))
            self.assertEqual(cfg.BACKUP_KEEP, 14)

    def test_empty_backup_time_means_no_planner(self):
        cfg = config.load(module=types.SimpleNamespace(BACKUP_TIME=""), base_dir="/tmp")
        self.assertEqual(cfg.BACKUP_TIME, "")

    def test_app_version_from_setting(self):
        cfg = config.load(module=None, base_dir="/tmp", APP_VERSION="abc1234")
        self.assertEqual(config.app_version(cfg), "abc1234")

    def test_app_version_unknown_without_git(self):
        with tempfile.TemporaryDirectory() as d:
            cfg = config.load(module=None, base_dir=d, APP_DIR=d)
            self.assertEqual(config.app_version(cfg), "onbekend")


if __name__ == "__main__":
    unittest.main()
