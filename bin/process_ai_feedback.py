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
    question_text = str(q.get('question_text') or "")
    criteria = str(q.get('criteria') or "")
    answer = str(q.get('answer') or "")

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
        params={
            "action": "open_student_answers",
            "api_key": API_KEY,
            "limit": 5
        },
        timeout=30
    )

    data = response.json()
    if "answers" in data:
        return data.get("answers", [])
    else:
        print("Kan geen de studentantwoorden ophalen.")
        return []

# =========================
# FEEDBACK VERSTUREN
# =========================

def submit_ai_feedback(
    student_answer_id: int,
    feedback_text: str
):
    """
    Verstuurt de AI feedback naar de backend.
    """

    payload = {
        "api_key": API_KEY,
        "student_answer_id": student_answer_id,
        "ai_feedback": feedback_text
    }

    requests.post(
        f"{BASE_URL}?action=submit_ai_feedback",
        json=payload,
        timeout=180,
        params={
            "api_key": API_KEY,
        }
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
                failed = False

                for model in LLM_MODELS:
                    print(f"Feedback opvragen voor student_answer_id {q['student_answer_id']} met model {model}")
                    
                    result = get_feedback_from_model(q, model)
                    fetch_open_student_answers() # Do nothing, just for show that the parser is active.

                    if result:
                        all_feedback.append(
                            f"Model: {model}\n"
                            f"Tijdsduur: {result['duration']:.2f}s\n"
                            f"Aantal punten: {result['score']}\n"
                            f"Feedback: {result['feedback']}"
                        )
                    else:
                        print(f"Model {model} faalde voor antwoord {q['student_answer_id']}. Feedback wordt niet verstuurd.")
                        failed = True
                        break

                if failed or not all_feedback:
                    continue

                final_feedback = "\n\n".join(all_feedback)

                submit_ai_feedback(
                    student_answer_id=q["student_answer_id"],
                    feedback_text=final_feedback
                )

                print(
                    f"Feedback verstuurd voor student_answer_id "
                    f"{q['student_answer_id']}"
                )

        except Exception as e:
            print("Onverwachte fout:", e)

        print(f"Wachten {POLL_INTERVAL} seconden...\n")
        time.sleep(POLL_INTERVAL)


# =========================
# START SCRIPT
# =========================

if __name__ == "__main__":
    run()
