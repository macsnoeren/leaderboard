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

// Autorisatie check: Alleen admin mag hier komen
if (($_SESSION['teacher_role'] ?? 'user') !== 'admin') {
    header('Location: teacher.php');
    exit;
}

// Tel ongelezen berichten voor de sidebar badge
$unread_total = $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();
$message = '';

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_user') {
        $username = trim($_POST['username']);
        $password = $_POST['password']; // Wachtwoorden niet trimmen voor consistentie
        $role = $_POST['role'] === 'admin' ? 'admin' : 'user';
        $force_change = isset($_POST['force_change']) ? 1 : 0;

        if (strlen($username) < 3 || strlen($password) < 4) {
            $message = "Username or password too short.";
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM teachers WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $message = "Username already exists.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO teachers (username, password_hash, role, force_password_change) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, $password_hash, $role, $force_change]);
                $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'USER_ADD', ?)")->execute([$_SESSION['teacher_id'], "Added new teacher: $username"]);
                $message = "User added successfully!";
            }
        }
    }

    // Handle Delete User
    if ($_POST['action'] === 'delete_user' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];

        // Prevent deleting yourself
        if ($id === $_SESSION['teacher_id']) {
            $message = "You cannot delete your own account.";
        } else {
            $stmt = $db->prepare("DELETE FROM teachers WHERE id = ?");
            $stmt->execute([$id]);
            $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'USER_DELETE', ?)")->execute([$_SESSION['teacher_id'], "Deleted teacher ID: $id"]);
            $message = "User deleted successfully.";
        }
    }

    // Handle Reset Password
    if ($_POST['action'] === 'reset_password' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $new_password = $_POST['new_password'] ?? '';
        $force_change = isset($_POST['force_change']) ? 1 : 0;

        if (strlen($new_password) < 4) {
            $message = "New password is too short.";
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE teachers SET password_hash = ?, force_password_change = ? WHERE id = ?");
            $stmt->execute([$hash, $force_change, $id]);
            $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'USER_RESET_PWD', ?)")->execute([$_SESSION['teacher_id'], "Admin reset password for teacher ID: $id (Force change: $force_change)"]);
            $message = "Password for user ID $id updated successfully.";
        }
    }
}

$users = $db->query("SELECT id, username, role, force_password_change FROM teachers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Zebrawave</title>
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
        .card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .card-title { font-size: 1.2em; font-weight: bold; color: #333; margin-bottom: 20px; }
        .message { background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 5px solid #4caf50; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; border-bottom: 2px solid #f0f2f5; color: #888; font-size: 0.85em; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid #f0f2f5; }
        input { padding: 10px; border: 1px solid #ddd; border-radius: 8px; margin-right: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .btn-primary { background: #667eea; color: white; }
        .btn-danger { background: #ffebee; color: #c62828; }
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
            <?php if ($_SESSION['teacher_role'] === 'admin'): ?>
            <a href="users.php" class="nav-item active">👥 Users</a>
            <a href="audit.php" class="nav-item">📋 Audit Logs</a>
            <?php endif; ?>
            <div style="margin-top: 20px; padding: 0 25px; font-size: 0.7em; color: #bbb; text-transform: uppercase;">Settings</div>
            <a href="password.php" class="nav-item">🔑 Password</a>
            <a href="logout.php" class="nav-item" style="margin-top: auto; color: #c62828;">🚪 Logout</a>
        </div>
    </nav>

    <div class="main-content">
        <h1 style="margin-bottom: 30px;">👥 Gebruikersbeheer</h1>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-title">Nieuwe Docent Toevoegen</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <input type="text" name="username" placeholder="Gebruikersnaam" required>
                <input type="password" name="password" placeholder="Wachtwoord" required>
                <select name="role" style="padding: 10px; border: 1px solid #ddd; border-radius: 8px; margin-right: 10px; vertical-align: middle;">
                    <option value="user">Docent (User)</option>
                    <option value="admin">Beheerder (Admin)</option>
                </select>
                <label style="display:inline; font-weight: normal;"><input type="checkbox" name="force_change" value="1"> Verplicht wijzigen</label>
                <button type="submit" class="btn btn-primary">Docent Toevoegen</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title">Bestaande Docenten</div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Gebruikersnaam</th>
                        <th>Rol</th>
                        <th>Status</th>
                        <th>Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                            <td><span class="badge" style="background:<?= $u['role'] === 'admin' ? '#667eea' : '#888' ?>;"><?= $u['role'] ?></span></td>
                            <td>
                                <?php if ($u['force_password_change']): ?>
                                    <span class="badge" style="background:#f44336;">Wachtwoord wijzigen verplicht</span>
                                <?php else: ?>
                                    <span style="color: #4caf50; font-size: 0.85em;">● Actief</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" style="display:inline-flex; gap: 5px; align-items: center;">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <input type="text" name="new_password" placeholder="Nieuw wachtwoord" style="width: 140px; padding: 5px;" required>
                                    <label style="font-size: 0.75em; font-weight: normal;"><input type="checkbox" name="force_change" value="1" checked> Forceer wijziging</label>
                                    <button type="submit" class="btn btn-primary" style="padding: 5px 10px;">Reset</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Verwijder deze docent?')">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 5px 10px;">Verwijderen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
