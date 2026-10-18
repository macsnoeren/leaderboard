# worker-service

Python-worker voor Zebrawave die taken in de achtergrond uitvoert: spellen exporteren en importeren (ook
archieven van de eerste versie van `bin/export_assignments.py`), volledige backups (handmatig en elke nacht)
en restores, en een rapport (PDF) per spel. Ontwerp: [DESIGN.md](DESIGN.md); uitwerking in taken:
[TASKS.md](TASKS.md).

De website zet taken in de wachtrij (`worker_jobs`, pagina **Taken**) en controleert de rechten; de worker
voert ze uit, direct op de database en de bestanden. Er is ook een commandoregel voor beheer en noodgevallen.

## Draaien met Docker

De service `worker` in `docker/docker-compose.yml` start mee met `docker/start.sh`. Zie
[docker/README.md](../docker/README.md) voor de instellingen (`WORKER_*` en `TZ`).

## Draaien zonder Docker

Vereist: Python 3.10+, PHP-CLI (voor `bin/migrate.php` na een restore) en bij voorkeur het lettertype
DejaVu Sans (`fonts-dejavu-core`) voor het rapport.

```sh
cd worker-service
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
cp config.py.sample config.py          # optioneel; alles heeft een standaardwaarde
(cd ../bin && ./setup.sh)              # maakt o.a. storage/ (buiten de webroot)
.venv/bin/python main.py               # stoppen met Ctrl+C: de lopende taak wordt afgemaakt
```

`STORAGE_DIR` in `config.py` moet dezelfde map zijn als `STORAGE_DIR` in `conf/config.php` (standaard
`storage/` in de repo-root). De worker en de webserver moeten als dezelfde gebruiker draaien (of dezelfde
groep met schrijfrechten), omdat ze dezelfde bestanden lezen en schrijven.

## Configuratie (`config.py`)

| Naam | Standaard | Betekenis |
|---|---|---|
| `DB_FILE` | `../database/leaderboard.db` | zelfde database als PHP |
| `HTDOCS_DIR` | `../htdocs` | voor `artifacts/` en `uploads/` |
| `STORAGE_DIR` | `../storage` | uploads, exports, rapporten, backups |
| `APP_DIR`, `PHP_BIN` | `..`, `php` | voor `bin/migrate.php` na een restore |
| `APP_VERSION` | leeg | versie in manifesten; leeg = commit uit `.git` |
| `POLL_INTERVAL` | `5` | seconden tussen twee rondes |
| `RESULT_TTL_DAYS` | `7` | zo lang zijn exports en rapporten te downloaden (ook `RESULT_TTL_DAYS` in `conf/config.php`) |
| `BACKUP_TIME` | `03:00` | lokale tijd van de nachtelijke backup; leeg = geen |
| `BACKUP_KEEP` | `14` | aantal automatische backups dat bewaard blijft |
| `BACKUP_MANUAL_DAYS` | `90` | handmatige en geüploade backups (0 = nooit verwijderen) |
| `BACKUP_PRE_RESTORE_DAYS` | `30` | veiligheidsbackups (0 = nooit verwijderen) |
| `BACKUP_MIN_FREE_MB` | `500` | minder vrij = geen backup |
| `MAX_ARCHIVE_MB`, `MAX_ARCHIVE_FILES` | `500`, `10000` | grenzen voor archieven (uitgepakt) |
| `RESTORE_GRACE_SECONDS` | `5` | wachttijd na het aanzetten van de onderhoudsmodus |
| `REPORT_FONT`, `REPORT_FONT_BOLD` | DejaVu Sans | Unicode-lettertype; zonder valt de PDF terug op Helvetica (Latin-1) |

## Commandoregel

Vanuit `worker-service/` (in Docker: `docker compose -f docker/docker-compose.yml exec worker python3 -m worker …`):

```sh
python3 -m worker export-game --game 3 [--with-progress] [-o spel.tar.gz]
python3 -m worker import-game archief.tar.gz [--name "Nieuwe naam"]   # altijd een nieuw spel
python3 -m worker inspect archief.tar.gz                               # formaat en inhoud; verandert niets
python3 -m worker backup [-o backup.tar.gz]
python3 -m worker restore backup.tar.gz --yes                          # ook als de website niet meer werkt
python3 -m worker report --game 3 [--sections answers,teams] [-o rapport.pdf]
```

Elk commando (behalve `inspect`) maakt een rij in `worker_jobs` voor de historie en voert de taak direct
uit, zonder wachtrij. Exitcode 0 = gelukt, 1 = mislukt, 2 = verkeerd gebruik (bijv. `restore` zonder `--yes`).

## Archieven

- **Spel-export (v3)**: `tar.gz` met `manifest.json` (object met `format: zebrawave-game`,
  `format_version: 3`), `files/`, `logo/` en optioneel `progress.json` (teams en voortgang, zonder
  e-mailadressen, tokens of docentnamen). Zie DESIGN §6.
- **Oude archieven** van `bin/export_assignments.py` (v1 zonder spel en AI-velden, v2 met rubric) worden
  herkend aan het manifest als lijst en krijgen standaardwaarden voor wat ontbreekt.
- **Backup**: `tar.gz` met `manifest.json` (`format: zebrawave-backup`), `database/leaderboard.db` en
  `htdocs/artifacts|uploads/`. Bevat ook wachtwoordhashes, TOTP-secrets en API-sleutels: veilig bewaren.

## Testen

```sh
docker run --rm -v "$PWD":/app -w /app/worker-service python:3.12-slim \
  sh -c "pip install -q -r requirements.txt && python -m unittest discover -s tests -v"
```

De tests werken in tijdelijke mappen met een eigen database (`tests/schema.py`); `migrate.php` wordt
gemockt. De test met het DejaVu-lettertype draait alleen als dat lettertype er is (in de worker-image wel).
De fixtures `tests/fixtures/legacy_v*.tar.gz` zijn gemaakt met de oude exportscripts zelf, zie
[tests/fixtures/README.md](tests/fixtures/README.md).
