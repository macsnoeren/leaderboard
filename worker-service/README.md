# worker-service

Python-worker voor OperationRoom die taken in de achtergrond uitvoert: spellen exporteren en importeren (ook
archieven van de eerste versie van `bin/export_assignments.py`), volledige backups (handmatig en elke nacht)
en restores, en een rapport (PDF) per spel.

De website zet taken in de wachtrij (`worker_jobs`, pagina **Taken**) en controleert de rechten; de worker
voert ze uit, direct op de database en de bestanden (niet via `api.php`: backup en restore moeten alles
kunnen lezen en vervangen). Er is ook een commandoregel voor beheer en noodgevallen.

```
 Website (PHP)                               worker-service (Python)
 operator/admin klikt "Exporteren",            main.py: lus
 "Backup maken", uploadt een archief …         1. heartbeat → worker_status
        │                                      2. planner: tijd voor de nachtelijke backup? → taak
        ▼                                      3. claim oudste 'queued' taak (atomair)
 enqueueJob(): worker_jobs + audit_logs ─────► 4. handler voert uit, meldt voortgang
        ▲                                      5. resultaat → storage/jobs/<id>/, status done|failed
        │ polling jobs.php?ajax=jobs           6. elk uur opruimen: verlopen resultaten, oude backups
 jobs.php, job_download.php (rechten) ◄──────
```

## Taken en rechten

| Taak (`type`) | Wie | Resultaat |
|---|---|---|
| `game_export` | operator van het spel, admin (`game.php`) | `operationroom-spel-<naam>-<datum>.tar.gz`, als sjabloon of met teams en voortgang |
| `report` | operator van het spel, admin (`game.php`, snelknop in `archive.php`) | `operationroom-rapport-<naam>-<datum>.pdf` |
| `game_import` | admin (`games.php`): een import maakt een spel | nieuw spel (`result_game_id`), de admin wordt eraan gekoppeld |
| `backup` | admin (`backups.php`), of de planner (`created_by` NULL) | `storage/backups/operationroom-backup-<datum>.tar.gz` |
| `backup_upload` | admin | geüploade backup, eerst volledig gecontroleerd, daarna in de lijst |
| `restore` | admin, met het eigen wachtwoord (fout telt mee in `login_attempts`, scope `restore`) | omgeving vervangen |

Downloaden mag alleen wie de taak mocht starten (`jobDownloadAllowed()`); backups downloaden alleen admins
via `backups.php?download=`. Audit: `GAME_EXPORT`, `GAME_IMPORT`, `REPORT_CREATE`, `BACKUP_CREATE`,
`BACKUP_DOWNLOAD`, `BACKUP_PIN`, `BACKUP_DELETE`, `BACKUP_RESTORE`, `JOB_DOWNLOAD`; de worker logt het eind
van een taak met `user_id` NULL (`JOB_DONE`/`JOB_FAILED`). Nooit wachtwoorden of tokens in `params_json`.

Een nieuw soort taak is één handler met `@register` in `worker/tasks/` plus een knop die `enqueueJob()`
aanroept; wachtrij, status, downloads en opruimen zijn gedeeld.

## Wachtrij

```
queued ──claim──► running ──► done      (resultaat te downloaden tot expires_at)
   │                 └──────► failed    (message = leesbare fout; details in de worker-log)
   └─ annuleren ──► cancelled           (alleen zolang queued, door de aanvrager of een admin)
```

- **Claimen is atomair** (`UPDATE … WHERE id = (oudste queued) AND status = 'queued'`); twee workers
  pakken nooit dezelfde taak. Eén worker per installatie is genoeg.
- **Exclusief**: `restore` en `backup` draaien alleen als er niets anders draait, en zolang er een
  `restore` in de wachtrij staat, claimt de worker niets anders.
- Een lopende taak werkt `heartbeat_at` bij. Een taak die `running` is zonder recente heartbeat wordt bij
  de start van de worker `failed` ("onderbroken"). **Er is geen automatische herhaling**: een import of
  restore twee keer uitvoeren is erger dan opnieuw klikken.
- De website toont "Worker actief" bij een heartbeat in `worker_status` van minder dan 120 s. Taken van de
  CLI melden geen heartbeat.

## Opslag (`storage/`)

Buiten de webroot, gedeeld door website en worker (in Docker een eigen volume):

```
storage/
  incoming/          uploads voor import/restore (willekeurige naam; weg na afloop van de taak), en
                     attachment-<id>.part: een bijlage die de website in stukken ontvangt (weg na het opslaan,
                     of door de website na 2 uur zonder nieuw stuk)
  jobs/<id>/         resultaten van export en rapport (weg na RESULT_TTL_DAYS; de rij blijft, "verlopen")
  backups/           backups met een .json met metadata (trigger, tellingen, vastgezet) ernaast
  tmp/               werkmap van de worker
  maintenance.json   alleen aanwezig tijdens een restore
```

