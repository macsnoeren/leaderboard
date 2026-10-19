"""Archiefformaat v3: manifest is een object met format/format_version.

manifest.json, files/<naam>, logo/<naam> en (bij include_progress) progress.json. De velden per opdracht
heten hetzelfde als in v1/v2; ai_rubric is hier een object.
"""
import json
import os

from worker.formats.legacy import artifact_ref, auto_allowed, rubric_fields
from worker.formats.model import AI_MODES, Assignment, FileRef, GameInfo, ImportModel
from worker.jobs import UserError

# Exports van vóór de naamswijziging hebben format "zebrawave-game": die blijven leesbaar
FORMAT = "operationroom-game"
FORMATS = (FORMAT, "zebrawave-game")
VERSION = 3


def _int(value, default):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _file_name(value):
    """Oorspronkelijke naam van een bijlage: alleen de naam, zonder stuurtekens, max. 200 tekens."""
    if not isinstance(value, str):
        return None
    name = os.path.basename(value.replace("\\", "/"))
    name = "".join(ch for ch in name if ch >= " " and ch != "\x7f").strip()[:200]
    return name or None


def _finish_message(value):
    """Eindtekst van het spel: tekst, max. 5000 tekens (zoals FINISH_MESSAGE_MAX_LENGTH in conf/game.php)."""
    if not isinstance(value, str) or not value.strip():
        return None
    return value.strip()[:5000]


def _file_ref(member, files, reader):
    info = files.get(member) if isinstance(files, dict) else None
    info = info if isinstance(info, dict) else {}
    return FileRef(member, size=reader.size(member), sha256=info.get("sha256"))


def read_v3(manifest, reader):
    warnings = []
    files = manifest.get("files") or {}
    g = manifest.get("game") or {}
    if not isinstance(g, dict):
        raise UserError("Ongeldig manifest: 'game' moet een object zijn")
    logo = None
    if g.get("logo_file"):
        member = f"logo/{os.path.basename(str(g['logo_file']))}"
        if reader.has(member):
            logo = _file_ref(member, files, reader)
        else:
            warnings.append(f"Logo {g['logo_file']} ontbreekt in het archief")
    mode = g.get("ai_mode") if g.get("ai_mode") in AI_MODES else "suggest"
    game = GameInfo(
        name=str(g.get("name") or "").strip() or None,
        ai_mode=mode,
        ai_max_attempts=min(50, max(1, _int(g.get("ai_max_attempts"), 5))),
        ai_max_questions=min(50, max(1, _int(g.get("ai_max_questions"), 5))),
        logo=logo,
        finish_message=_finish_message(g.get("finish_message")),
    )
    raw = manifest.get("assignments")
    if not isinstance(raw, list) or not all(isinstance(a, dict) for a in raw):
        raise UserError("Ongeldig manifest: 'assignments' moet een lijst met objecten zijn")
    assignments = []
    for a in raw:
        number = _int(a.get("assignment_number"), None)
        if number is None or not str(a.get("title") or "").strip():
            raise UserError("Ongeldig manifest: een opdracht zonder nummer of titel")
        artifact = artifact_ref(a.get("artifact_file"), reader, warnings, number)
        if artifact is not None:
            artifact = _file_ref(artifact.name, files, reader)
        rubric, status = rubric_fields(a.get("ai_rubric"), a.get("ai_rubric_status"))
        assignments.append(Assignment(
            assignment_number=number,
            title=str(a.get("title")),
            description=str(a.get("description") or ""),
            instruction=str(a.get("instruction") or ""),
            criteria=str(a.get("criteria") or ""),
            time_limit=_int(a.get("time_limit"), 0),
            artifact=artifact,
            artifact_name=_file_name(a.get("artifact_name")) if artifact is not None else None,
            ai_rubric=rubric,
            ai_rubric_status=status,
            ai_auto_allowed=auto_allowed(a.get("ai_auto_allowed", 1)),
        ))
    progress = None
    if manifest.get("include_progress"):
        if not reader.has("progress.json"):
            raise UserError("progress.json ontbreekt, terwijl het manifest voortgang belooft")
        try:
            progress = json.loads(reader.read_bytes("progress.json").decode("utf-8"))
        except (ValueError, UnicodeDecodeError) as e:
            raise UserError("progress.json is geen geldige JSON") from e
        if not isinstance(progress, dict):
            raise UserError("progress.json moet een object zijn")
    return ImportModel(format="v3", game=game, assignments=assignments, progress=progress, warnings=warnings)
