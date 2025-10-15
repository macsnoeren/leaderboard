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
<?php
require_once 'config.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$message = '';

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_user') {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);

        if (strlen($username) < 3 || strlen($password) < 4) {
            $message = "Username or password too short.";
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM teachers WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $message = "Username already exists.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO teachers (username, password_hash) VALUES (?, ?)");
                $stmt->execute([$username, $password_hash]);
                $message = "User added successfully!";
            }
        }
    }

    // Handle Delete User
    if ($_POST['action'] === 'delete_user' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];

        // Prevent deleting yourself
        if ($id === $_SESSION['teacher_id']) {
            $message = "You cannot delete your own account.";
        } else {
            $stmt = $db->prepare("DELETE FROM teachers WHERE id = ?");
            $stmt->execute([$id]);
            $message = "User deleted successfully.";
        }
    }

    // Handle Reset Password
    if ($_POST['action'] === 'reset_password' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $newPass = "newpass" . rand(100,999);
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE teachers SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $id]);
        $message = "Password for user ID $id reset to: $newPass";
    }
}

$users = $db->query("SELECT id, username FROM teachers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        h1 { margin-bottom: 20px; }
        .message {
            background: #e0f7fa;
            color: #00796b;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        form {
            margin-bottom: 20px;
        }
        input, button {
            padding: 10px;
            font-size: 1em;
            border-radius: 5px;
        }
        input[type="text"], input[type="password"] {
            width: 200px;
            border: 1px solid #ccc;
        }
        button {
            background: #667eea;
            color: white;
            border: none;
            cursor: pointer;
        }
        button:hover {
            background: #5568d3;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .actions form {
            display: inline-block;
            margin: 0 5px;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>👥 User Management</h1>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <h2>Add New User</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Add User</button>
        </form>

        <h2>Existing Users</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td class="actions">
                            <form method="POST" onsubmit="return confirm('Reset password for this user?')">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit">Reset Password</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Delete this user?')">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" style="background:#e53935;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="back-link">
            <a href="teacher.php">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
