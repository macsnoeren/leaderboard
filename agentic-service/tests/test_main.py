"""De agentloop met mocks; teamberichten gaan voor design-taken, met een verse lijst vóór elk team."""
import threading
import types
import unittest
from unittest import mock

from agentic.api import ApiError, MaintenanceError
from agentic.llm import LLMError
from main import AgentService, key_loader
from tests.helpers import RUBRIC, SETTINGS, make_task


class FakePipeline:
    def __init__(self, calls):
        self.calls = calls

    def process(self, task, agent_id):
        self.calls.append(("process", task["team_id"]))
        return {"agent_id": agent_id, "team_id": task["team_id"], "level": 1, "message_id": task["message_id"],
                "intent": "antwoord", "decision": "suggest"}


def make_service(tasks=None, jobs=None, model_ok=True):
    """Met een nep-wachtrij zoals de server: een geclaimd team staat er niet meer in (ook niet voor deze agent),
    een beoordeeld bericht ook niet."""
    calls = []
    queue = list(tasks or [])
    claimed = set()
    api = mock.Mock()
    api.agent_id = "agent-1"
    api.queue = queue

    def get_pending(limit=None):
        calls.append(("get_pending", limit))
        pending = [t for t in queue if t["team_id"] not in claimed]
        return pending[:limit] if limit else pending

    def claim_task(team_id):
        claimed.add(team_id)
        return True

    def submit_evaluation(payload):
        calls.append(("submit_evaluation", payload["team_id"]))
        queue[:] = [t for t in queue if (t["team_id"], t["message_id"]) != (payload["team_id"], payload["message_id"])]
        claimed.discard(payload["team_id"])
        return {"decision": payload["decision"]}

    api.get_pending.side_effect = get_pending
    api.get_design_jobs.side_effect = lambda: calls.append(("get_design_jobs",)) or (jobs or [])
    api.claim_task.side_effect = claim_task
    api.claim_design.return_value = True
    api.claim_replay.return_value = None
    api.submit_evaluation.side_effect = submit_evaluation
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
        api.claim_task.side_effect = lambda team_id: False
        service.run_once()
        self.assertNotIn(("process", 1), calls)

    def test_fresh_list_before_each_team(self):
        service, _, calls = make_service([make_task(team_id=1), make_task(team_id=2), make_task(team_id=3)])
        self.assertEqual(service.process_tasks(), 3)
        order = [c for c in calls if c[0] in ("get_pending", "process")]
        self.assertEqual(order, [("get_pending", 5), ("process", 1), ("get_pending", 5), ("process", 2),
                                 ("get_pending", 5), ("process", 3), ("get_pending", 5)])

    def test_newer_message_while_waiting(self):
        # Team 2 stuurt een nieuw bericht terwijl team 1 wordt beoordeeld: dan het nieuwe bericht, niet het oude
        service, api, calls = make_service([make_task(team_id=1), make_task(team_id=2, message_id=20)])
        processed = []
        real = service.pipeline.process

        def process(task, agent_id):
            processed.append((task["team_id"], task["message_id"]))
            if task["team_id"] == 1:
                api.queue[1] = make_task(team_id=2, message_id=21)
            return real(task, agent_id)

        service.pipeline.process = process
        service.process_tasks()
        self.assertEqual(processed, [(1, 11), (2, 21)])

    def test_answered_while_waiting_is_skipped(self):
        # Een operator antwoordt team 2 terwijl team 1 wordt beoordeeld: team 2 valt uit de wachtrij
        service, api, calls = make_service([make_task(team_id=1), make_task(team_id=2)])
        real = service.pipeline.process

        def process(task, agent_id):
            del api.queue[1:]
            return real(task, agent_id)

        service.pipeline.process = process
        service.process_tasks()
        self.assertNotIn(("process", 2), calls)

    def test_other_agent_claims_first_in_batch(self):
        service, api, calls = make_service([make_task(team_id=1), make_task(team_id=2)])
        api.claim_task.side_effect = lambda team_id: team_id != 1
        service.process_tasks()
        self.assertEqual([c for c in calls if c[0] == "process"], [("process", 2)])

    def test_failed_submit_does_not_loop(self):
        # Blijft een team in de lijst staan (bijv. omdat de claim al verlopen is), dan nog maar één keer per ronde
        service, api, calls = make_service([make_task(team_id=1)])
        api.claim_task.side_effect = lambda team_id: True
        api.submit_evaluation.side_effect = ApiError("HTTP 500", 500)
        self.assertEqual(service.process_tasks(), 0)
        self.assertEqual([c for c in calls if c[0] == "process"], [("process", 1)])

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

    def test_cancelled_task_is_not_submitted(self):
        from agentic.pipeline import TaskCancelled
        service, api, _ = make_service([make_task(team_id=1)])
        service.pipeline = mock.Mock()
        service.pipeline.process.side_effect = TaskCancelled("geannuleerd")
        self.assertEqual(service.process_tasks(), 0)
        api.submit_evaluation.assert_not_called()

    def test_submit_error_does_not_crash(self):
        service, api, _ = make_service([make_task(team_id=1)])
        api.submit_evaluation.side_effect = ApiError("409", 409)
        self.assertEqual(service.process_tasks(), 0)

    def test_run_unregisters_on_stop(self):
        service, api, _ = make_service()
        api.heartbeat.side_effect = lambda ok: service.stop_event.set()
        service.run()
        api.unregister_agent.assert_called_once()


