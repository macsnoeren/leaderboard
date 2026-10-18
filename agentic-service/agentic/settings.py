"""Instellingen van de agent: standaarden plus wat config.py of de environment opgeeft."""
import re
from types import SimpleNamespace

DEFAULTS = {
    "API_KEY": "",
    "BASE_URL": "http://localhost:8088/api.php",
    "OLLAMA_URL": "http://localhost:11434/api/chat",
    "POLL_INTERVAL": 10,
    # Boven deze grens (schatting: tekens / 4) worden de oudste berichten vervangen door de samenvatting
    "MAX_CHAT_TOKENS": 20000,
    # Seconden per modelaanroep; bij een timeout volgt één retry
    "LLM_TIMEOUT": 120,
    "INTENT_MODEL": "gpt-oss:20b-cloud",
    "INTENT_THINK": "low",
    "HELPER_MODEL": "gpt-oss:20b-cloud",
    "HELPER_THINK": "low",
    # Meer modellen = meerderheidsstem; zijn ze het oneens, dan is de beoordeling niet zeker
    "ASSESSOR_MODELS": ["gpt-oss:20b-cloud"],
    "ASSESSOR_THINK": "medium",
    "VALIDATOR_MODEL": "gpt-oss:120b-cloud",
    "VALIDATOR_THINK": "high",
    "DESIGNER_MODEL": "gpt-oss:120b-cloud",
    "DESIGNER_THINK": "high",
    # Alleen Ollama-cloudmodellen (naam eindigt op -cloud of :cloud): er draait geen model op deze machine
    "CLOUD_ONLY": True,
}

_INT_KEYS = {"POLL_INTERVAL", "MAX_CHAT_TOKENS", "LLM_TIMEOUT"}

# Environment-variabelen van docker/agent-config.py: AI_INTENT_MODEL, AI_INTENT_THINK, ...
ENV_NAMES = {
    "API_KEY": "AI_API_KEY",
    "BASE_URL": "AI_BASE_URL",
    "OLLAMA_URL": "OLLAMA_URL",
    "POLL_INTERVAL": "AI_POLL_INTERVAL",
    "MAX_CHAT_TOKENS": "AI_MAX_CHAT_TOKENS",
    "LLM_TIMEOUT": "AI_LLM_TIMEOUT",
    "INTENT_MODEL": "AI_INTENT_MODEL",
    "INTENT_THINK": "AI_INTENT_THINK",
    "HELPER_MODEL": "AI_HELPER_MODEL",
    "HELPER_THINK": "AI_HELPER_THINK",
    "ASSESSOR_MODELS": "AI_ASSESSOR_MODELS",
    "ASSESSOR_THINK": "AI_ASSESSOR_THINK",
    "VALIDATOR_MODEL": "AI_VALIDATOR_MODEL",
    "VALIDATOR_THINK": "AI_VALIDATOR_THINK",
    "DESIGNER_MODEL": "AI_DESIGNER_MODEL",
    "DESIGNER_THINK": "AI_DESIGNER_THINK",
    "CLOUD_ONLY": "AI_CLOUD_ONLY",
}

_CLOUD = re.compile(r"[-:]cloud$")


def is_cloud_model(model):
    """Ollama-cloudmodellen eindigen op '-cloud' of ':cloud' (gpt-oss:20b-cloud, kimi-k2.6:cloud)."""
    return bool(_CLOUD.search(str(model).strip()))


def _getter(settings):
    return settings.get if isinstance(settings, dict) else lambda k: getattr(settings, k)


def all_models(settings):
    """Alle modellen die de agent gebruikt; `settings` is een dict of een namespace."""
    get = _getter(settings)
    return sorted({get("INTENT_MODEL"), get("HELPER_MODEL"), get("VALIDATOR_MODEL"), get("DESIGNER_MODEL"), *get("ASSESSOR_MODELS")})


def local_models(settings):
    """Modellen die niet in de cloud draaien; leeg = in orde (of CLOUD_ONLY staat uit)."""
    if not _getter(settings)("CLOUD_ONLY"):
        return []
    return [m for m in all_models(settings) if not is_cloud_model(m)]


def split_models(value):
    """'a, b' of 'a b' of ['a', ' b'] → ['a', 'b']."""
    if value is None:
        return []
    if isinstance(value, (list, tuple)):
        return [str(v).strip() for v in value if str(v).strip()]
    return [m for m in re.split(r"[,\s]+", str(value)) if m]


def _coerce(key, value):
    if key == "ASSESSOR_MODELS":
        return split_models(value) or list(DEFAULTS[key])
    if key in _INT_KEYS:
        return int(value)
    if key == "CLOUD_ONLY":
        return value if isinstance(value, bool) else str(value).strip().lower() not in ("0", "false", "no", "nee", "off")
    return value


def from_env(environ):
    """Waarden uit de environment; ontbrekende of lege waarden krijgen de standaard."""
    values = {}
    for key, env in ENV_NAMES.items():
        raw = environ.get(env)
        values[key] = _coerce(key, raw) if raw not in (None, "") else (
            list(DEFAULTS[key]) if isinstance(DEFAULTS[key], list) else DEFAULTS[key])
    return values


def load_settings(module=None, **overrides):
    """Settings uit een config-module (config.py); ontbrekende waarden krijgen de standaard."""
    values = {}
    for key, default in DEFAULTS.items():
        value = getattr(module, key, None) if module is not None else None
        values[key] = _coerce(key, value) if value not in (None, "") else (
            list(default) if isinstance(default, list) else default)
    for key, value in overrides.items():
        values[key] = _coerce(key, value)
    return SimpleNamespace(**values)
