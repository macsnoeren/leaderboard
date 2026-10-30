"""AI-replay (README, "AI-replay"): de huidige pijplijn beoordeelt de chats van gekozen teams opnieuw,
bericht voor bericht, en zet dat naast wat er toen gebeurde. Er gaat niets naar api.php behalve voortgang
en het rapport (replay_report.py); er wordt niets ingediend, niemand krijgt een bericht.

Een replay loopt in stappen (step()), zodat main.py tussendoor de echte teamberichten kan doen. Per level
beoordeelt de AI, net als in het spel, alleen het laatste bericht van een reeks teamberichten op rij, met
de chat tot dat moment en een tussenstand die tijdens de replay wordt opgebouwd (merge_progress()).

Wat er "toen" gebeurde (truth()): het bericht waarna een operator het team doorzette is goed; een bericht
waarop een operator antwoordde zonder door te zetten is (nog) niet goed; een level-up door de AI die een
operator terugdraaide is niet goed; anders telt het oordeel "AI had gelijk/ongelijk" uit het AI-logboek.
"""
import logging
from datetime import datetime, timedelta

from .agents import assessor, designer
from .llm import LLMError
from .pipeline import Pipeline

logger = logging.getLogger(__name__)

PASS = ("voldoende", "goed")
RECENT = timedelta(minutes=10)
# De opdracht-assistent krijgt meteen de laatste ronde: hij moet een rubric geven, geen vragen aan de docent
DESIGN_ROUNDS = 3

# Uitkomst van een beoordeling naast wat er toen gebeurde
CATEGORIES = {
    "eens": "Eens met de operator",
    "strenger": "Strenger: zette een goedgekeurd antwoord niet door",
    "soepeler": "Soepeler: keurde goed wat de operator (nog) niet goedkeurde",
    "operator": "Naar de operator (escalatie)",
    "vraag": "Vraag of overig, geen oordeel",
    "geen": "Geen vergelijking: onbekend wat er toen goed was",
}


def _time(value):
    try:
        return datetime.strptime(str(value)[:19], "%Y-%m-%d %H:%M:%S")
    except ValueError:
        return None


def turns(level_messages):
    """Indexen van de teamberichten die de AI beoordeelt: het laatste van elke reeks teamberichten op rij."""
    return [i for i, m in enumerate(level_messages)
            if m["sender"] == "team" and (i + 1 == len(level_messages) or level_messages[i + 1]["sender"] != "team")]


def old_evaluation(team, message_id):
    """De laatste niet-verouderde beoordeling van dit bericht door de AI van toen, of None."""
    found = [e for e in team.get("evaluations", []) if e.get("message_id") == message_id and e.get("decision") != "stale"]
    return found[-1] if found else None


def truth(team, level, level_messages, i):
    """(True = goed / False = nog niet goed / None = onbekend, uitleg) voor het teambericht op plek i."""
    m = level_messages[i]
    old = old_evaluation(team, m["id"])
    for c in team.get("completed", []):
        if c["level"] != level or c.get("accepted_message_id") != m["id"]:
            continue
        if c.get("leveled_by") == "ai":
            if any(d["to_level"] == level and str(d["time"]) >= str(c["time"]) for d in team.get("level_downs", [])):
                return False, "De AI zette het team door; een operator zette het terug"
            if old and old.get("teacher_verdict") in ("agree", "disagree"):
                return old["teacher_verdict"] == "agree", "De AI zette het team door; de operator vond dat " + (
                    "terecht" if old["teacher_verdict"] == "agree" else "onterecht")
            return None, "De AI zette het team door (geen oordeel van een operator)"
        return True, "Een operator zette het team door" if c.get("leveled_by") == "operator" else "Het team ging door"
    following = []
    for later in level_messages[i + 1:]:
        if later["sender"] == "team":
            break
        following.append(later)
    if any(x["sender"] == "teacher" for x in following):
        return False, "Een operator antwoordde zonder het team door te zetten"
    if old and old.get("teacher_verdict") in ("agree", "disagree") and old.get("intent") == "antwoord":
        old_pass = old.get("verdict") in PASS
        agree = old["teacher_verdict"] == "agree"
        return (old_pass if agree else not old_pass), "Oordeel van de operator in het AI-logboek"
    return None, ""


