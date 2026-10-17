#!/bin/sh
# Bouwt en start de leaderboard testomgeving (webapp, AI-agent en Mailpit).
set -e
cd "$(dirname "$0")"

docker compose up -d --build

PORT=$(grep -s '^APP_PORT=' .env | cut -d= -f2)
echo
echo "Leaderboard draait op http://localhost:${PORT:-8088}"
echo "Eerste keer? Maak een beheerder aan via http://localhost:${PORT:-8088}/setup.php"
echo "Logs: docker compose -f $(pwd)/docker-compose.yml logs -f"
