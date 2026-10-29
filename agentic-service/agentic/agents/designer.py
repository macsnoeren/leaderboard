"""Opdracht-assistent: stelt 1–5 vragen aan de docent of stelt een rubric voor. In de laatste ronde
altijd een rubric. Het antwoord van de docent (criteria) verandert hij nooit.

Controles in code, omdat het model ze in de praktijk overslaat: verwijzen de criteria naar de bijlage en
heeft de docent nog niets beantwoord, dan eerst vragen; tijden en getallen in de rubric moeten in de tekst
van de docent voorkomen (geen verzonnen feiten); en hooguit MAX_REQUIRED aspecten zijn verplicht (het
antwoord van de docent is "goed", voor "voldoende" volstaat de kern). De operator kan dat in de tabel
nog aanpassen.
"""
import json
import re

from .. import context, schemas
from . import run, system_prompt

MAX_QUESTIONS = 5
# Alleen de kern is verplicht; anders moet een team voor "voldoende" al bijna het hele antwoord van de docent geven
MAX_REQUIRED = 2
_ATTACHMENT = re.compile(r"\b(bijlage|bijgevoegd|attachment|dossier|pdf|zie (het )?bestand|camerabeelden)\b", re.IGNORECASE)
# Tijden (23:15, 23.15) en getallen van twee of meer cijfers
_FACTS = re.compile(r"\b\d{1,2}[:.]\d{2}\b|\b\d{2,}\b")


def teacher_text(job):
    parts = [job.get("title", ""), job.get("description", ""), job.get("instruction", ""), job.get("criteria", "")]
    parts += [d.get("message", "") for d in job.get("dialog") or [] if d.get("role") == "teacher"]
    return " ".join(str(p) for p in parts)


def needs_attachment_facts(job):
    """De criteria verwijzen naar de bijlage en de docent heeft nog geen vraag beantwoord."""
    answered = any(d.get("role") == "teacher" for d in job.get("dialog") or [])
    return bool(_ATTACHMENT.search(str(job.get("criteria", "")))) and not answered


# Woorden die in een uitleg van de vraag gewoon mogen, ook als ze in de criteria staan
_STOPWORDS = set("""alleen alle antwoord antwoorden beide daarom deze dit door echt eerst genoeg goed geen genoemd heeft
hebben hij hoeft hun iets jullie maar meer mogen moet moeten noemen naam niet nodig onvoldoende ook over telt tellen
voldoende waarom wat wel welke wie zijn zonder erbij minimaal precies volgens""".split())
_WORD = re.compile(r"[a-zà-ÿ]{3,}")


def answer_terms(job):
    """Woorden, namen en getallen die het antwoord verraden: uit de criteria (en tijden/getallen uit de
    antwoorden van de docent), maar niet uit de vraag zelf."""
    question = " ".join(str(job.get(k, "")) for k in ("title", "description", "instruction")).lower()
    asked = set(_WORD.findall(question))
    terms = {w for w in _WORD.findall(str(job.get("criteria", "")).lower()) if w not in asked and w not in _STOPWORDS}
    terms |= {m.group(0) for m in _FACTS.finditer(teacher_text(job)) if m.group(0) not in question}
    return terms


def strip_revealing(lines, job):
    """(veilige regels, weggehaalde regels) voor verduidelijkingen_vraag: een regel met een antwoordterm
    gaat nooit naar teams."""
    terms = answer_terms(job)
    keep, removed = [], []
    for line in lines:
        words = set(_WORD.findall(line.lower())) | {m.group(0) for m in _FACTS.finditer(line)}
        (removed if words & terms else keep).append(line)
    return keep, removed


def invented_facts(rubric, job):
    """Tijden en getallen in de rubric die nergens in de tekst van de docent staan."""
    known = teacher_text(job)
    known_norm = known.replace(".", ":")
    found = {m.group(0) for m in _FACTS.finditer(json.dumps(rubric, ensure_ascii=False))}
    return sorted(f for f in found if f not in known and f.replace(".", ":") not in known_norm)


def must_give_rubric(job):
    """In de laatste ronde (standaard ronde 3) altijd een rubric; de server weigert daarna ook vragen."""
    return job.get("round", 1) >= job.get("max_rounds", 3)


