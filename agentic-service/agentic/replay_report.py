"""HTML-rapport van een AI-replay (replay.py): één bestand zonder externe bronnen, om te openen in de browser,
door te sturen of als PDF af te drukken. Alle tekst uit het spel wordt ge-escaped."""
from datetime import datetime
from html import escape

from .replay import CATEGORIES, turns

SENDERS = {"team": "Team", "teacher": "Operator", "ai": "AI"}
DECISIONS = {"level_up": "level omhoog", "reply": "reactie aan het team", "suggest": "suggestie voor de operator",
             "escalate": "naar de operator", "ignored": "genegeerd", "stale": "verouderd"}
MARKS = {"eens": "✓", "strenger": "▼", "soepeler": "▲", "operator": "✋", "vraag": "?", "geen": "·"}

CSS = """
:root { --bg: #F6F7FB; --surface: #FFFFFF; --text: #1B2030; --muted: #5D6478; --line: #DDE1EA;
  --ok: #1E7F4F; --ok-soft: #E3F4EA; --warn: #9A5B00; --warn-soft: #FFF1D6; --bad: #B4232C; --bad-soft: #FCE5E6;
  --info: #3B4FC4; --info-soft: #E6E9FB; --team: #EEF0FF; }
@media (prefers-color-scheme: dark) {
  :root { --bg: #0E1220; --surface: #161B2E; --text: #E7EAF3; --muted: #9AA2B8; --line: #2A3150;
    --ok: #5DD39E; --ok-soft: #12301F; --warn: #F5B754; --warn-soft: #33270F; --bad: #FF7A80; --bad-soft: #3A1518;
    --info: #9AA8FF; --info-soft: #1C2244; --team: #1D2340; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 1040px; margin: 0 auto; padding: 32px 16px 64px; }
h1 { font-size: 26px; margin: 0 0 4px; } h2 { font-size: 21px; margin: 40px 0 12px; } h3 { font-size: 17px; margin: 28px 0 10px; }
.muted { color: var(--muted); } .small { font-size: 13px; }
.card { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; margin: 12px 0; }
table { border-collapse: collapse; width: 100%; } th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { font-size: 13px; color: var(--muted); font-weight: 600; } td.num { text-align: right; font-variant-numeric: tabular-nums; }
.badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid var(--line); white-space: nowrap; }
.c-eens { background: var(--ok-soft); color: var(--ok); } .c-strenger, .c-soepeler { background: var(--bad-soft); color: var(--bad); }
.c-operator { background: var(--warn-soft); color: var(--warn); } .c-vraag, .c-geen { color: var(--muted); }
.v-onvoldoende { color: var(--bad); } .v-voldoende { color: var(--warn); } .v-goed { color: var(--ok); }
.msg { border: 1px solid var(--line); border-radius: 12px; padding: 8px 12px; margin: 8px 0; max-width: 78%; background: var(--surface); }
.msg-team { background: var(--team); } .msg-teacher, .msg-ai { margin-left: auto; }
.meta { font-size: 12px; color: var(--muted); margin-bottom: 2px; }
.text { white-space: pre-wrap; overflow-wrap: anywhere; }
.turn { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 6px 0 16px; }
.turn > div { border: 1px dashed var(--line); border-radius: 10px; padding: 8px 12px; background: var(--surface); font-size: 14px; }
.turn h4 { margin: 0 0 4px; font-size: 13px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
.turn .verdict-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-bottom: 4px; }
.event { text-align: center; font-size: 13px; color: var(--muted); margin: 10px 0; }
details summary { cursor: pointer; color: var(--muted); font-size: 13px; }
ul.aspects { margin: 4px 0 0; padding-left: 18px; font-size: 13px; }
a { color: var(--info); }
@media (max-width: 720px) { .turn { grid-template-columns: 1fr; } .msg { max-width: 100%; } }
@media print { body { background: #FFF; } details { display: block; } }
"""


def _verdict(verdict):
    return f'<span class="badge v-{escape(verdict)}">{escape(verdict)}</span>' if verdict else ""


def _category(cat):
    return f'<span class="badge c-{cat}">{MARKS[cat]} {escape(CATEGORIES[cat])}</span>'


def _anchor(result):
    return f"t{result['team_id']}-m{result['message_id']}"


