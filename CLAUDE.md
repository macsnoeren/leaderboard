# CLAUDE.md

Leaderboard for a manually graded team game ("Zebrawave"; detective assignments). Everything belongs to a **game** (`games`): teams, assignments, the archive and announcements. Teachers level teams up by hand; teams see their assignment on their own dashboard (no mail per level). Optional Python AI agents (Ollama) post grading suggestions into the teacher chat.

## Stack
- Plain PHP + SQLite (PDO). No framework, Composer, build step, or linter. Each page carries its own inline CSS/JS; `marked.js` comes from a CDN.
- Tests: plain-PHP CLI scripts in `tests/` that exit non-zero on failure. PHP may not be installed on the host, so run them in Docker: `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/auth_test.php` (same for `tests/totp_test.php` and `tests/game_test.php`).
- PHPMailer is a git submodule: `git submodule update --init`.
- Python 3.10+ with only `requests`, for the agent and `bin/` scripts.
- Sanity-check PHP with `php -l <file>`.

## Layout
- `htdocs/`: web root, one PHP file per page. Pages include `../conf/*.php`.
- `conf/`: kept outside the web root on purpose. `config.php` (gitignored, copy from `config.php.example`), `database.php` (`getDB()`), `mail_functions.php` (`createMailer()` + the send functions), `auth.php` (teacher auth helpers, see below), `totp.php` (pure TOTP/base32 functions), `game.php` (games, levels, teams; see below).
- `bin/`: `setup.sh` (must run from inside `bin/`), `migrate.php`, `import_assignments.py`/`export_assignments.py` (`--game <id>`; tar.gz + `manifest.json`; **import deletes that game's assignments first**).
- `agentic-service/process_ai_feedback.py`: the AI agent. It does `from config import ...`, so `config.py` must sit beside it (sample: `bin/config.py.sample`).
- Runtime data (gitignored): `database/leaderboard.db` at repo root, assignment files in `htdocs/artifacts/`, game logos in `htdocs/uploads/`.
- `IDEAS.md`: backlog.

## Architecture
- **Auth**, four separate mechanisms:
  - Teachers: PHP session, managed by `conf/auth.php`. Every teacher page calls `requireTeacher($db)` or `requireAdmin($db)`; don't write your own login or role checks. They re-read the user from the DB on each request: deleted/deactivated/not-yet-activated users and a stale `session_version` are logged out (`login.php?expired=1`), `teacher_role`/`teacher_username` are refreshed, and `force_password_change` (and `REQUIRE_2FA_ADMIN`) blocks every page except `password.php` (`profile.php`), POSTs included. On `?ajax…` requests they return an empty 401/403 instead of redirecting. Bump `session_version` (`bumpSessionVersion()`) to log a user out everywhere.
  - Teacher accounts: admins invite by e-mail (`users.php` → `activate.php`); users can reset via `forgot_password.php` → `reset_password.php` and set up optional TOTP 2FA in `profile.php` (second login step: `login_2fa.php`). One-time links use `user_tokens` (`createUserToken()`/`findUserToken()`/`markTokenUsed()`; only the sha256 hash is stored, a new token of the same type invalidates older ones). Failed logins and reset requests are rate-limited via `login_attempts` (`isThrottled()`/`recordAttempt()`). Login always shows `LOGIN_ERROR`, whatever the cause.
  - Teams: no login. `team.php?token=<teams.access_token>`. Teams can sign themselves up via `join.php?code=<games.join_code>` when the game's registration is open.
  - Agents: `api.php`, `X-API-Token` header or `?token=`, checked against `api_keys`.
  - Downloads: `team_download.php?token=<access_token>` (max 10 per level). `download.php?token=` from `download_tokens` only serves old links from the former level-up mail; no new tokens are made.
- **Teacher page pattern**: `session_start()` + requires (incl. `../conf/auth.php`, `../conf/game.php`) + `requireTeacher($db)`/`requireAdmin($db)` and, on game pages, `$game = requireGame($db, $user)`, then POST handlers (PRG: set `$_SESSION['success'|'error']`, e.g. via `flash()`, then redirect), then set `$pageTitle`/`$extraCSS`, then `include 'admin_header.php'` … `include 'admin_footer.php'`. Forms on user-management pages carry `csrfField()` and handlers check `verifyCsrf()`; do the same on new or changed pages (the older pages like `teacher.php` don't have CSRF yet).
- **Live updates** come from JS polling the same page with `?ajax...` params (e.g. `teacher.php?ajax_unread`, `messages.php?ajax=chat`). There are no websockets and no separate endpoints.
- **Games** (`conf/game.php`): `games.status` is `draft` → `running` ⇄ `paused` → `finished`; use `effective_status` from `getGame()` (or `effectiveStatusSql()`), which is `finished` once `ends_at` has passed. Admins control every game, teachers only the games linked in `game_teachers` (`canAccessGame()`). The active game is `$_SESSION['game_id']`, checked on every request by `requireGame()` (no access or no choice → `choose_game.php`, `?ajax…` → empty 403). **Scope every query to the active game** and check any `team_id`/assignment `id` from POST/GET with `findTeamInGame()` (or `game_id = ?`).
- **Levels**: 0 = not started, 1..N = working on assignment N, N+1 = Finish (N = assignments in the game). `teams.current_level` == `assignments.assignment_number` within the same `game_id`. Ranking is `current_level DESC, level_updated_at ASC` within a game (`rankedTeams()`); progress is `(level − 1) / N` (`progressPercent()`). `startGame()` puts all level-0 teams on 1 with one shared time; `createTeam()` starts a team on `initialLevel()`.
- **Level-up/down**: always via `levelUp()`/`levelDown()` in `conf/game.php`. Level-up archives the chat (with `team_name`) to `completed_assignments` and deletes only that level's AI suggestions; `team_messages` stay, so a level-down brings the conversation back (and removes that level's archive row). No mail is sent.
- **Teams** see a waiting screen in `draft`, a banner in `paused` and an end screen in `finished`; `team.php` refuses team messages unless the game is running/paused. Times for the countdown (`seconds_left`) come from SQL, not the team's clock.
- **Announcements** (`announcements`, `team_id` NULL = all teams of the game) show up as a pop-up on `team.php`; the last closed id is kept in `localStorage`.
- `team_messages.sender` is one of `team`, `teacher`, `suggestion` (AI). Suggestions are teacher-only: filter `sender != 'suggestion'` anywhere teams or archives see messages.
- Every teacher-side mutation inserts an `audit_logs` row (`user_id`, `EVENT_TYPE`, description), via `logAudit()` in new code. Do the same for new actions. Never log passwords, tokens, TOTP secrets or codes.
- Team mail: only `sendTeamLinkEmail()` (dashboard link), sent by hand (📧, "send link" checkbox) or on self sign-up. Team names come from teams themselves, so mails go through `sendAccountEmail()`, which escapes everything.
- Mail functions no-op when `ENABLE_EMAIL` is `false` (use this for local testing). Admin-started invite/reset actions then show the link in the flash message; `forgot_password.php` only writes it to `error_log()`, never to the page. Mailpit runs alongside in Docker (see `docker/README.md`).

## AI agent protocol (`api.php?action=`)
`get_pending` (GET, only teams of running/paused games) → `claim_task` → `send_suggestion`, plus `heartbeat` / `unregister_agent`. Multiple agents can run at once. A claim locks the team via `teams.ai_processing_by/at` with a 5‑minute timeout. The dashboard shows the agent as "active" when a heartbeat arrived in the last 120s. A score ≥ 7 marks the suggestion as level-up advice.

## Schema changes
There is no single schema file. `htdocs/setup.php` creates the base tables, and `bin/migrate.php` adds later columns/tables (idempotent: each `ALTER` is wrapped in try/catch). A fresh install needs **both**. When changing the schema, update the `CREATE TABLE` in `setup.php` **and** add an idempotent step to `migrate.php`.

- Game tables: `games` (`join_code` unique, `registration_open`, `logo_file`, `status_message`, `started_at`, `ends_at`), `game_teachers`, `announcements`; `game_id` on `teams`, `assignments`, `completed_assignments` (plus `team_name`), with unique indexes on `teams(game_id, team_name)` and `assignments(game_id, assignment_number)`. The migration puts existing data in the default game "Operation Gameplay".
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
