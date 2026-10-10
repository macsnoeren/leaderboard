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
// Publieke pagina: bevestigt een nieuw e-mailadres (link uit de mail naar dat adres, zie profile.php).
// Bevestigen gaat met een knop (POST), zodat een mailscanner die de link opent niets wijzigt.
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/mail_functions.php';
require_once '../conf/auth.php';

$db = getDB();

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$user = findUserToken($db, $token, 'email_change');

$error = '';
$done = false;
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newEmail = $user['new_email'];
    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } elseif (!$newEmail || emailTaken($db, $newEmail, (int)$user['id'])) {
        $error = 'Dit e-mailadres is inmiddels in gebruik bij een ander account. Kies in je profiel een ander adres.';
    } else {
        $db->beginTransaction();
        if (!markTokenUsed($db, (int)$user['token_id'])) {
            $db->rollBack();
            $user = null;
        } else {
            $db->prepare("UPDATE teachers SET email = ? WHERE id = ?")->execute([$newEmail, $user['id']]);
            logAudit($db, (int)$user['id'], 'PROFILE_EMAIL_CHANGE', 'E-mailadres gewijzigd van ' . ($user['email'] ?? '(leeg)') . " naar $newEmail");
            $db->commit();

            // Melding naar het oude adres, zodat een ongewenste wijziging opvalt
            if ($user['email']) {
                $result = sendEmailChangedNotice($user['email'], $newEmail);
                if ($result !== true) {
                    error_log("[leaderboard] Melding e-mailwijziging naar {$user['email']} kon niet worden verstuurd: $result");
                }
            }
            $done = true;
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
    <title>E-mailadres bevestigen</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .login-box { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 420px; }
        h1 { text-align: center; margin-bottom: 20px; color: #333; }
        p.intro { color: #555; margin-bottom: 20px; line-height: 1.5; }
        button { width: 100%; background: #667eea; color: white; padding: 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 1.1em; font-weight: 600; }
        button:hover { background: #5568d3; }
        .error { background: #f44336; color: white; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .notice { background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #667eea; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>E-mailadres bevestigen</h1>
        <?php if ($done): ?>
            <div class="notice">Je e-mailadres is gewijzigd naar <strong><?= htmlspecialchars($user['new_email']) ?></strong>.</div>
        <?php elseif (!$user): ?>
            <div class="error">Deze link is ongeldig of verlopen. Vraag de wijziging opnieuw aan in je profiel.</div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <p class="intro">Wil je <strong><?= htmlspecialchars($user['new_email']) ?></strong> gebruiken als e-mailadres voor het account <strong><?= htmlspecialchars($user['username']) ?></strong>?</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <button type="submit">Bevestigen</button>
            </form>
        <?php endif; ?>
        <div class="back-link">
            <a href="profile.php">← Naar je profiel</a>
        </div>
    </div>
</body>
</html>
