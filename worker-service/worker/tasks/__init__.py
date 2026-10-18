"""Handlers van de taken; importeren registreert ze (worker.jobs.register)."""
from worker.tasks import backup, export_game, import_game, report, restore  # noqa: F401
