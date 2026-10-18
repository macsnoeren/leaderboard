"""Opmaak van het rapport met fpdf2.

Voorblad, deel A (goedgekeurde antwoorden per opdracht, naast het modelantwoord) en deel B (verloop per
team). Antwoorden staan in doorlopende tekst, zodat een lang antwoord over een pagina kan doorlopen;
het verloop per team is een tabel. Tekst van teams en opdrachten is platte tekst: Markdown wordt
weggehaald, en tekens die het lettertype niet heeft worden '?'. Zonder Unicode-lettertype (REPORT_FONT)
valt de PDF terug op Helvetica (Latin-1).
"""
import logging
import os
import re
from datetime import datetime, timezone

from fpdf import FPDF

logger = logging.getLogger(__name__)
logging.getLogger("fpdf").setLevel(logging.ERROR)

FAMILY = "zw"
SECTIONS = ("answers", "teams")


def strip_markdown(text):
    """Markdown naar platte tekst: koppen, nadruk, code, links en afbeeldingen."""
    text = str(text or "")
    text = re.sub(r"!\[([^\]]*)\]\([^)]*\)", r"\1", text)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r"\1 (\2)", text)
    text = re.sub(r"^\s{0,3}#{1,6}\s*", "", text, flags=re.M)
    text = re.sub(r"^\s*>\s?", "", text, flags=re.M)
    text = re.sub(r"(\*\*|__)(.+?)\1", r"\2", text)
    text = re.sub(r"(?<![\w*])([*_])(?!\s)(.+?)(?<!\s)\1(?![\w*])", r"\2", text)
    text = re.sub(r"`{1,3}([^`]*)`{1,3}", r"\1", text)
    text = re.sub(r"^\s*[-*+]\s+", "• ", text, flags=re.M)
    return text.strip()


def local_time(dt, fmt="%d-%m %H:%M"):
    if dt is None:
        return "—"
    return dt.astimezone().strftime(fmt)


def duration(seconds):
    if seconds is None:
        return "—"
    seconds = max(0, int(seconds))
    hours, rest = divmod(seconds, 3600)
    minutes = rest // 60
    return f"{hours} u {minutes:02d} min" if hours else f"{minutes} min"


class ReportPDF(FPDF):
    def __init__(self, title, font=None, font_bold=None):
        super().__init__(format="A4")
        self.report_title = title
        self.set_auto_page_break(True, margin=18)
        self.set_margins(18, 18, 18)
        self.alias_nb_pages()
        self.cmap = None
        if font and os.path.isfile(font):
            self.add_font(FAMILY, "", font)
            self.add_font(FAMILY, "B", font_bold if font_bold and os.path.isfile(font_bold) else font)
            self.family = FAMILY
            self.cmap = set(self.fonts[FAMILY].cmap)
        else:
            logger.warning("Lettertype %s niet gevonden; het rapport gebruikt Helvetica (alleen Latin-1)", font)
            self.family = "helvetica"

    def clean(self, text):
        text = str(text if text is not None else "")
        if self.cmap is None:
            return text.replace("•", "-").replace("—", "-").replace("→", "->").encode("latin-1", "replace").decode("latin-1")
        return "".join(ch if ch in "\n\t" or ord(ch) in self.cmap else "?" for ch in text)

    def normalize_text(self, text):
        # Elke tekst (ook tabelcellen en de voettekst) gaat via clean(): nooit een tekenfout
        return super().normalize_text(self.clean(text))

    def font(self, size=10, bold=False):
        self.set_font(self.family, "B" if bold else "", size)

    def footer(self):
        self.set_y(-12)
        self.font(8)
        self.set_text_color(120)
        self.cell(0, 6, self.clean(f"{self.report_title} · pagina {self.page_no()} van {{nb}}"), align="C")
        self.set_text_color(0)

    def heading(self, text, size=15):
        self.font(size, bold=True)
        self.multi_cell(0, size * 0.5, self.clean(text), new_x="LMARGIN", new_y="NEXT")
        self.ln(2)

    def paragraph(self, text, size=10, bold=False, color=None, height=5):
        self.font(size, bold)
        if color is not None:
            self.set_text_color(*color)
        self.multi_cell(0, height, self.clean(text), new_x="LMARGIN", new_y="NEXT")
        self.set_text_color(0)

    def label_value(self, label, value):
        self.font(10, bold=True)
        self.multi_cell(0, 5, self.clean(label), new_x="LMARGIN", new_y="NEXT")
        self.paragraph(value or "—")
        self.ln(1)

    def rule(self):
        self.set_draw_color(200)
        self.line(self.l_margin, self.get_y(), self.w - self.r_margin, self.get_y())
        self.ln(3)


def _cover(pdf, overview, logo_path, sections):
    pdf.add_page()
    game = overview["game"]
    if logo_path:
        try:
            pdf.image(logo_path, x=pdf.l_margin, y=pdf.get_y(), h=28)
            pdf.ln(34)
        except Exception as e:  # noqa: BLE001 - een kapot logo mag het rapport niet tegenhouden
            logger.warning("Logo niet in het rapport: %s", e)
    pdf.heading("Rapport", size=22)
    pdf.heading(game["name"], size=16)
    pdf.ln(4)
    period = f"{local_time(overview['started_at'], '%d-%m-%Y %H:%M')} tot {local_time(overview['last_approval'], '%d-%m-%Y %H:%M')}"
    for label, value in (("Periode", period), ("Teams", str(overview["teams"])),
                         ("Gemaakt op", datetime.now(timezone.utc).astimezone().strftime("%d-%m-%Y %H:%M"))):
        pdf.font(11, bold=True)
        pdf.cell(40, 7, pdf.clean(label))
        pdf.font(11)
        pdf.cell(0, 7, pdf.clean(value), new_x="LMARGIN", new_y="NEXT")
    pdf.ln(6)
    contents = []
    if "answers" in sections:
        contents.append("A. Goedgekeurde antwoorden per opdracht, naast het modelantwoord")
    if "teams" in sections:
        contents.append("B. Verloop per team")
    pdf.paragraph("Inhoud:\n" + "\n".join(contents), size=11)
    pdf.ln(4)
    pdf.paragraph("Gemarkeerd met (afgeleid): voor dit level is niet vastgelegd wie goedkeurde of welk antwoord werd "
                  "goedgekeurd (archief van vóór de worker). Het antwoord is dan het laatste teambericht en 'door' komt uit "
                  "de audit-log.", size=9, color=(90, 90, 90))


