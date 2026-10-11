# Docker testomgeving

Eén container met Apache + PHP (de webapplicatie) en de Python AI-agent. De agent gebruikt de Ollama die op de host draait.

```sh
docker/start.sh            # bouwen en starten
docker/stop.sh             # stoppen, data blijft bewaard
docker/stop.sh --clean     # stoppen en database + artifacts wissen
```

Open daarna http://localhost:8088/setup.php en maak het eerste beheerdersaccount aan.

- **Database, artifacts en logo's** staan in de Docker volumes `database`, `artifacts` en `uploads`, dus ze blijven bewaard bij een rebuild. Met `docker compose -f docker/docker-compose.yml down -v` begin je weer helemaal schoon.
- **AI-agent**: de container maakt zelf een API key aan (`docker-agent`) en start de agent automatisch. Linksonder in het dashboard moet "Active" staan. Logs bekijk je met `docker compose -f docker/docker-compose.yml logs -f`.
- **Instellingen** zoals poort, modellen en SMTP: kopieer `.env.example` naar `docker/.env` en pas aan. E-mail staat standaard uit.
- **Mail testen met Mailpit**: `docker compose` start ook [Mailpit](https://mailpit.axllent.org/), dat alle mail opvangt in plaats van hem te versturen. Zet in `docker/.env`: `ENABLE_EMAIL=true`, `SMTP_HOST=localhost`, `SMTP_PORT=1025`, `SMTP_SECURE=` (leeg), `SMTP_AUTH=false` en een `FROM_EMAIL`, en herstart. De mail (teamlinks, uitnodigings- en resetmails) lees je op http://localhost:8025. Met `ENABLE_EMAIL=false` wordt er niets verstuurd: de beheerder ziet uitnodigings- en resetlinks dan in de flash-melding, en links van "Wachtwoord vergeten" staan alleen in de logs (`docker compose -f docker/docker-compose.yml logs leaderboard`).
- **2FA verplicht voor beheerders**: `REQUIRE_2FA_ADMIN=true` in `docker/.env`.
- **Code wijzigen**: herstart met `--build`, of zet de `htdocs`-mount in `docker-compose.yml` aan om PHP-wijzigingen direct te zien.
- **Host-netwerk**: de container draait op het netwerk van de host. Zo bereikt de agent Ollama op `localhost:11434` zonder dat je Ollama anders hoeft in te stellen. Dit werkt alleen op Linux.
