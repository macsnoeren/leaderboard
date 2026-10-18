"""Intern model van een spel-archief: v1, v2 en v3 worden hiernaar vertaald,
zodat de import maar één pad kent."""
from dataclasses import dataclass, field
from typing import Optional

AI_MODES = ("off", "suggest", "reply", "auto")
RUBRIC_STATUSES = ("none", "draft", "approved")


@dataclass
class FileRef:
    name: str  # naam in het archief, bijv. files/opdracht1.pdf
    size: Optional[int] = None
    sha256: Optional[str] = None


@dataclass
class Assignment:
    assignment_number: int
    title: str
    description: str = ""
    instruction: str = ""
    criteria: str = ""
    time_limit: int = 0
    artifact: Optional[FileRef] = None
    ai_rubric: Optional[str] = None  # JSON-tekst zoals in de database
    ai_rubric_status: str = "none"
    ai_auto_allowed: int = 1


@dataclass
class GameInfo:
    name: Optional[str] = None
    ai_mode: str = "suggest"
    ai_max_attempts: int = 5
    ai_max_questions: int = 5
    logo: Optional[FileRef] = None


@dataclass
class ImportModel:
    format: str  # v1 | v2 | v3
    game: GameInfo
    assignments: list
    progress: Optional[dict] = None  # inhoud van progress.json (v3 met voortgang)
    warnings: list = field(default_factory=list)
