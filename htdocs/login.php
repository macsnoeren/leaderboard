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

$error = '';
$notice = '';

$db = getDB();

// Controleer of er al gebruikers zijn
try {
    $stmt = $db->query("SELECT COUNT(*) FROM teachers");
    if ($stmt->fetchColumn() == 0) {
        header('Location: setup.php');
        exit;
    }
} catch (PDOException $e) {
    header('Location: setup.php');
    exit;
}

if (isset($_SESSION['teacher_logged_in'])) {
    header('Location: teacher.php');
    exit;
}

// Meldingen na een redirect
$notices = [
    'expired' => 'Je sessie is verlopen of je account is gewijzigd. Log opnieuw in.',
    'activated' => 'Je account is geactiveerd. Je kunt nu inloggen.',
    'reset' => 'Je wachtwoord is gewijzigd. Je kunt nu inloggen met je nieuwe wachtwoord.',
    'email_confirmed' => 'Je nieuwe e-mailadres is bevestigd.',
];
foreach ($notices as $param => $text) {
    if (isset($_GET[$param])) {
        $notice = $text;
    }
}
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

$identifier = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } else {
        // Inloggen met gebruikersnaam of e-mailadres; een exacte gebruikersnaam gaat voor
        $email = normalizeEmail($identifier) ?? '';
        $stmt = $db->prepare("SELECT * FROM teachers WHERE username = ? OR (email = ? AND email != '') ORDER BY (username = ?) DESC LIMIT 1");
        $stmt->execute([$identifier, $email, $identifier]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Pogingen tellen per account (of per ingevoerde naam als die niet bestaat) en per IP
        $attemptKey = $teacher ? 'user:' . $teacher['id'] : strtolower($identifier);

        if (isThrottled($db, 'login', $attemptKey, 5, 15) || isIpThrottled($db, 'login', 20, 15)) {
            $error = THROTTLE_ERROR;
        } else {
            // Ook zonder account een hash controleren, zodat de responstijd niets verraadt
            $hash = $teacher['password_hash'] ?? null;
            $passwordOk = password_verify($password, $hash ?: DUMMY_PASSWORD_HASH);

            // Gedeactiveerd of nog niet geactiveerd geeft dezelfde melding als een fout wachtwoord
            if (!$teacher || !$hash || !$passwordOk || (int)$teacher['is_active'] !== 1) {
                recordAttempt($db, 'login', $attemptKey);
                logAudit($db, $teacher ? (int)$teacher['id'] : null, 'LOGIN_FAILED', 'Mislukte inlogpoging voor: ' . substr($identifier, 0, 100));
                $error = LOGIN_ERROR;
            } else {
                clearAttempts($db, 'login', $attemptKey);
                if ((int)$teacher['totp_enabled'] === 1) {
                    // Tweede stap: nog niet ingelogd tot de code klopt
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa_user_id'] = (int)$teacher['id'];
                    $_SESSION['pending_2fa_at'] = time();
                    header('Location: login_2fa.php');
                    exit;
                }
                completeLogin($db, $teacher);
                header('Location: ' . loginRedirect($teacher));
                exit;
            }
        }
    }
}
?>
<?= authPageStart('Inloggen', ['subtitle' => 'Voor operators en beheerders', 'icon' => 'login']) ?>
        <?php if ($notice): ?>
            <?= alertBox('success', htmlspecialchars($notice)) ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?= alertBox('error', htmlspecialchars($error)) ?>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="field">
                <label for="l-user">Gebruikersnaam of e-mailadres</label>
                <input type="text" name="username" id="l-user" value="<?= htmlspecialchars($identifier) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="l-pass">Wachtwoord</label>
                <input type="password" name="password" id="l-pass" autocomplete="current-password" required>
                <div class="hint" style="text-align: right;"><a href="forgot_password.php">Wachtwoord vergeten?</a></div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block">Inloggen</button>
        </form>
<?= authPageEnd('<a href="index.php">← Naar het leaderboard</a>') ?>
