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

$pageTitle = 'Wachtwoord Wijzigen';
include 'admin_header.php';
?>
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
<?php include 'admin_footer.php'; ?>
