/*
 Copyright (C) 2025 Maurice Snoeren

 This program is free software: you can redistribute it and/or modify it under the terms of
 the GNU General Public License as published by the Free Software Foundation, version 3.

 This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 See the GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along with this program.
 If not, see https://www.gnu.org/licenses/.
*/
<?php
require_once 'config.php';

if (!isset($_GET['token'])) {
    die('Invalid download link');
}

$token = $_GET['token'];

$db = getDB();

// Look up token
$stmt = $db->prepare("
    SELECT dt.*, t.email, t.team_name
    FROM download_tokens dt
    JOIN teams t ON t.id = dt.team_id
    WHERE dt.token = ?
");
$stmt->execute([$token]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entry) {
    die('Invalid or expired token');
}

// Check expiry
if (strtotime($entry['expires_at']) < time()) {
    die('This link has expired');
}

// Verify the team really reached that level
$stmt = $db->prepare("SELECT * FROM teams WHERE id = ? AND current_level >= ?");
$stmt->execute([$entry['team_id'], $entry['level']]);
$team = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$team) {
    die('Access denied');
}

// Proceed with normal artifact download
$stmt = $db->prepare("SELECT * FROM assignments WHERE assignment_number = ?");
$stmt->execute([$entry['level']]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    die('Assignment not found');
}

$level = $entry["level"];		     

// Check if actual file exists
$artifact_path = __DIR__ . '/' . $assignment['artifact_file'];
if (file_exists($artifact_path)) {
    // Download the actual file
    $filename = basename($artifact_path);
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($artifact_path));
    
    readfile($artifact_path);
    exit;
} else {
    // Create a simple text file with assignment info
    $filename = "assignment_level_{$level}.txt";
    $content = "Assignment Level {$level}\n";
    $content .= "Title: {$assignment['title']}\n";
    $content .= "Description: {$assignment['description']}\n";
    $content .= "\nCongratulations on completing this level!\n";
    $content .= "\nNote: Place actual artifact files in the 'artifacts' folder.\n";
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    
    echo $content;
    exit;
}

?>
