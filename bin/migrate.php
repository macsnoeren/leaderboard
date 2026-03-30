<?php
/**
 * Database migratie script
 * Voegt de level_updated_at kolom toe voor eerlijke sortering op het leaderboard.
 */
require_once __DIR__ . '/../conf/config.php';

try {
    $db = getDB();
    
    // 1. Voeg level_updated_at toe
    try {
        $db->exec("ALTER TABLE teams ADD COLUMN level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    } catch (PDOException $e) {}

    // 2. Voeg access_token toe voor de teamomgeving
    try {
        $db->exec("ALTER TABLE teams ADD COLUMN access_token VARCHAR(64) UNIQUE");
        // Genereer tokens voor bestaande teams
        $db->exec("UPDATE teams SET access_token = lower(hex(randomblob(32))) WHERE access_token IS NULL");
    } catch (PDOException $e) {}

    // 3. Maak tabel voor het bijhouden van download limieten
    $db->exec("CREATE TABLE IF NOT EXISTS team_downloads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER,
        assignment_number INTEGER,
        download_count INTEGER DEFAULT 0,
        UNIQUE(team_id, assignment_number)
    )");
    
    echo "Migratie succesvol uitgevoerd.\n";
} catch (PDOException $e) {
    echo "Migratie mislukt: " . $e->getMessage() . "\n";
}