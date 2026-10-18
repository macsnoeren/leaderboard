<?php
/**
 * Database migratie script
 * Voegt de level_updated_at kolom toe voor eerlijke sortering op het leaderboard.
 */
require_once __DIR__ . '/../conf/config.php';
require_once __DIR__ . '/../conf/database.php';
require_once __DIR__ . '/../conf/game.php';

try {
    $db = getDB();
    
    // 0. Voeg role toe aan teachers
    try {
        $db->exec("ALTER TABLE teachers ADD COLUMN role TEXT DEFAULT 'user'");
    } catch (PDOException $e) {}

    // 0.1 Voeg force_password_change toe aan teachers
    try {
        $db->exec("ALTER TABLE teachers ADD COLUMN force_password_change INTEGER DEFAULT 0");
    } catch (PDOException $e) {}

    // 0.2 Voeg is_active toe aan teachers
    try {
        $db->exec("ALTER TABLE teachers ADD COLUMN is_active INTEGER DEFAULT 1");
    } catch (PDOException $e) {}

    // 0.3 Voeg nieuwe velden toe aan assignments
    try {
        $db->exec("ALTER TABLE assignments ADD COLUMN instruction TEXT");
        $db->exec("ALTER TABLE assignments ADD COLUMN criteria TEXT");
        $db->exec("ALTER TABLE assignments ADD COLUMN time_limit INTEGER DEFAULT 0");
    } catch (PDOException $e) {}

    // 1. Voeg level_updated_at toe. SQLite weigert een niet-constante default
    //    (CURRENT_TIMESTAMP) zodra de tabel rijen heeft, dus zonder default
    //    toevoegen en bestaande teams daarna vullen.
    try {
        $db->exec("ALTER TABLE teams ADD COLUMN level_updated_at DATETIME");
    } catch (PDOException $e) {}
    try {
        $db->exec("UPDATE teams SET level_updated_at = CURRENT_TIMESTAMP WHERE level_updated_at IS NULL");
    } catch (PDOException $e) {}

    // 2. Voeg access_token toe voor de teamomgeving
    try {
        $db->exec("ALTER TABLE teams ADD COLUMN access_token TEXT");
        // Genereer tokens voor bestaande teams
        $db->exec("UPDATE teams SET access_token = lower(hex(randomblob(32))) WHERE access_token IS NULL");
    } catch (PDOException $e) {
        // Kolom bestaat al of update mislukt
    }

    // 2.1 Voeg AI locking velden toe
    try {
        $db->exec("ALTER TABLE teams ADD COLUMN ai_processing_by TEXT");
        $db->exec("ALTER TABLE teams ADD COLUMN ai_processing_at DATETIME");
    } catch (PDOException $e) {}

    // 3. Maak tabel voor het bijhouden van download limieten
    $db->exec("CREATE TABLE IF NOT EXISTS team_downloads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER,
        assignment_number INTEGER,
        download_count INTEGER DEFAULT 0,
        UNIQUE(team_id, assignment_number)
    )");
    
    // 4. Voeg is_read toe aan team_messages
    try {
        $db->exec("ALTER TABLE team_messages ADD COLUMN is_read INTEGER DEFAULT 0");
    } catch (PDOException $e) {}

    // 5. Maak tabel voor API keys
    $db->exec("CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, key_name TEXT, api_key TEXT UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // 6. Maak tabel voor AI service status
    $db->exec("CREATE TABLE IF NOT EXISTS ai_service_status (id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id TEXT UNIQUE, last_heartbeat DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try {
        $db->exec("ALTER TABLE ai_service_status ADD COLUMN agent_id TEXT");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_agent_id ON ai_service_status(agent_id)");
    } catch (PDOException $e) {}

    // 7. Maak tabel voor gearchiveerde chats van voltooide opdrachten
    $db->exec("CREATE TABLE IF NOT EXISTS completed_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, chat_history TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // 8. Voeg aanwezigheid voor docenten toe
    try {
        $db->exec("ALTER TABLE teams ADD COLUMN editing_by TEXT");
        $db->exec("ALTER TABLE teams ADD COLUMN editing_at DATETIME");
    } catch (PDOException $e) {}

    // 9. Tabel voor aanwezigheid van meerdere docenten
    $db->exec("CREATE TABLE IF NOT EXISTS team_presence (team_id INTEGER, username TEXT, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (team_id, username))");

    // 10. Usermanagement: e-mail, profiel, sessieversie en 2FA voor docenten.
    //     Elke kolom apart, zodat een bestaande kolom de rest niet tegenhoudt.
    $teacherColumns = [
        'email TEXT',
        'display_name TEXT',
        'created_at DATETIME', // zonder default: SQLite weigert CURRENT_TIMESTAMP bij een gevulde tabel
        'last_login_at DATETIME',
        'session_version INTEGER DEFAULT 0',
        'totp_secret TEXT',
        'totp_enabled INTEGER DEFAULT 0',
        'totp_last_step INTEGER',
    ];
    foreach ($teacherColumns as $column) {
        try {
            $db->exec("ALTER TABLE teachers ADD COLUMN $column");
        } catch (PDOException $e) {}
    }
    $db->exec("UPDATE teachers SET created_at = datetime('now') WHERE created_at IS NULL");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_teachers_email ON teachers(email)");

    // 11. Tokens voor uitnodigingen, wachtwoordherstel en e-mailbevestiging
    $db->exec("CREATE TABLE IF NOT EXISTS user_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, new_email TEXT, expires_at DATETIME NOT NULL, used_at DATETIME, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    try {
        $db->exec("ALTER TABLE user_tokens ADD COLUMN new_email TEXT");
    } catch (PDOException $e) {}

    // 12. Herstelcodes voor 2FA en mislukte inlogpogingen
    $db->exec("CREATE TABLE IF NOT EXISTS user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL, used_at DATETIME)");
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, identifier TEXT, ip TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // 13. Spellen en de docenten die een spel besturen
    $db->exec("CREATE TABLE IF NOT EXISTS games (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', join_code TEXT UNIQUE, registration_open INTEGER DEFAULT 0, logo_file TEXT, status_message TEXT, started_at DATETIME, ends_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by INTEGER)");
    $db->exec("CREATE TABLE IF NOT EXISTS game_teachers (game_id INTEGER NOT NULL, teacher_id INTEGER NOT NULL, PRIMARY KEY (game_id, teacher_id))");

    // 14. Teams, opdrachten en archief horen bij een spel; het archief bewaart de teamnaam
    foreach (['teams ADD COLUMN game_id INTEGER', 'assignments ADD COLUMN game_id INTEGER',
              'completed_assignments ADD COLUMN game_id INTEGER', 'completed_assignments ADD COLUMN team_name TEXT'] as $column) {
        try {
            $db->exec("ALTER TABLE $column");
        } catch (PDOException $e) {}
    }

    // 15. Standaardspel voor de bestaande gegevens: alle teams, opdrachten, archiefregels en docenten
    if ((int)$db->query("SELECT COUNT(*) FROM games")->fetchColumn() === 0) {
        $started = $db->query("SELECT MIN(level_updated_at) FROM teams WHERE current_level > 0")->fetchColumn();
        $stmt = $db->prepare("INSERT INTO games (name, status, join_code, registration_open, logo_file, started_at, created_at)
                              VALUES ('Operation Gameplay', ?, ?, 0, 'images/logo-operation-gameplay.png', ?, datetime('now'))");
        $stmt->execute([$started ? 'running' : 'draft', generateJoinCode($db), $started ?: null]);
        $defaultGame = (int)$db->lastInsertId();
        if ($started) {
            // In een lopend spel begint elk team op level 1
            $db->exec("UPDATE teams SET current_level = 1, level_updated_at = datetime('now') WHERE current_level = 0");
        }
        $db->prepare("INSERT OR IGNORE INTO game_teachers (game_id, teacher_id) SELECT ?, id FROM teachers")->execute([$defaultGame]);
        echo "Standaardspel 'Operation Gameplay' (#$defaultGame) aangemaakt.\n";
    }
    // Rijen zonder spel (bijv. van een oud script) horen bij het oudste spel
    $defaultGame = (int)$db->query("SELECT MIN(id) FROM games")->fetchColumn();
    foreach (['teams', 'assignments', 'completed_assignments'] as $table) {
        $db->prepare("UPDATE $table SET game_id = ? WHERE game_id IS NULL")->execute([$defaultGame]);
    }
    $db->exec("UPDATE completed_assignments SET team_name = (SELECT team_name FROM teams WHERE teams.id = completed_assignments.team_id) WHERE team_name IS NULL");

    // 16. Teamnaam en opdrachtnummer uniek per spel. Dubbele bestaande rijen blokkeren dit:
    //     meld het, zodat ze met de hand opgelost kunnen worden.
    foreach (['idx_teams_game_name ON teams(game_id, team_name)', 'idx_assignments_game_number ON assignments(game_id, assignment_number)'] as $index) {
        try {
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS $index");
        } catch (PDOException $e) {
            echo "Waarschuwing: unieke index $index niet aangemaakt (dubbele waarden?): " . $e->getMessage() . "\n";
        }
    }

    // 17. Spelcode voor spellen die er nog geen hebben
    foreach ($db->query("SELECT id FROM games WHERE join_code IS NULL OR join_code = ''")->fetchAll(PDO::FETCH_COLUMN) as $gameId) {
        $db->prepare("UPDATE games SET join_code = ? WHERE id = ?")->execute([generateJoinCode($db), $gameId]);
    }

    // 18. Centrale mededelingen (team_id NULL = alle teams van het spel)
    $db->exec("CREATE TABLE IF NOT EXISTS announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER, message TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // 19. Agentic AI. Elke kolom apart: een bestaande kolom houdt de rest
    //     niet tegen. Constante defaults vullen ook de bestaande rijen (suggest, none, 1).
    $aiColumns = [
        "teams ADD COLUMN ai_mode TEXT DEFAULT 'suggest'",
        "games ADD COLUMN ai_mode TEXT DEFAULT 'suggest'",
        'games ADD COLUMN ai_max_attempts INTEGER DEFAULT 5',
        'games ADD COLUMN ai_max_questions INTEGER DEFAULT 5',
        'assignments ADD COLUMN ai_rubric TEXT',
        "assignments ADD COLUMN ai_rubric_status TEXT DEFAULT 'none'",
        "assignments ADD COLUMN ai_design_status TEXT DEFAULT 'idle'",
        'assignments ADD COLUMN ai_design_round INTEGER DEFAULT 0',
        'assignments ADD COLUMN ai_auto_allowed INTEGER DEFAULT 1',
        'assignments ADD COLUMN ai_processing_by TEXT',
        'assignments ADD COLUMN ai_processing_at DATETIME',
        'team_messages ADD COLUMN ai_evaluation_id INTEGER',
        'ai_service_status ADD COLUMN model_ok INTEGER DEFAULT 1',
    ];
    foreach ($aiColumns as $column) {
        try {
            $db->exec("ALTER TABLE $column");
        } catch (PDOException $e) {}
    }
    $db->exec("CREATE TABLE IF NOT EXISTS ai_team_progress (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, assignment_number INTEGER NOT NULL, aspects_json TEXT, summary TEXT, last_message_id INTEGER, updated_at DATETIME, UNIQUE (team_id, assignment_number))");
    $db->exec("CREATE TABLE IF NOT EXISTS assignment_ai_dialog (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL, role TEXT NOT NULL, message TEXT NOT NULL, teacher_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS ai_evaluations (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, team_name TEXT, assignment_number INTEGER NOT NULL, message_id INTEGER NOT NULL, intent TEXT, intent_json TEXT, helper_json TEXT, assessor_json TEXT, validator_json TEXT, verdict TEXT, decision TEXT, reason TEXT, reply_text TEXT, escalation_reason TEXT, resolved_by INTEGER, resolved_at DATETIME, teacher_verdict TEXT, agent_id TEXT, models TEXT, duration_ms INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ai_evaluations_team ON ai_evaluations(team_id, assignment_number)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_assignment_ai_dialog ON assignment_ai_dialog(assignment_id)");

    // 20. Worker-service: wachtrij, heartbeat en een archief dat weet wie
    //     goedkeurde en welk antwoord. Bestaande archiefregels houden NULL (het rapport leidt die af).
    foreach (['completed_assignments ADD COLUMN leveled_by INTEGER', 'completed_assignments ADD COLUMN leveled_by_name TEXT',
              'completed_assignments ADD COLUMN ai_evaluation_id INTEGER', 'completed_assignments ADD COLUMN accepted_message_id INTEGER',
              'completed_assignments ADD COLUMN accepted_answer TEXT', 'completed_assignments ADD COLUMN level_started_at DATETIME'] as $column) {
        try {
            $db->exec("ALTER TABLE $column");
        } catch (PDOException $e) {}
    }
    $db->exec("CREATE TABLE IF NOT EXISTS worker_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, game_id INTEGER, params_json TEXT, status TEXT NOT NULL DEFAULT 'queued', progress INTEGER DEFAULT 0, message TEXT, result_file TEXT, result_name TEXT, result_size INTEGER, result_game_id INTEGER, created_by INTEGER, created_at DATETIME, started_at DATETIME, heartbeat_at DATETIME, finished_at DATETIME, expires_at DATETIME, worker_id TEXT)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_worker_jobs_status ON worker_jobs(status, id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_worker_jobs_game ON worker_jobs(game_id, id)");
    $db->exec("CREATE TABLE IF NOT EXISTS worker_status (worker_id TEXT PRIMARY KEY, version TEXT, last_heartbeat DATETIME, current_job_id INTEGER)");

    // 21. Opdrachten zijn 1..N zonder gaten (levels rekenen daarmee; de volgorde wijzig je door te slepen).
    //     Oude spellen met gaten worden doorlopend genummerd, behalve als er al teamdata aan de nummers hangt.
    foreach ($db->query("SELECT DISTINCT game_id FROM assignments WHERE game_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $gameId) {
        $stmt = $db->prepare("SELECT id FROM assignments WHERE game_id = ? ORDER BY assignment_number ASC, id ASC");
        $stmt->execute([$gameId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $db->prepare("SELECT MAX(assignment_number) FROM assignments WHERE game_id = ?");
        $stmt->execute([$gameId]);
        if ((int)$stmt->fetchColumn() === count($ids)) {
            continue;
        }
        if (assignmentLockLevel($db, (int)$gameId) > 0) {
            echo "Let op: spel #$gameId heeft gaten in de opdrachtnummers, maar er hangt al teamdata aan; niet hernummerd.\n";
            continue;
        }
        reorderAssignments($db, (int)$gameId, $ids, null);
    }

    echo "Migratie succesvol uitgevoerd.\n";
} catch (PDOException $e) {
    echo "Migratie mislukt: " . $e->getMessage() . "\n";
    // Exitcode voor scripts, o.a. de worker na een restore
    exit(1);
}