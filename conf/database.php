<?php
/**
 * Database connection function
 */
require_once __DIR__ . '/worker.php';

function getDB() {
    // Tijdens een restore vervangt de worker de database: onderhoudspagina (of 503) in plaats van een
    // verbinding. Scripts op de commandoregel (bin/migrate.php, ook door de worker zelf) gaan door.
    if (PHP_SAPI !== 'cli' && isMaintenance()) {
        maintenanceResponse();
    }
    try {
        $db = new PDO('sqlite:' . DB_FILE);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}
