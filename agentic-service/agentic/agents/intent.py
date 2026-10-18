"""Intentie-agent: vraag, antwoord of overig. Krijgt de opdracht en de hele chat, maar niet het antwoord
van de docent, de rubric of de tussenstand."""
from .. import context, schemas
from . import run, system_prompt


def build_prompt(task, max_tokens=20000):
    return "\n\n".join([
        context.assignment_block(task),
        context.build_chat(task, max_tokens),
        context.new_message_block(task),
        "Wat is de intentie van het NIEUWE bericht?",
    ])


def effective_intent(output):
    """Code-regel: niet zeker = antwoord, wat het model ook zegt."""
    return output["intentie"] if output.get("zeker") else "antwoord"


def classify(llm, settings, task):
    output, meta = run(llm, settings.INTENT_MODEL, settings.INTENT_THINK, system_prompt("intent"),
                       build_prompt(task, settings.MAX_CHAT_TOKENS), schemas.INTENT)
    return effective_intent(output), output, meta
