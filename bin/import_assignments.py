import sqlite3
import tarfile
import json
import os
import sys
import shutil
import time

# Paden configureren
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, '../conf/leaderboard.db')
ARTIFACTS_DIR = os.path.join(BASE_DIR, '../htdocs/artifacts/')

def import_assignments(archive_path):
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

            # 2. Database connectie en opschonen
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            
            print("Huidige opdrachten verwijderen...")
            cursor.execute("DELETE FROM assignments")

            # 3. Importeer data en bestanden
            for a in assignments:
                new_file_path = None
                
                if a['artifact_file']:
                    # Genereer unieke bestandsnaam (conform PHP logica)
                    safe_name = f"assignment_{a['assignment_number']}_{int(time.time())}_{a['artifact_file']}"
                    
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

                # Database vullen
                cursor.execute("""
                    INSERT INTO assignments 
                    (assignment_number, title, description, instruction, criteria, time_limit, artifact_file) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                """, (
                    a['assignment_number'],
                    a['title'],
                    a['description'],
                    a.get('instruction', ''),
                    a.get('criteria', ''),
                    a.get('time_limit', 0),
                    new_file_path
                ))
                print(f"Geïmporteerd: #{a['assignment_number']} - {a['title']}")

            conn.commit()
            print("Import succesvol voltooid!")

    except Exception as e:
        print(f"Kritieke fout bij import: {e}")
    finally:
        if 'conn' in locals():
            conn.close()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Gebruik: python import_assignments.py <bestandsnaam.tar.gz>")
    else:
        import_assignments(sys.argv[1])