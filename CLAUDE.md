# CLAUDE.md

Leaderboard for a manually graded team game ("Zebrawave"; detective assignments). Teachers level teams up by hand; each level-up emails the team a download link for the next assignment. Optional Python AI agents (Ollama) post grading suggestions into the teacher chat.

## Stack
- Plain PHP + SQLite (PDO). No framework, Composer, build step, tests, or linter. Each page carries its own inline CSS/JS; `marked.js` comes from a CDN.
- PHPMailer is a git submodule: `git submodule update --init`.
- Python 3.10+ with only `requests`, for the agent and `bin/` scripts.
- Sanity-check PHP with `php -l <file>`.

## Layout
- `htdocs/`: web root, one PHP file per page. Pages include `../conf/*.php`.
- `conf/`: kept outside the web root on purpose. `config.php` (gitignored, copy from `config.php.example`), `database.php` (`getDB()`), `mail_functions.php`.
- `bin/`: `setup.sh` (must run from inside `bin/`), `migrate.php`, `import_assignments.py`/`export_assignments.py` (tar.gz + `manifest.json`; **import deletes all assignments first**).
- `agentic-service/process_ai_feedback.py`: the AI agent. It does `from config import ...`, so `config.py` must sit beside it (sample: `bin/config.py.sample`).
- Runtime data (gitignored): `database/leaderboard.db` at repo root, assignment files in `htdocs/artifacts/`.
- `IDEAS.md`: backlog.

## Architecture
- **Auth**, four separate mechanisms:
  - Teachers: PHP session (`teacher_logged_in`, `teacher_id`, `teacher_role` = `admin`|`user`). Admin-only pages check the role themselves.
  - Teams: no login. `team.php?token=<teams.access_token>`.
  - Agents: `api.php`, `X-API-Token` header or `?token=`, checked against `api_keys`.
  - Downloads: `download.php?token=` from `download_tokens` (24h, created by the level-up mail).
- **Teacher page pattern**: `session_start()` + requires + login check, then POST handlers (PRG: set `$_SESSION['success'|'error']`, then redirect), then set `$pageTitle`/`$extraCSS`, then `include 'admin_header.php'` … `include 'admin_footer.php'`.
- **Live updates** come from JS polling the same page with `?ajax...` params (e.g. `teacher.php?ajax_unread`, `messages.php?ajax=chat`). There are no websockets and no separate endpoints.
- **Levels**: `teams.current_level` == `assignments.assignment_number`. Ranking is `current_level DESC, level_updated_at ASC`.
- **Level-up** archives the chat to `completed_assignments`, deletes that level's `team_messages`, bumps the level, and sends the mail. This logic is **duplicated in `teacher.php` and `messages.php`**, so keep both in sync.
- `team_messages.sender` is one of `team`, `teacher`, `suggestion` (AI). Suggestions are teacher-only: filter `sender != 'suggestion'` anywhere teams or archives see messages.
- Every teacher-side mutation inserts an `audit_logs` row (`user_id`, `EVENT_TYPE`, description). Do the same for new actions.
- Mail functions no-op when `ENABLE_EMAIL` is `false` (use this for local testing).

## AI agent protocol (`api.php?action=`)
`get_pending` (GET) → `claim_task` → `send_suggestion`, plus `heartbeat` / `unregister_agent`. Multiple agents can run at once. A claim locks the team via `teams.ai_processing_by/at` with a 5‑minute timeout. The dashboard shows the agent as "active" when a heartbeat arrived in the last 120s. A score ≥ 7 marks the suggestion as level-up advice.

## Schema changes
There is no single schema file. `htdocs/setup.php` creates the base tables, and `bin/migrate.php` adds later columns/tables (idempotent: each `ALTER` is wrapped in try/catch). A fresh install needs **both**. When changing the schema, update the `CREATE TABLE` in `setup.php` **and** add an idempotent step to `migrate.php`.

## Running locally
Easiest: `docker compose -f docker/docker-compose.yml up -d --build`, then open http://localhost:8088/setup.php. One container runs the web app and the agent against the host's Ollama; see `docker/README.md`. Manual setup:
```sh
git submodule update --init
cp conf/config.php.example conf/config.php   # then fix it, see Gotchas
(cd bin && ./setup.sh)                        # creates database/ + htdocs/artifacts/
php -S localhost:8000 -t htdocs               # open /setup.php → first admin account
php bin/migrate.php
```

## Gotchas
- `config.php.example` has its license comment *above* `<?php`, so the comment gets echoed; move it below. Its default `DB_FILE` resolves to `conf/database/…`; set it to `__DIR__ . '/../database/leaderboard.db'` to match `setup.sh` and the Python scripts (which hardcode that path).
- The root `.gitignore` (Python template) ignores `lib/`. The existing submodule still works, but adding a new one there needs `--force`.
- Never commit `conf/config.php` or any `config.py`: they hold SMTP credentials and API keys.

## Conventions
- Code comments and most UI strings are Dutch. Match the surrounding file.
- Always use prepared statements, and escape output with `htmlspecialchars`.
- Stay framework-free: no Composer packages, bundlers, or shared CSS files unless asked.
