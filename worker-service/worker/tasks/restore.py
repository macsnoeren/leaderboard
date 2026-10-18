"""Taak restore: een backup terugzetten. Vervangt database, bijlagen en logo's.

1. Controleren zonder iets te veranderen (checksums, integrity_check, schema niet nieuwer dan nu).
2. Onderhoudsmodus aan (storage/maintenance.json) en even wachten.
3. Veiligheidsbackup van de huidige staat (trigger pre_restore).
4. Vervangen: database atomair (os.replace), artifacts/ en uploads/ leegmaken en vullen (niet hernoemen:
   in Docker zijn het mount points).
5. bin/migrate.php (een oudere backup krijgt de nieuwe kolommen).
6. Nazorg in de teruggezette database: open taken mislukt, de restore-taak opnieuw invoegen, auditregel.
7. Onderhoudsmodus uit.
Een fout in 4–6 zet de veiligheidsbackup terug; lukt ook dat niet, dan blijft de onderhoudsmodus aan.
"""
import json
import logging
import os
import shutil
import sqlite3
import subprocess
import time
import uuid

from worker import backups as backup_store
from worker import db
from worker.jobs import Reporter, UserError, register
from worker.tasks.backup import DIRS, create_backup, schema_of, validate_backup

logger = logging.getLogger(__name__)


def set_maintenance(storage, **info):
    info.setdefault("since", backup_store.utc_now_str())
    path = storage.maintenance_file
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(info, f, ensure_ascii=False)
    os.replace(tmp, path)


def clear_maintenance(storage):
    try:
        os.remove(storage.maintenance_file)
    except FileNotFoundError:
        pass


def newer_schema(backup_schema, current_schema):
    """Tabellen of kolommen in de backup die de huidige app niet kent (de backup is dan van een nieuwere versie)."""
    unknown = []
    for table, columns in sorted(backup_schema.items()):
        if table not in current_schema:
            unknown.append(table)
            continue
        unknown += [f"{table}.{c}" for c in columns if c not in current_schema[table]]
    return unknown


def run_migrate(cfg):
    script = os.path.join(cfg.APP_DIR, "bin", "migrate.php")
    try:
        result = subprocess.run([cfg.PHP_BIN, script], cwd=cfg.APP_DIR, capture_output=True, text=True, timeout=300)
    except (OSError, subprocess.TimeoutExpired) as e:
        raise RuntimeError(f"migrate.php kon niet draaien: {e}") from e
    output = (result.stdout or "") + (result.stderr or "")
    if result.returncode != 0 or "Migratie mislukt" in output:
        last = output.strip().splitlines()[-1] if output.strip() else f"exitcode {result.returncode}"
        raise RuntimeError(f"migrate.php mislukt: {last}")
    logger.info("migrate.php: %s", output.strip().splitlines()[-1] if output.strip() else "ok")


def _empty_dir(folder):
    os.makedirs(folder, exist_ok=True)
    for entry in os.listdir(folder):
        full = os.path.join(folder, entry)
        if os.path.isdir(full) and not os.path.islink(full):
            shutil.rmtree(full)
        else:
            os.remove(full)


def replace_installation(cfg, db_src, dirs):
    """Stap 4 en 5: database en mappen vervangen, daarna migreren."""
    db_file = cfg.DB_FILE
    tmp = os.path.join(os.path.dirname(db_file), f".restore-{uuid.uuid4().hex[:8]}.db")
    shutil.copyfile(db_src, tmp)
    try:
        for suffix in ("-wal", "-shm", "-journal"):
            if os.path.exists(db_file + suffix):
                os.remove(db_file + suffix)
        os.replace(tmp, db_file)
    finally:
        if os.path.exists(tmp):
            os.remove(tmp)
    for sub in DIRS:
        target = os.path.join(cfg.HTDOCS_DIR, sub)
        _empty_dir(target)
        for entry in sorted(os.listdir(dirs[sub])):
            shutil.copy2(os.path.join(dirs[sub], entry), os.path.join(target, entry))
    run_migrate(cfg)


def resolve_archive(job):
    """Pad van de backup: een naam uit storage/backups/ of een upload in incoming/. Geeft (pad, upload?)."""
    if job.params.get("backup"):
        name = os.path.basename(str(job.params["backup"]))
        if not backup_store.NAME_RE.match(name):
            raise UserError("Ongeldige naam van de backup")
        path = os.path.join(job.storage.backups, name)
        if not os.path.isfile(path):
            raise UserError(f"Backup {name} bestaat niet (meer)")
        return path, False
    if job.params.get("upload"):
        try:
            path = job.storage.abs(str(job.params["upload"]), under="incoming")
        except ValueError as e:
            raise UserError("Ongeldig pad van de upload") from e
        if not os.path.isfile(path):
            raise UserError("Het geüploade bestand is niet (meer) aanwezig")
        return path, True
    raise UserError("Geen backup opgegeven")


