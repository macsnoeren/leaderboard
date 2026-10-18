"""Backups in storage/backups/: <naam>.tar.gz met <naam>.tar.gz.json (metadata) ernaast.

De .json bevat trigger, created_at (UTC, 'YYYY-MM-DD HH:MM:SS' zoals SQLite), size, counts, pinned,
created_by, app_version en warnings. PHP (backups.php) leest hem en zet alleen 'pinned'.
"""
import json
import os
import re
from datetime import datetime, timezone

# Backups van vóór de naamswijziging heetten zebrawave-backup-…: die blijven geldig
NAME_RE = re.compile(r"^(operationroom|zebrawave)-backup-[A-Za-z0-9._-]+\.tar\.gz$")
FORMAT = "operationroom-backup"
FORMATS = (FORMAT, "zebrawave-backup")
TRIGGERS = ("scheduled", "manual", "pre_restore", "uploaded")


def utc_now_str():
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def parse_utc(text):
    """'YYYY-MM-DD HH:MM:SS' (UTC) → aware datetime, of None."""
    try:
        return datetime.strptime(str(text), "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except (TypeError, ValueError):
        return None


def meta_path(storage, name):
    return os.path.join(storage.backups, name + ".json")


def read_meta(storage, name):
    try:
        with open(meta_path(storage, name), encoding="utf-8") as f:
            meta = json.load(f)
        return meta if isinstance(meta, dict) else None
    except (OSError, ValueError):
        return None


def write_meta(storage, name, meta):
    path = meta_path(storage, name)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(meta, f, ensure_ascii=False, indent=2)
    os.replace(tmp, path)


def list_backups(storage):
    """Alle backups met geldige naam, archief en metadata; nieuwste eerst."""
    result = []
    if not os.path.isdir(storage.backups):
        return result
    for entry in os.listdir(storage.backups):
        if not entry.endswith(".tar.gz.json"):
            continue
        name = entry[:-5]
        if not NAME_RE.match(name) or not os.path.isfile(os.path.join(storage.backups, name)):
            continue
        meta = read_meta(storage, name)
        if meta is None:
            continue
        result.append({
            "file": name,
            "trigger": meta.get("trigger", "manual"),
            "created_at": parse_utc(meta.get("created_at")) or datetime.fromtimestamp(0, timezone.utc),
            "pinned": bool(meta.get("pinned")),
            "meta": meta,
        })
    result.sort(key=lambda b: (b["created_at"], b["file"]), reverse=True)
    return result


def new_name(storage, trigger, now_local=None):
    """Unieke bestandsnaam op basis van de lokale tijd, bijv. operationroom-backup-20261018-030000.tar.gz."""
    now_local = now_local or datetime.now()
    suffix = {"pre_restore": "-veiligheid", "uploaded": "-upload"}.get(trigger, "")
    base = f"operationroom-backup-{now_local:%Y%m%d-%H%M%S}{suffix}"
    name = base + ".tar.gz"
    n = 2
    while os.path.exists(os.path.join(storage.backups, name)):
        name = f"{base}-{n}.tar.gz"
        n += 1
    return name


def delete(storage, name):
    for path in (os.path.join(storage.backups, name), meta_path(storage, name)):
        try:
            os.remove(path)
        except OSError:
            pass
