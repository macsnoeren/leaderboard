"""AI-agent voor OperationRoom: beoordeelt teamberichten en helpt docenten met rubrics (README.md).

Starten: python3 main.py (met config.py hiernaast; zie config.py.sample). Stoppen met Ctrl+C of SIGTERM:
de agent meldt zich dan netjes af.

Per ronde: heartbeat (met model_ok), eerst de teamberichten (een lopend spel gaat voor), daarna de
opdrachten waar de opdracht-assistent naar moet kijken, en dan een stuk van een AI-replay (hooguit
REPLAY_SLICE_SECONDS, zodat nieuwe teamberichten niet lang wachten).
"""
import importlib
import logging
import signal
import threading
import time
import uuid

from agentic.agents import designer
from agentic.api import ApiClient, ApiError, MaintenanceError, read_api_key
from agentic.llm import LLMClient, LLMError
from agentic.pipeline import Pipeline, TaskCancelled
from agentic.replay import ReplayRun
from agentic.replay_report import render as render_replay
from agentic.settings import load_settings, local_models

logger = logging.getLogger("agentic")

# Zoveel teams vraagt de agent per keer op: genoeg om teams over te slaan die een andere agent net claimt
PENDING_BATCH = 5
# Zo lang werkt de agent per ronde aan een AI-replay; daarna kijkt hij eerst weer naar de teams
REPLAY_SLICE_SECONDS = 60


class AgentService:
    def __init__(self, settings, api, llm, pipeline=None, stop_event=None):
        self.settings = settings
        self.api = api
        self.llm = llm
        self.pipeline = pipeline or Pipeline(llm, settings, keepalive=lambda task: api.claim_task(task["team_id"]))
        self.stop_event = stop_event or threading.Event()
        self.replay = None  # de AI-replay waar deze agent mee bezig is (ReplayRun)

    def model_ok(self):
        return self.llm.last_ok if self.llm.last_ok is not None else self.llm.ping()

    def heartbeat(self):
        try:
            self.api.heartbeat(self.model_ok())
        except MaintenanceError:
            pass
        except Exception as e:  # noqa: BLE001 - een gemiste heartbeat mag de agent niet stoppen
            logger.warning("Heartbeat mislukt: %s", e)

    def process_tasks(self):
        """Verwerkt teams tot de wachtrij leeg is. Vlak voor elk team haalt de agent een verse lijst op (alleen
        de oudste paar), zodat hij nooit een bericht beoordeelt dat intussen al beantwoord is of door een
        nieuwer bericht is vervangen. Teams die een agent verwerkt (ook deze) staan niet in die lijst."""
        done = 0
        seen = set()
        while not self.stop_event.is_set():
            try:
                tasks = self.api.get_pending(limit=PENDING_BATCH)
            except MaintenanceError:
                raise
            except Exception as e:  # noqa: BLE001
                logger.error("Ophalen van teamberichten mislukt: %s", e)
                break
            task = self._claim_first(task for task in tasks if (task["team_id"], task["message_id"]) not in seen)
            if task is None:
                break
            seen.add((task["team_id"], task["message_id"]))
            done += self._process_task(task)
            self.heartbeat()
        return done

    def _claim_first(self, tasks):
        """De eerste taak die deze agent kan claimen, of None."""
        for task in tasks:
            if self.api.claim_task(task["team_id"]):
                return task
            logger.info("Overslaan: %s wordt al door een andere agent verwerkt", self._label(task))
        return None

    @staticmethod
    def _label(task):
        # Teamnaam alleen in het log van de agent, nooit in een prompt
        return f"team {task.get('team_id')} ({task.get('team_name', '?')}), level {task.get('current_level')}"

    def _process_task(self, task):
        """Beoordeelt één geclaimd team en dient het resultaat in; 1 als dat lukte, anders 0."""
        label = self._label(task)
        try:
            payload = self.pipeline.process(task, self.api.agent_id)
        except TaskCancelled:
            logger.info("%s: geannuleerd door een operator (of claim kwijt); niets ingediend", label)
            return 0
        except Exception:  # noqa: BLE001 - een fout in de agent wordt een escalatie, geen crash
            logger.exception("Interne fout bij %s", label)
            payload = {"agent_id": self.api.agent_id, "team_id": task["team_id"], "level": task["current_level"],
                       "message_id": task["message_id"], "intent": "antwoord", "decision": "escalate",
                       "reason": "Interne fout in de AI-agent; een docent moet dit bericht bekijken"}
        try:
            result = self.api.submit_evaluation(payload)
            logger.info("%s: %s gevraagd, %s uitgevoerd", label, payload["decision"], (result or {}).get("decision"))
            return 1
        except ApiError as e:
            logger.warning("Indienen voor %s mislukt: %s", label, e)
            return 0

    def process_design_jobs(self):
        try:
            jobs = self.api.get_design_jobs()
        except MaintenanceError:
            raise
        except Exception as e:  # noqa: BLE001
            logger.error("Ophalen van opdrachten mislukt: %s", e)
            return 0
        done = 0
        for job in jobs:
            if self.stop_event.is_set():
                break
            if not self.api.claim_design(job["assignment_id"]):
                continue
            label = f"opdracht {job.get('assignment_id')} (ronde {job.get('round')})"
            try:
                result, _meta = designer.design(self.llm, self.settings, job)
                body = {"assignment_id": job["assignment_id"]}
                body.update({"questions": result["vragen"]} if result["soort"] == "vragen" else {"rubric": result["rubric"]})
                try:
                    self.api.submit_design(body)
                except ApiError as e:
                    # De server keurde het voorstel af (bijv. een ongeldige rubric): dan mislukt
                    logger.warning("Voorstel voor %s geweigerd: %s", label, e)
                    self.api.submit_design({"assignment_id": job["assignment_id"], "error": str(e)})
                logger.info("%s: %s", label, result["soort"])
                done += 1
            except LLMError as e:
                logger.warning("Opdracht-assistent faalde bij %s: %s", label, e)
                try:
                    self.api.submit_design({"assignment_id": job["assignment_id"], "error": str(e)})
                except ApiError as e2:
                    logger.warning("Fout melden voor %s mislukt: %s", label, e2)
            self.heartbeat()
        return done

    def process_replay(self, clock=time.monotonic):
        """Werkt hooguit REPLAY_SLICE_SECONDS aan een AI-replay (claimt er een als er geen loopt). Na elke stap
        voortgang; is de replay klaar, dan het rapport. Geeft True als er nog een replay loopt."""
        if self.replay is None:
            try:
                job = self.api.claim_replay()
            except MaintenanceError:
                raise
            except Exception as e:  # noqa: BLE001
                logger.error("Ophalen van een AI-replay mislukt: %s", e)
                return False
            if not job:
                return False
            self.replay = ReplayRun(job, self.llm, self.settings)
            logger.info("AI-replay %s gestart: %d stappen", self.replay.job_id, len(self.replay.steps))
        run = self.replay
        start = clock()
        try:
            while not run.done and not self.stop_event.is_set():
                label = run.step()
                if not self.api.replay_progress(run.job_id, run.percent, label):
                    logger.warning("AI-replay %s is niet meer van deze agent; gestopt", run.job_id)
                    self.replay = None
                    return False
                if clock() - start >= REPLAY_SLICE_SECONDS:
                    break
            if run.done:
                self.api.submit_replay(run.job_id, html=render_replay(run, self.settings))
                logger.info("AI-replay %s klaar", run.job_id)
                self.replay = None
        except MaintenanceError:
            raise
        except Exception as e:  # noqa: BLE001 - een kapotte replay mag de agent niet stoppen
            logger.exception("AI-replay %s mislukt", run.job_id)
            self.abort_replay(f"Interne fout in de AI-agent: {e}")
        return self.replay is not None

    def abort_replay(self, reason):
        if self.replay is None:
            return
        try:
            self.api.submit_replay(self.replay.job_id, error=reason)
        except Exception as e:  # noqa: BLE001
            logger.warning("Melden dat AI-replay %s mislukte, lukte niet: %s", self.replay.job_id, e)
        self.replay = None

    def run_once(self):
        try:
            self.heartbeat()
            self.process_tasks()  # een lopend spel gaat voor
            if not self.stop_event.is_set():
                self.process_design_jobs()
            if not self.stop_event.is_set():
                self.process_replay()
        except MaintenanceError:
            # De worker zet een backup terug (onderhoudsmodus): niets beoordelen, volgende ronde opnieuw
            logger.info("Webapp in onderhoudsmodus; wachten")

    def run(self):
        logger.info("AI-agent %s gestart (interval %ss, Ollama %s)", self.api.agent_id, self.settings.POLL_INTERVAL, self.llm.url)
        try:
            while not self.stop_event.is_set():
                try:
                    self.run_once()
                except ApiError as e:
                    logger.error("API-fout: %s", e)
                except Exception:  # noqa: BLE001 - de agent blijft draaien
                    logger.exception("Onverwachte fout in deze ronde")
                # Loopt er een replay, dan meteen verder (na een blik op de teams)
                self.stop_event.wait(1 if self.replay is not None else self.settings.POLL_INTERVAL)
        finally:
            self.abort_replay("De AI-agent is gestopt tijdens deze replay; start hem opnieuw")
            self.api.unregister_agent()
            logger.info("AI-agent %s afgemeld", self.api.agent_id)


