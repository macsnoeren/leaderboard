# Taken: worker-service implementeren

Status: **alle taken klaar** (2026-10-18). Waar de uitvoering afwijkt van een taak (bijv. de knoppen
staan bij Spelinstellingen), staat dat in [DESIGN.md §16](DESIGN.md#16-afwijkingen-bij-de-implementatie).

Uitwerking van [DESIGN.md](DESIGN.md) in kleine taken. Elke taak is los op te leveren en heeft een
**Klaar als** dat je kunt testen. Doe de fasen in volgorde. Binnen een fase staan de taken in een
logische volgorde; afhankelijkheden staan erbij.

## Testen

- **PHP** (zelfde stijl als `tests/game_test.php`: plain PHP, in-memory SQLite, exitcode 1 bij
  fout):
  ```sh
  docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/worker_test.php
  ```
- **Python** (standaardbibliotheek `unittest` + `unittest.mock`, geen pytest; alleen `fpdf2`
  als extra):
  ```sh
  docker run --rm -v "$PWD":/app -w /app/worker-service python:3.12-slim \
    sh -c "pip install -q -r requirements.txt && python -m unittest discover -s tests -v"
  ```
- Python-tests werken in een tijdelijke map (`tempfile.TemporaryDirectory`) met een eigen
  database, `htdocs/` en `storage/`, nooit op de echte data. `php bin/migrate.php` wordt in
  unit-tests gemockt (`PHP_BIN`).
- **Handmatig** (UI, end-to-end): `docker compose -f docker/docker-compose.yml up -d --build`,
  daarna http://localhost:8088.

Uitgangspunten:

- PHP-logica komt in **`conf/worker.php`** (zoals `conf/game.php`), zodat ze testbaar is. De
  pagina's roepen alleen die functies aan.
- Python-logica staat in losse modules met pure functies waar dat kan (retentie, planner,
  formaatherkenning). De I/O zit in de taken.
- Nieuwe en gewijzigde pagina's gebruiken `csrfField()`/`verifyCsrf()`, `logAudit()` en de
  componenten uit `conf/theme.php`.

---

## Fase 0: Voorbereiding

- [x] **T0.1 Map en testopzet**
  - Bestanden: `worker-service/worker/__init__.py`, `worker-service/tests/__init__.py`,
    `tests/test_smoke.py`, `requirements.txt` (`fpdf2` op een vaste versie), `.gitignore`
    (`config.py`, `__pycache__/`).
  - Klaar als: het Python-testcommando hierboven draait en "OK" geeft.

- [x] **T0.2 Testschema voor Python**
  - Bestanden: `tests/schema.py` met `fresh_db(path)`. Die maakt alle tabellen die de worker
    raakt, gelijk aan `htdocs/setup.php` + `bin/migrate.php` (zoals `freshDb()` in
    `tests/game_test.php`), plus helpers `add_game()`, `add_team()`, `add_assignment()`.
  - Klaar als: een test maakt een database en vindt de tabellen `games`, `teams`,
    `assignments`, `completed_assignments`, `team_messages`, `ai_evaluations` en `audit_logs`.
  - Let op: bij elke schemawijziging in deze taken ook `tests/schema.py` bijwerken.

- [x] **T0.3 Echte oude archieven als fixture**
  - Doen: met de **oude scripts zelf** archieven maken van een kleine database met twee
    opdrachten, waarvan één met een kleine `.txt`-bijlage:
    - v1: `git show c249ede:bin/export_assignments.py > /tmp/export_v1.py` (database zonder
      `game_id`);
    - v2: het huidige `bin/export_assignments.py --game 1` (met een rubric bij één opdracht).
  - Bestanden: `tests/fixtures/legacy_v1.tar.gz`, `tests/fixtures/legacy_v2.tar.gz`,
    `tests/fixtures/README.md` (hoe ze gemaakt zijn).
  - Klaar als: `tar tzf` toont bij beide `manifest.json` en `files/…`, en het manifest is een
    lijst (v1 zonder `ai_*`, v2 met).

- [x] **T0.4 Configuratie**
  - Bestanden: `config.py.sample`, `worker/config.py` (`load()` leest `config.py` naast
    `main.py`, met de standaardwaarden uit DESIGN §10.2; relatieve paden ten opzichte van
    `worker-service/`).
  - Klaar als (tests): zonder `config.py` gelden de standaardwaarden; een waarde in
    `config.py` overschrijft; `BACKUP_TIME = ''` betekent "geen planner".

---

## Fase 1: Fundament (wachtrij, opslag, archieven)

- [x] **T1.1 Schema**
  - Bestanden: `htdocs/setup.php`, `bin/migrate.php`, `tests/schema.py`.
  - Doen: tabellen `worker_jobs` en `worker_status` plus indexen (DESIGN §5.1); kolommen
    `completed_assignments.leveled_by`, `leveled_by_name`, `ai_evaluation_id`,
    `accepted_message_id`, `accepted_answer` en `level_started_at` (DESIGN §9.2). Elke `ALTER`
    in een eigen try/catch, zonder `DEFAULT CURRENT_TIMESTAMP`.
  - Klaar als: (a) een verse install (`setup.php` + `migrate.php`) heeft alles
    (`sqlite3 database/leaderboard.db .schema`); (b) `php bin/migrate.php` twee keer achter
    elkaar geeft geen fout; (c) bestaande archiefregels houden hun gegevens (nieuwe kolommen
    NULL).

- [x] **T1.2 `levelUp()` vult het archief** (na T1.1)
  - Bestanden: `conf/game.php`, `tests/game_test.php`.
  - Doen: bij het archiveren ook `leveled_by` (= `$userId`), `leveled_by_name` (naam van de
    docent of NULL), `accepted_message_id` + `accepted_answer` (laatste `team`-bericht van dat
    level), `level_started_at` (de oude `teams.level_updated_at`) en, als `$userId` NULL is,
    `ai_evaluation_id` (nieuwste `ai_evaluations` met `decision = 'level_up'` voor dat team en
    level).
  - Klaar als (tests): een level-up door een docent vult naam en id; een level-up door de AI
    vult `ai_evaluation_id` en laat `leveled_by` NULL; een level zonder teambericht geeft NULL
    als antwoord; na `deleteTeam()` staat `accepted_answer` nog in het archief.

- [x] **T1.3 `conf/worker.php`** (na T1.1)
  - Bestanden: `conf/worker.php`, `tests/worker_test.php`, `conf/config.php.example`
    (`STORAGE_DIR`, standaard `__DIR__ . '/../storage'`).
  - Doen: `enqueueJob($db, $type, $gameId, $params, $userId)` (met `created_at` expliciet en
    een auditregel), `findJobForUser($db, $jobId, $user)`, `jobsForUser($db, $user, $gameId)`,
    `jobDownloadAllowed($db, $job, $user)`, `cancelJob()` (alleen `queued`), `workerActive($db)`.
  - Klaar als (tests): een onbekend type wordt geweigerd; een docent zonder toegang tot het
    spel ziet de taak niet en mag niet downloaden; `backup`/`restore`/`game_import` zijn
    alleen voor admins; annuleren van een `running`-taak lukt niet; `workerActive()` is waar
    bij een heartbeat van 119 s geleden en onwaar bij 121 s; `params_json` bevat nooit een
    sleutel `password`.

- [x] **T1.4 Opslagmap**
  - Bestanden: `bin/setup.sh`, root-`.gitignore`, `docker/entrypoint.sh`.
  - Doen: `storage/{incoming,jobs,backups,tmp}` aanmaken (en in Docker `chown www-data`).
  - Klaar als: na `(cd bin && ./setup.sh)` bestaan de mappen en toont `git status` ze niet.

- [x] **T1.5 Database-laag van de worker** (na T0.2, T1.1)
  - Bestanden: `worker/db.py`, `tests/test_db.py`.
  - Doen: `connect()` (timeout 30 s, `sqlite3.Row`); `claim_next(worker_id)` (atomaire
    `UPDATE … WHERE status='queued'`, DESIGN §5.2); `set_progress()`, `finish()`, `fail()`,
    `heartbeat()`, `fail_orphans(minutes=10)`; tijden altijd via `datetime('now')`.
  - Klaar als (tests): twee verbindingen die tegelijk claimen krijgen nooit dezelfde taak; een
    `restore` in de wachtrij blokkeert het claimen van andere taken; een `restore` wordt niet
    geclaimd zolang er een andere taak `running` is; een `running`-taak zonder heartbeat van
    11 minuten wordt `failed` met "onderbroken", één van 9 minuten niet.

- [x] **T1.6 Takenregister en `Job`-context** (na T1.5)
  - Bestanden: `worker/jobs.py`, `tests/test_jobs.py`.
  - Doen: `register(type)`-decorator; `Job` met `params`, `progress(pct, message)` (maximaal
    eens per seconde naar de database), `heartbeat()`, `warn()`, `set_result(path, name)`;
    `run_job()`: `UserError` → `failed` met die tekst, elke andere fout → `failed` met
    "Interne fout, zie de worker-log" (traceback alleen in de log).
  - Klaar als (tests, met een nep-handler): een geslaagde taak is `done` met
    `result_file`/`result_size`/`expires_at` (+`RESULT_TTL_DAYS`); een `UserError` toont de
    tekst; een `KeyError` toont geen interne details; waarschuwingen komen in `message`.

- [x] **T1.7 Opslag en opruimen** (na T1.6)
  - Bestanden: `worker/storage.py`, `tests/test_storage.py`.
  - Doen: paden (`incoming/`, `jobs/<id>/`, `backups/`, `tmp/`), `safe_filename(text)` (voor
    downloadnamen: spelnaam → `a-z0-9-`), `cleanup_results(db)`: bestanden van taken na
    `expires_at` verwijderen, `result_file` NULL maken; `tmp/` leegmaken bij de start.
  - Klaar als (tests): een verlopen resultaat is weg en de rij blijft bestaan; een niet-verlopen
    resultaat blijft; `safe_filename("Operatie Ü/../x")` bevat geen `/` of `..`.

- [x] **T1.8 Hoofdlus** (na T1.6, T1.7)
  - Bestanden: `main.py`, `tests/test_main.py`.
  - Doen: `run_once()` (heartbeat in `worker_status`, claim, uitvoeren);
    `fail_orphans()` + `tmp/` leegmaken bij de start; opruimen elk uur; SIGINT/SIGTERM zet een
    stopvlag; `unregister` (rij uit `worker_status`) bij het stoppen. Logging naar stdout met
    job-id.
  - Klaar als (tests): `run_once()` voert één taak uit en geeft `False` bij een lege wachtrij;
    `worker_status` heeft een rij na `run_once()`; na de stopvlag start er geen nieuwe taak.

- [x] **T1.9 Veilig archief lezen en schrijven**
  - Bestanden: `worker/archive.py`, `tests/test_archive.py`.
  - Doen: `ArchiveReader(path, allowed, max_bytes, max_files)`: alleen namen uit de allowlist
    (patronen zoals `files/<basename>`), alleen gewone bestanden, `read_bytes(name)`,
    `extract_to(name, target)` met streaming en sha256-controle; nooit `extractall`.
    `ArchiveWriter`: `add_bytes()`, `add_file()`, houdt `size`/`sha256` per bestand bij,
    schrijft naar een tijdelijk bestand en hernoemt pas aan het eind.
  - Klaar als (tests): weigert `../x`, `/etc/passwd`, een symlink, een hardlink, een archief
    groter dan de limiet (ook als het gecomprimeerd klein is), te veel bestanden, en een
    verkeerde checksum; een geldig archief leest correct; een afgebroken schrijfactie laat geen
    half bestand achter.

- [x] **T1.10 Momentopname van de database**
  - Bestanden: `worker/snapshot.py`, `tests/test_snapshot.py`.
  - Doen: `snapshot(db_file, target)` via `sqlite3.Connection.backup()`; contextmanager die de
    kopie in `tmp/` na afloop verwijdert.
  - Klaar als (tests): de kopie heeft dezelfde rijen; een schrijfactie op het origineel na de
    momentopname staat niet in de kopie; een openstaande schrijftransactie op het origineel
    laat de momentopname wachten en daarna slagen (binnen de timeout).

---

## Fase 2: Spel exporteren en importeren

- [x] **T2.1 Intern model**
  - Bestanden: `worker/formats/model.py`.
  - Doen: dataclasses `GameInfo`, `Assignment`, `FileRef`, `Progress` (teams, messages,
    completed, evaluations, progress, announcements, downloads, elk met `ref`'s) en
    `ImportModel` met `warnings`.
  - Klaar als: importeerbaar en gebruikt door T2.2–T2.7 (geen eigen tests).

- [x] **T2.2 Lezer voor v1/v2** (na T0.3, T1.9, T2.1)
  - Bestanden: `worker/formats/legacy.py`, `tests/test_legacy.py`.
  - Doen: de vertaaltabel uit DESIGN §6.3: ontbrekende velden krijgen de standaardwaarden;
    `ai_rubric` als tekst of object; ongeldige `ai_rubric_status` → `none`/`draft` zoals het
    huidige script; een bijlage die niet in `files/` zit → waarschuwing.
  - Klaar als (tests, met de fixtures uit T0.3): v1 geeft 2 opdrachten met
    `ai_rubric_status = 'none'`, `ai_auto_allowed = 1` en de bijlage; v2 geeft de rubric als
    object en de status uit het archief; een manifest met een verwijzing naar een ontbrekend
    bestand geeft één waarschuwing en geen fout.

- [x] **T2.3 Formaatherkenning en v3-lezer** (na T2.2)
  - Bestanden: `worker/formats/game_v3.py`, `worker/formats/__init__.py` (`read_archive()`),
    `tests/test_formats.py`.
  - Doen: lijst → v1 of v2 (op basis van `ai_*`); object met `format = 'zebrawave-game'` en
    `format_version = 3` → v3; een hogere versie → `UserError` "gemaakt met een nieuwere
    versie"; iets anders (ook een backup-archief) → `UserError` "onbekend formaat".
  - Klaar als (tests): de drie formaten geven hetzelfde soort model; versie 4 en een
    `zebrawave-backup` worden met een duidelijke melding geweigerd.

- [x] **T2.4 Export als sjabloon** (na T1.6, T1.9, T1.10, T2.1)
  - Bestanden: `worker/tasks/export_game.py`, `tests/test_export_game.py`.
  - Doen: handler `game_export` (`include_progress = false`): leest uit een momentopname en
    schrijft manifest v3 (DESIGN §6.1) + `files/` + `logo/`. Downloadnaam
    `zebrawave-spel-<naam>-<datum>.tar.gz`. Voortgang per bijlage.
  - Klaar als (tests): het manifest bevat spelinstellingen en alle opdrachten in volgorde met
    de rubric als object; geen `join_code`, `status`, `ends_at`, `assignment_ai_dialog` of
    `ai_processing_*`; elk bestand heeft `size`/`sha256` die kloppen; een ontbrekende bijlage
    geeft een waarschuwing en geen fout; opdrachten van een ander spel staan er niet in.

- [x] **T2.5 Export met voortgang** (na T2.4)
  - Bestanden: `worker/tasks/export_game.py`, `tests/test_export_game.py`.
  - Doen: `progress.json` volgens DESIGN §6.2 met refs in plaats van id's; archiefregels van
    verwijderde teams gaan mee.
  - Klaar als (tests): geen enkel e-mailadres, `access_token`, `teacher_id`/`resolved_by` of
    `download_tokens` in het hele archief (zoek op de testwaarden); berichten met sender
    `suggestion` gaan mee (docentcontext, gekoppeld aan hun beoordeling); elke
    `team_ref`/`message_ref` verwijst naar een bestaande ref; een archiefregel van een
    verwijderd team staat erin met teamnaam.

- [x] **T2.6 Import als nieuw spel (sjabloon)** (na T2.3)
  - Bestanden: `worker/tasks/import_game.py`, `tests/test_import_game.py`.
  - Doen: handler `game_import` (DESIGN §6.3): nieuw spel `draft`, `registration_open = 0`,
    nieuwe `join_code` (zelfde alfabet en lengte als `generateJoinCode()`, uniek); bestaande
    naam → " (2)", " (3)"; koppeling in `game_teachers` aan `created_by`; bijlagen naar
    `artifacts/game_<id>_assignment_<n>_<tijd>_<random>.<ext>` en logo naar `uploads/`,
    extensies alleen `pdf zip txt jpg png` (logo: zoals `games.php`); hernummeren naar 1..N met
    waarschuwing; alles in één transactie; bij een fout de geschreven bestanden weer
    verwijderen; `result_game_id` vullen; auditregel `GAME_IMPORT` met `user_id` NULL.
    Het geüploade bestand in `incoming/` na afloop verwijderen.
  - Klaar als (tests): het v1-fixture wordt een nieuw spel met de opgegeven naam en 2
    opdrachten met bijlage; een tweede import met dezelfde naam heet "… (2)"; nummers 1, 3, 3
    worden 1, 2, 3 met een waarschuwing; een bijlage `.php` wordt geweigerd; een fout halverwege
    (gemockt) laat geen spel en geen bestanden achter; bestaande spellen zijn onveranderd.

- [x] **T2.7 Import met voortgang** (na T2.5, T2.6)
  - Bestanden: `worker/tasks/import_game.py`, `tests/test_import_game.py`.
  - Doen: id's opnieuw uitdelen en `ai_evaluation_id`, `message_id`, `last_message_id` en
    `accepted_message_id` via de refs omzetten; nieuwe `access_token` per team (zelfde formaat
    als `createTeam()`); `email` NULL; status `paused` (of `finished` als het bronspel klaar
    was); hernummeren nodig → `UserError`.
  - Klaar als (tests): na import wijst elk `team_messages.ai_evaluation_id` naar een
    beoordeling van hetzelfde nieuwe team; geen enkel token komt overeen met het bronspel;
    import in dezelfde installatie als de bron lukt (geen unieke-sleutelfout).

- [x] **T2.8 Round-trip-tests** (na T2.7)
  - Bestanden: `tests/test_roundtrip.py`.
  - Klaar als (tests): export → import → export geeft (op id's, bestandsnamen, tokens,
    tijdstempels van de export en `join_code` na) hetzelfde manifest en dezelfde `progress.json`,
    zowel zonder als met voortgang; de bijlagen zijn byte-voor-byte gelijk.

- [x] **T2.9 CLI** (na T2.4, T2.6)
  - Bestanden: `worker/cli.py`, `worker/__main__.py`, `tests/test_cli.py`.
  - Doen: `export-game`, `import-game`, `inspect` (DESIGN §10.3). Elk commando maakt een
    `worker_jobs`-rij met `created_by` NULL en voert de handler direct uit (geen wachtrij).
  - Klaar als (tests): `inspect` meldt "v1", "v2" of "v3" met het aantal opdrachten en
    bestanden en verandert niets aan de database; `export-game --game 99` geeft exitcode 1
    met "spel 99 bestaat niet"; `-o` schrijft naar het opgegeven pad.

- [x] **T2.10 Pagina `jobs.php`** (na T1.3)
  - Bestanden: `htdocs/jobs.php`, `htdocs/admin_header.php` (menu-item "Taken").
  - Doen: tabel met de taken van het actieve spel (admin: ook taken zonder spel), status als
    `.badge` met tekst, voortgang, melding, knoppen Download / Annuleren / "Naar het spel"
    (bij import); polling `jobs.php?ajax=jobs` elke 3 s zolang er iets `queued`/`running` is;
    waarschuwing als er taken wachten en `workerActive()` onwaar is.
  - Klaar als (handmatig): een taak verspringt zonder herladen van "in wachtrij" naar "klaar";
    met de worker uit staat de waarschuwing er; een docent van een ander spel ziet de taak niet.

- [x] **T2.11 `job_download.php`** (na T1.3)
  - Bestanden: `htdocs/job_download.php`.
  - Doen: `requireTeacher()`, `jobDownloadAllowed()`, `Content-Disposition` met `result_name`,
    `readfile()`, auditregel `JOB_DOWNLOAD`; een verlopen resultaat → melding "verlopen".
  - Klaar als (handmatig): de eigen export downloadt; een ander id of een docent zonder
    toegang krijgt 403 (niet 404 met details); de audit-log toont de download.

- [x] **T2.12 Knop "Exporteren"** (na T2.10)
  - Bestanden: `htdocs/games.php`, `htdocs/assignments.php`.
  - Doen: dialoog met vinkje "met teams en voortgang" (uitleg: zonder e-mailadressen),
    `csrfField()`, `enqueueJob('game_export', …)`, redirect naar `jobs.php`.
  - Klaar als (handmatig): export via de website geeft een archief dat `inspect` als v3 herkent.

- [x] **T2.13 Spel importeren** (na T2.10)
  - Bestanden: `htdocs/games.php` (admin), `docker/Dockerfile` (`upload_max_filesize` en
    `post_max_size` op `MAX_ARCHIVE_MB`).
  - Doen: upload + optionele naam; grootte- en extensiecheck (`.tar.gz`/`.tgz`);
    `move_uploaded_file()` naar `storage/incoming/<random>.tar.gz`; oorspronkelijke naam alleen
    in `params_json`; `enqueueJob('game_import', …)`.
  - Klaar als (handmatig): `legacy_v1.tar.gz` uploaden geeft een nieuw spel dat via "Naar het
    spel" te openen is; een docent ziet de knop niet en een POST als docent wordt geweigerd.

- [x] **T2.14 Docker-service `worker`** (na T1.8)
  - Bestanden: `docker/Dockerfile.worker` (+ `.dockerignore`), `docker/worker-config.py`
    (`WORKER_*` uit de environment), `docker/docker-compose.yml`, `docker/README.md`.
  - Doen: image op basis van `php:8.3-cli` met `python3`, een venv met `requirements.txt` en
    `fonts-dejavu-core`; volumes `database`, `artifacts`, `uploads`, `storage` (lezen en
    schrijven); `user: www-data`; `TZ`; `stop_signal: SIGINT`. De webcontainer krijgt ook
    `storage`.
  - Klaar als (handmatig): `docker compose … up -d --build` start vier containers; een export
    via de website wordt uitgevoerd; `ls -l` in het `storage`-volume toont `www-data` als eigenaar.

- [x] **T2.15 Oude scripts weg** (na T2.9, T2.14)
  - Bestanden: `bin/export_assignments.py`, `bin/import_assignments.py` (verwijderen),
    `CLAUDE.md`, `IDEAS.md` (punt "exporteren en importeren via de website" afvinken).
  - Klaar als: `grep -rn "export_assignments\|import_assignments"` vindt alleen nog
    `tests/fixtures/README.md` en DESIGN.md.

---

## Fase 3: Backup

- [x] **T3.1 Backup maken** (na T1.9, T1.10)
  - Bestanden: `worker/tasks/backup.py`, `tests/test_backup.py`.
  - Doen: handler `backup` (DESIGN §7.1): eerst de database als momentopname, dan
    `htdocs/artifacts/` en `htdocs/uploads/`; manifest met `trigger`, `schema` (tabellen en
    kolommen), `counts` en per bestand `size`/`sha256`; schrijven naar `backups/` via een
    tijdelijke naam; daarnaast `<naam>.json` met de metadata (`trigger`, `created_at`,
    `size`, `counts`, `pinned: false`, `created_by`). Ontbrekende bestanden → waarschuwing.
  - Klaar als (tests): het archief bevat de database (`PRAGMA integrity_check` = ok) en alle
    bestanden; geen `config.php` of `storage/`; `counts` kloppen; een schrijfactie tijdens de
    backup breekt hem niet.

- [x] **T3.2 Vrije schijfruimte** (na T3.1)
  - Doen: vóór de backup `shutil.disk_usage()`; onder `BACKUP_MIN_FREE_MB` → `UserError`.
  - Klaar als (test, met gemockte `disk_usage`): de taak wordt `failed` met de melding en er
    staat geen half bestand in `backups/`.

- [x] **T3.3 Bewaartermijnen** (pure functie)
  - Bestanden: `worker/retention.py`, `tests/test_retention.py`.
  - Doen: `to_delete(backups, now, cfg)` volgens DESIGN §7.3: laatste `BACKUP_KEEP`
    automatische; handmatige en geüploade na `BACKUP_MANUAL_DAYS`; `pre_restore` na
    `BACKUP_PRE_RESTORE_DAYS`; `0` = nooit; de nieuwste backup en `pinned` nooit.
  - Klaar als (tests, tabelgestuurd): 16 automatische → de 2 oudste weg; een handmatige van
    91 dagen weg en een van 89 dagen niet; een vastgezette van een jaar blijft; als alle
    backups oud zijn blijft de nieuwste staan; met termijn 0 blijft alles staan.

- [x] **T3.4 Retentie uitvoeren** (na T3.1, T3.3)
  - Doen: na elke backup en elk uur `to_delete()` toepassen (archief + `.json` weg, log
    `BACKUP_DELETE` met `user_id` NULL).
  - Klaar als (test): na een backup met `BACKUP_KEEP = 2` staan er nog 2 automatische.

- [x] **T3.5 Planner** (pure functie + koppeling in `main.py`)
  - Bestanden: `worker/scheduler.py`, `tests/test_scheduler.py`, `main.py`.
  - Doen: `due(now, backup_time, last_scheduled_at)`: waar als het vandaag na `BACKUP_TIME`
    is en er vandaag nog geen geplande backup is (ook na een herstart: inhalen); de planner
    zet een `backup`-taak met `trigger = 'scheduled'` en `created_by` NULL in de wachtrij,
    niet als er al een `backup` `queued`/`running` is.
  - Klaar als (tests): 02:59 → nee; 03:00 → ja; 03:00 met een backup van 03:00 vandaag → nee;
    14:00 zonder backup vandaag (herstart) → ja; `BACKUP_TIME = ''` → nooit.

- [x] **T3.6 Pagina `backups.php`** (na T3.1)
  - Bestanden: `htdocs/backups.php`, `conf/worker.php` (`listBackups()` leest de `.json`'s),
    `tests/worker_test.php`, `htdocs/admin_header.php` (onder "Beheer").
  - Doen: `requireAdmin()`; tabel (datum, soort, grootte, tellingen, vastgezet); knoppen
    "Backup nu maken" (`enqueueJob('backup', …)`), Download (via `job_download.php` of een
    eigen admin-route, audit), Verwijderen (audit `BACKUP_DELETE`, bevestiging), Vastzetten /
    Losmaken (zet `pinned` in de `.json`); totale grootte en een waarschuwing bij weinig
    schijfruimte.
  - Klaar als (tests): `listBackups()` sorteert op datum en negeert een `.json` zonder archief en
    bestandsnamen met `/`. Handmatig: de knoppen werken en een docent krijgt 403.

- [x] **T3.7 Backup uploaden** (na T3.6)
  - Doen: upload naar `incoming/` → taak `backup_upload`: controleert het archief (veilig
    openen, manifest `zebrawave-backup`, checksums, `integrity_check`) en zet het in
    `backups/` met `trigger = 'uploaded'` (zelfde termijn als handmatig).
  - Klaar als (tests): een geldige backup komt in de lijst; een spel-export of een kapotte
    backup wordt geweigerd en laat niets achter in `backups/`.

---

## Fase 4: Restore

- [x] **T4.1 Onderhoudsmodus in de website**
  - Bestanden: `conf/worker.php` (`isMaintenance()`, `maintenanceInfo()`), `conf/database.php`
    (`getDB()`), `htdocs/api.php`, `tests/worker_test.php`.
  - Doen: bestaat `STORAGE_DIR/maintenance.json`, dan geeft `getDB()` een 503: gewone pagina's
    een eenvoudige onderhoudspagina (Ops-thema, ververst elke 5 s, toont `step` uit het
    bestand, zonder database), `?ajax…` een lege 503, `api.php` JSON
    `{"error":"maintenance"}` met 503.
  - Klaar als (tests): `isMaintenance()` volgt het bestand; een kapot JSON-bestand telt ook als
    onderhoud. Handmatig: met het bestand aanwezig tonen `teacher.php`, `team.php` en
    `index.php` de onderhoudspagina en blijven de polling-pagina's werken zodra het weg is.

- [x] **T4.2 AI-agent wacht bij onderhoud**
  - Bestanden: `agentic-service/agentic/api.py`, `agentic-service/main.py`, hun tests.
  - Doen: een 503 is geen fout maar "even wachten" (geen escalatie, geen claim, volgende ronde
    opnieuw).
  - Klaar als (test): een 503 op `get_pending` geeft geen exception naar boven en geen
    `submit_evaluation`.

- [x] **T4.3 Restore: controleren zonder iets te veranderen** (na T1.9)
  - Bestanden: `worker/tasks/restore.py`, `tests/test_restore.py`.
  - Doen: stap 1 uit DESIGN §8: uitpakken naar `tmp/`, checksums, `PRAGMA integrity_check`,
    schema vergelijken met de huidige database (tabellen of kolommen in de backup die de
    huidige app niet kent → `UserError` "backup van een nieuwere versie").
  - Klaar als (tests): een kapotte database, een verkeerde checksum en een backup met een extra
    kolom worden geweigerd; in alle gevallen zijn de database en de bestanden ongewijzigd en is
    er geen `maintenance.json`.

- [x] **T4.4 Restore: vervangen** (na T3.1, T4.3)
  - Doen: stappen 2–7 uit DESIGN §8: `maintenance.json` met `step`; wachten
    (`RESTORE_GRACE_SECONDS`, in tests 0); veiligheidsbackup (`pre_restore`); database naar een
    tijdelijk bestand in `database/`, `-wal`/`-shm` weg, `os.replace()`; `artifacts/` en
    `uploads/` **leegmaken en vullen** (niet de map hernoemen); `PHP_BIN bin/migrate.php`;
    nazorg in de teruggezette database (open taken → `failed` "onderbroken door restore",
    restore-taak opnieuw invoegen met verwijzing naar de veiligheidsbackup, audit
    `BACKUP_RESTORE` met de gebruikersnaam); onderhoud uit.
  - Klaar als (tests, migrate gemockt): na de restore is de inhoud van de backup terug
    (gewijzigde rij terug, nieuw team weg, verwijderde bijlage terug, nieuwe bijlage weg); er
    staat een `pre_restore`-backup; de restore-taak staat als `done` in de teruggezette
    database; `maintenance.json` is weg; de mappen `artifacts/` en `uploads/` zijn hetzelfde
    inode (niet hernoemd).

- [x] **T4.5 Restore: terugrollen bij een fout** (na T4.4)
  - Klaar als (tests): een fout in `migrate.php` (exitcode 1, gemockt) zet de veiligheidsbackup
    terug, de taak wordt `failed` met uitleg en de onderhoudsmodus gaat uit; mislukt ook het
    terugzetten (gemockt), dan blijft `maintenance.json` staan met de oorzaak.

- [x] **T4.6 Restore in `backups.php`** (na T3.6, T4.4)
  - Doen: knop "Terugzetten…" opent een `<dialog class="modal">` met datum, tellingen,
    app-versie en de waarschuwing (alles wordt vervangen, je kunt uitgelogd raken, de AI-agent
    moet mogelijk herstarten); bevestigen met het eigen wachtwoord (`password_verify`, een fout
    wachtwoord telt mee in `recordAttempt()`); `csrfField()`; `enqueueJob('restore', …)`.
  - Klaar als (handmatig): verkeerd wachtwoord → foutmelding en geen taak; goed wachtwoord →
    onderhoudspagina met stappen, daarna weer de site met de teruggezette gegevens.

- [x] **T4.7 CLI `backup` en `restore`** (na T3.1, T4.4)
  - Doen: `python -m worker backup [-o …]` en `restore <archief> --yes` (zonder `--yes`: stoppen
    met uitleg).
  - Klaar als (tests): zonder `--yes` verandert er niets; met `--yes` werkt de restore ook als er
    geen andere worker draait.

- [x] **T4.8 End-to-end in Docker** (na T2.14, T4.6)
  - Klaar als (handmatig, stappen in `docker/README.md`): backup maken → team toevoegen en
    bijlage vervangen → restore → het team is weg en de oude bijlage terug; `migrate.php` heeft
    gedraaid (log); de AI-agent gaat daarna door (of na een herstart, zie DESIGN §14.2).

---

## Fase 5: Rapport (PDF)

- [x] **T5.1 Gegevens: goedgekeurde antwoorden** (na T1.2)
  - Bestanden: `worker/report/data.py`, `tests/test_report_data.py`.
  - Doen: per opdracht de archiefregels met team, tijd, antwoord, "door" en pogingen. Bij
    regels zonder de nieuwe kolommen afleiden (DESIGN §9.2): laatste `Team: `-regel uit
    `chat_history`, en "door" via `audit_logs` (`TEAM_LEVEL_UP`/`AI_LEVEL_UP` binnen 5 s), dan
    gemarkeerd als `derived`. Pogingen: `ai_evaluations.intent = 'answer'` als die er zijn,
    anders het aantal teamberichten in dat level.
  - Klaar als (tests): een nieuwe regel gebruikt de kolommen; een oude regel krijgt het
    afgeleide antwoord met `derived = True`; zonder auditregel wordt "door" `onbekend`; een
    verwijderd team staat erin met zijn naam; een level-down + opnieuw level-up toont alleen de
    laatste goedkeuring.

- [x] **T5.2 Gegevens: verloop per team** (na T5.1)
  - Doen: per team (ook verwijderde) eindlevel en rang (`rankedTeams()`-volgorde), per opdracht
    start, goedkeuring, duur, pogingen, vragen, door wie; level-downs uit `audit_logs`
    (`TEAM_LEVEL_DOWN`, "(ID n)" in de omschrijving).
  - Klaar als (tests): de duur is goedkeuring min `level_started_at`; vragen tellen alleen
    `intent = 'question'`; een level-down staat in het verloop.

- [x] **T5.3 PDF-opmaak** (na T5.2)
  - Bestanden: `worker/report/pdf.py`, `tests/test_report_pdf.py`.
  - Doen: fpdf2 met DejaVu Sans (`REPORT_FONT`): voorblad (naam, logo, periode, aantal teams,
    datum), deel A en deel B (DESIGN §9.1), paginanummers, tabellen die over pagina's
    doorlopen, Markdown als platte tekst, *afgeleid* zichtbaar gemarkeerd.
  - Klaar als (tests, compressie uit): het bestand begint met `%PDF`; een teamnaam met `Ü` en een
    emoji breekt de opmaak niet; een antwoord van 2000 tekens loopt door over een pagina; alleen
    deel A gevraagd → geen deel B.

- [x] **T5.4 Taak `report` + CLI** (na T5.3)
  - Bestanden: `worker/tasks/report.py`, `worker/cli.py`.
  - Doen: uit een momentopname, `sections` uit de parameters, downloadnaam
    `zebrawave-rapport-<naam>-<datum>.pdf`; `python -m worker report --game N [-o …]`.
  - Klaar als (tests): de taak is `done` met een PDF als resultaat; een onbekend spel →
    `UserError`.

- [x] **T5.5 Knop "Rapport (PDF)"** (na T2.10, T5.4)
  - Bestanden: `htdocs/archive.php`, `htdocs/games.php`.
  - Doen: dialoog met vinkjes "Goedgekeurde antwoorden" en "Verloop per team",
    `enqueueJob('report', …)`, audit `REPORT_CREATE`, redirect naar `jobs.php`.
  - Klaar als (handmatig): het rapport van een gespeeld testspel toont per opdracht alle
    goedgekeurde antwoorden en per team het verloop.

---

## Fase 6: Afronding

- [x] **T6.1 README van de worker**
  - Bestanden: `worker-service/README.md`.
  - Klaar als: installatie zonder Docker (venv, `config.py`, `python3 main.py`), de
    configuratie en alle CLI-commando's staan erin en werken zoals beschreven.

- [x] **T6.2 `CLAUDE.md` bijwerken**
  - Doen: worker-service in Stack/Layout/Architecture (wachtrij, `storage/`, onderhoudsmodus,
    `conf/worker.php`), het testcommando, de nieuwe tabellen en archiefkolommen onder "Schema
    changes", de Docker-service, en dat de `bin/`-scripts weg zijn.
  - Klaar als: een nieuwe ontwikkelaar kan met alleen `CLAUDE.md` de worker starten en testen.

- [x] **T6.3 DESIGN.md bijwerken**
  - Doen: status naar "geïmplementeerd" met datum, een sectie "Afwijkingen bij de
    implementatie" (bijv. `backup_upload`, `trigger = 'uploaded'`), open vragen bijwerken.
  - Klaar als: DESIGN.md beschrijft wat er gebouwd is.
