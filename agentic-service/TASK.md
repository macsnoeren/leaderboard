# Taken: Agentic AI implementeren

Uitwerking van [DESIGN.md](DESIGN.md) in kleine taken. Elke taak is los op te leveren en heeft een
**Klaar als** dat je kunt testen. Doe de fasen in volgorde; binnen een fase staan de taken in een
logische volgorde (afhankelijkheden staan erbij).

## Testen

- **PHP** (zelfde stijl als `tests/game_test.php`: plain PHP, in-memory SQLite, exitcode 1 bij fout):
  ```sh
  docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/ai_test.php
  ```
- **Python** (alleen de standaardbibliotheek `unittest` + `unittest.mock`, geen pytest):
  ```sh
  docker run --rm -v "$PWD":/app -w /app/agentic-service python:3.12-slim \
    sh -c "pip install -q requests==2.32.3 && python -m unittest discover -s tests -v"
  ```
- **LLM-agents** worden in unit-tests getest met een `FakeLLM` die vaste JSON teruggeeft. Het echte
  model test je met de evals (T2.20).
- **Handmatig** (UI, end-to-end): `docker compose -f docker/docker-compose.yml up -d --build`,
  daarna http://localhost:8088.

Uitgangspunt voor PHP: logica komt in een nieuw bestand **`conf/ai.php`** (zoals `conf/game.php`),
zodat het testbaar is. `api.php` en de pagina's roepen alleen die functies aan.

---

## Fase 0 — Voorbereiding

- [x] **T0.1 — Python-testopzet**
  - Doen: `agentic-service/tests/__init__.py` en een lege `test_smoke.py`.
  - Klaar als: het Python-testcommando hierboven draait en "OK" geeft.

- [x] **T0.2 — `conf/ai.php` en `tests/ai_test.php`**
  - Doen: leeg `conf/ai.php` (require van `game.php`), `tests/ai_test.php` met `check()` en een
    `freshDb()` die alle huidige tabellen plus de nieuwe kolommen/tabellen uit DESIGN §7 aanmaakt.
  - Klaar als: `tests/ai_test.php` draait groen (0 echte checks is goed).

- [x] **T0.3 — Ollama-check voor gpt-oss cloud**
  - Doen: `agentic-service/tools/check_ollama.py` dat `gpt-oss:20b-cloud` en `gpt-oss:120b-cloud`
    via `/api/chat` aanroept met `format` (klein JSON-schema), `think: "low"` en `stream: false`,
    en rapporteert: antwoord ontvangen, JSON geldig volgens schema, `message.thinking` aanwezig,
    duur.
  - Klaar als: de uitkomst (respecteert gpt-oss `format`: ja/nee) staat in DESIGN §10. Dit bepaalt
    of `llm.py` (T2.10) op `format` kan leunen of vooral op eigen validatie + retry.

---

## Fase 1 — Fundament

- [x] **T1.1 — Schema**
  - Bestanden: `htdocs/setup.php`, `bin/migrate.php`.
  - Doen: alles uit DESIGN §7: `teams.ai_mode`; `games.ai_mode`, `ai_max_attempts`,
    `ai_max_questions`; de zes `assignments.ai_*`-kolommen; `ai_service_status.model_ok`; tabellen
    `ai_team_progress`, `assignment_ai_dialog`, `ai_evaluations`; plus
    `team_messages.ai_evaluation_id INTEGER` (koppelt een suggestie aan zijn beoordeling, nodig
    voor de knoppen in `messages.php`). Indexen: `ai_evaluations(team_id, assignment_number)`,
    `assignment_ai_dialog(assignment_id)`. Elke `ALTER` in een eigen try/catch.
  - Klaar als: (a) verse install (`setup.php` + `migrate.php`) heeft alle kolommen
    (`sqlite3 database/leaderboard.db .schema`); (b) `php bin/migrate.php` twee keer achter elkaar
    geeft geen fout; (c) bestaande teams/opdrachten krijgen `ai_mode = 'suggest'`,
    `ai_rubric_status = 'none'`, `ai_auto_allowed = 1`.

