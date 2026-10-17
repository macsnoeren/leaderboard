# Agentic AI service

De AI-agent van Zebrawave. Hij ondersteunt de docent: elk teambericht gaat door een pijplijn van
kleine agents, en de opdracht-assistent helpt bij het maken van een rubric. Wat de AI zelf mag
(alleen suggesties, het team antwoorden, of zelf een level omhoog zetten) stelt de docent per team in.
De webapplicatie blijft de baas: `api.php` controleert elke beslissing opnieuw.

- Ontwerp en beslissingen: [DESIGN.md](DESIGN.md)
- Uitwerking in taken: [TASK.md](TASK.md)

## Hoe het werkt

Per teambericht (`agentic/pipeline.py`):

1. **Voorfilter** (code): lege berichten en verdachte patronen ("negeer", "level up", …) krijgen een vlag.
2. **Intentie-agent**: vraag, antwoord of overig. Twijfel = antwoord; een gok in vraagvorm ("Is het de conciërge?") is ook een antwoord.
3. **Hulp-agent** (bij een vraag): legt uit wat de opdracht vraagt. Hij kent het antwoord niet.
4. **Beoordelaar** (bij een antwoord): beoordeelt alles wat het team op dit level gaf met de rubric, met letterlijke citaten die in code worden gecontroleerd.
5. **Validator** (groter model): controleert de beoordeling of de reactie (verklapt ze iets? manipulatie?).
6. **Beslisregels** (`agentic/policy.py`, code): suggestie, reactie aan het team, level-up of escalatie naar de docent.
7. **Indienen** bij `api.php?action=submit_evaluation`; de server voert alleen uit wat het spel toestaat.

Elke modelfout (timeout, HTTP 429/5xx, ongeldige JSON na één retry) wordt een escalatie, nooit een automatische actie.

De **opdracht-assistent** (`agents/designer.py`) stelt de docent vragen of stelt een rubric voor. Hij
vraagt eerst naar feiten die alleen in de bijlage staan en mag geen waarden verzinnen; in ronde 3 geeft
hij altijd een rubric.

## Opbouw

```
main.py                 loop: heartbeat, teamberichten, daarna design-taken
config.py.sample        kopieer naar config.py (staat in .gitignore)
agentic/
  settings.py           standaarden (DESIGN §10) + config.py of environment
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

## Starten

Met Docker: zie [docker/README.md](../docker/README.md). Zonder Docker (Python 3.10+, alleen `requests`):

```sh
cd agentic-service
cp config.py.sample config.py     # vul API_KEY (uit 🔑 API Keys) en BASE_URL (…/api.php) in
ollama signin                     # eenmalig, voor de -cloud-modellen
python3 tools/check_ollama.py     # werken de modellen?
python3 main.py                   # Ctrl+C meldt de agent netjes af
```

Modellen en denkniveaus staan per agent in `config.py` (`INTENT_MODEL`, `INTENT_THINK`, …,
`ASSESSOR_MODELS` als lijst voor een meerderheidsstem). Standaard: `gpt-oss:20b-cloud` voor intentie,
hulp-agent en beoordelaar, `gpt-oss:120b-cloud` voor validator en opdracht-assistent. Alleen
cloudmodellen: met `CLOUD_ONLY = True` (standaard) start de agent niet met een lokaal model en
weigert `llm.py` elke aanroep naar een lokaal model (dat wordt dan een escalatie).

## Testen

```sh
# Unit-tests (vanuit de repo-root)
docker run --rm -v "$PWD":/app -w /app/agentic-service python:3.12-slim \
  sh -c "pip install -q requests==2.32.3 && python -m unittest discover -s tests -v"

# Evals tegen het echte model (vanuit agentic-service/); herhaal na elke promptwijziging
python3 evals/run.py              # beoordelingspijplijn: per agent goed/fout + onterechte level-ups
python3 evals/run.py --designer   # opdracht-assistent
```

Privacy: naar het model gaan alleen de opdracht, de rubric en de berichten. Teamnamen, e-mailadressen,
tokens en docentnamen niet (`tests/test_context.py` controleert dat). Studenten kunnen wel zelf
persoonsgegevens in een bericht typen; zie DESIGN §11.
