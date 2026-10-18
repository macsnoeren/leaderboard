"""Spel-archieven lezen: herkent v1, v2 en v3 en vertaalt ze naar ImportModel (DESIGN §6.3)."""
import json

from worker.formats import game_v3, legacy
from worker.jobs import UserError

# Namen die een spel-archief mag bevatten (alles plat, zie worker.archive)
GAME_ALLOWED = [r"manifest\.json", r"progress\.json", r"files/[^/]+", r"logo/[^/]+"]


def load_manifest(reader):
    if not reader.has("manifest.json"):
        raise UserError("Ongeldig archief: manifest.json ontbreekt")
    try:
        return json.loads(reader.read_bytes("manifest.json").decode("utf-8"))
    except (ValueError, UnicodeDecodeError) as e:
        raise UserError("Ongeldig archief: manifest.json is geen geldige JSON") from e


def detect_format(manifest):
    """'v1', 'v2', 'v3', 'backup', 'newer' of 'unknown'."""
    if isinstance(manifest, list):
        return legacy.detect(manifest)
    if isinstance(manifest, dict):
        fmt = manifest.get("format")
        if fmt == "zebrawave-backup":
            return "backup"
        if fmt == game_v3.FORMAT:
            try:
                version = int(manifest.get("format_version"))
            except (TypeError, ValueError):
                return "unknown"
            if version > game_v3.VERSION:
                return "newer"
            if version == game_v3.VERSION:
                return "v3"
    return "unknown"


def read_archive(reader):
    manifest = load_manifest(reader)
    fmt = detect_format(manifest)
    if fmt in ("v1", "v2"):
        return legacy.read_legacy(manifest, reader)
    if fmt == "v3":
        return game_v3.read_v3(manifest, reader)
    if fmt == "newer":
        raise UserError("Dit archief is gemaakt met een nieuwere versie van Zebrawave; werk eerst deze installatie bij")
    if fmt == "backup":
        raise UserError("Dit is een volledige backup, geen spel-export; zet hem terug via Backups")
    raise UserError("Onbekend formaat: dit archief is geen spel-export van Zebrawave")
