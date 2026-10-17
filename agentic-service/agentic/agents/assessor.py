"""Beoordelaar: beoordeelt het totaal van wat het team op dit level gaf, met de rubric. Bij meerdere
ASSESSOR_MODELS telt de meerderheid; zijn de modellen het oneens, dan is de beoordeling niet zeker."""
import re
import unicodedata
from collections import Counter

from .. import context, schemas
from . import run, system_prompt

_RANK = {"onvoldoende": 0, "voldoende": 1, "goed": 2}
_QUOTES = "\"'“”„‘’‚«»`´"


def build_prompt(task, max_tokens=20000):
    return "\n\n".join([
        context.assignment_block(task, criteria=True),
        context.rubric_block(task.get("rubric")),
        context.clarifications_block(task),
        context.progress_block(task),
        context.build_chat(task, max_tokens, context.progress_summary(task)),
        context.new_message_block(task),
        "Beoordeel alles wat het team op dit level heeft gegeven.",
    ])


def normalize_quote(text):
    """Zoals normalizeQuoteText() in conf/ai.php: kleine letters, geen aanhalingstekens, witruimte samengevoegd."""
    text = unicodedata.normalize("NFC", str(text or "")).lower()
    text = "".join(c for c in text if c not in _QUOTES)
    return re.sub(r"\s+", " ", text).strip()


def verify_citations(aspects, messages):
    """Komt elk citaat echt voor in het genoemde teambericht? Een aspect boven onvoldoende moet een
    citaat hebben; een opgegeven citaat (ook bij onvoldoende) moet kloppen."""
    team = {m.get("id"): normalize_quote(m.get("text", "")) for m in messages if m.get("sender") == "team"}
    for a in aspects:
        quote = normalize_quote(a.get("bewijs", ""))
        msg_id = a.get("bericht") or 0
        if not quote and not msg_id:
            if a.get("niveau") != "onvoldoende":
                return False
            continue
        if not quote or msg_id not in team or quote not in team[msg_id]:
            return False
    return True


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


def assess(llm, settings, task):
    """{'verdict', 'unanimous', 'citations_ok', 'consistent', 'output', 'runs'}"""
    user = build_prompt(task, settings.MAX_CHAT_TOKENS)
    system = system_prompt("assessor")
    outputs, runs = [], []
    for model in settings.ASSESSOR_MODELS:
        output, meta = run(llm, model, settings.ASSESSOR_THINK, system, user, schemas.ASSESSOR)
        outputs.append(output)
        runs.append(meta)
    verdict = majority([o["oordeel"] for o in outputs])
    main = next(o for o in outputs if o["oordeel"] == verdict)
    messages = context.chat_messages(task)
    return {
        "verdict": verdict,
        "unanimous": len({o["oordeel"] for o in outputs}) == 1,
        "citations_ok": verify_citations(main["aspecten"], messages),
        "consistent": expected_pass(task.get("rubric"), main["aspecten"]) == (verdict != "onvoldoende"),
        "output": main,
        "runs": runs,
    }
