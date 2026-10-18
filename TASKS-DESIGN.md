# Design: analyse en taken

Doel: het leaderboard ziet er professioneler en consistenter uit, zonder het hackathon-gevoel kwijt te raken. Het moet nog steeds leuk zijn op de beamer en voor teams, en rustig en overzichtelijk voor docenten.

Randvoorwaarden (uit `CLAUDE.md`): geen framework, geen build-stap, geen Composer. Elke pagina heeft zijn eigen inline CSS/JS. UI-teksten zijn Nederlands.

---

## 1. Analyse van het huidige design

### Wat goed is
- Duidelijke basis: kaarten, afgeronde hoeken, één accentkleur. Het werkt en het oogt vriendelijk.
- Het publieke leaderboard heeft een mooie FLIP-animatie als de volgorde wijzigt, en teams krijgen confetti bij een level-up. Dat soort spelplezier houden we.
- Live updates via polling zijn al op alle relevante plekken aanwezig.
- Het spellogo komt terug als watermerk, wachtscherm en beamerscherm. Dat is een goed haakje voor branding per spel.

### Problemen

**Identiteit**
- De paarse gradient `#667eea → #764ba2` is de bekende standaardgradient uit templates. Hij zit op bijna elke pagina (body, header, badges, progress, knoppen) en maakt het geheel generiek. Er is niets "Zebrawave" of "detective/security" aan.
- Er is geen merk: de kop heet afwisselend "Leaderboard Zebrawave Studios", "Zebrawave Admin" of de spelnaam, en er is geen favicon.

**Consistentie**
- Er zijn geen gedeelde design tokens: kleuren, radii (5/6/8/10/12/15/20/25px) en schaduwen staan per pagina hardgecodeerd. Er zijn ±300 inline `style="..."`-attributen (assignments.php 50, teacher.php 45, messages.php 28).
- Componenten bestaan meerdere keren in net andere versies: drie modals (`team.php`, `assignments.php` en de overlay), twee markdown-stijlen (`.assignment-desc` en `.markdown-body`), en chatbubbels met andere kleuren voor docent en team (team-kant: team paars rechts; docent-kant: team blauw links, docent groen rechts).
- Klassen die gebruikt worden maar in de admin nergens gedefinieerd zijn: `.btn-success` (de level-up-knop heeft daardoor geen achtergrond), `.card-title`, `.team-name-link`, `.form-group`, `.progress-bg`, `.side-panel` en `.table-container`.
- Taal is gemengd: "Assignments", "Messages", "Users", "Total Teams", "View Public Leaderboard", "No teams registered yet" naast Nederlands. `admin_header.php` heeft `lang="en"`.
- Emoji dienen als iconen (📊⚙️📘✉️🤖📣🎮👥🔑📦📋👤💀🚪). Ze worden per OS anders getekend en ogen in de admin rommelig.

**Leesbaarheid en toegankelijkheid**
- Contrast is vaak te laag: `#999`/`#aaa`/`#bbb` op wit (2,8:1 of minder), wit op `#667eea` in knoppen (3,7:1, terwijl 4,5:1 nodig is), goud `#FFD700` als tekst op `#f8f9fa` voor rang #1 (1,3:1).
- Knoppen en links hebben geen zichtbare focusstijl. Status wordt vaak alleen met kleur aangegeven.
- Animaties (FLIP, confetti, wachtpuntjes) houden geen rekening met `prefers-reduced-motion`.
- Chattijden in `team.php` worden bij de eerste render ruw getoond (`$m['created_at']`, UTC), niet via `formatDateTime()`.

**Layout**
- De admin is niet responsive: de sidebar staat vast op 260px, er is geen enkele `@media`-regel en de teamtabel (6 kolommen) loopt over op een laptop met een smal venster.
- Het publieke leaderboard is een kaart van max. 1000px. Op een beamer is dat klein, en bij 15+ teams moet je scrollen, wat op een beamer niet kan.
- Op het teamdashboard zit de opdracht verstopt in een modal achter "Bekijk Opdracht", terwijl de chat het hele scherm vult. De opdracht is de hoofdinhoud.
- De admin-sidebar mengt spelpagina's, beheer, instellingen en een externe link ("💀 DARKNET CHAT", hardcoded URL) zonder groepering.

