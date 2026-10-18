"""Import als nieuw spel, zonder en met voortgang."""
import os
import shutil
import tempfile
import unittest
from unittest import mock

from tests.helpers import FIXTURES, make_env, seed_game
from tests.schema import add_teacher
from tests.test_legacy import make_archive
from worker import db
from worker.jobs import Reporter, UserError, run_job
from worker.tasks import import_game as imp
from worker.tasks.export_game import export_game


class ImportTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.conn = db.connect(self.env.db_file)
        self.addCleanup(self.conn.close)
        self.admin = add_teacher(self.conn, "beheer", role="admin")
        self.tmp = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, self.tmp)

    def do_import(self, path, name=None, reporter=None):
        reporter = reporter or Reporter()
        game_id = imp.import_archive(self.conn, self.env.htdocs, path, reporter, name=name, created_by=self.admin)
        return game_id, reporter

    def artifacts(self):
        return sorted(os.listdir(os.path.join(self.env.htdocs, "artifacts")))

    def test_v1_becomes_new_game(self):
        game_id, _ = self.do_import(os.path.join(FIXTURES, "legacy_v1.tar.gz"), name="Klas 2B")
        game = self.conn.execute("SELECT * FROM games WHERE id = ?", (game_id,)).fetchone()
        self.assertEqual((game["name"], game["status"], game["registration_open"]), ("Klas 2B", "draft", 0))
        self.assertRegex(game["join_code"], r"^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$")
        rows = self.conn.execute("SELECT * FROM assignments WHERE game_id = ? ORDER BY assignment_number", (game_id,)).fetchall()
        self.assertEqual([r["title"] for r in rows], ["De sleutel", "Het motief"])
        self.assertRegex(rows[0]["artifact_file"], rf"^artifacts/game_{game_id}_assignment_1_\d+_[0-9a-f]{{6}}\.txt$")
        with open(os.path.join(self.env.htdocs, rows[0]["artifact_file"]), encoding="utf-8") as f:
            self.assertIn("conciërge", f.read())
        self.assertIsNone(rows[1]["artifact_file"])
        self.assertEqual(self.conn.execute("SELECT teacher_id FROM game_teachers WHERE game_id = ?", (game_id,)).fetchone()[0], self.admin)

    def test_same_name_gets_suffix(self):
        first, _ = self.do_import(os.path.join(FIXTURES, "legacy_v2.tar.gz"), name="Klas 2B")
        second, _ = self.do_import(os.path.join(FIXTURES, "legacy_v2.tar.gz"), name="Klas 2B")
        names = [self.conn.execute("SELECT name FROM games WHERE id = ?", (g,)).fetchone()[0] for g in (first, second)]
        self.assertEqual(names, ["Klas 2B", "Klas 2B (2)"])

    def test_renumber_with_warning(self):
        path = os.path.join(self.tmp, "a.tar.gz")
        make_archive(path, [{"assignment_number": n, "title": f"T{i}"} for i, n in enumerate([1, 3, 3])])
        game_id, reporter = self.do_import(path, name="X")
        rows = self.conn.execute("SELECT assignment_number, title FROM assignments WHERE game_id = ? ORDER BY assignment_number",
                                 (game_id,)).fetchall()
        self.assertEqual([tuple(r) for r in rows], [(1, "T0"), (2, "T1"), (3, "T2")])
        self.assertEqual(len(reporter.warnings), 1)
        self.assertIn("hernummerd", reporter.warnings[0])

    def test_php_attachment_refused(self):
        path = os.path.join(self.tmp, "a.tar.gz")
        make_archive(path, [{"assignment_number": 1, "title": "T", "artifact_file": "shell.php"}], {"files/shell.php": b"<?php"})
        game_id, reporter = self.do_import(path, name="X")
        self.assertEqual(self.artifacts(), [])
        self.assertIsNone(self.conn.execute("SELECT artifact_file FROM assignments WHERE game_id = ?", (game_id,)).fetchone()[0])
        self.assertIn("niet toegestaan", reporter.warnings[0])

    def test_failure_leaves_nothing(self):
        before = self.conn.execute("SELECT COUNT(*) FROM games").fetchone()[0]
        with mock.patch.object(imp, "import_progress", side_effect=RuntimeError("halverwege")):
            path = os.path.join(self.tmp, "a.tar.gz")
            make_archive(path, {"format": "operationroom-game", "format_version": 3, "include_progress": True, "game": {"name": "X"},
                                "assignments": [{"assignment_number": 1, "title": "T", "artifact_file": "a.pdf"}], "files": {}},
                         {"files/a.pdf": b"%PDF", "progress.json": b'{"teams": []}'})
            with self.assertRaises(RuntimeError):
                self.do_import(path)
        self.assertEqual(self.conn.execute("SELECT COUNT(*) FROM games").fetchone()[0], before)
        self.assertEqual(self.conn.execute("SELECT COUNT(*) FROM assignments").fetchone()[0], 0)
        self.assertEqual(self.artifacts(), [])

    def test_existing_games_untouched(self):
        ids = seed_game(self.env)
        before = [tuple(r) for r in self.conn.execute("SELECT * FROM assignments WHERE game_id = ?", (ids["game"],))]
        self.do_import(os.path.join(FIXTURES, "legacy_v1.tar.gz"), name="Nieuw")
        after = [tuple(r) for r in self.conn.execute("SELECT * FROM assignments WHERE game_id = ?", (ids["game"],))]
        self.assertEqual(before, after)

    def export(self, include_progress=True):
        path = os.path.join(self.tmp, "export.tar.gz")
        export_game(self.conn, self.env.htdocs, self.ids["game"], include_progress, path, Reporter())
        return path

    def test_progress_import_in_same_installation(self):
        self.ids = seed_game(self.env)
        game_id, _ = self.do_import(self.export())
        game = self.conn.execute("SELECT * FROM games WHERE id = ?", (game_id,)).fetchone()
        self.assertEqual(game["name"], "Operatie Zebra (2)")
        self.assertEqual(game["status"], "paused")
        self.assertEqual(game["started_at"], "2026-10-18 08:00:00")
        teams = self.conn.execute("SELECT * FROM teams WHERE game_id = ?", (game_id,)).fetchall()
        self.assertEqual(sorted(t["team_name"] for t in teams), ["De Speurneuzen", "Team Zebra"])
        self.assertTrue(all(t["email"] is None for t in teams))
        self.assertFalse({t["access_token"] for t in teams} & set(self.ids["tokens"]))
        # Elke verwijzing van een bericht naar een beoordeling blijft binnen hetzelfde nieuwe team
        rows = self.conn.execute(
            "SELECT tm.team_id AS mt, e.team_id AS et FROM team_messages tm JOIN ai_evaluations e ON e.id = tm.ai_evaluation_id "
            "JOIN teams t ON t.id = tm.team_id WHERE t.game_id = ?", (game_id,)).fetchall()
        self.assertEqual(len(rows), 1)
        self.assertTrue(all(r["mt"] == r["et"] for r in rows))
        ev = self.conn.execute("SELECT e.* FROM ai_evaluations e WHERE e.game_id = ?", (game_id,)).fetchone()
        msg = self.conn.execute("SELECT * FROM team_messages WHERE id = ?", (ev["message_id"],)).fetchone()
        self.assertEqual(msg["message"], "De conciërge")
        archive = self.conn.execute("SELECT * FROM completed_assignments WHERE game_id = ? ORDER BY id", (game_id,)).fetchall()
        self.assertEqual(len(archive), 2)
        self.assertEqual(archive[0]["leveled_by_name"], "Docent")
        self.assertEqual(self.conn.execute("SELECT message FROM team_messages WHERE id = ?", (archive[0]["accepted_message_id"],)).fetchone()[0],
                         "De conciërge")
        self.assertIsNone(archive[1]["team_id"])
        self.assertEqual(archive[1]["team_name"], "Vertrokken Team")
        self.assertEqual(self.conn.execute("SELECT COUNT(*) FROM announcements WHERE game_id = ?", (game_id,)).fetchone()[0], 2)

    def test_finished_source_stays_finished(self):
        self.ids = seed_game(self.env, status="finished")
        game_id, _ = self.do_import(self.export())
        self.assertEqual(self.conn.execute("SELECT status FROM games WHERE id = ?", (game_id,)).fetchone()[0], "finished")

    def test_progress_renumber_refused(self):
        path = os.path.join(self.tmp, "a.tar.gz")
        make_archive(path, {"format": "operationroom-game", "format_version": 3, "include_progress": True, "game": {"name": "X"},
                            "assignments": [{"assignment_number": 1, "title": "A"}, {"assignment_number": 3, "title": "B"}], "files": {}},
                     {"progress.json": b'{"teams": []}'})
        with self.assertRaisesRegex(UserError, "hernummerd"):
            self.do_import(path)

    def test_job_removes_upload_and_sets_result(self):
        upload = os.path.join(self.env.storage.incoming, "abc.tar.gz")
        shutil.copy(os.path.join(FIXTURES, "legacy_v1.tar.gz"), upload)
        db.create_job(self.conn, "game_import", {"upload": "incoming/abc.tar.gz", "original_name": "oud spel.tar.gz"},
                      created_by=self.admin)
        job = run_job(self.conn, self.env.cfg, self.env.storage, db.claim_next(self.conn, "w"))
        row = db.get_job(self.conn, job.id)
        self.assertEqual(row["status"], "done", row["message"])
        self.assertFalse(os.path.exists(upload))
        name = self.conn.execute("SELECT name FROM games WHERE id = ?", (row["result_game_id"],)).fetchone()[0]
        self.assertEqual(name, "Import oud spel")

    def test_job_refuses_path_outside_incoming(self):
        db.create_job(self.conn, "game_import", {"upload": "../database/leaderboard.db"}, created_by=self.admin)
        job = run_job(self.conn, self.env.cfg, self.env.storage, db.claim_next(self.conn, "w"))
        self.assertEqual(db.get_job(self.conn, job.id)["status"], "failed")
        self.assertTrue(os.path.exists(self.env.db_file))


if __name__ == "__main__":
    unittest.main()
