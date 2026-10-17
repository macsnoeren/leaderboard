#!/bin/sh
# Start de AI-agent. De API key komt uit AI_API_KEY, of anders uit de database van de
# webcontainer (volume read-only op /data), waar de webcontainer de key 'docker-agent' aanmaakt.
set -e

if [ "${AI_AGENT_ENABLED:-1}" != "1" ]; then
    echo "AI-agent staat uit (AI_AGENT_ENABLED=${AI_AGENT_ENABLED}); de container wacht."
    exec sleep infinity
fi

export AI_BASE_URL="${AI_BASE_URL:-http://localhost:${APP_PORT:-8088}/api.php}"
export OLLAMA_URL="${OLLAMA_URL:-http://localhost:11434/api/chat}"

if [ -z "$AI_API_KEY" ]; then
    echo "API key ophalen uit de database..."
    while [ -z "$AI_API_KEY" ]; do
        AI_API_KEY=$(python3 - <<'EOF' 2>/dev/null || true
import sqlite3
db = sqlite3.connect("file:/data/leaderboard.db?mode=ro", uri=True)
row = db.execute("SELECT api_key FROM api_keys WHERE key_name = 'docker-agent' LIMIT 1").fetchone()
print(row[0] if row else "")
EOF
)
        [ -n "$AI_API_KEY" ] || { echo "Nog geen key 'docker-agent' (draait de webcontainer al?), opnieuw over 5s"; sleep 5; }
    done
    export AI_API_KEY
fi

# Alleen cloudmodellen (AI_CLOUD_ONLY, standaard aan): bij een lokaal model start de agent niet, maar wacht
# de container (geen herstartlus). Er draait dan nooit een model op deze machine.
if ! python3 - <<'EOF'
import os, sys
from agentic.settings import from_env, local_models
local = local_models(from_env(os.environ))
if local:
    print("FOUT: alleen cloudmodellen zijn toegestaan (AI_CLOUD_ONLY=1), maar ingesteld is: " + ", ".join(local))
    print("Kies -cloud-modellen in docker/.env (bijv. gpt-oss:20b-cloud) en herstart de agent.")
    sys.exit(1)
EOF
then
    exec sleep infinity
fi

# Controleer of Ollama bereikbaar is en de modellen er zijn; alleen een waarschuwing, de agent start toch.
# Een -cloud-model staat er pas na "ollama signin" op de host (of met een Ollama-API-key).
python3 - <<'EOF' || true
import json, os, re, urllib.request
from agentic.settings import from_env
s = from_env(os.environ)
base = re.sub(r"/api/.*$", "", s["OLLAMA_URL"])
models = sorted({s["INTENT_MODEL"], s["HELPER_MODEL"], s["VALIDATOR_MODEL"], s["DESIGNER_MODEL"], *s["ASSESSOR_MODELS"]})
print("Modellen: " + ", ".join(models))
try:
    with urllib.request.urlopen(base + "/api/tags", timeout=5) as r:
        available = {m["name"] for m in json.load(r)["models"]}
    print(f"Ollama bereikbaar op {base}")
    for m in models:
        if m not in available and f"{m}:latest" not in available:
            print(f"Waarschuwing: model '{m}' staat niet in Ollama (ollama pull {m})")
except Exception as e:
    print(f"Waarschuwing: Ollama niet bereikbaar op {base}: {e}")
EOF

echo "Agent gebruikt $AI_BASE_URL en Ollama op $OLLAMA_URL"
exec python3 -u main.py