## Archieven

Alle archieven zijn `tar.gz` met `manifest.json` in de root. Ze zijn onbetrouwbare invoer en worden alleen
via `worker/archive.py` gelezen: nooit `extractall`, alleen bekende namen, alleen gewone bestanden (geen
links, `..` of absolute paden), limieten op grootte en aantal (`MAX_ARCHIVE_MB`, `MAX_ARCHIVE_FILES`),
checksums controleren, en geïmporteerde bestanden krijgen altijd een eigen naam met een extensie uit een
allowlist.

### Spel-export (v3)

```
manifest.json      {"format": "operationroom-game", "format_version": 3, "game": {…}, "assignments": [...], "files": {pad: {size, sha256}}}
files/<naam>       bijlagen van opdrachten
logo/<naam>        logo van het spel (optioneel)
progress.json      alleen bij "met teams en voortgang"
```

- Opdrachten hebben dezelfde veldnamen als in v1/v2; `ai_rubric` is een object in plaats van JSON-tekst.
- Alleen spelinformatie: geen `join_code`, status, tijden, `game_teachers`, en geen dialoog van de
  opdracht-assistent (de goedgekeurde rubric wel).
- `progress.json` gebruikt eigen refs in plaats van id's: teams (zonder e-mail, token of
  `ai_processing_*`), berichten, archiefregels (ook van verwijderde teams; bij wie goedkeurde alleen
  `leveled_by_kind` `teacher`/`ai`, geen operatornamen), AI-beoordelingen, tussenstanden, mededelingen en
  downloads.

### Oude archieven (v1, v2)

Van `bin/export_assignments.py`, herkenbaar aan een manifest dat een **lijst** is. v1 (eerste versie,
commit `c249ede`, vóór spellen) heeft per opdracht `assignment_number`, `title`, `description`,
`instruction`, `criteria`, `time_limit`, `artifact_file`; v2 (vlak voor de worker) daarbij `ai_rubric`
(JSON-tekst), `ai_rubric_status` en `ai_auto_allowed`. Wat ontbreekt krijgt de standaard van een nieuw spel.
De fixtures staan in [tests/fixtures/](tests/fixtures/README.md).

### Import

Elk formaat wordt eerst vertaald naar één `ImportModel` (`worker/formats/`); de import kent maar één pad.

- **Altijd een nieuw spel**: status `draft`, nieuwe `join_code`, `registration_open = 0`. Met voortgang
  wordt het `paused` (of `finished`), zodat de AI-agent niet meteen antwoordt. Bestaat de naam al, dan
  komt er " (2)" achter.
- Opdrachten moeten 1..N zijn; gaten of dubbele nummers worden hernummerd met een melding, behalve bij een
  import met voortgang (dan kloppen de levels niet meer: geweigerd).
- Teams krijgen nieuwe `access_token`s en hebben geen e-mailadres; links opnieuw mailen na het invullen
  van een adres.
- Bijlagen groter dan `MAX_ATTACHMENT_MB`, of waarmee het spel boven `MAX_GAME_ATTACHMENTS_MB` komt, worden
  overgeslagen met een melding (dezelfde grenzen als op de website), net als bijlagen met een verkeerde extensie.
- Alles in één transactie; bij een fout worden de al geschreven bestanden weer verwijderd.
- Het oude "wis de opdrachten van spel X en importeer" bestaat niet meer: importeer als nieuw spel en
  verwijder het oude.

### Backup

```
manifest.json            {"format": "operationroom-backup", "format_version": 1, "trigger", "schema", "counts", "files", "job_id"}
database/leaderboard.db  momentopname via de online backup-API van SQLite (consistent, ook tijdens schrijven)
htdocs/artifacts/…
htdocs/uploads/…
```

Bevat wachtwoordhashes, TOTP-secrets, API-sleutels en team-tokens: alleen in `storage/backups/`, alleen
voor admins, elke download in de audit-log. Niet in de backup: `conf/config.php`, `config.py`, `storage/`.

## Bewaartermijnen

De planner zet elke dag om `BACKUP_TIME` (tijdzone van de worker, `TZ`) een backup in de wachtrij; een
gemiste backup van vandaag wordt na een herstart ingehaald. Onder `BACKUP_MIN_FREE_MB` vrije ruimte faalt
de backup.

| Soort | Bewaard |
|---|---|
| automatisch (`scheduled`) | de laatste `BACKUP_KEEP` (14) |
| handmatig en geüpload (`manual`, `uploaded`) | `BACKUP_MANUAL_DAYS` (90 dagen) |
| veiligheid (`pre_restore`) | `BACKUP_PRE_RESTORE_DAYS` (30 dagen) |

