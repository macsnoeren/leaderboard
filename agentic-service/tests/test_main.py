"""De agentloop met mocks; teamberichten gaan voor design-taken."""
import threading
import unittest
from unittest import mock

from agentic.api import ApiError, MaintenanceError
from agentic.llm import LLMError
from main import AgentService
from tests.helpers import RUBRIC, SETTINGS, make_task


class FakePipeline:
    def __init__(self, calls):
        self.calls = calls

    def process(self, task, agent_id):
        self.calls.append(("process", task["team_id"]))
        return {"agent_id": agent_id, "team_id": task["team_id"], "level": 1, "message_id": task["message_id"],
                "intent": "antwoord", "decision": "suggest"}


def make_service(tasks=None, jobs=None, model_ok=True):
    calls = []
    api = mock.Mock()
    api.agent_id = "agent-1"
    api.get_pending.side_effect = lambda: calls.append(("get_pending",)) or (tasks or [])
    api.get_design_jobs.side_effect = lambda: calls.append(("get_design_jobs",)) or (jobs or [])
    api.claim_task.return_value = True
    api.claim_design.return_value = True
    api.submit_evaluation.side_effect = lambda p: calls.append(("submit_evaluation", p["team_id"])) or {"decision": p["decision"]}
    api.submit_design.side_effect = lambda p: calls.append(("submit_design", p["assignment_id"])) or {}
    llm = mock.Mock()
    llm.last_ok = model_ok
    llm.url = "http://fake"
    service = AgentService(SETTINGS, api, llm, pipeline=FakePipeline(calls), stop_event=threading.Event())
    return service, api, calls


JOB = {"assignment_id": 30, "round": 1, "max_rounds": 3, "title": "T", "description": "", "instruction": "I", "criteria": "C", "dialog": []}


class MainTest(unittest.TestCase):
    def test_team_messages_before_design_jobs(self):
        service, _, calls = make_service([make_task(team_id=1), make_task(team_id=2)], [JOB])
        with mock.patch("main.designer.design", return_value=({"soort": "vragen", "vragen": ["V?"]}, {})):
            service.run_once()
        order = [c[0] for c in calls]
        self.assertLess(order.index("submit_evaluation"), order.index("get_design_jobs"))
        self.assertEqual([c for c in calls if c[0] == "submit_evaluation"], [("submit_evaluation", 1), ("submit_evaluation", 2)])
        self.assertEqual(order[-1], "submit_design")

    def test_heartbeat_reports_model_ok(self):
        service, api, _ = make_service(model_ok=False)
        service.run_once()
        api.heartbeat.assert_called_with(False)

    def test_claimed_elsewhere_is_skipped(self):
        service, api, calls = make_service([make_task(team_id=1)])
        api.claim_task.return_value = False
        service.run_once()
        self.assertNotIn(("process", 1), calls)

    def test_design_questions_and_rubric(self):
        service, api, _ = make_service(jobs=[JOB])
        with mock.patch("main.designer.design", return_value=({"soort": "rubric", "rubric": RUBRIC}, {})):
            service.process_design_jobs()
        api.submit_design.assert_called_with({"assignment_id": 30, "rubric": RUBRIC})

    def test_design_llm_error_reports_failure(self):
        service, api, _ = make_service(jobs=[JOB])
        with mock.patch("main.designer.design", side_effect=LLMError("down")):
            service.process_design_jobs()
        self.assertIn("error", api.submit_design.call_args.args[0])

    def test_internal_error_becomes_escalation(self):
        service, api, _ = make_service([make_task(team_id=1)])
        service.pipeline = mock.Mock()
        service.pipeline.process.side_effect = KeyError("kapot")
        service.process_tasks()
        self.assertEqual(api.submit_evaluation.call_args.args[0]["decision"], "escalate")

    def test_submit_error_does_not_crash(self):
        service, api, _ = make_service([make_task(team_id=1)])
        api.submit_evaluation.side_effect = ApiError("409", 409)
        self.assertEqual(service.process_tasks(), 0)

    def test_run_unregisters_on_stop(self):
        service, api, _ = make_service()
        api.heartbeat.side_effect = lambda ok: service.stop_event.set()
        service.run()
        api.unregister_agent.assert_called_once()


class MaintenanceTest(unittest.TestCase):
    """Bij onderhoud (503) wacht de agent, zonder fout en zonder iets in te dienen."""

    def test_503_on_get_pending(self):
        service, api, calls = make_service([make_task(team_id=1)])
        api.get_pending.side_effect = MaintenanceError("get_pending: onderhoudsmodus", 503)
        service.run_once()  # geen exception naar boven
        self.assertFalse([c for c in calls if c[0] == "submit_evaluation"])
        api.get_design_jobs.assert_not_called()

    def test_503_on_claim(self):
        service, api, calls = make_service([make_task(team_id=1)])
        api.claim_task.side_effect = MaintenanceError("claim_task: onderhoudsmodus", 503)
        service.run_once()
        self.assertFalse([c for c in calls if c[0] in ("process", "submit_evaluation")])


if __name__ == "__main__":
    unittest.main()