**Techniek**
- `marked` wordt geladen zonder versie (`cdn.jsdelivr.net/npm/marked/marked.min.js`). Een nieuwe major-versie kan de rendering ongemerkt breken.
- De HTML-mails hebben alleen een losse knop met inline stijl, zonder herkenbare opmaak.

---

## 2. Ontwerprichting: "Ops Room"

Het thema: een **operatiekamer van een detective-/securityteam**. Donker en technisch waar het spel gespeeld wordt (beamer, teamdashboard, wachtscherm), licht en rustig waar docenten lang werken (admin). Beide gebruiken dezelfde tokens, dus het voelt als één product.

Herkenbare elementen, met mate gebruikt:
- **Zebrastreep**: een subtiel diagonaal streeppatroon (`repeating-linear-gradient`) in kopbalken en in het actieve deel van de voortgangsbalk. Een knipoog naar Zebrawave.
- **Monospace voor "data"**: spelcodes, timers, levels, rangnummers en tokens. Dat geeft het hackersgevoel zonder dat het een terminal-parodie wordt.
- **Signaalkleur (cyaan)** voor alles wat live is: de "live"-indicator, een rij die net een level omhoog ging, de AI-status.
- **Gesegmenteerde voortgang**: de voortgangsbalk heeft één segment per opdracht, zodat je ziet "3 van 8 dossiers rond" in plaats van een los percentage.

Wat we weglaten: overal gradients, neon-glows, matrix-regen, scanlines. De lol zit in het leaderboard, de level-up en de beamer, niet in elk formulier.

### 2.1 Design tokens

Eén set CSS-variabelen, met een licht en een donker thema.

```css
:root {
  /* Merk */
  --brand:        #5B4BDB;   /* primair (licht thema); wit erop = 6,0:1 */
  --brand-hover:  #4A3BC4;
  --brand-soft:   #EEECFD;   /* achtergrond van actieve nav-items, badges */
  --signal:       #0891B2;   /* live/actief; alleen voor iconen, randen en grote tekst */

  /* Neutraal (licht thema: admin) */
  --bg:           #F4F6FB;
  --surface:      #FFFFFF;
  --surface-2:    #F8F9FC;
  --line:         #E3E7F0;
  --text:         #141A2B;
  --text-muted:   #5F6B85;   /* 5,3:1 op wit; vervangt #888/#999/#aaa */

  /* Status */
  --success: #15803D;  --success-soft: #E7F6EC;
  --warning: #B45309;  --warning-soft: #FEF3E2;
  --danger:  #B91C1C;  --danger-soft:  #FDECEC;
  --info:    #1D4ED8;  --info-soft:    #E8EEFD;

  /* Podium (als achtergrond met donkere tekst, nooit als tekstkleur) */
  --gold: #F5B700;  --silver: #B8C2D6;  --bronze: #D08A4E;

  /* Vorm */
  --radius-sm: 6px;  --radius: 10px;  --radius-lg: 16px;  --radius-pill: 999px;
  --shadow-1: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.08);
  --shadow-2: 0 12px 32px rgba(16,24,40,.18);

  /* Ruimte (basis 4px) */
  --s-1: 4px; --s-2: 8px; --s-3: 12px; --s-4: 16px; --s-5: 24px; --s-6: 32px; --s-7: 48px;

  /* Type */
  --font-sans: ui-sans-serif, system-ui, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: ui-monospace, "JetBrains Mono", "Cascadia Code", Consolas, monospace;

  /* Beweging */
  --ease: cubic-bezier(.2, .8, .2, 1);
  --t-fast: 150ms;  --t: 300ms;
}

/* Donker thema: beamer, leaderboard, teamdashboard, wachtscherm, auth-pagina's */
[data-theme="ops"] {
  --brand:       #8B7CFF;   /* 5,3:1 op --surface */
  --brand-hover: #A396FF;
  --brand-soft:  #231F4A;
  --signal:      #22D3EE;
  --bg:          #0B1020;
  --surface:     #121A2E;
  --surface-2:   #1B2540;
  --line:        #26314F;
  --text:        #E6EAF2;
  --text-muted:  #9AA4BF;   /* 7,0:1 op --surface */
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: 1ms !important; transition-duration: 1ms !important; }
}
```

