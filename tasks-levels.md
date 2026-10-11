# Taken: spellen, start en levels

Uitwerking van de ideeën "startknop", "iedereen begint op level 1" en "logo bij de studenten", uitgebreid naar meerdere spellen.
Elke taak is los te bouwen en te testen; na elke taak blijft de applicatie werken.

## Besluiten

- **Spellen**: tabel `games`. Teams, opdrachten en het archief horen bij een spel. Een admin maakt spellen aan en wijst docenten toe; die docenten (en alle admins) besturen het spel.
- **Status**: `draft` → `running` ⇄ `paused` → `finished` (UI: concept / bezig / pauze / afgelopen). Na `ends_at` is een spel vanzelf `finished` (berekend in SQL, geen cronjob).
- **Levels**: 0 = nog niet gestart, 1..N = bezig met opdracht N, N+1 = Finish. Start zet alle teams tegelijk op level 1 met dezelfde starttijd. Een team dat later bij een lopend spel komt, begint direct op level 1 met de tijd van dat moment.
- **Level terug** kan. Chatberichten blijven bij een level-up bewaard (`team_messages.assignment_number`), zodat het gesprek terugkomt; de archiefregel van dat level wordt dan verwijderd.
- **Pauze** = alleen een banner met de statustekst; teams kunnen doorwerken. **Afgelopen** = schermvullende melding, chat dicht.
- **Timer** bij teams telt af naar de eindtijd van het spel.
- **Mail** gaat niet meer automatisch bij een level-up; alleen handmatig, als een team er niet bij kan.
- **Logo** per spel uploadbaar: groot op het wachtscherm (team en leaderboard) en als watermerk in de chat (team en docent).
- **Centrale berichten** naar alle of enkele teams: pop-up die je wegklikt, daarna terug te lezen onder "Mededelingen".
- **Zelf aanmelden** met spelcode/QR: team komt direct in het spel; de docent zet de registratie open of dicht.
- **Alles leegmaken**: teams en hun chats weg, archief blijft (met teamnaam).
- **Opdrachten**: per spel zelf aanmaken of kopiëren uit een ander spel.

## Werkwijze per taak

- `php -l` op elk gewijzigd PHP-bestand.
- Schemawijziging: `CREATE TABLE` in `htdocs/setup.php` **en** een idempotente stap in `bin/migrate.php`; `migrate.php` twee keer draaien geeft geen fouten.
- Elke docentactie schrijft een `audit_logs`-regel via `logAudit()`. Nieuwe en gewijzigde formulieren krijgen `csrfField()`/`verifyCsrf()`.
- Tijden in UTC en vergelijken in SQL; tonen via `formatDateTime()`.
- Pure logica komt in `conf/game.php` (functies die een `PDO` meekrijgen, zonder sessie of mail), getest in `tests/game_test.php` met SQLite in het geheugen:
  `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/game_test.php`