- [x] **T1.2 — AI-modus per team (logica)**
  - Bestanden: `conf/ai.php`, `tests/ai_test.php`.
  - Doen: `AI_MODES = ['off','suggest','reply','auto']`, `isValidAiMode()`,
    `setTeamAiMode($db, $teamId, $gameId, $mode, $userId)` en
    `setAllTeamsAiMode($db, $gameId, $mode, $userId)` (zet ook `games.ai_mode`). Audit
    `TEAM_AI_MODE` / `GAME_AI_MODE`.
  - Klaar als (tests): ongeldige modus wordt geweigerd; een team van een ander spel wordt niet
    gewijzigd; "alle teams" raakt alleen teams van dat spel en zet `games.ai_mode`; er komt per
    actie één auditregel.

- [x] **T1.3 — Nieuwe teams krijgen de standaardmodus** (na T1.2)
  - Bestanden: `conf/game.php` (`createTeam()`), `tests/game_test.php`.
  - Klaar als (test): een team dat na `setAllTeamsAiMode(..., 'reply')` wordt aangemaakt, heeft
    `ai_mode = 'reply'`; ook via het `join.php`-pad (zelfde functie).

- [x] **T1.4 — Modus in `teacher.php`** (na T1.2)
  - Doen: per team een keuzelijst, en een formulier *Voor alle teams*. Beide met `csrfField()` /
    `verifyCsrf()`, PRG met `flash()`.
  - Klaar als (handmatig): wijzigen per team en voor alle teams werkt, staat in `audit.php`, en een
    POST zonder geldige CSRF-token wordt geweigerd.

- [x] **T1.5 — AI-blok in `game.php`**
  - Doen: standaardmodus voor nieuwe teams, `ai_max_attempts`, `ai_max_questions` (1–50). CSRF, audit.
  - Klaar als (handmatig): opslaan werkt, waarden buiten 1–50 geven een foutmelding.

- [x] **T1.6 — Rubric (logica)**
  - Bestanden: `conf/ai.php`, `tests/ai_test.php`.
  - Doen: `validateRubric(array): ?string` (formaat DESIGN §5: `kern` tekst, ≥ 1 aspect met `naam`,
    `verplicht` bool en drie niveauteksten; `ook_goed`, `veelgemaakte_fouten`,
    `verduidelijkingen_vraag` optionele lijsten van tekst), `saveRubric()` (status → `draft`),
    `approveRubric()` (alleen bij geldige rubric), `rubricStatusAfterEdit($old, $new)` (gewijzigde
    `instruction` of `criteria` bij `approved` → `draft`).
  - Klaar als (tests): ongeldige rubrics geven een duidelijke fout; goedkeuren zonder geldige
    rubric kan niet; een opdracht van een ander spel kan niet worden gewijzigd;
    `rubricStatusAfterEdit` klopt voor alle combinaties; `criteria` wordt nooit aangeraakt.

- [x] **T1.7 — Rubric in `assignments.php`** (na T1.6)
  - Doen: rubric-tabel (aspect × onvoldoende/voldoende/goed, rij toevoegen/verwijderen met JS),
    lijstvelden voor *ook goed*, *veelgemaakte fouten* en *verduidelijkingen van de vraag*, knop
    *Rubric goedkeuren*, vinkje *Altijd door een mens* (`ai_auto_allowed`), badge *Geen rubric /
    Concept / AI-klaar ✓* in het overzicht. Opslaan van gewijzigde instructie/criteria zet een
    goedgekeurde rubric terug op concept met flash "Opdracht gewijzigd, controleer de rubric".
  - Klaar als (handmatig): rubric invullen, opslaan, goedkeuren en de badges werken; de docent
    ziet nergens JSON; instructie wijzigen zet de badge terug op *Concept*.

