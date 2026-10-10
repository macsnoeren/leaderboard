# Usermanagement: ontwerp en taken

Dit ontwerp gaat alleen over accounts van docenten en beheerders (tabel `teachers`). De toegang voor teams, agents en downloads blijft zoals hij is.

---

## 1. Uitgangssituatie

Wat er al is:
- `users.php` (alleen admin): gebruiker aanmaken met gebruikersnaam en wachtwoord, wachtwoord resetten, (de)activeren en verwijderen.
- `password.php`: eigen wachtwoord wijzigen. Staat `force_password_change` aan, dan stuurt `admin_header.php` de gebruiker op elke pagina terug naar `password.php`.
- `teachers` heeft de kolommen `id, username, password_hash, role, force_password_change, is_active`. Er is geen e-mailadres.

Wat ontbreekt of niet klopt:
- Zonder e-mailadres kan een gebruiker niet uitgenodigd worden en kan hij zijn wachtwoord niet zelf herstellen.
- Rol, actief-status en "wachtwoord wijzigen verplicht" worden bij het inloggen in de sessie gezet en daarna niet meer gecontroleerd. Een gedeactiveerde gebruiker blijft dus ingelogd, en een admin die je terugzet naar `user` houdt zijn admin-rechten tot hij uitlogt.
- Er is geen CSRF-bescherming en geen limiet op het aantal inlogpogingen.
- De wachtwoordeisen verschillen per plek: minimaal 4 tekens in `users.php` (server), 6 in de HTML en in `password.php`, en geen eis in `setup.php`.
- Het reset-formulier in `users.php` heeft geen vinkje "wijzigen verplicht", waardoor `force_change` altijd 0 is.
- `setup.php` zet `teacher_id` als string (`lastInsertId()`) en zet geen `teacher_username`. Direct na de setup faalt daardoor de check `$id === $_SESSION['teacher_id']` in `users.php`, en kan de admin zijn eigen account verwijderen.
- Bij verwijderen wordt niet gecontroleerd of het de laatste admin is (bij deactiveren wel).

---

## 2. Ontwerp

### 2.1 Accountstatussen

| Status | Voorwaarde | Kan inloggen |
|---|---|---|
| Uitgenodigd | `password_hash IS NULL`, `is_active = 1` | Nee, alleen activeren via de link |
| Actief | `password_hash` gezet, `is_active = 1` | Ja |
| Gedeactiveerd | `is_active = 0` | Nee |

Daarnaast zijn er twee vlaggen: `force_password_change` en `totp_enabled`. De status "Uitnodiging verlopen" wordt niet opgeslagen. Het is een uitgenodigde gebruiker zonder geldig invite-token.

### 2.2 Datamodel

Nieuwe kolommen in `teachers`:

| Kolom | Type | Toelichting |
|---|---|---|
| `email` | TEXT | Altijd in kleine letters. Unieke index; meerdere `NULL`'s zijn toegestaan voor bestaande accounts. |
| `display_name` | TEXT | Naam die in de UI getoond wordt. |
| `created_at` | DATETIME | Zie de valkuil hieronder. |
| `last_login_at` | DATETIME | |
| `session_version` | INTEGER DEFAULT 0 | Ophogen maakt alle sessies van deze gebruiker ongeldig. |
| `totp_secret` | TEXT | Base32. `NULL` als 2FA uit staat. |
| `totp_enabled` | INTEGER DEFAULT 0 | |
| `totp_last_step` | INTEGER | Laatst gebruikte tijdstap, zodat een code niet twee keer werkt. |

`username` mag `NULL` zijn zolang een uitgenodigde gebruiker nog niet geactiveerd is. Een `UNIQUE`-kolom in SQLite staat meerdere `NULL`'s toe.

Nieuwe tabellen:

