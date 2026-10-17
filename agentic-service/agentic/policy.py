"""Beslisregels (DESIGN §6): pure functies, geen LLM. De server (allowedDecision() in conf/ai.php)
controleert de uitkomst opnieuw en stelt alleen omlaag bij.

ctx (uit de taak + voorfilter): mode, rubric_status, auto_allowed, escalation_open, flag_injection,
recent_attempts, max_attempts, recent_questions, max_questions.

Beslissingen: suggest | reply | level_up | escalate (en voor een vraag ook 'reassess': de validator
vond het geen vraag, beoordeel het bericht alsnog als antwoord).
"""

PASS = ("voldoende", "goed")


def effective_mode(ctx):
    """reply en auto alleen met een goedgekeurde rubric; auto + 'altijd door een mens' = reply."""
    mode = ctx.get("mode", "suggest")
    if mode in ("reply", "auto") and ctx.get("rubric_status") != "approved":
        return "suggest"
    if mode == "auto" and not ctx.get("auto_allowed", True):
        return "reply"
    return mode


def _hard_flags(ctx, result=None):
    reasons = []
    if ctx.get("flag_injection"):
        reasons.append("voorfilter: mogelijke manipulatie in het teambericht")
    if result and result.get("manipulatie_gedetecteerd"):
        reasons.append("validator: manipulatie gedetecteerd")
    return reasons


def _with_reasons(doubts, r):
    """Twijfels plus de redenen van de validator (die alleen tellen als er echt iets mis is)."""
    extra = [x for x in (r.get("redenen") or []) if isinstance(x, str) and x.strip()]
    return "; ".join(doubts + extra)


def decide_answer(ctx, r):
    """r: verdict (beoordelaar), validator_verdict, akkoord, mens_nodig, manipulatie_gedetecteerd,
    feedback_verklapt, citations_ok, consistent, unanimous. → (decision, reason)"""
    verdict = r.get("verdict")
    hard = _hard_flags(ctx, r)
    if hard:
        # Nooit automatisch: altijd naar de docent, in elke modus
        return "escalate", "; ".join(hard)

    doubts = []
    if r.get("validator_verdict") != verdict:
        doubts.append(f"beoordelaar: {verdict}, validator: {r.get('validator_verdict')}")
    if not r.get("akkoord"):
        doubts.append("validator niet akkoord")
    if r.get("mens_nodig"):
        doubts.append("validator: mens nodig")
    if r.get("feedback_verklapt"):
        doubts.append("feedback verklapt (een deel van) het antwoord")
    if not r.get("citations_ok"):
        doubts.append("citaat niet gevonden in het teambericht")
    if not r.get("consistent", True):
        doubts.append("oordeel klopt niet met de aspecten")
    if not r.get("unanimous", True):
        doubts.append("beoordelaars zijn het oneens")
    if ctx.get("rubric_status") != "approved":
        doubts.append("geen goedgekeurde rubric")
    over_limit = ctx.get("recent_attempts", 0) >= ctx.get("max_attempts", 5)
    if over_limit:
        doubts.append(f"pogingslimiet bereikt ({ctx.get('recent_attempts')} antwoorden in 10 minuten)")
    advice = " (level-up-advies)" if verdict in PASS else ""
    mode = effective_mode(ctx)
    if mode == "suggest":
        return "suggest", f"Oordeel {verdict}{advice}" + (": " + _with_reasons(doubts, r) if doubts else "")
    if ctx.get("escalation_open"):
        return "suggest", "Er staat al een escalatie open; de AI stuurt niets naar het team"
    if doubts:
        return "escalate", _with_reasons(doubts, r)
    if verdict in PASS:
        return ("level_up" if mode == "auto" else "reply"), f"Oordeel {verdict}: beoordelaar en validator eens"
    return "reply", "Oordeel onvoldoende: beoordelaar en validator eens"


def decide_question(ctx, r):
    """r: kan_beantwoorden (hulp-agent), was_vraag, verklapt_antwoord, misleidend, mens_nodig (validator).
    → (decision, reason). Een vraag levert nooit een oordeel of level-up."""
    hard = _hard_flags(ctx)
    if hard:
        return "escalate", "; ".join(hard)
    if r.get("was_vraag") is False:
        return "reassess", "validator: dit was geen vraag maar (een deel van) een antwoord"

    doubts = []
    if not r.get("kan_beantwoorden"):
        doubts.append("hulp-agent kan de vraag niet beantwoorden zonder iets te verklappen")
    if r.get("verklapt_antwoord"):
        doubts.append("reactie verklapt (een deel van) het antwoord")
    if r.get("misleidend"):
        doubts.append("reactie is misleidend")
    if r.get("mens_nodig"):
        doubts.append("validator: mens nodig")
    if ctx.get("rubric_status") != "approved":
        doubts.append("geen goedgekeurde rubric")
    over_limit = ctx.get("recent_questions", 0) >= ctx.get("max_questions", 5)
    if over_limit:
        doubts.append(f"vragenlimiet bereikt ({ctx.get('recent_questions')} vragen in 10 minuten)")
    mode = effective_mode(ctx)
    if mode == "suggest":
        return "suggest", "Vraag van het team" + (": " + _with_reasons(doubts, r) if doubts else "")
    if ctx.get("escalation_open"):
        return "suggest", "Er staat al een escalatie open; de AI stuurt niets naar het team"
    if doubts:
        return "escalate", _with_reasons(doubts, r)
    return "reply", "Verduidelijkende vraag: hulp-agent en validator akkoord"
