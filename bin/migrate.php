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

    // 1. Voeg level_updated_at toe
    try {
        $db->exec("ALTER TABLE teams ADD COLUMN level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    } catch (PDOException $e) {}

    // 2. Voeg access_token toe voor de teamomgeving
    try {
        $db->exec("ALTER TABLE teams ADD COLUMN access_token TEXT");
        // Genereer tokens voor bestaande teams
        $db->exec("UPDATE teams SET access_token = lower(hex(randomblob(32))) WHERE access_token IS NULL");
    } catch (PDOException $e) {
        // Kolom bestaat al of update mislukt
    }

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

    echo "Migratie succesvol uitgevoerd.\n";
} catch (PDOException $e) {
    echo "Migratie mislukt: " . $e->getMessage() . "\n";
}