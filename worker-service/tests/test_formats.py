"""Formaatherkenning en de v3-lezer."""
import os
import tempfile
import unittest

from tests.test_legacy import make_archive, open_fixture
from worker.archive import ArchiveReader
from worker.formats import GAME_ALLOWED, detect_format, read_archive
from worker.jobs import UserError

MB = 1024 * 1024

V3 = {
    "format": "zebrawave-game", "format_version": 3, "include_progress": False,
    "game": {"name": "Operatie", "ai_mode": "reply", "ai_max_attempts": 7, "ai_max_questions": 99, "logo_file": "logo.png"},
    "assignments": [{"assignment_number": 1, "title": "De sleutel", "criteria": "De conciërge", "artifact_file": "assignment_1_bijlage.txt",
                     "ai_rubric": {"kern": "x"}, "ai_rubric_status": "approved", "ai_auto_allowed": 1}],
    "files": {},
}


class FormatsTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)

    def read(self, manifest, files=None):
        path = os.path.join(self.dir.name, "a.tar.gz")
        make_archive(path, manifest, files)
        with ArchiveReader(path, GAME_ALLOWED, MB, 10) as r:
            return read_archive(r)

    def test_detect(self):
        self.assertEqual(detect_format([{"title": "x"}]), "v1")
        self.assertEqual(detect_format([{"title": "x", "ai_rubric": None}]), "v2")
        self.assertEqual(detect_format(V3), "v3")
        self.assertEqual(detect_format(dict(V3, format_version=4)), "newer")
        self.assertEqual(detect_format({"format": "zebrawave-backup", "format_version": 1}), "backup")
        self.assertEqual(detect_format({"iets": 1}), "unknown")
        self.assertEqual(detect_format("tekst"), "unknown")

    def test_three_formats_same_model(self):
        with open_fixture("legacy_v1.tar.gz") as r:
            v1 = read_archive(r)
        with open_fixture("legacy_v2.tar.gz") as r:
            v2 = read_archive(r)
        v3 = self.read(V3, {"files/assignment_1_bijlage.txt": b"x", "logo/logo.png": b"png"})
        for model in (v1, v2, v3):
            self.assertEqual(type(model.assignments[0]).__name__, "Assignment")
            self.assertEqual(model.assignments[0].title, "De sleutel")
            self.assertEqual(model.assignments[0].artifact.name, "files/assignment_1_bijlage.txt")
        self.assertEqual(v3.game.name, "Operatie")
        self.assertEqual(v3.game.ai_mode, "reply")
        self.assertEqual(v3.game.ai_max_questions, 50)  # begrensd op 1–50
        self.assertEqual(v3.game.logo.name, "logo/logo.png")
        self.assertEqual(v3.assignments[0].ai_rubric, '{"kern": "x"}')

    def test_newer_version_refused(self):
        with self.assertRaisesRegex(UserError, "nieuwere versie"):
            self.read(dict(V3, format_version=4))

    def test_backup_refused(self):
        with self.assertRaisesRegex(UserError, "backup"):
            self.read({"format": "zebrawave-backup", "format_version": 1})

    def test_unknown_refused(self):
        with self.assertRaisesRegex(UserError, "Onbekend formaat"):
            self.read({"iets": "anders"})

    def test_missing_manifest(self):
        path = os.path.join(self.dir.name, "leeg.tar.gz")
        import tarfile  # noqa: PLC0415
        with tarfile.open(path, "w:gz"):
            pass
        with ArchiveReader(path, GAME_ALLOWED, MB, 10) as r:
            with self.assertRaisesRegex(UserError, "manifest.json ontbreekt"):
                read_archive(r)

    def test_progress_promised_but_missing(self):
        with self.assertRaisesRegex(UserError, "progress.json"):
            self.read(dict(V3, include_progress=True), {"files/assignment_1_bijlage.txt": b"x"})


if __name__ == "__main__":
    unittest.main()