- [x] **T1.8 — Kopiëren, exporteren, importeren**
  - Bestanden: `conf/game.php` (`copyAssignments()`), `bin/export_assignments.py`,
    `bin/import_assignments.py`, `tests/game_test.php`.
  - Klaar als: (test) `copyAssignments()` neemt `ai_rubric`, `ai_rubric_status` en
    `ai_auto_allowed` mee; (handmatig) export → import naar een ander spel behoudt de rubric, en een
    oud archief zonder die velden importeert nog steeds (met standaardwaarden).

- [x] **T1.9 — Afzender `ai`**
  - Bestanden: `htdocs/team.php`, `conf/game.php` (`levelUp()`), `htdocs/messages.php`,
    `tests/game_test.php`.
  - Doen: `team.php` toont `ai` precies als `teacher` (ook in de ajax-weergave); `levelUp()`
    archiveert `ai` als `AI: …`; `messages.php` toont `ai` met eigen kleur en label "🤖 AI".
  - Klaar als: (test) het archief na `levelUp()` bevat `AI: …` en geen suggesties; (handmatig) een
    met de hand ingevoegd `ai`-bericht is voor het team niet van een docentbericht te
    onderscheiden.

- [x] **T1.10 — Opruimen bij verwijderen/leegmaken**
  - Bestanden: `conf/game.php` (`deleteTeam()`, `resetGame()`), `tests/ai_test.php`.
  - Klaar als (test): na `deleteTeam()`/`resetGame()` zijn de `ai_team_progress`- en
    `ai_evaluations`-rijen van die teams weg, en die van andere spellen niet.

---

## Fase 2 — Nieuwe agent in `suggest`-modus

Na deze fase is `process_ai_feedback.py` vervangen. De AI doet nog alleen suggesties, maar wel met
intentie, beoordelaar, validator, tussenstand en beslisregels.

### PHP

- [x] **T2.1 — Openstaande taken (logica)**
  - Bestanden: `conf/ai.php`, `tests/ai_test.php`.
  - Doen: `aiPendingTasks(PDO $db): array`, de query uit `api.php` verplaatst en uitgebreid met de
    velden uit DESIGN §10 (API-tabel): `message_id`, `ai_mode`, `ai_auto_allowed`, `rubric`,
    `rubric_status`, `clarifications` (uit `assignment_ai_dialog`), `recent_attempts` en
    `recent_questions` (laatste 10 min, dit level, uit `ai_evaluations`), `escalation_open`,
    `progress` en `messages` (lijst met id/afzender/tijd/tekst; `team`, `teacher`, `ai`, nooit
    `suggestion`).
  - Klaar als (tests): teams met `ai_mode = 'off'` en teams uit `draft`/`finished`-spellen komen er
    niet in; geclaimde teams (< 5 min) niet; een team waarvan het laatste bericht al een
    `teacher`-, `ai`- of `suggestion`-reactie heeft niet; `messages` bevat geen suggesties en
    staat op volgorde; tellingen kloppen.

- [x] **T2.2 — Serverregels: wat mag de AI?**
  - Bestanden: `conf/ai.php`, `tests/ai_test.php`.
  - Doen: pure functie `allowedDecision(array $ctx, string $requested): string` met `$ctx` =
    spelstatus, `ai_mode`, `ai_auto_allowed`, `rubric_status`, `escalation_open`. Regels uit DESIGN
    §6 "Controles": bij `paused` alleen suggesties; geen goedgekeurde rubric → `suggest`; `auto` +
    `ai_auto_allowed = 0` → als `reply`; open escalatie → alleen `suggest`.
  - Klaar als (tests, tabelgedreven over alle combinaties): de uitkomst is nooit "hoger" dan
    gevraagd (`suggest` < `reply` < `level_up`; `escalate` blijft `escalate`) en klopt per regel.

