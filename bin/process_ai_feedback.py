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

        # DOCUMENTATIE VAN DE PROMPT-OPBOUW:
        # 1. # CONTEXT: Gebruikt 'Persona Prompting'. Door de AI een expertrol te geven, 
        #    wordt de woordkeuze en diepgang van de analyse professioneler.
        #
        # 2. # INPUT DATA: Scheidt instructies strikt van de data van de gebruiker. 
        #    Dit voorkomt 'prompt injection' waarbij de team-tekst de AI probeert te foppen.
        #
        # 3. # JOUW EVALUATIEPROCES: Implementeert 'Chain-of-Thought'. 
        #    - Stap 1 (Essentie bepalen): Wat is de absolute kern die beantwoord moet worden?
        #    - Stap 2 (Semantische match): Zoek naar de intentie. Synoniemen of alternatieve
        #      verwoordingen die op hetzelfde neerkomen moeten positief gewaardeerd worden.
        #    - Stap 3 (Logica check): Is de redenering van het team valide binnen de context?
        #
        # 4. # OUTPUT SPECIFICATIES: Stelt harde grenzen aan de lengte (max 3 zinnen) 
        #    en de toon ('jullie'-vorm).
        #
        # 5. # VERPLICHTE JSON STRUCTUUR: Cruciaal voor machine-to-machine communicatie. 
        #    De AI wordt gedwongen om geen 'gelets' eromheen te typen, zodat Python de data kan parsen.

        prompt = f"""
        # CONTEXT
        Je bent een deskundige beoordelaar en recherche-instructeur voor een educatief spel. 
        Je taak is om het ingezonden werk van een team te analyseren en van feedback te voorzien.

        # INPUT DATA
        - **Opdracht voor het team**: {instruction}
        - **Beoordelingscriteria**: {criteria}
        - **Ingezonden antwoord van het team**: {answer}

        # JOUW EVALUATIEPROCES (Denk stap-voor-stap)
        1. **Kern van de criteria**: Bepaal wat de intentie is van de criteria. Welke informatie of welk inzicht is essentieel?
        2. **Semantische analyse**: Beoordeel het antwoord van het team op inhoud, niet op exacte bewoording. Accepteer synoniemen, alternatieve manieren om een conclusie te trekken of creatieve antwoorden die binnen de strekking van de criteria vallen.
        3. **Ruimdenkendheid**: Als een team een punt maakt dat niet letterlijk in de criteria staat, maar wel logisch voortvloeit uit de opdracht of de recherche-context, waardeer dit dan positief.
        4. **Scoretoewijzing**: Ken een score toe (0-10). Een 10 is voor een volledig antwoord (in strekking). Een 7 of hoger betekent dat de essentie is begrepen en dat ze door kunnen naar het volgende level.

        # OUTPUT SPECIFICATIES
        Geef je antwoord uitsluitend in JSON-formaat met de volgende velden:
        - "score": Een numerieke waarde tussen 0 en 10.
        - "feedback": Een constructieve, motiverende reactie direct gericht aan het team in de 'jullie'-vorm (max 3 zinnen).
        - "uitleg": Een interne toelichting voor de docent waarin je uitlegt waarom deze score is gegeven op basis van jouw referentie-oplossing en de criteria.

        # VERPLICHTE JSON STRUCTUUR
        {{
            "score": <getal>,
            "feedback": "<tekst>",
            "uitleg": "<tekst>"
        }}

        Begin nu met je analyse en output direct de JSON.
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

                for model in self.models:
                    logger.info(f"Analyseren: Team '{team_name}' met {model}...")
                    res = self.get_model_feedback(task, model)
                    
                    if res:
                        score = res.get('score', 0)
                        # Formatteer de feedback voor een individueel model
                        model_feedback = f"🤖 **AI Advies ({model})** - Score: {score}/10\n\n"
                        model_feedback += f"{res['feedback']}\n\n"
                        model_feedback += f"*Tip: {res['uitleg']}*"
                        
                        can_level_up = score >= 7.0
                        
                        # Stuur de suggestie direct per model door naar de API
                        if self.submit_suggestion(task['team_id'], model_feedback, can_level_up):
                            logger.info(f"Suggestie van {model} verstuurd voor {team_name}")
                    else:
                        logger.warning(f"Model {model} gaf geen resultaat.")

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
