"""T2.17 en T4.2: de beslistabel uit DESIGN §6, tabelgedreven."""
import itertools
import unittest

from agentic.policy import decide_answer, decide_question, effective_mode


def ctx(mode="reply", **kw):
    base = {"mode": mode, "rubric_status": "approved", "auto_allowed": True, "escalation_open": False,
            "flag_injection": False, "recent_attempts": 0, "max_attempts": 5, "recent_questions": 0, "max_questions": 5}
    base.update(kw)
    return base


def sure(verdict):
    return {"verdict": verdict, "validator_verdict": verdict, "akkoord": True, "mens_nodig": False,
            "manipulatie_gedetecteerd": False, "feedback_verklapt": False, "citations_ok": True, "consistent": True,
            "unanimous": True, "redenen": []}


def unsure(verdict):
    return dict(sure(verdict), validator_verdict="onvoldoende" if verdict != "onvoldoende" else "voldoende", akkoord=False)


def question(**kw):
    base = {"kan_beantwoorden": True, "was_vraag": True, "verklapt_antwoord": False, "misleidend": False, "mens_nodig": False, "redenen": []}
    base.update(kw)
    return base


class AnswerTableTest(unittest.TestCase):
    # (oordeel, zeker?, suggest, reply, auto) uit de tabel in DESIGN §6
    TABLE = [
        ("onvoldoende", True, "suggest", "reply", "reply"),
        ("onvoldoende", False, "suggest", "escalate", "escalate"),
        ("voldoende", True, "suggest", "reply", "level_up"),
        ("goed", True, "suggest", "reply", "level_up"),
        ("voldoende", False, "suggest", "escalate", "escalate"),
        ("goed", False, "suggest", "escalate", "escalate"),
    ]

    def test_table(self):
        for verdict, is_sure, *expected in self.TABLE:
            for mode, exp in zip(["suggest", "reply", "auto"], expected):
                r = sure(verdict) if is_sure else unsure(verdict)
                self.assertEqual(decide_answer(ctx(mode), r)[0], exp, (verdict, is_sure, mode))

    def test_level_up_advice_in_reason(self):
        self.assertIn("level-up-advies", decide_answer(ctx("suggest"), sure("voldoende"))[1])

    def test_each_doubt_makes_unsure(self):
        for field, value in [("akkoord", False), ("mens_nodig", True), ("feedback_verklapt", True), ("citations_ok", False),
                             ("consistent", False), ("unanimous", False), ("validator_verdict", "onvoldoende")]:
            decision, reason = decide_answer(ctx("auto"), dict(sure("goed"), **{field: value}))
            self.assertEqual(decision, "escalate", field)
            self.assertTrue(reason)

    def test_validator_reasons_alone_do_not_escalate(self):
        self.assertEqual(decide_answer(ctx("auto"), dict(sure("goed"), redenen=["Alles klopt"]))[0], "level_up")

    def test_attempt_limit(self):
        self.assertEqual(decide_answer(ctx("auto", recent_attempts=4), sure("goed"))[0], "level_up")
        decision, reason = decide_answer(ctx("auto", recent_attempts=5), sure("goed"))
        self.assertEqual(decision, "escalate")
        self.assertIn("pogingslimiet", reason)
        self.assertEqual(decide_answer(ctx("reply", recent_attempts=9), sure("onvoldoende"))[0], "escalate")

    def test_never_automatic_with_flags(self):
        for mode in ["suggest", "reply", "auto"]:
            self.assertEqual(decide_answer(ctx(mode, flag_injection=True), sure("goed"))[0], "escalate")
            self.assertEqual(decide_answer(ctx(mode), dict(sure("goed"), manipulatie_gedetecteerd=True))[0], "escalate")

    def test_rubric_and_auto_allowed(self):
        self.assertEqual(decide_answer(ctx("auto", rubric_status="draft"), sure("goed"))[0], "suggest")
        self.assertEqual(decide_answer(ctx("reply", rubric_status="none"), sure("goed"))[0], "suggest")
        self.assertEqual(decide_answer(ctx("auto", auto_allowed=False), sure("goed"))[0], "reply")
        self.assertEqual(effective_mode(ctx("auto", auto_allowed=False)), "reply")

    def test_open_escalation_only_suggest(self):
        for mode in ["reply", "auto"]:
            self.assertEqual(decide_answer(ctx(mode, escalation_open=True), sure("goed"))[0], "suggest")
            self.assertEqual(decide_answer(ctx(mode, escalation_open=True), unsure("goed"))[0], "suggest")

    def test_never_level_up_outside_auto(self):
        for mode, verdict, is_sure, esc, limit, rubric, auto_allowed in itertools.product(
                ["suggest", "reply", "auto"], ["onvoldoende", "voldoende", "goed"], [True, False], [True, False],
                [0, 5], ["approved", "draft"], [True, False]):
            r = sure(verdict) if is_sure else unsure(verdict)
            decision, _ = decide_answer(ctx(mode, escalation_open=esc, recent_attempts=limit, rubric_status=rubric, auto_allowed=auto_allowed), r)
            if decision == "level_up":
                self.assertEqual((mode, is_sure, esc, limit, rubric, auto_allowed), ("auto", True, False, 0, "approved", True))
                self.assertIn(verdict, ("voldoende", "goed"))


class QuestionTableTest(unittest.TestCase):
    def test_table(self):
        # vraag, zeker → suggestie | reactie | reactie; niet zeker → suggestie | escalatie | escalatie
        for mode, exp_sure, exp_unsure in [("suggest", "suggest", "suggest"), ("reply", "reply", "escalate"), ("auto", "reply", "escalate")]:
            self.assertEqual(decide_question(ctx(mode), question())[0], exp_sure, mode)
            self.assertEqual(decide_question(ctx(mode), question(kan_beantwoorden=False))[0], exp_unsure, mode)

    def test_validator_flags(self):
        for field in ["verklapt_antwoord", "misleidend", "mens_nodig"]:
            self.assertEqual(decide_question(ctx("auto"), question(**{field: True}))[0], "escalate", field)

    def test_not_a_question_reassess(self):
        self.assertEqual(decide_question(ctx("auto"), question(was_vraag=False))[0], "reassess")

    def test_question_never_level_up(self):
        for mode, kan, flag, esc in itertools.product(["suggest", "reply", "auto"], [True, False], [True, False], [True, False]):
            self.assertNotEqual(decide_question(ctx(mode, flag_injection=flag, escalation_open=esc), question(kan_beantwoorden=kan))[0], "level_up")

    def test_question_limit_and_flags(self):
        self.assertEqual(decide_question(ctx("reply", recent_questions=5), question())[0], "escalate")
        self.assertEqual(decide_question(ctx("reply", recent_questions=4), question())[0], "reply")
        self.assertEqual(decide_question(ctx("auto", flag_injection=True), question())[0], "escalate")
        self.assertEqual(decide_question(ctx("auto", rubric_status="draft"), question())[0], "suggest")
        self.assertEqual(decide_question(ctx("reply", escalation_open=True), question())[0], "suggest")


if __name__ == "__main__":
    unittest.main()
