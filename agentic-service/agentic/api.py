"""Client voor api.php van de webapplicatie (DESIGN §10, API-tabel)."""
import logging

import requests

logger = logging.getLogger(__name__)


class ApiError(Exception):
    def __init__(self, message, status=None, body=None):
        super().__init__(message)
        self.status = status
        self.body = body


class ApiClient:
    def __init__(self, base_url, api_key, agent_id, session=None, timeout=30):
        self.base_url = base_url
        self.api_key = api_key
        self.agent_id = agent_id
        self.session = session or requests.Session()
        self.timeout = timeout

    def _request(self, method, action, body=None, timeout=None):
        # De key alleen in de header: in de URL komt hij in de logs van de webserver terecht
        resp = self.session.request(
            method, self.base_url, params={"action": action}, json=body,
            headers={"X-API-Token": self.api_key, "Content-Type": "application/json"},
            timeout=timeout or self.timeout)
        try:
            data = resp.json()
        except ValueError:
            data = None
        if resp.status_code == 401:
            raise ApiError("Authenticatie mislukt: controleer API_KEY", 401, data)
        return resp.status_code, data

    def _post(self, action, body, ok_statuses=(200,)):
        status, data = self._request("POST", action, dict(body, agent_id=self.agent_id))
        if status not in ok_statuses:
            message = (data or {}).get("error", "") if isinstance(data, dict) else ""
            raise ApiError(f"{action}: HTTP {status} {message}".strip(), status, data)
        return data

    def get_pending(self):
        status, data = self._request("GET", "get_pending")
        if status != 200 or not isinstance(data, list):
            raise ApiError(f"get_pending: HTTP {status}", status, data)
        return data

    def claim_task(self, team_id):
        """True als deze agent het team nu heeft (ook om een claim te verlengen); 409 = bezet."""
        status, _ = self._request("POST", "claim_task", {"team_id": team_id, "agent_id": self.agent_id})
        return status == 200

    def submit_evaluation(self, payload):
        return self._post("submit_evaluation", payload)

    def heartbeat(self, model_ok):
        return self._post("heartbeat", {"model_ok": bool(model_ok)})

    def unregister_agent(self):
        try:
            self._request("POST", "unregister_agent", {"agent_id": self.agent_id}, timeout=5)
        except requests.RequestException as e:
            logger.warning("Afmelden mislukt: %s", e)

    def get_design_jobs(self):
        status, data = self._request("GET", "get_design_jobs")
        if status != 200 or not isinstance(data, list):
            raise ApiError(f"get_design_jobs: HTTP {status}", status, data)
        return data

    def claim_design(self, assignment_id):
        status, _ = self._request("POST", "claim_design", {"assignment_id": assignment_id, "agent_id": self.agent_id})
        return status == 200

    def submit_design(self, payload):
        return self._post("submit_design", payload)