def _post_restore(job, username, archive_name, safety_name, manifest):
    """Stap 6: in de teruggezette database. De taak verhuist naar een nieuwe rij (die stond niet in de backup)."""
    conn = db.connect(job.cfg.DB_FILE)
    try:
        # De backup-taak die deze backup maakte, stond tijdens de momentopname nog op running
        backup_job = manifest.get("job_id")
        if isinstance(backup_job, int):
            conn.execute("UPDATE worker_jobs SET status = 'done', progress = 100, message = 'Backup gemaakt (deze backup is teruggezet)', "
                         "result_file = ?, finished_at = datetime('now') WHERE id = ? AND type = 'backup' AND status = 'running'",
                         (f"backups/{archive_name}" if backup_store.NAME_RE.match(archive_name) else None, backup_job))
        conn.execute("UPDATE worker_jobs SET status = 'failed', message = 'Onderbroken door een restore', "
                     "finished_at = datetime('now') WHERE status IN ('queued', 'running')")
        params = {k: v for k, v in job.params.items() if k != "upload"}
        params["safety_backup"] = safety_name
        new_id = db.create_job(conn, "restore", params, status="running", worker_id=job.worker_id or "cli")
        db.audit(conn, "BACKUP_RESTORE", f"Backup {archive_name} teruggezet door {username}; veiligheidsbackup {safety_name}")
    except Exception:
        conn.close()
        raise
    old = job.conn
    job.conn, job.id = conn, new_id
    return old


def _reattach(job):
    """Na het vervangen van de database: nieuwe verbinding, en de taakrij terugzetten als die er niet in staat."""
    old = job.conn
    job.conn = db.connect(job.cfg.DB_FILE)
    try:
        old.close()
    except Exception:  # noqa: BLE001
        pass
    try:
        row = db.get_job(job.conn, job.id)
        if row is None or row["type"] != "restore":
            params = {k: v for k, v in job.params.items() if k != "upload"}
            job.id = db.create_job(job.conn, "restore", params, status="running", worker_id=job.worker_id or "cli")
    except sqlite3.Error as e:
        logger.warning("Taakrij van de restore niet terug te zetten: %s", e)


def restore(job, archive_path, username):
    cfg, storage = job.cfg, job.storage
    archive_name = os.path.basename(job.params.get("backup") or job.params.get("original_name") or archive_path)
    # 1. Controleren, nog niets aanraken
    job.progress(5, "Backup controleren")
    manifest, db_path, dirs = validate_backup(cfg, archive_path, job.tmp_dir())
    check = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    try:
        unknown = newer_schema(schema_of(check), schema_of(job.conn))
    finally:
        check.close()
    if unknown:
        raise UserError("Deze backup is van een nieuwere versie van Zebrawave (onbekend: " + ", ".join(unknown[:5]) + ")")

    # 2. Onderhoudsmodus en 3. veiligheidsbackup
    set_maintenance(storage, step="Onderhoud gestart", job_id=job.id, by=username)
    try:
        time.sleep(cfg.RESTORE_GRACE_SECONDS)
        set_maintenance(storage, step="Veiligheidsbackup van de huidige staat maken", job_id=job.id, by=username)
        job.progress(25, "Veiligheidsbackup maken")
        safety_path, _ = create_backup(cfg, storage, "pre_restore", created_by=job.created_by, reporter=Reporter())
    except BaseException:
        clear_maintenance(storage)
        raise
    safety_name = os.path.basename(safety_path)

    # 4–6. Vervangen, migreren, nazorg; bij een fout terug naar de veiligheidsbackup
    try:
        set_maintenance(storage, step="Database en bestanden vervangen", job_id=job.id, by=username)
        job.progress(60, "Database en bestanden vervangen")
        replace_installation(cfg, db_path, dirs)
        set_maintenance(storage, step="Afronden", job_id=job.id, by=username)
        stale = _post_restore(job, username, archive_name, safety_name, manifest)
        stale.close()
    except Exception as e:
        logger.exception("Restore van %s mislukt; terug naar de veiligheidsbackup %s", archive_name, safety_name)
        set_maintenance(storage, step="Terugzetten mislukt; de vorige toestand wordt hersteld", job_id=job.id, by=username)
        try:
            _, safety_db, safety_dirs = validate_backup(cfg, safety_path, storage.make_tmp(f"job-{job.id}-rollback"))
            replace_installation(cfg, safety_db, safety_dirs)
        except Exception as e2:
            logger.exception("Terugdraaien naar %s mislukt", safety_name)
            set_maintenance(storage, step="Terugzetten mislukt en de vorige toestand kon niet worden hersteld",
                            error=f"{type(e2).__name__}: {e2}", job_id=job.id, by=username, safety_backup=safety_name)
            _reattach(job)
            raise UserError("Terugzetten mislukt en terugdraaien ook; de site blijft in onderhoudsmodus. "
                            f"Zie de worker-log; de veiligheidsbackup is {safety_name}") from e2
        finally:
            shutil.rmtree(os.path.join(storage.tmp, f"job-{job.id}-rollback"), ignore_errors=True)
        # De veiligheidsbackup bevat deze taak nog als 'running'; verder in die database
        _reattach(job)
        clear_maintenance(storage)
        raise UserError(f"Terugzetten mislukt ({e}); de vorige toestand is hersteld") from e
    clear_maintenance(storage)
    job.summary = f"Backup {archive_name} teruggezet; veiligheidsbackup: {safety_name}"
    return manifest


@register("restore")
def handle(job):
    path, is_upload = resolve_archive(job)
    # De taak krijgt na de restore een nieuw id; ruim de werkmap van het oude id zelf op
    workdir = os.path.join(job.storage.tmp, f"job-{job.id}")
    try:
        restore(job, path, str(job.params.get("username") or "onbekend"))
    finally:
        shutil.rmtree(workdir, ignore_errors=True)
        if is_upload and os.path.exists(path):
            os.remove(path)