Lettertypes: de systeemstack is de basis (geen externe requests, snel, privacyvriendelijk voor scholen). Optioneel komt er één display-font (bijv. Space Grotesk, OFL) **zelf gehost** in `htdocs/fonts/` voor koppen op beamer en leaderboard. Geen Google Fonts, want dan gaan de IP-adressen van leerlingen naar Google.

Typeschaal: 12 / 14 / 16 / 20 / 24 / 32 / 48px. Beamer en leaderboard gebruiken `clamp()`. Getallen (rang, timer, level) krijgen `font-variant-numeric: tabular-nums`, zodat ze niet verspringen als ze veranderen.

### 2.2 Hoe de stijl gedeeld wordt

`CLAUDE.md` zegt: geen gedeelde CSS-bestanden tenzij gevraagd. Voorstel dat daarbinnen blijft:

- `conf/theme.php` met `themeStyles(): string`, die één inline `<style>`-blok teruggeeft met de tokens, de reset en de basiscomponenten. Elke pagina roept `<?= themeStyles() ?>` aan in de `<head>` en houdt daarnaast zijn eigen pagina-CSS. `admin_header.php` gebruikt hem ook.
- `conf/theme.php` krijgt ook `icon(string $name): string` met ±25 inline SVG-iconen (paden overgenomen uit Lucide, ISC-licentie), zodat de admin geen emoji meer als icoon gebruikt.

**Open beslissing:** een echt bestand `htdocs/assets/app.css` is eenvoudiger en wordt door de browser gecachet. Kies dat alleen als je die regel in `CLAUDE.md` wilt aanpassen.

### 2.3 Componenten

Eén versie van elk, in `themeStyles()`:

| Component | Varianten / opmerkingen |
|---|---|
| `.btn` | `primary`, `secondary` (rand), `ghost`, `success`, `danger` (zacht), `icon` (vierkant, met `title` + `aria-label`); maten `sm`/`md`/`lg`; `:focus-visible`-ring 3px `--brand` 40% |
| `.badge` | status: `draft` grijs, `running` groen met pulserende stip, `paused` oranje, `finished` blauw; AI-modus; verdict (`onvoldoende`/`voldoende`/`goed`). Altijd tekst + kleur, nooit alleen kleur. `gameStatusBadge()` gaat klassen gebruiken in plaats van inline stijl |
| `.card` + `.card-title` + `.card-actions` | één padding (24px), één radius, `--shadow-1` |
| `.stat` | label (12px, uppercase, `--text-muted`) + waarde (mono, 28px) + optionele trend/hint |
| `.table` | sticky kop, zebrastrepen uit, hover-rij, `.table-compact`, rechts uitgelijnde actiekolom; in een `.table-scroll`-wrapper voor smalle schermen |
| `.field` | label, input, hint, foutmelding; vervangt `.form-group`/`.form-row` |
| `.alert` | `success`/`error`/`warning`/`info`; `renderFlash()` en de testmodus-banner gebruiken dit |
| `<dialog class="modal">` | native `<dialog>` (focus, Esc en backdrop gratis); vervangt de drie modal-implementaties |
| `.progress` | gesegmenteerd (één segment per opdracht), actief segment met zebrastreep-animatie, `finished` volledig groen |
| `.rank` | cirkel met nummer; 1/2/3 met podiumkleur als achtergrond en donkere tekst |
| `.bubble` | `team`, `teacher`, `ai`, `suggestion`, `escalation`, `info`; dezelfde kleuren aan team- en docentkant |
| `.markdown` | één stijl voor opdrachten, mededelingen en de AI-dialoog |
| `.empty` | icoon + zin + optionele actie, voor "nog geen teams", "geen berichten" enz. |
| `.page-header` | titel, subtitel (spelnaam + status-badge) en acties rechts |