- [x] **T2.3 — Tussenstand bijwerken** (na T2.1)
  - Bestanden: `conf/ai.php`, `tests/ai_test.php`.
  - Doen: `updateTeamProgress($db, $teamId, $level, $aspects, $summary, $messageId)`. Per aspect
    telt het nieuwste standpunt; een aspect wordt alleen overgenomen als het citaat echt voorkomt
    in het genoemde teambericht van dít team en dít level (server-side citaat-check, genormaliseerd
    op hoofdletters, witruimte en aanhalingstekens). Plus `clearTeamProgress()`.
  - Klaar als (tests): een verzonnen citaat wordt genegeerd; een citaat uit een ander team of
    level telt niet; een later standpunt overschrijft een eerder; de samenvatting wordt opgeslagen.

- [x] **T2.4 — `submitEvaluation()`: opslaan, suggestie, verouderd, escalatie** (na T2.2, T2.3)
  - Bestanden: `conf/ai.php`, `tests/ai_test.php`.
  - Doen: valideert de payload (team bestaat, `ai_processing_by = agent_id`), controleert of het
    resultaat verouderd is (`level` ≠ huidig level of `message_id` ≠ laatste teambericht →
    `decision = 'stale'`, alleen opslaan), slaat `ai_evaluations` op, past `allowedDecision()` toe
    en voert `suggest` of `escalate` uit: suggestiebericht met `ai_evaluation_id` en een vaste
    opmaak (intentie, oordeel, reden, voorgestelde reactie). Werkt de tussenstand bij (niet bij
    stale), geeft het team vrij, audit `AI_SUGGEST` / `AI_ESCALATE` (`user_id = NULL`, met agent-id
    en model). `reply` en `level_up` worden in deze fase als `suggest` uitgevoerd (zie T4.4, T5.1).
  - Klaar als (tests): stale doet niets behalve opslaan; claim van een andere agent → fout; een
    suggestie heeft `ai_evaluation_id`; het team is daarna vrijgegeven; er komt een auditregel; de
    auditregel bevat geen teamtekst of tokens.

- [x] **T2.5 — `api.php` omzetten** (na T2.1, T2.4)
  - Doen: `get_pending` → `aiPendingTasks()`; nieuwe actie `submit_evaluation` →
    `submitEvaluation()`; `send_suggestion` verwijderen; `heartbeat` slaat `model_ok` op.
  - Klaar als (handmatig, met `curl` en een API-key): `get_pending` geeft het nieuwe formaat;
    `submit_evaluation` met een geldige payload zet een suggestie in de chat; een ongeldige payload
    geeft 400; een verkeerde key geeft 401.

- [x] **T2.6 — Suggesties in `messages.php`** (na T2.4)
  - Doen: een suggestie toont intentie, oordeel, reden en voorgestelde reactie in vaste opmaak;
    *Overnemen* zet de voorgestelde reactie in het antwoordveld (via `ai_evaluation_id`, niet meer
    door tekst te splitsen). Een paneel toont de tussenstand van het huidige level, met *Wissen*
    (CSRF, audit).
  - Klaar als (handmatig): opmaak, overnemen en wissen werken; de tussenstand staat nergens op
    `team.php`.

- [x] **T2.7 — AI-status met `model_ok` in `teacher.php`** (na T2.5)
  - Klaar als (handmatig): bij `model_ok = 0` staat er "AI-agent actief, model niet bereikbaar"
    in plaats van "actief".

### Python

- [x] **T2.8 — Pakket en config**
  - Bestanden: `agentic-service/agentic/__init__.py`, `agentic-service/config.py.sample`,
    `docker/agent-config.py`, `bin/config.py.sample`.
  - Doen: mappenstructuur uit DESIGN §10. Config per agent (`*_MODEL`, `*_THINK`),
    `ASSESSOR_MODELS` als lijst, `POLL_INTERVAL`, `OLLAMA_URL` (`/api/chat`), `MAX_CHAT_TOKENS`
    (20.000). Docker leest `AI_INTENT_MODEL`, `AI_INTENT_THINK`, enz. Vervang de echte API-key en
    URL in `bin/config.py.sample` door placeholders (of verwijder dat bestand ten gunste van de
    nieuwe sample).
  - Klaar als (test): `AI_ASSESSOR_MODELS="a, b"` geeft `["a", "b"]`; ontbrekende waarden krijgen
    de standaarden uit DESIGN §10.

