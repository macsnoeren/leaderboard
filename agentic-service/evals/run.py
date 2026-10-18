"""Evals tegen het echte model.

Gebruik (vanuit agentic-service/, Ollama moet draaien en aangemeld zijn voor de -cloud-modellen):
    python3 evals/run.py                 # beoordelingspijplijn, alle cases in evals/cases/
    python3 evals/run.py 04 11           # alleen cases waarvan de naam zo begint
    python3 evals/run.py --designer      # opdracht-assistent, evals/designer/
Modellen en denkniveaus komen uit config.py (als die er is) of uit de AI_*-variabelen (zie README.md).

Elke case draait in modus 'auto' met een goedgekeurde rubric, zodat zichtbaar wordt wat de AI zelf zou
doen. Het rapport geeft per agent goed/fout en een lijst van onterechte level-ups. Herhaal na elke
promptwijziging en noteer de score in README.md.
"""
import argparse
import glob
import json
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))

from agentic.agents import designer  # noqa: E402
from agentic.llm import LLMClient, LLMError  # noqa: E402
from agentic.pipeline import Pipeline  # noqa: E402
from agentic.settings import from_env, load_settings, local_models  # noqa: E402

PASS = ("voldoende", "goed")


def settings():
    try:
        import config  # noqa: PLC0415
        s = load_settings(config)
    except ImportError:
        s = load_settings(None, **{k: v for k, v in from_env(os.environ).items() if k not in ("API_KEY", "BASE_URL")})
    if local_models(s):
        sys.exit(f"Alleen cloudmodellen zijn toegestaan (CLOUD_ONLY); ingesteld: {', '.join(local_models(s))}")
    return s


def make_task(case, assignment):
    messages = [dict(m, time=f"2026-10-17 10:{i:02d}:00") for i, m in enumerate(case["messages"])]
    last_team = [m for m in messages if m["sender"] == "team"][-1]
    return {
        "team_id": 1, "team_name": "Evalteam", "current_level": 1, "game_status": "running",
        "assignment_title": assignment["assignment_title"], "description": assignment["description"],
        "instruction": assignment["instruction"], "criteria": assignment["criteria"],
        "rubric": assignment["rubric"], "rubric_status": "approved", "clarifications": assignment.get("clarifications", []),
        "ai_mode": case.get("mode", "auto"), "ai_auto_allowed": True, "escalation_open": False,
        "recent_attempts": 0, "recent_questions": 0, "max_attempts": 5, "max_questions": 5,
        "progress": case.get("progress"), "message_id": last_team["id"], "messages": messages,
    }


def output_of(meta, key=None):
    if not meta:
        return {}
    if key and isinstance(meta, dict) and key in meta:
        meta = meta[key]
    if isinstance(meta, list):
        meta = meta[0]
    return meta.get("output") or {}


def check(expected, actual):
    if expected is None:
        return actual is None
    return actual in expected if isinstance(expected, list) else actual == expected


