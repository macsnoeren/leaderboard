<?php
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
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// Tel ongelezen berichten voor de sidebar badge
$unread_total = $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } else {
        // Haal teacher uit database
        $stmt = $db->prepare("SELECT * FROM teachers WHERE id = ?");
        $stmt->execute([$_SESSION['teacher_id']]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($teacher && password_verify($old_password, $teacher['password_hash'])) {
            // Hash nieuw wachtwoord
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("UPDATE teachers SET password_hash = ?, force_password_change = 0 WHERE id = ?");
            $stmt->execute([$new_hash, $_SESSION['teacher_id']]);

            unset($_SESSION['force_password_change']);
            $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'PWD_CHANGE', 'User changed their own password')")->execute([$_SESSION['teacher_id']]);
            $success = "Password updated successfully!";
        } else {
            $error = "Old password is incorrect.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Zebrawave</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; position: fixed; height: 100vh; }
        .sidebar-header { padding: 30px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-align: center; font-weight: bold; font-size: 1.2em; }
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .nav-item { display: flex; align-items: center; padding: 12px 25px; color: #4b4f56; text-decoration: none; transition: 0.2s; font-weight: 500; }
        .nav-item:hover { background: #f0f2f5; color: #667eea; }
        .nav-item.active { background: #f0f4ff; color: #667eea; border-left: 4px solid #667eea; }
        .badge { background: #f44336; color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75em; margin-left: auto; }
        .main-content { margin-left: 260px; flex: 1; padding: 40px; }
        .card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); max-width: 500px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; }
        .btn { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #5568d3; }
        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .error { background: #ffebee; color: #c62828; }
        .success { background: #e8f5e9; color: #2e7d32; }
    </style>
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">Zebrawave Admin</div>
        <div class="sidebar-nav">
            <a href="teacher.php" class="nav-item">📊 Dashboard</a>
            <a href="assignments.php" class="nav-item">📘 Assignments</a>
            <a href="messages.php" class="nav-item">
                ✉️ Messages
                <span id="unread-badge-container">
                    <?php if ($unread_total > 0): ?>
                        <span class="badge"><?= $unread_total ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <a href="users.php" class="nav-item">👥 Users</a>
            <a href="audit.php" class="nav-item">📋 Audit Logs</a>
            <div style="margin-top: 20px; padding: 0 25px; font-size: 0.7em; color: #bbb; text-transform: uppercase;">Settings</div>
            <a href="password.php" class="nav-item active">🔑 Password</a>
            <a href="logout.php" class="nav-item" style="margin-top: auto; color: #c62828;">🚪 Logout</a>
        </div>
    </nav>

    <div class="main-content">
        <h1 style="margin-bottom: 30px;">🔑 Wachtwoord Wijzigen</h1>

        <?php if (isset($_SESSION['force_password_change'])): ?>
            <div class="message error">
                <strong>Wachtwoord wijzigen verplicht:</strong> 
                Een beheerder heeft ingesteld dat je je wachtwoord moet wijzigen voordat je verder kunt gaan.
            </div>
        <?php endif; ?>

        <div class="card">
            <?php if ($error): ?>
                <div class="message error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="message success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Huidig Wachtwoord:</label>
                    <input type="password" name="old_password" required>
                </div>
                <div class="form-group">
                    <label>Nieuw Wachtwoord:</label>
                    <input type="password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label>Bevestig Nieuw Wachtwoord:</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" class="btn">Wachtwoord Bijwerken</button>
            </form>
        </div>
    </div>

    <script>
        // Live update voor de berichten badge
        function updateUnreadBadge() {
            fetch('teacher.php?ajax_unread=1')
                .then(r => r.text())
                .then(count => {
                    const container = document.getElementById('unread-badge-container');
                    if (parseInt(count) > 0) {
                        container.innerHTML = `<span class="badge">${count}</span>`;
                    } else {
                        container.innerHTML = '';
                    }
                });
        }
        setInterval(updateUnreadBadge, 10000); // Check elke 10 seconden
    </script>
</body>
</html>