def _aspects(aspects):
    if not aspects:
        return '<p class="muted small">Nog geen tussenstand.</p>'
    items = "".join(f"<li><strong>{escape(str(a.get('naam')))}</strong>: {_verdict(a.get('niveau'))} "
                    f"<span class=\"muted\">(bericht {escape(str(a.get('bericht')))}: “{escape(str(a.get('bewijs', '')))}”)</span></li>"
                    for a in aspects)
    return f'<ul class="aspects">{items}</ul>'


def _rubric_table(rubric):
    if not rubric:
        return '<p class="muted small">Geen rubric: de AI kan bij deze opdracht alleen suggesties doen.</p>'
    rows = "".join(
        f"<tr><td><strong>{escape(str(a.get('naam')))}</strong><br><span class=\"muted small\">{'verplicht' if a.get('verplicht') else 'niet verplicht'}</span></td>"
        f"<td>{escape(str(a.get('onvoldoende', '')))}</td><td>{escape(str(a.get('voldoende', '')))}</td><td>{escape(str(a.get('goed', '')))}</td></tr>"
        for a in rubric.get("aspecten", []))
    return (f"<p><strong>Kern:</strong> {escape(str(rubric.get('kern', '')))}</p>"
            f"<table><thead><tr><th>Aspect</th><th>Onvoldoende</th><th>Voldoende</th><th>Goed</th></tr></thead><tbody>{rows}</tbody></table>")


def _summary(run):
    s = run.summary()
    rows = "".join(f"<tr><td>{_category(k)}</td><td class=\"num\">{s['old'][k]}</td><td class=\"num\">{s['new'][k]}</td></tr>"
                   for k in CATEGORIES)
    return (f'<div class="card"><table><thead><tr><th>Uitkomst</th><th class="num">AI van toen</th><th class="num">Huidige AI</th></tr></thead>'
            f"<tbody>{rows}</tbody></table>"
            f"<p class=\"small\" style=\"margin:10px 0 0\"><strong>Onterechte level-ups door de huidige AI: {s['wrong_level_ups']}</strong> · "
            f"{s['turns']} beurten beoordeeld, waarvan {s['with_old']} toen ook door de AI.</p></div>")


def _review_list(run):
    items = []
    for r in run.results.values():
        differs = r["old_category"] is not None and r["category"] != r["old_category"]
        if r["category"] in ("strenger", "soepeler") or differs:
            team = next(t for t in run.job["teams"] if t["team_id"] == r["team_id"])
            items.append(f'<li><a href="#{_anchor(r)}">{escape(team["team_name"])}, level {r["level"]}</a> — {_category(r["category"])}'
                         + (f' <span class="muted small">(toen: {escape(CATEGORIES[r["old_category"]])})</span>' if differs else "") + "</li>")
    if not items:
        return '<p class="muted">Niets bijzonders: de huidige AI wijkt nergens af van de operator of van de AI van toen.</p>'
    return "<ul>" + "".join(items) + "</ul>"


def _old_block(result):
    old = result["old"]
    parts = []
    if result["truth_text"]:
        parts.append(f"<div>{escape(result['truth_text'])}</div>")
    if old:
        parts.append(f'<div class="verdict-row">AI: {escape(old.get("intent") or "")} {_verdict(old.get("verdict"))} '
                     f'<span class="muted small">→ {escape(DECISIONS.get(old.get("decision"), old.get("decision") or ""))}</span></div>')
        if old.get("reply_text"):
            parts.append(f'<div class="text small">“{escape(old["reply_text"])}”</div>')
        if old.get("reason"):
            parts.append(f'<details><summary>Reden</summary><div class="text small">{escape(old["reason"])}</div></details>')
    if not parts:
        parts.append('<div class="muted">Geen AI-beoordeling en geen duidelijke reactie van een operator.</div>')
    return "<div><h4>Toen</h4>" + "".join(parts) + "</div>"


def _new_block(result):
    parts = [f'<div class="verdict-row">{_category(result["category"])}</div>',
             f'<div class="verdict-row">AI: {escape(result["intent"] or "")} {_verdict(result["verdict"])} '
             f'<span class="muted small">→ {escape(DECISIONS.get(result["decision"], result["decision"] or ""))}</span></div>']
    if result["reply_text"]:
        parts.append(f'<div class="text small">“{escape(result["reply_text"])}”</div>')
    parts.append(f'<details><summary>Reden en tussenstand</summary><div class="text small">{escape(result["reason"])}</div>'
                 f'{_aspects(result["progress"])}</details>')
    return "<div><h4>Nu</h4>" + "".join(parts) + "</div>"


