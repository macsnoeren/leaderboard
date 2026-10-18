"""Taak report: rapport (PDF) van een spel (DESIGN §9). Leest uit een momentopname van de database."""
import os

from worker.jobs import UserError, register
from worker.report import data
from worker.report.pdf import SECTIONS, build_pdf
from worker.snapshot import open_snapshot
from worker.tasks.export_game import download_name, htdocs_file


def make_report(conn, cfg, game_id, sections, out_path, reporter, compress=True):
    overview = data.game_overview(conn, game_id)
    if overview is None:
        raise UserError(f"Spel {game_id} bestaat niet")
    reporter.progress(20, "Goedgekeurde antwoorden verzamelen")
    answers = data.accepted_answers(conn, game_id) if "answers" in sections else None
    reporter.progress(50, "Verloop per team verzamelen")
    traces = data.team_traces(conn, game_id) if "teams" in sections else None
    reporter.progress(75, "PDF maken")
    logo = htdocs_file(cfg.HTDOCS_DIR, overview["game"].get("logo_file"))
    build_pdf(out_path, overview, answers, traces, sections=sections, logo_path=logo, font=cfg.REPORT_FONT,
              font_bold=cfg.REPORT_FONT_BOLD, compress=compress)
    derived = sum(1 for item in answers or [] for r in item["rows"] if r["derived"])
    return {"game_name": overview["game"]["name"], "approvals": sum(len(i["rows"]) for i in answers or []),
            "teams": len(traces or []), "derived": derived}


@register("report")
def handle(job):
    game_id = job.params.get("game_id", job.game_id)
    if game_id is None:
        raise UserError("Geen spel opgegeven")
    sections = [s for s in (job.params.get("sections") or SECTIONS) if s in SECTIONS]
    if not sections:
        raise UserError("Kies minstens één onderdeel voor het rapport")
    with open_snapshot(job.cfg.DB_FILE, job.storage.tmp) as snap:
        row = snap.execute("SELECT name FROM games WHERE id = ?", (game_id,)).fetchone()
        if row is None:
            raise UserError(f"Spel {game_id} bestaat niet")
        name = download_name(row["name"], kind="rapport", ext="pdf")
        out = os.path.join(job.storage.job_dir(job.id), name)
        summary = make_report(snap, job.cfg, int(game_id), sections, out, job)
    job.set_result(out, name)
    parts = []
    if "answers" in sections:
        parts.append(f"{summary['approvals']} goedgekeurde antwoorden" + (f" ({summary['derived']} afgeleid)" if summary["derived"] else ""))
    if "teams" in sections:
        parts.append(f"{summary['teams']} teams")
    job.summary = ", ".join(parts)
