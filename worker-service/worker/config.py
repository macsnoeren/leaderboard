"""Configuratie van de worker: standaardwaarden, overschreven door een optionele config.py naast main.py.

Zie de tabel in README.md en config.py.sample. Relatieve paden zijn ten opzichte van worker-service/ (of
base_dir), zodat de worker vanuit elke werkmap hetzelfde vindt. Getallen worden altijd int, ook als ze als
tekst binnenkomen (docker/worker-config.py zet ze uit de environment).
"""
import importlib.util
import os
from types import SimpleNamespace

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

DEFAULTS = {
    "DB_FILE": "../database/leaderboard.db",
    "HTDOCS_DIR": "../htdocs",
    "STORAGE_DIR": "../storage",
    "APP_DIR": "..",
    "PHP_BIN": "php",
    # Leeg = de commit uit .git van APP_DIR (zie app_version())
    "APP_VERSION": "",
    "POLL_INTERVAL": 5,
    "RESULT_TTL_DAYS": 7,
    # Leeg = geen automatische backups
    "BACKUP_TIME": "03:00",
    "BACKUP_KEEP": 14,
    "BACKUP_MANUAL_DAYS": 90,
    "BACKUP_PRE_RESTORE_DAYS": 30,
    "BACKUP_MIN_FREE_MB": 500,
    "MAX_ARCHIVE_MB": 10240,
    "MAX_ARCHIVE_FILES": 10000,
    "MAX_ATTACHMENT_MB": 500,
    "MAX_GAME_ATTACHMENTS_MB": 2048,
    "RESTORE_GRACE_SECONDS": 5,
    "REPORT_FONT": "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    "REPORT_FONT_BOLD": "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
}

# Paden die ten opzichte van base_dir worden opgelost (PHP_BIN niet: "php" staat in het PATH)
PATH_KEYS = {"DB_FILE", "HTDOCS_DIR", "STORAGE_DIR", "APP_DIR", "REPORT_FONT", "REPORT_FONT_BOLD"}
INT_KEYS = {"POLL_INTERVAL", "RESULT_TTL_DAYS", "BACKUP_KEEP", "BACKUP_MANUAL_DAYS", "BACKUP_PRE_RESTORE_DAYS",
            "BACKUP_MIN_FREE_MB", "MAX_ARCHIVE_MB", "MAX_ARCHIVE_FILES", "MAX_ATTACHMENT_MB", "MAX_GAME_ATTACHMENTS_MB",
            "RESTORE_GRACE_SECONDS"}

_FROM_FILE = object()


def _load_file(base_dir):
    """config.py in base_dir als module, of None als die er niet is."""
    path = os.path.join(base_dir, "config.py")
    if not os.path.isfile(path):
        return None
    spec = importlib.util.spec_from_file_location("worker_local_config", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _coerce(key, value, base_dir):
    if key in INT_KEYS:
        return int(value)
    if key in PATH_KEYS and value:
        return os.path.abspath(os.path.join(base_dir, str(value)))
    return "" if value is None else str(value)


def load(module=_FROM_FILE, base_dir=None, **overrides):
    """Instellingen als namespace.

    module: een module of namespace met overschrijvingen; standaard config.py in base_dir (als die bestaat),
    None = geen. base_dir: map voor relatieve paden en config.py; standaard worker-service/.
    overrides: gaan boven alles (gebruikt door de tests).
    """
    base_dir = os.path.abspath(base_dir or BASE_DIR)
    if module is _FROM_FILE:
        module = _load_file(base_dir)
    values = {}
    for key, default in DEFAULTS.items():
        value = getattr(module, key, None) if module is not None else None
        # Een lege BACKUP_TIME is een keuze (geen planner); bij de rest betekent leeg "standaard"
        if value is None or (value == "" and key != "BACKUP_TIME"):
            value = default
        if key in overrides:
            value = overrides[key]
        values[key] = _coerce(key, value, base_dir)
    return SimpleNamespace(**values)


def _git_commit(app_dir):
    """Korte commit van de checkout in app_dir, of None (geen .git, of onleesbaar)."""
    git = os.path.join(app_dir, ".git")
    try:
        # In een submodule is .git een bestand met "gitdir: <pad>"
        if os.path.isfile(git):
            with open(git, encoding="utf-8") as f:
                line = f.read().strip()
            if not line.startswith("gitdir:"):
                return None
            git = os.path.normpath(os.path.join(app_dir, line[len("gitdir:"):].strip()))
        with open(os.path.join(git, "HEAD"), encoding="utf-8") as f:
            head = f.read().strip()
        if head.startswith("ref:"):
            ref = head[len("ref:"):].strip()
            ref_file = os.path.join(git, ref)
            if os.path.isfile(ref_file):
                with open(ref_file, encoding="utf-8") as f:
                    head = f.read().strip()
            else:
                head = None
                packed = os.path.join(git, "packed-refs")
                if os.path.isfile(packed):
                    with open(packed, encoding="utf-8") as f:
                        for row in f:
                            parts = row.split()
                            if len(parts) == 2 and parts[1] == ref:
                                head = parts[0]
                                break
        return head[:7] if head and len(head) >= 7 else None
    except OSError:
        return None


def app_version(cfg):
    """Versie voor manifesten: APP_VERSION, anders de commit uit .git, anders 'onbekend'."""
    return cfg.APP_VERSION or _git_commit(cfg.APP_DIR) or "onbekend"
