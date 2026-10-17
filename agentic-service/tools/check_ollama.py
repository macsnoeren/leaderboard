"""Controleert of Ollama de gpt-oss-cloudmodellen met `format` (JSON-schema) en `think` goed afhandelt.

Gebruik (vanuit agentic-service/):
    python3 tools/check_ollama.py [--url http://localhost:11434/api/chat] [--model gpt-oss:20b-cloud ...]

Rapporteert per model: antwoord ontvangen, JSON geldig volgens het schema, message.thinking aanwezig en
de duur. Exitcode 1 als een model niet bruikbaar is.
"""
import argparse
import json
import os
import sys
import time

import requests

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
from agentic.schemas import validate  # noqa: E402

SCHEMA = {
    "type": "object",
    "properties": {
        "intentie": {"type": "string", "enum": ["vraag", "antwoord", "overig"]},
        "zeker": {"type": "boolean"},
        "toelichting": {"type": "string"},
    },
    "required": ["intentie", "zeker", "toelichting"],
}

PROMPT = ("Een team in een detectivespel schrijft: \"Is het de conciërge?\". "
          "Is dit een verduidelijkende vraag over de opdracht, een antwoord (ook een gok in vraagvorm "
          "telt als antwoord) of iets anders? Antwoord in JSON met de velden intentie, zeker en toelichting.")


def check(url, model, think, timeout, schema_in_prompt=False):
    content = PROMPT
    if schema_in_prompt:
        content += ("\n\nGeef alleen één JSON-object, zonder uitleg of codeblok, volgens dit JSON-schema:\n"
                    + json.dumps(SCHEMA, ensure_ascii=False))
    body = {
        "model": model,
        "messages": [{"role": "user", "content": content}],
        "format": SCHEMA,
        "think": think,
        "stream": False,
        "options": {"temperature": 0},
    }
    start = time.time()
    result = {"model": model, "think": think, "received": False, "json_valid": False,
              "thinking": False, "duration_s": None, "error": None, "content": None}
    try:
        resp = requests.post(url, json=body, timeout=timeout)
        result["duration_s"] = round(time.time() - start, 2)
        if resp.status_code != 200:
            result["error"] = f"HTTP {resp.status_code}: {resp.text[:200]}"
            return result
        message = resp.json().get("message", {})
        content = message.get("content", "")
        result["received"] = bool(content)
        result["content"] = content[:200]
        result["thinking"] = bool(message.get("thinking"))
        try:
            errors = validate(json.loads(content), SCHEMA)
            result["json_valid"] = not errors
            if errors:
                result["error"] = "; ".join(errors)
        except ValueError as e:
            result["error"] = f"geen JSON: {e}"
    except requests.RequestException as e:
        result["duration_s"] = round(time.time() - start, 2)
        result["error"] = str(e)
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--url", default=os.environ.get("OLLAMA_URL", "http://localhost:11434/api/chat"))
    parser.add_argument("--model", action="append", help="model (meerdere keren mogelijk)")
    parser.add_argument("--think", default="low", help="low | medium | high")
    parser.add_argument("--runs", type=int, default=3, help="aantal aanroepen per model")
    parser.add_argument("--timeout", type=int, default=180)
    parser.add_argument("--schema-in-prompt", action="store_true",
                        help="zet het schema ook in de prompt (zoals llm.py doet)")
    args = parser.parse_args()
    url = args.url.replace("/api/generate", "/api/chat")
    models = args.model or ["gpt-oss:20b-cloud", "gpt-oss:120b-cloud"]

    ok = True
    for model in models:
        for run in range(1, args.runs + 1):
            r = check(url, model, args.think, args.timeout, args.schema_in_prompt)
            usable = r["received"] and r["json_valid"]
            ok = ok and usable
            print(f"{model} (run {run}, think={args.think}, schema_in_prompt={args.schema_in_prompt}): antwoord={'ja' if r['received'] else 'nee'}, "
                  f"json_geldig={'ja' if r['json_valid'] else 'nee'}, thinking={'ja' if r['thinking'] else 'nee'}, "
                  f"duur={r['duration_s']}s" + (f", fout={r['error']}" if r["error"] else ""))
            if r["content"]:
                print(f"    {r['content']}")
    print("\nRespecteert format: " + ("ja" if ok else "nee (zie fouten hierboven)"))
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
