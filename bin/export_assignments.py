import argparse
import sqlite3
import tarfile
import json
import os
import sys
import io
from datetime import datetime

# Paden configureren relatief aan dit script
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, '../database/leaderboard.db')
HTDOCS_PATH = os.path.join(BASE_DIR, '../htdocs')

def export_assignments(game_id, output_name=None):
    if not output_name:
        output_name = f"assignments_game{game_id}_{datetime.now().strftime('%Y%m%d_%H%M%S')}.tar.gz"

    if not os.path.exists(DB_PATH):
        print(f"Fout: Database niet gevonden op {DB_PATH}")
        return

    try:
        # 1. Haal data op uit de database
        conn = sqlite3.connect(DB_PATH)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()
        
        cursor.execute("SELECT name FROM games WHERE id = ?", (game_id,))
        game = cursor.fetchone()
        if not game:
            print(f"Fout: spel {game_id} bestaat niet.")
            return

        cursor.execute("SELECT * FROM assignments WHERE game_id = ? ORDER BY assignment_number ASC", (game_id,))
        rows = cursor.fetchall()
        
        if not rows:
            print(f"Geen opdrachten gevonden in spel {game_id} ({game['name']}).")
            return

        # 2. Bouw het manifest en verzamel bestanden
        manifest = []
        assignments = [dict(row) for row in rows]
        
        # Gebruik tarfile met gzip compressie
        with tarfile.open(output_name, "w:gz") as tar:
            for a in assignments:
                entry = {
                    'assignment_number': a['assignment_number'],
                    'title': a['title'],
                    'description': a['description'],
                    'instruction': a.get('instruction', ''),
                    'criteria': a.get('criteria', ''),
                    'time_limit': a.get('time_limit', 0),
                    'artifact_file': os.path.basename(a['artifact_file']) if a['artifact_file'] else None
                }
                manifest.append(entry)

                # Voeg artifact bestand toe aan het archief
                if a['artifact_file']:
                    file_path = os.path.join(HTDOCS_PATH, a['artifact_file'])
                    if os.path.exists(file_path):
                        tar.add(file_path, arcname=f"files/{os.path.basename(a['artifact_file'])}")
                    else:
                        print(f"Waarschuwing: Bestand niet gevonden: {file_path}")

            # Voeg manifest.json toe aan de root van de tar
            manifest_data = json.dumps(manifest, indent=4).encode('utf-8')
            manifest_info = tarfile.TarInfo(name="manifest.json")
            manifest_info.size = len(manifest_data)
            tar.addfile(manifest_info, io.BytesIO(manifest_data))

        print(f"Export succesvol afgerond: {output_name}")

    except Exception as e:
        print(f"Kritieke fout bij export: {e}")
    finally:
        if 'conn' in locals():
            conn.close()

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Exporteer de opdrachten van één spel naar een tar.gz met manifest.json.")
    parser.add_argument("--game", type=int, required=True, help="id van het spel (zie 🎮 Spellen)")
    parser.add_argument("output", nargs="?", help="bestandsnaam van het archief (optioneel)")
    args = parser.parse_args()
    export_assignments(args.game, args.output)