def key_loader(settings, config_module):
    """Waar de agent bij een 401 de key opnieuw leest (bijvoorbeeld na een restore): de database van de
    webapp als API_KEY_DB is ingesteld (Docker), anders config.py."""
    if settings.API_KEY_DB:
        return lambda: read_api_key(settings.API_KEY_DB, settings.API_KEY_NAME)
    return lambda: load_settings(importlib.reload(config_module)).API_KEY


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
    import config  # noqa: PLC0415 - config.py hoort naast main.py (zie config.py.sample)
    settings = load_settings(config)
    local = local_models(settings)
    if local:
        logger.error("Alleen cloudmodellen zijn toegestaan (CLOUD_ONLY), maar ingesteld is: %s. "
                     "Kies een -cloud-model of zet CLOUD_ONLY = False.", ", ".join(local))
        raise SystemExit(2)
    agent_id = f"agent-{uuid.uuid4().hex[:8]}"
    api = ApiClient(settings.BASE_URL, settings.API_KEY, agent_id, key_loader=key_loader(settings, config))
    llm = LLMClient(settings.OLLAMA_URL, timeout=settings.LLM_TIMEOUT, cloud_only=settings.CLOUD_ONLY)
    service = AgentService(settings, api, llm)

    def stop(signum, _frame):
        logger.info("Signaal %s ontvangen, de agent stopt na de huidige taak", signum)
        service.stop_event.set()

    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)
    service.run()


if __name__ == "__main__":
    main()
