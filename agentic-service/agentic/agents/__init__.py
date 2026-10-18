"""De LLM-agents. Elke agent bouwt zijn eigen prompt met agentic.context en krijgt alleen wat hij nodig
heeft (README, "Context"). Uitkomst: (uitvoer, meta) met meta = model, think, duur,
uitvoer en redenering; meta gaat als *_json naar submit_evaluation (zichtbaar in ai_log.php).
"""
import os
import re

from ..context import DATA_NOTICE

_PROMPT_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "prompts")


def load_prompt(name, section=None):
    """prompts/<name>.md; met `section` het gemeenschappelijke deel plus <!-- section: … -->."""
    with open(os.path.join(_PROMPT_DIR, f"{name}.md"), encoding="utf-8") as f:
        text = f.read()
    parts = re.split(r"<!--\s*section:\s*(\w+)\s*-->", text)
    common = parts[0].strip()
    sections = dict(zip(parts[1::2], (p.strip() for p in parts[2::2])))
    if section is None:
        return common
    if section not in sections:
        raise KeyError(f"sectie '{section}' ontbreekt in {name}.md")
    return f"{common}\n\n{sections[section]}"


def system_prompt(name, section=None):
    return f"{load_prompt(name, section)}\n\n{DATA_NOTICE}"


def run(llm, model, think, system, user, schema, check=None):
    output, thinking, duration_ms = llm.chat_json(model, think, system, user, schema, check=check)
    return output, {"model": model, "think": think, "duration_ms": duration_ms, "output": output, "thinking": thinking}
