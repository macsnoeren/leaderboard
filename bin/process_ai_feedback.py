# Copyright (C) 2025 JMNL Innovation.
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.

import requests
import json
import time
from typing import List, Dict, Optional
import re
from config import API_KEY, BASE_URL, OLLAMA_URL, LLM_MODELS, POLL_INTERVAL

# =========================
# LLM FEEDBACK FUNCTIE
# =========================

def extract_json(data) -> Optional[Dict]:
    """
    Probeert een dict te maken van Ollama output.

    - Accepteert string of dict
    - Verwijdert Markdown codeblocks zoals ```json ... ```
    """
    if isinstance(data, dict):
        return data

    if not isinstance(data, str):
        return None

    # Verwijder eventuele ```json ... ``` of ``` ... ```
    cleaned = re.sub(r'```(?:json)?\n?|```', '', data)

    # Zoek eerste {...} in de tekst
    match = re.search(r'\{.*\}', cleaned, re.DOTALL)
    if not match:
        return None

    try:
        return json.loads(match.group())
    except json.JSONDecodeError:
        return None

def get_feedback_from_model(
    q: Dict,
    model_name: str
) -> Optional[Dict]:
    """
    Vraagt feedback op bij één LLM-model.

    :param q: Studentantwoord object uit de API
    :param model_name: Naam van het LLM-model (Ollama)
    :return: Dict met score en feedback of None bij fout
    """

    # Haal waarden op en zorg dat ze strings zijn (voorkom NoneType errors)
    question_text = str(q.get('instruction') or "")
    criteria = str(q.get('criteria') or "")
    answer = str(q.get('last_team_message') or "")

    # Gebruik de prompt uit de database als die er is, anders de hardcoded fallback
    if q.get('prompt_text'):
        print(f"[{model_name}] Gebruikt custom prompt uit database.")
        prompt = q['prompt_text']
        prompt = prompt.replace('{{question_text}}', question_text)
        prompt = prompt.replace('{{criteria}}', criteria)
        prompt = prompt.replace('{{student_answer}}', answer)
    else:
        prompt = f"""
Negeer alle eerdere context.

Je bent een automatisch beoordelingssysteem.
Je mag GEEN uitleg, analyse of extra tekst geven.

TAKEN:
- Beoordeel het antwoord van de student.
- Ken punten toe: 0, 1, 5 of 10.
- 10 punten wanneer het juiste antwoord wordt gegeven.
- 5 punten als het antwoord in de buurt komt.
- 1 punt als er enigzins iets zinnigs in staat.
- Geef korte feedback aan de student in de je-vorm.
- Geef een korte uitleg wat beter kan in de je-vorm.

GESTELDE VRAAG AAN STUDENT:
{question_text}

HET JUISTE ANTWOORD EN CRITERIA:
{criteria}

REGELS:
- Geef ALLEEN de onderstaande output.
- Gebruik exact deze labels.
- Voeg niets toe.
- Gebruik maximaal 4 zinnen feedback.

OUTPUTFORMAAT JSON exact (verplicht):
{{ 
    "score": <0-10>,
    "feedback": "<tekst>",
    "uitleg": "<tekst>"
}}

STUDENTANTWOORD:
{answer}
"""
    
    payload = {
        "model": model_name,
        "prompt": prompt,
        "stream": False
    }

    try:
        start_time = time.time()
        response = requests.post(OLLAMA_URL, json=payload, timeout=600)
        end_time = time.time()
        duration = end_time - start_time
        data = response.json()
        raw = data.get("response", "")
        parsed = extract_json(raw)

        if not parsed:
            print(f"[{model_name}] Kon geen geldige JSON vinden ({raw})")
            print("RAW OUTPUT:", raw)
            return None
        
        parsed['duration'] = duration
        return parsed

    except json.JSONDecodeError:
        print(f"[{model_name}] JSON parsing mislukt: {parsed}")
    except requests.RequestException as e:
        print(f"[{model_name}] Request error:", e)

    return None

# =========================
# STUDENTANTWOORDEN OPHALEN
# =========================

def fetch_open_student_answers() -> List[Dict]:
    """
    Haalt openstaande studentantwoorden op uit de API.
    """

    response = requests.get(
        BASE_URL,
        headers={"X-API-Token": API_KEY},
        params={
            "action": "get_pending"
        },
        timeout=30
    )

    if response.status_code != 200:
        print(f"API Fout: Status {response.status_code}")
        print("Response:", response.text)
        return []

    try:
        return response.json()
    except Exception as e:
        print(f"JSON Decodeer fout: {e}")
        print("Raw output van server:", response.text)
        return []

# =========================
# FEEDBACK VERSTUREN
# =========================

def submit_ai_feedback(
    team_id: int,
    feedback_text: str,
    should_level_up: bool = False
):
    """
    Verstuurt de AI feedback naar de backend.
    """

    payload = {
        "team_id": team_id,
        "message": feedback_text,
        "level_up": should_level_up
    }

    requests.post(
        f"{BASE_URL}?action=send_suggestion",
        headers={"X-API-Token": API_KEY},
        json=payload,
        timeout=180
    )


# =========================
# HOOFDLOOP
# =========================

def run():
    """
    Hoofdproces:
    - Loopt continu
    - Checkt elke 30 seconden op nieuwe antwoorden
    - Genereert feedback met meerdere LLM-modellen
    """

    print("AI feedback service gestart...")

    while True:
        try:
            answers = fetch_open_student_answers()

            if not answers:
                print("Geen nieuwe studentantwoorden.")
            else:
                print(f"{len(answers)} nieuwe antwoorden gevonden.")

            for q in answers:
                all_feedback = []
                total_score = 0
                failed = False

                for model in LLM_MODELS:
                    print(f"Feedback opvragen voor team {q['team_name']} met model {model}")
                    
                    result = get_feedback_from_model(q, model)
                    total_score += result.get('score', 0) if result else 0

                    if result:
                        all_feedback.append(
                            f"Model: {model}\n"
                            f"Tijdsduur: {result['duration']:.2f}s\n"
                            f"Aantal punten: {result['score']}\n"
                            f"Feedback: {result['feedback']}"
                        )
                    else:
                        print(f"Model {model} faalde. Feedback wordt niet verstuurd.")
                        failed = True
                        break

                if failed or not all_feedback:
                    continue

                final_feedback = "\n\n".join(all_feedback)
                avg_score = total_score / len(LLM_MODELS)
                can_level_up = avg_score >= 7.0

                submit_ai_feedback(
                    team_id=q["team_id"],
                    feedback_text=final_feedback,
                    should_level_up=can_level_up
                )

                print(f"Suggestie verstuurd voor team {q['team_name']} (Advies Level Up: {can_level_up})")

        except Exception as e:
            print("Onverwachte fout:", e)

        print(f"Wachten {POLL_INTERVAL} seconden...\n")
        time.sleep(POLL_INTERVAL)


# =========================
# START SCRIPT
# =========================

if __name__ == "__main__":
    run()