```sql
CREATE TABLE IF NOT EXISTS user_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL,               -- 'invite' | 'reset'
    token_hash TEXT NOT NULL UNIQUE,  -- sha256 van het token; het token zelf staat alleen in de mail
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    created_by INTEGER,               -- id van de admin, NULL bij een zelf aangevraagde reset
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,          -- password_hash()
    used_at DATETIME
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope TEXT NOT NULL,              -- 'login' | '2fa' | 'forgot'
    identifier TEXT,                  -- gebruikersnaam/e-mail (lowercase) of user_id
    ip TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

`login_attempts` slaat alleen mislukte pogingen en aanvragen voor wachtwoordherstel op. Bij elke nieuwe insert worden regels ouder dan 24 uur verwijderd.

**Valkuilen** (getest met SQLite 3.37):
- `ALTER TABLE … ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP` faalt met *"Cannot add a column with non-constant default"* zodra de tabel rijen bevat. Voeg in `migrate.php` daarom `created_at DATETIME` zonder default toe en vul bestaande rijen met `UPDATE teachers SET created_at = datetime('now') WHERE created_at IS NULL`. Nieuwe code zet `created_at` bij een insert altijd expliciet.
- `CURRENT_TIMESTAMP` en `datetime('now')` geven UTC, terwijl PHP's `date()` de lokale tijd gebruikt. Doe alle nieuwe tijdberekeningen en -vergelijkingen daarom in SQL (`expires_at = datetime('now', '+72 hours')` en `expires_at > datetime('now')`) en niet in PHP.

### 2.3 Gedeelde code

Nieuwe bestanden in `conf/`, naast `database.php` en `mail_functions.php`. Het paginapatroon uit CLAUDE.md blijft hetzelfde.

`conf/auth.php`:
```
requireTeacher(PDO $db): array          // sessie- en databasecheck (zie 2.4), geeft de gebruiker terug
requireAdmin(PDO $db): array            // requireTeacher + rolcheck
completeLogin(PDO $db, array $user)     // regenerate id, sessie vullen, last_login_at, audit LOGIN
logAudit(PDO $db, ?int $userId, string $type, string $description)
validatePassword(string $pw, string $confirm): ?string   // foutmelding of null
normalizeEmail(string $email): ?string                   // trim + lowercase, null als ongeldig
createUserToken(PDO $db, int $userId, string $type, int $ttlMinutes, ?int $createdBy): string
findUserToken(PDO $db, string $token, string $type): ?array   // alleen geldig, ongebruikt en van een actieve gebruiker
markTokenUsed(PDO $db, int $tokenId)
csrfField(): string  /  verifyCsrf()
isThrottled(PDO $db, string $scope, string $identifier, int $max, int $minutes): bool
recordAttempt(PDO $db, string $scope, string $identifier)
```

`conf/totp.php` bevat pure functies zonder database: `base32Encode`, `base32Decode`, `totpCode`, `totpVerify` en `totpUri`.

### 2.4 Sessies

- Bij het inloggen bewaart de sessie `teacher_id` (als int) en `session_version`.
- `requireTeacher()` leest bij elk verzoek de gebruiker opnieuw uit de database. Het logt uit (`session_destroy()`, daarna naar `login.php?expired=1`) als de gebruiker niet meer bestaat, als `is_active = 0`, als `password_hash` leeg is of als `session_version` niet overeenkomt.
- Daarna ververst het `teacher_role` en `teacher_username` in de sessie vanuit de database. Een gewijzigde rol werkt dus direct.
- `force_password_change` wordt ook uit de database gelezen. De redirect naar `password.php` verhuist van `admin_header.php` naar `requireTeacher()`, zodat ook POST-handlers geblokkeerd zijn.
- Bij een AJAX-verzoek (een GET-parameter die met `ajax` begint) geeft `requireTeacher()` een lege `401` terug in plaats van een redirect. Zo krijgt de polling-JS geen loginpagina als HTML terug.
- `session_version` gaat met 1 omhoog als het wachtwoord gewijzigd of gereset wordt, bij deactiveren en als een admin 2FA reset. Wijzigt de gebruiker zijn eigen wachtwoord, dan krijgt zijn huidige sessie direct de nieuwe versie en blijft hij ingelogd.

### 2.5 Flows

**Inloggen**
```
gebruikersnaam óf e-mail + wachtwoord
  → actief? (anders dezelfde algemene foutmelding)
  → 2FA aan? → login_2fa.php (code of herstelcode)
  → completeLogin()
  → force_password_change? → password.php
  → teacher.php