def run_pipeline(filters):
    s = settings()
    llm = LLMClient(s.OLLAMA_URL, timeout=s.LLM_TIMEOUT, cloud_only=s.CLOUD_ONLY)
    pipeline = Pipeline(llm, s)
    assignments = json.load(open(os.path.join(HERE, "assignments.json"), encoding="utf-8"))
    files = sorted(glob.glob(os.path.join(HERE, "cases", "*.json")))
    if filters:
        files = [f for f in files if any(os.path.basename(f).startswith(x) for x in filters)]
    print(f"Modellen: intentie {s.INTENT_MODEL}/{s.INTENT_THINK}, hulp {s.HELPER_MODEL}/{s.HELPER_THINK}, "
          f"beoordelaar {','.join(s.ASSESSOR_MODELS)}/{s.ASSESSOR_THINK}, validator {s.VALIDATOR_MODEL}/{s.VALIDATOR_THINK}\n")

    score = {k: [0, 0] for k in ("intentie-agent", "intentie (na validator)", "beoordelaar", "validator", "mens nodig", "beslissing")}
    wrong_level_ups, rows = [], []
    for path in files:
        case = json.load(open(path, encoding="utf-8"))
        exp = case["expected"]
        start = time.time()
        payload = pipeline.process(make_task(case, assignments[case["assignment"]]), "eval")
        took = time.time() - start
        raw_intent = (payload.get("intent_json") or {}).get("effective")
        assessor_verdict = output_of(payload.get("assessor_json")).get("oordeel")
        validator_verdict = output_of(payload.get("validator_json"), "antwoord").get("eigen_oordeel")
        decision = payload["decision"]
        human = decision == "escalate"

        def tally(name, ok, applicable=True):
            if applicable:
                score[name][0] += int(ok)
                score[name][1] += 1
            return "·" if not applicable else ("✓" if ok else "✗")

        marks = [
            tally("intentie-agent", raw_intent == exp["intent"]),
            # Een overig-bericht wordt als antwoord beoordeeld
            tally("intentie (na validator)", payload["intent"] == ("antwoord" if exp["intent"] == "overig" else exp["intent"])),
            tally("beoordelaar", check(exp["verdict"], assessor_verdict), exp["verdict"] is not None),
            tally("validator", check(exp["verdict"], validator_verdict), exp["verdict"] is not None and validator_verdict is not None),
            tally("mens nodig", human == exp["human"], exp["human"] is not None),
        ]
        # Een beslissing is goed als ze veilig is: level-up alleen bij een verwacht voldoende/goed
        expected_pass = exp["verdict"] is not None and all(v in PASS for v in (exp["verdict"] if isinstance(exp["verdict"], list) else [exp["verdict"]]))
        bad_level_up = decision == "level_up" and (not expected_pass or exp["human"] is True)
        if bad_level_up:
            wrong_level_ups.append(case["name"])
        marks.append(tally("beslissing", not bad_level_up and (decision != "reply" or exp["intent"] == "vraag" or not expected_pass)))
        rows.append(case["name"])
        print(f"{case['name']:<28} {' '.join(marks)}  intentie={raw_intent}/{payload['intent']} oordeel={assessor_verdict}/{validator_verdict} "
              f"beslissing={decision} ({took:.0f}s)")
        if payload.get("reason") and (decision == "escalate" or "✗" in marks):
            print(f"{'':<28} reden: {payload['reason'][:220]}")
    print("\nPer agent (goed/totaal):")
    for name, (ok, total) in score.items():
        print(f"  {name:<26} {ok}/{total}" + (f"  ({ok / total * 100:.0f} %)" if total else ""))
    print(f"\nOnterechte level-ups: {len(wrong_level_ups)}" + (": " + ", ".join(wrong_level_ups) if wrong_level_ups else ""))


def run_designer(filters):
    s = settings()
    llm = LLMClient(s.OLLAMA_URL, timeout=s.LLM_TIMEOUT, cloud_only=s.CLOUD_ONLY)
    files = sorted(glob.glob(os.path.join(HERE, "designer", "*.json")))
    if filters:
        files = [f for f in files if any(os.path.basename(f).startswith(x) for x in filters)]
    print(f"Opdracht-assistent: {s.DESIGNER_MODEL}/{s.DESIGNER_THINK}\n")
    ok = 0
    for path in files:
        case = json.load(open(path, encoding="utf-8"))
        start = time.time()
        try:
            result, _ = designer.design(llm, s, case["job"])
            soort = result["soort"]
        except LLMError as e:
            result, soort = {"fout": str(e)}, "fout"
        good = soort == case["expected"]
        ok += int(good)
        print(f"{case['name']:<22} {'✓' if good else '✗'} verwacht={case['expected']} kreeg={soort} ({time.time() - start:.0f}s)")
        if soort == "vragen":
            for q in result["vragen"]:
                print(f"    ? {q}")
        elif soort == "rubric":
            r = result["rubric"]
            print(f"    kern: {r['kern']}")
            for a in r["aspecten"]:
                print(f"    - {a['naam']} ({'verplicht' if a['verplicht'] else 'optioneel'}): voldoende = {a['voldoende']}")
            print("    verduidelijkingen_vraag (handmatig controleren: verklapt niets?):")
            for v in r.get("verduidelijkingen_vraag", []):
                print(f"      · {v}")
        else:
            print(f"    {result['fout']}")
    print(f"\nScore: {ok}/{len(files)}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Evals tegen het echte model")
    parser.add_argument("filters", nargs="*", help="alleen cases waarvan de naam zo begint")
    parser.add_argument("--designer", action="store_true", help="opdracht-assistent in plaats van de beoordelingspijplijn")
    args = parser.parse_args()
    (run_designer if args.designer else run_pipeline)(args.filters)