- Handmatig testen: `docker compose -f docker/docker-compose.yml up -d --build` (http://localhost:8088, Mailpit voor mail).

---

## Fase 1: spellen, start/pauze/einde, levels, logo, aftelklok

### Datamodel en kernlogica

- [x] **1.1 Schema `games` en `game_teachers`**
  `games`: `id`, `name`, `status` (default `draft`), `join_code` (uniek), `registration_open` (0/1), `logo_file`, `status_message`, `started_at`, `ends_at`, `created_at`, `created_by`.
  `game_teachers`: `game_id`, `teacher_id`, primaire sleutel op beide.
  *Klaar als:* verse install en migratie van een bestaande DB hebben beide tabellen; tweede `migrate.php`-run is foutloos.

- [x] **1.2 `game_id` op bestaande tabellen + standaardspel**
  `game_id` op `teams`, `assignments`, `completed_assignments`; `team_name` op `completed_assignments`. Unieke index op `teams(game_id, team_name)` en `assignments(game_id, assignment_number)`.
  Migratie: maak spel "Operation Gameplay" (logo `images/logo-operation-gameplay.png`, status `running` als er een team met level > 0 is, anders `draft`), koppel alle teams/opdrachten/archiefregels en alle docenten eraan, vul `completed_assignments.team_name` vanuit `teams`.
  *Klaar als:* op een kopie van de huidige DB staan na migratie alle rijen aan spel 1 en toont het archief nog dezelfde namen. (Commit ook `htdocs/images/logo-operation-gameplay.png`.)

- [x] **1.3 `conf/game.php`: spel ophalen en effectieve status**
  `getGame(PDO, int $id)` geeft het spel terug met `effective_status`: `finished` als `status` `running`/`paused` is en `ends_at <= datetime('now')`, anders `status`. Plus `canAccessGame(PDO, array $user, int $gameId)` (admin altijd, docent alleen via `game_teachers`).
  *Klaar als:* `tests/game_test.php` test: eindtijd in het verleden → `finished`; in de toekomst of leeg → eigen status; `draft` met verlopen eindtijd blijft `draft`; docent zonder koppeling → geen toegang; admin → wel.

- [x] **1.4 `conf/game.php`: levelfuncties**
  - `levelUp(PDO, int $teamId, ?int $userId)`: archiveert de chat (zonder suggesties) in `completed_assignments` met `game_id` en `team_name`, verwijdert alleen de AI-suggesties van dat level, verhoogt het level, zet `level_updated_at`. Stopt bij N+1.
  - `levelDown(PDO, int $teamId, ?int $userId)`: verlaagt het level (minimaal 1 bij een gestart spel), verwijdert de nieuwste archiefregel van het level waar het team naar teruggaat, zet `level_updated_at` op nu.
  - `startGame(PDO, int $gameId, ?int $userId)`: alleen vanuit `draft`; zet status `running`, `started_at`, en alle teams met level 0 op level 1 met **één** gezamenlijke `level_updated_at`.
  - `initialLevel(array $game)`: 0 bij `draft`, 1 bij `running`/`paused`; bij `finished` kan er geen team bij.
  Alles in een transactie, elk met een `logAudit()`-regel.
  *Klaar als:* tests dekken: level-up maakt archiefregel met teamnaam en laat teamberichten staan; level-up voorbij N+1 doet niets; level terug brengt de berichten van het vorige level terug (query op `assignment_number`) en verwijdert die archiefregel; start zet alle teams op 1 met gelijke tijd en weigert een tweede start.

### Docentkant

- [x] **1.5 Level-up in `teacher.php` en `messages.php` via `levelUp()`**
  Vervang beide kopieën door de functie; geen automatische mail meer. CSRF op deze formulieren. Ongelezen-tellingen (`ajax_unread`, zijbalk in `messages.php`) alleen voor het huidige level van een team, anders blijven oude berichten als ongelezen tellen.
  *Klaar als:* level-up vanuit beide pagina's werkt, er komt niets in Mailpit, de oude chat staat in het archief en het ongelezen-getal klopt na een level-up.

- [x] **1.6 Knop "level terug"**
  ⬇️ naast ⬆️ in `teacher.php` en in de chatkop van `messages.php`, met bevestiging, uitgeschakeld op level ≤ 1.
  *Klaar als:* team op level 3 → terug → level 2 met het gesprek van level 2 zichtbaar voor team en docent; archief bevat geen regel meer voor level 2.

- [x] **1.7 Spellenbeheer voor admins (`games.php`)**
  Lijst van spellen, nieuw spel aanmaken (naam), docenten toewijzen/ontkoppelen. Menu-item "🎮 Spellen" (alleen admin). `requireAdmin()`, CSRF, audit (`GAME_CREATE`, `GAME_TEACHERS`).
  *Klaar als:* admin maakt spel 2 aan en koppelt een docent; een gewone docent kan `games.php` niet openen.

- [x] **1.8 Actief spel in de sessie + spelkiezer**
  `$_SESSION['game_id']`; helper `requireGame(PDO, array $user)` in `conf/game.php` (geen toegang of geen keuze → naar een keuzepagina; bij `?ajax…` lege 403). Spelkiezer in `admin_header.php`. Docent zonder spel ziet "Je bent nog niet aan een spel toegevoegd".
  *Klaar als:* docent met twee spellen kan wisselen; bij één spel wordt dat automatisch gekozen; ontkoppelde docent verliest bij de volgende request de toegang.

- [x] **1.9 Pagina's filteren op het actieve spel**
  `teacher.php`, `messages.php`, `assignments.php`, `archive.php`: alle queries op `game_id`. Elke actie met een `team_id` of opdracht-`id` uit POST/GET controleert dat die bij het actieve spel hoort. Nieuwe opdrachten krijgen `game_id`; nummers uniek per spel; uploadnaam bevat het spel-id.
  *Klaar als:* met twee spellen ziet en wijzigt een docent alleen teams/opdrachten/berichten van het gekozen spel; een gemanipuleerde POST met een `team_id` van een ander spel wordt geweigerd.

- [x] **1.10 Team toevoegen in het actieve spel**
  Startlevel via `initialLevel()`; geweigerd bij `finished`. Teamnaam uniek binnen het spel. E-mail optioneel; vinkje "stuur dashboardlink per mail" (alleen zichtbaar als er een adres is). Knop "📋 kopieer teamlink" per team.
  *Klaar als:* team toegevoegd aan een lopend spel staat direct op level 1 met de huidige tijd; aan een concept-spel op 0; zonder e-mail gaat er niets naar Mailpit.

- [x] **1.11 Spelinstellingen en logo (`game.php`)**
  Nieuwe pagina "⚙️ Spel" voor het actieve spel: naam, eindtijd (`datetime-local`, in PHP omgezet naar UTC), logo-upload (png/jpg/webp, gecontroleerd met `getimagesize`, geen svg; opslaan als `uploads/game_<id>_logo_<tijd>.<ext>`, oud bestand weg). `bin/setup.sh` maakt de uploadmap aan en `.gitignore` negeert hem. Audit `GAME_UPDATE`.
  *Klaar als:* logo uploaden toont het nieuwe logo; een `.svg` of een hernoemd tekstbestand wordt geweigerd; ingevoerde eindtijd 14:00 lokaal staat als UTC in de DB en wordt als 14:00 getoond.

- [x] **1.12 Statusknoppen (`game.php`)**
  Start (via `startGame()`, met bevestiging), Pauze, Hervat, Beëindig, elk met een tekstveld voor `status_message` (bijv. "We hebben pauze tot 11:00", "Kom naar lokaal X"). Alleen geldige overgangen tonen. Audit `GAME_START`/`GAME_PAUSE`/`GAME_RESUME`/`GAME_FINISH`.
  *Klaar als:* alle overgangen werken en verschijnen in de auditlog; Start is alleen zichtbaar in `draft`.

- [x] **1.13 Watermerk in de docentenchat**
  Logo van het spel als vaste, gecentreerde achtergrond met lage dekking (~8%) achter de berichten in `messages.php`.
  *Klaar als:* logo zichtbaar maar tekst goed leesbaar; zonder logo geen kapotte afbeelding.

### Teamkant

- [x] **1.14 `team.php`, `team_download.php`, `download.php` per spel**
  Opdracht ophalen op `game_id` + `assignment_number`; totaal aantal opdrachten per spel; positie berekenen binnen het spel. De ajax-response krijgt `status`, `status_message` en `seconds_left` (berekend op de server, zodat een verkeerde klok op de laptop van het team niet uitmaakt).
  *Klaar als:* twee spellen met elk een opdracht 1 tonen elk hun eigen opdracht en download; positie telt alleen teams van hetzelfde spel.

- [x] **1.15 Wachtscherm**
  Bij `draft`: schermvullend het logo, de teamnaam en "Wacht op het startsein…"; geen chat, geen opdrachtknop. Polling ziet de statuswissel en herlaadt de pagina, waarna de bestaande overlay "🕵️ OPDRACHT BINNEN!" en de opdracht-popup verschijnen.
  *Klaar als:* team-tab open laten, docent drukt op Start → binnen ~5 s verschijnt de opdracht zonder handmatig herladen.

- [x] **1.16 Pauzebanner en eindscherm**
  `paused`: banner bovenaan met `status_message`, verder alles bruikbaar. `finished`: schermvullende melding met logo, `status_message` en eindpositie; chat dicht. De server weigert berichten van teams bij `draft` en `finished`.
  *Klaar als:* pauze → banner verschijnt en team kan nog berichten sturen; einde (handmatig of doordat de eindtijd verstrijkt) → eindscherm; een POST met een bericht na het einde komt niet in de DB.

- [x] **1.17 Aftelklok**
  "Tijd bezig" wordt "Resterende tijd" tot `ends_at`, bijgesteld met `seconds_left` uit elke poll. Zonder eindtijd: tijd sinds de start. Laatste 10 minuten rood.
  *Klaar als:* eindtijd over 2 minuten → klok telt af, wordt rood, en bij 0 verschijnt binnen één poll het eindscherm.

- [x] **1.18 Watermerk in de teamchat**
  Zelfde stijl als 1.13 in `team.php`, ook in de vergrote tekstmodus.
  *Klaar als:* zichtbaar op laptop- en telefoonbreedte zonder de berichten onleesbaar te maken.

### Leaderboard, agent, opruimen

- [x] **1.19 Publiek leaderboard per spel**
  `index.php?game=<join_code>`; zonder parameter: het enige lopende spel, of een keuzelijst als er meerdere zijn. Bij `draft`: groot logo met "Binnenkort van start". Voortgang overal als `(level − 1) / N` (nu verschillend in `index.php` en `teacher.php`). Bij `finished`: eindstand met "Afgelopen".
  *Klaar als:* team dat net op level 1 begint staat op 0%; team op Finish op 100%; concept-spel toont alleen het logo.

- [x] **1.20 Agent alleen voor actieve spellen**
  `api.php?action=get_pending` geeft alleen teams van spellen met effectieve status `running` of `paused`, en koppelt de opdracht op `game_id`.
  *Klaar als:* een bericht van een team in een `draft`- of `finished`-spel verschijnt niet in `get_pending`; in een lopend spel wel.

- [x] **1.21 Mails opruimen**
  Dode `elseif ('resend_level_mail')`-tak in `teacher.php` weg. 📧 stuurt de dashboardlink van het team (geen download-token meer). Waar nog download-tokens gemaakt worden: `expires_at` via `datetime('now', '+1 day')`. Teamnamen ge-escaped in de HTML van de mails (nodig voor fase 3, waarin teams hun naam zelf invullen).
  *Klaar als:* 📧 levert in Mailpit één mail met een werkende teamlink; een teamnaam als `<b>x</b>` verschijnt letterlijk in de mail.

---

## Fase 2: centrale berichten

- [x] **2.1 Schema `announcements`**
  `id`, `game_id`, `team_id` (NULL = alle teams), `message`, `created_by`, `created_at`.
  *Klaar als:* tabel bestaat na verse install en na migratie.

- [x] **2.2 Versturen (`announcements.php`)**
  Menu-item "📣 Mededelingen": bericht typen (markdown, net als de opdrachten), ontvangers kiezen (alle teams of een selectie), lijst van verstuurde berichten. Audit `ANNOUNCEMENT_SEND` (zonder de inhoud te hoeven loggen).
  *Klaar als:* bericht aan twee van de drie teams levert twee rijen op; het team-id wordt gecontroleerd tegen het actieve spel.

- [x] **2.3 Ontvangen in `team.php`**
  Ajax geeft de mededelingen voor dit team (`team_id IS NULL OR team_id = ?`). Nieuwe (id hoger dan het laatst gesloten id in `localStorage`) verschijnen als pop-up; knop "📣 Mededelingen" toont ze allemaal.
  *Klaar als:* bericht aan alle teams verschijnt binnen ~5 s bij elk team als pop-up, komt na wegklikken en herladen niet terug, en blijft te lezen in de lijst; een bericht aan team A verschijnt niet bij team B.

---

## Fase 3: zelf aanmelden met code en QR

- [x] **3.1 Spelcode genereren**
  `generateJoinCode()` in `conf/game.php`: 6 tekens zonder verwarrende tekens (geen 0/O/1/I/L), uniek. Ingevuld bij het aanmaken van een spel en via de migratie voor bestaande spellen. Knop "nieuwe code" in `game.php`.
  *Klaar als:* test: 1000 codes bevatten geen verboden tekens en een bestaande code wordt niet opnieuw uitgegeven.

- [x] **3.2 Registratie open/dicht**
  Schakelaar in `game.php` (`registration_open`), audit `REGISTRATION_TOGGLE`.
  *Klaar als:* status zichtbaar en wijzigbaar; staat in de auditlog.

- [x] **3.3 Aanmeldpagina `join.php`**
  Publiek. `?code=` of een invulveld voor de code; teamnaam (verplicht, max. lengte, uniek binnen het spel) en optioneel e-mail. CSRF; rate limiting via `isThrottled()`/`recordAttempt()`. Alleen als de registratie open is en het spel niet `finished`. Startlevel via `initialLevel()`. Daarna door naar `team.php?token=…` met de melding "bewaar deze link" en, als er een e-mail is, de link per mail. Audit `TEAM_REGISTER` met `user_id` NULL.
  *Klaar als:* aanmelden bij een lopend spel → direct level 1 met opdracht; dichte registratie, onbekende code of dubbele naam → nette foutmelding; tien snelle pogingen met een foute code worden afgeremd.

- [x] **3.4 QR-code**
  In `game.php` een QR-code naar `BASE_URL/join.php?code=…` (QR-bibliotheek via CDN met vaste versie), plus een schermvullende beamerweergave met logo, code en QR.
  *Klaar als:* QR scannen met een telefoon opent de aanmeldpagina met de code al ingevuld.

---

## Fase 4: alles leegmaken en opdrachten kopiëren

- [x] **4.1 `resetGame()` in `conf/game.php`**
  Verwijdert alle teams van het spel met hun `team_messages`, `download_tokens`, `team_downloads` en de mededelingen van het spel; laat `completed_assignments`, opdrachten en instellingen staan; status terug naar `draft`, `started_at` leeg.
  *Klaar als:* test: na reset geen teams/berichten van dit spel, archief ongewijzigd (met teamnamen), teams van een ander spel ongemoeid.

- [x] **4.2 Knop "Alles leegmaken"**
  In `game.php`, met bevestiging door de spelnaam te typen. Audit `GAME_RESET`.
  *Klaar als:* zonder de juiste naam gebeurt er niets; met de juiste naam is het spel leeg en staat het archief nog in `archive.php`.

- [x] **4.3 Opdrachten kopiëren uit een ander spel**
  `copyAssignments(PDO, int $fromGame, int $toGame)`: kopieert de rijen en de bestanden (nieuwe bestandsnaam), nummering loopt door na het hoogste bestaande nummer. Knop in `assignments.php`; alleen spellen waar de docent toegang toe heeft. Audit `ASSIGNMENTS_COPY`.
  *Klaar als:* test: kopie naar een leeg spel geeft nummers 1..N; naar een spel met 2 opdrachten 3..N+2. Handmatig: het gekopieerde bestand is te downloaden en verwijderen in het ene spel raakt het andere niet.

- [x] **4.4 Import/export-scripts per spel**
  `import_assignments.py` en `export_assignments.py` krijgen `--game <id>`; import wist alleen de opdrachten van dat spel.
  *Klaar als:* export van spel 1 en import in spel 2 laat de opdrachten van spel 1 staan.

---

## Buiten deze taken

- Spellen verwijderen.
- Een spoor per team en "alle goedgekeurde antwoorden per vraag" in het archief (IDEAS.md); 1.2 legt hiervoor de basis door de teamnaam in het archief te bewaren.
- Thema per spel.
- Het oude download-token per mail (`download.php`) uitfaseren: blijft voorlopig werken voor bestaande links.