```

**Uitnodigen**
1. De admin vult e-mail, rol en optioneel een naam in. Er komt een rij in `teachers` met `password_hash = NULL` en een `invite`-token dat 72 uur geldig is.
2. De gebruiker krijgt een mail met `BASE_URL/activate.php?token=…`.
3. De gebruiker kiest een gebruikersnaam (vooraf ingevuld met het deel vóór de @), een naam en een wachtwoord. Het token wordt als gebruikt gemarkeerd en de gebruiker gaat naar `login.php` met een melding.
4. "Opnieuw versturen" maakt een nieuw token aan. Oude invite-tokens van die gebruiker werken dan niet meer.

**Wachtwoord vergeten**
1. Via de link "Wachtwoord vergeten?" op `login.php` komt de gebruiker op `forgot_password.php` en vult daar zijn e-mailadres in.
2. De pagina toont altijd dezelfde melding, of het adres nu bestaat of niet. Alleen voor een actief account met een e-mailadres wordt een `reset`-token (60 minuten geldig) aangemaakt en een mail verstuurd.
3. Op `reset_password.php?token=…` kiest de gebruiker een nieuw wachtwoord. Daarna wordt het token gebruikt, werken andere reset-tokens van die gebruiker niet meer, wordt `force_password_change` 0 en gaat `session_version` met 1 omhoog.
4. 2FA blijft aan staan. Na een reset is bij het inloggen nog steeds een code nodig.

**2FA (TOTP)**
- Volgens RFC 6238: SHA1, 6 cijfers, stappen van 30 seconden en ±1 stap speling. Dit werkt met Google en Microsoft Authenticator, 1Password, Bitwarden en vergelijkbare apps.
- Een code werkt maar één keer: de tijdstap moet groter zijn dan `totp_last_step`.
- De QR-code wordt in de browser getekend met `qrcodejs` 1.0.0 van cdnjs (`https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js`). Gebruik **nooit** een externe QR-dienst via een `<img src>`: dan gaat het secret naar een derde partij. Toon het secret daarnaast ook als tekst, zodat het handmatig ingevoerd kan worden.
- Bij het inschakelen krijgt de gebruiker 10 herstelcodes. Die worden één keer getoond en gehasht opgeslagen; elke code werkt één keer.
- Een admin kan 2FA van een gebruiker resetten, maar kan het secret nooit zien.

### 2.6 Mail

- Nieuwe functies in `mail_functions.php`: `sendInviteEmail`, `sendPasswordResetEmail` en (optioneel) `sendEmailChangedNotice`. Ze gebruiken allemaal de gedeelde `createMailer()` en geven `true` of `ErrorInfo` terug, net als de bestaande functies.
- **Testmodus of mislukte mail, als de admin de actie start** (uitnodigen, reset-link sturen): de flash-melding toont de link, zodat de admin hem zelf kan doorgeven.
- **Testmodus bij `forgot_password.php`**: de link gaat alleen naar `error_log()` (zichtbaar in de Docker-logs) en **nooit** naar de pagina. Anders kan iedereen elk account resetten.

### 2.7 Beveiligingsregels

- Een token bestaat uit 32 willekeurige bytes (hex). In de database staat alleen de sha256-hash; zoeken gaat op die hash. Elk token werkt één keer.
- Inloggen geeft altijd dezelfde foutmelding ("Ongeldige gebruikersnaam of wachtwoord"), ook bij een gedeactiveerd of uitgenodigd account. Wachtwoord vergeten geeft ook altijd dezelfde melding.
- Limieten:

  | Scope | Limiet |
  |---|---|
  | Inloggen | 5 mislukte pogingen per identifier per 15 minuten, 20 per IP per 15 minuten |
  | 2FA-stap | 5 pogingen, daarna terug naar `login.php` |
  | Wachtwoord vergeten | 3 per e-mailadres per uur, 10 per IP per uur |

- Elk formulier op een nieuwe of gewijzigde pagina heeft een CSRF-token.
- `session_regenerate_id(true)` wordt aangeroepen in `completeLogin()`.
- Je kunt jezelf niet verwijderen, deactiveren of terugzetten naar `user`. De laatste actieve admin kan niet verwijderd, gedeactiveerd of teruggezet worden.
- Elke wijziging krijgt een `audit_logs`-regel. Log nooit wachtwoorden, tokens, TOTP-secrets of codes.
- Wachtwoordbeleid: minimaal 8 tekens en beide invoervelden moeten gelijk zijn.

### 2.8 Bewust buiten scope

- Authenticatie van teams, agents en downloads.
- Open zelfregistratie zonder uitnodiging.
- SSO/LDAP en passkeys/WebAuthn.
- Versleutelen van `totp_secret` in de database. De database staat buiten de webroot; dit kan later met een sleutel in `config.php`.
- CSRF op bestaande pagina's die verder niet veranderen (`teacher.php`, `assignments.php`, `messages.php`, …). Dat kan als losse vervolgtaak.

### 2.9 Keuzes die nog bevestigd moeten worden

1. De gebruiker kiest zelf zijn gebruikersnaam bij het activeren. Alternatief: de admin vult die in bij het uitnodigen.
2. Inloggen kan met gebruikersnaam of e-mailadres.
3. 2FA is optioneel per gebruiker. 2FA verplichten voor admins is een optionele taak (T6.7).
4. E-mail wijzigen in het profiel vraagt het huidige wachtwoord en stuurt een melding naar het oude adres. Bevestigen via het nieuwe adres is optioneel (T5.4).
5. Een uitnodiging is 72 uur geldig, een reset-link 60 minuten.

---

## 3. Testaanpak

- Controleer elk aangepast PHP-bestand met `php -l`. PHP staat niet op de host, dus doe dit in Docker:
  `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php -l htdocs/users.php`
- Pure functies (wachtwoordregels, e-mail, TOTP) krijgen een CLI-test in `tests/`: gewone PHP zonder framework, die met exitcode ≠ 0 stopt bij een fout. Zo voer je ze uit:
  `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/totp_test.php`
- Pagina's test je met de stappen onder **Test** bij elke taak, in de Docker-omgeving (`docker/start.sh`, http://localhost:8088).
- Mail test je met Mailpit (T0.4), of met `ENABLE_EMAIL=false` en de link in de flash-melding of in de log.
- De database bekijk je met `docker compose -f docker/docker-compose.yml exec leaderboard sqlite3 database/leaderboard.db`.
- Voor sessietests gebruik je twee browsers, of een normaal en een privévenster.

---

## 4. Taken

Bij elke taak staat welke bestanden veranderen en hoe je hem test. Werk de fases in volgorde af. Binnen een fase staan afhankelijkheden onder **Na**.

### Fase 0: Fundament

#### T0.1 Schema uitbreiden
- [x] Voeg de kolommen en tabellen uit 2.2 toe aan de `CREATE TABLE` in `setup.php` en als idempotente stappen aan `migrate.php`, inclusief de unieke index `idx_teachers_email` en het vullen van `created_at`.

**Bestanden:** `htdocs/setup.php`, `bin/migrate.php`
**Test:**
1. Verse installatie (`docker/stop.sh --clean`, `docker/start.sh`): `.schema teachers` toont alle nieuwe kolommen en de drie nieuwe tabellen bestaan.
2. Bestaande database met docenten: draai `php bin/migrate.php` twee keer. Er komt geen fout, en `SELECT id, created_at FROM teachers` heeft geen `NULL` meer.
3. Inloggen met een bestaand account werkt nog.

#### T0.2 `conf/auth.php` met helpers + eerste tests
- [x] Maak `conf/auth.php` met `logAudit`, `validatePassword`, `normalizeEmail`, `createUserToken`, `findUserToken` en `markTokenUsed` (de rest volgt in fase 1).
- [x] Maak `tests/auth_test.php` voor `validatePassword` en `normalizeEmail`.

**Bestanden:** `conf/auth.php` (nieuw), `tests/auth_test.php` (nieuw)
**Test:**
1. `php tests/auth_test.php` slaagt met minstens deze gevallen: `"kort"` geeft een fout, twee verschillende invoeren geven een fout, `"  Jan@School.NL "` wordt `"jan@school.nl"`, en `"geen-mail"` wordt `null`.
2. Tokens (handmatig via een tijdelijk CLI-script of pas in T3.2): na `createUserToken` staat in `user_tokens` een hash van 64 tekens die niet gelijk is aan het token. `findUserToken` geeft `null` voor een verlopen token, een gebruikt token, een token van het verkeerde type en een token van een gedeactiveerde gebruiker.

#### T0.3 `createMailer()` in mail_functions
- [x] Haal de dubbele SMTP-setup uit `sendLevelUpEmail` en `sendWelcomeEmail` en zet die in `createMailer(): PHPMailer`.

**Bestanden:** `conf/mail_functions.php`
**Test:** Een level-up en een nieuw team versturen nog steeds hun mail (Mailpit of echte SMTP). Met `ENABLE_EMAIL=false` gebeurt er niets en verschijnt er geen fout.

#### T0.4 (optioneel) Mailpit in Docker
- [x] Voeg een `mailpit`-service toe aan `docker-compose.yml` (host-netwerk, SMTP 1025, UI 8025).
- [x] Voeg optionele constanten `SMTP_SECURE` (`'tls'` of `''`) en `SMTP_AUTH` (bool) toe, met `defined()`-fallback naar het huidige gedrag zodat bestaande `config.php`-bestanden blijven werken. Laat `entrypoint.sh` ze uit de omgeving zetten.

**Bestanden:** `docker/docker-compose.yml`, `docker/entrypoint.sh`, `docker/.env.example`, `docker/README.md`, `conf/mail_functions.php`, `conf/config.php.example`
**Test:** Met `ENABLE_EMAIL=true`, `SMTP_HOST=localhost`, `SMTP_PORT=1025`, `SMTP_SECURE=` en `SMTP_AUTH=false` verschijnt de welkomstmail van een nieuw team op http://localhost:8025.

### Fase 1: Sessies en inloggen

#### T1.1 `requireTeacher()` / `requireAdmin()` met `session_version`
- [x] Implementeer de functies volgens 2.4.
- [x] Vervang de losse login- en rolchecks in `teacher.php`, `assignments.php`, `messages.php`, `password.php`, `users.php`, `api_keys.php`, `archive.php` en `audit.php`.
- [x] Verplaats de `force_password_change`-redirect van `admin_header.php` naar `requireTeacher()`.

**Na:** T0.1, T0.2
**Bestanden:** `conf/auth.php`, de acht pagina's hierboven, `htdocs/admin_header.php`
**Test:**
1. Log in als gebruiker B in browser 2. Deactiveer B als admin in browser 1. B's volgende klik leidt naar `login.php?expired=1`.
2. Log in als admin B (niet de laatste admin) in browser 2. Zet B via de database terug naar `user` (`UPDATE teachers SET role='user' WHERE id=<B>`). Bij de volgende klik ziet B het menu-item "Users" niet meer, en `users.php` stuurt hem door naar `teacher.php`.
3. Zet `force_password_change = 1` in de database terwijl B is ingelogd. Elke pagina, ook een POST, gaat naar `password.php`.
4. Uitgelogd `teacher.php?ajax_unread=1` openen geeft status 401 met een lege body.

#### T1.2 CSRF-tokens
- [x] Voeg `csrfField()` en `verifyCsrf()` toe aan `conf/auth.php`.
- [x] Gebruik ze in alle formulieren van `users.php`, `password.php` en `login.php`. Elke nieuwe pagina uit dit document gebruikt ze ook.

**Na:** T0.2
**Bestanden:** `conf/auth.php`, `htdocs/users.php`, `htdocs/password.php`, `htdocs/login.php`
**Test:**
1. Alle formulieren werken zoals voorheen.
2. Haal het hidden veld weg met devtools en verstuur het formulier: je krijgt een foutmelding en er verandert niets in de database.

#### T1.3 `completeLogin()`, inloggen met e-mail, setup repareren
- [x] `login.php` zoekt op `username = ? OR email = ?` (e-mail genormaliseerd) en weigert accounts met `password_hash IS NULL`.
- [x] Alle foutmeldingen worden "Ongeldige gebruikersnaam of wachtwoord".
- [x] `completeLogin()` zet `last_login_at` en `session_version` en schrijft een audit-regel `LOGIN`.
- [x] `setup.php` gebruikt `completeLogin()`, waardoor `teacher_id` een int is en `teacher_username` gezet wordt.
- [x] `login.php` toont de melding bij `?expired=1`.

**Na:** T1.1
**Bestanden:** `htdocs/login.php`, `htdocs/setup.php`, `conf/auth.php`
**Test:**
1. Inloggen met de gebruikersnaam werkt, en met het e-mailadres in hoofdletters ook.
2. Een gedeactiveerd account, een uitgenodigd account en een verkeerd wachtwoord geven alle drie exact dezelfde melding.
3. Na een verse setup toont de zijbalk de gebruikersnaam, en "verwijderen" op je eigen account geeft "Je kunt je eigen account niet verwijderen".
4. `last_login_at` wordt bijgewerkt.

#### T1.4 Inlogpogingen beperken
- [x] Voeg `isThrottled()` en `recordAttempt()` toe.
- [x] Pas in `login.php` de limieten uit 2.7 toe en schrijf een audit-regel `LOGIN_FAILED` (met de identifier, zonder wachtwoord).

**Na:** T1.3
**Bestanden:** `conf/auth.php`, `htdocs/login.php`
**Test:**
1. Na 5 foute wachtwoorden voor `jan` meldt de 6e poging (ook met het goede wachtwoord) "Te veel pogingen, probeer het over 15 minuten opnieuw".
2. Een andere gebruiker kan vanaf hetzelfde IP nog wel inloggen.
3. Haal na het testen de regels weg met `DELETE FROM login_attempts`, of laat `created_at` in de database 16 minuten teruglopen. Daarna werkt inloggen weer.

#### T1.5 Eén wachtwoordbeleid
- [x] Gebruik `validatePassword()` in `setup.php`, `password.php` en `users.php` (reset). Zet `minlength="8"` in de HTML.

**Na:** T0.2
**Bestanden:** `htdocs/setup.php`, `htdocs/password.php`, `htdocs/users.php`
**Test:** Een wachtwoord van 7 tekens wordt op alle drie de plekken geweigerd met dezelfde melding, ook als je de HTML-validatie met devtools uitschakelt. Een wachtwoord van 8 tekens wordt geaccepteerd.

### Fase 2: Gebruikers beheren (admin)

#### T2.1 Uitgebreid gebruikersoverzicht
- [x] Geef de tabel in `users.php` deze kolommen: naam, gebruikersnaam, e-mail, rol, status (Uitgenodigd / Uitnodiging verlopen / Actief / Gedeactiveerd), badges voor "Wijzigen verplicht" en "2FA", en "laatst ingelogd".

**Na:** T0.1
**Bestanden:** `htdocs/users.php`
**Test:** Zet in de database één gebruiker op elke status. Het overzicht toont de juiste status en badges, en `NULL`-waarden geven geen PHP-warnings.

#### T2.2 Gebruiker bewerken (`user_edit.php`)
- [x] Maak een nieuwe pagina met een formulier voor naam, e-mail, gebruikersnaam en rol, en voeg een knop "Bewerken" toe per rij in `users.php`.
- [x] Controleer dat e-mail en gebruikersnaam uniek en geldig zijn.
- [x] Beveilig de rol: je kunt jezelf niet terugzetten en niet de laatste actieve admin.
- [x] Schrijf een audit-regel `USER_EDIT` met de gewijzigde velden (oud naar nieuw).

**Na:** T1.1, T1.2
**Bestanden:** `htdocs/user_edit.php` (nieuw), `htdocs/users.php`
**Test:**
1. Wijzig het e-mailadres van B. Het overzicht toont het nieuwe adres in kleine letters.
2. Het e-mailadres of de gebruikersnaam van A invullen bij B geeft een foutmelding.
3. Je eigen rol wijzigen geeft een foutmelding.
4. Met één admin kan die admin niet teruggezet worden naar `user`.
5. `user_edit.php?id=999` geeft de melding "Gebruiker niet gevonden".
6. Als `user` naar `user_edit.php` gaan stuurt je door naar `teacher.php`.

#### T2.3 Wachtwoord instellen door admin
- [x] Voeg het ontbrekende vinkje "Wijzigen verplicht bij volgende login" toe aan het resetformulier (standaard aan).
- [x] Hoog `session_version` op en schrijf een audit-regel `USER_RESET_PWD`.

**Na:** T1.1, T1.5
**Bestanden:** `htdocs/users.php` (of `user_edit.php`)
**Test:**
1. Reset het wachtwoord van B terwijl B in browser 2 is ingelogd. B is bij de volgende klik uitgelogd.
2. B logt in met het nieuwe wachtwoord en moet direct een eigen wachtwoord kiezen.
3. Zonder vinkje komt B direct op het dashboard.

#### T2.4 Wachtwoordwijziging afdwingen zonder reset
- [x] Voeg per gebruiker de knop "Wachtwoord wijzigen verplichten" toe, of "Opheffen" als het al verplicht is. Het wachtwoord blijft hetzelfde.
- [x] Schrijf de audit-regels `USER_FORCE_PWD` en `USER_UNFORCE_PWD`.

**Na:** T1.1
**Bestanden:** `htdocs/users.php`
**Test:**
1. Zet de vlag aan voor de ingelogde B. B's volgende klik gaat naar `password.php` en B blijft ingelogd.
2. Na het opheffen kan B weer gewoon verder.

#### T2.5 Deactiveren en verwijderen beveiligen
- [x] Gebruik `(int)` bij de vergelijking met de eigen id.
- [x] Controleer bij verwijderen ook of het de laatste admin is.
- [x] Verwijder bij het verwijderen van een gebruiker ook zijn `user_tokens` en `user_recovery_codes`.
- [x] Hoog bij deactiveren `session_version` op.

**Na:** T1.1
**Bestanden:** `htdocs/users.php`
**Test:**
1. Jezelf verwijderen of deactiveren wordt geweigerd.
2. De laatste admin verwijderen wordt geweigerd.
3. Na het verwijderen van B geven `SELECT COUNT(*) FROM user_tokens WHERE user_id=<B>` en dezelfde query op `user_recovery_codes` allebei 0.

### Fase 3: Uitnodigen per e-mail

#### T3.1 `sendInviteEmail()`
- [x] Maak `sendInviteEmail($to, $link, $invitedBy)` met een Nederlandse HTML-tekst en `AltBody`, en vermeld de geldigheid (72 uur).

**Na:** T0.3
**Bestanden:** `conf/mail_functions.php`
**Test:** Roep de functie aan vanuit een tijdelijk CLI-script. De mail komt aan in Mailpit, de link is klikbaar en de tekst bevat geen onbedoelde HTML.

#### T3.2 Uitnodigen vanuit `users.php`
- [x] Vervang het formulier "Nieuwe Docent" door: e-mail, rol en naam (optioneel).
- [x] Maak de gebruiker aan zonder wachtwoord en met een `invite`-token, en verstuur de mail.
- [x] Toon in testmodus of als de mail mislukt de link in de flash-melding (zie 2.6).
- [x] Schrijf een audit-regel `USER_INVITE`.

**Na:** T0.2, T2.1, T3.1
**Bestanden:** `htdocs/users.php`
**Test:**
1. Na het uitnodigen van `nieuw@school.nl` heeft de gebruiker de status "Uitgenodigd" en staat de mail in Mailpit.
2. Hetzelfde adres nog eens uitnodigen geeft "E-mailadres is al in gebruik".
3. Met `ENABLE_EMAIL=false` toont de flash-melding de link.
4. Een uitgenodigde gebruiker kan niet inloggen.

#### T3.3 Activatiepagina (`activate.php`)
- [x] Maak een publieke pagina in de stijl van `login.php`. Bij een geldig token toont hij een formulier voor gebruikersnaam (voorgevuld), naam en twee keer het wachtwoord.
- [x] Bij opslaan: wachtwoord zetten, token als gebruikt markeren, audit-regel `USER_ACTIVATED`, en doorsturen naar `login.php` met een melding.
- [x] Bij een ongeldig of verlopen token: de melding "Deze link is ongeldig of verlopen. Vraag de beheerder om een nieuwe uitnodiging."

**Na:** T3.2, T1.5
**Bestanden:** `htdocs/activate.php` (nieuw)
**Test:**
1. Na het activeren via de link kan de gebruiker inloggen en is de status "Actief".
2. Dezelfde link nog eens openen geeft de melding "ongeldig".
3. Laat `expires_at` in de database teruglopen naar het verleden: de link is dan ongeldig.
4. Een gebruikersnaam die al bestaat wordt geweigerd en het formulier blijft ingevuld.
5. Is de gebruiker na het uitnodigen gedeactiveerd, dan is de link ongeldig.

#### T3.4 Uitnodiging opnieuw versturen of intrekken
- [x] Toon bij de statussen "Uitgenodigd" en "Uitnodiging verlopen" een knop "Opnieuw versturen". Die maakt een nieuw token en maakt de oude ongeldig.
- [x] "Intrekken" verwijdert de nog niet geactiveerde gebruiker.
- [x] Schrijf de audit-regels `USER_INVITE_RESEND` en `USER_INVITE_REVOKE`.

**Na:** T3.3
**Bestanden:** `htdocs/users.php`
**Test:**
1. Na "opnieuw versturen" werkt de oude link niet meer en de nieuwe wel.
2. Na "intrekken" is de gebruiker weg en werkt de link niet meer.

### Fase 4: Wachtwoord vergeten

#### T4.1 `sendPasswordResetEmail()`
- [x] Maak een mail met de link en de geldigheid (60 minuten), plus de zin "Heb je dit niet aangevraagd? Dan kun je deze mail negeren."

**Na:** T0.3
**Bestanden:** `conf/mail_functions.php`
**Test:** Hetzelfde als T3.1.

#### T4.2 `forgot_password.php`
- [x] Maak een publieke pagina met een veld voor het e-mailadres, en voeg op `login.php` de link "Wachtwoord vergeten?" toe.
- [x] Toon altijd dezelfde melding. Pas de limieten uit 2.7 toe.
- [x] Schrijf in testmodus de link alleen naar `error_log()`.
- [x] Schrijf een audit-regel `PWD_RESET_REQUEST`, alleen als het account bestaat.

**Na:** T0.2, T1.4, T4.1
**Bestanden:** `htdocs/forgot_password.php` (nieuw), `htdocs/login.php`
**Test:**
1. Een bestaand adres, een onbekend adres en een gedeactiveerd account geven exact dezelfde melding en een vergelijkbare responstijd. Alleen bij het bestaande adres staat er een mail in Mailpit.
2. De 4e aanvraag binnen een uur voor hetzelfde adres verstuurt geen mail meer, maar de melding blijft hetzelfde.
3. Met `ENABLE_EMAIL=false` staat de link in `docker compose logs` en niet op de pagina.

#### T4.3 `reset_password.php`
- [x] Maak een pagina die bij een geldig token twee keer het nieuwe wachtwoord vraagt.
- [x] Na opslaan gebeurt het volgende (zie 2.5): token gebruikt, overige reset-tokens ongeldig, `force_password_change = 0`, `session_version` +1, audit-regel `PWD_RESET`, en doorsturen naar `login.php`.

**Na:** T4.2
**Bestanden:** `htdocs/reset_password.php` (nieuw)
**Test:**
1. Na het resetten werkt het oude wachtwoord niet meer en het nieuwe wel.
2. Een openstaande sessie in een andere browser is uitgelogd.
3. De link werkt maar één keer.
4. Als je twee keer een reset aanvraagt en de tweede link gebruikt, werkt de eerste link daarna niet meer.
5. Na 60 minuten is de link ongeldig (test door `expires_at` in de database aan te passen).

#### T4.4 Admin: reset-link sturen
- [x] Voeg per actieve gebruiker met een e-mailadres de knop "Reset-link sturen" toe in `users.php`. Gebruik `createUserToken()` en `sendPasswordResetEmail()`.
- [x] Toon de link in de flash-melding in testmodus of als de mail mislukt.
- [x] Schrijf een audit-regel `USER_SEND_RESET`.

**Na:** T4.3
**Bestanden:** `htdocs/users.php`
**Test:**
1. B ontvangt de mail en kan via de link een nieuw wachtwoord instellen.
2. Bij een gebruiker zonder e-mailadres is de knop uitgeschakeld en staat er een tooltip bij.

### Fase 5: Eigen profiel

#### T5.1 `profile.php` + menu
- [x] Maak een nieuwe pagina met de eigen gegevens (gebruikersnaam, naam, e-mail, rol, laatst ingelogd) en een formulier om de naam te wijzigen.
- [x] Vervang in `admin_header.php` het menu-item "🔑 Password" door "👤 Profiel". Het profiel linkt naar `password.php`.
- [x] Schrijf een audit-regel `PROFILE_UPDATE`.

**Na:** T1.1, T1.2
**Bestanden:** `htdocs/profile.php` (nieuw), `htdocs/admin_header.php`
**Test:**
1. Een gewijzigde naam blijft bewaard na herladen.
2. De pagina werkt voor zowel `user` als `admin`.
3. Een gebruiker met `force_password_change` wordt eerst naar `password.php` gestuurd.

#### T5.2 Eigen e-mailadres wijzigen
- [x] Voeg in `profile.php` een formulier toe met het nieuwe e-mailadres en het huidige wachtwoord. Controleer dat het adres uniek en geldig is.
- [x] Stuur via `sendEmailChangedNotice()` een melding naar het oude adres, als dat er was.
- [x] Schrijf een audit-regel `PROFILE_EMAIL_CHANGE`.

**Na:** T5.1
**Bestanden:** `htdocs/profile.php`, `conf/mail_functions.php`
**Test:**
1. Met een verkeerd wachtwoord verandert er niets.
2. Een adres dat al in gebruik is wordt geweigerd.
3. Na een geldige wijziging staat er een melding in Mailpit voor het oude adres.
4. "Wachtwoord vergeten" werkt daarna met het nieuwe adres.

#### T5.3 `password.php` afronden
- [x] Hoog na het wijzigen `session_version` op en zet de nieuwe versie in de huidige sessie.
- [x] Toon de link "← Terug naar profiel", maar niet als de wijziging verplicht is.

**Na:** T1.1, T1.5
**Bestanden:** `htdocs/password.php`
**Test:** Wijzig het wachtwoord in browser 1 terwijl je ook in browser 2 bent ingelogd. Browser 1 blijft ingelogd en browser 2 is uitgelogd.

#### T5.4 (optioneel) Nieuw e-mailadres bevestigen
- [x] Voeg tokentype `email_change` toe, met het nieuwe adres opgeslagen bij het token. Het adres verandert pas na een klik op de link in de mail naar het nieuwe adres.

**Na:** T5.2
**Test:** Het adres verandert pas na de klik, en de link werkt maar één keer.

### Fase 6: Tweestapsverificatie (TOTP)

#### T6.1 `conf/totp.php` + tests
- [x] Implementeer `base32Encode`/`base32Decode`, `totpCode($secret, $time)`, `totpVerify($secret, $code, $lastStep, $time = null)` (geeft de gebruikte stap terug, of `false`) en `totpUri($secret, $account, 'Zebrawave')`.
- [x] Maak `tests/totp_test.php`.

**Bestanden:** `conf/totp.php` (nieuw), `tests/totp_test.php` (nieuw)
**Test:** `php tests/totp_test.php` slaagt met de vectoren uit RFC 6238 (SHA1, 6 cijfers, secret `12345678901234567890`, base32 `GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ`):

| tijd (s) | code |
|---|---|
| 59 | 287082 |
| 1111111109 | 081804 |
| 1111111111 | 050471 |
| 1234567890 | 005924 |
| 2000000000 | 279037 |
| 20000000000 | 353130 |

De test controleert daarnaast:
- `base32Decode(base32Encode($x)) === $x` voor willekeurige bytes;
- de code van de vorige en de volgende stap wordt geaccepteerd, maar die van 2 stappen ernaast niet;
- een code met `step <= $lastStep` wordt geweigerd;
- invoer met spaties (`"287 082"`) wordt geaccepteerd.

#### T6.2 2FA inschakelen in het profiel
- [x] Voeg in `profile.php` de knop "2FA inschakelen" toe. Die zet een nieuw secret in de sessie (nog niet in de database) en toont de QR-code (qrcodejs, zie 2.5) en het secret als tekst.
- [x] Na een geldige code: `totp_secret` opslaan, `totp_enabled = 1`, `totp_last_step` zetten en een audit-regel `2FA_ENABLE` schrijven.

**Na:** T5.1, T6.1
**Bestanden:** `htdocs/profile.php`
**Test:**
1. Scan de code met een authenticator-app en voer de code in: 2FA staat aan.
2. Een foute code laat 2FA uit.
3. De pagina laadt geen afbeeldingen of scripts van andere domeinen dan cdnjs (controleer in het Network-tabblad van devtools).
4. Herladen tijdens het instellen maakt een nieuw secret aan, en het oude werkt dan niet meer.

#### T6.3 Herstelcodes
- [x] Maak bij het inschakelen 10 codes van het formaat `xxxxx-xxxxx`. Toon ze één keer met een knop "Kopiëren" en sla ze gehasht op.
- [x] Voeg in het profiel de knop "Nieuwe herstelcodes" toe. Die vraagt een geldige TOTP-code en vervangt alle bestaande codes.
- [x] Toon hoeveel codes er nog over zijn.

**Na:** T6.2
**Bestanden:** `htdocs/profile.php`, `conf/auth.php` of `conf/totp.php`
**Test:**
1. Er staan 10 rijen in `user_recovery_codes` en geen code staat leesbaar in de database.
2. Na het opnieuw genereren werken de oude codes niet meer.
3. De teller daalt na gebruik (dit kan pas na T6.4).

#### T6.4 Inloggen stap 2 (`login_2fa.php`)
- [x] Is 2FA aan, dan zet `login.php` na een juist wachtwoord alleen `pending_2fa_user_id` en `pending_2fa_at` en stuurt door naar `login_2fa.php`.
- [x] Die pagina accepteert een TOTP-code of een herstelcode. De tussenstap is 5 minuten geldig, na 5 fouten gaat de gebruiker terug naar `login.php`.
- [x] Bij succes worden `totp_last_step` of `used_at` bijgewerkt, wordt `completeLogin()` aangeroepen en volgt daarna zo nodig `force_password_change`.
- [x] Schrijf de audit-regels `2FA_FAILED` en `2FA_RECOVERY_USED`.

**Na:** T1.3, T1.4, T6.3
**Bestanden:** `htdocs/login.php`, `htdocs/login_2fa.php` (nieuw)
**Test:**
1. Met wachtwoord en code ben je ingelogd. Met wachtwoord en een foute code niet.
2. Na het wachtwoord direct `teacher.php` openen stuurt je naar `login.php`.
3. Dezelfde code direct opnieuw gebruiken (uitloggen en snel opnieuw inloggen) wordt geweigerd.
4. Een herstelcode werkt één keer.
5. Na 5 minuten wachten op de 2FA-pagina moet je opnieuw inloggen.
6. Een gebruiker met 2FA en `force_password_change` krijgt eerst de code en daarna `password.php`.

#### T6.5 2FA uitschakelen door de gebruiker
- [x] Voeg in het profiel de knop "2FA uitschakelen" toe. Die vraagt het huidige wachtwoord en een geldige code (of herstelcode).
- [x] Wis het secret en de codes en schrijf een audit-regel `2FA_DISABLE`.

**Na:** T6.4
**Bestanden:** `htdocs/profile.php`
**Test:**
1. Zonder geldige code staat 2FA nog aan.
2. Na het uitschakelen vraagt het inloggen geen code meer en is `user_recovery_codes` voor deze gebruiker leeg.

#### T6.6 Admin: 2FA resetten
- [x] Voeg in `users.php` of `user_edit.php` de knop "2FA resetten" toe, met een bevestigingsvraag. Die wist het secret en de codes en hoogt `session_version` op.
- [x] Schrijf een audit-regel `USER_2FA_RESET`.

**Na:** T6.4, T2.2
**Bestanden:** `htdocs/users.php` of `htdocs/user_edit.php`
**Test:**
1. B (met 2FA, ingelogd in browser 2) is na de reset uitgelogd en kan inloggen zonder code.
2. De badge "2FA" verdwijnt uit het overzicht.

#### T6.7 (optioneel) 2FA verplicht voor admins
- [x] Voeg de constante `REQUIRE_2FA_ADMIN` toe (met `defined()`-fallback naar `false`). `requireTeacher()` stuurt een admin zonder 2FA dan naar de 2FA-setup in `profile.php` en blokkeert verder alles, zoals bij `force_password_change`.

**Na:** T6.2, T1.1
**Bestanden:** `conf/auth.php`, `conf/config.php.example`, `docker/entrypoint.sh`
**Test:**
1. Met de constante op `true` kan een admin zonder 2FA alleen `profile.php` en `logout.php` openen. Na het inschakelen werkt alles weer.
2. Een `user` merkt er niets van.

### Fase 7: Afronding

#### T7.1 Documentatie bijwerken
- [x] Werk in `CLAUDE.md` de onderdelen Auth, Schema en Gotchas bij: `conf/auth.php`, `requireTeacher()` in plaats van losse checks, de nieuwe tabellen, de UTC-regel voor tijden en de map `tests/`.
- [x] Zet in `config.php.example` de nieuwe constanten, inclusief een `BASE_URL`-uitleg (die is nodig voor de links in uitnodigingen en resets).
- [x] Beschrijf Mailpit in `docker/README.md`.

**Bestanden:** `CLAUDE.md`, `conf/config.php.example`, `docker/README.md`

#### T7.2 Regressietest end-to-end
- [x] Doorloop in een verse Docker-omgeving met Mailpit: setup → uitnodigen → activeren → inloggen → 2FA aan → uitloggen → inloggen met code → wachtwoord vergeten → reset → inloggen met code → admin reset 2FA → admin deactiveert → gebruiker is uitgelogd.
- [x] Controleer dat de bestaande functies nog werken: team level-up met mail, teamchat, AI-agent "Active" en audit-log.
- [x] Controleer dat `audit_logs` geen wachtwoorden, tokens of codes bevat:
  `SELECT * FROM audit_logs WHERE description LIKE '%token%' OR description LIKE '%secret%'`.