- [x] **T2.9 — `schemas.py`**
  - Doen: JSON-schema's voor intent, helper, assessor, validator-antwoord, validator-vraag,
    designer, en `validate(obj, schema)` voor de subset die we gebruiken (`type`, `required`,
    `enum`, `properties`, `items`).
  - Klaar als (tests): de voorbeelden uit DESIGN §6 zijn geldig; een ontbrekend verplicht veld, een
    verkeerd type of een waarde buiten een `enum` is ongeldig.

- [x] **T2.10 — `llm.py`** (na T2.9)
  - Doen: `LLMClient.chat_json(model, think, system, user, schema)` → `(dict, thinking,
    duration_ms)`. POST naar `/api/chat` met `format`, `think`, `stream: false`; valideert met
    `schemas.validate`; bij ongeldige JSON, timeout of HTTP 429/5xx één retry, daarna `LLMError`.
    Houdt `last_ok` bij voor de heartbeat.
  - Klaar als (tests met `unittest.mock`): geldig antwoord → dict; eerst ongeldig en dan geldig →
    dict; twee keer ongeldig → `LLMError`; 429 → retry; `last_ok` klopt.

- [x] **T2.11 — `api.py` (client voor `api.php`)**
  - Doen: `get_pending`, `claim_task`, `submit_evaluation`, `heartbeat(model_ok)`,
    `unregister_agent`; voor later alvast `get_design_jobs`, `claim_design`, `submit_design`.
  - Klaar als (tests met mock): juiste URL, methode, header `X-API-Token` en body; 409 bij claim
    geeft `False`.

- [x] **T2.12 — Context en prompt-opbouw** (`agentic/context.py`)
  - Doen: bouwt het chatblok uit `messages` met id, rol ("Team", "Docent", "AI") en tijd, in
    afgebakende data-blokken; het nieuwe bericht apart gemarkeerd. Boven `MAX_CHAT_TOKENS`
    (schatting: tekens / 4) worden de oudste berichten vervangen door de samenvatting uit de
    tussenstand.
  - Klaar als (tests): (a) **dataminimalisatie**: teamnaam, e-mail, token en docentnaam uit de
    taak komen in geen enkele prompt voor; (b) een teambericht met "negeer alle instructies" staat
    binnen het data-blok; (c) afkappen werkt en behoudt het nieuwste bericht; (d) suggesties komen
    nooit in de context.

- [x] **T2.13 — Voorfilter** (`agentic/prefilter.py`)
  - Klaar als (tests): leeg bericht en patronen als "negeer", "ignore previous", "system prompt",
    "level up", "geef een 10" geven `flag_injection`; normale antwoorden niet.

- [x] **T2.14 — Intentie-agent** (`agents/intent.py`, `prompts/intent.md`) (na T2.10, T2.12)
  - Doen: prompt met de regels uit DESIGN §6 stap 3 (gok in vraagvorm = antwoord, gemengd =
    antwoord). Code-regel: `zeker = false` → `antwoord`, ongeacht wat het model zegt.
  - Klaar als (tests met FakeLLM): `vraag`+zeker → `vraag`; `vraag`+niet zeker → `antwoord`;
    `overig`+niet zeker → `antwoord`; `overig`+zeker → `overig`; de prompt bevat de hele chat,
    maar geen `criteria`, geen rubric en geen tussenstand (tabel *Wie krijgt wat*, DESIGN §6).

