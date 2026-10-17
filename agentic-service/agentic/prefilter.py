"""Voorfilter in code (geen LLM): lege berichten en verdachte patronen krijgen `flag_injection`.

Een vlag betekent niet dat het team vals speelt, maar dat de AI niets automatisch mag doen: het bericht
gaat altijd naar een docent (policy.py).
"""
import re
import unicodedata

PATTERNS = [
    (r"\bnegeer\b", "negeer"),
    (r"\bvergeet (al )?(je|jouw|alle|de) (instructies|regels|prompt)\b", "vergeet instructies"),
    (r"\bignore (all |any |the )?(previous|prior|above|earlier)\b", "ignore previous"),
    (r"\bdisregard\b", "disregard"),
    (r"\bsystem ?prompt\b|\bsysteem ?prompt\b", "system prompt"),
    (r"\b(jij bent|je bent) nu\b|\byou are now\b", "rolwissel"),
    (r"\bjailbreak\b|\bdeveloper mode\b", "jailbreak"),
    (r"\blevel ?up\b|\blevel omhoog\b", "level up"),
    (r"\bgeef (ons |me |mij |het team )?(een |de )?(cijfer |score )?(10|tien|negen|acht|voldoende)\b", "geef een cijfer"),
    (r"\b(beoordeel|keur|reken) (dit|het|ons|dat)\b.*\b(goed|voldoende)\b", "beoordeel als goed"),
    (r"<\s*/?\s*(system|assistant|instructions?)\s*>", "rol-tag"),
    (r"={3,}\s*(begin|einde|end)\b", "blokgrens"),
]
_COMPILED = [(re.compile(p, re.IGNORECASE), label) for p, label in PATTERNS]


def normalize(text):
    text = unicodedata.normalize("NFKC", str(text or ""))
    return re.sub(r"\s+", " ", text).strip()


def check(text):
    """{'flag_injection': bool, 'redenen': [...]}"""
    norm = normalize(text)
    if not norm:
        return {"flag_injection": True, "redenen": ["leeg bericht"]}
    reasons = [label for rx, label in _COMPILED if rx.search(norm)]
    return {"flag_injection": bool(reasons), "redenen": reasons}
