"""Archieven veilig lezen en schrijven."""
import hashlib
import io
import os
import tarfile
import tempfile
import unittest

from worker.archive import ArchiveError, ArchiveReader, ArchiveWriter

ALLOWED = [r"manifest\.json", r"files/[^/]+"]
MB = 1024 * 1024


def make_tar(path, entries):
    """entries: lijst van (naam, bytes) of (naam, TarInfo-aanpassing) voor links."""
    with tarfile.open(path, "w:gz") as tar:
        for name, data, kind in entries:
            info = tarfile.TarInfo(name)
            if kind == "sym":
                info.type = tarfile.SYMTYPE
                info.linkname = data
                tar.addfile(info)
            elif kind == "hard":
                info.type = tarfile.LNKTYPE
                info.linkname = data
                tar.addfile(info)
            elif kind == "dir":
                info.type = tarfile.DIRTYPE
                tar.addfile(info)
            else:
                info.size = len(data)
                tar.addfile(info, io.BytesIO(data))


class ArchiveTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.path = os.path.join(self.dir.name, "a.tar.gz")

    def reader(self, entries, max_bytes=10 * MB, max_files=100):
        make_tar(self.path, entries)
        return ArchiveReader(self.path, ALLOWED, max_bytes, max_files)

    def test_valid_archive(self):
        with self.reader([("manifest.json", b"[]", "file"), ("files/", None, "dir"), ("files/a.txt", b"abc", "file"),
                          ("extra/onbekend.txt", b"x", "file")]) as r:
            self.assertEqual(sorted(r.names()), ["files/a.txt", "manifest.json"])
            self.assertEqual(r.read_bytes("manifest.json"), b"[]")
            self.assertEqual(r.ignored, ["extra/onbekend.txt"])
            target = os.path.join(self.dir.name, "uit.txt")
            digest = r.extract_to("files/a.txt", target, sha256=hashlib.sha256(b"abc").hexdigest())
            with open(target, "rb") as f:
                self.assertEqual(f.read(), b"abc")
            self.assertEqual(digest, hashlib.sha256(b"abc").hexdigest())

    def test_rejects_dotdot(self):
        with self.assertRaises(ArchiveError):
            self.reader([("../x", b"x", "file")])
        with self.assertRaises(ArchiveError):
            self.reader([("files/../../x", b"x", "file")])

    def test_rejects_absolute(self):
        with self.assertRaises(ArchiveError):
            self.reader([("/etc/passwd", b"x", "file")])

    def test_rejects_symlink(self):
        with self.assertRaises(ArchiveError):
            self.reader([("files/a.txt", "/etc/passwd", "sym")])

    def test_rejects_hardlink(self):
        with self.assertRaises(ArchiveError):
            self.reader([("manifest.json", b"[]", "file"), ("files/a.txt", "manifest.json", "hard")])

    def test_rejects_too_big_even_if_compressed_small(self):
        zeros = b"\0" * (3 * MB)  # comprimeert tot een paar KB
        make_tar(self.path, [("files/nul.bin", zeros, "file")])
        self.assertLess(os.path.getsize(self.path), 100 * 1024)
        with self.assertRaises(ArchiveError):
            ArchiveReader(self.path, ALLOWED, 2 * MB, 100)

    def test_rejects_too_many_files(self):
        with self.assertRaises(ArchiveError):
            self.reader([(f"files/{i}.txt", b"x", "file") for i in range(11)], max_files=10)

    def test_rejects_wrong_checksum(self):
        with self.reader([("files/a.txt", b"abc", "file")]) as r:
            target = os.path.join(self.dir.name, "uit.txt")
            with self.assertRaises(ArchiveError):
                r.extract_to("files/a.txt", target, sha256="0" * 64)
            self.assertFalse(os.path.exists(target))
            self.assertFalse(os.path.exists(target + ".part"))

    def test_not_a_tar(self):
        with open(self.path, "wb") as f:
            f.write(b"dit is geen archief")
        with self.assertRaises(ArchiveError):
            ArchiveReader(self.path, ALLOWED, MB, 10)

    def test_duplicate_names(self):
        with self.assertRaises(ArchiveError):
            self.reader([("files/a.txt", b"1", "file"), ("files/a.txt", b"2", "file")])

    def test_writer_records_checksums(self):
        src = os.path.join(self.dir.name, "bron.txt")
        with open(src, "wb") as f:
            f.write(b"bijlage")
        with ArchiveWriter(self.path) as w:
            w.add_file("files/bron.txt", src)
            w.add_bytes("manifest.json", b"{}", record=False)
        self.assertEqual(w.files, {"files/bron.txt": {"size": 7, "sha256": hashlib.sha256(b"bijlage").hexdigest()}})
        with ArchiveReader(self.path, ALLOWED, MB, 10) as r:
            self.assertEqual(r.read_bytes("files/bron.txt"), b"bijlage")

    def test_writer_abort_leaves_nothing(self):
        with self.assertRaises(RuntimeError):
            with ArchiveWriter(self.path) as w:
                w.add_bytes("manifest.json", b"{}")
                raise RuntimeError("halverwege")
        self.assertEqual(os.listdir(self.dir.name), [])


if __name__ == "__main__":
    unittest.main()
