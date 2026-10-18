"""Commandoregel voor beheer en noodgevallen (DESIGN §10.3): python -m worker <commando>.

Elk commando (behalve inspect) maakt een worker_jobs-rij met created_by NULL voor de historie en voert de
handler direct uit, zonder wachtrij. Exitcode 0 = gelukt, 1 = mislukt, 2 = verkeerd gebruik.
"""
import argparse
import logging
import os
import secrets
import shutil
import sys

import worker.tasks  # noqa: F401 - registreert de handlers
from worker import config, db
from worker.archive import ArchiveError, ArchiveReader
from worker.formats import GAME_ALLOWED, detect_format, load_manifest, read_archive
from worker.jobs import UserError, run_job
from worker.storage import Storage


def run_direct(cfg, storage, job_type, params, game_id=None):
    """Voert een taak direct uit. Geeft (status, message, absoluut pad van het resultaat of None, rij)."""
    if not os.path.isfile(cfg.DB_FILE):
        raise UserError(f"Database {cfg.DB_FILE} bestaat niet")
    storage.ensure()
    conn = db.connect(cfg.DB_FILE)
    try:
        job_id = db.create_job(conn, job_type, params, game_id=game_id, status="running", worker_id="cli")
        job = run_job(conn, cfg, storage, db.get_job(conn, job_id), worker_id=None)
        row = db.get_job(job.conn, job.id)
        result = os.path.join(storage.root, row["result_file"]) if row and row["result_file"] else None
        return row["status"], row["message"], result, row
    finally:
        conn.close()


def _copy_to_incoming(storage, path):
    if not os.path.isfile(path):
        raise UserError(f"Bestand {path} niet gevonden")
    storage.ensure()
    rel = f"incoming/{secrets.token_hex(16)}.tar.gz"
    shutil.copy(path, os.path.join(storage.root, rel))
    return rel


def _finish(status, message, result, output=None):
    if status != "done":
        print(f"Mislukt: {message}", file=sys.stderr)
        return 1
    if message:
        print(message)
    if result:
        if output:
            shutil.copy(result, output)
            print(f"Opgeslagen: {output}")
        else:
            print(f"Resultaat: {result}")
    return 0


def inspect(path, cfg):
    """Formaat, versie en inhoud van een archief; verandert niets."""
    allowed = GAME_ALLOWED + [r"database/leaderboard\.db", r"htdocs/(artifacts|uploads)/[^/]+"]
    with ArchiveReader(path, allowed, cfg.MAX_ARCHIVE_MB * 1024 * 1024, cfg.MAX_ARCHIVE_FILES) as reader:
        manifest = load_manifest(reader)
        fmt = detect_format(manifest)
        lines = [f"Bestand: {path}"]
        if fmt == "backup":
            lines.append(f"Formaat: backup (versie {manifest.get('format_version')})")
            lines.append(f"Gemaakt: {manifest.get('created_at')} ({manifest.get('trigger')}), app-versie {manifest.get('app_version')}")
            counts = manifest.get("counts") or {}
            lines.append("Inhoud: " + ", ".join(f"{v} {k}" for k, v in counts.items()))
        elif fmt in ("v1", "v2", "v3"):
            model = read_archive(reader)
            files = sum(1 for a in model.assignments if a.artifact) + (1 if model.game.logo else 0)
            lines.append(f"Formaat: {fmt}")
            lines.append(f"Spel: {model.game.name or '(geen naam; v1/v2 hebben geen spelinformatie)'}")
            lines.append(f"Opdrachten: {len(model.assignments)}, bestanden: {files}")
            lines.append("Voortgang: " + (f"ja, {len(model.progress.get('teams') or [])} teams" if model.progress else "nee"))
            lines.extend(f"Waarschuwing: {w}" for w in model.warnings)
        else:
            lines.append({"newer": "Formaat: spel-export van een nieuwere versie",
                          "unknown": "Formaat: onbekend"}.get(fmt, f"Formaat: {fmt}"))
        if reader.ignored:
            lines.append(f"Genegeerd: {len(reader.ignored)} onbekende bestanden")
    print("\n".join(lines))
    return fmt


def build_parser():
    p = argparse.ArgumentParser(prog="python -m worker", description="Taken van de Zebrawave-worker direct uitvoeren.")
    sub = p.add_subparsers(dest="command", required=True)
    e = sub.add_parser("export-game", help="Spel exporteren naar een archief (v3)")
    e.add_argument("--game", type=int, required=True)
    e.add_argument("--with-progress", action="store_true", help="ook teams en voortgang (zonder e-mailadressen)")
    e.add_argument("-o", "--output")
    i = sub.add_parser("import-game", help="Archief (v1, v2 of v3) importeren als nieuw spel")
    i.add_argument("archive")
    i.add_argument("--name", help="naam van het nieuwe spel")
    sub.add_parser("inspect", help="Formaat en inhoud van een archief tonen (verandert niets)").add_argument("archive")
    b = sub.add_parser("backup", help="Volledige backup maken")
    b.add_argument("-o", "--output")
    r = sub.add_parser("restore", help="Backup terugzetten (vervangt alles)")
    r.add_argument("archive")
    r.add_argument("--yes", action="store_true", help="bevestigen: alles wordt vervangen")
    rp = sub.add_parser("report", help="Rapport (PDF) van een spel")
    rp.add_argument("--game", type=int, required=True)
    rp.add_argument("--sections", default="answers,teams", help="answers, teams of beide (standaard)")
    rp.add_argument("-o", "--output")
    return p


def main(argv=None, cfg=None):
    logging.basicConfig(level=logging.WARNING, format="%(levelname)s: %(message)s")
    args = build_parser().parse_args(argv)
    cfg = cfg or config.load()
    storage = Storage(cfg.STORAGE_DIR)
    try:
        if args.command == "inspect":
            inspect(args.archive, cfg)
            return 0
        if args.command == "export-game":
            res = run_direct(cfg, storage, "game_export", {"game_id": args.game, "include_progress": args.with_progress}, args.game)
            return _finish(*res[:3], args.output)
        if args.command == "import-game":
            rel = _copy_to_incoming(storage, args.archive)
            status, message, _, row = run_direct(cfg, storage, "game_import", {
                "upload": rel, "name": args.name, "original_name": os.path.basename(args.archive)})
            if status == "done":
                print(f"Nieuw spel: #{row['result_game_id']}")
            return _finish(status, message, None)
        if args.command == "backup":
            res = run_direct(cfg, storage, "backup", {"trigger": "manual"})
            return _finish(*res[:3], args.output)
        if args.command == "restore":
            if not args.yes:
                print("Een restore vervangt de database, alle bijlagen en logo's. Er wordt eerst een veiligheidsbackup gemaakt.\n"
                      "Herhaal met --yes om door te gaan.", file=sys.stderr)
                return 2
            rel = _copy_to_incoming(storage, args.archive)
            res = run_direct(cfg, storage, "restore", {"upload": rel, "username": "cli"})
            return _finish(*res[:3])
        if args.command == "report":
            sections = [s.strip() for s in args.sections.split(",") if s.strip()]
            res = run_direct(cfg, storage, "report", {"game_id": args.game, "sections": sections}, args.game)
            return _finish(*res[:3], args.output)
    except (UserError, ArchiveError) as e:
        print(f"Mislukt: {e}", file=sys.stderr)
        return 1
    return 2


if __name__ == "__main__":
    sys.exit(main())
