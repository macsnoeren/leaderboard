"""Worker voor Zebrawave: voert taken uit de wachtrij (worker_jobs) uit, zie README.md.

Starten: python3 main.py (config.py hiernaast is optioneel; zie config.py.sample). Stoppen met Ctrl+C of
SIGTERM: de lopende taak wordt afgemaakt (een backup wordt afgebroken) en de worker meldt zich af.

Per ronde: heartbeat, planner (nachtelijke backup), één taak claimen en uitvoeren; elk uur opruimen.
Elke ronde opent een eigen verbinding, zodat een restore (nieuwe database) geen oude verbinding achterlaat.
"""
import logging
import os
import signal
import sqlite3
import threading
import time
import uuid

import worker.tasks  # noqa: F401 - registreert de handlers
from worker import VERSION, config, db, retention, scheduler
from worker.jobs import run_job
from worker.storage import Storage, cleanup_results

logger = logging.getLogger("worker")

HOUSEKEEPING_SECONDS = 3600


class Worker:
    def __init__(self, cfg, worker_id=None, stop_event=None, clock=time.monotonic):
        self.cfg = cfg
        self.storage = Storage(cfg.STORAGE_DIR)
        self.worker_id = worker_id or f"worker-{uuid.uuid4().hex[:8]}"
        self.stop_event = stop_event or threading.Event()
        self.clock = clock
        self._last_housekeeping = None

    def connect(self):
        if not os.path.isfile(self.cfg.DB_FILE):
            raise FileNotFoundError(f"Database {self.cfg.DB_FILE} bestaat (nog) niet")
        return db.connect(self.cfg.DB_FILE)

    def startup(self):
        self.storage.ensure()
        self.storage.clear_tmp()
        conn = self.connect()
        try:
            orphans = db.fail_orphans(conn)
            if orphans:
                logger.warning("%d onderbroken taak/taken op 'mislukt' gezet", orphans)
        finally:
            conn.close()

    def run_once(self):
        """Eén ronde. Geeft True als er een taak is uitgevoerd."""
        if self.stop_event.is_set():
            return False
        conn = self.connect()
        job = None
        try:
            db.worker_heartbeat(conn, self.worker_id, VERSION)
            scheduler.maybe_enqueue(conn, self.cfg, self.storage)
            row = db.claim_next(conn, self.worker_id)
            if row is None:
                return False
            job = run_job(conn, self.cfg, self.storage, row, worker_id=self.worker_id, stop_event=self.stop_event)
            # Een restore wisselt van database; meld de heartbeat in de nieuwe
            db.worker_heartbeat(job.conn, self.worker_id, VERSION)
            return True
        finally:
            if job is not None and job.conn is not conn:
                job.conn.close()
            conn.close()

    def housekeeping(self, force=False):
        now = self.clock()
        if not force and self._last_housekeeping is not None and now - self._last_housekeeping < HOUSEKEEPING_SECONDS:
            return
        self._last_housekeeping = now
        conn = self.connect()
        try:
            cleanup_results(conn, self.storage)
            retention.apply(self.cfg, self.storage, conn)
        finally:
            conn.close()

    def unregister(self):
        try:
            conn = self.connect()
            try:
                db.unregister(conn, self.worker_id)
            finally:
                conn.close()
        except Exception as e:  # noqa: BLE001
            logger.warning("Afmelden mislukt: %s", e)

    def run(self):
        logger.info("Worker %s gestart (versie %s, interval %ss, database %s)", self.worker_id, VERSION,
                    self.cfg.POLL_INTERVAL, self.cfg.DB_FILE)
        started = False
        try:
            while not self.stop_event.is_set():
                did = False
                try:
                    if not started:
                        self.startup()
                        started = True
                    did = self.run_once()
                    self.housekeeping()
                except (FileNotFoundError, sqlite3.OperationalError) as e:
                    # Webapp nog niet geïnstalleerd/gemigreerd, of database even bezet
                    logger.warning("Wachten: %s", e)
                except Exception:  # noqa: BLE001 - de worker blijft draaien
                    logger.exception("Onverwachte fout in deze ronde")
                if not did:
                    self.stop_event.wait(self.cfg.POLL_INTERVAL)
        finally:
            self.unregister()
            logger.info("Worker %s gestopt", self.worker_id)


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(levelname)s - %(name)s - %(message)s",
                        datefmt="%Y-%m-%d %H:%M:%S")
    cfg = config.load()
    service = Worker(cfg)

    def stop(signum, _frame):
        logger.info("Signaal %s: stoppen na de lopende taak", signum)
        service.stop_event.set()

    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)
    service.run()


if __name__ == "__main__":
    main()
