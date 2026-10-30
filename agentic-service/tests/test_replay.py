"""AI-replay: welke berichten worden beoordeeld, wat er toen gebeurde, de taak per beurt en het rapport."""
import copy
import unittest

from agentic import replay, replay_report
from agentic.llm import LLMError
from tests.helpers import RUBRIC, SETTINGS, FakeLLM


def job(new_rubric=False):
    level1 = [
        {"id": 11, "level": 1, "sender": "team", "time": "2026-10-17 10:00:00", "text": "Het was Jan, de conciërge."},
        {"id": 12, "level": 1, "sender": "teacher", "time": "2026-10-17 10:01:00", "text": "Bijna: waarom deed hij het?"},
        {"id": 13, "level": 1, "sender": "team", "time": "2026-10-17 10:03:00", "text": "Hij had gokschulden"},
        {"id": 14, "level": 1, "sender": "team", "time": "2026-10-17 10:03:30", "text": "en pakte de kas <script>alert(1)</script>"},
    ]
    level2 = [
        {"id": 21, "level": 2, "sender": "team", "time": "2026-10-17 10:10:00", "text": "Is het de directeur?"},
        {"id": 22, "level": 2, "sender": "ai", "time": "2026-10-17 10:11:00", "text": "Jullie zijn er nog niet."},
        {"id": 23, "level": 2, "sender": "team", "time": "2026-10-17 10:12:00", "text": "Dan de tuinman."},
    ]
    return {
        "job_id": 9, "game": {"name": "Operatie <Test>", "ai_max_attempts": 5, "ai_max_questions": 5},
        "new_rubric": new_rubric, "created_at": "2026-10-30 12:00:00",
        "assignments": [
            {"number": 1, "title": "De kas", "description": "D1", "instruction": "Wie en waarom?", "criteria": "Jan; gokschulden",
             "time_limit": 0, "has_attachment": False, "rubric": copy.deepcopy(RUBRIC), "rubric_status": "approved", "clarifications": []},
            {"number": 2, "title": "De tweede", "description": "D2", "instruction": "Wie nog meer?", "criteria": "Piet",
             "time_limit": 0, "has_attachment": False, "rubric": None, "rubric_status": "none", "clarifications": []},
        ],
        "teams": [{
            "team_id": 7, "team_name": "Geheime Tijgers", "current_level": 2,
            "messages": level1 + level2,
            "evaluations": [{"message_id": 21, "level": 2, "intent": "antwoord", "verdict": "onvoldoende", "decision": "reply",
                             "reason": "Verkeerde persoon", "reply_text": "Jullie zijn er nog niet.", "teacher_verdict": None, "time": "t"},
                            {"message_id": 23, "level": 2, "intent": "antwoord", "verdict": "goed", "decision": "level_up",
                             "reason": "", "reply_text": "Uitstekend!", "teacher_verdict": None, "time": "t"}],
            "completed": [{"level": 1, "accepted_message_id": 14, "leveled_by": "operator", "time": "2026-10-17 10:05:00"},
                          {"level": 2, "accepted_message_id": 23, "leveled_by": "ai", "time": "2026-10-17 10:13:00"}],
            "level_downs": [{"to_level": 2, "time": "2026-10-17 10:20:00"}],
        }],
    }


class FakePipeline:
    """Geeft per bericht-id een vaste uitkomst en onthoudt de taken."""
    def __init__(self, outcomes):
        self.outcomes = outcomes
        self.tasks = []

    def process(self, task, agent_id):
        self.tasks.append(task)
        out = dict(self.outcomes[task["message_id"]])
        out.setdefault("reason", "")
        out.setdefault("reply_text", "")
        return out


OUTCOMES = {
    11: {"intent": "antwoord", "verdict": "onvoldoende", "decision": "reply",
         "progress": {"aspects": [{"naam": "Dader", "niveau": "goed", "bewijs": "het was jan", "bericht": 11}], "summary": "Dader goed."}},
    14: {"intent": "antwoord", "verdict": "goed", "decision": "level_up",
         "progress": {"aspects": [{"naam": "Motief", "niveau": "goed", "bewijs": "en pakte de kas", "bericht": 14}], "summary": ""}},
    21: {"intent": "antwoord", "verdict": "onvoldoende", "decision": "suggest"},
    23: {"intent": "antwoord", "verdict": "voldoende", "decision": "suggest"},
}


def run_all(run):
    labels = []
    while not run.done:
        labels.append(run.step())
    return labels


