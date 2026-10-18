"""Gegevens voor het rapport (DESIGN §9): goedgekeurde antwoorden per opdracht en het verloop per team.

Archiefregels van na de migratie (level_started_at gevuld) hebben "wie" en "welk antwoord" in eigen
kolommen. Voor oudere regels wordt het afgeleid: antwoord = laatste "Team: …" uit chat_history, "door" =
de TEAM_LEVEL_UP-regel in audit_logs die binnen een paar seconden ligt. Die rijen zijn derived.
"""
import json
import re
from datetime import datetime, timezone

PREFIXES = (("Team: ", "team"), ("AI: ", "ai"), ("Docent: ", "teacher"))
AUDIT_WINDOW_SECONDS = 5


def parse_utc(text):
    if not text:
        return None
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M"):
        try:
            return datetime.strptime(str(text)[:19], fmt).replace(tzinfo=timezone.utc)
        except ValueError:
            continue
    return None


def parse_chat(chat):
    """chat_history ("Team: …\\nDocent: …") → [(sender, tekst)]; regels zonder voorvoegsel horen bij het vorige bericht."""
    messages = []
    for line in (chat or "").split("\n"):
        for prefix, sender in PREFIXES:
            if line.startswith(prefix):
                messages.append([sender, line[len(prefix):]])
                break
        else:
            if messages:
                messages[-1][1] += "\n" + line
    return [tuple(m) for m in messages]


def last_team_message(chat):
    team = [text for sender, text in parse_chat(chat) if sender == "team"]
    return team[-1] if team else None


def _teacher_label(conn, user_id):
    row = conn.execute("SELECT display_name, username, email FROM teachers WHERE id = ?", (user_id,)).fetchone()
    if row is None:
        return f"docent #{user_id}"
    return row[0] or row[1] or row[2] or f"docent #{user_id}"


def approver(conn, row):
    """(naam, derived) van wie het level goedkeurde."""
    if row["level_started_at"] is not None:
        if row["leveled_by_name"]:
            return row["leveled_by_name"], False
        if row["leveled_by"] is not None:
            return _teacher_label(conn, row["leveled_by"]), False
        verdict = None
        if row["ai_evaluation_id"] is not None:
            ev = conn.execute("SELECT verdict FROM ai_evaluations WHERE id = ?", (row["ai_evaluation_id"],)).fetchone()
            verdict = ev[0] if ev else None
        return (f"AI ({verdict})" if verdict else "AI"), False
    if row["team_id"] is None:
        return "onbekend", True
    audit = conn.execute(
        "SELECT user_id FROM audit_logs WHERE event_type = 'TEAM_LEVEL_UP' AND description LIKE ? "
        "AND ABS(julianday(created_at) - julianday(?)) * 86400 <= ? "
        "ORDER BY ABS(julianday(created_at) - julianday(?)) LIMIT 1",
        (f"%(ID {int(row['team_id'])}) naar level%", row["created_at"], AUDIT_WINDOW_SECONDS, row["created_at"])).fetchone()
    if audit is None:
        return "onbekend", True
    return ("AI" if audit[0] is None else _teacher_label(conn, audit[0])), True


def attempts(conn, team_id, level, chat):
    """(pogingen, vragen): uit de AI-beoordelingen als die er zijn, anders het aantal teamberichten (vragen onbekend)."""
    if team_id is not None:
        rows = dict(conn.execute("SELECT intent, COUNT(*) FROM ai_evaluations WHERE team_id = ? AND assignment_number = ? "
                                 "GROUP BY intent", (team_id, level)).fetchall())
        if rows:
            return rows.get("antwoord", 0), rows.get("vraag", 0)
        n = conn.execute("SELECT COUNT(*) FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender = 'team'",
                         (team_id, level)).fetchone()[0]
        if n:
            return n, None
    return sum(1 for sender, _ in parse_chat(chat) if sender == "team"), None


def _latest_rows(conn, game_id):
    """Laatste archiefregel per (team, level); een team wordt herkend aan team_id, of aan de naam als dat er niet is."""
    latest = {}
    for row in conn.execute("SELECT * FROM completed_assignments WHERE game_id = ? ORDER BY id", (game_id,)):
        key = (row["team_id"] if row["team_id"] is not None else f"naam:{row['team_name']}", row["assignment_number"])
        latest[key] = row
    return latest


def _answer(row):
    if row["level_started_at"] is not None:
        return row["accepted_answer"]
    return last_team_message(row["chat_history"])


def _rubric(text):
    try:
        value = json.loads(text) if text else None
    except ValueError:
        return None
    return value if isinstance(value, dict) else None


