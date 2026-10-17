"""Validator: controleert onafhankelijk (bij voorkeur een ander, groter model) de beoordeling of de
reactie van de hulp-agent. Krijgt wél het antwoord van de docent, de rubric en de tussenstand."""
import json

from .. import context, schemas
from . import run, system_prompt


def _base(task, max_tokens):
    return [
        context.assignment_block(task, criteria=True),
        context.rubric_block(task.get("rubric")),
        context.clarifications_block(task),
        context.progress_block(task),
        context.build_chat(task, max_tokens, context.progress_summary(task)),
        context.new_message_block(task),
    ]


def build_answer_prompt(task, assessment, max_tokens=20000):
    return "\n\n".join(_base(task, max_tokens) + [
        context.block("UITKOMST BEOORDELAAR", json.dumps(assessment, ensure_ascii=False, indent=1)),
        "Controleer deze beoordeling.",
    ])


def build_question_prompt(task, helper_output, max_tokens=20000):
    return "\n\n".join(_base(task, max_tokens) + [
        context.block("REACTIE HULP-AGENT", json.dumps(helper_output, ensure_ascii=False, indent=1)),
        "Controleer deze reactie op de vraag van het team.",
    ])


def check_answer(llm, settings, task, assessment):
    return run(llm, settings.VALIDATOR_MODEL, settings.VALIDATOR_THINK, system_prompt("validator", "antwoord"),
               build_answer_prompt(task, assessment, settings.MAX_CHAT_TOKENS), schemas.VALIDATOR_ANSWER)


def check_question(llm, settings, task, helper_output):
    return run(llm, settings.VALIDATOR_MODEL, settings.VALIDATOR_THINK, system_prompt("validator", "vraag"),
               build_question_prompt(task, helper_output, settings.MAX_CHAT_TOKENS), schemas.VALIDATOR_QUESTION)
