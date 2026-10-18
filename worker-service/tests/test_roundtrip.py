"""T2.8: export → import → export geeft hetzelfde spel (op id's, bestandsnamen, tokens en exporttijd na)."""
import hashlib
import json
import os
import unittest

from tests.helpers import Env, make_env, seed_game
from tests.test_export_game import read_tar
from worker import db
from worker.jobs import Reporter
from worker.tasks.export_game import export_game
from worker.tasks.import_game import import_archive


def normalized(files):
    """Manifest en progress.json met bestandsnamen vervangen door de inhoud (sha256)."""
    manifest = json.loads(files["manifest.json"])
    by_hash = {}
    for name in manifest.pop("files"):
        by_hash[name] = hashlib.sha256(files[name]).hexdigest()
    manifest.pop("exported_at")
    for a in manifest["assignments"]:
        if a["artifact_file"]:
            a["artifact_file"] = by_hash[f"files/{a['artifact_file']}"]
    if manifest["game"]["logo_file"]:
        manifest["game"]["logo_file"] = by_hash[f"logo/{manifest['game']['logo_file']}"]
    progress = json.loads(files["progress.json"]) if "progress.json" in files else None
    return manifest, progress


class RoundTripTest(unittest.TestCase):
    def setUp(self):
        self.source = make_env(self)
        self.ids = seed_game(self.source)
        self.target = Env()
        self.addCleanup(self.target.cleanup)

    def roundtrip(self, include_progress):
        first = os.path.join(self.source.root, "eerste.tar.gz")
        export_game(self.source.conn, self.source.htdocs, self.ids["game"], include_progress, first, Reporter(), app_version="t")
        conn = db.connect(self.target.db_file)
        self.addCleanup(conn.close)
        game_id = import_archive(conn, self.target.htdocs, first, Reporter())
        second = os.path.join(self.target.root, "tweede.tar.gz")
        export_game(conn, self.target.htdocs, game_id, include_progress, second, Reporter(), app_version="t")
        return read_tar(first), read_tar(second)

    def test_template(self):
        a, b = self.roundtrip(False)
        self.assertEqual(normalized(a), normalized(b))

    def test_with_progress(self):
        self.maxDiff = None
        a, b = self.roundtrip(True)
        (ma, pa), (mb, pb) = normalized(a), normalized(b)
        # Bewust anders: een lopend spel komt gepauzeerd binnen (DESIGN §6.3)
        self.assertEqual((pa["game"].pop("status"), pb["game"].pop("status")), ("running", "paused"))
        self.assertEqual((ma, pa), (mb, pb))

    def test_attachments_byte_for_byte(self):
        a, b = self.roundtrip(False)
        self.assertEqual(sorted(hashlib.sha256(v).hexdigest() for k, v in a.items() if k.startswith(("files/", "logo/"))),
                         sorted(hashlib.sha256(v).hexdigest() for k, v in b.items() if k.startswith(("files/", "logo/"))))


if __name__ == "__main__":
    unittest.main()
