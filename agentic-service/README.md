# Agentic AI service

De AI-agent van OperationRoom. Hij ondersteunt de operator: elk teambericht gaat door een pijplijn van
kleine agents, en de opdracht-assistent helpt bij het maken van een rubric. Wat de AI zelf mag
(alleen suggesties, het team antwoorden, of zelf een level omhoog zetten) stelt de operator per team in.
De webapplicatie blijft de baas: `api.php` controleert elke beslissing opnieuw, dus een kapotte of
gestolen agent kan nooit meer dan het spel toestaat.

Voor operators staat dezelfde uitleg, zonder techniek, op de pagina *Zo werkt de AI* (`htdocs/ai_uitleg.php`,
onder "Tools" in de zijbalk). Pas die mee aan als modi, prompts of beslisregels veranderen.

Uitgangspunten:

- **Beslisregels zijn code, geen prompt** (`agentic/policy.py`, op de server `allowedDecision()` in
  `conf/ai.php`): testbaar en uitlegbaar.
- **Alles via `api.php` en polling**: geen Ollama-aanroep vanuit PHP, geen websockets. De agent kan op een
  andere machine draaien, en er kunnen er meerdere tegelijk draaien.
- **De AI is onzichtbaar voor teams**: op `team.php` ziet een AI-bericht (`sender = 'ai'`) eruit als een
  operatorbericht. De operator, het archief en `ai_log.php` laten wel zien wat van de AI kwam.
- **Bij twijfel naar de operator.** Elke modelfout (timeout, HTTP 429/5xx, ongeldige JSON na één retry)
  wordt een escalatie, nooit een automatische actie.

## AI-modi

De modus staat per team in `teams.ai_mode` (keuzelijst in `teacher.php`, of *Voor alle teams*, wat ook
`games.ai_mode` zet als standaard voor nieuwe teams). Er is geen overerving: elk team heeft zijn eigen modus.

| Modus | AI-uitkomst | AI antwoordt team | AI zet level omhoog |
|---|---|---|---|
| `off` | agent slaat het team over | nee | nee |
| `suggest` (standaard) | suggestie voor de operator | nee | nee |
| `reply` | team krijgt feedback en reacties op vragen; operator krijgt het level-up-advies | ja | nee |
| `auto` | team krijgt feedback, level-up gebeurt automatisch | ja | ja |

- `reply` en `auto` vragen een **goedgekeurde rubric** (`ai_rubric_status = 'approved'`); anders valt de
  opdracht terug op `suggest`. Wijzigt de operator `instruction` of `criteria`, dan gaat de rubric terug naar
  `draft`.
- Per opdracht kan de operator "altijd door een mens" kiezen (`ai_auto_allowed = 0`): `auto` wordt dan `reply`.
- Zet `auto` pas aan in een echt spel als de evals en de overeenstemming in `ai_log.php` (na minstens één
  spel in `suggest` of `reply`) dat rechtvaardigen; richtlijn: ≥ 90 % overeenstemming en **0** onterechte
  level-ups in de evals.

## Hoe het werkt

Per teambericht (`agentic/pipeline.py`):

1. **Voorfilter** (code): lege berichten en verdachte patronen ("negeer", "level up", …) krijgen de vlag
   `flag_injection`.
2. **Intentie-agent**: vraag, antwoord of overig. Twijfel (`zeker = false`) = antwoord, wat het model ook
   zegt. Een inhoudelijke gok in vraagvorm ("Is het de conciërge?") en een gemengd bericht zijn ook een
   antwoord, anders kan een team het antwoord met ja/nee-vragen ontfutselen. "Overig" ("ok") wordt ook als
   antwoord beoordeeld (`intent_json` houdt `overig`).
3. **Hulp-agent** (bij een vraag): legt uit wat de opdracht vraagt. Hij krijgt alleen de beschrijving, de
   instructie en de `verduidelijkingen_vraag` uit de rubric, niet het antwoord: wat hij niet weet, kan hij
   niet verklappen. Een vraag leidt nooit tot een oordeel of een level-up.
