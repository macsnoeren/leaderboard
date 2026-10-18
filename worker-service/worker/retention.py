"""Bewaartermijnen voor backups.

- automatisch (scheduled): de laatste BACKUP_KEEP;
- handmatig en geüpload: BACKUP_MANUAL_DAYS dagen;
- veiligheid (pre_restore): BACKUP_PRE_RESTORE_DAYS dagen;
- 0 = nooit automatisch verwijderen; de nieuwste backup en vastgezette backups nooit.
"""
import logging
from datetime import timedelta

from worker import backups as backup_store
from worker import db

logger = logging.getLogger(__name__)


def to_delete(backups, now, cfg):
    """backups: dicts met file, trigger, created_at (aware datetime) en pinned. Geeft de namen die weg mogen."""
    if not backups:
        return []
    ordered = sorted(backups, key=lambda b: (b["created_at"], b["file"]), reverse=True)
    newest = ordered[0]["file"]
    remove = []
    scheduled_seen = 0
    for b in ordered:
        if b["file"] == newest or b.get("pinned"):
            if b["trigger"] == "scheduled":
                scheduled_seen += 1
            continue
        if b["trigger"] == "scheduled":
            scheduled_seen += 1
            if cfg.BACKUP_KEEP > 0 and scheduled_seen > cfg.BACKUP_KEEP:
                remove.append(b["file"])
            continue
        days = cfg.BACKUP_PRE_RESTORE_DAYS if b["trigger"] == "pre_restore" else cfg.BACKUP_MANUAL_DAYS
        if days > 0 and b["created_at"] < now - timedelta(days=days):
            remove.append(b["file"])
    return remove


def apply(cfg, storage, conn=None, now=None):
    """Verwijdert wat volgens to_delete() weg mag (archief + .json), met een auditregel. Geeft de namen terug."""
    from datetime import datetime, timezone  # noqa: PLC0415
    now = now or datetime.now(timezone.utc)
    names = to_delete(backup_store.list_backups(storage), now, cfg)
    for name in names:
        backup_store.delete(storage, name)
        logger.info("Backup %s verwijderd (bewaartermijn)", name)
        if conn is not None:
            db.audit(conn, "BACKUP_DELETE", f"Backup {name} verwijderd (bewaartermijn)")
    return names
