<?php
/**
 * Download van het resultaat van een taak (export, rapport, backup). Alleen wie de taak mag zien
 * (jobDownloadAllowed()); elke download komt in de audit-log.
 */
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);

$job = findJobForUser($db, (int)($_GET['id'] ?? 0), $user);
if (!$job || !jobDownloadAllowed($db, $job, $user)) {
    // Geen onderscheid tussen "bestaat niet" en "geen toegang"
    http_response_code(403);
    exit('Geen toegang tot dit bestand.');
}
$path = jobResultPath($job);
if ($path === null) {
    flash('error', 'Dit bestand is er niet meer: exports en rapporten worden na ' . resultTtlDays() . ' dagen opgeruimd. Maak het opnieuw.');
    redirect('jobs.php');
}

$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($job['result_name'] ?: basename($path)));
$type = substr($name, -4) === '.pdf' ? 'application/pdf' : 'application/gzip';
logAudit($db, (int)$user['id'], 'JOB_DOWNLOAD', jobTypeLabel($job['type']) . " gedownload (taak #{$job['id']}, $name)");

sendFile($path, 'attachment; filename="' . $name . '"', $type);