De nieuwste backup wordt nooit verwijderd, en een vastgezette backup ("bewaren") valt buiten elke termijn.
`0` bij een termijn = nooit automatisch verwijderen.

## Restore

1. **Controleren, nog niets aanraken**: archief veilig openen, checksums, `PRAGMA integrity_check` op de
   uitgepakte database; een backup van een nieuwere app-versie (onbekende tabellen/kolommen) wordt geweigerd.
2. **Onderhoudsmodus aan** (`storage/maintenance.json`), `RESTORE_GRACE_SECONDS` wachten.
3. **Veiligheidsbackup** van de huidige staat (`pre_restore`).
4. **Vervangen**: database via een tijdelijk bestand en `os.replace()` (atomair); de inhoud van
   `artifacts/` en `uploads/` (niet de mappen zelf: in Docker zijn dat mount points).
5. `php bin/migrate.php`, zodat een oudere backup de nieuwe kolommen krijgt.
6. **Nazorg**: taken die in de backup `queued`/`running` stonden worden `failed` (de backup-taak zelf
   `done`); de restore-taak wordt opnieuw ingevoegd; `BACKUP_RESTORE` in de audit-log.
7. **Onderhoudsmodus uit.**

Een fout in stap 4–6 zet automatisch de veiligheidsbackup terug; lukt ook dat niet, dan blijft de
onderhoudsmodus aan met de oorzaak in `maintenance.json` en de log.

De onderhoudsmodus is een bestand, geen databasevlag, omdat de database wordt vervangen. `getDB()` geeft
dan een 503: een pagina die zichzelf ververst, leeg bij `?ajax…`, JSON bij `api.php` (de AI-agent wacht).
De commandoregel slaat de controle over. Na een restore blijft iedereen ingelogd zolang `session_version`
in de backup klopt; een admin die niet in de backup staat, is uitgelogd. Is de `docker-agent`-key anders,
herstart dan de agent-container.

## Rapport

Per spel één PDF (fpdf2, lettertype DejaVu Sans; zonder valt het terug op Helvetica en worden tekens
buiten Latin-1 een '?'), gelezen uit een momentopname van de database:

- **Goedgekeurde antwoorden per opdracht**: modelantwoord en rubric, daarna per team wanneer, welk
  antwoord, door wie (operator of AI met oordeel) en na hoeveel pogingen. Zo zie je in één keer of alle
  goedgekeurde antwoorden echt goed zijn.
- **Verloop per team** (ook verwijderde teams, uit het archief): eindlevel, rang en per opdracht start,
  goedkeuring, duur, pogingen, vragen en wie goedkeurde.

`levelUp()` vult daarvoor `leveled_by`, `leveled_by_name`, `ai_evaluation_id`, `accepted_message_id`,
`accepted_answer` en `level_started_at` in `completed_assignments`. Voor oudere archiefregels leidt het
rapport het af (antwoord = laatste `Team: …`-regel uit `chat_history`, "door" = de dichtstbijzijnde
`TEAM_LEVEL_UP`/`AI_LEVEL_UP` in `audit_logs`) en markeert de rij als *afgeleid*. Pogingen en vragen komen
uit `ai_evaluations` (`antwoord`/`vraag`), anders telt elk teambericht als poging.

## Draaien met Docker

De service `worker` in `docker/docker-compose.yml` start mee met `docker/start.sh` (image op basis van
`php:8.3-cli` met Python en `fonts-dejavu-core`, draait als `www-data`). Zie
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
| `MAX_ARCHIVE_MB`, `MAX_ARCHIVE_FILES` | `10240`, `10000` | grenzen voor archieven (uitgepakt; een backup bevat alle spellen); `MAX_ARCHIVE_MB` ook in `conf/config.php` |
| `MAX_ATTACHMENT_MB`, `MAX_GAME_ATTACHMENTS_MB` | `500`, `2048` | grootste bijlage en alle bijlagen van een spel samen bij een import; ook in `conf/config.php` |
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

## Testen

```sh
docker run --rm -v "$PWD":/app -w /app/worker-service python:3.12-slim \
  sh -c "pip install -q -r requirements.txt && python -m unittest discover -s tests -v"
```

De tests werken in tijdelijke mappen met een eigen database (`tests/schema.py`); `migrate.php` wordt
gemockt. De test met het DejaVu-lettertype draait alleen als dat lettertype er is (in de worker-image wel).
De fixtures `tests/fixtures/legacy_v*.tar.gz` zijn gemaakt met de oude exportscripts zelf, zie
[tests/fixtures/README.md](tests/fixtures/README.md).
