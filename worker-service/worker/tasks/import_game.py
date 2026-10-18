"""Taak game_import: een archief (v1, v2 of v3) als nieuw spel (DESIGN §6.3).

Altijd een nieuw spel: concept (met voortgang: gepauzeerd, of afgelopen als het bronspel klaar was),
nieuwe spelcode, registratie dicht, gekoppeld aan de importerende beheerder. Bijlagen en logo krijgen een
eigen naam. Alles in één transactie; bij een fout worden de geschreven bestanden weer verwijderd.
"""
import logging
import os
import secrets
import time

from worker import db
from worker.archive import ArchiveReader
from worker.formats import GAME_ALLOWED, read_archive
from worker.formats.model import AI_MODES
from worker.jobs import UserError, register

logger = logging.getLogger(__name__)

# Zelfde als generateJoinCode() in conf/game.php
JOIN_CODE_ALPHABET = "ABCDEFGHJKMNPQRSTUVWXYZ23456789"
JOIN_CODE_LENGTH = 6
# Zelfde als assignments.php en game.php
ARTIFACT_EXTENSIONS = ("pdf", "zip", "txt", "jpg", "png")
LOGO_EXTENSIONS = ("png", "jpg", "jpeg", "webp")
NAME_MAX_LENGTH = 100
SENDERS = ("team", "teacher", "ai", "suggestion")


def generate_join_code(conn):
    while True:
        code = "".join(secrets.choice(JOIN_CODE_ALPHABET) for _ in range(JOIN_CODE_LENGTH))
        if conn.execute("SELECT COUNT(*) FROM games WHERE join_code = ?", (code,)).fetchone()[0] == 0:
            return code


def unique_game_name(conn, name):
    name = name[:NAME_MAX_LENGTH]
    candidate, n = name, 2
    while conn.execute("SELECT COUNT(*) FROM games WHERE lower(name) = lower(?)", (candidate,)).fetchone()[0]:
        suffix = f" ({n})"
        candidate = name[:NAME_MAX_LENGTH - len(suffix)] + suffix
        n += 1
    return candidate


def _ext(name):
    base = os.path.basename(name).lower()
    return base.rsplit(".", 1)[1] if "." in base else ""


def renumber(model, reporter):
    """Opdrachten moeten 1..N zonder gaten zijn. Hernummert in volgorde (met waarschuwing), behalve bij voortgang."""
    numbers = [a.assignment_number for a in model.assignments]
    if numbers == list(range(1, len(numbers) + 1)):
        return
    if sorted(numbers) == list(range(1, len(numbers) + 1)):
        model.assignments.sort(key=lambda a: a.assignment_number)
        return
    if model.progress is not None:
        raise UserError("De opdrachten zijn niet doorlopend genummerd (1..N); bij een import met voortgang kan niet "
                        "hernummerd worden, omdat de levels dan niet meer kloppen")
    order = sorted(range(len(model.assignments)), key=lambda i: (model.assignments[i].assignment_number, i))
    model.assignments = [model.assignments[i] for i in order]
    old = ", ".join(str(a.assignment_number) for a in model.assignments)
    for i, a in enumerate(model.assignments, start=1):
        a.assignment_number = i
    reporter.warn(f"Opdrachten hernummerd naar 1..{len(numbers)} (was: {old})")


