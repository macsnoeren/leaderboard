"""Ollama /api/chat met een JSON-schema, timeout en één retry.

Uitkomst van tools/check_ollama.py (DESIGN §10): gpt-oss negeert `format` via de cloud (het antwoordt
met een codeblok en eigen veldwaarden). Daarom staat het schema ook in de prompt, haalt
`parse_json()` het JSON-object uit een eventueel codeblok en valideert deze client altijd zelf.
`format` gaat wel mee: modellen die het wel respecteren hebben er baat bij.
"""
import json
import logging
import re
import time

import requests

from . import schemas
from .settings import is_cloud_model

logger = logging.getLogger(__name__)

MAX_THINKING_CHARS = 20000
_RETRY_STATUS = {429, 500, 502, 503, 504}


class LLMError(Exception):
    """Het model gaf na één retry nog geen bruikbaar antwoord (timeout, HTTP-fout of ongeldige JSON)."""


def parse_json(text):
    """JSON-object uit de modeltekst, ook als het in een ```json-codeblok of tussen andere tekst staat."""
    if not isinstance(text, str):
        raise ValueError("geen tekst")
    cleaned = re.sub(r"```(?:json)?", "", text).strip()
    try:
        value = json.loads(cleaned)
    except ValueError:
        start, end = cleaned.find("{"), cleaned.rfind("}")
        if start < 0 or end <= start:
            raise ValueError("geen JSON-object gevonden")
        value = json.loads(cleaned[start:end + 1])
    if not isinstance(value, dict):
        raise ValueError("JSON is geen object")
    return value


def think_value(think):
    """'low'/'medium'/'high' voor gpt-oss, True/False voor andere redeneermodellen, None = niet meesturen."""
    if think is None:
        return None
    if isinstance(think, bool):
        return think
    value = str(think).strip().lower()
    if value in ("", "none", "off", "no"):
        return None
    if value in ("true", "false"):
        return value == "true"
    return value


def schema_instruction(schema):
    return ("\n\n# Uitvoer\nGeef uitsluitend één JSON-object, zonder uitleg en zonder codeblok, dat voldoet aan "
            "dit JSON-schema (alle verplichte velden, met precies deze types):\n"
            + json.dumps(schema, ensure_ascii=False))


class LLMClient:
    def __init__(self, url, timeout=120, session=None, sleep=time.sleep, cloud_only=True):
        # Oude configuraties wijzen naar /api/generate; deze client praat met /api/chat
        self.url = re.sub(r"/api/generate/?$", "/api/chat", url)
        self.timeout = timeout
        self.session = session or requests.Session()
        self.sleep = sleep
        self.last_ok = None  # None = nog geen aanroep gedaan
        # Vangnet: een lokaal model zou op deze machine (zonder GPU) draaien; dan liever een escalatie
        self.cloud_only = cloud_only

    def ping(self):
        """Is Ollama bereikbaar? Werkt last_ok bij als er nog geen modelaanroep is geweest."""
        base = re.sub(r"/api/.*$", "", self.url)
        try:
            ok = self.session.get(base + "/api/tags", timeout=5).status_code == 200
        except requests.RequestException:
            ok = False
        if self.last_ok is None or not ok:
            self.last_ok = ok
        return ok

    def chat_json(self, model, think, system, user, schema, check=None):
        """Eén modelaanroep die een geldig JSON-object oplevert: (dict, thinking, duration_ms).

        `check(obj)` mag een extra foutmelding teruggeven (bijv. "rubric zonder aspecten"); die telt als
        ongeldige uitvoer. Bij ongeldige JSON, een timeout of HTTP 429/5xx volgt één retry; daarna LLMError.
        """
        if self.cloud_only and not is_cloud_model(model):
            self.last_ok = False
            raise LLMError(f"{model}: geen cloudmodel (alleen -cloud/:cloud is toegestaan, zie CLOUD_ONLY)")
        messages = [
            {"role": "system", "content": system + schema_instruction(schema)},
            {"role": "user", "content": user},
        ]
        body = {"model": model, "messages": messages, "format": schema, "stream": False,
                "options": {"temperature": 0}}
        think = think_value(think)
        if think is not None:
            body["think"] = think

        start = time.monotonic()
        last_error = None
        for attempt in (1, 2):
            try:
                obj, thinking = self._call(body, schema, check)
                self.last_ok = True
                return obj, thinking, int((time.monotonic() - start) * 1000)
            except _Retry as e:
                last_error = str(e)
                logger.warning("Model %s, poging %d: %s", model, attempt, last_error)
                if e.feedback and attempt == 1:
                    # Tweede poging met de fout erbij, zodat het model zich kan verbeteren
                    body = dict(body, messages=messages + [
                        {"role": "assistant", "content": e.content or ""},
                        {"role": "user", "content": f"Je antwoord was ongeldig: {e.feedback}. "
                                                    "Geef alleen het JSON-object volgens het schema."},
                    ])
                if attempt == 1 and e.backoff:
                    self.sleep(e.backoff)
            except _Fatal as e:
                last_error = str(e)
                break
        self.last_ok = False
        raise LLMError(f"{model}: {last_error}")

    def _call(self, body, schema, check):
        try:
            resp = self.session.post(self.url, json=body, timeout=self.timeout)
        except requests.Timeout:
            raise _Retry("model reageert niet (timeout)")
        except requests.RequestException as e:
            # De details alleen in het log; de docent ziet een korte reden
            logger.debug("Verbinding met Ollama mislukt: %s", e)
            raise _Retry("Ollama niet bereikbaar", backoff=2)
        if resp.status_code in _RETRY_STATUS:
            raise _Retry(f"HTTP {resp.status_code}", backoff=5 if resp.status_code == 429 else 2)
        if resp.status_code != 200:
            raise _Fatal(f"HTTP {resp.status_code}: {resp.text[:200]}")
        try:
            message = resp.json().get("message") or {}
        except ValueError:
            raise _Retry("antwoord van Ollama is geen JSON")
        content = message.get("content") or ""
        thinking = (message.get("thinking") or "")[:MAX_THINKING_CHARS]
        try:
            obj = parse_json(content)
        except ValueError as e:
            raise _Retry(f"ongeldige JSON ({e})", feedback="geen geldig JSON-object", content=content)
        errors = schemas.validate(obj, schema)
        if not errors and check is not None:
            extra = check(obj)
            errors = [extra] if extra else []
        if errors:
            raise _Retry("uitvoer klopt niet met het schema: " + "; ".join(errors[:5]),
                         feedback="; ".join(errors[:5]), content=content)
        return obj, thinking


class _Retry(Exception):
    def __init__(self, message, feedback=None, content=None, backoff=0):
        super().__init__(message)
        self.feedback = feedback
        self.content = content
        self.backoff = backoff


class _Fatal(Exception):
    pass
