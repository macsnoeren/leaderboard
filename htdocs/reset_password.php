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
// Publieke pagina: nieuw wachtwoord kiezen via een reset-link (wachtwoord vergeten of door de beheerder gestuurd)
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';

$db = getDB();

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$user = findUserToken($db, $token, 'reset');
if ($user && empty($user['password_hash'])) {
    $user = null; // nog niet geactiveerd: dat gaat via de uitnodiging
}

$error = '';
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } elseif ($passwordError = validatePassword($_POST['password'] ?? '', $_POST['confirm_password'] ?? '')) {
        $error = $passwordError;
    } else {
        $id = (int)$user['id'];
        $db->beginTransaction();
        if (!markTokenUsed($db, (int)$user['token_id'])) {
            $db->rollBack();
            $user = null;
        } else {
            // 2FA blijft aan; andere reset-links en open sessies vervallen
            $db->prepare("UPDATE teachers SET password_hash = ?, force_password_change = 0 WHERE id = ?")
               ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
            invalidateUserTokens($db, $id, 'reset');
            bumpSessionVersion($db, $id);
            clearAttempts($db, 'login', 'user:' . $id);
            logAudit($db, $id, 'PWD_RESET', 'Wachtwoord opnieuw ingesteld via een reset-link');
            $db->commit();
            header('Location: login.php?reset=1');
            exit;
        }
    }
}
?>
<?= authPageStart('Nieuw wachtwoord', ['no_referrer' => true, 'icon' => 'key']) ?>
        <?php if (!$user): ?>
            <?= alertBox('error', 'Deze link is ongeldig of verlopen. Vraag een nieuwe link aan via "Wachtwoord vergeten?".') ?>
            <a href="forgot_password.php" class="btn btn-primary btn-block">Nieuwe link aanvragen</a>
        <?php else: ?>
            <p class="auth-sub">Kies een nieuw wachtwoord voor <strong><?= htmlspecialchars($user['username']) ?></strong>.</p>
            <?php if ($error): ?>
                <?= alertBox('error', htmlspecialchars($error)) ?>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="field">
                    <label for="r-pw">Nieuw wachtwoord</label>
                    <input type="password" name="password" id="r-pw" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required autofocus>
                    <div class="hint">Minimaal <?= PASSWORD_MIN_LENGTH ?> tekens.</div>
                </div>
                <div class="field">
                    <label for="r-pw2">Bevestig nieuw wachtwoord</label>
                    <input type="password" name="confirm_password" id="r-pw2" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block">Wachtwoord opslaan</button>
            </form>
        <?php endif; ?>
<?= authPageEnd('<a href="login.php">← Naar inloggen</a>') ?>
