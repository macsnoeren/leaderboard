"""Client voor api.php van de webapplicatie."""
import logging
import sqlite3

import requests

logger = logging.getLogger(__name__)


class ApiError(Exception):
    def __init__(self, message, status=None, body=None):
        super().__init__(message)
        self.status = status
        self.body = body


class MaintenanceError(ApiError):
    """503: de webapp staat in onderhoudsmodus (een restore door de worker). Geen fout: even wachten."""


def read_api_key(db_path, key_name):
    """De key `key_name` uit api_keys in de database van de webapp (alleen lezen), of None."""
    try:
        db = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True, timeout=5)
        try:
            row = db.execute("SELECT api_key FROM api_keys WHERE key_name = ? LIMIT 1", (key_name,)).fetchone()
        finally:
            db.close()
    except sqlite3.Error as e:
        logger.warning("API-key niet te lezen uit %s: %s", db_path, e)
        return None
    return row[0] if row and row[0] else None


class ApiClient:
    """`key_loader` (optioneel) geeft de huidige key: bij een 401 leest de client die opnieuw en probeert
    het verzoek één keer met de nieuwe key, zodat de agent na een restore niet herstart hoeft te worden."""

    def __init__(self, base_url, api_key, agent_id, session=None, timeout=30, key_loader=None):
        self.base_url = base_url
        self.api_key = api_key
        self.agent_id = agent_id
        self.session = session or requests.Session()
        self.timeout = timeout
        self.key_loader = key_loader

    def _reload_key(self):
        """True als er een andere key is dan de huidige."""
        if self.key_loader is None:
            return False
        try:
            key = self.key_loader()
        except Exception as e:  # noqa: BLE001 - dan blijft het bij de 401
            logger.warning("API-key opnieuw inlezen mislukt: %s", e)
            return False
        if not key or key == self.api_key:
            return False
        self.api_key = key
        logger.info("Nieuwe API-key ingelezen na een 401")
        return True

    def _request(self, method, action, body=None, timeout=None, params=None):
        for attempt in range(2):
            # De key alleen in de header: in de URL komt hij in de logs van de webserver terecht
            resp = self.session.request(
                method, self.base_url, params=dict(params or {}, action=action), json=body,
                headers={"X-API-Token": self.api_key, "Content-Type": "application/json"},
                timeout=timeout or self.timeout)
            # Bij een 401 één nieuwe poging, alleen als er inmiddels een andere key is
            if resp.status_code != 401 or attempt or not self._reload_key():
                break
        try:
            data = resp.json()
        except ValueError:
            data = None
        if resp.status_code == 401:
            raise ApiError("Authenticatie mislukt: controleer API_KEY", 401, data)
        if resp.status_code == 503:
            raise MaintenanceError(f"{action}: onderhoudsmodus", 503, data)
        return resp.status_code, data

    def _post(self, action, body, ok_statuses=(200,)):
        status, data = self._request("POST", action, dict(body, agent_id=self.agent_id))
        if status not in ok_statuses:
            message = (data or {}).get("error", "") if isinstance(data, dict) else ""
            raise ApiError(f"{action}: HTTP {status} {message}".strip(), status, data)
        return data

    def get_pending(self, limit=None):
        """De wachtrij, oudste bericht eerst; `limit` = alleen de eerste zoveel."""
        status, data = self._request("GET", "get_pending", params={"limit": limit} if limit else None)
        if status != 200 or not isinstance(data, list):
            raise ApiError(f"get_pending: HTTP {status}", status, data)
        return data

    def claim_task(self, team_id):
        """True als deze agent het team nu heeft (ook om een claim te verlengen); 409 = bezet."""
        status, _ = self._request("POST", "claim_task", {"team_id": team_id, "agent_id": self.agent_id})
        return status == 200

    def submit_evaluation(self, payload):
        return self._post("submit_evaluation", payload)

    def claim_replay(self):
        """De oudste AI-replay in de wachtrij, met alle gegevens (zie aiReplayData() in conf/ai.php), of None."""
        return (self._post("claim_replay", {}) or {}).get("job")

    def replay_progress(self, job_id, progress, message):
        """False als de replay niet (meer) van deze agent is (bijv. mislukt verklaard na een lange stilte)."""
        status, _ = self._request("POST", "replay_progress", {"job_id": job_id, "agent_id": self.agent_id,
                                                               "progress": int(progress), "message": message})
        return status == 200

    def submit_replay(self, job_id, html=None, error=None):
        body = {"job_id": job_id}
        body.update({"html": html} if error is None else {"error": error})
        return self._post("submit_replay", body, ok_statuses=(200,))

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
