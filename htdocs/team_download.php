<?php
require_once '../conf/config.php';
require_once '../conf/database.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("Ongeldige token.");
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM teams WHERE access_token = ?");
$stmt->execute([$token]);
$team = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$team) {
    die("Team niet gevonden.");
}

$level = $team['current_level'];
$max_downloads = 10;

// Check download count
$stmt = $db->prepare("SELECT download_count FROM team_downloads WHERE team_id = ? AND assignment_number = ?");
$stmt->execute([$team['id'], $level]);
$count = $stmt->fetchColumn() ?: 0;

if ($count >= $max_downloads) {
    die("Maximale aantal downloads (10) voor dit level bereikt.");
}

// Haal assignment op
$stmt = $db->prepare("SELECT * FROM assignments WHERE assignment_number = ?");
$stmt->execute([$level]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    die("Geen bestanden gevonden voor dit level.");
}

// Update count (safe increment)
$stmt = $db->prepare("SELECT id FROM team_downloads WHERE team_id = ? AND assignment_number = ?");
$stmt->execute([$team['id'], $level]);
$rowId = $stmt->fetchColumn();

if ($rowId) {
    $db->prepare("UPDATE team_downloads SET download_count = download_count + 1 WHERE id = ?")->execute([$rowId]);
} else {
    $db->prepare("INSERT INTO team_downloads (team_id, assignment_number, download_count) VALUES (?, ?, 1)")->execute([$team['id'], $level]);
}

// Serve file
$artifact_path = __DIR__ . '/' . $assignment['artifact_file'];
if (!empty($assignment['artifact_file']) && file_exists($artifact_path)) {
    $filename = basename($artifact_path);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($artifact_path));
    readfile($artifact_path);
    exit;
} else {
    $filename = "assignment_level_{$level}.txt";
    $content = "Assignment Level {$level}\nTitle: {$assignment['title']}\nDescription: {$assignment['description']}\n";
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content; exit;
}