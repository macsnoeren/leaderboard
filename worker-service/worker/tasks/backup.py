"""Taken backup en backup_upload: de volledige omgeving.

Een backup bevat de database (momentopname via de backup-API), htdocs/artifacts/ en htdocs/uploads/,
met een manifest (schema, tellingen, sha256 per bestand). Niet: conf/config.php, config.py en storage/.
Naast het archief staat <naam>.json met de metadata voor backups.php en de bewaartermijnen.
"""
import json
import os
import shutil
import sqlite3

from worker import backups as backup_store
from worker import config, retention
from worker.archive import ArchiveReader, ArchiveWriter
from worker.formats import game_v3
from worker.jobs import Reporter, UserError, register
from worker.snapshot import snapshot_tmp

VERSION = 1
BACKUP_ALLOWED = [r"manifest\.json", r"database/leaderboard\.db", r"htdocs/(artifacts|uploads)/[^/]+"]
DIRS = ("artifacts", "uploads")
COUNT_TABLES = ("games", "teams", "assignments", "team_messages", "completed_assignments", "teachers")


def schema_of(conn):
    """{tabel: [kolommen]} zonder interne sqlite_-tabellen."""
    tables = [r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")]
    return {t: [c[1] for c in conn.execute(f'PRAGMA table_info("{t}")')] for t in tables}


def counts_of(conn, schema):
    return {t: conn.execute(f'SELECT COUNT(*) FROM "{t}"').fetchone()[0] for t in COUNT_TABLES if t in schema}


def _missing_references(conn, htdocs_dir, schema):
    """Bestanden waar de database naar verwijst die niet op de server staan."""
    refs = []
    if "assignments" in schema and "artifact_file" in schema["assignments"]:
        refs += [r[0] for r in conn.execute("SELECT artifact_file FROM assignments WHERE artifact_file IS NOT NULL AND artifact_file != ''")]
    if "games" in schema and "logo_file" in schema["games"]:
        refs += [r[0] for r in conn.execute("SELECT logo_file FROM games WHERE logo_file LIKE 'uploads/%'")]
    return sorted({r for r in refs if not os.path.isfile(os.path.join(htdocs_dir, r))})


def create_backup(cfg, storage, trigger, created_by=None, reporter=None, job_id=None):
    """Maakt een backup in storage/backups/. Geeft (pad, metadata).

    job_id: de backup-taak zelf; die staat in de momentopname nog als 'running' en wordt na een restore
    van deze backup op 'done' gezet."""
    reporter = reporter or Reporter()
    storage.ensure()
    if cfg.BACKUP_MIN_FREE_MB > 0 and storage.free_mb(storage.backups) < cfg.BACKUP_MIN_FREE_MB:
        raise UserError(f"Te weinig vrije schijfruimte voor een backup (minder dan {cfg.BACKUP_MIN_FREE_MB} MB vrij)")
    name = backup_store.new_name(storage, trigger)
    path = os.path.join(storage.backups, name)
    reporter.progress(5, "Database kopiëren")
    with snapshot_tmp(cfg.DB_FILE, storage.tmp) as snap_path:
        snap = sqlite3.connect(snap_path)
        try:
            schema = schema_of(snap)
            counts = counts_of(snap, schema)
            for ref in _missing_references(snap, cfg.HTDOCS_DIR, schema):
                reporter.warn(f"Bestand {ref} staat in de database maar ontbreekt op de server")
        finally:
            snap.close()
        with ArchiveWriter(path) as writer:
            writer.add_file("database/leaderboard.db", snap_path)
            entries = []
            for sub in DIRS:
                folder = os.path.join(cfg.HTDOCS_DIR, sub)
                if not os.path.isdir(folder):
                    continue
                for entry in sorted(os.listdir(folder)):
                    full = os.path.join(folder, entry)
                    if os.path.islink(full) or not os.path.isfile(full):
                        reporter.warn(f"htdocs/{sub}/{entry} overgeslagen (geen gewoon bestand)")
                        continue
                    entries.append((f"htdocs/{sub}/{entry}", full))
            for i, (arcname, full) in enumerate(entries, start=1):
                reporter.check_stop()
                reporter.progress(10 + int(85 * i / max(1, len(entries))), f"Bestand {i} van {len(entries)}")
                try:
                    writer.add_file(arcname, full)
                except FileNotFoundError:
                    reporter.warn(f"{arcname} verdween tijdens de backup")
            counts["files"] = len(writer.files) - 1
            manifest = {
                "format": backup_store.FORMAT,
                "format_version": VERSION,
                "created_at": backup_store.utc_now_str(),
                "trigger": trigger,
                "job_id": job_id,
                "app_version": config.app_version(cfg),
                "schema": schema,
                "counts": counts,
                "files": dict(writer.files),
            }
            writer.add_bytes("manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2).encode("utf-8"), record=False)
    meta = {
        "file": name,
        "trigger": trigger,
        "created_at": manifest["created_at"],
        "size": os.path.getsize(path),
        "counts": counts,
        "pinned": False,
        "created_by": created_by,
        "app_version": manifest["app_version"],
        "warnings": list(reporter.warnings),
    }
    backup_store.write_meta(storage, name, meta)
    return path, meta


def validate_backup(cfg, archive_path, workdir):
    """Controleert een backup volledig en pakt hem uit in workdir, zonder iets aan de installatie te veranderen.

    Geeft (manifest, pad van de database, {'artifacts': map, 'uploads': map}).
    """
    with ArchiveReader(archive_path, BACKUP_ALLOWED, cfg.MAX_ARCHIVE_MB * 1024 * 1024, cfg.MAX_ARCHIVE_FILES) as reader:
        if not reader.has("manifest.json"):
            raise UserError("Dit is geen backup van OperationRoom (manifest.json ontbreekt)")
        try:
            manifest = json.loads(reader.read_bytes("manifest.json").decode("utf-8"))
        except (ValueError, UnicodeDecodeError) as e:
            raise UserError("De backup is beschadigd (manifest.json is geen geldige JSON)") from e
        if isinstance(manifest, list) or (isinstance(manifest, dict) and manifest.get("format") in game_v3.FORMATS):
            raise UserError("Dit is een spel-export, geen backup; importeer hem bij Spellen")
        if not isinstance(manifest, dict) or manifest.get("format") not in backup_store.FORMATS:
            raise UserError("Dit is geen backup van OperationRoom")
        try:
            version = int(manifest.get("format_version"))
        except (TypeError, ValueError) as e:
            raise UserError("De backup heeft geen geldige versie") from e
        if version > VERSION:
            raise UserError("Deze backup is gemaakt met een nieuwere versie van OperationRoom; werk eerst deze installatie bij")
        files = manifest.get("files")
        if not isinstance(files, dict) or "database/leaderboard.db" not in files:
            raise UserError("De backup is onvolledig: de database ontbreekt")
        unexpected = set(reader.names()) - set(files) - {"manifest.json"}
        if unexpected:
            raise UserError(f"De backup bevat onverwachte bestanden: {', '.join(sorted(unexpected)[:3])}")
        dirs = {sub: os.path.join(workdir, "htdocs", sub) for sub in DIRS}
        for folder in dirs.values():
            os.makedirs(folder, exist_ok=True)
        db_path = os.path.join(workdir, "leaderboard.db")
        for name, info in files.items():
            if not reader.has(name):
                raise UserError(f"De backup is onvolledig: {name} ontbreekt")
            sha = info.get("sha256") if isinstance(info, dict) else None
            if name == "database/leaderboard.db":
                target = db_path
            else:
                _, sub, base = name.split("/", 2)
                target = os.path.join(dirs[sub], base)
            reader.extract_to(name, target, sha256=sha)
    try:
        conn = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
        try:
            result = conn.execute("PRAGMA integrity_check").fetchone()[0]
        finally:
            conn.close()
    except sqlite3.DatabaseError as e:
        raise UserError("De database in de backup is beschadigd") from e
    if result != "ok":
        raise UserError("De database in de backup is beschadigd (integrity_check)")
    return manifest, db_path, dirs


@register("backup")
def handle(job):
    trigger = job.params.get("trigger", "manual")
    if trigger not in ("manual", "scheduled"):
        trigger = "manual"
    path, meta = create_backup(job.cfg, job.storage, trigger, created_by=job.created_by, reporter=job, job_id=job.id)
    job.set_result(path, os.path.basename(path), expires=False)
    counts = meta["counts"]
    job.summary = f"{counts.get('games', 0)} spellen, {counts.get('teams', 0)} teams, {counts.get('files', 0)} bestanden"
    removed = retention.apply(job.cfg, job.storage, job.conn)
    if removed:
        job.summary += f"; {len(removed)} oude backup(s) opgeruimd"


@register("backup_upload")
def handle_upload(job):
    """Een geüploade backup controleren en in de lijst zetten (trigger 'uploaded')."""
    upload = job.params.get("upload")
    try:
        path = job.storage.abs(str(upload or ""), under="incoming")
    except ValueError as e:
        raise UserError("Ongeldig pad van de upload") from e
    try:
        if not os.path.isfile(path):
            raise UserError("Het geüploade bestand is niet (meer) aanwezig")
        manifest, _, _ = validate_backup(job.cfg, path, job.tmp_dir())
        name = backup_store.new_name(job.storage, "uploaded")
        target = os.path.join(job.storage.backups, name)
        shutil.move(path, target)
        counts = manifest.get("counts") if isinstance(manifest.get("counts"), dict) else {}
        backup_store.write_meta(job.storage, name, {
            "file": name, "trigger": "uploaded", "created_at": backup_store.utc_now_str(), "size": os.path.getsize(target),
            "counts": counts, "pinned": False, "created_by": job.created_by, "app_version": manifest.get("app_version"),
            "original_created_at": manifest.get("created_at"), "warnings": [],
        })
        job.set_result(target, name, expires=False)
        job.summary = f"Backup van {manifest.get('created_at', '?')} (UTC) staat in de lijst"
    finally:
        if os.path.exists(path):
            os.remove(path)