def import_archive(conn, htdocs_dir, archive_path, reporter, name=None, created_by=None, fallback_name="Import",
                   max_bytes=500 * 1024 * 1024, max_files=10000):
    """Importeert het archief als nieuw spel. conn: verbinding met de echte database. Geeft het nieuwe game_id."""
    with ArchiveReader(archive_path, GAME_ALLOWED, max_bytes, max_files) as reader:
        model = read_archive(reader)
        for w in model.warnings:
            reporter.warn(w)
        if not model.assignments:
            reporter.warn("Het archief bevat geen opdrachten")
        renumber(model, reporter)
        game_name = (name or "").strip() or model.game.name or fallback_name
        written = []
        try:
            with db.transaction(conn):
                game_id = _insert_game(conn, model, game_name, created_by)
                total = len(model.assignments)
                for i, a in enumerate(model.assignments, start=1):
                    reporter.progress(10 + int(70 * i / max(1, total)), f"Opdracht {i} van {total}")
                    rel = None
                    if a.artifact is not None:
                        ext = _ext(a.artifact.name)
                        if ext not in ARTIFACT_EXTENSIONS:
                            reporter.warn(f"Bijlage {os.path.basename(a.artifact.name)} van opdracht {a.assignment_number} "
                                          "overgeslagen: dit soort bestand is niet toegestaan")
                        else:
                            rel = f"artifacts/game_{game_id}_assignment_{a.assignment_number}_{int(time.time())}_{secrets.token_hex(3)}.{ext}"
                            target = os.path.join(htdocs_dir, rel)
                            os.makedirs(os.path.dirname(target), exist_ok=True)
                            written.append(target)
                            reader.extract_to(a.artifact.name, target, sha256=a.artifact.sha256)
                    conn.execute(
                        "INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, time_limit, "
                        "artifact_file, ai_rubric, ai_rubric_status, ai_auto_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        (game_id, a.assignment_number, a.title, a.description, a.instruction, a.criteria, a.time_limit, rel,
                         a.ai_rubric, a.ai_rubric_status, a.ai_auto_allowed))
                if model.game.logo is not None:
                    ext = _ext(model.game.logo.name)
                    if ext not in LOGO_EXTENSIONS:
                        reporter.warn("Logo overgeslagen: alleen PNG, JPG en WebP zijn toegestaan")
                    else:
                        rel = f"uploads/game_{game_id}_logo_{int(time.time())}_{secrets.token_hex(3)}.{ext}"
                        target = os.path.join(htdocs_dir, rel)
                        os.makedirs(os.path.dirname(target), exist_ok=True)
                        written.append(target)
                        reader.extract_to(model.game.logo.name, target, sha256=model.game.logo.sha256)
                        conn.execute("UPDATE games SET logo_file = ? WHERE id = ?", (rel, game_id))
                if created_by is not None:
                    conn.execute("INSERT OR IGNORE INTO game_teachers (game_id, teacher_id) VALUES (?, ?)", (game_id, created_by))
                teams = 0
                if model.progress is not None:
                    reporter.progress(85, "Teams en voortgang")
                    teams = import_progress(conn, game_id, model.progress, total)
                final_name = conn.execute("SELECT name FROM games WHERE id = ?", (game_id,)).fetchone()[0]
                db.audit(conn, "GAME_IMPORT", f"Spel '{final_name}' (#{game_id}) geïmporteerd uit een {model.format}-archief "
                                              f"({total} opdrachten" + (f", {teams} teams)" if model.progress is not None else ")"))
        except BaseException:
            for path in written:
                try:
                    os.remove(path)
                except OSError:
                    pass
            raise
    reporter.summary = f"Spel '{final_name}' met {total} opdrachten" + (f" en {teams} teams" if model.progress is not None else "")
    return game_id


def _insert_game(conn, model, game_name, created_by):
    status, started_at = "draft", None
    if model.progress is not None:
        source = (model.progress.get("game") or {}) if isinstance(model.progress.get("game"), dict) else {}
        status = {"draft": "draft", "finished": "finished"}.get(source.get("status"), "paused")
        started_at = source.get("started_at") if status != "draft" else None
    cur = conn.execute(
        "INSERT INTO games (name, status, join_code, registration_open, started_at, created_at, created_by, ai_mode, "
        "ai_max_attempts, ai_max_questions) VALUES (?, ?, ?, 0, ?, datetime('now'), ?, ?, ?, ?)",
        (unique_game_name(conn, game_name), status, generate_join_code(conn), started_at, created_by,
         model.game.ai_mode if model.game.ai_mode in AI_MODES else "suggest", model.game.ai_max_attempts, model.game.ai_max_questions))
    return cur.lastrowid


def _list(progress, key):
    value = progress.get(key) or []
    if not isinstance(value, list) or not all(isinstance(x, dict) for x in value):
        raise UserError(f"progress.json: '{key}' moet een lijst met objecten zijn")
    return value


def _ref(mapping, ref, what, required=True):
    if ref is None and not required:
        return None
    if ref not in mapping:
        raise UserError(f"progress.json verwijst naar een onbekend {what} ({ref!r})")
    return mapping[ref]


