"""Momentopname van de database met de online backup-API van SQLite (DESIGN §7.1, §9.3).

Consistent, ook als de website op dat moment schrijft, en zonder een lange leesactie op de echte
database: export, rapport en backup lezen uit de kopie.
"""
import contextlib
import os
import sqlite3
import time
import uuid


def snapshot(db_file, target, timeout=30):
    """Kopie van db_file naar target. Wacht op een schrijfactie van de website, maar maximaal timeout seconden."""
    if not os.path.isfile(db_file):
        raise FileNotFoundError(db_file)
    deadline = time.monotonic() + timeout

    def progress(status, remaining, total):
        # backup() probeert bij een lock eindeloos opnieuw; zo blijft het begrensd
        if status in (sqlite3.SQLITE_BUSY, sqlite3.SQLITE_LOCKED) and time.monotonic() > deadline:
            raise TimeoutError("De database bleef te lang bezet; momentopname afgebroken")

    src = sqlite3.connect(db_file, timeout=timeout)
    try:
        dst = sqlite3.connect(target)
        try:
            src.backup(dst, progress=progress, sleep=0.05)
        finally:
            dst.close()
    finally:
        src.close()
    return target


@contextlib.contextmanager
def snapshot_tmp(db_file, tmp_dir):
    """Maakt een momentopname in tmp_dir en verwijdert die na afloop."""
    os.makedirs(tmp_dir, exist_ok=True)
    target = os.path.join(tmp_dir, f"snapshot-{uuid.uuid4().hex[:8]}.db")
    try:
        yield snapshot(db_file, target)
    finally:
        for suffix in ("", "-journal", "-wal", "-shm"):
            try:
                os.remove(target + suffix)
            except OSError:
                pass


@contextlib.contextmanager
def open_snapshot(db_file, tmp_dir):
    """Momentopname als open verbinding (Row-factory)."""
    with snapshot_tmp(db_file, tmp_dir) as path:
        conn = sqlite3.connect(path, isolation_level=None)
        conn.row_factory = sqlite3.Row
        try:
            yield conn
        finally:
            conn.close()