---

## 3. Schermontwerpen

### 3.1 Publiek leaderboard (`index.php`) en beamer

Donker thema, schermvullend, gemaakt om van 5 meter afstand te lezen.

```
┌───────────────────────────────────────────────────────────────────────┐
│ [logo] OPERATION GAMEPLAY        ● LIVE   ⏱ 01:23:45   12 teams  [QR] │
├───────────────────────────────────────────────────────────────────────┤
│   ┌──────2──────┐ ┌──────1──────┐ ┌──────3──────┐                     │
│   │ De Speurders│ │  Byte Club  │ │  Null Ptrs  │   ← podium (top 3)  │
│   │  Level 6/8  │ │ ✔ Finish    │ │  Level 5/8  │                     │
│   └─────────────┘ └─────────────┘ └─────────────┘                     │
│  4  Root Kit        ▰▰▰▰▱▱▱▱  Level 5/8                               │
│  5  Hashbrowns      ▰▰▰▰▱▱▱▱  Level 5/8   ⬆ zojuist                   │
│  6  ...                                                                │
├───────────────────────────────────────────────────────────────────────┤
│ ⬆ Hashbrowns → level 5 · 14:02    ⬆ Byte Club → finish · 13:58  …    │
└───────────────────────────────────────────────────────────────────────┘
```

- Kopbalk: logo, spelnaam, live-indicator (cyaan stip), resterende tijd (mono, laatste 10 minuten rood), aantal teams, en een kleine QR-code naar `join.php` zolang de registratie open is.
- Top 3 als podium; de rest als compacte rijen met gesegmenteerde voortgang.
- Past altijd op één scherm: rijhoogte schaalt mee, en boven ±12 teams gaat de lijst in twee kolommen.
- Level-up: de rij licht 3 seconden cyaan op en schuift met de bestaande FLIP-animatie omhoog; onderaan loopt een ticker met de laatste level-ups.
- Toestanden: `draft` = logo + "Binnenkort van start" + spelcode; `paused` = gedimde lijst met een duidelijke pauzebalk; `finished` = "Eindstand" met het podium groot in beeld.

### 3.2 Teamdashboard (`team.php`)

Donker thema. De opdracht wordt de hoofdinhoud, de chat staat ernaast.

```
┌───────────────────────────────────────────────────────────────────────┐
│ [logo] Team Byte Club      #2 · Level 4/8 · ⏱ 01:23:45   📣(1)  🏆   │
│ ▰▰▰▱▱▱▱▱                                                              │
├──────────────────────────────────────┬────────────────────────────────┤
│ DOSSIER 4 — De verdwenen laptop      │ Berichten          [A+]        │
│ Verwachte tijd: 20 min               │  ┌──────────────┐              │
│                                      │  │ Docent 13:40 │              │
│ (markdown-beschrijving)              │  └──────────────┘              │
│                                      │          ┌──────────────┐      │
│ ┌ INSTRUCTIE ──────────────────────┐ │          │ Jullie 13:42 │      │
│ │ ...                              │ │          └──────────────┘      │
│ └──────────────────────────────────┘ │                                │
│ [⬇ Bestanden downloaden]  3/10       │ [ antwoord typen…        ] [➤] │
└──────────────────────────────────────┴────────────────────────────────┘
```

