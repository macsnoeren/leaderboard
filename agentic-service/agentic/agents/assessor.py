"""Beoordelaar: beoordeelt het totaal van wat het team op dit level gaf, met de rubric. Bij meerdere
ASSESSOR_MODELS telt de meerderheid; zijn de modellen het oneens, dan is de beoordeling niet zeker.

Teams geven hun antwoord vaak in delen. Zet de beoordelaar een aspect lager dan de tussenstand zonder een
nieuwer teambericht over dat aspect te citeren (hij vergat een eerder deel), dan krijgt hij één herkansing
met die aspecten erbij. Met zo'n citaat is het een nieuw, fout standpunt en gaat het aspect terecht omlaag.
Het oordeel moet passen bij de tussenstand ná dit bericht (merge_progress(), dezelfde regel als
updateTeamProgress() in conf/ai.php); zo niet, dan is het niet consistent en beslist een operator."""
import re
import unicodedata
from collections import Counter

from .. import context, schemas
from . import run, system_prompt

_RANK = {"onvoldoende": 0, "voldoende": 1, "goed": 2}
_QUOTES = "\"'“”„‘’‚«»`´"
# Teams kennen de rubric en de criteria niet: feedback die daarnaar verwijst helpt ze niet en verraadt de opbouw
_RUBRIC_TALK = re.compile(r"\b(rubrics?|aspect(en)?|criteri(a|um)|beoordelingscriteria|antwoordmodel|modelantwoord"
                          r"|antwoord van de (docent|operator))\b", re.IGNORECASE)
_COUNT_TALK = re.compile(r"\b(twee|drie|vier|vijf|zes|zeven|acht|negen|tien|\d+)\s+(de\s+)?(punten|onderdelen|elementen|zaken)\b",
                         re.IGNORECASE)


def build_prompt(task, max_tokens=20000, forgotten=None):
    parts = [
        context.assignment_block(task, criteria=True),
        context.rubric_block(task.get("rubric")),
        context.clarifications_block(task),
        context.progress_block(task),
        context.build_chat(task, max_tokens, context.progress_summary(task)),
        context.new_message_block(task),
        "Beoordeel alles wat het team op dit level heeft gegeven, niet alleen het nieuwe bericht.",
    ]
    if forgotten:
        parts += [
            context.block("AL BEREIKT", "\n".join(context.format_aspect(a) for a in forgotten)),
            "Let op: deze aspecten had het team in eerdere berichten al bereikt, maar je beoordeling zette ze lager "
            "zonder een later teambericht over dat aspect te citeren. Neem niveau, bewijs en bericht over uit AL BEREIKT. "
            "Alleen als het team er in een later bericht zelf iets anders (fouts) over zei, zet je het lager, met dat "
            "latere bericht als bewijs. Noem in feedback_team niets als ontbrekend dat het team al gaf.",
        ]
    return "\n\n".join(parts)


def rubric_talk(feedback, task):
    """Een foutmelding als de feedback aan het team de rubric, de criteria of het aantal vereiste punten noemt
    (behalve woorden die de opdracht zelf gebruikt), anders None. Gaat als check naar het model, dat het dan
    één keer opnieuw mag proberen."""
    question = " ".join(str(task.get(k, "")) for k in ("assignment_title", "description", "instruction")).lower()
    found = [m.group(0) for m in _RUBRIC_TALK.finditer(feedback or "") if m.group(0).lower() not in question]
    found += [m.group(0) for m in _COUNT_TALK.finditer(feedback or "") if m.group(1).lower() not in question]
    if not found:
        return None
    return ("feedback_team noemt iets wat teams niet kennen (" + ", ".join(found) + "): noem nooit de rubric, aspecten, "
            "criteria of hoeveel punten er nodig zijn; verwijs alleen naar wat de opdracht zelf vraagt")


def normalize_quote(text):
    """Zoals normalizeQuoteText() in conf/ai.php: kleine letters, geen aanhalingstekens, witruimte samengevoegd."""
    text = unicodedata.normalize("NFC", str(text or "")).lower()
    text = "".join(c for c in text if c not in _QUOTES)
    return re.sub(r"\s+", " ", text).strip()


def _team_texts(messages):
    return {m.get("id"): normalize_quote(m.get("text", "")) for m in messages if m.get("sender") == "team"}


def _cited(aspect, team):
    """Staat het citaat van dit aspect echt in het genoemde teambericht?"""
    quote = normalize_quote(aspect.get("bewijs", ""))
    msg_id = aspect.get("bericht") or 0
    return bool(quote) and msg_id in team and quote in team[msg_id]


