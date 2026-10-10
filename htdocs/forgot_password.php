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
// Publieke pagina: reset-link aanvragen. De melding is altijd hetzelfde, of het adres nu bestaat of niet.
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/mail_functions.php';
require_once '../conf/auth.php';

$db = getDB();

/**
 * Maakt een reset-token en verstuurt de mail, alleen voor een actief account met dit adres
 * en zolang de limieten (3 per adres en 10 per IP per uur) niet bereikt zijn.
 */
function handleResetRequest(PDO $db, string $input): void {
    $email = normalizeEmail($input);
    $key = $email ?? strtolower(substr($input, 0, 200));

    $throttled = isThrottled($db, 'forgot', $key, 3, 60) || isIpThrottled($db, 'forgot', 10, 60);
    recordAttempt($db, 'forgot', $key);
    if ($throttled || $email === null) {
        return;
    }

    $stmt = $db->prepare("SELECT * FROM teachers WHERE email = ? AND is_active = 1 AND password_hash IS NOT NULL AND password_hash != ''");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return;
    }

    $token = createUserToken($db, (int)$user['id'], 'reset', RESET_TTL_MINUTES, null);
    $link = BASE_URL . '/reset_password.php?token=' . $token;
    logAudit($db, (int)$user['id'], 'PWD_RESET_REQUEST', 'Wachtwoordherstel aangevraagd via "Wachtwoord vergeten"');

    if (!isEmailEnabled()) {
        // Testmodus: de link alleen in de serverlog, nooit op de pagina (anders kan iedereen elk account resetten)
        error_log("[leaderboard] Testmodus: reset-link voor $email: $link");
        return;
    }
    $result = sendPasswordResetEmail($email, $link);
    if ($result !== true) {
        error_log("[leaderboard] Reset-mail naar $email kon niet worden verstuurd: $result");
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } else {
        $input = trim((string)($_POST['email'] ?? ''));

        // Eerst antwoorden en pas daarna zoeken en mailen, zodat de responstijd niet verraadt of het adres bestaat
        session_write_close();
        ignore_user_abort(true);
        header('Location: forgot_password.php?sent=1');
        header('Content-Length: 0');
        header('Connection: close');
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }

        handleResetRequest($db, $input);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wachtwoord vergeten</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .login-box { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 420px; }
        h1 { text-align: center; margin-bottom: 20px; color: #333; }
        p.intro { color: #555; margin-bottom: 20px; line-height: 1.5; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
        input[type="email"] { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 1em; }
        button { width: 100%; background: #667eea; color: white; padding: 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 1.1em; font-weight: 600; }
        button:hover { background: #5568d3; }
        .error { background: #f44336; color: white; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .notice { background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 5px; margin-bottom: 20px; line-height: 1.5; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #667eea; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Wachtwoord vergeten</h1>
        <?php if (isset($_GET['sent'])): ?>
            <div class="notice">Als dit e-mailadres bij een actief account hoort, ontvang je binnen enkele minuten een mail met een link om een nieuw wachtwoord in te stellen. De link is 60 minuten geldig.</div>
        <?php else: ?>
            <p class="intro">Vul het e-mailadres van je account in. Je krijgt dan een link om een nieuw wachtwoord in te stellen.</p>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group">
                    <label>E-mailadres:</label>
                    <input type="email" name="email" autocomplete="email" required autofocus>
                </div>
                <button type="submit">Link versturen</button>
            </form>
        <?php endif; ?>
        <div class="back-link">
            <a href="login.php">← Naar inloggen</a>
        </div>
    </div>
</body>
</html>
