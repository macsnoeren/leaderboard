"""Hulp-agent: reageert op een verduidelijkende vraag zonder het antwoord te geven. Krijgt alleen de
beschrijving, de instructie, de verduidelijkingen van de vraag en de chat: niet de criteria, niet de
rest van de rubric en niet de tussenstand (wat hij niet weet, kan hij niet verklappen)."""
from .. import context, schemas
from . import run, system_prompt


def build_prompt(task, max_tokens=20000):
    return "\n\n".join([
        context.assignment_block(task, criteria=False),
        context.question_clarifications_block(task.get("rubric")),
        context.build_chat(task, max_tokens),
        context.new_message_block(task),
        "Schrijf de reactie op de vraag in het NIEUWE bericht.",
    ])


def respond(llm, settings, task):
    return run(llm, settings.HELPER_MODEL, settings.HELPER_THINK, system_prompt("helper"),
               build_prompt(task, settings.MAX_CHAT_TOKENS), schemas.HELPER)
