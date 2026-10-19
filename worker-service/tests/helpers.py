"""Gedeelde testhulp: een tijdelijke installatie (database, htdocs, storage) met een eigen configuratie."""
import os
import shutil
import tempfile

from tests import schema
from worker import config
from worker.storage import Storage

FIXTURES = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures")


class Env:
    """Tijdelijke installatie. Gebruik: env = Env(); ...; env.cleanup() (of addCleanup)."""

    def __init__(self, **overrides):
        self.root = tempfile.mkdtemp(prefix="worker-test-")
        for d in ("database", "htdocs/artifacts", "htdocs/uploads"):
            os.makedirs(os.path.join(self.root, d))
        self.db_file = os.path.join(self.root, "database", "leaderboard.db")
        settings = dict(DB_FILE=self.db_file, HTDOCS_DIR=os.path.join(self.root, "htdocs"),
                        STORAGE_DIR=os.path.join(self.root, "storage"), APP_DIR=self.root,
                        APP_VERSION="test", BACKUP_MIN_FREE_MB=0, RESTORE_GRACE_SECONDS=0)
        settings.update(overrides)
        self.cfg = config.load(module=None, base_dir=self.root, **settings)
        self.storage = Storage(self.cfg.STORAGE_DIR)
        self.storage.ensure()
        self.conn = schema.fresh_db(self.db_file)

    @property
    def htdocs(self):
        return self.cfg.HTDOCS_DIR

    def write_file(self, rel, data=b"inhoud"):
        """Schrijft een bestand onder htdocs/ en geeft het relatieve pad terug."""
        path = os.path.join(self.htdocs, rel)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "wb") as f:
            f.write(data if isinstance(data, bytes) else data.encode("utf-8"))
        return rel

    def cleanup(self):
        try:
            self.conn.close()
        except Exception:  # noqa: BLE001
            pass
        shutil.rmtree(self.root, ignore_errors=True)


EMAIL = "speurneus@example.com"


def seed_game(env, name="Operatie Zebra", status="running"):
    """Een gespeeld spel met alles erop en eraan (voor export, import, round-trip en rapport). Geeft ids terug."""
    c = env.conn
    teacher = schema.add_teacher(c, "jjansen", display_name="J. Jansen")
    logo = env.write_file("uploads/game_1_logo_1.png", b"\x89PNG logo")
    game = schema.add_game(c, name, status=status, logo_file=logo, started_at="2026-10-18 08:00:00")
    c.execute("UPDATE games SET ai_mode = 'reply', ai_max_attempts = 7 WHERE id = ?", (game,))
    c.execute("INSERT INTO game_teachers (game_id, teacher_id) VALUES (?, ?)", (game, teacher))
    art = env.write_file("artifacts/game_1_assignment_1_1.pdf", b"%PDF bijlage 1")
    a1 = schema.add_assignment(c, game, 1, "De sleutel", art, criteria="De conciërge", artifact_name="Bewijsstuk sleutel.pdf",
                               ai_rubric='{"kern": "De conciërge", "aspecten": []}', ai_rubric_status="approved")
    schema.add_assignment(c, game, 2, "Het motief", None, criteria="Geldnood", ai_auto_allowed=0)
    c.execute("INSERT INTO assignment_ai_dialog (assignment_id, role, message, teacher_id) VALUES (?, 'teacher', 'geheim overleg', ?)",
              (a1, teacher))

    t1 = schema.add_team(c, game, "De Speurneuzen", level=2, email=EMAIL)
    t2 = schema.add_team(c, game, "Team Zebra", level=1)
    m1 = schema.add_message(c, t1, 1, "team", "De beheerder?", created="-50 minutes")
    m2 = schema.add_message(c, t1, 1, "teacher", "Bijna", created="-45 minutes")
    m3 = schema.add_message(c, t1, 1, "team", "De conciërge", created="-40 minutes")
    cur = c.execute("INSERT INTO ai_evaluations (game_id, team_id, team_name, assignment_number, message_id, intent, verdict, decision, "
                    "reason, agent_id, resolved_by, created_at) VALUES (?, ?, 'De Speurneuzen', 1, ?, 'antwoord', 'goed', 'suggest', "
                    "'klopt', 'agent-geheim', ?, datetime('now', '-39 minutes'))", (game, t1, m3, teacher))
    ev = cur.lastrowid
    m4 = schema.add_message(c, t1, 1, "suggestion", "AI: goed", created="-39 minutes", evaluation_id=ev)
    m5 = schema.add_message(c, t2, 1, "team", "Weten we niet", created="-10 minutes")
    c.execute("INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history, created_at, leveled_by, "
              "leveled_by_name, accepted_message_id, accepted_answer, level_started_at) VALUES (?, ?, 'De Speurneuzen', 1, ?, "
              "datetime('now', '-38 minutes'), ?, 'J. Jansen', ?, 'De conciërge', '2026-10-18 08:00:00')",
              (game, t1, "Team: De beheerder?\nDocent: Bijna\nTeam: De conciërge", teacher, m3))
    # Archiefregel van een verwijderd team (team_id bestaat niet meer)
    c.execute("INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history, created_at) "
              "VALUES (?, 999, 'Vertrokken Team', 1, 'Team: Het was de tuinman', datetime('now', '-30 minutes'))", (game,))
    c.execute("INSERT INTO ai_team_progress (game_id, team_id, assignment_number, aspects_json, summary, last_message_id, updated_at) "
              "VALUES (?, ?, 1, '[]', 'op weg', ?, datetime('now'))", (game, t1, m3))
    c.execute("INSERT INTO announcements (game_id, team_id, message, created_by) VALUES (?, NULL, 'Welkom', ?)", (game, teacher))
    c.execute("INSERT INTO announcements (game_id, team_id, message, created_by) VALUES (?, ?, 'Alleen voor jullie', ?)", (game, t2, teacher))
    c.execute("INSERT INTO team_downloads (team_id, assignment_number, download_count) VALUES (?, 1, 3)", (t1,))
    c.execute("INSERT INTO download_tokens (team_id, level, token, expires_at) VALUES (?, 1, 'downloadtokengeheim', datetime('now'))", (t1,))
    tokens = [r[0] for r in c.execute("SELECT access_token FROM teams")]
    return {"game": game, "teacher": teacher, "teams": (t1, t2), "messages": (m1, m2, m3, m4, m5), "evaluation": ev,
            "tokens": tokens, "assignment": a1}


def make_env(testcase, **overrides):
    env = Env(**overrides)
    testcase.addCleanup(env.cleanup)
    return env