def _team(run, team):
    html = [f'<h2 id="team-{team["team_id"]}">{escape(team["team_name"])}</h2>']
    for level, msgs in run.levels(team):
        a = run.assignments[level]
        html.append(f"<h3>Level {level}: {escape(a['title'])}</h3>")
        judged = set(turns(msgs))
        for i, m in enumerate(msgs):
            html.append(f'<div class="msg msg-{escape(m["sender"])}"><div class="meta">{SENDERS.get(m["sender"], m["sender"])} · {escape(str(m["time"]))}</div>'
                        f'<div class="text">{escape(m["text"])}</div></div>')
            if m["sender"] == "team" and i not in judged:
                html.append('<div class="event">(samen met het volgende teambericht beoordeeld)</div>')
            result = run.results.get((team["team_id"], m["id"]))
            if result:
                html.append(f'<div class="turn" id="{_anchor(result)}">{_old_block(result)}{_new_block(result)}</div>')
            for c in team.get("completed", []):
                if c["level"] == level and c.get("accepted_message_id") == m["id"]:
                    who = {"operator": "door een operator", "ai": "door de AI"}.get(c.get("leveled_by"), "")
                    html.append(f'<div class="event">↑ Level omhoog {who} · {escape(str(c["time"]))}</div>')
        for d in team.get("level_downs", []):
            if d["to_level"] == level:
                html.append(f'<div class="event">↓ Een operator zette het team terug naar level {level} · {escape(str(d["time"]))}</div>')
    return "".join(html)


def render(run, settings=None):
    job = run.job
    game = escape((job.get("game") or {}).get("name", ""))
    models = ""
    if settings is not None:
        models = (f"Beoordelaar {escape(', '.join(settings.ASSESSOR_MODELS))}, validator {escape(settings.VALIDATOR_MODEL)}, "
                  f"intentie {escape(settings.INTENT_MODEL)}")
    rubric_note = ("Voor deze replay stelde de opdracht-assistent eerst een nieuwe rubric voor (de opdrachten zelf zijn niet veranderd)."
                   if run.new_rubric else "Met de rubric die nu bij elke opdracht staat.")
    rubrics = []
    for number in sorted({level for t in job["teams"] for level, _ in run.levels(t)}):
        a = run.assignments[number]
        info = run.rubrics.get(number)
        note = ""
        if info and info.get("error"):
            note = f'<p class="small c-operator badge">Nieuwe rubric mislukt ({escape(info["error"])}); de huidige is gebruikt.</p>'
        rubrics.append(f"<details class=\"card\"><summary><strong>Opdracht {number}: {escape(a['title'])}</strong></summary>"
                       f"{note}{_rubric_table(run.rubric_for(number))}</details>")
    teams = ", ".join(escape(t["team_name"]) for t in job["teams"]) or "geen"
    return f"""<!doctype html>
<html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI-replay: {game}</title><style>{CSS}</style></head>
<body><main>
<h1>AI-replay: {game}</h1>
<p class="muted">Gemaakt op {datetime.now():%d-%m-%Y %H:%M} · taak #{job["job_id"]} · teams: {teams}</p>
<div class="card small">
<p style="margin-top:0">De huidige AI heeft de chats van deze teams opnieuw beoordeeld, bericht voor bericht, in de modus
<em>automatisch</em>: zo zie je wat de AI zelf zou doen. Er is niets naar de teams gestuurd. Per beurt staat links wat er
<strong>toen</strong> gebeurde en rechts wat de AI <strong>nu</strong> zou doen. De vergelijking gaat uit van de operator:
het bericht waarna een operator het team doorzette was goed; een bericht waarop een operator antwoordde zonder door te
zetten nog niet. Let op: had de AI nu anders gereageerd, dan had het team daarna misschien iets anders geschreven.</p>
<p style="margin-bottom:0">{escape(rubric_note)} {models}</p>
</div>
<h2>Samenvatting</h2>
{_summary(run)}
<h2>Om na te kijken</h2>
{_review_list(run)}
<h2>Gebruikte rubrics</h2>
{"".join(rubrics) or '<p class="muted">Geen opdrachten met berichten.</p>'}
{"".join(_team(run, t) for t in job["teams"])}
</main></body></html>
"""
