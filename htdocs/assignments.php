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

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// Tel ongelezen berichten voor de sidebar badge
$unread_total = $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();

// Zorg dat de uploadmap bestaat
$uploadDir = __DIR__ . '/artifacts';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

// Handle toevoegen van assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_assignment') {
        $assignment_number = (int)$_POST['assignment_number'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);

        $artifact_file = null;

        // File upload
        if (!empty($_FILES['artifact_file']['name'])) {
            $fileTmp = $_FILES['artifact_file']['tmp_name'];
            $fileName = basename($_FILES['artifact_file']['name']);
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);

            // Veilig bestandsnaam genereren
            $safeName = 'assignment_' . $assignment_number . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $safeName;

            if (move_uploaded_file($fileTmp, $targetPath)) {
                $artifact_file = 'artifacts/' . $safeName;
            } else {
                $_SESSION['error'] = "File upload failed.";
                header('Location: assignments.php');
                exit;
            }
        }

        // Database invoegen
        $stmt = $db->prepare("INSERT INTO assignments (assignment_number, title, description, artifact_file)
                              VALUES (?, ?, ?, ?)");
        $stmt->execute([$assignment_number, $title, $description, $artifact_file]);
        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'ASSIGNMENT_ADD', ?)")->execute([$_SESSION['teacher_id'], "Added assignment #$assignment_number: $title"]);

        $_SESSION['success'] = "Assignment added successfully!";
        header('Location: assignments.php');
        exit;
    }

    // Verwijderen van assignment
    if ($_POST['action'] === 'delete_assignment' && isset($_POST['assignment_id'])) {
        $id = (int)$_POST['assignment_id'];

        // Verwijder het bestand
        $stmt = $db->prepare("SELECT artifact_file FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetchColumn();

        if ($file && file_exists(__DIR__ . '/' . $file)) {
            unlink(__DIR__ . '/' . $file);
        }

        $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
        $stmt->execute([$id]);

        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'ASSIGNMENT_DELETE', ?)")->execute([$_SESSION['teacher_id'], "Deleted assignment ID: $id"]);
        $_SESSION['success'] = "Assignment deleted successfully!";
        header('Location: assignments.php');
        exit;
    }
}

// Ophalen van alle assignments
$assignments = $db->query("SELECT * FROM assignments ORDER BY assignment_number ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Assignments - Zebrawave</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; position: fixed; height: 100vh; }
        .sidebar-header { padding: 30px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-align: center; font-weight: bold; font-size: 1.2em; }
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .nav-item { display: flex; align-items: center; padding: 12px 25px; color: #4b4f56; text-decoration: none; transition: 0.2s; font-weight: 500; }
        .nav-item:hover { background: #f0f2f5; color: #667eea; }
        .nav-item.active { background: #f0f4ff; color: #667eea; border-left: 4px solid #667eea; }
        .badge { background: #f44336; color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75em; margin-left: auto; }
        .main-content { margin-left: 260px; flex: 1; padding: 40px; }
        .card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .card-title { font-size: 1.2em; font-weight: bold; color: #333; margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 30px; }
        input, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .btn-primary { background: #667eea; color: white; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; border-bottom: 2px solid #f0f2f5; color: #888; font-size: 0.85em; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid #f0f2f5; }
    </style>
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">Zebrawave Admin</div>
        <div class="sidebar-nav">
            <a href="teacher.php" class="nav-item">📊 Dashboard</a>
            <a href="assignments.php" class="nav-item active">📘 Assignments</a>
            <a href="messages.php" class="nav-item">
                ✉️ Messages
                <span id="unread-badge-container">
                    <?php if ($unread_total > 0): ?>
                        <span class="badge"><?= $unread_total ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <a href="users.php" class="nav-item">👥 Users</a>
            <a href="audit.php" class="nav-item">📋 Audit Logs</a>
            <div style="margin-top: 20px; padding: 0 25px; font-size: 0.7em; color: #bbb; text-transform: uppercase;">Settings</div>
            <a href="password.php" class="nav-item">🔑 Password</a>
            <a href="logout.php" class="nav-item" style="margin-top: auto; color: #c62828;">🚪 Logout</a>
        </div>
    </nav>

    <div class="main-content">
        <h1 style="margin-bottom: 30px;">📘 Opdrachten Beheer</h1>

        <div class="grid">
            <div class="card">
                <div class="card-title">Nieuwe Opdracht</div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_assignment">
                    <label>Opdracht Nummer:</label>
                    <input type="number" name="assignment_number" required>
                    <label>Titel:</label>
                    <input type="text" name="title" required>
                    <label>Beschrijving:</label>
                    <textarea name="description" rows="3"></textarea>
                    <label>Bestand (PDF/ZIP):</label>
                    <input type="file" name="artifact_file" accept=".pdf,.zip">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Toevoegen</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">Bestaande Opdrachten</div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Titel</th>
                            <th>Bestand</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars($a['assignment_number']) ?></td>
                                <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
                                <td>
                                    <?php if ($a['artifact_file']): ?>
                                        <a href="<?= htmlspecialchars($a['artifact_file']) ?>" target="_blank">PDF</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Verwijder deze opdracht?');">
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                        <button type="submit" style="background:none; border:none; color:red; cursor:pointer;">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
