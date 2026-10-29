"""De agents met een FakeLLM."""
import unittest

from agentic.agents import assessor, designer, helper, intent, validator
from agentic.llm import LLMError
from tests.helpers import RUBRIC, SETTINGS, FakeLLM, assessment, helper_out, make_task, validator_answer, validator_question

SECRET = ["CRITERIA-GEHEIM", "DIALOOG-ANTWOORD", '"aspecten"', "Gokschuld én de verduisterde kas", "TUSSENSTAND-GEHEIM"]


class IntentTest(unittest.TestCase):
    def run_intent(self, intentie, zeker):
        llm = FakeLLM(intent={"intentie": intentie, "zeker": zeker, "toelichting": "x"})
        return intent.classify(llm, SETTINGS, make_task())[0], llm

    def test_code_rule(self):
        self.assertEqual(self.run_intent("vraag", True)[0], "vraag")
        self.assertEqual(self.run_intent("vraag", False)[0], "antwoord")
        self.assertEqual(self.run_intent("overig", False)[0], "antwoord")
        self.assertEqual(self.run_intent("overig", True)[0], "overig")
        self.assertEqual(self.run_intent("antwoord", False)[0], "antwoord")

    def test_prompt_contents(self):
        messages = [{"id": 1, "sender": "team", "time": "t", "text": "EERSTE-BERICHT"},
                    {"id": 2, "sender": "teacher", "time": "t", "text": "DOCENT-BERICHT"},
                    {"id": 3, "sender": "team", "time": "t", "text": "Is het de conciërge?"}]
        task = make_task(messages, progress={"aspects": [], "summary": "TUSSENSTAND-GEHEIM"})
        _, llm = None, FakeLLM(intent={"intentie": "antwoord", "zeker": True, "toelichting": "x"})
        intent.classify(llm, SETTINGS, task)
        prompt = llm.prompts()[0]
        for text in ["EERSTE-BERICHT", "DOCENT-BERICHT", "Is het de conciërge?", "INSTRUCTIE-TEKST"]:
            self.assertIn(text, prompt)
        for text in SECRET:
            self.assertNotIn(text, prompt)
        self.assertEqual(llm.calls[0]["model"], SETTINGS.INTENT_MODEL)
        self.assertEqual(llm.calls[0]["think"], "low")


class HelperTest(unittest.TestCase):
    def test_prompt_has_only_question_info(self):
        task = make_task(progress={"aspects": [{"naam": "Dader", "niveau": "goed", "bewijs": "x", "bericht": 11}], "summary": "TUSSENSTAND-GEHEIM"})
        llm = FakeLLM(helper=helper_out())
        helper.respond(llm, SETTINGS, task)
        prompt = llm.prompts()[0]
        for text in ["BESCHRIJVING-TEKST", "INSTRUCTIE-TEKST", "VERDUIDELIJKING-VRAAG", "Het was Jan, de conciërge."]:
            self.assertIn(text, prompt)
        for text in SECRET + ["Dader", "Motief", "De tuinman"]:
            self.assertNotIn(text, prompt)

    def test_cannot_answer_is_passed_on(self):
        out, meta = helper.respond(FakeLLM(helper=helper_out("Vraag het je docent.", kan=False)), SETTINGS, make_task())
        self.assertFalse(out["kan_beantwoorden"])
        self.assertEqual(meta["thinking"], "denkwerk van helper")


