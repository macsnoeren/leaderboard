"""Oude archieven (v1, v2) lezen met de echte fixtures."""
import io
import json
import os
import tarfile
import tempfile
import unittest

from tests.helpers import FIXTURES
from worker.archive import ArchiveReader
from worker.formats import GAME_ALLOWED, read_archive
from worker.jobs import UserError

MB = 1024 * 1024


def open_fixture(name):
    return ArchiveReader(os.path.join(FIXTURES, name), GAME_ALLOWED, 10 * MB, 100)


def make_archive(path, manifest, files=None):
    with tarfile.open(path, "w:gz") as tar:
        for name, data in (files or {}).items():
            info = tarfile.TarInfo(name)
            info.size = len(data)
            tar.addfile(info, io.BytesIO(data))
        data = json.dumps(manifest).encode()
        info = tarfile.TarInfo("manifest.json")
        info.size = len(data)
        tar.addfile(info, io.BytesIO(data))


class LegacyTest(unittest.TestCase):
    def test_v1(self):
        with open_fixture("legacy_v1.tar.gz") as r:
            model = read_archive(r)
        self.assertEqual(model.format, "v1")
        self.assertEqual([a.assignment_number for a in model.assignments], [1, 2])
        first, second = model.assignments
        self.assertEqual(first.title, "De sleutel")
        self.assertEqual(first.criteria, "De conciërge")
        self.assertEqual(first.time_limit, 30)
        self.assertEqual(first.artifact.name, "files/assignment_1_bijlage.txt")
        self.assertEqual((first.ai_rubric, first.ai_rubric_status, first.ai_auto_allowed), (None, "none", 1))
        self.assertIsNone(second.artifact)
        self.assertIsNone(model.game.name)
        self.assertEqual(model.warnings, [])

    def test_v2(self):
        with open_fixture("legacy_v2.tar.gz") as r:
            model = read_archive(r)
        self.assertEqual(model.format, "v2")
        first, second = model.assignments
        self.assertEqual(json.loads(first.ai_rubric)["kern"], "De conciërge had de sleutel")
        self.assertEqual(first.ai_rubric_status, "approved")
        self.assertEqual(second.ai_rubric_status, "none")
        self.assertEqual(second.ai_auto_allowed, 0)

    def test_missing_file_gives_one_warning(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "a.tar.gz")
            make_archive(path, [{"assignment_number": 1, "title": "T", "artifact_file": "weg.pdf"}])
            with ArchiveReader(path, GAME_ALLOWED, MB, 10) as r:
                model = read_archive(r)
        self.assertEqual(len(model.warnings), 1)
        self.assertIn("weg.pdf", model.warnings[0])
        self.assertIsNone(model.assignments[0].artifact)

    def test_rubric_status_rules(self):
        cases = [
            ({"ai_rubric": {"kern": "x"}, "ai_rubric_status": "approved"}, "approved"),
            ({"ai_rubric": {"kern": "x"}, "ai_rubric_status": "onzin"}, "draft"),
            ({"ai_rubric": None, "ai_rubric_status": "approved"}, "none"),
            ({"ai_rubric": "", "ai_rubric_status": "draft"}, "none"),
        ]
        with tempfile.TemporaryDirectory() as d:
            for i, (fields, expected) in enumerate(cases):
                path = os.path.join(d, f"{i}.tar.gz")
                make_archive(path, [dict({"assignment_number": 1, "title": "T"}, **fields)])
                with ArchiveReader(path, GAME_ALLOWED, MB, 10) as r:
                    self.assertEqual(read_archive(r).assignments[0].ai_rubric_status, expected, fields)

    def test_assignment_without_title(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "a.tar.gz")
            make_archive(path, [{"assignment_number": 1}])
            with ArchiveReader(path, GAME_ALLOWED, MB, 10) as r:
                with self.assertRaises(UserError):
                    read_archive(r)


if __name__ == "__main__":
    unittest.main()
