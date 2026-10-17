# Docker testomgeving

Drie containers: `leaderboard` (Apache + PHP, de webapplicatie), `agent` (de Python AI-agent) en `mailpit`. De agent gebruikt de Ollama die op de host draait.

```sh
docker/start.sh            # bouwen en starten
docker/stop.sh             # stoppen, data blijft bewaard
docker/stop.sh --clean     # stoppen en database + artifacts wissen
```

Open daarna http://localhost:8088/setup.php en maak het eerste beheerdersaccount aan.

- **Database, artifacts en logo's** staan in de Docker volumes `database`, `artifacts` en `uploads`, dus ze blijven bewaard bij een rebuild. Met `docker compose -f docker/docker-compose.yml down -v` begin je weer helemaal schoon.
- **AI-agent** (`agentic-service/main.py`, zie `agentic-service/README.md`): de webcontainer maakt een API key aan (`docker-agent`); de agent-container leest die (alleen-lezen) uit de database en start daarna. Linksonder in het dashboard moet "Actief" staan; "AI-agent actief, model niet bereikbaar" betekent dat Ollama of het model niet antwoordt (de AI escaleert dan alles naar de docent). Logs: `docker compose -f docker/docker-compose.yml logs -f agent`.
  - Standaard gebruikt de agent `gpt-oss:20b-cloud` (intentie, hulp-agent, beoordelaar) en `gpt-oss:120b-cloud` (validator, opdracht-assistent) via de Ollama op de host. Die cloudmodellen werken pas na eenmalig `ollama signin` op de host (of met een Ollama-API-key in de omgeving van Ollama). Controleren: `python3 agentic-service/tools/check_ollama.py`.
  - Per agent een model en denkniveau in `docker/.env`: `AI_INTENT_MODEL`/`AI_INTENT_THINK`, `AI_HELPER_MODEL`/`AI_HELPER_THINK`, `AI_ASSESSOR_MODELS` (meerdere = meerderheidsstem)/`AI_ASSESSOR_THINK`, `AI_VALIDATOR_MODEL`/`AI_VALIDATOR_THINK`, `AI_DESIGNER_MODEL`/`AI_DESIGNER_THINK`. Leeg = standaard. Denkniveau `low`, `medium` of `high`. Alleen cloudmodellen (naam eindigt op `-cloud` of `:cloud`): met `AI_CLOUD_ONLY=1` (standaard) weigert de agent een lokaal model en wacht de container met een foutmelding in de logs, zodat er nooit een model op deze machine draait. Verder `AI_POLL_INTERVAL` (standaard 10 s), `AI_MAX_CHAT_TOKENS`, `AI_LLM_TIMEOUT` en `OLLAMA_URL` (naar `/api/chat`). De oude variabele `AI_MODELS` wordt niet meer gebruikt.
  - Bij de start meldt de agent welke modellen ontbreken in Ollama.
  - Alleen de agent herstarten, bijvoorbeeld na het wijzigen van de modellen: `docker compose -f docker/docker-compose.yml up -d agent`. Stoppen: `... stop agent` (de agent meldt zich dan netjes af).
  - `AI_AGENT_ENABLED=0` zet de agent uit: de container wacht dan alleen.
  - De agent tegen een andere server laten draaien (bijvoorbeeld productie), met de Ollama van deze computer: zet `AI_BASE_URL=https://…/api.php` en `AI_API_KEY=<key uit 🔑 API Keys>` in `docker/.env` en start alleen de agent: `docker compose -f docker/docker-compose.yml up -d --build --no-deps agent`.
  - Wat de AI mag, stel je per team in op het dashboard (of voor alle teams tegelijk); standaard alleen suggesties. Antwoorden en automatisch een level omhoog werken alleen bij opdrachten met een goedgekeurde rubric (📘 Assignments).
- **Instellingen** zoals poort, modellen en SMTP: kopieer `.env.example` naar `docker/.env` en pas aan. E-mail staat standaard uit.
- **Mail testen met Mailpit**: `docker compose` start ook [Mailpit](https://mailpit.axllent.org/), dat alle mail opvangt in plaats van hem te versturen. Zet in `docker/.env`: `ENABLE_EMAIL=true`, `SMTP_HOST=localhost`, `SMTP_PORT=1025`, `SMTP_SECURE=` (leeg), `SMTP_AUTH=false` en een `FROM_EMAIL`, en herstart. De mail (teamlinks, uitnodigings- en resetmails) lees je op http://localhost:8025. Met `ENABLE_EMAIL=false` wordt er niets verstuurd: de beheerder ziet uitnodigings- en resetlinks dan in de flash-melding, en links van "Wachtwoord vergeten" staan alleen in de logs (`docker compose -f docker/docker-compose.yml logs leaderboard`).
- **2FA verplicht voor beheerders**: `REQUIRE_2FA_ADMIN=true` in `docker/.env`.
- **Code wijzigen**: herstart met `--build`, of zet de `htdocs`-mount in `docker-compose.yml` aan om PHP-wijzigingen direct te zien.
- **Host-netwerk**: de containers draaien op het netwerk van de host. Zo bereikt de agent Ollama op `localhost:11434` (Ollama luistert standaard alleen op localhost) zonder dat je Ollama anders hoeft in te stellen. Dit werkt alleen op Linux.
