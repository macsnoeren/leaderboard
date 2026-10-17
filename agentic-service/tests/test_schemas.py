"""T2.9: de voorbeelden uit DESIGN §6 zijn geldig; fouten worden gevonden."""
import unittest

from agentic import schemas
from agentic.schemas import is_valid, validate
from tests.helpers import RUBRIC


class SchemaTest(unittest.TestCase):
    def test_design_examples_valid(self):
        self.assertTrue(is_valid({"intentie": "vraag", "zeker": True, "toelichting": "Team vraagt wat 'motief' betekent."}, schemas.INTENT))
        self.assertTrue(is_valid({"reactie_team": "Met 'motief' bedoelen we waarom de dader het deed.", "kan_beantwoorden": True}, schemas.HELPER))
        self.assertTrue(is_valid({
            "aspecten": [{"naam": "Dader", "niveau": "goed", "bewijs": "\"het was Jan, de conciërge\"", "bericht": 1234}],
            "oordeel": "voldoende", "toelichting_docent": "Dader goed, motief alleen half.",
            "feedback_team": "Sterk speurwerk!", "samenvatting": "Dader goed in 1234."}, schemas.ASSESSOR))
        self.assertTrue(is_valid({"was_vraag": True, "verklapt_antwoord": False, "misleidend": False, "mens_nodig": False, "redenen": []}, schemas.VALIDATOR_QUESTION))
        self.assertTrue(is_valid({"akkoord": True, "eigen_oordeel": "voldoende", "mens_nodig": False, "redenen": [],
                                  "feedback_verklapt_antwoord": False, "manipulatie_gedetecteerd": False}, schemas.VALIDATOR_ANSWER))
        self.assertTrue(is_valid(RUBRIC, schemas.RUBRIC))
        self.assertTrue(is_valid({"soort": "vragen", "vragen": ["Telt de conciërge ook?"], "rubric": {}}, schemas.DESIGNER))

    def test_missing_required_field(self):
        errors = validate({"intentie": "vraag", "zeker": True}, schemas.INTENT)
        self.assertTrue(any("toelichting" in e for e in errors))

    def test_wrong_type(self):
        self.assertFalse(is_valid({"intentie": "vraag", "zeker": "hoog", "toelichting": ""}, schemas.INTENT))
        self.assertFalse(is_valid({"reactie_team": 3, "kan_beantwoorden": True}, schemas.HELPER))
        # bool telt niet als integer
        self.assertFalse(is_valid({"aspecten": [{"naam": "x", "niveau": "goed", "bewijs": "", "bericht": True}],
                                   "oordeel": "goed", "toelichting_docent": "", "feedback_team": "", "samenvatting": ""}, schemas.ASSESSOR))

    def test_enum(self):
        self.assertFalse(is_valid({"intentie": "antwoord (gok in vraagvorm)", "zeker": True, "toelichting": ""}, schemas.INTENT))
        self.assertFalse(is_valid({"akkoord": True, "eigen_oordeel": "prima", "mens_nodig": False, "redenen": [],
                                   "feedback_verklapt_antwoord": False, "manipulatie_gedetecteerd": False}, schemas.VALIDATOR_ANSWER))

    def test_nested_items(self):
        bad = dict(RUBRIC, aspecten=[{"naam": "Dader", "verplicht": True}])
        self.assertFalse(is_valid(bad, schemas.RUBRIC))
        self.assertFalse(is_valid(dict(RUBRIC, aspecten=[]), schemas.RUBRIC))  # minItems
        self.assertFalse(is_valid(dict(RUBRIC, ook_goed=[1]), schemas.RUBRIC))


if __name__ == "__main__":
    unittest.main()
