# config.py

# API-sleutel voor de webapplicatie
API_KEY = "e35efff426264333486125c4633ab55f96f7270cf9d7fcf6c5ea777fa6f9d689"

# URL van de webapplicatie API
BASE_URL = "https://vmacman.jmnl.nl/leaderboard/htdocs/api.php"

# URL van de Ollama API
OLLAMA_URL = "http://localhost:11434/api/generate"

# Lijst met LLM-modellen die gebruikt worden voor de beoordeling
LLM_MODELS = [
    "qwen3:4b",
    "qwen3:30b",
    "gemma3:1b",
    "gemma3:4b",
    "gpt-oss:120b-cloud",
]

# Interval in seconden voor het ophalen van nieuwe antwoorden
POLL_INTERVAL = 30