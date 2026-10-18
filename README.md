# OperationRoom

Omgeving voor teamspellen, hackathons en opdrachten voor studenten, met een live leaderboard. Teams zien hun
opdracht op hun eigen dashboard en sturen hun antwoord via de chat; een docent beoordeelt het en zet het team
een level omhoog. Alles hoort bij een **spel**: teams, opdrachten, het archief en mededelingen.

Het begon als het detectivespel Zebrawave. Exports en backups van vóór de naamswijziging
(`zebrawave-game`, `zebrawave-backup-…`) zijn nog gewoon te importeren en terug te zetten.

Onderdelen:

- **Webapplicatie** (`htdocs/`, `conf/`): PHP + SQLite, zonder framework. Publiek leaderboard en beamer,
  teamdashboard, docentenomgeving.
- **AI-agent** (optioneel, [agentic-service/](agentic-service/README.md)): leest elk teambericht mee en doet,
  afhankelijk van de AI-modus van het team, een suggestie aan de docent, antwoordt het team of zet het zelf
  een level omhoog. Werkt met Ollama-cloudmodellen.
- **Worker** ([worker-service/](worker-service/README.md)): taken in de achtergrond: spel exporteren en
  importeren, backups en restores, en een rapport (PDF) per spel.

## Starten

Met Docker (aanbevolen): zie [docker/README.md](docker/README.md).

```sh
docker/start.sh        # daarna http://localhost:8088/setup.php → eerste beheerdersaccount
```

Zonder Docker (PHP 8 met SQLite, Python 3.10+):

```sh
git submodule update --init                    # PHPMailer
cp conf/config.php.example conf/config.php     # SMTP en BASE_URL invullen
(cd bin && ./setup.sh)                         # maakt database/, htdocs/artifacts/ en storage/
php -S localhost:8000 -t htdocs                # open /setup.php → eerste beheerdersaccount
php bin/migrate.php
```

Daarna de worker en eventueel de AI-agent starten, zie hun README. De agent heeft een eigen API-key nodig:
maak die per agent aan onder **API-sleutels** en zet hem in `agentic-service/config.py`. Linksonder in het
dashboard staat of de agent actief is.

## Een spel voorbereiden

1. Maak onder **Spellen** een spel aan (of importeer een export) en zet de opdrachten klaar onder
   **Opdrachten**. Wil je dat de AI teams antwoordt, laat dan per opdracht een rubric maken en keur die goed.
2. Opdrachten die afhangen van externe omgevingen (zoals de Darknet-chat voor "Darknet Gamers!"): herstart
   die omgeving voor een schone lei, maak er een nieuwe token aan (bijv. `ZEBR-A234`) en zet die in de tekst
   van de opdracht. De link naar de Darknet-chat in de zijbalk komt uit `DARKNET_CHAT_URL` in `config.php`.
3. Laat teams zich aanmelden via de aanmeldlink van het spel, of maak ze zelf aan, en start het spel.

## Testen

PHP-tests in `tests/` (exitcode ≠ 0 bij een fout), in Docker als PHP niet geïnstalleerd is:

```sh
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/game_test.php
```

Zie de README's van de agent en de worker voor hun tests, en `CLAUDE.md` voor de architectuur en de
afspraken in de code.