4. **Beoordelaar** (bij een antwoord): beoordeelt **alles wat het team op dit level gaf** met de rubric,
   met per aspect een letterlijk citaat plus bericht-id. De code controleert dat het citaat echt in dat
   teambericht staat, en dat het oordeel past bij de aspecten.
5. **Validator** (groter model): controleert de beoordeling of de reactie. Verklapt ze iets, bevestigt of
   ontkent ze een gok, is er manipulatie, was het wel een vraag? Zegt hij "geen vraag", dan wordt het
   bericht alsnog één keer als antwoord beoordeeld.
6. **Beslisregels** (`agentic/policy.py`): zie hieronder.
7. **Indienen** bij `api.php?action=submit_evaluation`; de server voert alleen uit wat het spel toestaat.

### Context

Teams geven hun antwoord vaak in stukjes, dus elke agent krijgt de hele chat van het level (berichten van
team, operator en AI, geen suggesties), met het nieuwe bericht gemarkeerd. Boven `MAX_CHAT_TOKENS` worden de
oudste berichten vervangen door de samenvatting uit de **tussenstand** (`ai_team_progress`: per aspect het
hoogste bereikte niveau met citaat; de server werkt hem bij na elke geldige beoordeling).

- Eerder gegeven goede delen tellen mee; een bevestiging van de operator ("de dader klopt") telt als bereikt.
- Het laatste standpunt per aspect telt; meerdere tegenstrijdige antwoorden tegelijk = onvoldoende.
- Alleen wat echt in de chat staat telt; een tussenstand zonder geldig citaat wordt genegeerd.
- De hulp-agent krijgt de tussenstand **niet** (die verraadt de rubric-aspecten).

### Beslisregels

Een antwoord is "zeker" als beoordelaar en validator hetzelfde oordeel geven, de validator akkoord is,
niemand `mens_nodig` zegt, alle citaten kloppen, de rubric is goedgekeurd en het team onder de
pogingslimiet zit. Een vraag is "zeker" als de hulp-agent `kan_beantwoorden` zegt en de validator niets
verklappends of misleidends ziet.

| Oordeel | Zeker? | `suggest` | `reply` | `auto` |
|---|---|---|---|---|
| onvoldoende | ja | suggestie | feedback aan team | feedback aan team |
| onvoldoende | nee | suggestie | escalatie | escalatie |
| voldoende/goed | ja | suggestie (level-up-advies) | feedback + level-up-advies voor operator | `levelUp()` + bericht aan team |
| voldoende/goed | nee | suggestie (level-up-advies) | escalatie | escalatie |
| vraag | ja | suggestie (voorgestelde reactie) | reactie aan team | reactie aan team |
| vraag | nee | suggestie (voorgestelde reactie) | escalatie | escalatie |

- **Harde vlaggen escaleren in elke modus**, ook in `suggest`: `flag_injection`, manipulatie en elke
  modelfout. De pogingen- en vragenlimiet (`ai_max_attempts`, `ai_max_questions` per team per level per
  10 minuten) tellen als twijfel.
- **Escalatie**: de operator ziet een suggestie met het voorlopige oordeel en de reden, en "Operator nodig"
  in `teacher.php`. Het team krijgt niets, en zolang de escalatie openstaat stuurt de AI niets en zet ze
  geen level. Ze is afgehandeld als de operator antwoordt, het level verandert, of een escalatieknop in
  `messages.php` gebruikt wordt (ook *Verwerpen*).
- Feedback die de validator als verklappend markeert, gaat nooit naar het team.
- `goed` en `voldoende` geven allebei een level-up; het verschil wordt bewaard en kleurt de feedback. Bij
  een level-up door de AI komt de feedback op het nieuwe level, zodat het team hem ziet.

