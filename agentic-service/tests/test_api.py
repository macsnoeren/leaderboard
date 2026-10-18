"""Client voor api.php: URL, methode, header X-API-Token en body; 409 bij claim = False."""
import unittest
from unittest import mock

from agentic.api import ApiClient, ApiError, MaintenanceError


def response(status=200, data=None):
    r = mock.Mock()
    r.status_code = status
    r.json.return_value = data if data is not None else {"status": "success"}
    return r


class ApiTest(unittest.TestCase):
    def setUp(self):
        self.session = mock.Mock()
        self.api = ApiClient("http://app/api.php", "geheim", "agent-1", session=self.session)

    def last(self):
        args, kwargs = self.session.request.call_args
        return args, kwargs

    def test_get_pending(self):
        self.session.request.return_value = response(data=[{"team_id": 1}])
        self.assertEqual(self.api.get_pending(), [{"team_id": 1}])
        (method, url), kwargs = self.last()
        self.assertEqual((method, url), ("GET", "http://app/api.php"))
        self.assertEqual(kwargs["params"], {"action": "get_pending"})
        self.assertEqual(kwargs["headers"]["X-API-Token"], "geheim")

    def test_claim_task(self):
        self.session.request.return_value = response()
        self.assertTrue(self.api.claim_task(5))
        (method, _), kwargs = self.last()
        self.assertEqual(method, "POST")
        self.assertEqual(kwargs["params"], {"action": "claim_task"})
        self.assertEqual(kwargs["json"], {"team_id": 5, "agent_id": "agent-1"})

    def test_claim_conflict_is_false(self):
        self.session.request.return_value = response(409, {"error": "Task already claimed"})
        self.assertFalse(self.api.claim_task(5))
        self.assertFalse(self.api.claim_design(5))

    def test_submit_evaluation(self):
        self.session.request.return_value = response(data={"status": "success", "decision": "suggest"})
        self.assertEqual(self.api.submit_evaluation({"team_id": 1, "decision": "suggest"})["decision"], "suggest")
        _, kwargs = self.last()
        self.assertEqual(kwargs["params"], {"action": "submit_evaluation"})
        self.assertEqual(kwargs["json"]["agent_id"], "agent-1")
        self.assertEqual(kwargs["json"]["team_id"], 1)

    def test_submit_error_raises(self):
        self.session.request.return_value = response(400, {"error": "Ongeldige intent"})
        with self.assertRaises(ApiError) as ctx:
            self.api.submit_evaluation({"team_id": 1})
        self.assertEqual(ctx.exception.status, 400)

    def test_heartbeat_model_ok(self):
        self.session.request.return_value = response()
        self.api.heartbeat(False)
        _, kwargs = self.last()
        self.assertEqual(kwargs["params"], {"action": "heartbeat"})
        self.assertEqual(kwargs["json"], {"model_ok": False, "agent_id": "agent-1"})

    def test_unauthorized(self):
        self.session.request.return_value = response(401, {"error": "Unauthorized"})
        with self.assertRaises(ApiError):
            self.api.get_pending()

    def test_design_actions(self):
        self.session.request.return_value = response(data=[{"assignment_id": 3}])
        self.assertEqual(self.api.get_design_jobs(), [{"assignment_id": 3}])
        self.session.request.return_value = response()
        self.assertTrue(self.api.claim_design(3))
        self.assertEqual(self.last()[1]["json"], {"assignment_id": 3, "agent_id": "agent-1"})
        self.api.submit_design({"assignment_id": 3, "questions": ["Vraag?"]})
        self.assertEqual(self.last()[1]["params"], {"action": "submit_design"})

    def test_token_not_in_url(self):
        self.session.request.return_value = response(data=[])
        self.api.get_pending()
        _, kwargs = self.last()
        self.assertNotIn("token", kwargs["params"])

    def test_unregister(self):
        self.session.request.return_value = response()
        self.api.unregister_agent()
        self.assertEqual(self.last()[1]["params"], {"action": "unregister_agent"})


class MaintenanceApiTest(unittest.TestCase):
    def test_503_raises_maintenance(self):
        session = mock.Mock()
        session.request.return_value = response(503, {"error": "maintenance"})
        api = ApiClient("http://app/api.php", "geheim", "agent-1", session=session)
        with self.assertRaises(MaintenanceError):
            api.get_pending()


if __name__ == "__main__":
    unittest.main()
