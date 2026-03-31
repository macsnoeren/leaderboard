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

def export_assignments(output_name=None):
    if not output_name:
        output_name = f"assignments_export_{datetime.now().strftime('%Y%m%d_%H%M%S')}.tar.gz"

    if not os.path.exists(DB_PATH):
        print(f"Fout: Database niet gevonden op {DB_PATH}")
        return

    try:
        # 1. Haal data op uit de database
        conn = sqlite3.connect(DB_PATH)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()
        
        cursor.execute("SELECT * FROM assignments ORDER BY assignment_number ASC")
        rows = cursor.fetchall()
        
        if not rows:
            print("Geen opdrachten gevonden om te exporteren.")
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
    # Gebruik argument als bestandsnaam indien opgegeven
    target_file = sys.argv[1] if len(sys.argv) > 1 else None
    export_assignments(target_file)