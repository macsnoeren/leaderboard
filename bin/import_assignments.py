import argparse
import sqlite3
import tarfile
import json
import os
import sys
import shutil
import time

# Paden configureren
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, '../database/leaderboard.db')
ARTIFACTS_DIR = os.path.join(BASE_DIR, '../htdocs/artifacts/')

def import_assignments(game_id, archive_path):
    if not os.path.exists(archive_path):
        print(f"Fout: Bestand niet gevonden: {archive_path}")
        return

    if not os.path.exists(ARTIFACTS_DIR):
        os.makedirs(ARTIFACTS_DIR, exist_ok=True)

    try:
        with tarfile.open(archive_path, "r:gz") as tar:
            # 1. Lees manifest
            try:
                manifest_file = tar.extractfile("manifest.json")
                if not manifest_file:
                    raise KeyError
                assignments = json.loads(manifest_file.read().decode('utf-8'))
            except (KeyError, json.JSONDecodeError):
                print("Fout: Ongeldig archief, manifest.json ontbreekt of is corrupt.")
                return

            # 2. Database connectie en opschonen (alleen de opdrachten van dit spel)
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()

            cursor.execute("SELECT name FROM games WHERE id = ?", (game_id,))
            game = cursor.fetchone()
            if not game:
                print(f"Fout: spel {game_id} bestaat niet.")
                return

            print(f"Huidige opdrachten van spel {game_id} ({game[0]}) verwijderen...")
            cursor.execute("SELECT artifact_file FROM assignments WHERE game_id = ? AND artifact_file IS NOT NULL", (game_id,))
            old_files = [row[0] for row in cursor.fetchall()]
            cursor.execute("DELETE FROM assignments WHERE game_id = ?", (game_id,))

            # 3. Importeer data en bestanden
            for a in assignments:
                new_file_path = None
                
                if a['artifact_file']:
                    # Eigen bestandsnaam per spel: een import in een ander spel mag de bestanden
                    # van het spel waaruit geëxporteerd is niet overschrijven
                    ext = os.path.splitext(a['artifact_file'])[1].lower()
                    safe_name = f"game_{game_id}_assignment_{int(a['assignment_number'])}_{int(time.time())}{ext}"
                    
                    try:
                        # Extraheer bestand direct uit de tar naar de doellocatie
                        member = tar.getmember(f"files/{a['artifact_file']}")
                        source = tar.extractfile(member)
                        if source:
                            with open(os.path.join(ARTIFACTS_DIR, safe_name), 'wb') as target:
                                shutil.copyfileobj(source, target)
                            new_file_path = f"artifacts/{safe_name}"
                    except KeyError:
                        print(f"Waarschuwing: Bestand {a['artifact_file']} niet in archief gevonden.")

                # Rubric: een oud archief zonder deze velden krijgt de standaardwaarden
                rubric = a.get('ai_rubric')
                if isinstance(rubric, (dict, list)):
                    rubric = json.dumps(rubric, ensure_ascii=False)
                rubric_status = a.get('ai_rubric_status') or 'none'
                if rubric_status not in ('none', 'draft', 'approved') or not rubric:
                    rubric_status = 'none' if not rubric else 'draft'

                # Database vullen
                cursor.execute("""
                    INSERT INTO assignments 
                    (game_id, assignment_number, title, description, instruction, criteria, time_limit, artifact_file,
                     ai_rubric, ai_rubric_status, ai_auto_allowed)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """, (
                    game_id,
                    a['assignment_number'],
                    a['title'],
                    a['description'],
                    a.get('instruction', ''),
                    a.get('criteria', ''),
                    a.get('time_limit', 0),
                    new_file_path,
                    rubric or None,
                    rubric_status,
                    0 if a.get('ai_auto_allowed', 1) in (0, '0', False) else 1,
                ))
                print(f"Geïmporteerd: #{a['assignment_number']} - {a['title']}")

            conn.commit()

            # Oude bestanden van dit spel opruimen, tenzij een andere opdracht ze nog gebruikt
            for old_file in old_files:
                cursor.execute("SELECT COUNT(*) FROM assignments WHERE artifact_file = ?", (old_file,))
                path = os.path.join(ARTIFACTS_DIR, '..', old_file)
                if cursor.fetchone()[0] == 0 and os.path.isfile(path):
                    os.remove(path)

            print("Import succesvol voltooid!")

    except Exception as e:
        print(f"Kritieke fout bij import: {e}")
    finally:
        if 'conn' in locals():
            conn.close()

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Importeer opdrachten in één spel. Let op: wist eerst de opdrachten van dat spel.")
    parser.add_argument("--game", type=int, required=True, help="id van het spel (zie 🎮 Spellen)")
    parser.add_argument("archive", help="bestandsnaam van het archief (.tar.gz)")
    args = parser.parse_args()
    import_assignments(args.game, args.archive)