class FakeReplay:
    """Een replay met `n` stappen; `fail_at` = stap die een fout geeft."""
    def __init__(self, job, llm, settings, n=3, fail_at=None):
        self.job_id = job["job_id"]
        self.steps = list(range(n))
        self.pos = 0
        self.fail_at = fail_at

    @property
    def done(self):
        return self.pos >= len(self.steps)

    @property
    def percent(self):
        return int(self.pos * 100 / len(self.steps))

    def step(self):
        if self.pos == self.fail_at:
            raise KeyError("kapot")
        self.pos += 1
        return f"stap {self.pos}"


class ReplayLoopTest(unittest.TestCase):
    def service(self, **fake):
        service, api, calls = make_service()
        api.claim_replay.side_effect = [{"job_id": 9}, None]
        api.replay_progress.return_value = True
        patcher = mock.patch("main.ReplayRun", side_effect=lambda job, llm, settings: FakeReplay(job, llm, settings, **fake))
        patcher.start()
        self.addCleanup(patcher.stop)
        render = mock.patch("main.render_replay", return_value="<html>rapport</html>")
        render.start()
        self.addCleanup(render.stop)
        return service, api

    def test_replay_runs_after_teams_and_submits_report(self):
        service, api = self.service()
        service.run_once()
        self.assertEqual([c.args for c in api.replay_progress.call_args_list], [(9, 33, "stap 1"), (9, 66, "stap 2"), (9, 100, "stap 3")])
        api.submit_replay.assert_called_once_with(9, html="<html>rapport</html>")
        self.assertIsNone(service.replay)

    def test_slice_gives_teams_a_turn(self):
        # Na REPLAY_SLICE_SECONDS stopt de ronde; de volgende ronde kijkt eerst weer naar de teams
        service, api = self.service(n=5)
        ticks = iter([0, 10, 70, 70, 80])
        self.assertTrue(service.process_replay(clock=lambda: next(ticks)))
        self.assertEqual(api.replay_progress.call_count, 2)
        api.submit_replay.assert_not_called()
        service.process_replay(clock=lambda: 0)
        api.submit_replay.assert_called_once()

    def test_progress_refused_stops_without_report(self):
        service, api = self.service()
        api.replay_progress.return_value = False
        self.assertFalse(service.process_replay())
        api.submit_replay.assert_not_called()
        self.assertIsNone(service.replay)

    def test_error_is_reported(self):
        service, api = self.service(fail_at=1)
        service.process_replay()
        self.assertIn("Interne fout", api.submit_replay.call_args.kwargs["error"])
        self.assertIsNone(service.replay)

    def test_stopping_agent_fails_the_replay(self):
        service, api = self.service(n=5)
        service.process_replay(clock=iter([0, 61]).__next__)
        api.heartbeat.side_effect = lambda ok: service.stop_event.set()
        service.run()
        self.assertIn("gestopt", api.submit_replay.call_args.kwargs["error"])


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


class KeyLoaderTest(unittest.TestCase):
    def test_database_when_configured(self):
        settings = mock.Mock(API_KEY_DB="/data/leaderboard.db", API_KEY_NAME="docker-agent")
        with mock.patch("main.read_api_key", return_value="uit-db") as read:
            self.assertEqual(key_loader(settings, None)(), "uit-db")
        read.assert_called_once_with("/data/leaderboard.db", "docker-agent")

    def test_otherwise_config_is_reread(self):
        settings = mock.Mock(API_KEY_DB="")
        config = mock.Mock()
        with mock.patch("main.importlib.reload", side_effect=lambda m: types.SimpleNamespace(API_KEY="aangepast")) as reload:
            self.assertEqual(key_loader(settings, config)(), "aangepast")
        reload.assert_called_once_with(config)


if __name__ == "__main__":
    unittest.main()
