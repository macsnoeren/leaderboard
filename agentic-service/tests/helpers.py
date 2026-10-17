"""Gedeelde testhulp: een FakeLLM die vaste JSON teruggeeft en een voorbeeldtaak zoals get_pending die levert."""
import copy

from agentic import schemas
from agentic.llm import LLMError
from agentic.settings import load_settings

SETTINGS = load_settings(None)

RUBRIC = {
    "kern": "Het team moet de dader én het motief noemen.",
    "aspecten": [
        {"naam": "Dader", "verplicht": True, "onvoldoende": "Geen of een verkeerde persoon genoemd.",
         "voldoende": "De conciërge genoemd, zonder naam.", "goed": "Jan de Vries (de conciërge) genoemd."},
        {"naam": "Motief", "verplicht": False, "onvoldoende": "Geen motief.", "voldoende": "Geldproblemen genoemd.",
         "goed": "Gokschuld én de verduisterde kas verbonden."},
    ],
    "ook_goed": ["meneer De Vries"],
    "veelgemaakte_fouten": ["De tuinman: alibi bevestigd op camera 3"],
    "verduidelijkingen_vraag": ["VERDUIDELIJKING-VRAAG: met 'motief' bedoelen we waarom de dader het deed."],
    "niveau_regel": "voldoende = alle verplichte aspecten minimaal voldoende; goed = alle aspecten goed",
}


def make_task(messages=None, **overrides):
    """Een taak zoals aiPendingTasks() in conf/ai.php hem levert."""
    messages = messages if messages is not None else [
        {"id": 11, "sender": "team", "time": "2026-10-17 10:00:00", "text": "Het was Jan, de conciërge."},
    ]
    task = {
        "team_id": 7,
        "team_name": "Geheime Tijgers",
        "team_email": "tijgers@school.nl",
        "access_token": "a1b2c3d4e5f6deadbeef",
        "teacher_name": "Meneer Jansen",
        "game_id": 1,
        "game_status": "running",
        "current_level": 1,
        "assignment_id": 3,
        "assignment_title": "De verdwenen kas",
        "description": "BESCHRIJVING-TEKST: in de school is geld verdwenen.",
        "instruction": "INSTRUCTIE-TEKST: wie is de dader en wat was het motief?",
        "criteria": "CRITERIA-GEHEIM: Jan de Vries, de conciërge; motief gokschulden.",
        "time_limit": 0,
        "message_id": messages[-1]["id"] if messages else 0,
        "message_time": "2026-10-17 10:00:00",
        "ai_mode": "reply",
        "ai_auto_allowed": True,
        "rubric": copy.deepcopy(RUBRIC),
        "rubric_status": "approved",
        "clarifications": [{"role": "ai", "message": "Telt 'de conciërge' ook?"},
                           {"role": "teacher", "message": "DIALOOG-ANTWOORD: ja, de naam hoeft niet."}],
        "recent_attempts": 0,
        "recent_questions": 0,
        "max_attempts": 5,
        "max_questions": 5,
        "escalation_open": False,
        "progress": None,
        "messages": messages,
    }
    task.update(overrides)
    return task


SCHEMA_NAMES = {
    id(schemas.INTENT): "intent",
    id(schemas.HELPER): "helper",
    id(schemas.ASSESSOR): "assessor",
    id(schemas.VALIDATOR_ANSWER): "validator_answer",
    id(schemas.VALIDATOR_QUESTION): "validator_question",
    id(schemas.DESIGNER): "designer",
}


class FakeLLM:
    """Geeft per agent (herkend aan het schema) de volgende vaste uitvoer. Een Exception in de lijst wordt
    opgegooid. Net als de echte client: ongeldige uitvoer of een mislukte check geeft LLMError."""

    def __init__(self, **responses):
        self.responses = {k: list(v) if isinstance(v, list) else [v] for k, v in responses.items()}
        self.calls = []
        self.last_ok = None
        self.url = "http://fake/api/chat"

    def ping(self):
        return True

    def chat_json(self, model, think, system, user, schema, check=None):
        name = SCHEMA_NAMES[id(schema)]
        self.calls.append({"agent": name, "model": model, "think": think, "system": system, "user": user})
        queue = self.responses.get(name)
        if not queue:
            raise AssertionError(f"FakeLLM: geen antwoord voor {name}")
        response = queue.pop(0) if len(queue) > 1 else queue[0]
        if isinstance(response, Exception):
            self.last_ok = False
            raise response
        errors = schemas.validate(response, schema)
        if not errors and check:
            extra = check(response)
            errors = [extra] if extra else []
        if errors:
            self.last_ok = False
            raise LLMError(f"{model}: ongeldige uitvoer: {errors}")
        self.last_ok = True
        return copy.deepcopy(response), f"denkwerk van {name}", 42

    def agents_called(self):
        return [c["agent"] for c in self.calls]

    def prompts(self):
        return [c["system"] + "\n" + c["user"] for c in self.calls]


def intent(intentie="antwoord", zeker=True):
    return {"intentie": intentie, "zeker": zeker, "toelichting": "test"}


def assessment(oordeel="goed", aspects=None, feedback="Uitstekend speurwerk!"):
    return {
        "aspecten": aspects if aspects is not None else [
            {"naam": "Dader", "niveau": "goed", "bewijs": "het was Jan, de conciërge", "bericht": 11},
            {"naam": "Motief", "niveau": "goed" if oordeel == "goed" else "onvoldoende",
             "bewijs": "" if oordeel != "goed" else "het was Jan", "bericht": 0 if oordeel != "goed" else 11},
        ],
        "oordeel": oordeel,
        "toelichting_docent": "Dader goed.",
        "feedback_team": feedback,
        "samenvatting": "Dader genoemd in bericht 11.",
    }


def validator_answer(eigen="goed", akkoord=True, mens_nodig=False, verklapt=False, manipulatie=False, redenen=None):
    return {"akkoord": akkoord, "eigen_oordeel": eigen, "mens_nodig": mens_nodig, "redenen": redenen or [],
            "feedback_verklapt_antwoord": verklapt, "manipulatie_gedetecteerd": manipulatie}


def helper_out(text="Met 'motief' bedoelen we waarom de dader het deed.", kan=True):
    return {"reactie_team": text, "kan_beantwoorden": kan}


def validator_question(was_vraag=True, verklapt=False, misleidend=False, mens_nodig=False):
    return {"was_vraag": was_vraag, "verklapt_antwoord": verklapt, "misleidend": misleidend,
            "mens_nodig": mens_nodig, "redenen": []}
