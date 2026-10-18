# Ontwerp: worker-service voor Zebrawave

Status: **geïmplementeerd** (2026-10-18; zie [Beslissingen](#13-beslissingen) en
[Afwijkingen bij de implementatie](#16-afwijkingen-bij-de-implementatie)). Uitwerking in taken:
[TASKS.md](TASKS.md); gebruik: [README.md](README.md). Open punten staan in [§14](#14-open-vragen).
Vervangt: `bin/export_assignments.py` en `bin/import_assignments.py` (verwijderd).

## 1. Doel

Een Python-worker die **in de achtergrond taken** uitvoert die te lang duren voor een
webrequest, met bestanden werken of de hele installatie raken:

1. **Spel exporteren**: een spel als één archief downloaden. Standaard als *sjabloon*
   (spelinstellingen, logo, opdrachten met bijlagen en rubrics), optioneel *met teams en
   voortgang* (teams, berichten, archief, AI-beoordelingen).
2. **Spel importeren**: een archief inlezen als **nieuw spel**. Dit kan ook een archief zijn
   van de **eerste versie** van `bin/export_assignments.py` (zonder spel, zonder AI-velden), of
   van de huidige versie (met rubric).
3. **Backup**: de **hele omgeving** (database + `htdocs/artifacts/` + `htdocs/uploads/`) in één
   archief. Handmatig en automatisch (dagelijks, met bewaartermijn).
4. **Restore**: een backup terugzetten via de website, met onderhoudsmodus en een
   veiligheidsbackup vooraf.
5. **Rapport (PDF)** per spel: welke antwoorden zijn goedgekeurd (per opdracht, naast het
   modelantwoord) en het verloop per team. Zo kun je achteraf nalopen of terecht is
   goedgekeurd.
6. **Uitbreidbaar**: een nieuw soort taak is één handler plus een knop; de wachtrij, status,
   downloads en opruimen zijn gedeeld.

Niet in deze versie: taken over meerdere installaties, versleutelde of externe (off-site)
backups, een AI-hercontrole in het rapport (zie §14).

## 2. Huidige situatie (analyse)

- **Export/import** staan als losse scripts in `bin/`, alleen op de commandoregel:
  - `export_assignments.py --game <id>` schrijft een `tar.gz` met `manifest.json` (een
    **lijst** opdrachten) en `files/<bestandsnaam>`.
  - `import_assignments.py --game <id> <archief>` **wist eerst alle opdrachten van dat spel**
    en vult ze opnieuw. Bijlagen krijgen een nieuwe naam `game_<id>_assignment_<n>_<tijd>.<ext>`.
- Het archiefformaat heeft **geen versienummer**. Er zijn in de praktijk twee varianten:

  | Variant | Herkenbaar aan | Velden per opdracht |
  |---|---|---|
  | **v1** (eerste versie, commit `c249ede`, vóór spellen) | lijst, geen `ai_*` | `assignment_number`, `title`, `description`, `instruction`, `criteria`, `time_limit`, `artifact_file` |
  | **v2** (huidig script) | lijst, met `ai_rubric` | v1 + `ai_rubric` (JSON-tekst), `ai_rubric_status`, `ai_auto_allowed` |

  Spelinstellingen, logo, teams en de dialoog van de opdracht-assistent gaan niet mee.
- **Backups** zijn er niet. In Docker staan de data in drie volumes (`database`, `artifacts`,
  `uploads`); `conf/config.php` wordt bij elke start uit de environment gemaakt.
- **Rapport**: het archief (`completed_assignments`) bewaart per level-up alleen de chat als
  platte tekst (`chat_history`) met teamnaam. **Wie** goedkeurde (docent of AI) en **welk**
  bericht het goedgekeurde antwoord was, staat nergens gestructureerd: het staat alleen in
  `audit_logs` (`TEAM_LEVEL_UP` met `user_id`, de teamnaam in de omschrijving) en, bij de AI,
  in `ai_evaluations` (`decision = 'level_up'`, `message_id`). Na `deleteTeam()` blijft alleen
  de archieftekst over.
- Alleen **admins** maken en beheren spellen (`games.php` → `requireAdmin()`).
- Wat we hergebruiken: het polling-patroon van de website (`?ajax…`), de manier waarop de
  AI-agent een taak claimt (atomair, met heartbeat), `copyAssignments()` als voorbeeld voor
  het hernoemen van bijlagen, en `logAudit()`.

## 3. Overzicht

```
 Website (PHP)                               worker-service (Python)
 ─────────────                               ───────────────────────
 docent/admin klikt "Exporteren",            main.py: lus
 "Backup maken", uploadt een archief …         1. heartbeat → worker_status
        │                                      2. planner: is het tijd voor de
        ▼                                         nachtelijke backup? → job
 INSERT worker_jobs (status 'queued') ───────► 3. claim oudste 'queued' job (atomair)
 + audit_logs                                  4. handler voert uit, meldt voortgang
        ▲                                         → worker_jobs.progress/message
        │ polling ?ajax=jobs                   5. resultaat → storage/jobs/<id>/…
        │                                         status 'done' | 'failed'
 jobs.php: status, voortgang, download ◄────── 6. opruimen: verlopen resultaten,
 job_download.php (rechten-check)                 oude backups
                                                       │
                     direct ◄──────────────────────────┘
        database/leaderboard.db · htdocs/artifacts · htdocs/uploads · storage/
```

Uitgangspunten:

- **De worker werkt direct op de database en de bestanden**, net als de `bin/`-scripts, en
  niet via `api.php`. Backup en restore moeten de hele database en alle bestanden kunnen lezen
  en vervangen; dat kan niet zinnig via HTTP.
- **De website is de enige ingang voor gebruikers.** Een taak is een rij in `worker_jobs`.
  De website controleert de rechten (rol, spel) bij het aanmaken en bij het downloaden; de
  worker voert alleen uit wat in de wachtrij staat. Daarnaast is er een **CLI** voor beheer en
  noodgevallen (§10), die dezelfde handlers gebruikt.
- **Geen websockets, geen nieuwe endpoints voor de status**: `jobs.php?ajax=jobs` pollt, zoals
  de rest van de app.
- **Één archiefmodel voor import**: v1, v2 en het nieuwe v3-formaat worden eerst naar
  hetzelfde interne model vertaald (§6.3); de import zelf kent maar één pad.

## 4. Taken en rechten

| Taak (`type`) | Wie | Parameters | Resultaat |
|---|---|---|---|
| `game_export` | docent van het spel (`canAccessGame()`), admin | `game_id`, `include_progress` | `zebrawave-spel-<naam>-<datum>.tar.gz` |
| `game_import` | **admin** (maakt een spel aan) | geüpload archief, `name` (optioneel) | nieuw spel (`result_game_id`) |
| `report` | docent van het spel, admin | `game_id`, `sections` (`answers`, `teams`) | `zebrawave-rapport-<naam>-<datum>.pdf` |
| `backup` | admin, of de planner (`created_by` NULL) | `trigger`: `manual` \| `scheduled` \| `pre_restore` | `storage/backups/zebrawave-backup-<datum>.tar.gz` |
| `restore` | admin, na bevestiging met eigen wachtwoord | `backup` (bestandsnaam of upload) | omgeving vervangen |

- **Import is admin-only**, omdat een import altijd een nieuw spel maakt en alleen admins
  spellen aanmaken. De importerende admin wordt aan het nieuwe spel gekoppeld
  (`game_teachers`). Docenten kunnen niet importeren (§13).
- Downloaden mag alleen wie de taak mocht starten: exports en rapporten iedereen met toegang
  tot dat spel, backups alleen admins.
- Elke aanvraag en elke download komt in `audit_logs`: `GAME_EXPORT`, `GAME_IMPORT`,
  `REPORT_CREATE`, `BACKUP_CREATE`, `BACKUP_DELETE`, `BACKUP_RESTORE`, `JOB_DOWNLOAD`. De
  worker logt het eind van een taak met `user_id` NULL (`JOB_DONE`/`JOB_FAILED`).

## 5. Wachtrij en levenscyclus

### 5.1 Tabellen

```sql
CREATE TABLE worker_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,                 -- game_export | game_import | report | backup | restore
  game_id INTEGER,                    -- NULL bij backup/restore/import
  params_json TEXT,                   -- zie §4; nooit wachtwoorden of tokens
  status TEXT NOT NULL DEFAULT 'queued',  -- queued | running | done | failed | cancelled
  progress INTEGER DEFAULT 0,         -- 0–100
  message TEXT,                       -- "Bijlagen inpakken (4/12)", foutmelding voor de gebruiker
  result_file TEXT,                   -- pad relatief aan STORAGE_DIR
  result_name TEXT,                   -- bestandsnaam voor de download
  result_size INTEGER,
  result_game_id INTEGER,             -- game_import: het nieuwe spel
  created_by INTEGER,                 -- NULL = planner of CLI
  created_at DATETIME,
  started_at DATETIME,
  heartbeat_at DATETIME,
  finished_at DATETIME,
  expires_at DATETIME,                -- resultaat wordt daarna opgeruimd
  worker_id TEXT
);
CREATE INDEX idx_worker_jobs_status ON worker_jobs(status, id);
CREATE INDEX idx_worker_jobs_game ON worker_jobs(game_id, id);

CREATE TABLE worker_status (
  worker_id TEXT PRIMARY KEY,
  version TEXT,
  last_heartbeat DATETIME,
  current_job_id INTEGER
);
```

Volgens de schemaregels: `CREATE TABLE` in `htdocs/setup.php` **en** een idempotente stap in
`bin/migrate.php`; `created_at` expliciet zetten bij insert.

### 5.2 Statussen

```
queued ──claim──► running ──► done      (resultaat te downloaden tot expires_at)
   │                 └──────► failed    (message = leesbare fout; details in de worker-log)
   └─ annuleren ──► cancelled           (alleen zolang queued, door de aanvrager of een admin)
```

- **Claimen is atomair**:
  `UPDATE worker_jobs SET status='running', worker_id=?, started_at=datetime('now'), heartbeat_at=datetime('now') WHERE id = (SELECT id FROM worker_jobs WHERE status='queued' ORDER BY id LIMIT 1) AND status='queued'`,
  daarna lezen welke job dat werd. Twee workers pakken dus nooit dezelfde taak. We gaan uit
  van **één worker per installatie**; meer mag, maar levert weinig op.
- **Exclusieve taken**: `restore` (en `backup`) draaien alleen als er geen andere taak
  `running` is. Zolang er een `restore` in de wachtrij staat, claimt de worker niets anders.
- Een lopende taak werkt elke ~10 s `heartbeat_at` bij. Een taak die `running` is zonder
  heartbeat van de laatste 10 minuten wordt bij de volgende start van de worker `failed`
  ("onderbroken"). **Er is geen automatische herhaling**: een import of restore twee keer
  uitvoeren is erger dan opnieuw klikken.
- De website toont "Worker actief" als `worker_status.last_heartbeat` minder dan 120 s oud is.
  Staat een taak in de wachtrij zonder actieve worker, dan staat dat er duidelijk bij.

### 5.3 Opslag (`storage/`)

Nieuwe map **buiten de webroot**, naast `database/` (gitignored, aangemaakt door
`bin/setup.sh`; in Docker een eigen volume dat web en worker delen):

```
storage/
  incoming/   uploads voor import/restore (willekeurige naam; weg na afloop van de taak)
  jobs/<id>/  resultaten van export en rapport (weg na expires_at)
  backups/    volledige backups + .json met metadata per backup
  tmp/        werkmap van de worker (leeg na elke taak)
  maintenance.json   alleen aanwezig tijdens een restore (§8)
```

- Pad in PHP: `STORAGE_DIR` in `config.php` (standaard `__DIR__ . '/../storage'`); in de
  worker `STORAGE_DIR` in `config.py`.
- **Opruimen** (door de worker, elk uur): resultaten van export/rapport na
  `RESULT_TTL_DAYS` (standaard **7**); de rij in `worker_jobs` blijft voor de historie
  (zonder bestand: "verlopen"). Automatische backups volgens §7.3.
- Downloads gaan via `job_download.php?id=…` (rechten-check, `Content-Disposition`,
  `readfile`), nooit via een directe URL.

## 6. Spel exporteren en importeren

### 6.1 Archiefformaat v3

Nog steeds een `tar.gz` met `manifest.json` in de root, maar het manifest is nu een
**object** met een formaatnaam en versie. Een lijst betekent v1/v2.

```
manifest.json
files/<bestandsnaam>         bijlagen van opdrachten (zoals v1/v2)
logo/<bestandsnaam>          logo van het spel (optioneel)
progress.json                alleen bij include_progress (§6.2)
```

```json
{
  "format": "zebrawave-game",
  "format_version": 3,
  "exported_at": "2026-10-18T09:12:00Z",
  "app_version": "87ecf58",
  "include_progress": false,
  "game": {
    "name": "Operation Gameplay",
    "ai_mode": "suggest",
    "ai_max_attempts": 5,
    "ai_max_questions": 5,
    "logo_file": "logo.png"
  },
  "assignments": [
    {
      "assignment_number": 1,
      "title": "…", "description": "…", "instruction": "…", "criteria": "…",
      "time_limit": 60,
      "artifact_file": "opdracht1.pdf",
      "ai_rubric": { "…": "object, geen JSON-tekst" },
      "ai_rubric_status": "approved",
      "ai_auto_allowed": 1
    }
  ],
  "files": { "files/opdracht1.pdf": { "size": 123456, "sha256": "…" } }
}
```

- De velden per opdracht hebben **dezelfde namen als in v1/v2**, zodat de vertaling triviaal
  is en een oud script een nieuw manifest nog half kan lezen.
- De export bevat alleen de **spelinformatie** (instellingen en opdrachten), niet het
  proces eromheen. De dialoog van de opdracht-assistent (`assignment_ai_dialog`) gaat dus
  **niet** mee, net als bij `copyAssignments()`. Een goedgekeurde rubric gaat wel mee: die is
  het resultaat van die dialoog.
- Niet in de export: `join_code`, `status`, `started_at`/`ends_at`, `game_teachers`,
  `ai_design_status`/`ai_processing_*` (horen bij de installatie of bij een lopend proces).
- `sha256` per bestand: bij import wordt elk bestand gecontroleerd.

### 6.2 Met teams en voortgang (`include_progress`)

`progress.json` bevat de spelgegevens met **eigen referenties** in plaats van database-id's:

| Onderdeel | Inhoud | Niet mee |
|---|---|---|
| `teams` | `ref`, `team_name`, `current_level`, `level_updated_at`, `ai_mode` | `email`, `access_token`, `ai_processing_*` |
| `messages` | `ref`, `team_ref`, `assignment_number`, `sender`, `message`, `is_read`, `created_at`, `evaluation_ref` | |
| `completed` | archiefregels (ook van verwijderde teams, met `team_name`), incl. de velden uit §9.2 | |
| `evaluations` | `ai_evaluations` met `team_ref`, `message_ref` | `resolved_by`, `agent_id` |
| `progress` | `ai_team_progress` | |
| `announcements` | met `team_ref` of NULL | `created_by` |
| `downloads` | `team_downloads` | `download_tokens` |

- Bij import worden alle id's opnieuw uitgedeeld en de verwijzingen (`ai_evaluation_id`,
  `message_id`, `last_message_id`) via de refs omgezet.
- **Teams krijgen nieuwe `access_token`s.** Oude links werken dus niet in het geïmporteerde
  spel (ook niet als het in dezelfde installatie wordt geïmporteerd, waar het token anders
  dubbel zou zijn). Links zijn opnieuw te mailen via de bestaande 📧-knop.
- Docentnamen gaan niet mee (ze bestaan niet in een andere installatie).
- **E-mailadressen van teams gaan niet mee.** Geïmporteerde teams hebben geen e-mailadres;
  wie een team opnieuw een link wil mailen, vult het adres eerst in.

### 6.3 Import: van elk formaat naar één model

```
archief ──► veilig openen (§11) ──► manifest.json
                                     ├─ lijst, met ai_rubric      → v2-lezer
                                     ├─ lijst, zonder ai_*        → v1-lezer
                                     ├─ object, format_version 3  → v3-lezer
                                     └─ anders                    → fout "onbekend formaat"
            ──► ImportModel(game, assignments, files, progress?)
            ──► validatie ──► één transactie: nieuw spel + opdrachten (+ voortgang)
            ──► bestanden naar htdocs/artifacts/ en htdocs/uploads/
```

Vertaling van oude archieven (v1/v2) naar het model:

| Veld | v1 | v2 | Standaard als het ontbreekt |
|---|---|---|---|
| spelnaam | — | — | `name` uit het formulier, anders "Import <bestandsnaam>" |
| spelinstellingen | — | — | standaard van een nieuw spel (`ai_mode = 'suggest'`, 5/5) |
| `instruction`, `criteria` | ja | ja | `''` |
| `time_limit` | ja | ja | `0` |
| `artifact_file` | ja | ja | geen bijlage; ontbreekt het bestand in `files/` → waarschuwing |
| `ai_rubric` | — | JSON-tekst | NULL |
| `ai_rubric_status` | — | ja | `none`; ongeldig of zonder rubric → `none`/`draft` (zoals nu) |
| `ai_auto_allowed` | — | ja | `1` |

Regels bij de import:

- **Altijd een nieuw spel**, status `draft`, nieuwe `join_code`, `registration_open = 0`. Een
  import met voortgang wordt `paused` (of `finished` als het bronspel klaar was), zodat de
  AI-agent niet meteen gaat antwoorden en een docent bewust start. `ends_at` gaat niet mee.
- Bestaat de spelnaam al, dan komt er " (2)" achter.
- **Nummering**: opdrachten moeten 1..N zonder gaten zijn (levels hangen daaraan). Zijn er
  gaten of dubbele nummers, dan nummert de import in volgorde opnieuw en meldt dat. Bij een
  import met voortgang wordt hernummeren geweigerd (dan kloppen de levels niet meer).
- Bijlagen krijgen een nieuwe naam zoals in `copyAssignments()`:
  `artifacts/game_<id>_assignment_<n>_<tijd>_<random>.<ext>`; het logo
  `uploads/game_<id>_logo_<random>.<ext>`. Alleen extensies die de website ook toestaat.
- Alles in één databasetransactie. Mislukt iets, dan worden de al geschreven bestanden weer
  verwijderd (zoals `copyAssignments()` doet). Er blijft geen half spel achter.
- Het resultaat van de taak is het nieuwe spel; `jobs.php` toont een link "Naar het spel".

### 6.4 Wat er met `bin/` gebeurt

- `export_assignments.py` en `import_assignments.py` verdwijnen. In plaats daarvan de CLI
  van de worker (§10): `export-game` en `import-game`.
- Bewuste gedragswijziging: het oude "wis de opdrachten van spel X en importeer" bestaat
  niet meer. Wie dat wil, importeert als nieuw spel en verwijdert het oude.
- `IDEAS.md` "Assignments via de website exporteren en importeren" is hiermee gedekt.

## 7. Backup

### 7.1 Inhoud

```
zebrawave-backup-20261018-030000.tar.gz
  manifest.json
  database/leaderboard.db
  htdocs/artifacts/…
  htdocs/uploads/…
```

```json
{
  "format": "zebrawave-backup",
  "format_version": 1,
  "created_at": "2026-10-18T01:00:00Z",
  "trigger": "scheduled",
  "app_version": "87ecf58",
  "schema": { "teams": ["id", "game_id", "…"], "…": [] },
  "counts": { "games": 3, "teams": 41, "assignments": 38, "files": 52 },
  "files": { "database/leaderboard.db": { "size": 0, "sha256": "…" } }
}
```

- **De database wordt niet als bestand gekopieerd**, maar met de online backup-API van
  SQLite (`sqlite3.Connection.backup()`): een consistente momentopname, ook als de website
  op dat moment schrijft. Eerst de database, daarna de bestanden.
- Bestanden die tijdens de backup bijkomen of verdwijnen: een nieuw bestand zonder
  databaseregel is onschuldig, een ontbrekend bestand wordt als waarschuwing gemeld.
- `schema` (tabellen en kolommen) gebruiken we bij de restore om te zien of de backup van
  een nieuwere versie van de app komt (§8).
- **Niet in de backup**: `conf/config.php` en `config.py` (SMTP-wachtwoord, staan in de
  environment of op de server), Mailpit, de worker-`storage/` zelf.
- **Een backup bevat geheimen**: wachtwoordhashes, TOTP-secrets, API-keys en team-tokens.
  Daarom alleen in `storage/backups/` (buiten de webroot), alleen te downloaden door admins,
  en elke download in de audit-log. Versleuteling: zie §14.1.

### 7.2 Handmatig

Admin-pagina **`backups.php`** (onder "Beheer" in de zijbalk): lijst met datum, trigger, grootte
en tellingen, knoppen "Backup nu maken", "Download", "Verwijderen", "Terugzetten…" en
"Backup uploaden" (voor een backup van een andere installatie).

### 7.3 Automatisch en bewaartermijn

- De worker heeft een eenvoudige planner: elke dag om `BACKUP_TIME` (standaard `03:00`,
  tijdzone van de worker via `TZ`) zet hij een `backup`-taak met `trigger = 'scheduled'` in de
  wachtrij. Die is dus gewoon zichtbaar in de lijst. Na een herstart wordt een gemiste
  backup van vandaag ingehaald.
- Bewaren, per soort backup (voorstel, zie §13):

  | Soort | Bewaard | Waarom |
  |---|---|---|
  | automatisch (`scheduled`) | de laatste **14** (`BACKUP_KEEP`) | twee weken terug kunnen; oudere backups hebben weinig zin bij dagelijkse |
  | handmatig (`manual`) | **90 dagen** (`BACKUP_MANUAL_DAYS`) | vaak gemaakt vóór iets spannends of aan het eind van een periode; een schooltrimester terug |
  | veiligheid (`pre_restore`) | **30 dagen** (`BACKUP_PRE_RESTORE_DAYS`) | alleen nodig om een restore ongedaan te maken; dat merk je snel |

  Twee vangnetten: **de nieuwste backup wordt nooit verwijderd**, welke soort het ook is. En
  een admin kan een backup **vastzetten** ("bewaren"): die valt buiten elke termijn (bijv. de
  eindstand van een schooljaar). Vastzetten staat in de `.json` naast de backup. Een admin
  kan backups altijd eerder handmatig verwijderen. `0` bij een termijn = nooit automatisch
  verwijderen.
- `backups.php` toont de totale grootte en waarschuwt als de vrije schijfruimte onder
  `BACKUP_MIN_FREE_MB` zakt; de worker slaat dan de automatische backup over (`failed`).
- `BACKUP_TIME` leeg = geen automatische backups.

## 8. Restore

Restore vervangt **alles**: database, bijlagen en logo's. Het is de gevaarlijkste taak, dus:

```
admin kiest backup (lijst of upload) → ziet samenvatting (datum, tellingen, app-versie)
   → bevestigt met eigen wachtwoord → job 'restore' (exclusief)
worker:
 1. Controleren, nog niets aanraken: archief veilig openen (§11), checksums,
    PRAGMA integrity_check op de uitgepakte database in storage/tmp/,
    schema vergelijken: backup van een nieuwere app-versie → weigeren.
 2. Onderhoudsmodus aan: storage/maintenance.json; 5 s wachten tot lopende requests klaar zijn.
 3. Veiligheidsbackup van de huidige staat (trigger 'pre_restore').
 4. Vervangen:
    - database: naar een tijdelijk bestand in database/ schrijven, -wal/-shm verwijderen,
      os.replace() (atomair);
    - artifacts/ en uploads/: inhoud leegmaken en de uitgepakte bestanden erin zetten.
      (Niet de map zelf hernoemen: in Docker is dat een mount point.)
 5. php bin/migrate.php (een oudere backup krijgt zo de nieuwe kolommen).
 6. Nazorg in de teruggezette database:
    - taken die in de backup 'queued'/'running' stonden → 'failed' ("onderbroken door restore");
    - de restore-taak zelf opnieuw invoegen (die stond niet in de backup), met verwijzing naar
      de veiligheidsbackup;
    - audit_logs: BACKUP_RESTORE (user_id NULL, met de gebruikersnaam in de omschrijving,
      want id's kunnen in de backup anders zijn).
 7. Onderhoudsmodus uit.
 Fout in stap 4–6 → automatisch de veiligheidsbackup terugzetten; lukt ook dat niet,
 dan blijft de onderhoudsmodus aan en staat de oorzaak in maintenance.json en de log.
```

**Onderhoudsmodus** is een bestand en geen databasevlag, omdat de database tijdens de restore
wordt vervangen. `getDB()` in `conf/database.php` controleert het bestand (één `is_file()` per
request):

- gewone pagina's: 503 met een eenvoudige "Even geduld, onderhoud"-pagina (Ops-thema);
- `?ajax…`-requests: lege 503 (de polling probeert het vanzelf opnieuw);
- `api.php`: JSON 503; de AI-agent behandelt dat als "even wachten".

Na de restore:

- Iedereen blijft ingelogd zolang `session_version` in de backup klopt; anders volgt de
  gewone "sessie verlopen". Bestaat de admin die de restore deed niet in de backup, dan is die
  uitgelogd. Dat is verwacht en staat in de bevestigingsdialoog.
- Is de `docker-agent`-API-key in de backup anders, dan moet de agent-container herstarten
  (of de key opnieuw lezen na een 401; zie §14.2).

## 9. Rapport (PDF)

### 9.1 Inhoud

Per spel één PDF, met de onderdelen die de docent aanvinkt:

**A. Goedgekeurde antwoorden per opdracht.** Per opdracht een kop met titel, het
modelantwoord (`criteria`) en de rubric (kernaspecten), daarna een tabel:

| Team | Goedgekeurd op | Goedgekeurd antwoord | Door | Pogingen |
|---|---|---|---|---|
| De Speurneuzen | 18-10 10:42 | "De conciërge, via de sleutel in…" | docent J. Jansen | 3 |
| Team Zebra | 18-10 10:57 | "…" | AI (goed) | 1 |

Zo zie je in één oogopslag of alle goedgekeurde antwoorden echt goed zijn. Rijen waarbij het
antwoord niet zeker te bepalen is (oude gegevens, §9.2) zijn gemarkeerd als *afgeleid*.

**B. Verloop per team.** Per team (ook verwijderde teams, uit het archief): eindlevel en
rang, en per opdracht start, goedkeuring, duur, aantal pogingen en vragen, wie goedkeurde,
en eventuele level-downs.

Voorblad: spelnaam, logo, periode, aantal teams, datum van het rapport. Teamnamen en
teksten komen van teams zelf; ze worden als platte tekst in de PDF gezet (Markdown in
opdrachten wordt platte tekst).

### 9.2 Benodigde schemawijziging (eerst doen)

Om "door wie" en "welk antwoord" betrouwbaar te weten, krijgt het archief extra kolommen.
`levelUp()` vult ze:

| Kolom in `completed_assignments` | Inhoud |
|---|---|
| `leveled_by` | `teachers.id`, NULL = AI |
| `leveled_by_name` | naam van de docent op dat moment (blijft na verwijderen van de docent) |
| `ai_evaluation_id` | de AI-beoordeling die tot de level-up leidde (alleen bij AI) |
| `accepted_message_id` | laatste teambericht van dat level vóór de level-up |
| `accepted_answer` | tekst van dat bericht (blijft na `deleteTeam()`) |
| `level_started_at` | `teams.level_updated_at` vóór de level-up |

Deze wijziging moet **in fase 1** al mee, zodat de gegevens vanaf dan worden verzameld. Ze
helpt ook bij het idee in `IDEAS.md` (spoor van een team, ook na verwijderen; per vraag alle
goedgekeurde antwoorden zien).

Voor archiefregels van vóór de migratie leidt het rapport het af: antwoord = laatste regel
`Team: …` uit `chat_history`; "door" = de `TEAM_LEVEL_UP`/`AI_LEVEL_UP`-regel in `audit_logs`
die in tijd het dichtst bij `created_at` ligt (binnen een paar seconden). Lukt dat niet, dan
staat er "onbekend".

**Pogingen en vragen**: als er `ai_evaluations` zijn, telt `intent = 'antwoord'` als poging en
`'vraag'` als vraag; anders telt elk teambericht in dat level als poging.

### 9.3 Techniek

- **fpdf2** (vastgepinde versie, pure Python, geen systeembibliotheken nodig) met het
  lettertype DejaVu Sans (Unicode, voor teamnamen met accenten of andere tekens). In Docker
  via het pakket `fonts-dejavu-core`; zonder Docker via `REPORT_FONT` in `config.py`.
- Het rapport leest uit een **momentopname** van de database (backup-API naar `storage/tmp/`),
  zodat een lange leesactie de website niet blokkeert.

## 10. De worker zelf

### 10.1 Structuur

```
worker-service/
  DESIGN.md · README.md · requirements.txt (fpdf2==<vast>)
  main.py               lus: heartbeat → planner → claim → handler → opruimen
  config.py.sample      (config.py gitignored, zoals bij de agent)
  worker/
    db.py               verbinding (timeout 30 s, foreign keys), claim/update van jobs
    jobs.py             register type → handler; Job-object met progress()/message()
    storage.py          paden, veilige bestandsnamen, opruimen
    archive.py          tar.gz veilig lezen/schrijven, checksums, limieten (§11)
    snapshot.py         SQLite-momentopname via backup-API
    formats/
      model.py          ImportModel (dataclasses)
      legacy.py         v1/v2 → model
      game_v3.py        v3 ↔ model
    tasks/
      export_game.py · import_game.py · backup.py · restore.py · report.py
    report/pdf.py
    cli.py              python -m worker …
  tests/
    fixtures/           een echt v1- en v2-archief, een kleine database
```

- Python 3.10+, alleen de standaardbibliotheek plus `fpdf2`.
- Lopen: `main.py` pollt elke `POLL_INTERVAL` (standaard 5 s). Afsluiten met SIGINT/SIGTERM:
  de lopende taak afmaken (behalve bij een backup: afbreken en opruimen), dan stoppen.
- Logging naar stdout (Docker) met job-id; geen inhoud van berichten, geen tokens.

### 10.2 Configuratie (`config.py`)

| Naam | Standaard | Betekenis |
|---|---|---|
| `DB_FILE` | `../database/leaderboard.db` | zelfde pad als PHP |
| `HTDOCS_DIR` | `../htdocs` | voor `artifacts/` en `uploads/` |
| `STORAGE_DIR` | `../storage` | §5.3 |
| `PHP_BIN` | `php` | voor `bin/migrate.php` na een restore |
| `POLL_INTERVAL` | `5` | seconden |
| `RESULT_TTL_DAYS` | `7` | §5.3 |
| `BACKUP_TIME` | `03:00` | leeg = geen automatische backups |
| `BACKUP_KEEP` | `14` | aantal automatische backups |
| `BACKUP_MANUAL_DAYS` | `90` | bewaartermijn handmatige backups (0 = nooit verwijderen) |
| `BACKUP_PRE_RESTORE_DAYS` | `30` | bewaartermijn veiligheidsbackups (0 = nooit verwijderen) |
| `BACKUP_MIN_FREE_MB` | `500` | §7.3 |
| `MAX_ARCHIVE_MB` | `500` | grootste upload/uitgepakte inhoud (§11) |
| `REPORT_FONT` | DejaVu Sans | pad naar een TTF |

In Docker leest `docker/worker-config.py` dit uit `WORKER_*`-variabelen, zoals bij de agent.

### 10.3 CLI

Voor beheer, migratie en noodgevallen (draait dezelfde handlers, direct, zonder wachtrij,
maar wel met een `worker_jobs`-rij met `created_by` NULL voor de historie):

```sh
python -m worker export-game --game 3 [--with-progress] [-o bestand.tar.gz]
python -m worker import-game archief.tar.gz [--name "Nieuwe naam"]
python -m worker backup [-o bestand.tar.gz]
python -m worker restore backup.tar.gz --yes        # ook als de website niet meer werkt
python -m worker report --game 3 [-o rapport.pdf]
python -m worker inspect archief.tar.gz             # formaat, versie, inhoud; verandert niets
```

## 11. Veiligheid

- **Archieven zijn onbetrouwbare invoer** (uploads, ook van admins, kunnen van elders komen):
  - nooit `extractall`; alleen bekende namen lezen (`manifest.json`, `files/<basename>`,
    `logo/<basename>`, `progress.json`, `database/leaderboard.db`, `htdocs/artifacts|uploads/<basename>`);
  - alleen gewone bestanden, geen links, geen `..` of absolute paden;
  - limiet op totale uitgepakte grootte en aantal bestanden (`MAX_ARCHIVE_MB`), tegen
    zip-bommen;
  - bestandsnamen bij het wegschrijven altijd zelf maken (§6.3), extensie uit een allowlist;
  - checksums controleren als het manifest ze heeft (v3, backup).
- **Rechten**: de website controleert rol en spel bij aanmaken en downloaden; de worker
  vertrouwt alleen wat er in `worker_jobs` staat en leest geen invoer van gebruikers buiten
  de geüploade bestanden in `storage/incoming/`.
- **Restore** vraagt het wachtwoord van de admin opnieuw (en alleen admins). Formulieren
  krijgen `csrfField()`/`verifyCsrf()`.
- Uploads gaan met `move_uploaded_file()` naar `storage/incoming/` met een willekeurige naam;
  de oorspronkelijke naam staat alleen in `params_json` voor weergave.
- Geen geheimen in `params_json`, `message` of logs. Een backup bevat ze wel (§7.1).
- SQLite-gelijktijdigheid: de website gebruikt de standaard-journalmodus. De worker houdt
  schrijftransacties kort (import: één transactie van milliseconden), leest voor export en
  rapport uit een momentopname, en wacht maximaal 30 s op een lock.

## 12. Aanpassingen in de website en Docker

**PHP**

- `conf/worker.php`: `enqueueJob()`, `findJobForUser()`, `workerActive()`,
  `jobDownloadAllowed()`, `isMaintenance()`.
- `conf/database.php`: onderhoudscheck in `getDB()` (§8).
- `conf/game.php`: `levelUp()` vult de nieuwe archiefkolommen (§9.2).
- Nieuwe pagina's:
  - `jobs.php` ("Taken"): de taken van het actieve spel (admins: alle), met voortgang
    (polling `?ajax=jobs`), download, annuleren;
  - `job_download.php`;
  - `backups.php` (admin): §7.2 en §8.
- Knoppen: "Exporteren" (met vinkje "met teams en voortgang") en "Rapport (PDF)" op
  `games.php`/`archive.php`; "Spel importeren" (upload + naam) op `games.php`.
- `setup.php` + `migrate.php`: `worker_jobs`, `worker_status`, de kolommen uit §9.2.
- `config.php.example`: `STORAGE_DIR`. PHP-limieten voor uploads (`upload_max_filesize`,
  `post_max_size`) in de Docker-image ophogen tot `MAX_ARCHIVE_MB`.

**Docker**

- Nieuwe service `worker`: image op basis van `php:8.3-cli` (voor `bin/migrate.php`) met
  `python3`, een venv met `requirements.txt` en `fonts-dejavu-core`.
- Volumes: `database`, `artifacts`, `uploads` (**lezen en schrijven**, anders dan bij de
  agent) en een nieuw volume `storage`, dat ook de webcontainer krijgt.
- Draait als `www-data` (uid 33), zodat de bestanden die de worker schrijft voor Apache
  leesbaar en schrijfbaar blijven.
- `TZ` voor de backuptijd.

## 13. Beslissingen

Afgestemd op 2026-10-18:

1. Taken starten via de **website** (wachtrij), met daarnaast een **CLI**.
2. De worker werkt **direct op database en bestanden**, niet via `api.php`.
3. Spel-export: **keuze** tussen sjabloon (standaard) en met teams en voortgang.
4. Backup = de **volledige omgeving** (database + bestanden); restore vervangt alles.
5. Import maakt **altijd een nieuw spel**; bestaande spellen worden nooit overschreven.
6. Rapport: **goedgekeurde antwoorden per opdracht** en **verloop per team**, als **PDF**.
7. Rechten: docenten exporteren en rapporteren voor hun eigen spellen; **backup/restore
   alleen admins**.
8. **Dagelijkse automatische backup** met bewaartermijn.
9. Restore **via de website** met onderhoudsmodus en een veiligheidsbackup vooraf.
10. Resultaten van export en rapport **7 dagen** bewaren (instelbaar).
11. **Importeren alleen door admins** (een import maakt een spel; docenten kunnen dat niet).
12. De spel-export bevat alleen **spelinformatie**: de dialoog van de opdracht-assistent gaat
    niet mee (de rubric wel).
13. Een export met voortgang bevat **geen e-mailadressen** van teams.

Eigen ontwerpkeuzes (nog te bevestigen):

- Archiefformaat v3 als object met `format`/`format_version`; v1/v2 blijven leesbaar.
- `bin/export_assignments.py` en `bin/import_assignments.py` worden vervangen door de CLI.
- Een import met voortgang krijgt nieuwe team-tokens.
- `completed_assignments` krijgt kolommen voor "wie" en "welk antwoord" (§9.2).
- Bewaartermijnen voor backups: 14 automatische, handmatige 90 dagen, veiligheidsbackups
  30 dagen, de nieuwste nooit, vastgezette nooit (§7.3).
- fpdf2 voor de PDF; de worker-image is gebaseerd op `php:8.3-cli` om `migrate.php` te kunnen
  draaien.

## 14. Open vragen

1. **Versleutelde backups** (met een wachtwoord, vraagt een extra library) of een kopie naar
   een andere plek (rsync/S3)? Nu buiten scope.
2. **AI-agent na een restore**: moet de agent bij een 401 zelf de API-key opnieuw lezen, of is
   een herstart van de container acceptabel? Nu: herstarten (staat in `docker/README.md`).
3. **Rapport later uitbreiden** met een AI-hercontrole van goedgekeurde antwoorden of een
   kwaliteitscontrole van opdrachten (rubric goedgekeurd, bijlage aanwezig)?
4. **Certificaat** (`IDEAS.md`): een PDF per team zou een logische volgende taak voor deze
   worker zijn. Meenemen in het ontwerp?

## 15. Fasering

| Fase | Inhoud | Klaar als |
|---|---|---|
| 1 | Wachtrij (`worker_jobs`, `worker_status`), `storage/`, worker-lus + CLI, export/import (v3 + v1/v2), `jobs.php`, Docker-service, **archiefkolommen §9.2** | een v1-archief uit commit `c249ede` importeert als nieuw spel; export → import geeft een identiek spel |
| 2 | Backup (handmatig + planner + bewaartermijn), `backups.php` | nachtelijke backup verschijnt en oude worden opgeruimd |
| 3 | Restore met onderhoudsmodus en veiligheidsbackup | restore van een oudere backup werkt, en een kapotte backup verandert niets |
| 4 | Rapport (PDF) | rapport toont per opdracht alle goedgekeurde antwoorden, ook van verwijderde teams |

**Tests** (stdlib `unittest`, zoals de agent; in Docker met de worker-image):
- formaten: v1-, v2- en v3-fixture → zelfde model; export → import → vergelijken;
- veiligheid: archief met `../`, symlink, te groot, ontbrekend manifest;
- wachtrij: atomaire claim, verweesde `running`-taken, exclusieve restore;
- backup/restore: momentopname tijdens schrijven, rollback na een fout in stap 4;
- PHP: `tests/game_test.php` uitbreiden voor de nieuwe kolommen in `levelUp()`.

## 16. Afwijkingen bij de implementatie

Ten opzichte van de tekst hierboven (2026-10-18):

1. **Knoppen.** "Exporteren" en "Rapport (PDF)" staan bij **Spelinstellingen** (`game.php`), niet op
   `games.php`/`archive.php`: die pagina's zijn alleen voor beheerders, en docenten mogen exporteren en
   rapporten maken voor hun eigen spellen. `archive.php` heeft een snelknop voor het rapport. Importeren
   staat op `games.php` (beheerders). De takenlijst is **Taken** (`jobs.php`) in de zijbalk.
2. **`backup_upload`** is een eigen taak: een geüploade backup wordt eerst volledig gecontroleerd (zoals
   stap 1 van een restore) en komt dan in de lijst met trigger `uploaded` (bewaartermijn als handmatig).
3. **Downloads van backups** gaan via `backups.php?download=` (audit `BACKUP_DOWNLOAD`); vastzetten en
   losmaken via `BACKUP_PIN`. Restore met een fout wachtwoord telt mee in `login_attempts` (scope `restore`,
   5 per 15 minuten).
4. **Onderhoudsmodus** zit in `conf/worker.php`, dat `conf/database.php` laadt; `getDB()` controleert het
   bestand, behalve op de commandoregel (zo kan de worker `migrate.php` draaien tijdens de restore).
   `bin/migrate.php` geeft nu exitcode 1 bij een fout. De AI-agent behandelt 503 als "wachten"
   (`MaintenanceError`).
5. **Restore, randgevallen.** Een backup-taak staat in zijn eigen momentopname nog op `running`; het
   manifest bewaart daarom `job_id`, en na een restore van die backup staat die taak op `done`. Als de
   database bij een fout is vervangen, verbindt de restore opnieuw en zet hij zo nodig de taakrij terug
   (`_reattach`), zodat de mislukte restore altijd zichtbaar is.
6. **Docentnamen in een export met voortgang.** `progress.json` bewaart per archiefregel alleen
   `leveled_by_kind` (`teacher`/`ai`/onbekend); na import staat er bij een docent "Docent".
7. **Rapport.** Lange antwoorden staan als doorlopende tekst (een tabelrij kan in fpdf2 niet over een
   pagina doorlopen); het verloop per team is wel een tabel. Zonder DejaVu valt de PDF terug op Helvetica
   (alleen Latin-1; andere tekens worden '?').
8. **CLI.** Taken van de commandoregel melden geen heartbeat in `worker_status`, zodat de website geen
   actieve worker toont als alleen de CLI draaide.
9. **Configuratie aan PHP-kant.** `STORAGE_DIR`, `MAX_ARCHIVE_MB` en `RESULT_TTL_DAYS` in `config.php`
   (in Docker uit `WORKER_*`), gelijk aan de worker.

