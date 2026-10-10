#!/bin/sh
# Stopt de leaderboard testcontainer. Met --clean worden ook database en artifacts verwijderd.
set -e
cd "$(dirname "$0")"

if [ "$1" = "--clean" ]; then
    docker compose down -v
    echo "Gestopt; database en artifacts zijn verwijderd."
else
    docker compose down
    echo "Gestopt; database en artifacts blijven bewaard (gebruik --clean om ze te wissen)."
fi
