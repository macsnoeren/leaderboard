<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';

$db = getDB();

// 1. Initialiseer de database tabellen
$db->exec("CREATE TABLE IF NOT EXISTS teachers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT 'user', force_password_change INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE IF NOT EXISTS teams (id INTEGER PRIMARY KEY AUTOINCREMENT, team_name TEXT, email TEXT, current_level INTEGER DEFAULT 0, level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, access_token TEXT UNIQUE)");
$db->exec("CREATE TABLE IF NOT EXISTS assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_number INTEGER, title TEXT, description TEXT, artifact_file TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS download_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, level INTEGER, token TEXT, expires_at DATETIME)");
$db->exec("CREATE TABLE IF NOT EXISTS team_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, download_count INTEGER DEFAULT 0, UNIQUE(team_id, assignment_number))");
$db->exec("CREATE TABLE IF NOT EXISTS audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS team_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, sender TEXT, message TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

// 2. Voeg standaard assignments toe als deze nog niet bestaan
$count = $db->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
if ($count == 0) {
    $stmt = $db->prepare("INSERT INTO assignments (assignment_number, title, description, artifact_file) VALUES (?, ?, ?, ?)");
    for ($i = 1; $i <= 14; $i++) {
        $stmt->execute([
            $i,
            "Assignment $i",
            "Assignment $i description",
            "artifacts/assignment$i.pdf"
        ]);
    }
}

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO teachers (username, password_hash, role) VALUES (?, ?, 'admin')");
        $stmt->execute([$username, $password_hash]);
        
        $new_id = $db->lastInsertId();
        $_SESSION['teacher_logged_in'] = true;
        $_SESSION['teacher_id'] = $new_id;
        $_SESSION['teacher_role'] = 'admin';

        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'SETUP', 'First admin account created')")->execute([$new_id]);
        header('Location: teacher.php');
        exit;
    } else {
        $error = 'Vul alle velden in.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
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
        <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Gebruikersnaam:</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Wachtwoord:</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit">Account aanmaken & Inloggen</button>
        </form>
    </div>
</body>
</html>