def import_progress(conn, game_id, progress, total):
    """Teams en alles wat erbij hoort; id's opnieuw uitgedeeld, verwijzingen via refs omgezet. Geeft het aantal teams."""
    teams = {}
    for t in _list(progress, "teams"):
        level = max(0, min(total + 1, int(t.get("current_level") or 0)))
        mode = t.get("ai_mode") if t.get("ai_mode") in AI_MODES else "suggest"
        try:
            cur = conn.execute(
                "INSERT INTO teams (game_id, team_name, email, current_level, level_updated_at, access_token, ai_mode) "
                "VALUES (?, ?, NULL, ?, COALESCE(?, datetime('now')), ?, ?)",
                (game_id, str(t.get("team_name") or "Team"), level, t.get("level_updated_at"), secrets.token_hex(32), mode))
        except Exception as e:
            raise UserError(f"Team {t.get('team_name')!r} komt twee keer voor") from e
        teams[t.get("ref")] = cur.lastrowid

    messages = {}
    message_eval = []
    for m in _list(progress, "messages"):
        sender = m.get("sender") if m.get("sender") in SENDERS else "teacher"
        cur = conn.execute(
            "INSERT INTO team_messages (team_id, assignment_number, sender, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, COALESCE(?, datetime('now')))",
            (_ref(teams, m.get("team_ref"), "team"), m.get("assignment_number"), sender, m.get("message") or "",
             1 if m.get("is_read") else 0, m.get("created_at")))
        messages[m.get("ref")] = cur.lastrowid
        if m.get("evaluation_ref"):
            message_eval.append((cur.lastrowid, m["evaluation_ref"]))

    evaluations = {}
    eval_fields = ("intent", "intent_json", "helper_json", "assessor_json", "validator_json", "verdict", "decision", "reason",
                   "reply_text", "escalation_reason", "resolved_at", "teacher_verdict", "models", "duration_ms")
    for e in _list(progress, "evaluations"):
        cols = ", ".join(eval_fields)
        cur = conn.execute(
            f"INSERT INTO ai_evaluations (game_id, team_id, team_name, assignment_number, message_id, {cols}, created_at) "
            f"VALUES (?, ?, ?, ?, ?, {', '.join('?' * len(eval_fields))}, COALESCE(?, datetime('now')))",
            (game_id, _ref(teams, e.get("team_ref"), "team"), e.get("team_name"), e.get("assignment_number"),
             messages.get(e.get("message_ref"), 0), *(e.get(k) for k in eval_fields), e.get("created_at")))
        evaluations[e.get("ref")] = cur.lastrowid
    for message_id, ref in message_eval:
        conn.execute("UPDATE team_messages SET ai_evaluation_id = ? WHERE id = ?", (_ref(evaluations, ref, "beoordeling"), message_id))

    for c in _list(progress, "completed"):
        kind = c.get("leveled_by_kind")
        conn.execute(
            "INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history, created_at, leveled_by, "
            "leveled_by_name, ai_evaluation_id, accepted_message_id, accepted_answer, level_started_at) "
            "VALUES (?, ?, ?, ?, ?, COALESCE(?, datetime('now')), NULL, ?, ?, ?, ?, ?)",
            (game_id, _ref(teams, c.get("team_ref"), "team", required=False), c.get("team_name"), c.get("assignment_number"),
             c.get("chat_history") or "", c.get("created_at"), "Docent" if kind == "teacher" else None,
             _ref(evaluations, c.get("evaluation_ref"), "beoordeling", required=False),
             _ref(messages, c.get("accepted_message_ref"), "bericht", required=False), c.get("accepted_answer"),
             c.get("level_started_at")))

    for p in _list(progress, "progress"):
        conn.execute(
            "INSERT INTO ai_team_progress (game_id, team_id, assignment_number, aspects_json, summary, last_message_id, updated_at) "
            "VALUES (?, ?, ?, ?, ?, ?, ?)",
            (game_id, _ref(teams, p.get("team_ref"), "team"), p.get("assignment_number"), p.get("aspects_json"), p.get("summary"),
             _ref(messages, p.get("last_message_ref"), "bericht", required=False), p.get("updated_at")))

    for a in _list(progress, "announcements"):
        conn.execute("INSERT INTO announcements (game_id, team_id, message, created_by, created_at) VALUES (?, ?, ?, NULL, COALESCE(?, datetime('now')))",
                     (game_id, _ref(teams, a.get("team_ref"), "team", required=False), a.get("message") or "", a.get("created_at")))

    for d in _list(progress, "downloads"):
        conn.execute("INSERT INTO team_downloads (team_id, assignment_number, download_count) VALUES (?, ?, ?)",
                     (_ref(teams, d.get("team_ref"), "team"), d.get("assignment_number"), int(d.get("download_count") or 0)))
    return len(teams)


@register("game_import")
def handle(job):
    upload = job.params.get("upload")
    if not upload:
        raise UserError("Geen archief opgegeven")
    try:
        path = job.storage.abs(upload, under="incoming")
    except ValueError as e:
        raise UserError("Ongeldig pad van het archief") from e
    original = os.path.basename(str(job.params.get("original_name") or "archief.tar.gz"))
    fallback = "Import " + original.replace(".tar.gz", "").replace(".tgz", "")
    try:
        if not os.path.isfile(path):
            raise UserError("Het geüploade archief is niet (meer) aanwezig")
        game_id = import_archive(job.conn, job.cfg.HTDOCS_DIR, path, job, name=job.params.get("name"), created_by=job.created_by,
                                 fallback_name=fallback, max_bytes=job.cfg.MAX_ARCHIVE_MB * 1024 * 1024,
                                 max_files=job.cfg.MAX_ARCHIVE_FILES)
    finally:
        try:
            os.remove(path)
        except OSError:
            pass
    job.result_game_id = game_id
