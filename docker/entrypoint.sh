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
define('FROM_EMAIL', '${FROM_EMAIL:-}');
define('FROM_NAME', '${FROM_NAME:-Assignment System}');
define('BASE_URL', '$BASE_URL');
EOF

# Database en artifact store (volumes)
mkdir -p "$APP/database" "$APP/htdocs/artifacts"

# Schema: setup.php maakt de basistabellen, migrate.php de latere kolommen (beide idempotent)
(cd "$APP/htdocs" && php setup.php > /dev/null 2>&1) || true
php "$APP/bin/migrate.php"

# AI-agent: eigen API key in de database en config.py naast het script
if [ "${AI_AGENT_ENABLED:-1}" = "1" ]; then
    sqlite3 "$DB" "INSERT INTO api_keys (key_name, api_key)
                   SELECT 'docker-agent', lower(hex(randomblob(32)))
                   WHERE NOT EXISTS (SELECT 1 FROM api_keys WHERE key_name = 'docker-agent');"
    API_KEY=$(sqlite3 "$DB" "SELECT api_key FROM api_keys WHERE key_name = 'docker-agent' LIMIT 1;")
    MODELS=$(echo "${AI_MODELS:-qwen2.5:1.5b}" | tr -d ' ' | sed 's/[^,][^,]*/"&"/g; s/,/, /g')

    cat > "$APP/agentic-service/config.py" <<EOF
API_KEY = "$API_KEY"
BASE_URL = "http://localhost:$APP_PORT/api.php"
OLLAMA_URL = "${OLLAMA_URL:-http://localhost:11434/api/generate}"
LLM_MODELS = [$MODELS]
POLL_INTERVAL = ${AI_POLL_INTERVAL:-30}
EOF

    # Agent op de achtergrond, herstart automatisch als hij stopt
    (
        sleep 3
        cd "$APP/agentic-service"
        while true; do
            python3 -u process_ai_feedback.py || true
            echo "AI-agent gestopt, herstart over 10s..."
            sleep 10
        done
    ) &
fi

chown -R www-data:www-data "$APP/database" "$APP/htdocs/artifacts"

exec apache2-foreground