class AssessorTest(unittest.TestCase):
    def test_prompt_contents(self):
        task = make_task(progress={"aspects": [], "summary": "TUSSENSTAND-GEHEIM"})
        llm = FakeLLM(assessor=assessment())
        assessor.assess(llm, SETTINGS, task)
        prompt = llm.prompts()[0]
        for text in ["CRITERIA-GEHEIM", "DIALOOG-ANTWOORD", "TUSSENSTAND-GEHEIM", "Gokschuld én de verduisterde kas", "hagelschieten"]:
            self.assertIn(text, prompt)

    def test_valid_citation(self):
        result = assessor.assess(FakeLLM(assessor=assessment()), SETTINGS, make_task())
        self.assertTrue(result["citations_ok"])
        self.assertEqual(result["verdict"], "goed")
        self.assertTrue(result["unanimous"])
        self.assertTrue(result["consistent"])

    def test_invented_citation(self):
        bad = assessment(aspects=[{"naam": "Dader", "niveau": "goed", "bewijs": "het was Jan de Vries, zeker weten", "bericht": 11},
                                  {"naam": "Motief", "niveau": "onvoldoende", "bewijs": "", "bericht": 0}], oordeel="voldoende")
        self.assertFalse(assessor.assess(FakeLLM(assessor=bad), SETTINGS, make_task())["citations_ok"])

    def test_wrong_message_id(self):
        bad = assessment(aspects=[{"naam": "Dader", "niveau": "goed", "bewijs": "het was Jan", "bericht": 99},
                                  {"naam": "Motief", "niveau": "onvoldoende", "bewijs": "", "bericht": 0}], oordeel="voldoende")
        self.assertFalse(assessor.assess(FakeLLM(assessor=bad), SETTINGS, make_task())["citations_ok"])

    def test_citation_from_teacher_message_does_not_count(self):
        task = make_task([{"id": 11, "sender": "team", "time": "t", "text": "Het was iemand."},
                          {"id": 12, "sender": "teacher", "time": "t", "text": "Het was Jan"}])
        bad = assessment(aspects=[{"naam": "Dader", "niveau": "goed", "bewijs": "Het was Jan", "bericht": 12}], oordeel="voldoende")
        self.assertFalse(assessor.verify_citations(bad["aspecten"], task["messages"]))

    def test_normalized_citation(self):
        msgs = [{"id": 5, "sender": "team", "text": 'Het   was "Jan", de  Conciërge!'}]
        self.assertTrue(assessor.verify_citations([{"naam": "Dader", "niveau": "goed", "bewijs": "“het was jan, de conciërge”", "bericht": 5}], msgs))

    def test_inconsistent_verdict(self):
        out = assessment(aspects=[{"naam": "Dader", "niveau": "onvoldoende", "bewijs": "", "bericht": 0},
                                  {"naam": "Motief", "niveau": "goed", "bewijs": "het was Jan", "bericht": 11}], oordeel="voldoende")
        self.assertFalse(assessor.assess(FakeLLM(assessor=out), SETTINGS, make_task())["consistent"])

    def test_answer_in_parts_forgotten_aspect_gets_second_chance(self):
        # Dader stond al in de tussenstand; de beoordelaar kijkt alleen naar het motief en vergeet de dader
        messages = [{"id": 11, "sender": "team", "time": "t1", "text": "Het was Jan, de conciërge."},
                    {"id": 12, "sender": "ai", "time": "t2", "text": "Jullie zijn er nog niet: waarom?"},
                    {"id": 13, "sender": "team", "time": "t3", "text": "Hij had gokschulden en pakte de kas."}]
        dader = {"naam": "Dader", "niveau": "goed", "bewijs": "het was Jan, de conciërge", "bericht": 11}
        motief = {"naam": "Motief", "niveau": "goed", "bewijs": "hij had gokschulden en pakte de kas", "bericht": 13}
        task = make_task(messages, progress={"aspects": [dader], "summary": "Dader goed in bericht 11."})
        vergeten = assessment("onvoldoende", aspects=[dict(dader, niveau="onvoldoende", bewijs="", bericht=0), motief], feedback="Wie was het?")
        llm = FakeLLM(assessor=[vergeten, assessment("goed", aspects=[dader, motief])])
        result = assessor.assess(llm, SETTINGS, task)
        self.assertEqual(llm.agents_called(), ["assessor", "assessor"])
        self.assertNotIn("AL BEREIKT", llm.calls[0]["user"])
        self.assertIn("===BEGIN AL BEREIKT===\n- Dader: goed (bericht 11", llm.calls[1]["user"])
        self.assertEqual((result["verdict"], result["consistent"], result["forgotten"]), ("goed", True, ["Dader"]))
        self.assertEqual(len(result["runs"]), 2)

    def test_still_forgotten_after_second_chance_is_inconsistent(self):
        # Blijft de beoordelaar bij onvoldoende terwijl de tussenstand alles heeft: een operator beslist
        messages = [{"id": 11, "sender": "team", "time": "t1", "text": "Het was Jan, de conciërge."},
                    {"id": 13, "sender": "team", "time": "t3", "text": "Hij had gokschulden en pakte de kas."}]
        dader = {"naam": "Dader", "niveau": "goed", "bewijs": "het was Jan, de conciërge", "bericht": 11}
        motief = {"naam": "Motief", "niveau": "goed", "bewijs": "hij had gokschulden en pakte de kas", "bericht": 13}
        task = make_task(messages, progress={"aspects": [dader], "summary": ""})
        vergeten = assessment("onvoldoende", aspects=[dict(dader, niveau="onvoldoende", bewijs="", bericht=0), motief])
        result = assessor.assess(FakeLLM(assessor=vergeten), SETTINGS, task)
        self.assertFalse(result["consistent"])

    def test_no_second_chance_without_forgotten_aspects(self):
        task = make_task(progress={"aspects": [{"naam": "Dader", "niveau": "voldoende", "bewijs": "de conciërge", "bericht": 11}], "summary": ""})
        llm = FakeLLM(assessor=assessment())
        self.assertEqual(assessor.assess(llm, SETTINGS, task)["forgotten"], [])
        self.assertEqual(llm.agents_called(), ["assessor"])

    def test_merge_progress(self):
        messages = [{"id": 11, "sender": "team", "text": "Het was Jan, de conciërge."},
                    {"id": 13, "sender": "team", "text": "Motief: geld."},
                    {"id": 14, "sender": "team", "text": "Nee, het was Piet."}]
        previous = [{"naam": "Dader", "niveau": "goed", "bewijs": "het was jan", "bericht": 11}]
        # Bericht 13 gaat over het motief: de dader blijft groen; een verzonnen citaat telt niet
        merged = assessor.merge_progress(previous, [
            {"naam": "Dader", "niveau": "onvoldoende", "bewijs": "", "bericht": 0},
            {"naam": "Motief", "niveau": "voldoende", "bewijs": "motief: geld", "bericht": 13},
            {"naam": "Extra", "niveau": "goed", "bewijs": "verzonnen", "bericht": 13}], messages)
        self.assertEqual({a["naam"]: a["niveau"] for a in merged}, {"Dader": "goed", "Motief": "voldoende"})
        # Bericht 14 zegt iets fouts over de dader: die gaat weer op rood
        merged = assessor.merge_progress(merged, [{"naam": "dader", "niveau": "onvoldoende", "bewijs": "het was Piet", "bericht": 14}], messages)
        self.assertEqual({assessor.normalize_quote(a["naam"]): a["niveau"] for a in merged}, {"dader": "onvoldoende", "motief": "voldoende"})
        self.assertFalse(assessor.expected_pass(RUBRIC, merged))
        # Een citaat uit een ouder bericht verandert niets
        again = assessor.merge_progress(merged, [{"naam": "Dader", "niveau": "goed", "bewijs": "het was jan", "bericht": 11}], messages)
        self.assertEqual(again, merged)

    def test_wrong_new_answer_is_not_forgotten(self):
        # Het team zet een groen aspect zelf op een fout spoor: geen herkansing, wel consistent onvoldoende
        messages = [{"id": 11, "sender": "team", "time": "t1", "text": "Het was Jan, de conciërge."},
                    {"id": 13, "sender": "team", "time": "t3", "text": "Nee, het was de tuinman."}]
        dader = {"naam": "Dader", "niveau": "goed", "bewijs": "het was Jan, de conciërge", "bericht": 11}
        task = make_task(messages, progress={"aspects": [dader], "summary": ""})
        out = assessment("onvoldoende", aspects=[{"naam": "Dader", "niveau": "onvoldoende", "bewijs": "het was de tuinman", "bericht": 13},
                                                 {"naam": "Motief", "niveau": "onvoldoende", "bewijs": "", "bericht": 0}])
        llm = FakeLLM(assessor=out)
        result = assessor.assess(llm, SETTINGS, task)
        self.assertEqual(llm.agents_called(), ["assessor"])
        self.assertEqual((result["verdict"], result["consistent"], result["forgotten"]), ("onvoldoende", True, []))

    def test_feedback_about_rubric_is_refused(self):
        # Teams kennen de rubric niet: "alle vier de punten" of "het aspect motief" helpt ze niet
        task = make_task()
        for bad in ["Alle vier de punten moeten erin staan.", "Het aspect motief ontbreekt nog.", "Volgens de criteria mist er iets.",
                    "Jullie hebben pas 2 onderdelen."]:
            self.assertIsNotNone(assessor.rubric_talk(bad, task), bad)
        for ok in ["Jullie zijn er nog niet: waarom deed hij het?", "Uitstekend! Dader en motief kloppen.", "Kijk nog eens naar de vraag."]:
            self.assertIsNone(assessor.rubric_talk(ok, task), ok)
        # Wat de opdracht zelf zegt, mag de feedback herhalen
        self.assertIsNone(assessor.rubric_talk("Noem alle vier de punten.", make_task(instruction="Noem vier punten waaraan je de dader herkent.")))
        with self.assertRaises(LLMError):
            assessor.assess(FakeLLM(assessor=assessment("onvoldoende", feedback="Alle vier de punten moeten erin staan.")), SETTINGS, task)

    def test_majority_and_disagreement(self):
        settings = type(SETTINGS)(**dict(vars(SETTINGS), ASSESSOR_MODELS=["a", "b", "c"]))
        voldoende = assessment("voldoende")
        llm = FakeLLM(assessor=[assessment("goed"), voldoende, voldoende])
        result = assessor.assess(llm, settings, make_task())
        self.assertEqual(result["verdict"], "voldoende")
        self.assertFalse(result["unanimous"])
        self.assertEqual([c["model"] for c in llm.calls], ["a", "b", "c"])
        self.assertEqual(len(result["runs"]), 3)
        llm = FakeLLM(assessor=[assessment("goed"), assessment("goed")])
        two = type(SETTINGS)(**dict(vars(SETTINGS), ASSESSOR_MODELS=["a", "b"]))
        self.assertTrue(assessor.assess(llm, two, make_task())["unanimous"])
        self.assertEqual(assessor.majority(["goed", "onvoldoende"]), "onvoldoende")  # gelijk = strengste