def verify_citations(aspects, messages):
    """Komt elk citaat echt voor in het genoemde teambericht? Een aspect boven onvoldoende moet een
    citaat hebben; een opgegeven citaat (ook bij onvoldoende) moet kloppen."""
    team = _team_texts(messages)
    for a in aspects:
        if not normalize_quote(a.get("bewijs", "")) and not a.get("bericht"):
            if a.get("niveau") != "onvoldoende":
                return False
            continue
        if not _cited(a, team):
            return False
    return True


def _newer_statement(new, old, team):
    """Mag `new` het aspect in de tussenstand (`old`) vervangen? Alleen met een geldig citaat uit een nieuwer
    teambericht (ook als het lager is: het team zegt er iets fouts over), of uit hetzelfde bericht als het hoger is."""
    if not _cited(new, team):
        return False
    if not old:
        return True
    old_msg = old.get("bericht") or 0
    return new["bericht"] > old_msg or (new["bericht"] == old_msg and _RANK[new["niveau"]] >= _RANK.get(old.get("niveau"), 0))


def merge_progress(previous, aspects, messages):
    """De tussenstand na deze beoordeling, zoals updateTeamProgress() in conf/ai.php hem opslaat: per aspect
    het laatste standpunt met een geldig citaat. Zonder nieuwer citaat blijft een aspect staan."""
    team = _team_texts(messages)
    merged = {normalize_quote(a.get("naam")): a for a in previous or []}
    for a in aspects:
        key = normalize_quote(a.get("naam"))
        if key and a.get("niveau") in _RANK and _newer_statement(a, merged.get(key), team):
            merged[key] = a
    return list(merged.values())


def forgotten_aspects(previous, aspects, messages, rubric=None):
    """Aspecten uit de tussenstand die de beoordeling lager zet (of weglaat) zonder een nieuwer teambericht
    over dat aspect te citeren: dan vergat de beoordelaar een eerder gegeven deel."""
    team = _team_texts(messages)
    now = {normalize_quote(a.get("naam")): a for a in aspects}
    names = {normalize_quote(a.get("naam")) for a in (rubric or {}).get("aspecten", [])}
    forgotten = []
    for old in previous or []:
        key = normalize_quote(old.get("naam"))
        new = now.get(key)
        if names and key not in names:
            continue
        if _RANK.get(old.get("niveau"), 0) > _RANK.get((new or {}).get("niveau"), 0) and not (new and _newer_statement(new, old, team)):
            forgotten.append(old)
    return forgotten


def expected_pass(rubric, aspects):
    """Haalt het team volgens de aspecten de rubric (alle verplichte aspecten minimaal voldoende)?"""
    levels = {normalize_quote(a.get("naam")): a.get("niveau") for a in aspects}
    for asp in (rubric or {}).get("aspecten", []):
        if asp.get("verplicht") and _RANK.get(levels.get(normalize_quote(asp.get("naam"))), 0) < 1:
            return False
    return True


def majority(verdicts):
    """Meest gekozen oordeel; bij gelijke stand het strengste."""
    counts = Counter(verdicts)
    best = max(counts.values())
    return min((v for v, n in counts.items() if n == best), key=lambda v: _RANK[v])


def _run_all(llm, settings, task, user):
    system = system_prompt("assessor")
    outputs, runs = [], []
    for model in settings.ASSESSOR_MODELS:
        output, meta = run(llm, model, settings.ASSESSOR_THINK, system, user, schemas.ASSESSOR,
                           check=lambda obj: rubric_talk(obj.get("feedback_team"), task))
        outputs.append(output)
        runs.append(meta)
    verdict = majority([o["oordeel"] for o in outputs])
    return outputs, runs, verdict, next(o for o in outputs if o["oordeel"] == verdict)


def assess(llm, settings, task):
    """{'verdict', 'unanimous', 'citations_ok', 'consistent', 'output', 'runs', 'forgotten'}"""
    previous = (task.get("progress") or {}).get("aspects") or []
    messages = context.chat_messages(task)
    outputs, runs, verdict, main = _run_all(llm, settings, task, build_prompt(task, settings.MAX_CHAT_TOKENS))
    forgotten = forgotten_aspects(previous, main["aspecten"], messages, task.get("rubric"))
    if forgotten:
        # Eén herkansing met de vergeten aspecten erbij; beide rondes staan in assessor_json
        outputs, more, verdict, main = _run_all(llm, settings, task, build_prompt(task, settings.MAX_CHAT_TOKENS, forgotten))
        runs += more
    merged = merge_progress(previous, main["aspecten"], messages)
    return {
        "verdict": verdict,
        "unanimous": len({o["oordeel"] for o in outputs}) == 1,
        "citations_ok": verify_citations(main["aspecten"], messages),
        "consistent": expected_pass(task.get("rubric"), merged) == (verdict != "onvoldoende"),
        "output": main,
        "runs": runs,
        "forgotten": [a.get("naam") for a in forgotten],
    }
