"""Taak game_export: een spel als archief v3.

Standaard alleen de spelinformatie (instellingen, logo, opdrachten met bijlagen en rubric). Met
include_progress ook teams, berichten, archief, AI-beoordelingen, mededelingen en downloads in
progress.json, met eigen refs in plaats van id's en zonder e-mailadressen, tokens of docentnamen.
Leest uit een momentopname van de database.
"""
import json
import os
from datetime import datetime, timezone

from worker import config
from worker.archive import ArchiveWriter
from worker.formats import game_v3
from worker.jobs import UserError, register
from worker.snapshot import open_snapshot
from worker.storage import safe_filename

EFFECTIVE_STATUS = ("CASE WHEN status IN ('running', 'paused') AND ends_at IS NOT NULL AND ends_at <= datetime('now') "
                    "THEN 'finished' ELSE status END")


def htdocs_file(htdocs_dir, rel):
    """Absoluut pad van een bestand uit de database (relatief aan htdocs), alleen als het binnen htdocs bestaat."""
    if not rel:
        return None
    base = os.path.abspath(htdocs_dir)
    path = os.path.abspath(os.path.join(base, rel))
    if os.path.commonpath([path, base]) != base or not os.path.isfile(path):
        return None
    return path


def _rubric_value(text):
    if not text:
        return None
    try:
        return json.loads(text)
    except ValueError:
        return text


class _Names:
    """Unieke namen binnen een map in het archief (twee bijlagen met dezelfde naam)."""

    def __init__(self, prefix):
        self.prefix = prefix
        self.by_path = {}
        self.used = set()

    def get(self, path):
        if path in self.by_path:
            return self.by_path[path], False
        base = os.path.basename(path)
        stem, ext = os.path.splitext(base)
        name, n = base, 2
        while name in self.used:
            name = f"{stem}-{n}{ext}"
            n += 1
        self.used.add(name)
        self.by_path[path] = name
        return name, True


