"""Testschema: dezelfde tabellen als htdocs/setup.php + bin/migrate.php (zoals freshDb() in tests/game_test.php).

Bij een schemawijziging in setup.php/migrate.php ook dit bestand bijwerken.
"""
import secrets
import sqlite3

SCHEMA = [
    "CREATE TABLE teachers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT 'user', force_password_change INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, email TEXT, display_name TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME, session_version INTEGER DEFAULT 0, totp_secret TEXT, totp_enabled INTEGER DEFAULT 0, totp_last_step INTEGER)",
    "CREATE UNIQUE INDEX idx_teachers_email ON teachers(email)",
    "CREATE TABLE user_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, new_email TEXT, expires_at DATETIME NOT NULL, used_at DATETIME, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL, used_at DATETIME)",
    "CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, identifier TEXT, ip TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE games (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', join_code TEXT UNIQUE, registration_open INTEGER DEFAULT 0, logo_file TEXT, status_message TEXT, started_at DATETIME, ends_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by INTEGER, ai_mode TEXT DEFAULT 'suggest', ai_max_attempts INTEGER DEFAULT 5, ai_max_questions INTEGER DEFAULT 5)",
    "CREATE TABLE game_teachers (game_id INTEGER NOT NULL, teacher_id INTEGER NOT NULL, PRIMARY KEY (game_id, teacher_id))",
    "CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_name TEXT, email TEXT, current_level INTEGER DEFAULT 0, level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, access_token TEXT UNIQUE, ai_mode TEXT DEFAULT 'suggest', ai_processing_by TEXT, ai_processing_at DATETIME)",
    "CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, assignment_number INTEGER, title TEXT, description TEXT, instruction TEXT, criteria TEXT, time_limit INTEGER, artifact_file TEXT, artifact_name TEXT, ai_rubric TEXT, ai_rubric_status TEXT DEFAULT 'none', ai_design_status TEXT DEFAULT 'idle', ai_design_round INTEGER DEFAULT 0, ai_auto_allowed INTEGER DEFAULT 1, ai_processing_by TEXT, ai_processing_at DATETIME)",
    "CREATE TABLE download_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, level INTEGER, token TEXT, expires_at DATETIME)",
    "CREATE TABLE team_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, download_count INTEGER DEFAULT 0, UNIQUE(team_id, assignment_number))",
    "CREATE TABLE team_presence (team_id INTEGER, username TEXT, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (team_id, username))",
    "CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE team_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, sender TEXT, message TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, ai_evaluation_id INTEGER)",
    "CREATE TABLE api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, key_name TEXT, api_key TEXT UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE ai_service_status (id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id TEXT UNIQUE, last_heartbeat DATETIME DEFAULT CURRENT_TIMESTAMP, model_ok INTEGER DEFAULT 1)",
    "CREATE TABLE completed_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_id INTEGER, team_name TEXT, assignment_number INTEGER, chat_history TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, leveled_by INTEGER, leveled_by_name TEXT, ai_evaluation_id INTEGER, accepted_message_id INTEGER, accepted_answer TEXT, level_started_at DATETIME)",
    "CREATE TABLE announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER, message TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE ai_team_progress (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, assignment_number INTEGER NOT NULL, aspects_json TEXT, summary TEXT, last_message_id INTEGER, updated_at DATETIME, UNIQUE (team_id, assignment_number))",
    "CREATE TABLE assignment_ai_dialog (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL, role TEXT NOT NULL, message TEXT NOT NULL, teacher_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE ai_evaluations (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, team_name TEXT, assignment_number INTEGER NOT NULL, message_id INTEGER NOT NULL, intent TEXT, intent_json TEXT, helper_json TEXT, assessor_json TEXT, validator_json TEXT, verdict TEXT, decision TEXT, reason TEXT, reply_text TEXT, escalation_reason TEXT, resolved_by INTEGER, resolved_at DATETIME, teacher_verdict TEXT, agent_id TEXT, models TEXT, duration_ms INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
    "CREATE INDEX idx_ai_evaluations_team ON ai_evaluations(team_id, assignment_number)",
    "CREATE INDEX idx_assignment_ai_dialog ON assignment_ai_dialog(assignment_id)",
    "CREATE UNIQUE INDEX idx_teams_game_name ON teams(game_id, team_name)",
    "CREATE UNIQUE INDEX idx_assignments_game_number ON assignments(game_id, assignment_number)",
    "CREATE TABLE worker_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, game_id INTEGER, params_json TEXT, status TEXT NOT NULL DEFAULT 'queued', progress INTEGER DEFAULT 0, message TEXT, result_file TEXT, result_name TEXT, result_size INTEGER, result_game_id INTEGER, created_by INTEGER, created_at DATETIME, started_at DATETIME, heartbeat_at DATETIME, finished_at DATETIME, expires_at DATETIME, worker_id TEXT)",
    "CREATE INDEX idx_worker_jobs_status ON worker_jobs(status, id)",
    "CREATE INDEX idx_worker_jobs_game ON worker_jobs(game_id, id)",
    "CREATE TABLE worker_status (worker_id TEXT PRIMARY KEY, version TEXT, last_heartbeat DATETIME, current_job_id INTEGER)",
]


def fresh_db(path):
    """Maakt een lege database met het volledige schema en geeft een open verbinding terug."""
    conn = sqlite3.connect(path, isolation_level=None)
    conn.row_factory = sqlite3.Row
    for statement in SCHEMA:
        conn.execute(statement)
    return conn


def add_teacher(conn, username="docent", role="user", display_name=None):
    cur = conn.execute("INSERT INTO teachers (username, role, display_name, password_hash) VALUES (?, ?, ?, 'x')",
                       (username, role, display_name))
    return cur.lastrowid


def add_game(conn, name="Spel", status="draft", logo_file=None, started_at=None):
    cur = conn.execute(
        "INSERT INTO games (name, status, join_code, logo_file, started_at, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
        (name, status, secrets.token_hex(3).upper(), logo_file, started_at))
    return cur.lastrowid


def add_assignment(conn, game_id, number, title=None, artifact_file=None, **fields):
    values = {"title": title or f"Opdracht {number}", "description": f"Beschrijving {number}",
              "instruction": f"Instructie {number}", "criteria": f"Antwoord {number}", "time_limit": 30,
              "artifact_file": artifact_file}
    values.update(fields)
    cols = ", ".join(["game_id", "assignment_number"] + list(values))
    marks = ", ".join(["?"] * (len(values) + 2))
    cur = conn.execute(f"INSERT INTO assignments ({cols}) VALUES ({marks})", [game_id, number, *values.values()])
    return cur.lastrowid


def add_team(conn, game_id, name="Team", level=1, email=None, updated="-1 hour"):
    cur = conn.execute(
        "INSERT INTO teams (game_id, team_name, email, current_level, level_updated_at, access_token) VALUES (?, ?, ?, ?, datetime('now', ?), ?)",
        (game_id, name, email, level, updated, secrets.token_hex(32)))
    return cur.lastrowid


def add_message(conn, team_id, level, sender, message, created="-30 minutes", evaluation_id=None):
    cur = conn.execute(
        "INSERT INTO team_messages (team_id, assignment_number, sender, message, created_at, ai_evaluation_id) VALUES (?, ?, ?, ?, datetime('now', ?), ?)",
        (team_id, level, sender, message, created, evaluation_id))
    return cur.lastrowid
