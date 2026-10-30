"""Database-laag van de worker: verbinding, wachtrij (worker_jobs) en heartbeat (worker_status).

Tijden altijd via datetime('now') (UTC), net als in PHP. Verbindingen staan in autocommit; schrijven
met meerdere stappen gaat via transaction() (BEGIN IMMEDIATE), zodat een claim atomair is.
"""
import contextlib
import json
import sqlite3

# Draaien alleen als er niets anders draait; een restore in de wachtrij houdt de rest tegen
EXCLUSIVE_TYPES = ("restore", "backup")
# Deze taken voert de AI-agent uit (via api.php, AGENT_JOB_TYPES in conf/worker.php); de worker slaat ze over
AGENT_TYPES = ("ai_replay",)
ORPHAN_MINUTES = 10


def connect(db_file, timeout=30):
    conn = sqlite3.connect(db_file, timeout=timeout, isolation_level=None)
    conn.row_factory = sqlite3.Row
    return conn


@contextlib.contextmanager
def transaction(conn):
    conn.execute("BEGIN IMMEDIATE")
    try:
        yield conn
    except BaseException:
        conn.execute("ROLLBACK")
        raise
    conn.execute("COMMIT")


def create_job(conn, job_type, params=None, game_id=None, created_by=None, status="queued", worker_id=None):
    started = "datetime('now')" if status == "running" else "NULL"
    cur = conn.execute(
        f"INSERT INTO worker_jobs (type, game_id, params_json, status, progress, created_by, created_at, started_at, heartbeat_at, worker_id) "
        f"VALUES (?, ?, ?, ?, 0, ?, datetime('now'), {started}, {started}, ?)",
        (job_type, game_id, json.dumps(params or {}, ensure_ascii=False), status, created_by, worker_id))
    return cur.lastrowid


def get_job(conn, job_id):
    return conn.execute("SELECT * FROM worker_jobs WHERE id = ?", (job_id,)).fetchone()


def claim_next(conn, worker_id):
    """Pakt de oudste taak die nu mag draaien en zet hem op running. Geeft de rij terug, of None."""
    exclusive = ", ".join("?" for _ in EXCLUSIVE_TYPES)
    agent = ", ".join("?" for _ in AGENT_TYPES)
    with transaction(conn):
        running = [r["type"] for r in conn.execute(f"SELECT type FROM worker_jobs WHERE status = 'running' AND type NOT IN ({agent})",
                                                    AGENT_TYPES)]
        if any(t in EXCLUSIVE_TYPES for t in running):
            return None
        restore = conn.execute("SELECT id FROM worker_jobs WHERE status = 'queued' AND type = 'restore' ORDER BY id LIMIT 1").fetchone()
        if restore:
            # Een restore gaat voor alles en wacht tot de rest klaar is
            candidate = None if running else restore["id"]
        elif running:
            row = conn.execute(f"SELECT id FROM worker_jobs WHERE status = 'queued' AND type NOT IN ({exclusive}, {agent}) "
                               "ORDER BY id LIMIT 1", EXCLUSIVE_TYPES + AGENT_TYPES).fetchone()
            candidate = row["id"] if row else None
        else:
            row = conn.execute(f"SELECT id FROM worker_jobs WHERE status = 'queued' AND type NOT IN ({agent}) ORDER BY id LIMIT 1",
                               AGENT_TYPES).fetchone()
            candidate = row["id"] if row else None
        if candidate is None:
            return None
        cur = conn.execute("UPDATE worker_jobs SET status = 'running', worker_id = ?, started_at = datetime('now'), "
                           "heartbeat_at = datetime('now'), progress = 0 WHERE id = ? AND status = 'queued'", (worker_id, candidate))
        if cur.rowcount != 1:
            return None
    return get_job(conn, candidate)


def set_progress(conn, job_id, progress, message=None):
    progress = max(0, min(100, int(progress)))
    if message is None:
        conn.execute("UPDATE worker_jobs SET progress = ?, heartbeat_at = datetime('now') WHERE id = ?", (progress, job_id))
    else:
        conn.execute("UPDATE worker_jobs SET progress = ?, message = ?, heartbeat_at = datetime('now') WHERE id = ?",
                     (progress, message, job_id))


def heartbeat_job(conn, job_id):
    conn.execute("UPDATE worker_jobs SET heartbeat_at = datetime('now') WHERE id = ?", (job_id,))


def finish_job(conn, job_id, message=None, result_file=None, result_name=None, result_size=None, result_game_id=None,
               expires_days=None):
    expires = "datetime('now', ?)" if expires_days else "NULL"
    params = [message, result_file, result_name, result_size, result_game_id]
    if expires_days:
        params.append(f"+{int(expires_days)} days")
    params.append(job_id)
    conn.execute(
        f"UPDATE worker_jobs SET status = 'done', progress = 100, message = ?, result_file = ?, result_name = ?, result_size = ?, "
        f"result_game_id = ?, finished_at = datetime('now'), heartbeat_at = datetime('now'), expires_at = {expires} WHERE id = ?",
        params)


def fail_job(conn, job_id, message):
    conn.execute("UPDATE worker_jobs SET status = 'failed', message = ?, finished_at = datetime('now') WHERE id = ?",
                 (message, job_id))


def fail_orphans(conn, minutes=ORPHAN_MINUTES):
    """Taken die running zijn zonder heartbeat van de laatste minuten (de worker stopte) worden failed.
    Taken van de AI-agent niet: die ruimt api.php zelf op (claimAiReplay())."""
    agent = ", ".join("?" for _ in AGENT_TYPES)
    cur = conn.execute(
        "UPDATE worker_jobs SET status = 'failed', message = 'Onderbroken: de worker stopte tijdens deze taak', "
        "finished_at = datetime('now') WHERE status = 'running' AND (heartbeat_at IS NULL OR heartbeat_at < datetime('now', ?)) "
        f"AND type NOT IN ({agent})",
        (f"-{int(minutes)} minutes",) + AGENT_TYPES)
    return cur.rowcount


def worker_heartbeat(conn, worker_id, version, current_job_id=None):
    conn.execute(
        "INSERT INTO worker_status (worker_id, version, last_heartbeat, current_job_id) VALUES (?, ?, datetime('now'), ?) "
        "ON CONFLICT(worker_id) DO UPDATE SET version = excluded.version, last_heartbeat = excluded.last_heartbeat, "
        "current_job_id = excluded.current_job_id",
        (worker_id, version, current_job_id))


def unregister(conn, worker_id):
    conn.execute("DELETE FROM worker_status WHERE worker_id = ?", (worker_id,))


def audit(conn, event_type, description):
    """Auditregel van de worker (user_id NULL). Nooit wachtwoorden, tokens of geheimen in de omschrijving."""
    conn.execute("INSERT INTO audit_logs (user_id, event_type, description, created_at) VALUES (NULL, ?, ?, datetime('now'))",
                 (event_type, description))


def job_params(row):
    try:
        params = json.loads(row["params_json"] or "{}")
    except (TypeError, ValueError):
        params = {}
    return params if isinstance(params, dict) else {}
