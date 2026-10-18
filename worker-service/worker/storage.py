"""Opslag buiten de webroot (DESIGN §5.3): incoming/, jobs/<id>/, backups/, tmp/ en maintenance.json."""
import logging
import os
import re
import shutil
import unicodedata

logger = logging.getLogger(__name__)

SUBDIRS = ("incoming", "jobs", "backups", "tmp")


class Storage:
    def __init__(self, root):
        self.root = os.path.abspath(root)

    def ensure(self):
        for sub in SUBDIRS:
            os.makedirs(os.path.join(self.root, sub), exist_ok=True)

    @property
    def incoming(self):
        return os.path.join(self.root, "incoming")

    @property
    def backups(self):
        return os.path.join(self.root, "backups")

    @property
    def tmp(self):
        return os.path.join(self.root, "tmp")

    @property
    def maintenance_file(self):
        return os.path.join(self.root, "maintenance.json")

    def job_dir(self, job_id):
        path = os.path.join(self.root, "jobs", str(int(job_id)))
        os.makedirs(path, exist_ok=True)
        return path

    def rel(self, path):
        """Pad relatief aan de opslag (zo staat het in worker_jobs.result_file)."""
        rel = os.path.relpath(os.path.abspath(path), self.root)
        if rel.startswith(".."):
            raise ValueError(f"{path} ligt buiten de opslag")
        return rel.replace(os.sep, "/")

    def abs(self, rel, under=None):
        """Absoluut pad van een relatief pad uit de database; weigert alles buiten de opslag (of buiten under)."""
        base = os.path.join(self.root, under) if under else self.root
        path = os.path.abspath(os.path.join(self.root, rel))
        if os.path.commonpath([path, os.path.abspath(base)]) != os.path.abspath(base):
            raise ValueError(f"{rel} ligt buiten {base}")
        return path

    def make_tmp(self, name):
        path = os.path.join(self.tmp, name)
        shutil.rmtree(path, ignore_errors=True)
        os.makedirs(path)
        return path

    def clear_tmp(self):
        """Werkmap leegmaken (bij de start van de worker)."""
        if not os.path.isdir(self.tmp):
            return
        for entry in os.listdir(self.tmp):
            path = os.path.join(self.tmp, entry)
            if os.path.isdir(path) and not os.path.islink(path):
                shutil.rmtree(path, ignore_errors=True)
            else:
                try:
                    os.remove(path)
                except OSError:
                    pass

    def free_mb(self, path=None):
        return shutil.disk_usage(path or self.root).free // (1024 * 1024)


def safe_filename(text, default="spel", max_length=60):
    """Deel van een downloadnaam: alleen a-z, 0-9 en '-' (bijv. 'Operatie Ü/../x' → 'operatie-u-x')."""
    text = unicodedata.normalize("NFKD", str(text or "")).encode("ascii", "ignore").decode("ascii").lower()
    text = re.sub(r"[^a-z0-9]+", "-", text).strip("-")
    return (text[:max_length].strip("-")) or default


def cleanup_results(conn, storage):
    """Verwijdert verlopen resultaten van export en rapport; de rij in worker_jobs blijft. Geeft het aantal terug."""
    rows = conn.execute("SELECT id, result_file FROM worker_jobs WHERE result_file IS NOT NULL AND expires_at IS NOT NULL "
                        "AND expires_at <= datetime('now')").fetchall()
    removed = 0
    for row in rows:
        try:
            path = storage.abs(row["result_file"], under="jobs")
        except ValueError:
            path = None
        if path and os.path.isfile(path):
            os.remove(path)
            try:
                os.rmdir(os.path.dirname(path))
            except OSError:
                pass
        conn.execute("UPDATE worker_jobs SET result_file = NULL WHERE id = ?", (row["id"],))
        removed += 1
    if removed:
        logger.info("%d verlopen resultaten opgeruimd", removed)
    return removed
