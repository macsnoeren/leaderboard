"""Planner voor de dagelijkse automatische backup (DESIGN §7.3).

Elke dag na BACKUP_TIME (lokale tijd, TZ) zet de planner een backup-taak met trigger 'scheduled' in de
wachtrij, ook na een herstart (inhalen). "Al gedaan vandaag" kijkt naar de backups zelf én naar de
taken, zodat een mislukte backup niet elke ronde opnieuw start.
"""
import logging
import re
from datetime import datetime, timezone

from worker import backups as backup_store
from worker import db

logger = logging.getLogger(__name__)


def parse_time(text):
    m = re.fullmatch(r"(\d{1,2}):(\d{2})", (text or "").strip())
    if not m or int(m.group(1)) > 23 or int(m.group(2)) > 59:
        return None
    return int(m.group(1)), int(m.group(2))


def due(now_local, backup_time, last_scheduled_local):
    """Is er nu een geplande backup nodig? now_local en last_scheduled_local in dezelfde tijdzone."""
    hm = parse_time(backup_time)
    if hm is None:
        return False
    target = now_local.replace(hour=hm[0], minute=hm[1], second=0, microsecond=0)
    if now_local < target:
        return False
    return last_scheduled_local is None or last_scheduled_local < target


def _to_local(utc_dt):
    return utc_dt.astimezone()


def last_scheduled(conn, storage):
    """Tijdstip (lokaal, aware) van de laatste geplande backup of geplande backup-taak, of None."""
    times = [b["created_at"] for b in backup_store.list_backups(storage) if b["trigger"] == "scheduled"]
    row = conn.execute("SELECT MAX(created_at) FROM worker_jobs WHERE type = 'backup' "
                       "AND json_extract(params_json, '$.trigger') = 'scheduled'").fetchone()
    if row and row[0]:
        parsed = backup_store.parse_utc(row[0])
        if parsed:
            times.append(parsed)
    return _to_local(max(times)) if times else None


def maybe_enqueue(conn, cfg, storage, now_local=None):
    """Zet een geplande backup in de wachtrij als dat nu moet. Geeft het job-id, of None."""
    if not cfg.BACKUP_TIME:
        return None
    if parse_time(cfg.BACKUP_TIME) is None:
        logger.warning("BACKUP_TIME %r is ongeldig (verwacht HH:MM); geen automatische backups", cfg.BACKUP_TIME)
        return None
    now_local = now_local or datetime.now(timezone.utc).astimezone()
    if not due(now_local, cfg.BACKUP_TIME, last_scheduled(conn, storage)):
        return None
    busy = conn.execute("SELECT COUNT(*) FROM worker_jobs WHERE type = 'backup' AND status IN ('queued', 'running')").fetchone()[0]
    if busy:
        return None
    job_id = db.create_job(conn, "backup", {"trigger": "scheduled"})
    logger.info("Geplande backup in de wachtrij gezet (taak %s)", job_id)
    return job_id
