"""Oude archieven van bin/export_assignments.py (DESIGN §2, §6.3).

v1 (eerste versie, commit c249ede): manifest is een lijst met assignment_number, title, description,
instruction, criteria, time_limit en artifact_file. v2 voegt ai_rubric (JSON-tekst), ai_rubric_status en
ai_auto_allowed toe. Ontbrekende velden krijgen de standaardwaarden; de regels voor de rubric zijn die van
het oude importscript.
"""
import json
import os

from worker.formats.model import RUBRIC_STATUSES, Assignment, FileRef, GameInfo, ImportModel
from worker.jobs import UserError

AI_FIELDS = ("ai_rubric", "ai_rubric_status", "ai_auto_allowed")


def detect(manifest):
    return "v2" if any(isinstance(a, dict) and any(k in a for k in AI_FIELDS) for a in manifest) else "v1"


def _int(value, default=0):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _text(value):
    return "" if value is None else str(value)


def rubric_fields(raw_rubric, raw_status):
    """(rubric als JSON-tekst of None, status) volgens de regels van het oude importscript."""
    rubric = raw_rubric
    if isinstance(rubric, (dict, list)):
        rubric = json.dumps(rubric, ensure_ascii=False)
    rubric = rubric or None
    status = raw_status or "none"
    if status not in RUBRIC_STATUSES or not rubric:
        status = "none" if not rubric else "draft"
    return rubric, status


def auto_allowed(value):
    return 0 if value in (0, "0", False) else 1


def artifact_ref(name, reader, warnings, number):
    if not name:
        return None
    base = os.path.basename(str(name))
    member = f"files/{base}"
    if not reader.has(member):
        warnings.append(f"Bijlage {base} van opdracht {number} ontbreekt in het archief")
        return None
    return FileRef(member, size=reader.size(member))


def read_legacy(manifest, reader):
    if not all(isinstance(a, dict) for a in manifest):
        raise UserError("Ongeldig manifest: elke opdracht moet een object zijn")
    fmt = detect(manifest)
    warnings = []
    assignments = []
    for a in manifest:
        if a.get("assignment_number") is None or not str(a.get("title") or "").strip():
            raise UserError("Ongeldig manifest: een opdracht zonder nummer of titel")
        number = _int(a.get("assignment_number"), None)
        if number is None:
            raise UserError(f"Ongeldig opdrachtnummer: {a.get('assignment_number')!r}")
        rubric, status = rubric_fields(a.get("ai_rubric"), a.get("ai_rubric_status"))
        assignments.append(Assignment(
            assignment_number=number,
            title=_text(a.get("title")),
            description=_text(a.get("description")),
            instruction=_text(a.get("instruction")),
            criteria=_text(a.get("criteria")),
            time_limit=_int(a.get("time_limit"), 0),
            artifact=artifact_ref(a.get("artifact_file"), reader, warnings, number),
            ai_rubric=rubric,
            ai_rubric_status=status,
            ai_auto_allowed=auto_allowed(a.get("ai_auto_allowed", 1)),
        ))
    return ImportModel(format=fmt, game=GameInfo(), assignments=assignments, warnings=warnings)
