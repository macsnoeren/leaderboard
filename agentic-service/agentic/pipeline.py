"""Pijplijn per teambericht (DESIGN §6, stappen 2–7): voorfilter → intentie → hulp-agent of
beoordelaar → validator → beslisregels → payload voor submit_evaluation.

Elke LLMError geeft een escalatie: nooit een automatische actie als een model faalt.
"""
import logging
import time

from . import context, policy, prefilter
from .agents import assessor, helper, intent, validator
from .llm import LLMError

logger = logging.getLogger(__name__)


def task_context(task, flags):
    return {
        "mode": task.get("ai_mode", "suggest"),
        "rubric_status": task.get("rubric_status", "none"),
        "auto_allowed": bool(task.get("ai_auto_allowed", True)),
        "escalation_open": bool(task.get("escalation_open")),
        "flag_injection": flags["flag_injection"],
        "recent_attempts": int(task.get("recent_attempts", 0)),
        "max_attempts": int(task.get("max_attempts", 5)),
        "recent_questions": int(task.get("recent_questions", 0)),
        "max_questions": int(task.get("max_questions", 5)),
    }


class Pipeline:
    def __init__(self, llm, settings, keepalive=None):
        self.llm = llm
        self.settings = settings
        # Verlengt de claim op het team tussen twee modelaanroepen (claim_task door dezelfde agent)
        self.keepalive = keepalive or (lambda task: None)

    def process(self, task, agent_id):
        """Payload voor submit_evaluation. 'overig' wordt als antwoord beoordeeld (intent 'antwoord');
        de oorspronkelijke intentie staat in intent_json."""
        start = time.monotonic()
        state = {"task": task, "jsons": {}, "models": [], "intent": None}
        message = context.new_message(task)
        flags = prefilter.check(message["text"] if message else "")
        ctx = task_context(task, flags)
        try:
            decision = self._run(state, ctx, flags)
        except LLMError as e:
            logger.warning("LLM-fout bij team %s: %s", task.get("team_id"), e)
            decision = {"decision": "escalate", "reason": f"AI-fout: {e}", "verdict": state.get("verdict"),
                        "reply_text": state.get("reply_text", "")}
            state["jsons"].setdefault("error", str(e))
        payload = {
            "agent_id": agent_id,
            "team_id": task["team_id"],
            "level": task["current_level"],
            "message_id": task["message_id"],
            "intent": state["intent"] or "antwoord",
            "verdict": decision.get("verdict"),
            "decision": decision["decision"],
            "reason": decision.get("reason", ""),
            "reply_text": decision.get("reply_text", ""),
            "models": sorted(set(state["models"])),
            "duration_ms": int((time.monotonic() - start) * 1000),
            "prefilter": flags,
        }
        for key in ("intent_json", "helper_json", "assessor_json", "validator_json"):
            if key in state["jsons"]:
                payload[key] = state["jsons"][key]
        if "error" in state["jsons"]:
            # De agent die faalde krijgt de fout in zijn eigen veld (zichtbaar in ai_log.php)
            failed = state.get("step", "intent_json")
            payload[failed] = payload.get(failed) or {"error": state["jsons"]["error"]}
        if state.get("progress"):
            payload["progress"] = state["progress"]
        return payload

    def _call(self, state, step, fn, *args):
        state["step"] = step
        self.keepalive(state["task"])
        result = fn(self.llm, self.settings, *args)
        meta = result[-1]
        state["models"].append(meta["model"])
        return result

    def _run(self, state, ctx, flags):
        task = state["task"]
        effective, raw, meta = self._call(state, "intent_json", intent.classify, task)
        state["jsons"]["intent_json"] = dict(meta, effective=effective)
        state["intent"] = effective
        if effective == "overig":
            # Ook "ok" of "hoi" wordt beoordeeld: de beoordelaar kijkt naar alles wat het team op dit level gaf
            state["intent"] = "antwoord"
            answer = self._answer(state, ctx)
            answer["reason"] = (f"Intentie overig ({raw.get('toelichting', '')}); toch beoordeeld als antwoord. "
                                + answer["reason"])
            return answer
        if effective == "vraag":
            result = self._question(state, ctx)
            if result is not None:
                return result
            # De validator vond het geen vraag: één keer als antwoord beoordelen
            state["intent"] = "antwoord"
            answer = self._answer(state, ctx)
            answer["reason"] = "Validator: dit was geen vraag; beoordeeld als antwoord. " + answer["reason"]
            return answer
        return self._answer(state, ctx)

    def _question(self, state, ctx):
        task = state["task"]
        out, meta = self._call(state, "helper_json", helper.respond, task)
        state["jsons"]["helper_json"] = meta
        state["reply_text"] = out["reactie_team"]
        vout, vmeta = self._call(state, "validator_json", validator.check_question, task, out)
        state["jsons"]["validator_json"] = {"vraag": vmeta}
        decision, reason = policy.decide_question(ctx, {
            "kan_beantwoorden": out["kan_beantwoorden"],
            "was_vraag": vout["was_vraag"],
            "verklapt_antwoord": vout["verklapt_antwoord"],
            "misleidend": vout["misleidend"],
            "mens_nodig": vout["mens_nodig"],
            "redenen": vout["redenen"],
        })
        if decision == "reassess":
            state["reply_text"] = ""
            return None
        return {"decision": decision, "reason": reason, "verdict": None, "reply_text": out["reactie_team"]}

    def _answer(self, state, ctx):
        task = state["task"]
        # De beoordelaar kan meerdere modellen draaien; daarom niet via _call()
        state["step"] = "assessor_json"
        self.keepalive(task)
        assessment = assessor.assess(self.llm, self.settings, task)
        state["models"].extend(run["model"] for run in assessment["runs"])
        state["jsons"]["assessor_json"] = assessment["runs"][0] if len(assessment["runs"]) == 1 else assessment["runs"]
        main = assessment["output"]
        state["verdict"] = assessment["verdict"]
        state["reply_text"] = main["feedback_team"]
        state["progress"] = {"aspects": main["aspecten"], "summary": main.get("samenvatting", "")}

        vout, vmeta = self._call(state, "validator_json", validator.check_answer, task, main)
        previous = state["jsons"].get("validator_json")
        state["jsons"]["validator_json"] = dict(previous, antwoord=vmeta) if isinstance(previous, dict) and "vraag" in previous else vmeta
        decision, reason = policy.decide_answer(ctx, {
            "verdict": assessment["verdict"],
            "validator_verdict": vout["eigen_oordeel"],
            "akkoord": vout["akkoord"],
            "mens_nodig": vout["mens_nodig"],
            "manipulatie_gedetecteerd": vout["manipulatie_gedetecteerd"],
            "feedback_verklapt": vout["feedback_verklapt_antwoord"],
            "citations_ok": assessment["citations_ok"],
            "consistent": assessment["consistent"],
            "unanimous": assessment["unanimous"],
            "redenen": vout["redenen"],
        })
        note = main.get("toelichting_docent", "").strip()
        return {"decision": decision, "reason": f"{reason}. {note}".strip() if note else reason,
                "verdict": assessment["verdict"], "reply_text": main["feedback_team"]}
