<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';

$db = getDB();

// 1. Initialiseer de database tabellen
// username en password_hash zijn NULL zolang een uitgenodigde docent nog niet geactiveerd is
$db->exec("CREATE TABLE IF NOT EXISTS teachers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT 'user', force_password_change INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, email TEXT, display_name TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME, session_version INTEGER DEFAULT 0, totp_secret TEXT, totp_enabled INTEGER DEFAULT 0, totp_last_step INTEGER)");
// Bij een oude database zonder kolom email faalt dit; migrate.php maakt de index dan alsnog aan
try {
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_teachers_email ON teachers(email)");
} catch (PDOException $e) {}
$db->exec("CREATE TABLE IF NOT EXISTS user_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, new_email TEXT, expires_at DATETIME NOT NULL, used_at DATETIME, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL, used_at DATETIME)");
$db->exec("CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, identifier TEXT, ip TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
// Spellen: teams, opdrachten, archief en mededelingen horen bij een spel
$db->exec("CREATE TABLE IF NOT EXISTS games (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', join_code TEXT UNIQUE, registration_open INTEGER DEFAULT 0, logo_file TEXT, status_message TEXT, started_at DATETIME, ends_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS game_teachers (game_id INTEGER NOT NULL, teacher_id INTEGER NOT NULL, PRIMARY KEY (game_id, teacher_id))");
$db->exec("CREATE TABLE IF NOT EXISTS teams (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_name TEXT, email TEXT, current_level INTEGER DEFAULT 0, level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, access_token TEXT UNIQUE)");
$db->exec("CREATE TABLE IF NOT EXISTS assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, assignment_number INTEGER, title TEXT, description TEXT, instruction TEXT, criteria TEXT, time_limit INTEGER, artifact_file TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS download_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, level INTEGER, token TEXT, expires_at DATETIME)");
$db->exec("CREATE TABLE IF NOT EXISTS team_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, download_count INTEGER DEFAULT 0, UNIQUE(team_id, assignment_number))");
$db->exec("CREATE TABLE IF NOT EXISTS audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS team_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, sender TEXT, message TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, key_name TEXT, api_key TEXT UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS ai_service_status (id INTEGER PRIMARY KEY AUTOINCREMENT, last_heartbeat DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS completed_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_id INTEGER, team_name TEXT, assignment_number INTEGER, chat_history TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER, message TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
// Bij een oude database zonder kolom game_id falen deze; migrate.php maakt de indexen dan alsnog aan
try {
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_teams_game_name ON teams(game_id, team_name)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_assignments_game_number ON assignments(game_id, assignment_number)");
} catch (PDOException $e) {}

// 2. Voeg standaard assignments toe als deze nog niet bestaan
/*
$count = $db->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
if ($count == 0) {
    $stmt = $db->prepare("INSERT INTO assignments (assignment_number, title, description, instruction, criteria, time_limit, artifact_file) VALUES (?, ?, ?, ?, ?, ?, ?)");
    for ($i = 1; $i <= 14; $i++) {
        $stmt->execute([
            $i,
            "Assignment $i",
            "Assignment $i description",
            "Gedetailleerde instructies voor opdracht $i...",
            "Het antwoord moet voldoen aan de gestelde eisen voor level $i.",
            60,
            "artifacts/assignment$i.pdf"
        ]);
    }
}
*/

// 3. Controleer of setup nog nodig is
try {
    $stmt = $db->query("SELECT COUNT(*) FROM teachers");
    $userCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    $userCount = 0;
}

if ($userCount > 0) {
    // Als er al gebruikers zijn, is setup niet meer toegankelijk
    header('Location: login.php');
    exit;
}

$error = '';
$username = '';
$emailInput = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $emailInput = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = normalizeEmail($emailInput);

    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } elseif ($usernameError = validateUsername($username)) {
        $error = $usernameError;
    } elseif ($emailInput !== '' && $email === null) {
        $error = 'Vul een geldig e-mailadres in, of laat het veld leeg.';
    } elseif ($passwordError = validatePassword($password, $_POST['confirm_password'] ?? '')) {
        $error = $passwordError;
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        // De eerste gebruiker wordt altijd als actieve 'admin' aangemaakt zonder force_password_change
        $stmt = $db->prepare("INSERT INTO teachers (username, email, password_hash, role, force_password_change, is_active, session_version, created_at) VALUES (?, ?, ?, 'admin', 0, 1, 0, datetime('now'))");
        $stmt->execute([$username, $email, $password_hash]);

        $new_id = (int)$db->lastInsertId();
        logAudit($db, $new_id, 'SETUP', 'First admin account created');
        completeLogin($db, findTeacher($db, $new_id));
        header('Location: teacher.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>Initial Setup</title>
    <style>
        body { font-family: sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .box { background: white; padding: 40px; border-radius: 15px; width: 100%; max-width: 400px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        h1 { text-align: center; color: #333; }
        .info { background: #e3f2fd; color: #0d47a1; padding: 15px; border-radius: 5px; margin-bottom: 20px; font-size: 0.9em; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; }
        .error { color: #f44336; margin-bottom: 15px; text-align: center; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Setup Admin</h1>
        <div class="info">Er zijn geen gebruikers gevonden. Maak de eerste beheerder aan om te beginnen. De database is automatisch gecontroleerd en bijgewerkt.</div>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Gebruikersnaam:</label>
                <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="username" required>
            </div>
            <div class="form-group">
                <label>E-mailadres (optioneel, nodig voor wachtwoordherstel):</label>
                <input type="email" name="email" value="<?= htmlspecialchars($emailInput) ?>" autocomplete="email">
            </div>
            <div class="form-group">
                <label>Wachtwoord (minimaal <?= PASSWORD_MIN_LENGTH ?> tekens):</label>
                <input type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label>Bevestig wachtwoord:</label>
                <input type="password" name="confirm_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
            </div>
            <button type="submit">Account aanmaken & Inloggen</button>
        </form>
    </div>
</body>
</html>