class ValidatorTest(unittest.TestCase):
    def test_answer_mode_parsed(self):
        llm = FakeLLM(validator_answer=validator_answer("voldoende", akkoord=False, redenen=["motief ontbreekt"]))
        out, meta = validator.check_answer(llm, SETTINGS, make_task(), assessment())
        self.assertEqual(out["eigen_oordeel"], "voldoende")
        self.assertFalse(out["akkoord"])
        self.assertEqual(meta["model"], SETTINGS.VALIDATOR_MODEL)
        prompt = llm.prompts()[0]
        self.assertIn("CRITERIA-GEHEIM", prompt)
        self.assertIn("UITKOMST BEOORDELAAR", prompt)

    def test_invalid_output_raises(self):
        with self.assertRaises(LLMError):
            validator.check_answer(FakeLLM(validator_answer={"akkoord": "ja"}), SETTINGS, make_task(), assessment())

    def test_question_mode_parsed(self):
        llm = FakeLLM(validator_question=validator_question(was_vraag=False, verklapt=True, misleidend=True))
        out, _ = validator.check_question(llm, SETTINGS, make_task(), helper_out())
        self.assertEqual((out["was_vraag"], out["verklapt_antwoord"], out["misleidend"]), (False, True, True))
        self.assertIn("REACTIE HULP-AGENT", llm.prompts()[0])


