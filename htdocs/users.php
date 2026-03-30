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

    // Handle Enable User
    if ($_POST['action'] === 'enable_user' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        
        // Voorkom dat je jezelf deactiveert/activeert (voor de veiligheid)
        if ($id === $_SESSION['teacher_id']) {
            $message = "Je kunt je eigen account status niet wijzigen.";
        } else {
            $stmt = $db->prepare("UPDATE teachers SET is_active = 1 WHERE id = ?");
            $stmt->execute([$id]);
            $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'USER_ENABLE', ?)")->execute([$_SESSION['teacher_id'], "Admin heeft docent ID: $id geactiveerd."]);
            $message = "Docent is geactiveerd.";
        }
    }

    // Handle Disable User
    if ($_POST['action'] === 'disable_user' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];

        if ($id === $_SESSION['teacher_id']) {
            $message = "Je kunt je eigen account niet deactiveren.";
        } else {
            // Controleer of er ten minste één actieve admin overblijft
            $stmt = $db->prepare("SELECT role FROM teachers WHERE id = ?");
            $stmt->execute([$id]);
            $user_role = $stmt->fetchColumn();
            
            if ($user_role === 'admin' && $db->query("SELECT COUNT(*) FROM teachers WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1) {
                $message = "Fout: Je kunt de laatste actieve beheerder niet deactiveren.";
            } else {
                $stmt = $db->prepare("UPDATE teachers SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'USER_DISABLE', ?)")->execute([$_SESSION['teacher_id'], "Admin heeft docent ID: $id gedeactiveerd."]);
                $message = "Docent is gedeactiveerd.";
            }
        }
    }
}

$users = $db->query("SELECT id, username, role, force_password_change, is_active FROM teachers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Gebruikersbeheer';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">👥 Gebruikersbeheer</h1>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <div class="main-panel">
                <div class="card">
                    <div class="card-title">🛡️ Bestaande Docenten</div>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Gebruikersnaam</th>
                                <th>Rol</th>
                                <th>Status</th>
                                <th style="text-align: right;">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?= $u['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                    <td><span class="badge" style="background:<?= $u['role'] === 'admin' ? '#667eea' : '#888' ?>;"><?= $u['role'] ?></span></td>
                                    <td>
                                        <?php if ($u['is_active']): ?>
                                            <?php if ($u['force_password_change']): ?>
                                                <span class="badge" style="background:#fbc02d;">⚠️ Wijzigen verplicht</span>
                                            <?php else: ?>
                                                <span style="color: #4caf50; font-size: 0.85em;">● Actief</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge" style="background:#f44336;">● Gedeactiveerd</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <form method="POST" style="display:inline-flex; gap: 5px; align-items: center; justify-content: flex-end;">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <input type="text" name="new_password" placeholder="Nieuw wachtwoord" style="width: 130px; padding: 6px;" minlength="6" required>
                                            <button type="submit" class="btn btn-primary" style="padding: 6px 12px;">Reset</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <?php if ($u['is_active']): ?>
                                                <button type="submit" class="btn btn-outline" style="padding: 6px 12px;" name="action" value="disable_user" title="Deactiveren">🚫</button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-outline" style="padding: 6px 12px;" name="action" value="enable_user" title="Activeren">✅</button>
                                            <?php endif; ?>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanent verwijderen?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="padding: 6px 12px;">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="side-panel">
                <div class="card" style="border-top: 5px solid #667eea;">
                    <div class="card-title" style="color: #667eea; font-size: 1.4em;">➕ Nieuwe Docent</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_user">
                        <div style="margin-bottom: 15px;">
                            <label style="display:block; margin-bottom:5px; font-size:0.9em;">Gebruikersnaam</label>
                            <input type="text" name="username" placeholder="Bijv. m.janssen" required>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display:block; margin-bottom:5px; font-size:0.9em;">Wachtwoord</label>
                            <input type="password" name="password" placeholder="Wachtwoord" required>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display:block; margin-bottom:5px; font-size:0.9em;">Rol</label>
                            <select name="role">
                                <option value="user">Docent (User)</option>
                                <option value="admin">Beheerder (Admin)</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="font-weight: normal; font-size: 0.9em; display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" name="force_change" value="1" style="width: auto;"> 
                                Wachtwoord wijzigen verplichten
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px;">🚀 Toevoegen</button>
                    </form>
                </div>
            </div>
        </div>
<?php include 'admin_footer.php'; ?>