### Controles op de server (`submitEvaluation()`)

De server voert een beslissing alleen uit als het spel `running` is (bij `paused`: alleen suggesties), de
agent het team geclaimd heeft, `level` en `message_id` nog actueel zijn (anders `stale`: alleen opslaan),
en de actie binnen `ai_mode`, `ai_auto_allowed`, de rubricstatus en een eventuele open escalatie valt.
Valt een controle af, dan wordt de actie omlaag bijgesteld, nooit omhoog. Elke actie komt in `audit_logs`
met `user_id` NULL (`AI_SUGGEST`, `AI_REPLY`, `AI_LEVEL_UP`, `AI_ESCALATE`).

## Opdracht-assistent

`agents/designer.py`, gestart met *Laat AI meekijken* in `assignments.php` (`ai_design_status`
`idle` → `pending` → `questions`/`draft`). De assistent stelt 1–5 vragen of stelt een rubric voor; na
3 vraagrondes geeft hij altijd een rubric. De vragen en antwoorden (`assignment_ai_dialog`) gaan als
verduidelijkingen mee naar de beoordelaar. De AI schrijft nooit in `criteria` (het antwoord van de operator).

De AI leest geen bijlagen. Verwijzen de criteria naar de bijlage en heeft de operator nog niets beantwoord,
dan vraagt de assistent eerst; tijden en getallen in de rubric moeten in de tekst van de operator staan, en
regels in `verduidelijkingen_vraag` met antwoordtermen worden weggehaald (code-controles, omdat het model
ze in de praktijk oversloeg).

Rubric (`assignments.ai_rubric`, JSON; de operator ziet een tabel, geen JSON):

```json
{
  "kern": "Het team moet de dader én het motief noemen.",
  "aspecten": [
    {"naam": "Dader", "verplicht": true,
     "onvoldoende": "Geen of een verkeerde persoon.", "voldoende": "De conciërge, zonder naam.", "goed": "Jan de Vries (de conciërge)."}
  ],
  "ook_goed": ["Varianten of synoniemen die ook goed zijn"],
  "veelgemaakte_fouten": ["De tuinman: alibi bevestigd op camera 3"],
  "verduidelijkingen_vraag": ["Met 'motief' bedoelen we: waarom deed de dader het?"],
  "niveau_regel": "voldoende = alle verplichte aspecten minimaal voldoende; goed = alle aspecten goed"
}
```

Aspectnamen zijn uniek en er is minstens één verplicht aspect.

## Opbouw

```
main.py                 loop: heartbeat, teamberichten, daarna design-taken
config.py.sample        kopieer naar config.py (staat in .gitignore)
agentic/
  settings.py           standaarden + config.py of environment
  api.py                client voor api.php
  llm.py                Ollama /api/chat met JSON-schema, timeout, 1 retry
  schemas.py            JSON-schema's per agent + validatie
  context.py            prompt-opbouw: data-blokken, dataminimalisatie, afkappen
  prefilter.py          voorfilter in code
  policy.py             beslisregels (pure functies)
  pipeline.py           de stappen per teambericht
  agents/               intent, helper, assessor, validator, designer
  prompts/              de prompts per agent
tests/                  unittest + unittest.mock, met een FakeLLM
evals/                  cases tegen het echte model
tools/check_ollama.py   controleert of Ollama de modellen met JSON-uitvoer bedient
```

Het protocol met `api.php` staat in `CLAUDE.md` ("AI agent protocol").

## Starten

Met Docker: zie [docker/README.md](../docker/README.md). Zonder Docker (Python 3.10+, alleen `requests`):

```sh
cd agentic-service
cp config.py.sample config.py     # vul API_KEY (uit API-sleutels) en BASE_URL (…/api.php) in
ollama signin                     # eenmalig, voor de -cloud-modellen
python3 tools/check_ollama.py     # werken de modellen?
python3 main.py                   # Ctrl+C meldt de agent netjes af
```

