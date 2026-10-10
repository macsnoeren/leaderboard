# CLAUDE.md

Leaderboard for a manually graded team game ("Zebrawave"; detective assignments). Teachers level teams up by hand; each level-up emails the team a download link for the next assignment. Optional Python AI agents (Ollama) post grading suggestions into the teacher chat.

## Stack
- Plain PHP + SQLite (PDO). No framework, Composer, build step, or linter. Each page carries its own inline CSS/JS; `marked.js` comes from a CDN.
- Tests: plain-PHP CLI scripts in `tests/` that exit non-zero on failure. PHP may not be installed on the host, so run them in Docker: `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/auth_test.php` (same for `tests/totp_test.php`).
- PHPMailer is a git submodule: `git submodule update --init`.
- Python 3.10+ with only `requests`, for the agent and `bin/` scripts.
- Sanity-check PHP with `php -l <file>`.

## Layout
- `htdocs/`: web root, one PHP file per page. Pages include `../conf/*.php`.
- `conf/`: kept outside the web root on purpose. `config.php` (gitignored, copy from `config.php.example`), `database.php` (`getDB()`), `mail_functions.php` (`createMailer()` + the send functions), `auth.php` (teacher auth helpers, see below), `totp.php` (pure TOTP/base32 functions).
- `bin/`: `setup.sh` (must run from inside `bin/`), `migrate.php`, `import_assignments.py`/`export_assignments.py` (tar.gz + `manifest.json`; **import deletes all assignments first**).
- `agentic-service/process_ai_feedback.py`: the AI agent. It does `from config import ...`, so `config.py` must sit beside it (sample: `bin/config.py.sample`).
- Runtime data (gitignored): `database/leaderboard.db` at repo root, assignment files in `htdocs/artifacts/`.
- `IDEAS.md`: backlog.

## Architecture
- **Auth**, four separate mechanisms:
  - Teachers: PHP session, managed by `conf/auth.php`. Every teacher page calls `requireTeacher($db)` or `requireAdmin($db)`; don't write your own login or role checks. They re-read the user from the DB on each request: deleted/deactivated/not-yet-activated users and a stale `session_version` are logged out (`login.php?expired=1`), `teacher_role`/`teacher_username` are refreshed, and `force_password_change` (and `REQUIRE_2FA_ADMIN`) blocks every page except `password.php` (`profile.php`), POSTs included. On `?ajax…` requests they return an empty 401/403 instead of redirecting. Bump `session_version` (`bumpSessionVersion()`) to log a user out everywhere.
  - Teacher accounts: admins invite by e-mail (`users.php` → `activate.php`); users can reset via `forgot_password.php` → `reset_password.php` and set up optional TOTP 2FA in `profile.php` (second login step: `login_2fa.php`). One-time links use `user_tokens` (`createUserToken()`/`findUserToken()`/`markTokenUsed()`; only the sha256 hash is stored, a new token of the same type invalidates older ones). Failed logins and reset requests are rate-limited via `login_attempts` (`isThrottled()`/`recordAttempt()`). Login always shows `LOGIN_ERROR`, whatever the cause.
  - Teams: no login. `team.php?token=<teams.access_token>`.
  - Agents: `api.php`, `X-API-Token` header or `?token=`, checked against `api_keys`.
  - Downloads: `download.php?token=` from `download_tokens` (24h, created by the level-up mail).
