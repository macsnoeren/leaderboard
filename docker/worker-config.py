# config.py voor de worker in Docker: de paden zijn die van de volumes, de rest komt uit de environment
# (WORKER_<NAAM>, zie docker-compose.yml en docker/.env). Ontbrekende waarden krijgen de standaard uit
# worker/config.py.
import os

DB_FILE = "/var/www/leaderboard/database/leaderboard.db"
HTDOCS_DIR = "/var/www/leaderboard/htdocs"
STORAGE_DIR = "/var/www/leaderboard/storage"
APP_DIR = "/var/www/leaderboard"
PHP_BIN = "php"

for _key in ("POLL_INTERVAL", "RESULT_TTL_DAYS", "BACKUP_TIME", "BACKUP_KEEP", "BACKUP_MANUAL_DAYS", "BACKUP_PRE_RESTORE_DAYS",
             "BACKUP_MIN_FREE_MB", "MAX_ARCHIVE_MB", "MAX_ARCHIVE_FILES", "MAX_ATTACHMENT_MB", "MAX_GAME_ATTACHMENTS_MB",
             "RESTORE_GRACE_SECONDS", "APP_VERSION"):
    _value = os.environ.get(f"WORKER_{_key}")
    if _value is not None and (_value != "" or _key == "BACKUP_TIME"):
        globals()[_key] = _value