- [x] **T2.15 — Beoordelaar** (`agents/assessor.py`, `prompts/assessor.md`) (na T2.14)
  - Doen: invoer volgens DESIGN §6 stap 4b, inclusief tussenstand en regels (laatste standpunt
    telt, tegenstrijdige antwoorden tegelijk = onvoldoende, docentbevestiging telt).
    `verify_citations(aspects, messages)` controleert of elk citaat in het genoemde teambericht
    staat. Bij meerdere `ASSESSOR_MODELS`: meerderheidsstem; als ze het oneens zijn → niet zeker.
  - Klaar als (tests met FakeLLM): een geldig citaat wordt geaccepteerd; een verzonnen citaat of
    een verkeerd bericht-id → `citations_ok = False`; meerderheid en onenigheid kloppen.

- [x] **T2.16 — Validator, antwoordmodus** (`agents/validator.py`, `prompts/validator.md`)
  - Klaar als (tests met FakeLLM): de uitvoer wordt geparsed; een ongeldige uitvoer na retry geeft
    `LLMError`.

- [x] **T2.17 — Beslisregels** (`agentic/policy.py`)
  - Doen: `decide_answer(...)` en `decide_question(...)` → `(decision, reason)` volgens de tabel in
    DESIGN §6 (zeker/onzeker, modus, rubric, pogingslimiet, flags, open escalatie).
  - Klaar als (tabelgedreven tests): elke rij uit de tabel; boven de pogingslimiet → `escalate`; een
    vraag levert nooit `level_up`; `flag_injection` of `manipulatie_gedetecteerd` → nooit
    automatisch.

- [x] **T2.18 — Pijplijn** (`agentic/pipeline.py`) (na T2.12–T2.17)
  - Doen: stappen 2–7 uit DESIGN §6 voor één taak; elke `LLMError` → `escalate`; levert de payload
    voor `submit_evaluation` (incl. modellen, duur en `thinking`).
  - Klaar als (scenario-tests met FakeLLM): (1) antwoord in twee berichten, waarvan het eerste al in
    de tussenstand staat → voldoende; (2) injection → escalatie; (3) LLM valt uit → escalatie;
    (4) `overig` → geen submit.

- [x] **T2.19 — `main.py` en oude agent weg** (na T2.11, T2.18)
  - Bestanden: `agentic-service/main.py`, `docker/Dockerfile.agent`, `agentic-service/README.md`;
    verwijder `process_ai_feedback.py`.
  - Doen: loop met heartbeat (`model_ok`), claim, pijplijn, submit; nette afmelding bij stoppen.
  - Klaar als (handmatig, Docker): de agent meldt zich aan; een teambericht levert binnen ~30 s
    een suggestie met intentie, oordeel en reden; bij een verkeerde `OLLAMA_URL` toont
    `teacher.php` "model niet bereikbaar" en komt er een escalatie, geen crash.

- [x] **T2.20 — Evals tegen het echte model**
  - Bestanden: `agentic-service/evals/cases/*.json`, `agentic-service/evals/run.py`.
  - Doen: ≥ 20 cases (opdracht + rubric + chat + verwacht: intentie, oordeel, mens nodig), ook
    lastige: gok in vraagvorm, antwoord in stukjes, hagelschieten, injection, synoniemen. `run.py`
    draait de pijplijn tegen de echte modellen en print per agent een overzicht goed/fout plus een
    lijst van onterechte level-ups.
  - Klaar als: het rapport draait en de score staat genoteerd. Herhaal na elke promptwijziging.

---

## Fase 3 — Opdracht-assistent

- [x] **T3.1 — Design-flow (logica)**
  - Bestanden: `conf/ai.php`, `tests/ai_test.php`.
  - Doen: `requestDesign()` (alleen als instructie en criteria gevuld zijn → `pending`),
    `addTeacherAnswer()` (`questions` → `pending`), `pendingDesignJobs()`, `claimDesign()` (lock
    5 min), `submitDesign()` (vragen → dialoog + `questions`; rubric → `validateRubric` + `draft` +
    `idle`; na 3 vraagrondes alleen nog een rubric).
  - Klaar als (tests): statusovergangen kloppen; een ongeldige rubric wordt geweigerd; ronde 4 met
    vragen wordt geweigerd; `criteria` blijft ongewijzigd; lock werkt (tweede agent krijgt hem niet).

