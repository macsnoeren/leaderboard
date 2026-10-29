"""Export als sjabloon en met voortgang."""
import hashlib
import json
import os
import tarfile
import unittest

from tests.helpers import EMAIL, make_env, seed_game
from tests.schema import add_assignment, add_game
from worker import db
from worker.jobs import Reporter, UserError, run_job
from worker.tasks.export_game import export_game


def read_tar(path):
    with tarfile.open(path, "r:gz") as tar:
        return {m.name: tar.extractfile(m).read() for m in tar.getmembers() if m.isfile()}


class ExportTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.ids = seed_game(self.env)
        self.out = os.path.join(self.env.root, "export.tar.gz")

    def export(self, include_progress=False, game=None):
        reporter = Reporter()
        export_game(self.env.conn, self.env.htdocs, game or self.ids["game"], include_progress, self.out, reporter, app_version="t")
        return read_tar(self.out), reporter

    def test_template(self):
        files, _ = self.export()
        manifest = json.loads(files["manifest.json"])
        self.assertEqual((manifest["format"], manifest["format_version"]), ("operationroom-game", 3))
        self.assertFalse(manifest["include_progress"])
        self.assertEqual(manifest["game"]["name"], "Operatie Zebra")
        self.assertEqual(manifest["game"]["ai_mode"], "reply")
        self.assertEqual(manifest["game"]["ai_max_attempts"], 7)
        self.assertEqual(manifest["game"]["finish_message"], "***GEFELICITEERD!***\n\nDe zaak is opgelost.")
        self.assertEqual([a["assignment_number"] for a in manifest["assignments"]], [1, 2])
        self.assertEqual(manifest["assignments"][0]["ai_rubric"], {"kern": "De conciërge", "aspecten": []})
        self.assertEqual(manifest["assignments"][1]["ai_auto_allowed"], 0)
        self.assertEqual([a["artifact_name"] for a in manifest["assignments"]], ["Bewijsstuk sleutel.pdf", None])
        for key in ("join_code", "status", "ends_at", "started_at"):
            self.assertNotIn(key, manifest["game"])
        raw = files["manifest.json"].decode()
        for secret in ("geheim overleg", "ai_processing", "ai_design"):
            self.assertNotIn(secret, raw)
        self.assertNotIn("progress.json", files)

    def test_checksums_match(self):
        files, _ = self.export()
        manifest = json.loads(files["manifest.json"])
        self.assertEqual(set(manifest["files"]), {"files/game_1_assignment_1_1.pdf", "logo/game_1_logo_1.png"})
        for name, info in manifest["files"].items():
            self.assertEqual(info["size"], len(files[name]))
            self.assertEqual(info["sha256"], hashlib.sha256(files[name]).hexdigest())

    def test_missing_attachment_is_warning(self):
        os.remove(os.path.join(self.env.htdocs, "artifacts/game_1_assignment_1_1.pdf"))
        files, reporter = self.export()
        self.assertEqual(len(reporter.warnings), 1)
        self.assertIsNone(json.loads(files["manifest.json"])["assignments"][0]["artifact_file"])

    def test_other_game_not_included(self):
        other = add_game(self.env.conn, "Ander spel")
        add_assignment(self.env.conn, other, 1, "Vreemde opdracht")
        files, _ = self.export()
        self.assertNotIn("Vreemde opdracht", files["manifest.json"].decode())

    def test_unknown_game(self):
        with self.assertRaisesRegex(UserError, "bestaat niet"):
            self.export(game=99)

    def test_with_progress_has_no_secrets(self):
        files, _ = self.export(include_progress=True)
        progress = json.loads(files["progress.json"])
        blob = b"".join(files.values()).decode("utf-8", "ignore")
        for secret in [EMAIL, "agent-geheim", "J. Jansen", "jjansen", *self.ids["tokens"]]:
            self.assertNotIn(secret, blob)
        self.assertNotIn("resolved_by", files["progress.json"].decode())
        self.assertEqual(len(progress["teams"]), 2)
        self.assertIn("suggestion", [m["sender"] for m in progress["messages"]])
        self.assertEqual(progress["game"]["status"], "running")

    def test_refs_are_consistent(self):
        files, _ = self.export(include_progress=True)
        p = json.loads(files["progress.json"])
        teams = {t["ref"] for t in p["teams"]}
        messages = {m["ref"] for m in p["messages"]}
        evaluations = {e["ref"] for e in p["evaluations"]}
        self.assertTrue(all(m["team_ref"] in teams for m in p["messages"]))
        self.assertTrue(all(m["evaluation_ref"] in evaluations for m in p["messages"] if m["evaluation_ref"]))
        self.assertTrue(all(e["message_ref"] in messages for e in p["evaluations"]))
        self.assertTrue(all(c["team_ref"] in teams for c in p["completed"] if c["team_ref"]))
        self.assertTrue(all(c["accepted_message_ref"] in messages for c in p["completed"] if c["accepted_message_ref"]))
        gone = [c for c in p["completed"] if c["team_name"] == "Vertrokken Team"]
        self.assertEqual(len(gone), 1)
        self.assertIsNone(gone[0]["team_ref"])
        kinds = {c["team_name"]: c["leveled_by_kind"] for c in p["completed"]}
        self.assertEqual(kinds, {"De Speurneuzen": "teacher", "Vertrokken Team": None})

    def test_job_handler(self):
        conn = db.connect(self.env.db_file)
        self.addCleanup(conn.close)
        db.create_job(conn, "game_export", {"game_id": self.ids["game"], "include_progress": True}, game_id=self.ids["game"])
        job = run_job(conn, self.env.cfg, self.env.storage, db.claim_next(conn, "w"))
        row = db.get_job(conn, job.id)
        self.assertEqual(row["status"], "done", row["message"])
        self.assertTrue(row["result_name"].startswith("operationroom-spel-operatie-zebra-"))
        self.assertTrue(os.path.isfile(os.path.join(self.env.cfg.STORAGE_DIR, row["result_file"])))
        self.assertIn("met teams en voortgang", row["message"])


if __name__ == "__main__":
    unittest.main()
