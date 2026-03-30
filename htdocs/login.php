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
require_once '../conf/config.php';

if (isset($_SESSION['teacher_logged_in'])) {
    header('Location: teacher.php');
    exit;
}

$error = '';

$db = getDB();

// Zorg dat de basis tabellen bestaan (voor een verse installatie)
$db->exec("CREATE TABLE IF NOT EXISTS teachers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS teams (id INTEGER PRIMARY KEY AUTOINCREMENT, team_name TEXT, email TEXT, current_level INTEGER DEFAULT 0, level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, access_token TEXT UNIQUE)");
$db->exec("CREATE TABLE IF NOT EXISTS assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_number INTEGER, title TEXT, description TEXT, artifact_file TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS download_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, level INTEGER, token TEXT, expires_at DATETIME)");
$db->exec("CREATE TABLE IF NOT EXISTS team_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, download_count INTEGER DEFAULT 0, UNIQUE(team_id, assignment_number))");
$db->exec("CREATE TABLE IF NOT EXISTS audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

// Migratie voor bestaande installaties: voeg ontbrekende kolommen toe aan 'teams'
try { @$db->exec("ALTER TABLE teams ADD COLUMN level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP"); } catch (PDOException $e) { /* Kolom bestaat waarschijnlijk al */ }
try { @$db->exec("ALTER TABLE teams ADD COLUMN access_token TEXT"); } catch (PDOException $e) { /* Kolom bestaat waarschijnlijk al */ }
// Zorg dat teams zonder token er alsnog een krijgen
try {
    $db->exec("UPDATE teams SET access_token = lower(hex(randomblob(32))) WHERE access_token IS NULL");
} catch (PDOException $e) { /* Kolom bestaat nog niet of tabel is bezet */ }

// Controleer of er al gebruikers zijn
$userCount = $db->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
$setup_mode = ($userCount == 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($setup_mode) {
        // Maak de eerste admin aan
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO teachers (username, password_hash) VALUES (?, ?)");
        $stmt->execute([$username, $password_hash]);
        
        $new_id = $db->lastInsertId();
        $_SESSION['teacher_logged_in'] = true;
        $_SESSION['teacher_id'] = $new_id;

        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'SETUP', 'First admin account created')")->execute([$new_id]);
        header('Location: teacher.php');
        exit;
    } else {
        $stmt = $db->prepare("SELECT * FROM teachers WHERE username = ?");
        $stmt->execute([$username]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($teacher && password_verify($password, $teacher['password_hash'])) {
            $_SESSION['teacher_logged_in'] = true;
            $_SESSION['teacher_id'] = $teacher['id'];

            $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'LOGIN', 'User logged in')")->execute([$teacher['id']]);
            header('Location: teacher.php');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
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
        <h1><?= $setup_mode ? 'Setup First Admin' : 'Login' ?></h1>
        <?php if ($setup_mode): ?>
            <div style="background: #e3f2fd; color: #0d47a1; padding: 10px; border-radius: 5px; margin-bottom: 20px; font-size: 0.9em;">
                No admin account found. Create the first one to get started.
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Username:</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Password:</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit"><?= $setup_mode ? 'Create Admin Account' : 'Login' ?></button>
        </form>
        <div class="back-link">
            <a href="index.php">← Back to Leaderboard</a>
        </div>
    </div>
</body>
</html>
