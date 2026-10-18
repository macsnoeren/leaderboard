"""AI-agent voor Zebrawave: beoordeelt teamberichten en helpt docenten met rubrics (README.md).

Starten: python3 main.py (met config.py hiernaast; zie config.py.sample). Stoppen met Ctrl+C of SIGTERM:
de agent meldt zich dan netjes af.

Per ronde: heartbeat (met model_ok), eerst de teamberichten (een lopend spel gaat voor), daarna de
opdrachten waar de opdracht-assistent naar moet kijken.
"""
import logging
import signal
import threading
import uuid

from agentic.agents import designer
from agentic.api import ApiClient, ApiError, MaintenanceError
from agentic.llm import LLMClient, LLMError
from agentic.pipeline import Pipeline
from agentic.settings import load_settings, local_models

logger = logging.getLogger("agentic")


class AgentService:
    def __init__(self, settings, api, llm, pipeline=None, stop_event=None):
        self.settings = settings
        self.api = api
        self.llm = llm
        self.pipeline = pipeline or Pipeline(llm, settings, keepalive=lambda task: api.claim_task(task["team_id"]))
        self.stop_event = stop_event or threading.Event()

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
        try:
            tasks = self.api.get_pending()
        except MaintenanceError:
            raise
        except Exception as e:  # noqa: BLE001
            logger.error("Ophalen van teamberichten mislukt: %s", e)
            return 0
        done = 0
        for task in tasks:
            if self.stop_event.is_set():
                break
            # Teamnaam alleen in het log van de agent, nooit in een prompt
            label = f"team {task.get('team_id')} ({task.get('team_name', '?')}), level {task.get('current_level')}"
            if not self.api.claim_task(task["team_id"]):
                logger.info("Overslaan: %s wordt al door een andere agent verwerkt", label)
                continue
            try:
                payload = self.pipeline.process(task, self.api.agent_id)
            except Exception:  # noqa: BLE001 - een fout in de agent wordt een escalatie, geen crash
                logger.exception("Interne fout bij %s", label)
                payload = {"agent_id": self.api.agent_id, "team_id": task["team_id"], "level": task["current_level"],
                           "message_id": task["message_id"], "intent": "antwoord", "decision": "escalate",
                           "reason": "Interne fout in de AI-agent; een docent moet dit bericht bekijken"}
            try:
                result = self.api.submit_evaluation(payload)
                logger.info("%s: %s gevraagd, %s uitgevoerd", label, payload["decision"], (result or {}).get("decision"))
                done += 1
            except ApiError as e:
                logger.warning("Indienen voor %s mislukt: %s", label, e)
            self.heartbeat()
        return done

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

    def run_once(self):
        try:
            self.heartbeat()
            self.process_tasks()  # een lopend spel gaat voor
            if not self.stop_event.is_set():
                self.process_design_jobs()
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
                self.stop_event.wait(self.settings.POLL_INTERVAL)
        finally:
            self.api.unregister_agent()
            logger.info("AI-agent %s afgemeld", self.api.agent_id)


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
    api = ApiClient(settings.BASE_URL, settings.API_KEY, agent_id)
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
