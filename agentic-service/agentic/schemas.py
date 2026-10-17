"""JSON-schema's per agent en een kleine validator.

De schema's gaan als `format` mee naar Ollama. Omdat een model zich daar niet altijd aan houdt,
controleert `validate()` de uitvoer altijd zelf. Ondersteund wordt alleen de subset die we gebruiken:
`type`, `required`, `enum`, `properties`, `items` en `minItems`.
"""

VERDICTS = ["onvoldoende", "voldoende", "goed"]
INTENTS = ["vraag", "antwoord", "overig"]

_STRING_LIST = {"type": "array", "items": {"type": "string"}}

INTENT = {
    "type": "object",
    "properties": {
        "intentie": {"type": "string", "enum": INTENTS},
        "zeker": {"type": "boolean"},
        "toelichting": {"type": "string"},
    },
    "required": ["intentie", "zeker", "toelichting"],
}

HELPER = {
    "type": "object",
    "properties": {
        "reactie_team": {"type": "string"},
        "kan_beantwoorden": {"type": "boolean"},
    },
    "required": ["reactie_team", "kan_beantwoorden"],
}

ASSESSOR = {
    "type": "object",
    "properties": {
        "aspecten": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "naam": {"type": "string"},
                    "niveau": {"type": "string", "enum": VERDICTS},
                    "bewijs": {"type": "string"},
                    "bericht": {"type": "integer"},
                },
                "required": ["naam", "niveau", "bewijs", "bericht"],
            },
        },
        "oordeel": {"type": "string", "enum": VERDICTS},
        "toelichting_docent": {"type": "string"},
        "feedback_team": {"type": "string"},
        "samenvatting": {"type": "string"},
    },
    "required": ["aspecten", "oordeel", "toelichting_docent", "feedback_team", "samenvatting"],
}

VALIDATOR_ANSWER = {
    "type": "object",
    "properties": {
        "akkoord": {"type": "boolean"},
        "eigen_oordeel": {"type": "string", "enum": VERDICTS},
        "mens_nodig": {"type": "boolean"},
        "redenen": _STRING_LIST,
        "feedback_verklapt_antwoord": {"type": "boolean"},
        "manipulatie_gedetecteerd": {"type": "boolean"},
    },
    "required": ["akkoord", "eigen_oordeel", "mens_nodig", "redenen",
                 "feedback_verklapt_antwoord", "manipulatie_gedetecteerd"],
}

VALIDATOR_QUESTION = {
    "type": "object",
    "properties": {
        "was_vraag": {"type": "boolean"},
        "verklapt_antwoord": {"type": "boolean"},
        "misleidend": {"type": "boolean"},
        "mens_nodig": {"type": "boolean"},
        "redenen": _STRING_LIST,
    },
    "required": ["was_vraag", "verklapt_antwoord", "misleidend", "mens_nodig", "redenen"],
}

RUBRIC = {
    "type": "object",
    "properties": {
        "kern": {"type": "string"},
        "aspecten": {
            "type": "array",
            "minItems": 1,
            "items": {
                "type": "object",
                "properties": {
                    "naam": {"type": "string"},
                    "verplicht": {"type": "boolean"},
                    "onvoldoende": {"type": "string"},
                    "voldoende": {"type": "string"},
                    "goed": {"type": "string"},
                },
                "required": ["naam", "verplicht", "onvoldoende", "voldoende", "goed"],
            },
        },
        "ook_goed": _STRING_LIST,
        "veelgemaakte_fouten": _STRING_LIST,
        "verduidelijkingen_vraag": _STRING_LIST,
        "niveau_regel": {"type": "string"},
    },
    "required": ["kern", "aspecten"],
}

# De designer geeft óf vragen óf een rubric. De rubric is hier een losse `object`; designer.py
# controleert hem daarna tegen RUBRIC (een strenger schema in `format` laat kleine modellen vastlopen
# als ze vragen willen stellen).
DESIGNER = {
    "type": "object",
    "properties": {
        "soort": {"type": "string", "enum": ["vragen", "rubric"]},
        "vragen": _STRING_LIST,
        "rubric": {"type": "object"},
    },
    "required": ["soort", "vragen", "rubric"],
}

_TYPES = {
    "object": dict,
    "array": list,
    "string": str,
    "boolean": bool,
    "number": (int, float),
}


def _type_ok(value, expected):
    if expected == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if expected == "number":
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    py = _TYPES.get(expected)
    return py is None or isinstance(value, py)


def validate(obj, schema, path="$"):
    """Geeft een lijst met fouten terug; leeg = geldig."""
    errors = []
    expected = schema.get("type")
    if expected and not _type_ok(obj, expected):
        return [f"{path}: verwacht {expected}, kreeg {type(obj).__name__}"]
    if "enum" in schema and obj not in schema["enum"]:
        errors.append(f"{path}: {obj!r} is niet een van {schema['enum']}")
    if isinstance(obj, dict):
        for key in schema.get("required", []):
            if key not in obj:
                errors.append(f"{path}: verplicht veld '{key}' ontbreekt")
        for key, sub in schema.get("properties", {}).items():
            if key in obj:
                errors.extend(validate(obj[key], sub, f"{path}.{key}"))
    if isinstance(obj, list):
        if "minItems" in schema and len(obj) < schema["minItems"]:
            errors.append(f"{path}: minimaal {schema['minItems']} item(s)")
        if "items" in schema:
            for i, item in enumerate(obj):
                errors.extend(validate(item, schema["items"], f"{path}[{i}]"))
    return errors


def is_valid(obj, schema):
    return not validate(obj, schema)
