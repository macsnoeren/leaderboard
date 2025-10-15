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

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

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
    <title>Manage Assignments</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 { color: #333; }
        a.logout {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .success, .error {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            color: white;
        }
        .success { background: #4caf50; }
        .error { background: #f44336; }
        form {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        input, textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 1em;
        }
        label {
            font-weight: bold;
            margin-top: 10px;
            display: block;
        }
        button {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            padding: 10px 20px;
            margin-top: 10px;
            font-size: 1em;
            cursor: pointer;
        }
        button:hover { background: #5568d3; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th { background: #f8f9fa; }
        .delete-btn {
            background: #f44336;
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .delete-btn:hover { background: #d32f2f; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>📘 Manage Assignments</h1>
        <a href="teacher.php" class="logout">← Back to Dashboard</a>
    </header>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <h2>Add New Assignment</h2>
        <input type="hidden" name="action" value="add_assignment">

        <label>Assignment Number:</label>
        <input type="number" name="assignment_number" required>

        <label>Title:</label>
        <input type="text" name="title" required>

        <label>Description:</label>
        <textarea name="description" rows="3"></textarea>

        <label>Upload Artifact (PDF or ZIP):</label>
        <input type="file" name="artifact_file" accept=".pdf,.zip">

        <button type="submit">Add Assignment</button>
    </form>

    <h2>Existing Assignments</h2>
    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Title</th>
            <th>Description</th>
            <th>File</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($assignments as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['assignment_number']) ?></td>
                <td><?= htmlspecialchars($a['title']) ?></td>
                <td><?= htmlspecialchars($a['description']) ?></td>
                <td>
                    <?php if ($a['artifact_file']): ?>
                        <a href="<?= htmlspecialchars($a['artifact_file']) ?>" target="_blank">View File</a>
                    <?php else: ?>
                        <em>No file</em>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this assignment?');">
                        <input type="hidden" name="action" value="delete_assignment">
                        <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                        <button type="submit" class="delete-btn">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
