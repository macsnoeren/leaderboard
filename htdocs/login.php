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
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
        }
        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 1em;
        }
        button {
            width: 100%;
            background: #667eea;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: 600;
        }
        button:hover {
            background: #5568d3;
        }
        .error {
            background: #f44336;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .notice {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .forgot-link {
            text-align: right;
            margin: -10px 0 20px;
            font-size: 0.9em;
        }
        .forgot-link a {
            color: #667eea;
            text-decoration: none;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Login</h1>
        <?php if ($notice): ?>
            <div class="notice"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Gebruikersnaam of e-mailadres:</label>
                <input type="text" name="username" value="<?= htmlspecialchars($identifier) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label>Wachtwoord:</label>
                <input type="password" name="password" autocomplete="current-password" required>
            </div>
            <div class="forgot-link"><a href="forgot_password.php">Wachtwoord vergeten?</a></div>
            <button type="submit">Inloggen</button>
        </form>
        <div class="back-link">
            <a href="index.php">← Terug naar het leaderboard</a>
        </div>
    </div>
</body>
</html>