class DesignerTest(unittest.TestCase):
    def job(self, round_=1, **kw):
        return dict({"assignment_id": 3, "title": "T", "description": "D", "instruction": "I", "criteria": "C",
                     "has_attachment": True, "rubric": None, "round": round_, "max_rounds": 3,
                     "dialog": [{"role": "ai", "message": "Vraag 1?"}, {"role": "teacher", "message": "Antwoord 1"}]}, **kw)

    def test_questions(self):
        llm = FakeLLM(designer={"soort": "vragen", "vragen": ["Welke naam staat in de bijlage?", " "], "rubric": {}})
        result, _ = designer.design(llm, SETTINGS, self.job())
        self.assertEqual(result, {"soort": "vragen", "vragen": ["Welke naam staat in de bijlage?"]})
        prompt = llm.prompts()[0]
        self.assertIn("bijlage", prompt)
        self.assertIn("Antwoord 1", prompt)

    def test_only_the_core_is_required(self):
        # Het antwoord van de docent is "goed"; met alles verplicht zou "voldoende" bijna hetzelfde vragen
        aspects = [dict(RUBRIC["aspecten"][0], naam=f"Punt {i}", verplicht=True) for i in range(1, 5)]
        strict = dict(RUBRIC, aspecten=aspects)
        with self.assertRaises(LLMError) as err:
            designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": strict}), SETTINGS, self.job())
        self.assertIn("hooguit 2", str(err.exception))
        two = dict(RUBRIC, aspecten=[dict(a, verplicht=i < 2) for i, a in enumerate(aspects)])
        self.assertEqual(designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": two}), SETTINGS, self.job())[0]["soort"], "rubric")

    def test_rubric(self):
        result, _ = designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": RUBRIC}), SETTINGS, self.job())
        self.assertEqual(result["soort"], "rubric")
        self.assertEqual(result["rubric"]["kern"], RUBRIC["kern"])

    def test_rubric_without_aspects_raises(self):
        with self.assertRaises(LLMError):
            designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": dict(RUBRIC, aspecten=[])}), SETTINGS, self.job())

    def test_attachment_criteria_require_questions_first(self):
        job = self.job(dialog=[], criteria="Zie de bijlage: het tijdstip op de camerabeelden.")
        rubric = dict(RUBRIC, ook_goed=["het tijdstip uit de bijlage"])
        with self.assertRaises(LLMError):
            designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": rubric}), SETTINGS, job)
        answered = dict(job, dialog=[{"role": "ai", "message": "Welk tijdstip?"}, {"role": "teacher", "message": "23:15"}])
        self.assertEqual(designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": rubric}), SETTINGS, answered)[0]["soort"], "rubric")

    def test_invented_facts_rejected(self):
        job = self.job(dialog=[{"role": "teacher", "message": "Het was om 23:15."}], criteria="Het tijdstip van de inbraak.")
        invented = dict(RUBRIC, ook_goed=["14:35"])
        with self.assertRaises(LLMError):
            designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": invented}), SETTINGS, job)
        grounded = dict(RUBRIC, ook_goed=["23:15", "23.15 uur"])
        self.assertEqual(designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": grounded}), SETTINGS, job)[0]["soort"], "rubric")

    def test_revealing_clarifications_removed(self):
        job = self.job(title="De vluchtauto", description="Een getuige zag een auto wegrijden.", instruction="Welke kleur had de vluchtauto?",
                       criteria="Rood: een rode Opel Corsa. Alleen 'rood' is genoeg voor voldoende.", dialog=[])
        rubric = dict(RUBRIC, verduidelijkingen_vraag=["We vragen naar de kleur van de auto.", "Met het merk (Opel Corsa) erbij is het goed.",
                                                     "Een enkel woord (bijv. rood) is genoeg."])
        result, meta = designer.design(FakeLLM(designer={"soort": "rubric", "vragen": [], "rubric": rubric}), SETTINGS, job)
        self.assertEqual(result["rubric"]["verduidelijkingen_vraag"], ["We vragen naar de kleur van de auto."])
        self.assertEqual(len(meta["verwijderde_verduidelijkingen"]), 2)

    def test_round_three_requires_rubric(self):
        llm = FakeLLM(designer={"soort": "vragen", "vragen": ["Nog een vraag?"], "rubric": {}})
        with self.assertRaises(LLMError):
            designer.design(llm, SETTINGS, self.job(3))
        self.assertIn("LAATSTE RONDE", llm.calls[0]["user"])
        llm = FakeLLM(designer={"soort": "vragen", "vragen": ["Vraag?"], "rubric": {}})
        designer.design(llm, SETTINGS, self.job(1))
        self.assertNotIn("LAATSTE RONDE", llm.calls[0]["user"])


if __name__ == "__main__":
    unittest.main()