def _answers(pdf, answers):
    pdf.add_page()
    pdf.heading("A. Goedgekeurde antwoorden per opdracht", size=17)
    for item in answers:
        a = item["assignment"]
        if pdf.get_y() > pdf.h - 70:
            pdf.add_page()
        pdf.heading(f"Opdracht {a['assignment_number']}: {a['title'] or ''}", size=13)
        pdf.label_value("Modelantwoord (criteria)", strip_markdown(a["criteria"]))
        rubric = item["rubric"]
        if rubric:
            lines = []
            if rubric.get("kern"):
                lines.append(f"Kern: {rubric['kern']}")
            for asp in rubric.get("aspecten") or []:
                if isinstance(asp, dict):
                    req = " (verplicht)" if asp.get("verplicht") else ""
                    lines.append(f"• {asp.get('naam', '')}{req}: voldoende = {asp.get('voldoende', '')}; goed = {asp.get('goed', '')}")
            if rubric.get("ook_goed"):
                lines.append("Ook goed: " + "; ".join(str(x) for x in rubric["ook_goed"]))
            if lines:
                pdf.label_value(f"Rubric ({a.get('ai_rubric_status') or 'none'})", "\n".join(lines))
        rows = item["rows"]
        if not rows:
            pdf.paragraph("Nog door geen enkel team goedgekeurd.", size=10, color=(110, 110, 110))
            pdf.ln(4)
            continue
        pdf.font(10, bold=True)
        pdf.cell(0, 6, pdf.clean(f"Goedgekeurd bij {len(rows)} team(s)"), new_x="LMARGIN", new_y="NEXT")
        pdf.rule()
        for r in rows:
            team = r["team_name"] + (" (verwijderd)" if r["deleted"] else "")
            meta = (f"{local_time(r['approved_at'])} · door {r['by']} · {r['attempts']} poging(en)"
                    + (" · (afgeleid)" if r["derived"] else ""))
            pdf.font(10, bold=True)
            pdf.multi_cell(0, 5, pdf.clean(team), new_x="LMARGIN", new_y="NEXT")
            pdf.paragraph(meta, size=8, color=(100, 100, 100), height=4)
            pdf.paragraph(r["answer"] if r["answer"] else "(geen teambericht gevonden)", size=10)
            pdf.ln(2)
        pdf.ln(4)


def _teams(pdf, traces):
    pdf.add_page()
    pdf.heading("B. Verloop per team", size=17)
    if not traces:
        pdf.paragraph("Geen teams.")
    for t in traces:
        if pdf.get_y() > pdf.h - 60:
            pdf.add_page()
        if t["deleted"]:
            title = f"{t['team_name']} (verwijderd)"
        else:
            level = "Finish" if t["finished"] else ("Start" if t["level"] <= 0 else f"level {t['level']}")
            title = f"{t['rank']}. {t['team_name']} · {level}"
        pdf.heading(title, size=12)
        if not t["levels"]:
            pdf.paragraph("Nog geen opdracht afgerond.", size=9, color=(110, 110, 110))
        else:
            pdf.font(8)
            widths = (44, 22, 22, 20, 15, 14, 37)
            with pdf.table(col_widths=widths, line_height=4.5, first_row_as_headings=True, text_align="LEFT") as table:
                header = table.row()
                for label in ("Opdracht", "Start", "Goedgekeurd", "Duur", "Pogingen", "Vragen", "Door"):
                    header.cell(label)
                for lv in t["levels"]:
                    row = table.row()
                    title = f"{lv['assignment_number']}. {lv['title'] or ''}"
                    row.cell(pdf.clean(title[:80]))
                    row.cell(local_time(lv["started_at"]))
                    row.cell(local_time(lv["approved_at"]))
                    row.cell(duration(lv["duration_s"]))
                    row.cell(str(lv["attempts"]))
                    row.cell("—" if lv["questions"] is None else str(lv["questions"]))
                    row.cell(pdf.clean((lv["by"] or "")[:40] + (" (afgeleid)" if lv["derived"] else "")))
        if t["level_downs"]:
            downs = "; ".join(f"{local_time(d['at'])} terug naar level {d['to_level'] or '?'}" for d in t["level_downs"])
            pdf.paragraph(f"Level terug: {downs}", size=9, color=(110, 110, 110))
        pdf.ln(4)


def build_pdf(path, overview, answers=None, traces=None, sections=SECTIONS, logo_path=None, font=None, font_bold=None,
              compress=True):
    pdf = ReportPDF(f"Rapport {overview['game']['name']}", font=font, font_bold=font_bold)
    pdf.set_compression(compress)
    pdf.set_title(pdf.clean(f"Rapport {overview['game']['name']}"))
    pdf.set_creator("Zebrawave worker")
    _cover(pdf, overview, logo_path, sections)
    if "answers" in sections:
        _answers(pdf, answers or [])
    if "teams" in sections:
        _teams(pdf, traces or [])
    pdf.output(path)
    return path
