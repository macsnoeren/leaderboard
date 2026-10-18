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
// Tweede inlogstap: TOTP-code of herstelcode. login.php zet pending_2fa_user_id na een juist wachtwoord.
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';

$db = getDB();

function backToLogin(?string $message = null): void {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_at']);
    if ($message !== null) {
        $_SESSION['login_error'] = $message;
    }
    header('Location: login.php');
    exit;
}

$userId = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
if (!$userId) {
    backToLogin();
}
if (time() - (int)($_SESSION['pending_2fa_at'] ?? 0) > PENDING_2FA_SECONDS) {
    backToLogin('De tijd om de code in te voeren is verlopen. Log opnieuw in.');
}

$user = findTeacher($db, $userId);
if (!$user || !(int)$user['is_active'] || empty($user['password_hash']) || !(int)$user['totp_enabled']) {
    backToLogin();
}

// Maximaal 5 foute codes per 15 minuten, ook over meerdere inlogpogingen heen
$attemptKey = (string)$userId;
if (isThrottled($db, '2fa', $attemptKey, 5, 15)) {
    backToLogin(THROTTLE_ERROR);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } else {
        $method = verifySecondFactor($db, $user, (string)($_POST['code'] ?? ''));
        if ($method !== null) {
            clearAttempts($db, '2fa', $attemptKey);
            if ($method === 'recovery') {
                logAudit($db, $userId, '2FA_RECOVERY_USED', 'Herstelcode gebruikt bij inloggen (nog ' . countRecoveryCodes($db, $userId) . ' over)');
            }
            $user = findTeacher($db, $userId);
            completeLogin($db, $user);
            header('Location: ' . loginRedirect($user));
            exit;
        }

        recordAttempt($db, '2fa', $attemptKey);
        logAudit($db, $userId, '2FA_FAILED', 'Foute 2FA-code bij inloggen');
        if (isThrottled($db, '2fa', $attemptKey, 5, 15)) {
            backToLogin(THROTTLE_ERROR);
        }
        $error = 'Ongeldige code. Probeer het opnieuw.';
    }
}
?>
<?= authPageStart('Verificatiecode', ['subtitle' => 'Voer de 6-cijferige code uit je authenticator-app in.', 'icon' => 'shield-check']) ?>
        <?php if ($error): ?>
            <?= alertBox('error', htmlspecialchars($error)) ?>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="field">
                <label for="c-code">Code</label>
                <input type="text" name="code" id="c-code" class="input-mono" style="font-size: 22px; text-align: center; letter-spacing: .3em;" inputmode="numeric" autocomplete="one-time-code" maxlength="20" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block">Verifiëren</button>
        </form>
        <p class="auth-note">Geen toegang tot je app? Vul dan een van je herstelcodes in (formaat xxxxx-xxxxx). Elke herstelcode werkt één keer.</p>
<?= authPageEnd('<a href="login.php">← Opnieuw inloggen</a>') ?>
