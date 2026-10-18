"""Takenregister en de context van een lopende taak (DESIGN §5).

Een handler is een functie handler(job) die met @register('type') wordt aangemeld. Fouten voor de
gebruiker gooi je als UserError (die tekst komt in de website); elke andere fout wordt "Interne fout,
zie de worker-log" met de traceback alleen in de log.
"""
import logging
import os
import shutil
import time

from worker import db

logger = logging.getLogger(__name__)

HANDLERS = {}


class UserError(Exception):
    """Fout met een melding die de gebruiker mag zien (geen interne details)."""


class Stopped(UserError):
    def __init__(self):
        super().__init__("Afgebroken: de worker stopt")


def register(job_type):
    def decorator(fn):
        HANDLERS[job_type] = fn
        return fn
    return decorator


class Reporter:
    """Voortgang en waarschuwingen; de basis van Job en bruikbaar in tests en de CLI."""

    def __init__(self):
        self.warnings = []
        self.last_message = None
        self.summary = None

    def progress(self, pct, message=None):
        if message:
            self.last_message = message

    def warn(self, text):
        logger.warning("%s", text)
        self.warnings.append(text)

    def check_stop(self):
        pass


class Job(Reporter):
    """Een lopende taak. conn en id kunnen tijdens een restore veranderen (de database wordt vervangen).
    worker_id None (CLI): geen heartbeat in worker_status, zodat de website geen actieve worker ziet."""

    PROGRESS_INTERVAL = 1.0

    def __init__(self, conn, row, cfg, storage, worker_id="worker", stop_event=None, clock=time.monotonic):
        super().__init__()
        self.conn = conn
        self.row = row
        self.id = row["id"]
        self.type = row["type"]
        self.game_id = row["game_id"]
        self.created_by = row["created_by"]
        self.params = db.job_params(row)
        self.cfg = cfg
        self.storage = storage
        self.worker_id = worker_id
        self.stop_event = stop_event
        self.clock = clock
        self._last_write = None
        self.result_path = None
        self.result_name = None
        self.result_game_id = None
        self.result_expires = True

    def progress(self, pct, message=None):
        super().progress(pct, message)
        now = self.clock()
        if self._last_write is not None and now - self._last_write < self.PROGRESS_INTERVAL:
            return
        self._last_write = now
        try:
            db.set_progress(self.conn, self.id, pct, message)
            if self.worker_id:
                db.worker_heartbeat(self.conn, self.worker_id, _version(), self.id)
        except Exception as e:  # noqa: BLE001 - voortgang mag een taak niet laten mislukken
            logger.warning("Voortgang van taak %s niet opgeslagen: %s", self.id, e)

    def heartbeat(self):
        try:
            db.heartbeat_job(self.conn, self.id)
            if self.worker_id:
                db.worker_heartbeat(self.conn, self.worker_id, _version(), self.id)
        except Exception as e:  # noqa: BLE001
            logger.warning("Heartbeat van taak %s mislukt: %s", self.id, e)

    def check_stop(self):
        if self.stop_event is not None and self.stop_event.is_set():
            raise Stopped()

    def set_result(self, path, name, expires=True):
        self.result_path = path
        self.result_name = name
        self.result_expires = expires

    def tmp_dir(self):
        return self.storage.make_tmp(f"job-{self.id}")

    def final_message(self):
        parts = [self.summary] if self.summary else []
        if self.warnings:
            label = "waarschuwing" if len(self.warnings) == 1 else "waarschuwingen"
            parts.append(f"{len(self.warnings)} {label}: " + " · ".join(self.warnings))
        return " ".join(parts) or None


def _version():
    from worker import VERSION  # noqa: PLC0415
    return VERSION


def run_job(conn, cfg, storage, row, worker_id="worker", stop_event=None):
    """Voert een geclaimde taak uit en zet de eindstatus. Geeft de Job terug."""
    job = Job(conn, row, cfg, storage, worker_id=worker_id, stop_event=stop_event)
    handler = HANDLERS.get(job.type)
    label = f"taak {job.id} ({job.type})"
    logger.info("Start %s", label)
    try:
        if handler is None:
            raise UserError(f"Onbekend soort taak: {job.type}")
        handler(job)
    except UserError as e:
        logger.info("%s mislukt: %s", label, e)
        _end(job, failed=str(e))
    except Exception:  # noqa: BLE001 - een taak mag de worker niet laten stoppen
        logger.exception("Interne fout in %s", label)
        _end(job, failed="Interne fout, zie de worker-log")
    else:
        _end(job)
        logger.info("Klaar: %s", label)
    finally:
        shutil.rmtree(os.path.join(storage.tmp, f"job-{job.id}"), ignore_errors=True)
    return job


def _end(job, failed=None):
    conn = job.conn
    try:
        if failed is not None:
            message = failed + (f" ({job.final_message()})" if job.warnings else "")
            db.fail_job(conn, job.id, message)
            db.audit(conn, "JOB_FAILED", f"Taak #{job.id} ({job.type}) mislukt")
            return
        size = None
        rel = None
        if job.result_path:
            rel = job.storage.rel(job.result_path)
            size = os.path.getsize(job.result_path)
        db.finish_job(conn, job.id, message=job.final_message(), result_file=rel, result_name=job.result_name,
                      result_size=size, result_game_id=job.result_game_id,
                      expires_days=job.cfg.RESULT_TTL_DAYS if (job.result_path and job.result_expires) else None)
        db.audit(conn, "JOB_DONE", f"Taak #{job.id} ({job.type}) klaar")
    except Exception:  # noqa: BLE001
        logger.exception("Eindstatus van taak %s niet opgeslagen", job.id)