def category(intent, verdict, decision, truth_value):
    if decision == "escalate":
        return "operator"
    if intent != "antwoord":
        return "vraag"
    if truth_value is None:
        return "geen"
    passed = verdict in PASS
    if passed == truth_value:
        return "eens"
    return "soepeler" if passed else "strenger"


class ReplayRun:
    def __init__(self, job, llm, settings, pipeline=None):
        self.job = job
        self.job_id = job["job_id"]
        self.llm = llm
        self.settings = settings
        self.pipeline = pipeline or Pipeline(llm, settings)
        self.assignments = {a["number"]: a for a in job.get("assignments", [])}
        self.new_rubric = bool(job.get("new_rubric"))
        self.rubrics = {}        # nummer → {'rubric', 'source', 'error'}
        self.progress = {}       # (team_id, level) → {'aspects', 'summary'}
        self.results = {}        # (team_id, message_id) → uitkomst
        self.started = datetime.now()
        self.steps = []
        used = set()
        for t, team in enumerate(job.get("teams", [])):
            for level, msgs in self.levels(team):
                for i in turns(msgs):
                    self.steps.append(("turn", t, level, i))
                    used.add(level)
        if self.new_rubric:
            self.steps = [("rubric", n) for n in sorted(used)] + self.steps
        self.pos = 0

    def levels(self, team):
        """[(level, berichten van dat level op volgorde)] voor levels met een opdracht."""
        out = []
        for level in sorted({m["level"] for m in team.get("messages", [])}):
            if level in self.assignments:
                out.append((level, [m for m in team["messages"] if m["level"] == level]))
        return out

    @property
    def done(self):
        return self.pos >= len(self.steps)

    @property
    def percent(self):
        return int(self.pos * 100 / len(self.steps)) if self.steps else 100

    def step(self):
        """Doet de volgende stap; geeft een korte omschrijving voor de voortgang."""
        step = self.steps[self.pos]
        self.pos += 1
        if step[0] == "rubric":
            return self._design(step[1])
        _, t, level, i = step
        team = self.job["teams"][t]
        msgs = dict(self.levels(team))[level]
        self._assess(team, level, msgs, i)
        number = turns(msgs).index(i) + 1
        return f"Team {team['team_name']}, level {level}: beurt {number} van {len(turns(msgs))}"

    # --- rubric -----------------------------------------------------------------------------------------

    def _design(self, number):
        a = self.assignments[number]
        job = {"assignment_id": 0, "title": a["title"], "description": a["description"], "instruction": a["instruction"],
               "criteria": a["criteria"], "has_attachment": bool(a.get("has_attachment")), "rubric": None,
               "round": DESIGN_ROUNDS, "max_rounds": DESIGN_ROUNDS, "dialog": a.get("clarifications") or []}
        try:
            result, _meta = designer.design(self.llm, self.settings, job)
            self.rubrics[number] = {"rubric": result["rubric"], "source": "nieuw", "error": None}
        except LLMError as e:
            logger.warning("Replay %s: nieuwe rubric voor opdracht %s mislukt: %s", self.job_id, number, e)
            self.rubrics[number] = {"rubric": a.get("rubric"), "source": "huidig", "error": str(e)}
        return f"Nieuwe rubric voor opdracht {number}"

    def rubric_for(self, number):
        if number in self.rubrics:
            return self.rubrics[number]["rubric"]
        return self.assignments[number].get("rubric")

    # --- beoordelen -------------------------------------------------------------------------------------

    def build_task(self, team, level, msgs, i):
        """De taak zoals aiPendingTasks() hem op dat moment had gegeven, in modus auto: wat doet de AI zelf?"""
        a = self.assignments[level]
        m = msgs[i]
        rubric = self.rubric_for(level)
        game = self.job.get("game") or {}
        return {
            "team_id": team["team_id"], "game_status": "running", "current_level": level,
            "assignment_title": a["title"], "description": a["description"], "instruction": a["instruction"],
            "criteria": a["criteria"], "time_limit": a.get("time_limit", 0),
            "message_id": m["id"], "message_time": m["time"],
            "ai_mode": "auto", "ai_auto_allowed": True,
            "rubric": rubric, "rubric_status": "approved" if rubric else "none",
            "clarifications": a.get("clarifications") or [],
            "recent_attempts": self._recent(team, level, m, "antwoord"),
            "recent_questions": self._recent(team, level, m, "vraag"),
            "max_attempts": int(game.get("ai_max_attempts", 5)), "max_questions": int(game.get("ai_max_questions", 5)),
            "escalation_open": False,
            "progress": self.progress.get((team["team_id"], level)),
            "messages": [{"id": x["id"], "sender": x["sender"], "time": x["time"], "text": x["text"]} for x in msgs[:i + 1]],
        }

    def _recent(self, team, level, message, intent):
        """Beoordelingen van dit team op dit level in de 10 minuten vóór dit bericht, zoals aiRecentCounts()."""
        now = _time(message["time"])
        if now is None:
            return 0
        count = 0
        for r in self.results.values():
            if r["team_id"] == team["team_id"] and r["level"] == level and r["intent"] == intent and r["decision"] != "escalate":
                t = _time(r["time"])
                if t is not None and now - RECENT < t <= now:
                    count += 1
        return count

    def _assess(self, team, level, msgs, i):
        task = self.build_task(team, level, msgs, i)
        payload = self.pipeline.process(task, f"replay-{self.job_id}")
        key = (team["team_id"], level)
        if payload.get("intent") == "antwoord" and payload.get("progress"):
            previous = (self.progress.get(key) or {}).get("aspects") or []
            self.progress[key] = {"aspects": assessor.merge_progress(previous, payload["progress"]["aspects"], task["messages"]),
                                  "summary": payload["progress"].get("summary") or (self.progress.get(key) or {}).get("summary", "")}
        truth_value, truth_text = truth(team, level, msgs, i)
        old = old_evaluation(team, msgs[i]["id"])
        m = msgs[i]
        self.results[(team["team_id"], m["id"])] = {
            "team_id": team["team_id"], "level": level, "message_id": m["id"], "time": m["time"],
            "intent": payload.get("intent"), "verdict": payload.get("verdict"), "decision": payload.get("decision"),
            "reason": payload.get("reason", ""), "reply_text": payload.get("reply_text", ""),
            "models": payload.get("models", []), "duration_ms": payload.get("duration_ms"),
            "progress": [dict(a) for a in (self.progress.get(key) or {}).get("aspects", [])],
            "truth": truth_value, "truth_text": truth_text, "old": old,
            "category": category(payload.get("intent"), payload.get("verdict"), payload.get("decision"), truth_value),
            "old_category": (category(old.get("intent"), old.get("verdict"), old.get("decision"), truth_value) if old else None),
        }

    # --- samenvatting -----------------------------------------------------------------------------------

    def summary(self):
        """Aantallen per categorie voor de oude en de nieuwe AI, plus onterechte level-ups."""
        new = {k: 0 for k in CATEGORIES}
        old = {k: 0 for k in CATEGORIES}
        wrong_level_ups = 0
        for r in self.results.values():
            new[r["category"]] += 1
            if r["old_category"]:
                old[r["old_category"]] += 1
            if r["category"] == "soepeler" and r["decision"] == "level_up":
                wrong_level_ups += 1
        return {"new": new, "old": old, "wrong_level_ups": wrong_level_ups, "turns": len(self.results),
                "with_old": sum(1 for r in self.results.values() if r["old"])}

