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
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Het token staat in de URL: niet doorgeven aan andere sites -->
    <meta name="referrer" content="no-referrer">
    <title>Nieuw wachtwoord</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .login-box { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 420px; }
        h1 { text-align: center; margin-bottom: 20px; color: #333; }
        p.intro { color: #555; margin-bottom: 20px; line-height: 1.5; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
        input[type="password"] { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 1em; }
        button { width: 100%; background: #667eea; color: white; padding: 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 1.1em; font-weight: 600; }
        button:hover { background: #5568d3; }
        .error { background: #f44336; color: white; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #667eea; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Nieuw wachtwoord</h1>
        <?php if (!$user): ?>
            <div class="error">Deze link is ongeldig of verlopen. Vraag een nieuwe link aan via "Wachtwoord vergeten?".</div>
            <div class="back-link"><a href="forgot_password.php">Nieuwe link aanvragen</a></div>
        <?php else: ?>
            <p class="intro">Kies een nieuw wachtwoord voor <strong><?= htmlspecialchars($user['username']) ?></strong>.</p>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label>Nieuw wachtwoord (minimaal <?= PASSWORD_MIN_LENGTH ?> tekens):</label>
                    <input type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required autofocus>
                </div>
                <div class="form-group">
                    <label>Bevestig nieuw wachtwoord:</label>
                    <input type="password" name="confirm_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                </div>
                <button type="submit">Wachtwoord opslaan</button>
            </form>
        <?php endif; ?>
        <div class="back-link">
            <a href="login.php">← Naar inloggen</a>
        </div>
    </div>
</body>
</html>
