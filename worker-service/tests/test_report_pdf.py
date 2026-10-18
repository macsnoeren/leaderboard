"""PDF-opmaak, de taak report en de CLI."""
import contextlib
import io
import os
import re
import unittest

from tests.helpers import make_env, seed_game
from tests.schema import add_team
from worker import cli, db
from worker.jobs import Reporter, run_job
from worker.report.pdf import strip_markdown
from worker.tasks.report import make_report

DEJAVU = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"


def pages(data):
    return len(re.findall(rb"/Type /Page\b(?!s)", data))


class ReportPdfTest(unittest.TestCase):
    def setUp(self):
        # Helvetica (zonder REPORT_FONT): tekst staat leesbaar in een niet-gecomprimeerde PDF
        self.env = make_env(self, REPORT_FONT="/bestaat/niet.ttf")
        self.ids = seed_game(self.env)
        self.out = os.path.join(self.env.root, "r.pdf")

    def build(self, sections=("answers", "teams"), cfg=None):
        make_report(self.env.conn, cfg or self.env.cfg, self.ids["game"], list(sections), self.out, Reporter(), compress=False)
        with open(self.out, "rb") as f:
            return f.read()

    def test_is_pdf_with_content(self):
        data = self.build()
        self.assertTrue(data.startswith(b"%PDF"))
        self.assertIn(b"Operatie Zebra", data)
        self.assertIn(b"A. Goedgekeurde antwoorden per opdracht", data)
        self.assertIn(b"B. Verloop per team", data)
        # Het verwijderde team heeft een oude archiefregel; haakjes zijn in een PDF-string ge-escaped
        self.assertTrue(rb"\(afgeleid\)" in data)

    def test_only_answers(self):
        data = self.build(("answers",))
        self.assertIn(b"A. Goedgekeurde antwoorden", data)
        self.assertNotIn(b"B. Verloop per team", data)

    def test_unicode_team_name_core_font(self):
        add_team(self.env.conn, self.ids["game"], "Ü-team \U0001F680")
        data = self.build()
        self.assertTrue(data.startswith(b"%PDF"))

    @unittest.skipUnless(os.path.isfile(DEJAVU), "DejaVu Sans niet geïnstalleerd (zit wel in de worker-image)")
    def test_unicode_team_name_dejavu(self):
        add_team(self.env.conn, self.ids["game"], "Ü-team \U0001F680")
        env = make_env(self, REPORT_FONT=DEJAVU)
        ids = seed_game(env)
        add_team(env.conn, ids["game"], "Ü-team \U0001F680")
        make_report(env.conn, env.cfg, ids["game"], ["answers", "teams"], self.out, Reporter())
        with open(self.out, "rb") as f:
            self.assertTrue(f.read().startswith(b"%PDF"))

    def test_long_answer_flows_over_pages(self):
        short = pages(self.build(("answers",)))
        self.env.conn.execute("UPDATE completed_assignments SET accepted_answer = ? WHERE team_name = 'De Speurneuzen'",
                              (("Een heel lang antwoord met veel woorden. " * 60)[:2000] * 3,))
        long = pages(self.build(("answers",)))
        self.assertGreater(long, short)

    def test_strip_markdown(self):
        self.assertEqual(strip_markdown("## Kop\n**vet** en *schuin* met `code` en [link](http://x)"),
                         "Kop\nvet en schuin met code en link (http://x)")

    def test_job_and_unknown_game(self):
        conn = db.connect(self.env.db_file)
        self.addCleanup(conn.close)
        db.create_job(conn, "report", {"game_id": self.ids["game"], "sections": ["answers", "teams"]}, game_id=self.ids["game"])
        job = run_job(conn, self.env.cfg, self.env.storage, db.claim_next(conn, "w"))
        row = db.get_job(conn, job.id)
        self.assertEqual(row["status"], "done", row["message"])
        self.assertTrue(row["result_name"].startswith("zebrawave-rapport-operatie-zebra-") and row["result_name"].endswith(".pdf"))
        self.assertIn("goedgekeurde antwoorden", row["message"])
        db.create_job(conn, "report", {"game_id": 99}, game_id=99)
        job = run_job(conn, self.env.cfg, self.env.storage, db.claim_next(conn, "w"))
        self.assertEqual(db.get_job(conn, job.id)["status"], "failed")
        self.assertIn("bestaat niet", db.get_job(conn, job.id)["message"])

    def test_cli_report(self):
        target = os.path.join(self.env.root, "cli.pdf")
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = cli.main(["report", "--game", str(self.ids["game"]), "--sections", "teams", "-o", target], cfg=self.env.cfg)
        self.assertEqual(code, 0, err.getvalue())
        with open(target, "rb") as f:
            self.assertTrue(f.read().startswith(b"%PDF"))


if __name__ == "__main__":
    unittest.main()
