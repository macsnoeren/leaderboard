"""Archieven (tar.gz) veilig lezen en schrijven (DESIGN §11).

Een archief is onbetrouwbare invoer: alleen gewone bestanden met een naam uit de allowlist, geen
links, geen '..' of absolute paden, limieten op grootte en aantal, en nooit extractall. Schrijven gaat
naar een tijdelijk bestand dat pas aan het eind wordt hernoemd.
"""
import hashlib
import io
import os
import re
import tarfile
import time

from worker.jobs import UserError

CHUNK = 1024 * 1024


class ArchiveError(UserError):
    pass


def _bad_name(name):
    if not name or name.startswith("/") or "\\" in name or "\x00" in name:
        return True
    parts = name.split("/")
    return any(p in ("", ".", "..") for p in parts)


class ArchiveReader:
    """Leest een tar.gz. allowed is een lijst regexen (volledige match) voor de toegestane namen;
    veilige maar onbekende namen worden genegeerd (self.ignored)."""

    def __init__(self, path, allowed, max_bytes, max_files):
        self.path = path
        self.allowed = [re.compile(p) for p in allowed]
        self.members = {}
        self.ignored = []
        try:
            self.tar = tarfile.open(path, "r:gz")
            members = self.tar.getmembers()
        except (tarfile.TarError, EOFError, OSError, ValueError) as e:
            raise ArchiveError("Dit is geen geldig .tar.gz-archief") from e
        try:
            self._check(members, max_bytes, max_files)
        except ArchiveError:
            self.tar.close()
            raise

    def _check(self, members, max_bytes, max_files):
        if len(members) > max_files:
            raise ArchiveError(f"Het archief bevat te veel bestanden (meer dan {max_files})")
        total = 0
        for m in members:
            name = m.name[2:] if m.name.startswith("./") else m.name
            name = name.rstrip("/") if m.isdir() else name
            if _bad_name(name):
                raise ArchiveError(f"Ongeldige naam in het archief: {m.name!r}")
            if m.isdir():
                continue
            if not m.isfile():
                raise ArchiveError(f"Het archief bevat een link of speciaal bestand: {m.name!r}")
            total += m.size
            if total > max_bytes:
                raise ArchiveError(f"Het archief is uitgepakt te groot (meer dan {max_bytes // (1024 * 1024)} MB)")
            if not any(p.fullmatch(name) for p in self.allowed):
                self.ignored.append(name)
                continue
            if name in self.members:
                raise ArchiveError(f"Het archief bevat {name} twee keer")
            self.members[name] = m

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()

    def close(self):
        self.tar.close()

    def names(self):
        return list(self.members)

    def has(self, name):
        return name in self.members

    def size(self, name):
        return self.members[name].size

    def _open(self, name):
        if name not in self.members:
            raise ArchiveError(f"{name} ontbreekt in het archief")
        f = self.tar.extractfile(self.members[name])
        if f is None:
            raise ArchiveError(f"{name} kan niet worden gelezen")
        return f

    def read_bytes(self, name, max_bytes=64 * 1024 * 1024):
        if self.members.get(name) is not None and self.members[name].size > max_bytes:
            raise ArchiveError(f"{name} is te groot")
        with self._open(name) as f:
            return f.read()

    def sha256(self, name):
        digest = hashlib.sha256()
        with self._open(name) as f:
            for chunk in iter(lambda: f.read(CHUNK), b""):
                digest.update(chunk)
        return digest.hexdigest()

    def extract_to(self, name, target, sha256=None):
        """Schrijft één bestand naar target (via target.part); controleert sha256 als die is opgegeven.
        Geeft de sha256 terug."""
        digest = hashlib.sha256()
        part = target + ".part"
        try:
            with self._open(name) as src, open(part, "wb") as dst:
                for chunk in iter(lambda: src.read(CHUNK), b""):
                    digest.update(chunk)
                    dst.write(chunk)
            if sha256 is not None and digest.hexdigest() != sha256:
                raise ArchiveError(f"Controlegetal van {name} klopt niet (beschadigd archief)")
            os.replace(part, target)
        finally:
            if os.path.exists(part):
                os.remove(part)
        return digest.hexdigest()


class _HashingReader:
    def __init__(self, f):
        self.f = f
        self.digest = hashlib.sha256()

    def read(self, size=-1):
        data = self.f.read(size)
        self.digest.update(data)
        return data


class ArchiveWriter:
    """Schrijft een tar.gz naar final_path via final_path.part. Houdt size/sha256 per bestand bij (files)."""

    def __init__(self, final_path):
        self.final_path = final_path
        self.part = final_path + ".part"
        self.tar = tarfile.open(self.part, "w:gz")
        self.files = {}
        self.closed = False

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        if exc_type is None:
            self.close()
        else:
            self.abort()

    def _info(self, name, size, mtime=None):
        info = tarfile.TarInfo(name)
        info.size = size
        info.mtime = int(mtime if mtime is not None else time.time())
        info.mode = 0o644
        return info

    def add_bytes(self, name, data, record=True):
        self.tar.addfile(self._info(name, len(data)), io.BytesIO(data))
        if record:
            self.files[name] = {"size": len(data), "sha256": hashlib.sha256(data).hexdigest()}

    def add_file(self, name, src_path):
        st = os.stat(src_path)
        with open(src_path, "rb") as f:
            reader = _HashingReader(f)
            self.tar.addfile(self._info(name, st.st_size, st.st_mtime), reader)
        self.files[name] = {"size": st.st_size, "sha256": reader.digest.hexdigest()}

    def close(self):
        if self.closed:
            return
        self.tar.close()
        self.closed = True
        os.replace(self.part, self.final_path)

    def abort(self):
        if not self.closed:
            try:
                self.tar.close()
            except Exception:  # noqa: BLE001
                pass
            self.closed = True
        if os.path.exists(self.part):
            os.remove(self.part)


def sha256_file(path):
    digest = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(CHUNK), b""):
            digest.update(chunk)
    return digest.hexdigest()
