"""Gegevens voor het rapport, ook voor oude archiefregels (afgeleid)."""
import unittest

from tests.helpers import make_env, seed_game
from tests.schema import add_message, add_team
from worker.report import data


class ReportDataTest(unittest.TestCase):
    def setUp(self):
        self.env = make_env(self)
        self.ids = seed_game(self.env)
        self.c = self.env.conn
        self.game = self.ids["game"]

    def answers(self, level=1):
        result = data.accepted_answers(self.c, self.game)
        return {r["team_name"]: r for r in result[level - 1]["rows"]}

    def add_old_row(self, team_id, team_name, level, chat, created="-20 minutes"):
        self.c.execute("INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history, created_at) "
                       "VALUES (?, ?, ?, ?, ?, datetime('now', ?))", (self.game, team_id, team_name, level, chat, created))

    def test_new_row_uses_columns(self):
        row = self.answers()["De Speurneuzen"]
        self.assertEqual(row["answer"], "De conciërge")
        self.assertEqual(row["by"], "J. Jansen")
        self.assertFalse(row["derived"])
        # Pogingen uit de AI-beoordelingen (1 antwoord beoordeeld)
        self.assertEqual(row["attempts"], 1)

    def test_old_row_is_derived(self):
        team = add_team(self.c, self.game, "Oude Rot", level=2)
        self.add_old_row(team, "Oude Rot", 1, "Team: Eerste gok\nDocent: Nee\nTeam: De conciërge,\nmet de sleutel\nDocent: Goed!")
        self.c.execute("INSERT INTO audit_logs (user_id, event_type, description, created_at) VALUES (?, 'TEAM_LEVEL_UP', ?, "
                       "datetime('now', '-20 minutes', '+2 seconds'))", (self.ids["teacher"], f"Team 'Oude Rot' (ID {team}) naar level 2"))
        row = self.answers()["Oude Rot"]
        self.assertEqual(row["answer"], "De conciërge,\nmet de sleutel")
        self.assertEqual(row["by"], "J. Jansen")
        self.assertTrue(row["derived"])

    def test_old_row_without_audit_is_unknown(self):
        team = add_team(self.c, self.game, "Stille", level=2)
        self.add_old_row(team, "Stille", 1, "Team: iets")
        row = self.answers()["Stille"]
        self.assertEqual(row["by"], "onbekend")
        self.assertTrue(row["derived"])

    def test_old_row_ai_from_audit(self):
        team = add_team(self.c, self.game, "Robots", level=2)
        self.add_old_row(team, "Robots", 1, "Team: x")
        self.c.execute("INSERT INTO audit_logs (user_id, event_type, description, created_at) VALUES (NULL, 'TEAM_LEVEL_UP', ?, "
                       "datetime('now', '-20 minutes'))", (f"Team 'Robots' (ID {team}) naar level 2",))
        self.assertEqual(self.answers()["Robots"]["by"], "AI")

    def test_deleted_team_listed(self):
        row = self.answers()["Vertrokken Team"]
        self.assertTrue(row["deleted"])
        self.assertEqual(row["answer"], "Het was de tuinman")

    def test_level_down_and_up_shows_latest_only(self):
        team = self.ids["teams"][0]
        self.c.execute("INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history, created_at, "
                       "leveled_by_name, accepted_answer, level_started_at) VALUES (?, ?, 'De Speurneuzen', 1, '', datetime('now'), "
                       "'J. Jansen', 'Tweede keer goed', datetime('now', '-5 minutes'))", (self.game, team))
        rows = [r for r in data.accepted_answers(self.c, self.game)[0]["rows"] if r["team_name"] == "De Speurneuzen"]
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["answer"], "Tweede keer goed")

    def test_attempts_without_ai_count_messages(self):
        team = add_team(self.c, self.game, "Handwerk", level=2)
        for text in ("a", "b", "c"):
            add_message(self.c, team, 1, "team", text)
        self.c.execute("INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history, created_at, "
                       "leveled_by_name, accepted_answer, level_started_at) VALUES (?, ?, 'Handwerk', 1, '', datetime('now'), 'X', 'c', "
                       "datetime('now', '-1 hour'))", (self.game, team))
        self.assertEqual(self.answers()["Handwerk"]["attempts"], 3)

    def test_team_traces(self):
        traces = {t["team_name"]: t for t in data.team_traces(self.c, self.game)}
        speur = traces["De Speurneuzen"]
        self.assertEqual(speur["rank"], 1)
        level = speur["levels"][0]
        self.assertEqual(level["duration_s"], int((level["approved_at"] - level["started_at"]).total_seconds()))
        self.assertEqual((level["attempts"], level["questions"]), (1, 0))
        self.assertTrue(traces["Vertrokken Team"]["deleted"])
        self.assertEqual(traces["Team Zebra"]["levels"], [])

    def test_questions_count_only_vraag(self):
        team, m = self.ids["teams"][0], self.ids["messages"][0]
        for intent in ("vraag", "vraag", "overig"):
            self.c.execute("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, intent) VALUES (?, ?, 1, ?, ?)",
                           (self.game, team, m, intent))
        level = {t["team_name"]: t for t in data.team_traces(self.c, self.game)}["De Speurneuzen"]["levels"][0]
        self.assertEqual((level["attempts"], level["questions"]), (1, 2))

    def test_level_down_in_trace(self):
        team = self.ids["teams"][0]
        self.c.execute("INSERT INTO audit_logs (user_id, event_type, description) VALUES (1, 'TEAM_LEVEL_DOWN', ?)",
                       (f"Team 'De Speurneuzen' (ID {team}) terug naar level 1",))
        downs = {t["team_name"]: t for t in data.team_traces(self.c, self.game)}["De Speurneuzen"]["level_downs"]
        self.assertEqual([d["to_level"] for d in downs], [1])

    def test_parse_chat(self):
        self.assertEqual(data.parse_chat("Team: a\nb\nAI: c\nDocent: d"), [("team", "a\nb"), ("ai", "c"), ("teacher", "d")])
        self.assertIsNone(data.last_team_message("Docent: alleen ik"))


if __name__ == "__main__":
    unittest.main()