- [x] **T3.2 — API-acties** `get_design_jobs`, `claim_design`, `submit_design` (na T3.1)
  - Klaar als (handmatig, `curl`): volledige ronde vragen → antwoord → rubric werkt.

- [x] **T3.3 — Designer-agent** (`agents/designer.py`, `prompts/designer.md`)
  - Doen: geeft 1–5 vragen óf een rubric; in ronde 3 altijd een rubric; vraagt naar feiten die
    alleen in de bijlage staan.
  - Klaar als (tests met FakeLLM): vragen en rubric worden goed doorgegeven; een rubric zonder
    aspecten → `LLMError`; in ronde 3 wordt om een rubric gevraagd.

- [x] **T3.4 — Design-taken in `main.py`** (na T3.2, T3.3)
  - Doen: per cyclus eerst teamberichten (live spel gaat voor), daarna design-taken.
  - Klaar als (test met mocks): bij beide soorten taken worden de teamberichten eerst verwerkt.

- [x] **T3.5 — AI-paneel in `assignments.php`** (na T3.1, T1.7)
  - Doen: dialoog, antwoordveld, knop *Laat AI meekijken*, live bijwerken via
    `assignments.php?edit=ID&ajax=ai` (lege 401/403 via `requireTeacher` bij `?ajax…`), rubric-voorstel
    in de rubric-tabel van T1.7. CSRF, audit `ASSIGNMENT_AI_REQUEST`, `ASSIGNMENT_RUBRIC_APPROVE`.
  - Klaar als (handmatig, Docker): docent vraagt AI, beantwoordt de vragen, krijgt een rubric, past
    die aan en keurt goed; de badge wordt *AI-klaar ✓*.

- [x] **T3.6 — Evals voor de designer**
  - Klaar als: 5 opdrachten (waarvan 2 bewust vaag) geven bij vaag vragen en bij duidelijk een
    geldige rubric; `verduidelijkingen_vraag` bevat geen onderdelen van het antwoord (handmatig
    beoordeeld).

---

## Fase 4 — `reply`: AI antwoordt het team

- [x] **T4.1 — Hulp-agent** (`agents/helper.py`, `prompts/helper.md`)
  - Klaar als (tests): de prompt bevat de beschrijving, de instructie, `verduidelijkingen_vraag` en
    de chat, maar **geen** `criteria`, geen aspecten en geen tussenstand (test met herkenbare
    teksten); `kan_beantwoorden = false` wordt doorgegeven.

- [x] **T4.2 — Validator, vraagmodus**
  - Klaar als (tests met FakeLLM): `verklapt_antwoord`, `misleidend` en `was_vraag` worden geparsed
    en door `decide_question` goed vertaald.

- [x] **T4.3 — Vraagpad in de pijplijn** (na T4.1, T4.2)
  - Doen: `vraag` → helper → validator; `was_vraag = false` → één keer opnieuw als antwoord, daarna
    escalatie.
  - Klaar als (scenario-tests): goede vraag → `reply`; verklappende reactie → `escalate`;
    `was_vraag = false` → beoordelaar draait; een vraag levert nooit `level_up`.

- [x] **T4.4 — `submitEvaluation()`: `reply`** (na T2.4)
  - Doen: `reply` zet een `ai`-bericht voor het team; bij voldoende/goed in `reply` ook een
    suggestie met level-up-advies voor de docent. Audit `AI_REPLY`.
  - Klaar als (tests): bij `ai_mode = 'reply'` en een goedgekeurde rubric komt er een `ai`-bericht;
    zonder goedgekeurde rubric, bij `paused` of bij een open escalatie alleen een suggestie.