- **Teacher page pattern**: `session_start()` + requires (incl. `../conf/auth.php`) + `requireTeacher($db)`/`requireAdmin($db)`, then POST handlers (PRG: set `$_SESSION['success'|'error']`, e.g. via `flash()`, then redirect), then set `$pageTitle`/`$extraCSS`, then `include 'admin_header.php'` … `include 'admin_footer.php'`. Forms on user-management pages carry `csrfField()` and handlers check `verifyCsrf()`; do the same on new or changed pages (the older pages like `teacher.php` don't have CSRF yet).
- **Live updates** come from JS polling the same page with `?ajax...` params (e.g. `teacher.php?ajax_unread`, `messages.php?ajax=chat`). There are no websockets and no separate endpoints.
- **Levels**: `teams.current_level` == `assignments.assignment_number`. Ranking is `current_level DESC, level_updated_at ASC`.
- **Level-up** archives the chat to `completed_assignments`, deletes that level's `team_messages`, bumps the level, and sends the mail. This logic is **duplicated in `teacher.php` and `messages.php`**, so keep both in sync.
- `team_messages.sender` is one of `team`, `teacher`, `suggestion` (AI). Suggestions are teacher-only: filter `sender != 'suggestion'` anywhere teams or archives see messages.
- Every teacher-side mutation inserts an `audit_logs` row (`user_id`, `EVENT_TYPE`, description), via `logAudit()` in new code. Do the same for new actions. Never log passwords, tokens, TOTP secrets or codes.
- Mail functions no-op when `ENABLE_EMAIL` is `false` (use this for local testing). Admin-started invite/reset actions then show the link in the flash message; `forgot_password.php` only writes it to `error_log()`, never to the page. Mailpit runs alongside in Docker (see `docker/README.md`).

## AI agent protocol (`api.php?action=`)
`get_pending` (GET) → `claim_task` → `send_suggestion`, plus `heartbeat` / `unregister_agent`. Multiple agents can run at once. A claim locks the team via `teams.ai_processing_by/at` with a 5‑minute timeout. The dashboard shows the agent as "active" when a heartbeat arrived in the last 120s. A score ≥ 7 marks the suggestion as level-up advice.

## Schema changes
There is no single schema file. `htdocs/setup.php` creates the base tables, and `bin/migrate.php` adds later columns/tables (idempotent: each `ALTER` is wrapped in try/catch). A fresh install needs **both**. When changing the schema, update the `CREATE TABLE` in `setup.php` **and** add an idempotent step to `migrate.php`.

- User management tables: `teachers` (plus `email` with unique index `idx_teachers_email`, `display_name`, `created_at`, `last_login_at`, `session_version`, `totp_secret`/`totp_enabled`/`totp_last_step`; `username` and `password_hash` are `NULL` while an invite is pending), `user_tokens` (`invite` | `reset` | `email_change`), `user_recovery_codes`, `login_attempts`.
- SQLite refuses `ALTER TABLE … ADD COLUMN … DEFAULT CURRENT_TIMESTAMP` on a table with rows: add the column without a default, backfill with an `UPDATE`, and set the value explicitly on insert.
- `CURRENT_TIMESTAMP`/`datetime('now')` are UTC, while PHP's `date()` is local time. Do time arithmetic and comparisons in SQL (`expires_at > datetime('now')`), not in PHP; `formatDateTime()` converts for display.

## Running locally
Easiest: `docker compose -f docker/docker-compose.yml up -d --build`, then open http://localhost:8088/setup.php. One container runs the web app and the agent against the host's Ollama; see `docker/README.md`. Manual setup:
```sh
git submodule update --init
cp conf/config.php.example conf/config.php   # then fill in SMTP and BASE_URL
(cd bin && ./setup.sh)                        # creates database/ + htdocs/artifacts/
php -S localhost:8000 -t htdocs               # open /setup.php → first admin account
php bin/migrate.php
```

## Gotchas
- `BASE_URL` in `config.php` must be the public URL of `htdocs/` (no trailing slash): it's used for the links in download, invite, reset and e-mail confirmation mails.
- PHPMailer rejects a `FROM_EMAIL` without a TLD (e.g. `x@localhost`), even for Mailpit; use something like `leaderboard@example.com`.
- Older `config.php` files (from before the example was fixed) may still have the license comment above `<?php` and a `DB_FILE` that resolves to `conf/database/…`; the path should be `__DIR__ . '/../database/leaderboard.db'` to match `setup.sh` and the Python scripts (which hardcode that path).
- The root `.gitignore` (Python template) ignores `lib/`. The existing submodule still works, but adding a new one there needs `--force`.
- Never commit `conf/config.php` or any `config.py`: they hold SMTP credentials and API keys.

## Conventions
- Code comments and most UI strings are Dutch. Match the surrounding file.
- Always use prepared statements, and escape output with `htmlspecialchars`.
- Stay framework-free: no Composer packages, bundlers, or shared CSS files unless asked.