class TruthTest(unittest.TestCase):
    def setUp(self):
        self.team = job()["teams"][0]
        self.l1 = [m for m in self.team["messages"] if m["level"] == 1]
        self.l2 = [m for m in self.team["messages"] if m["level"] == 2]

    def test_turns_are_last_of_a_run(self):
        self.assertEqual(replay.turns(self.l1), [0, 3])
        self.assertEqual(replay.turns(self.l2), [0, 2])

    def test_truth(self):
        self.assertEqual(replay.truth(self.team, 1, self.l1, 0)[0], False)   # operator antwoordde, niet door
        self.assertEqual(replay.truth(self.team, 1, self.l1, 3)[0], True)    # operator zette door
        self.assertEqual(replay.truth(self.team, 2, self.l2, 0)[0], None)    # alleen de AI reageerde
        value, text = replay.truth(self.team, 2, self.l2, 2)
        self.assertEqual(value, False)                                       # AI zette door, operator terug
        self.assertIn("terug", text)

    def test_teacher_verdict_from_ai_log(self):
        team = copy.deepcopy(self.team)
        team["evaluations"][0]["teacher_verdict"] = "disagree"
        self.assertEqual(replay.truth(team, 2, self.l2, 0)[0], True)  # AI zei onvoldoende, operator: onterecht

    def test_category(self):
        self.assertEqual(replay.category("antwoord", "goed", "level_up", True), "eens")
        self.assertEqual(replay.category("antwoord", "onvoldoende", "reply", True), "strenger")
        self.assertEqual(replay.category("antwoord", "voldoende", "level_up", False), "soepeler")
        self.assertEqual(replay.category("antwoord", "goed", "escalate", True), "operator")
        self.assertEqual(replay.category("vraag", None, "reply", False), "vraag")
        self.assertEqual(replay.category("antwoord", "goed", "reply", None), "geen")


class ReplayRunTest(unittest.TestCase):
    def make(self, **kw):
        pipeline = FakePipeline(OUTCOMES)
        run = replay.ReplayRun(job(**kw), FakeLLM(), SETTINGS, pipeline=pipeline)
        return run, pipeline

    def test_steps_and_tasks(self):
        run, pipeline = self.make()
        labels = run_all(run)
        self.assertEqual(len(run.steps), 4)
        self.assertEqual(run.percent, 100)
        self.assertIn("Geheime Tijgers", labels[0])
        first, second = pipeline.tasks[0], pipeline.tasks[1]
        # Alleen de chat tot en met het beoordeelde bericht, in modus auto, zonder teamnaam
        self.assertEqual([m["id"] for m in first["messages"]], [11])
        self.assertEqual([m["id"] for m in second["messages"]], [11, 12, 13, 14])
        self.assertEqual((first["ai_mode"], first["rubric_status"]), ("auto", "approved"))
        self.assertNotIn("team_name", first)
        # De tussenstand van de eerste beurt gaat mee naar de tweede
        self.assertEqual(second["progress"]["aspects"][0]["naam"], "Dader")
        self.assertEqual(second["recent_attempts"], 1)
        # Level 2 heeft geen rubric
        self.assertEqual(pipeline.tasks[2]["rubric_status"], "none")

    def test_results_and_summary(self):
        run, _ = self.make()
        run_all(run)
        r = run.results
        self.assertEqual(r[(7, 11)]["category"], "eens")
        self.assertEqual(r[(7, 14)]["category"], "eens")
        self.assertEqual({a["naam"] for a in r[(7, 14)]["progress"]}, {"Dader", "Motief"})
        self.assertEqual(r[(7, 21)]["category"], "geen")
        self.assertEqual(r[(7, 23)]["category"], "soepeler")
        self.assertEqual(r[(7, 23)]["old_category"], "soepeler")
        s = run.summary()
        self.assertEqual((s["new"]["eens"], s["new"]["soepeler"], s["wrong_level_ups"]), (2, 1, 0))
        self.assertEqual((s["turns"], s["with_old"]), (4, 2))

    def test_new_rubric_first(self):
        designed = dict(copy.deepcopy(RUBRIC), kern="NIEUWE-KERN")
        llm = FakeLLM(designer=[{"soort": "rubric", "vragen": [], "rubric": designed}, LLMError("model weg")])
        pipeline = FakePipeline(OUTCOMES)
        run = replay.ReplayRun(job(new_rubric=True), llm, SETTINGS, pipeline=pipeline)
        self.assertEqual(run.steps[:2], [("rubric", 1), ("rubric", 2)])
        run_all(run)
        self.assertEqual(pipeline.tasks[0]["rubric"]["kern"], "NIEUWE-KERN")
        self.assertIn("LAATSTE RONDE", llm.calls[0]["user"])
        # Mislukt een nieuwe rubric, dan de huidige, met de fout in het rapport
        self.assertEqual(run.rubrics[2]["source"], "huidig")
        self.assertIn("model weg", replay_report.render(run, SETTINGS))

    def test_report(self):
        run, _ = self.make()
        run_all(run)
        html = replay_report.render(run, SETTINGS)
        self.assertTrue(html.startswith("<!doctype html>"))
        self.assertIn("Operatie &lt;Test&gt;", html)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertNotIn("<script>", html)
        self.assertIn('href="#t7-m23"', html)            # om na te kijken
        self.assertIn("Onterechte level-ups door de huidige AI: 0", html)
        self.assertIn("(samen met het volgende teambericht beoordeeld)", html)
        self.assertIn("↓ Een operator zette het team terug", html)

    def test_empty_job(self):
        empty = dict(job(), teams=[])
        run = replay.ReplayRun(empty, FakeLLM(), SETTINGS, pipeline=FakePipeline({}))
        self.assertTrue(run.done)
        self.assertIn("Niets bijzonders", replay_report.render(run))


if __name__ == "__main__":
    unittest.main()