- Desktop: twee kolommen (dossier links, chat rechts). Mobiel: tabs "Dossier" en "Chat", met een badge op Chat bij een nieuw bericht.
- Kopbalk: rang, level, timer en een dunne gesegmenteerde voortgangsbalk.
- Chat: bubbels met afzender en lokale tijd, `Ctrl/⌘+Enter` om te versturen, de verstuurknop is uitgeschakeld tijdens het versturen, en nieuwe berichten worden voorgelezen via `aria-live="polite"`.
- Level-up-overlay: "DOSSIER 4 GESLOTEN" met de nieuwe rang, confetti, en daarna een zachte overgang naar het nieuwe dossier. Bij `prefers-reduced-motion` alleen de tekst.
- Wacht-, pauze- en eindscherm in dezelfde stijl als de beamer.

### 3.3 Admin (docenten)

Licht thema, met een donkere sidebar als verbinding met de rest.

- **Sidebar** (`--bg` van het ops-thema): logo + "Zebrawave", spelkiezer met status-badge, en gegroepeerde navigatie met SVG-iconen:
  - *Spel*: Dashboard, Berichten (badges), Opdrachten, Mededelingen, AI-logboek, Spelinstellingen
  - *Beheer* (admin): Spellen, Gebruikers, API-sleutels, Archief, Auditlog
  - *Tools*: Darknet-chat (extern-icoon; URL naar `config.php` als `DARKNET_CHAT_URL`, verborgen als die leeg is)
  - Onderaan: gebruiker (naam + rol), Profiel, Uitloggen, en de AI-agentstatus als pill met stip (groen actief / oranje model onbereikbaar / grijs inactief).
- **Responsive**: onder 1000px klapt de sidebar in tot een topbalk met hamburger; tabellen scrollen horizontaal in hun eigen wrapper.
- **Paginakop** overal hetzelfde: titel, spelnaam + badge, acties rechts ("Publiek leaderboard ↗", "Beamer ↗").
- **Dashboard**: vier stat-tegels (Teams, Opdrachten, Ongelezen, Docent nodig; de laatste oranje als > 0). Teamtabel met kolommen rang, team (naam + e-mail + tijd + "Docent nodig"-badge), level, voortgang, AI-modus en acties. De acties worden `⬆` en `⬇` als icon-knoppen en een `⋯`-menu voor link kopiëren, link mailen en verwijderen, zodat de rij rustig blijft.
- **Berichten**: drie zones (teamlijst met zoekveld en badges | chat | AI-paneel met de evaluatie). Dezelfde bubbelkleuren als aan de teamkant.

### 3.4 Auth- en aanmeldpagina's

`login.php`, `login_2fa.php`, `activate.php`, `forgot_password.php`, `reset_password.php`, `setup.php`, `join.php` en `choose_game.php` krijgen één layout: het donkere ops-thema met een gecentreerde kaart, het logo erboven en één primaire knop. `join.php` toont het spellogo en een groot mono-invoerveld voor de spelcode.

### 3.5 E-mail

Eén eenvoudige, tabelgebaseerde HTML-template in `mail_functions.php`: een kopbalk in merkkleur met "Zebrawave", witte inhoud, een knop met voldoende contrast (`#5B4BDB`), de link als tekst eronder en een grijze voetregel. Alles inline gestyled (mailclients), en de tekstversie (`AltBody`) blijft.

---

## 4. Taken

Volgorde: eerst het fundament, daarna de schermen die tijdens een hackathon het meest gezien worden.

### Fase 0: Fundament
- [x] `conf/theme.php` met `themeStyles()` (tokens uit §2.1, reset, basiscomponenten uit §2.3) en `icon()`. *Klaar als:* één include levert alle tokens en componenten, en `php -l` slaagt.
- [x] `admin_header.php` gebruikt `themeStyles()`. Oude kleuren in de header vervangen door tokens, `lang="nl"`.
- [x] Ontbrekende klassen definiëren of vervangen: `.btn-success`, `.card-title`, `.team-name-link`, `.form-group`, `.progress-bg`, `.side-panel`, `.table-container`.
- [x] `gameStatusBadge()` en `renderFlash()` gebruiken klassen (`.badge`, `.alert`) in plaats van inline stijl.
- [x] `marked` op een vaste versie zetten (bijv. `marked@12.0.2`), op elke plek waar hij geladen wordt.
- [x] Favicon (SVG, zebrastreep in een vergrootglas of iets dergelijks) en `<meta name="theme-color">`.

