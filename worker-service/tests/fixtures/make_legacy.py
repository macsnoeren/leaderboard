"""Maakt legacy_v1.tar.gz en legacy_v2.tar.gz met de oude exportscripts zelf (zie README.md).

Uitvoeren vanuit de repo-root: python3 worker-service/tests/fixtures/make_legacy.py <v2-script>
<v2-script> is bin/export_assignments.py zoals het was vóór de worker (standaard: git show 87ecf58:…).
"""
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", "..", ".."))

RUBRIC = {
    "kern": "De conciërge had de sleutel",
    "aspecten": [{"naam": "Dader", "verplicht": True, "onvoldoende": "geen of verkeerde naam",
                  "voldoende": "de conciërge", "goed": "de conciërge, met de sleutel als bewijs"}],
    "ook_goed": ["de beheerder"],
    "veelgemaakte_fouten": [],
    "verduidelijkingen_vraag": [],
    "niveau_regel": "Alle verplichte aspecten minimaal voldoende",
}


def git_show(rev_path):
    return subprocess.run(["git", "show", rev_path], cwd=REPO, check=True, capture_output=True, text=True).stdout


def make_tree(script_source):
    root = tempfile.mkdtemp(prefix="legacy-")
    for d in ("bin", "database", "htdocs/artifacts"):
        os.makedirs(os.path.join(root, d))
    with open(os.path.join(root, "bin", "export.py"), "w", encoding="utf-8") as f:
        f.write(script_source)
    with open(os.path.join(root, "htdocs", "artifacts", "assignment_1_bijlage.txt"), "w", encoding="utf-8") as f:
        f.write("Het logboek van de conciërge: 21:04 sleutel uitgeleend.\n")
    return root


def run_export(root, args, target):
    out = tempfile.mkdtemp(prefix="legacy-out-")
    subprocess.run([sys.executable, os.path.join(root, "bin", "export.py"), *args, "export.tar.gz"], cwd=out, check=True)
    shutil.copy(os.path.join(out, "export.tar.gz"), target)
    shutil.rmtree(out)


def make_v1():
    """Eerste versie (commit c249ede): geen spellen, geen AI-velden."""
    root = make_tree(git_show("c249ede:bin/export_assignments.py"))
    db = sqlite3.connect(os.path.join(root, "database", "leaderboard.db"))
    db.execute("CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_number INTEGER, title TEXT, "
               "description TEXT, instruction TEXT, criteria TEXT, time_limit INTEGER, artifact_file TEXT)")
    db.execute("INSERT INTO assignments (assignment_number, title, description, instruction, criteria, time_limit, artifact_file) "
               "VALUES (1, 'De sleutel', 'Wie had de sleutel?', 'Lees het logboek', 'De conciërge', 30, 'artifacts/assignment_1_bijlage.txt')")
    db.execute("INSERT INTO assignments (assignment_number, title, description, instruction, criteria, time_limit, artifact_file) "
               "VALUES (2, 'Het motief', 'Waarom?', 'Denk na', 'Geldnood', 45, NULL)")
    db.commit()
    db.close()
    run_export(root, [], os.path.join(HERE, "legacy_v1.tar.gz"))
    shutil.rmtree(root)


def make_v2(script_source):
    """Versie met spellen en rubric (bin/export_assignments.py --game 1)."""
    root = make_tree(script_source)
    db = sqlite3.connect(os.path.join(root, "database", "leaderboard.db"))
    db.execute("CREATE TABLE games (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)")
    db.execute("INSERT INTO games (name) VALUES ('Operation Gameplay')")
    db.execute("CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, assignment_number INTEGER, "
               "title TEXT, description TEXT, instruction TEXT, criteria TEXT, time_limit INTEGER, artifact_file TEXT, "
               "ai_rubric TEXT, ai_rubric_status TEXT DEFAULT 'none', ai_auto_allowed INTEGER DEFAULT 1)")
    db.execute("INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, time_limit, "
               "artifact_file, ai_rubric, ai_rubric_status, ai_auto_allowed) VALUES (1, 1, 'De sleutel', 'Wie had de sleutel?', "
               "'Lees het logboek', 'De conciërge', 30, 'artifacts/assignment_1_bijlage.txt', ?, 'approved', 1)",
               (json.dumps(RUBRIC, ensure_ascii=False),))
    db.execute("INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, time_limit, "
               "artifact_file, ai_rubric, ai_rubric_status, ai_auto_allowed) VALUES (1, 2, 'Het motief', 'Waarom?', 'Denk na', "
               "'Geldnood', 45, NULL, NULL, 'none', 0)")
    db.commit()
    db.close()
    run_export(root, ["--game", "1"], os.path.join(HERE, "legacy_v2.tar.gz"))
    shutil.rmtree(root)


if __name__ == "__main__":
    v2_source = open(sys.argv[1], encoding="utf-8").read() if len(sys.argv) > 1 else git_show("87ecf58:bin/export_assignments.py")
    make_v1()
    make_v2(v2_source)
    print("Fixtures gemaakt in", HERE)
