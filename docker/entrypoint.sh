#!/bin/sh
set -e

APP=/var/www/leaderboard
DB="$APP/database/leaderboard.db"
APP_PORT="${APP_PORT:-8088}"
BASE_URL="${BASE_URL:-http://localhost:$APP_PORT}"

# Apache op APP_PORT laten luisteren (container draait met host-netwerk)
sed -ri "s/^Listen .*/Listen $APP_PORT/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:$APP_PORT>/" /etc/apache2/sites-available/000-default.conf

# conf/config.php genereren uit de environment
cat > "$APP/conf/config.php" <<EOF
<?php
define('DB_FILE', '$DB');
define('ENABLE_EMAIL', ${ENABLE_EMAIL:-false});
define('SMTP_HOST', '${SMTP_HOST:-}');
define('SMTP_PORT', ${SMTP_PORT:-587});
define('SMTP_USER', '${SMTP_USER:-}');
define('SMTP_PASS', '${SMTP_PASS:-}');
define('SMTP_SECURE', '${SMTP_SECURE-tls}');
define('SMTP_AUTH', ${SMTP_AUTH:-true});
define('FROM_EMAIL', '${FROM_EMAIL:-}');
define('FROM_NAME', '${FROM_NAME:-Assignment System}');
define('BASE_URL', '$BASE_URL');
define('REQUIRE_2FA_ADMIN', ${REQUIRE_2FA_ADMIN:-false});
define('DARKNET_CHAT_URL', '${DARKNET_CHAT_URL-https://zebrawavesecurity.info/_dark_web_chat/}');
define('STORAGE_DIR', '$APP/storage');
define('MAX_ARCHIVE_MB', ${MAX_ARCHIVE_MB:-10240});
define('MAX_ATTACHMENT_MB', ${MAX_ATTACHMENT_MB:-500});
define('MAX_GAME_ATTACHMENTS_MB', ${MAX_GAME_ATTACHMENTS_MB:-2048});
define('RESULT_TTL_DAYS', ${WORKER_RESULT_TTL_DAYS:-7});
EOF

# Database, artifact store, logo-uploads en de opslag van de worker (volumes)
mkdir -p "$APP/database" "$APP/htdocs/artifacts" "$APP/htdocs/uploads" \
         "$APP/storage/incoming" "$APP/storage/jobs" "$APP/storage/backups" "$APP/storage/tmp"

# Schema: setup.php maakt de basistabellen, migrate.php de latere kolommen (beide idempotent)
(cd "$APP/htdocs" && php setup.php > /dev/null 2>&1) || true
php "$APP/bin/migrate.php"

# API key voor de AI-agent; die draait in een eigen container (service 'agent')
if [ "${AI_AGENT_ENABLED:-1}" = "1" ]; then
    sqlite3 "$DB" "INSERT INTO api_keys (key_name, api_key)
                   SELECT 'docker-agent', lower(hex(randomblob(32)))
                   WHERE NOT EXISTS (SELECT 1 FROM api_keys WHERE key_name = 'docker-agent');"
fi

chown -R www-data:www-data "$APP/database" "$APP/htdocs/artifacts" "$APP/htdocs/uploads" "$APP/storage"

exec apache2-foreground
