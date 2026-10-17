# config.py voor de AI-agent in Docker: alle waarden komen uit de environment
# (zie docker-compose.yml en docker/.env). agent-entrypoint.sh zet AI_API_KEY en AI_BASE_URL.
# Variabelen: AI_INTENT_MODEL, AI_INTENT_THINK, AI_HELPER_MODEL, AI_HELPER_THINK, AI_ASSESSOR_MODELS
# (komma's of spaties), AI_ASSESSOR_THINK, AI_VALIDATOR_MODEL, AI_VALIDATOR_THINK, AI_DESIGNER_MODEL,
# AI_DESIGNER_THINK, AI_POLL_INTERVAL, AI_MAX_CHAT_TOKENS, AI_LLM_TIMEOUT en OLLAMA_URL.
# Ontbrekende waarden krijgen de standaard uit agentic/settings.py.
import os

from agentic.settings import from_env

globals().update(from_env(os.environ))
