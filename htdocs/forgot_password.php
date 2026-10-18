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
<?= authPageStart('Wachtwoord vergeten', ['icon' => 'key']) ?>
        <?php if (isset($_GET['sent'])): ?>
            <?= alertBox('success', 'Als dit e-mailadres bij een actief account hoort, ontvang je binnen enkele minuten een mail met een link om een nieuw wachtwoord in te stellen. De link is 60 minuten geldig.') ?>
        <?php else: ?>
            <p class="auth-sub">Vul het e-mailadres van je account in. Je krijgt dan een link om een nieuw wachtwoord in te stellen.</p>
            <?php if ($error): ?>
                <?= alertBox('error', htmlspecialchars($error)) ?>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="field">
                    <label for="f-email">E-mailadres</label>
                    <input type="email" name="email" id="f-email" autocomplete="email" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block">Link versturen</button>
            </form>
        <?php endif; ?>
<?= authPageEnd('<a href="login.php">← Naar inloggen</a>') ?>