Weigert `api.php` de key (401, bijvoorbeeld na een restore of nadat de key in API-sleutels is vervangen),
dan leest de agent hem één keer opnieuw in en probeert het verzoek nog eens; herstarten is niet nodig.
Hij leest uit de database van de webapp als `API_KEY_DB` is ingesteld (pad naar `leaderboard.db`, alleen
lezen, met `API_KEY_NAME`, standaard `docker-agent`; zo werkt de Docker-agent), en anders uit `config.py`
(pas daar dus gewoon `API_KEY` aan terwijl de agent draait).

## Modellen

Per agent een model en denkniveau in `config.py` (`INTENT_MODEL`, `INTENT_THINK`, …, `ASSESSOR_MODELS` als
lijst voor een meerderheidsstem; oneens = niet zeker):

| Agent | Model | `think` |
|---|---|---|
| Intentie, hulp-agent | `gpt-oss:20b-cloud` | `low` |
| Beoordelaar | `gpt-oss:20b-cloud` | `medium` |
| Validator, opdracht-assistent | `gpt-oss:120b-cloud` | `high` |

- Beoordelaar en validator draaien bewust op verschillende modellen, zodat ze niet dezelfde fout maken.
- **Alleen cloudmodellen** (er is geen GPU): met `CLOUD_ONLY = True` (standaard) start de agent niet met
  een model waarvan de naam niet op `-cloud`/`:cloud` eindigt, en weigert `llm.py` zo'n aanroep (escalatie).
  De agent praat met de lokale Ollama (`OLLAMA_URL`), die de cloudmodellen doorstuurt; die moet aangemeld
  zijn (`ollama signin`).
- **gpt-oss negeert `format` (JSON-schema) via de cloud** (gemeten met `tools/check_ollama.py`, Ollama
  0.32.15: 0 van 6 geldig met alleen `format`, 6 van 6 met het schema ook in de prompt). Daarom zet
  `llm.py` het schema in de systeemprompt, haalt het JSON-object uit een eventueel codeblok, valideert zelf
  en geeft bij de retry de fout mee.
- De redenering (`message.thinking`) wordt in `ai_evaluations` bewaard voor `ai_log.php`, nooit naar het
  team gestuurd.
- Is het model niet bereikbaar, dan meldt de heartbeat `model_ok = 0` en toont `teacher.php` "model niet
  bereikbaar".

## Testen

```sh
# Unit-tests (vanuit de repo-root)
docker run --rm -v "$PWD":/app -w /app/agentic-service python:3.12-slim \
  sh -c "pip install -q requests==2.32.3 && python -m unittest discover -s tests -v"

# Evals tegen het echte model (vanuit agentic-service/); herhaal na elke promptwijziging
python3 evals/run.py              # beoordelingspijplijn: per agent goed/fout + onterechte level-ups
python3 evals/run.py --designer   # opdracht-assistent
```

Laatste evals (2026-10-17, standaardmodellen, modus `auto`, 26 cases): intentie 26/26, beoordelaar 19/21,
validator 18/21, mens nodig 10/11, **0 onterechte level-ups**; opdracht-assistent 5/5. De fouten vallen aan
de veilige kant (onvoldoende of een escalatie bij twijfel over voldoende/goed). Noteer nieuwe scores hier.

## Privacy

Naar het model gaan alleen de opdracht, de rubric en de berichten. Teamnamen, e-mailadressen, tokens en
operatornamen niet: in de prompt heet het team "Team" en de operator "Docent" (`tests/test_context.py`
controleert dat). Teamtekst staat in afgebakende data-blokken.

Met cloudmodellen gaan berichten van studenten wel naar de servers van Ollama, en studenten kunnen zelf
persoonsgegevens in een bericht typen. Leg vast dat dit binnen de afspraken van de opleiding past;
`join.php` en `team.php` vermelden dat berichten met AI-hulp verwerkt kunnen worden.