def export_game(conn, htdocs_dir, game_id, include_progress, out_path, reporter, app_version="onbekend"):
    """Schrijft het archief naar out_path. conn is bij voorkeur een momentopname. Geeft een samenvatting."""
    game = conn.execute(f"SELECT *, {EFFECTIVE_STATUS} AS effective_status FROM games WHERE id = ?", (game_id,)).fetchone()
    if game is None:
        raise UserError(f"Spel {game_id} bestaat niet")
    rows = conn.execute("SELECT * FROM assignments WHERE game_id = ? ORDER BY assignment_number, id", (game_id,)).fetchall()
    names = _Names("files")
    files_written = 0
    with ArchiveWriter(out_path) as writer:
        assignments = []
        for i, a in enumerate(rows, start=1):
            reporter.progress(int(80 * i / max(1, len(rows))), f"Opdracht {i} van {len(rows)}")
            artifact = None
            if a["artifact_file"]:
                path = htdocs_file(htdocs_dir, a["artifact_file"])
                if path is None:
                    reporter.warn(f"Bijlage van opdracht {a['assignment_number']} ontbreekt op de server")
                else:
                    artifact, new = names.get(path)
                    if new:
                        writer.add_file(f"files/{artifact}", path)
                        files_written += 1
            assignments.append({
                "assignment_number": a["assignment_number"],
                "title": a["title"] or "",
                "description": a["description"] or "",
                "instruction": a["instruction"] or "",
                "criteria": a["criteria"] or "",
                "time_limit": a["time_limit"] or 0,
                "artifact_file": artifact,
                "artifact_name": (a["artifact_name"] if "artifact_name" in a.keys() else None) if artifact else None,
                "ai_rubric": _rubric_value(a["ai_rubric"]),
                "ai_rubric_status": a["ai_rubric_status"] or "none",
                "ai_auto_allowed": 0 if a["ai_auto_allowed"] in (0, "0") else 1,
            })

        logo_name = None
        if game["logo_file"]:
            path = htdocs_file(htdocs_dir, game["logo_file"])
            if path is None:
                reporter.warn("Het logo van het spel ontbreekt op de server")
            else:
                logo_name = os.path.basename(path)
                writer.add_file(f"logo/{logo_name}", path)

        if include_progress:
            reporter.progress(85, "Teams en voortgang")
            progress = progress_data(conn, game)
            writer.add_bytes("progress.json", json.dumps(progress, ensure_ascii=False, indent=2).encode("utf-8"))

        manifest = {
            "format": game_v3.FORMAT,
            "format_version": game_v3.VERSION,
            "exported_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "app_version": app_version,
            "include_progress": bool(include_progress),
            "game": {
                "name": game["name"],
                "ai_mode": game["ai_mode"] or "suggest",
                "ai_max_attempts": game["ai_max_attempts"] or 5,
                "ai_max_questions": game["ai_max_questions"] or 5,
                "logo_file": logo_name,
                "finish_message": game["finish_message"] if "finish_message" in game.keys() else None,
            },
            "assignments": assignments,
            "files": dict(writer.files),
        }
        writer.add_bytes("manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2).encode("utf-8"), record=False)
    return {"game_name": game["name"], "assignments": len(assignments), "files": files_written}


def progress_data(conn, game):
    """Inhoud van progress.json. Refs (t1, m1, e1) vervangen de id's; geen e-mail, tokens of docentnamen."""
    game_id = game["id"]
    teams = conn.execute("SELECT * FROM teams WHERE game_id = ? ORDER BY id", (game_id,)).fetchall()
    team_ref = {t["id"]: f"t{i}" for i, t in enumerate(teams, start=1)}
    team_ids = list(team_ref)
    marks = ",".join("?" * len(team_ids)) or "NULL"

    messages = conn.execute(f"SELECT * FROM team_messages WHERE team_id IN ({marks}) ORDER BY id", team_ids).fetchall()
    message_ref = {m["id"]: f"m{i}" for i, m in enumerate(messages, start=1)}
    evaluations = conn.execute(f"SELECT * FROM ai_evaluations WHERE team_id IN ({marks}) ORDER BY id", team_ids).fetchall()
    evaluation_ref = {e["id"]: f"e{i}" for i, e in enumerate(evaluations, start=1)}

    def kind(row):
        if row["leveled_by"] is not None or row["leveled_by_name"] is not None:
            return "teacher"
        if row["level_started_at"] is not None:
            return "ai"
        return None

    completed = conn.execute("SELECT * FROM completed_assignments WHERE game_id = ? ORDER BY id", (game_id,)).fetchall()
    return {
        "game": {"status": game["effective_status"], "started_at": game["started_at"]},
        "teams": [{"ref": team_ref[t["id"]], "team_name": t["team_name"], "current_level": t["current_level"],
                   "level_updated_at": t["level_updated_at"], "ai_mode": t["ai_mode"]} for t in teams],
        "messages": [{"ref": message_ref[m["id"]], "team_ref": team_ref[m["team_id"]], "assignment_number": m["assignment_number"],
                      "sender": m["sender"], "message": m["message"], "is_read": m["is_read"], "created_at": m["created_at"],
                      "evaluation_ref": evaluation_ref.get(m["ai_evaluation_id"])} for m in messages],
        "evaluations": [{"ref": evaluation_ref[e["id"]], "team_ref": team_ref[e["team_id"]], "team_name": e["team_name"],
                         "assignment_number": e["assignment_number"], "message_ref": message_ref.get(e["message_id"]),
                         **{k: e[k] for k in ("intent", "intent_json", "helper_json", "assessor_json", "validator_json", "verdict",
                                              "decision", "reason", "reply_text", "escalation_reason", "resolved_at",
                                              "teacher_verdict", "models", "duration_ms", "created_at")}}
                        for e in evaluations],
        "completed": [{"team_ref": team_ref.get(c["team_id"]), "team_name": c["team_name"], "assignment_number": c["assignment_number"],
                       "chat_history": c["chat_history"], "created_at": c["created_at"], "leveled_by_kind": kind(c),
                       "evaluation_ref": evaluation_ref.get(c["ai_evaluation_id"]),
                       "accepted_message_ref": message_ref.get(c["accepted_message_id"]),
                       "accepted_answer": c["accepted_answer"], "level_started_at": c["level_started_at"]} for c in completed],
        "progress": [{"team_ref": team_ref[p["team_id"]], "assignment_number": p["assignment_number"], "aspects_json": p["aspects_json"],
                      "summary": p["summary"], "last_message_ref": message_ref.get(p["last_message_id"]), "updated_at": p["updated_at"]}
                     for p in conn.execute(f"SELECT * FROM ai_team_progress WHERE team_id IN ({marks}) ORDER BY id", team_ids)],
        "announcements": [{"team_ref": team_ref.get(a["team_id"]), "message": a["message"], "created_at": a["created_at"]}
                          for a in conn.execute("SELECT * FROM announcements WHERE game_id = ? ORDER BY id", (game_id,))
                          if a["team_id"] is None or a["team_id"] in team_ref],
        "downloads": [{"team_ref": team_ref[d["team_id"]], "assignment_number": d["assignment_number"], "download_count": d["download_count"]}
                      for d in conn.execute(f"SELECT * FROM team_downloads WHERE team_id IN ({marks}) ORDER BY id", team_ids)],
    }


def download_name(game_name, kind="spel", ext="tar.gz"):
    return f"operationroom-{kind}-{safe_filename(game_name)}-{datetime.now():%Y%m%d-%H%M}.{ext}"


@register("game_export")
def handle(job):
    game_id = job.params.get("game_id", job.game_id)
    if game_id is None:
        raise UserError("Geen spel opgegeven")
    with open_snapshot(job.cfg.DB_FILE, job.storage.tmp) as snap:
        row = snap.execute("SELECT name FROM games WHERE id = ?", (game_id,)).fetchone()
        if row is None:
            raise UserError(f"Spel {game_id} bestaat niet")
        name = download_name(row["name"])
        out = os.path.join(job.storage.job_dir(job.id), name)
        summary = export_game(snap, job.cfg.HTDOCS_DIR, int(game_id), bool(job.params.get("include_progress")), out, job,
                              app_version=config.app_version(job.cfg))
    job.set_result(out, name)
    job.summary = (f"{summary['assignments']} opdrachten, {summary['files']} bijlagen"
                   + (", met teams en voortgang" if job.params.get("include_progress") else ""))
