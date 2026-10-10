<?php
/**
 * Database migratie script
 * Voegt de level_updated_at kolom toe voor eerlijke sortering op het leaderboard.
 */
require_once __DIR__ . '/../conf/config.php';
require_once __DIR__ . '/../conf/database.php';

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

    echo "Migratie succesvol uitgevoerd.\n";
} catch (PDOException $e) {
    echo "Migratie mislukt: " . $e->getMessage() . "\n";
}