- [x] **T4.5 — Escalaties afhandelen** (logica)
  - Bestanden: `conf/ai.php`, `conf/game.php`, `htdocs/messages.php`, `tests/ai_test.php`.
  - Doen: `resolveEscalations($db, $teamId, $level, $userId, $teacherVerdict)`; wordt aangeroepen
    bij een docentantwoord (`messages.php` reply), in `levelUp()`/`levelDown()`, en via de
    escalatieknoppen.
  - Klaar als (tests): na elk van die drie acties staat er geen open escalatie meer;
    `resolved_by`/`resolved_at` zijn gevuld; escalaties van andere teams blijven open.

- [x] **T4.6 — Escalatie-UI in `messages.php`** (na T4.5)
  - Doen: badge "🙋 Docent nodig" in de teamlijst (ook in `?ajax=sidebar`); bij een escalatie het
    teamantwoord naast het voorlopige oordeel, met knoppen *Eens → level up*, *Eens → reactie
    sturen*, *Oneens → zelf antwoorden*, *Afgehandeld*. CSRF, audit; vult `teacher_verdict`.
  - Klaar als (handmatig): elke knop doet wat hij zegt en sluit de escalatie; het team ziet niets
    van de escalatie.

- [x] **T4.7 — Teller "Docent nodig" in `teacher.php`**
  - Klaar als (handmatig): de teller ververst via de bestaande polling en klopt met het aantal open
    escalaties in het actieve spel.

- [x] **T4.8 — End-to-end `reply`** (handmatig, Docker)
  - Klaar als: (1) een verduidelijkende vraag krijgt een reactie die eruitziet als van een docent;
    (2) "Is het de conciërge?" wordt als antwoord beoordeeld; (3) boven `ai_max_questions` komt er
    een escalatie; (4) bij een escalatie krijgt het team niets tot de docent handelt.

---

## Fase 5 — `auto`: AI zet zelf een level omhoog

- [x] **T5.1 — `submitEvaluation()`: `level_up`** (na T4.4)
  - Doen: alleen als `allowedDecision()` `level_up` toestaat: `levelUp($db, $teamId, null)` plus een
    `ai`-bericht met de feedback; audit `AI_LEVEL_UP` met agent-id en model.
  - Klaar als (tests): `auto` + goedgekeurde rubric + `running` → level +1 en archief; `reply` →
    advies, geen level-up; `ai_auto_allowed = 0` → `reply`; `paused` → suggestie; stale → niets;
    open escalatie → suggestie; twee keer dezelfde payload → maar één level-up.

- [x] **T5.2 — AI-logboek** (`htdocs/ai_log.php`)
  - Doen: `requireTeacher` + `requireGame`; alle `ai_evaluations` van het spel met filters (team,
    opdracht, beslissing); per evaluatie de agentuitvoer en de redenering (`thinking`); knoppen
    *AI had gelijk* / *AI had ongelijk* (`teacher_verdict`, CSRF, audit); per opdracht het
    percentage overeenstemming. Link in `admin_header.php`.
  - Klaar als (handmatig): filters en markeren werken; alleen evaluaties van het actieve spel zijn
    zichtbaar (ook bij een geknutseld `id` in de URL).

- [ ] **T5.3 — Go/no-go voor `auto`**
  - Klaar als: de evals (T2.20) en de echte overeenstemming uit `ai_log.php` (na minstens één spel
    in `suggest` of `reply`) zijn bekeken. Voorstel voor de drempel: ≥ 90 % overeenstemming en
    **0** onterechte level-ups in de evals. Pas daarna `auto` in een echt spel aanzetten.

---

## Afronding

- [x] **T6.1 — Documentatie**: `CLAUDE.md` (AI-protocol, sender `ai`, nieuwe tabellen, Python-tests,
  `conf/ai.php`), `docker/README.md` (nieuwe env-variabelen, `ollama signin`),
  `agentic-service/README.md`.
- [x] **T6.2 — `IDEAS.md`**: het Agentic-AI-item afvinken.
- [x] **T6.3 — Privacy (optioneel, zie DESIGN §11 Aandachtspunt)**: tekst op `join.php` dat berichten
  met AI-hulp verwerkt kunnen worden, als de opleiding dat wil.
