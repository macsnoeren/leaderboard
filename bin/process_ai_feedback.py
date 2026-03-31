# Copyright (C) 2025 JMNL Innovation.
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.

import requests
import json
import time
import re
import logging
from typing import List, Dict, Optional, Any
import re
from config import API_KEY, BASE_URL, OLLAMA_URL, LLM_MODELS, POLL_INTERVAL

# Configureer logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
logger = logging.getLogger(__name__)

class AIFeedbackService:
    def __init__(self):
        self.api_key = API_KEY
        self.base_url = BASE_URL
        self.ollama_url = OLLAMA_URL
        self.models = LLM_MODELS
        self.poll_interval = POLL_INTERVAL

    def _get_headers(self) -> Dict[str, str]:
        return {
            "X-API-Token": self.api_key,
            "Content-Type": "application/json"
        }

    def _extract_json(self, text: Any) -> Optional[Dict]:
        if isinstance(text, dict): return text
        if not isinstance(text, str): return None
        
        cleaned = re.sub(r'```(?:json)?\n?|```', '', text)
        match = re.search(r'\{.*\}', cleaned, re.DOTALL)
        if not match: return None

        try:
            return json.loads(match.group())
        except json.JSONDecodeError:
            return None

    def fetch_pending_tasks(self) -> List[Dict]:
        try:
            response = requests.get(
                self.base_url,
                headers=self._get_headers(),
                params={"action": "get_pending", "token": self.api_key}, # Fallback token in URL
                timeout=30
            )
            
            if response.status_code == 401:
                logger.error("Authenticatie mislukt. Controleer je API_KEY.")
                return []
            
            response.raise_for_status()
            return response.json()
        except Exception as e:
            logger.error(f"Fout bij ophalen taken: {e}")
            return []

    def get_model_feedback(self, task: Dict, model: str) -> Optional[Dict]:
        instruction = str(task.get('instruction') or "")
        criteria = str(task.get('criteria') or "")
        answer = str(task.get('last_team_message') or "")

        prompt = f"""
        Negeer eerdere context. Je bent een automatische beoordelaar voor een recherche-spel.
        
        OPDRACHT VOOR TEAM: {instruction}
        BEOORDELINGSCRITERIA: {criteria}
        ANTWOORD VAN HET TEAM: {answer}

        TAAK:
        1. Geef een score van 0 tot 10.
        2. Geef korte feedback in de 'jullie'-vorm.
        3. Geef een korte uitleg wat er beter kan.

        OUTPUT MOET EXACT DEZE JSON ZIJN:
        {{
            "score": <getal>,
            "feedback": "<tekst>",
            "uitleg": "<tekst>"
        }}
        """
        
        try:
            start = time.time()
            resp = requests.post(
                self.ollama_url,
                json={"model": model, "prompt": prompt, "stream": False},
                timeout=300
            )
            resp.raise_for_status()
            duration = time.time() - start
            
            raw_response = resp.json().get("response", "")
            result = self._extract_json(raw_response)
            
            if result:
                result['duration'] = duration
                return result
        except Exception as e:
            logger.warning(f"Model {model} fout: {e}")
        return None

    def submit_suggestion(self, team_id: int, message: str, level_up: bool):
        try:
            payload = {
                "team_id": team_id,
                "message": message,
                "level_up": level_up
            }
            resp = requests.post(
                f"{self.base_url}?action=send_suggestion&token={self.api_key}",
                headers=self._get_headers(),
                json=payload,
                timeout=30
            )
            resp.raise_for_status()
            return True
        except Exception as e:
            logger.error(f"Fout bij versturen suggestie voor team {team_id}: {e}")
            return False
            
    def send_heartbeat(self):
        try:
            resp = requests.post(
                f"{self.base_url}?action=heartbeat&token={self.api_key}",
                headers=self._get_headers(),
                timeout=5
            )
            resp.raise_for_status()
        except Exception as e:
            logger.warning(f"Fout bij versturen heartbeat: {e}")

    def run(self):
        logger.info(f"AI Feedback Service gestart. Interval: {self.poll_interval}s")
        
        while True:
            tasks = self.fetch_pending_tasks()
            if tasks:
                logger.info(f"{len(tasks)} antwoorden gevonden om te verwerken.")
            
            for task in tasks:
                team_name = task.get('team_name', 'Onbekend')
                all_feedback = []
                scores = []
                failed = False

                for model in self.models:
                    logger.info(f"Analyseren: Team '{team_name}' met {model}...")
                    res = self.get_model_feedback(task, model)
                    
                    if res:
                        scores.append(res.get('score', 0))
                        all_feedback.append(f"**{model}** ({res['score']}/10):\n{res['feedback']}\n*Tip: {res['uitleg']}*")
                    else:
                        logger.warning(f"Model {model} gaf geen resultaat.")

                if not all_feedback:
                    continue

                avg_score = sum(scores) / len(scores) if scores else 0
                can_level_up = avg_score >= 7.0
                
                summary = f"🤖 **AI Analyse Rapport** (Gemiddelde score: {avg_score:.1f}/10)\n\n"
                summary += "\n\n---\n\n".join(all_feedback)
                
                if self.submit_suggestion(task['team_id'], summary, can_level_up):
                    logger.info(f"Suggestie geplaatst voor {team_name} (Level up advies: {can_level_up})")

            self.send_heartbeat()
            time.sleep(self.poll_interval)

if __name__ == "__main__":
    try:
        service = AIFeedbackService()
        service.run()
    except KeyboardInterrupt:
        logger.info("Service gestopt door gebruiker.")
    except Exception as e:
        logger.critical(f"Kritieke fout in service: {e}")