### Fase 1: Publiek leaderboard en beamer
- [x] `index.php` naar het ops-thema met de layout uit §3.1 (kopbalk, podium, compacte rijen, gesegmenteerde voortgang).
- [x] De AJAX-respons uitbreiden met `seconds_left` en de laatste level-ups (teamnaam, level, tijd; alleen publieke velden) voor de timer en de ticker.
- [x] Altijd passend op één scherm: schalende rijhoogte en twee kolommen boven ±12 teams.
- [x] Highlight bij een level-up, met respect voor `prefers-reduced-motion`.
- [x] Toestanden `draft`/`paused`/`finished` en het lege scherm ("Nog geen teams") ontwerpen.
- [x] `beamer.php` in dezelfde stijl (logo, spelcode in mono, QR-code op een witte kaart).
- [x] Contrast: rang-kleuren als achtergrond met donkere tekst; geen witte tekst meer op een lichte voortgangsbalk.

### Fase 2: Teamdashboard
- [x] Twee-koloms layout dossier + chat (§3.2), met tabs op mobiel. Het opdrachtmodal vervalt.
- [x] Kopbalk met rang, level, timer en voortgangsbalk; de knoppen "Mededelingen" en "Leaderboard" als ghost-knoppen.
- [x] Chatbubbels via `.bubble`; tijden via `formatDateTime()` (bugfix: nu ruw UTC bij de eerste render); `Ctrl/⌘+Enter` om te versturen; `aria-live`.
- [x] Level-up-overlay, wacht-, pauze- en eindscherm in de nieuwe stijl; inline `style=` eruit.
- [x] Mededelingen en welkomstbanner via `<dialog>` en `.alert`.

### Fase 3: Admin-shell
- [x] Sidebar zoals in §3.3: gegroepeerd, SVG-iconen, gebruikersblok en AI-status als pill.
- [x] Alle navigatielabels en kopteksten in het Nederlands (Opdrachten, Berichten, Gebruikers, API-sleutels, Auditlog, "Publiek leaderboard").
- [x] Darknet-chat-URL naar `config.php.example` (`DARKNET_CHAT_URL`), met een extern-icoon.
- [x] Responsive: een ingeklapte sidebar onder 1000px, en `.table-scroll` rond alle tabellen.
- [x] `.page-header`-component op alle admin-pagina's.
- [x] Testmodus-banner via `.alert.warning`.

### Fase 4: Admin-pagina's opschonen
Per pagina: inline `style=` vervangen door componentklassen, emoji-iconen door `icon()`, Engelse teksten door Nederlandse, en lege toestanden via `.empty`.
- [x] `teacher.php`: stat-tegels, teamtabel met `⋯`-actiemenu, panelen "Nieuw team" en "AI voor alle teams".
- [x] `messages.php`: drie zones, zoekveld in de teamlijst, bubbels gelijk aan de teamkant, AI-paneel met verdict-badges.
- [x] `assignments.php`: `<dialog>` in plaats van het eigen modal, `.markdown` en een nettere rubric-tabel.
- [x] `game.php`: secties als kaarten met `.field`; de gevarenzone duidelijk gescheiden en rood omrand.
- [x] `ai_log.php`, `announcements.php`, `archive.php`, `audit.php`: tabellen via `.table` en filters in een toolbar.
- [x] `games.php`, `users.php`, `user_edit.php`, `api_keys.php`, `profile.php`, `password.php`.

### Fase 5: Auth en aanmelden
- [x] Eén auth-layout (§3.4) voor `login.php`, `login_2fa.php`, `activate.php`, `forgot_password.php`, `reset_password.php`, `setup.php`, `confirm_email.php`.
- [x] `join.php` en `choose_game.php` in dezelfde stijl, met spellogo en een spelcodeveld in mono.