def accepted_answers(conn, game_id):
    """Per opdracht (op volgorde) de goedgekeurde antwoorden met team, tijd, antwoord, door wie en pogingen."""
    team_ids = {r[0] for r in conn.execute("SELECT id FROM teams WHERE game_id = ?", (game_id,))}
    by_level = {}
    for (_, level), row in _latest_rows(conn, game_id).items():
        by, derived_by = approver(conn, row)
        tries, _ = attempts(conn, row["team_id"] if row["team_id"] in team_ids else None, level, row["chat_history"])
        by_level.setdefault(level, []).append({
            "team_name": row["team_name"] or "Verwijderd team",
            "deleted": row["team_id"] not in team_ids,
            "approved_at": parse_utc(row["created_at"]),
            "answer": _answer(row),
            "by": by,
            "derived": row["level_started_at"] is None or derived_by,
            "attempts": tries,
        })
    result = []
    for a in conn.execute("SELECT * FROM assignments WHERE game_id = ? ORDER BY assignment_number", (game_id,)):
        rows = sorted(by_level.get(a["assignment_number"], []),
                      key=lambda r: (r["approved_at"] or datetime.max.replace(tzinfo=timezone.utc), r["team_name"]))
        result.append({"assignment": dict(a), "rubric": _rubric(a["ai_rubric"]), "rows": rows})
    return result


def team_traces(conn, game_id):
    """Per team (in de volgorde van het leaderboard, daarna verwijderde teams) het verloop per opdracht."""
    game = conn.execute("SELECT * FROM games WHERE id = ?", (game_id,)).fetchone()
    total = conn.execute("SELECT COUNT(*) FROM assignments WHERE game_id = ?", (game_id,)).fetchone()[0]
    titles = dict(conn.execute("SELECT assignment_number, title FROM assignments WHERE game_id = ?", (game_id,)).fetchall())
    teams = conn.execute("SELECT * FROM teams WHERE game_id = ? ORDER BY current_level DESC, level_updated_at ASC, team_name ASC",
                         (game_id,)).fetchall()
    latest = _latest_rows(conn, game_id)
    team_ids = {t["id"] for t in teams}

    def levels_for(key, team_id):
        rows = sorted((row for (k, _), row in latest.items() if k == key), key=lambda r: r["assignment_number"])
        out, previous = [], parse_utc(game["started_at"]) if game else None
        for row in rows:
            approved = parse_utc(row["created_at"])
            started = parse_utc(row["level_started_at"])
            derived = started is None
            started = started or previous
            tries, questions = attempts(conn, team_id, row["assignment_number"], row["chat_history"])
            by, derived_by = approver(conn, row)
            out.append({
                "assignment_number": row["assignment_number"],
                "title": titles.get(row["assignment_number"], ""),
                "started_at": started,
                "approved_at": approved,
                "duration_s": int((approved - started).total_seconds()) if approved and started else None,
                "attempts": tries,
                "questions": questions,
                "by": by,
                "derived": derived or derived_by,
            })
            previous = approved
        return out

    def level_downs(team_id):
        downs = []
        for created, desc in conn.execute("SELECT created_at, description FROM audit_logs WHERE event_type = 'TEAM_LEVEL_DOWN' "
                                          "AND description LIKE ? ORDER BY id", (f"%(ID {int(team_id)}) terug naar level%",)):
            m = re.search(r"terug naar level (\d+)", desc or "")
            downs.append({"at": parse_utc(created), "to_level": int(m.group(1)) if m else None})
        return downs

    result = []
    for rank, t in enumerate(teams, start=1):
        result.append({"team_name": t["team_name"], "deleted": False, "rank": rank, "level": t["current_level"],
                       "finished": t["current_level"] > total, "total": total,
                       "levels": levels_for(t["id"], t["id"]), "level_downs": level_downs(t["id"])})
    deleted = {}
    for (key, _), row in latest.items():
        if row["team_id"] not in team_ids:
            deleted.setdefault(key, row["team_name"] or "Verwijderd team")
    for key, name in sorted(deleted.items(), key=lambda kv: kv[1]):
        levels = levels_for(key, None)
        done = max((lv["assignment_number"] for lv in levels), default=0)
        result.append({"team_name": name, "deleted": True, "rank": None, "level": done + 1, "finished": done >= total,
                       "total": total, "levels": levels,
                       "level_downs": level_downs(key) if isinstance(key, int) else []})
    return result


def game_overview(conn, game_id):
    game = conn.execute("SELECT * FROM games WHERE id = ?", (game_id,)).fetchone()
    if game is None:
        return None
    teams = conn.execute("SELECT COUNT(*) FROM teams WHERE game_id = ?", (game_id,)).fetchone()[0]
    first_last = conn.execute("SELECT MIN(created_at), MAX(created_at) FROM completed_assignments WHERE game_id = ?", (game_id,)).fetchone()
    return {"game": dict(game), "teams": teams, "started_at": parse_utc(game["started_at"]) or parse_utc(first_last[0]),
            "last_approval": parse_utc(first_last[1])}