def build_prompt(job):
    lines = [
        f"Titel: {context.neutralize(job.get('title', ''))}",
        f"Beschrijving: {context.neutralize(job.get('description', ''))}",
        f"Instructie (wat het team moet doen): {context.neutralize(job.get('instruction', ''))}",
        f"Antwoord van de docent (criteria): {context.neutralize(job.get('criteria', ''))}",
        "Bijlage: " + ("ja, er hoort een bestand bij deze opdracht (dat kun jij niet lezen)" if job.get("has_attachment") else "nee"),
    ]
    dialog = "\n".join(f"{'Vraag van de AI' if d.get('role') == 'ai' else 'Antwoord van de docent'}: {context.neutralize(d.get('message', ''))}"
                       for d in job.get("dialog") or []) or "(nog geen)"
    parts = [context.block("OPDRACHT", "\n".join(lines)), context.block("EERDERE VRAGEN EN ANTWOORDEN", dialog)]
    if job.get("rubric"):
        parts.append(context.rubric_block(job["rubric"]))
    if must_give_rubric(job):
        parts.append(f"Ronde {job.get('round', 1)}: LAATSTE RONDE. Geef nu een rubric (soort \"rubric\"), geen vragen meer.")
    else:
        parts.append(f"Ronde {job.get('round', 1)} van {job.get('max_rounds', 3)}. Stel vragen of geef een rubric.")
    return "\n\n".join(parts)


def make_check(job):
    must_rubric = must_give_rubric(job)

    def check(obj):
        if obj["soort"] == "vragen":
            if must_rubric:
                return "dit is de laatste ronde: geef een rubric, geen vragen"
            questions = [q for q in obj.get("vragen", []) if isinstance(q, str) and q.strip()]
            if not 1 <= len(questions) <= MAX_QUESTIONS:
                return f"geef 1 tot {MAX_QUESTIONS} vragen"
            return None
        if not must_rubric and needs_attachment_facts(job):
            return ("de criteria verwijzen naar de bijlage, die je niet kunt lezen: stel eerst vragen over "
                    "het concrete antwoord (soort \"vragen\")")
        rubric = obj.get("rubric") or {}
        errors = schemas.validate(rubric, schemas.RUBRIC)
        if errors:
            return "rubric: " + "; ".join(errors[:5])
        required = sum(1 for a in rubric["aspecten"] if a.get("verplicht"))
        if not required:
            return "rubric: minstens één aspect moet verplicht zijn"
        if required > MAX_REQUIRED:
            return (f"rubric: {required} aspecten zijn verplicht; maak alleen de kern verplicht (hooguit {MAX_REQUIRED}). "
                    "Details en extra punten maken het verschil tussen voldoende en goed")
        names = [a["naam"].strip().lower() for a in rubric["aspecten"]]
        if "" in names or len(set(names)) != len(names):
            return "rubric: elk aspect heeft een eigen, niet-lege naam"
        if not rubric["kern"].strip():
            return "rubric: de kern is leeg"
        invented = invented_facts(rubric, job)
        if invented:
            return ("rubric bevat waarden die niet in de opdracht of de antwoorden van de docent staan: "
                    + ", ".join(invented) + ". Verzin geen feiten" + ("" if must_rubric else "; vraag het de docent"))
        return None
    return check


def design(llm, settings, job):
    """({'soort': 'vragen', 'vragen': [...]} of {'soort': 'rubric', 'rubric': {...}}, meta)"""
    output, meta = run(llm, settings.DESIGNER_MODEL, settings.DESIGNER_THINK, system_prompt("designer"),
                       build_prompt(job), schemas.DESIGNER, check=make_check(job))
    if output["soort"] == "vragen":
        return {"soort": "vragen", "vragen": [q.strip() for q in output["vragen"] if isinstance(q, str) and q.strip()]}, meta
    rubric = dict(output["rubric"])
    keep, removed = strip_revealing(rubric.get("verduidelijkingen_vraag") or [], job)
    rubric["verduidelijkingen_vraag"] = keep
    if removed:
        meta["verwijderde_verduidelijkingen"] = removed
    return {"soort": "rubric", "rubric": rubric}, meta
