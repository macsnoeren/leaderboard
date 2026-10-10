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
require_once '../conf/auth.php';

$db = getDB();
$user = requireTeacher($db);
$forced = (int)$user['force_password_change'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_password = $_POST['old_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';

    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
    } elseif (!password_verify($old_password, $user['password_hash'])) {
        flash('error', 'Je huidige wachtwoord klopt niet.');
    } elseif ($passwordError = validatePassword($new_password, $_POST['confirm_password'] ?? '')) {
        flash('error', $passwordError);
    } elseif (password_verify($new_password, $user['password_hash'])) {
        flash('error', 'Kies een ander wachtwoord dan je huidige.');
    } else {
        $stmt = $db->prepare("UPDATE teachers SET password_hash = ?, force_password_change = 0 WHERE id = ?");
        $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $user['id']]);

        // Andere sessies uitloggen; deze sessie krijgt de nieuwe versie en blijft ingelogd
        $_SESSION['session_version'] = bumpSessionVersion($db, (int)$user['id']);
        logAudit($db, (int)$user['id'], 'PWD_CHANGE', 'User changed their own password');

        if ($forced) {
            flash('success', 'Je wachtwoord is gewijzigd.');
            redirect('teacher.php');
        }
        flash('success', 'Je wachtwoord is gewijzigd. Andere sessies zijn uitgelogd.');
        redirect('profile.php');
    }
    redirect('password.php');
}

$pageTitle = 'Wachtwoord Wijzigen';
include 'admin_header.php';
?>
    <?php if (!$forced): ?>
        <p style="margin-bottom: 15px;"><a href="profile.php" style="color: #667eea; text-decoration: none;">← Terug naar profiel</a></p>
    <?php endif; ?>
    <h1 style="margin-bottom: 30px;">🔑 Wachtwoord Wijzigen</h1>

        <?php if ($forced): ?>
            <div class="error-msg">
                <strong>Wachtwoord wijzigen verplicht:</strong>
                Een beheerder heeft ingesteld dat je je wachtwoord moet wijzigen voordat je verder kunt gaan.
            </div>
        <?php endif; ?>

        <?= renderFlash() ?>

        <div class="card" style="max-width: 500px;">
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Huidig Wachtwoord:</label>
                    <input type="password" name="old_password" autocomplete="current-password" required>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Nieuw Wachtwoord (minimaal <?= PASSWORD_MIN_LENGTH ?> tekens):</label>
                    <input type="password" name="new_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Bevestig Nieuw Wachtwoord:</label>
                    <input type="password" name="confirm_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary">Wachtwoord Bijwerken</button>
            </form>
        </div>
<?php include 'admin_footer.php'; ?>