### Fase 6: E-mail
- [x] HTML-mailtemplate zoals in §3.5; testen in Mailpit (licht en donker).

### Fase 7: Afwerking en toegankelijkheid
- [x] Contrastcheck van alle tekst ≥ 4,5:1 (grote tekst ≥ 3:1), in beide thema's.
- [x] Zichtbare `:focus-visible` op alle interactieve elementen; alles bedienbaar met het toetsenbord.
- [x] `prefers-reduced-motion` voor FLIP, confetti, wachtpuntjes en de zebrastreep-animatie.
- [x] Iconknoppen hebben `aria-label`; status nooit alleen via kleur.
- [x] Optioneel: donker thema voor de admin via `prefers-color-scheme` (de tokens zijn er al klaar voor).
- [x] Controle: `grep -c 'style="' htdocs/*.php` is sterk gedaald, en `#667eea` komt nergens meer voor.

---

## 5. Open beslissingen

1. **Gedeelde stijl**: inline via `themeStyles()` (past bij `CLAUDE.md`) of een echt `htdocs/assets/app.css` (cache, eenvoudiger)? Voorstel: `themeStyles()`.
2. **Merkkleur**: violet `#5B4BDB`/`#8B7CFF` als evolutie van het huidige paars, of een kleur die past bij het Zebrawave-logo (als dat er is)?
3. **Display-font**: alleen de systeemstack, of één zelf gehost font voor koppen op de beamer?
4. **Teamdashboard**: mag het opdrachtmodal verdwijnen ten gunste van de twee-koloms layout? Dat is de grootste UX-wijziging in dit plan.

---

## 6. Uitvoering (2026-10-18)

Alle taken uit §4 zijn uitgevoerd. Gekozen bij de open beslissingen (§5), steeds het voorstel:

1. **Gedeelde stijl**: inline via `themeStyles()` en `themeScript()` in `conf/theme.php` (geen `app.css`).
2. **Merkkleur**: violet `#5B4BDB` / `#8B7CFF`.
3. **Display-font**: alleen de systeemstack; geen zelf gehost font.
4. **Teamdashboard**: het opdrachtmodal is weg; dossier en chat staan naast elkaar (tabs op mobiel).

Afwijkingen en aanvullingen ten opzichte van het plan:

- **Contrast** (nagerekend voor beide thema's): `--success` in het lichte thema is `#166534` (op `--success-soft` 6,4:1; `#15803D` haalde 4,49:1). Nieuw: `--line-strong` (`#848EA5`, randen van invoervelden ≥ 3:1), `--on-brand`/`--on-success` (donkere tekst op de lichtere ops-kleuren) en `--signal-text` (`#0E7490` voor tekst op `--signal-soft`).
- **Dashboard**: level en voortgang staan in één kolom, zodat de teamtabel op 1440 px naast het zijpaneel past; onder 1280 px komen de panelen onder de tabel.
- **Berichten**: het AI-paneel (modus, escalatie, tussenstand, opdracht) is onder 1400 px een inklapbaar blok boven de chat. De beoordelingskaarten met knoppen blijven in de chat staan, bij het bericht waar ze over gaan.
- **Leaderboard**: wordt in JS opgebouwd uit dezelfde JSON als de AJAX-respons (met een `<noscript>`-lijst); de ticker komt uit `completed_assignments` van teams die nog in het spel zitten.
- **Darknet-chat**: `DARKNET_CHAT_URL` staat in `config.php.example` en Docker standaard leeg, dus de link is verborgen tot je hem invult (de oude URL staat als commentaar in het voorbeeld).
- **`api_keys.php`** kreeg bij het opschonen ook CSRF, PRG en `logAudit()`.
- Inline `style="`-attributen in `htdocs/*.php`: van 292 naar 56 (vooral marges).

