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
        $instruction = trim($_POST['instruction'] ?? '');
        $criteria = trim($_POST['criteria'] ?? '');
        $time_limit = (int)($_POST['time_limit'] ?? 0);

        $artifact_file = null;

        // File upload
        if (!empty($_FILES['artifact_file']['name'])) {
            $fileTmp = $_FILES['artifact_file']['tmp_name'];
            $fileName = basename($_FILES['artifact_file']['name']);
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            
            // Beveiliging: Whitelist extensies tegen RCE
            $allowed = ['pdf', 'zip', 'txt', 'jpg', 'png'];
            if (!in_array($ext, $allowed)) {
                $_SESSION['error'] = "Bestandstype niet toegestaan.";
                header('Location: assignments.php');
                exit;
            }

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
        $stmt = $db->prepare("INSERT INTO assignments (assignment_number, title, description, instruction, criteria, time_limit, artifact_file)
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$assignment_number, $title, $description, $instruction, $criteria, $time_limit, $artifact_file]);
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

$pageTitle = 'Assignments';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">📘 Opdrachten Beheer</h1>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error-msg"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="dashboard-grid">
        <div class="side-panel">
            <div class="card">
                <div class="card-title">➕ Nieuwe Opdracht</div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_assignment">
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Opdracht Nummer</label>
                    <input type="number" name="assignment_number" required>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Titel</label>
                    <input type="text" name="title" required>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Beschrijving</label>
                    <textarea name="description" rows="3"></textarea>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">De Echte Opdracht (Instructie)</label>
                        <textarea name="instruction" rows="4" placeholder="Wat moeten ze precies doen?"></textarea>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Beoordelingscriteria</label>
                        <textarea name="criteria" rows="3" placeholder="Waar moet het antwoord aan voldoen?"></textarea>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Tijdslimiet (minuten)</label>
                        <input type="number" name="time_limit" placeholder="0 = onbeperkt">
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Bestand (PDF/ZIP)</label>
                    <input type="file" name="artifact_file" accept=".pdf,.zip">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Toevoegen</button>
                </form>
            </div>
        </div>

        <div class="main-panel">
            <div class="card">
                <div class="card-title">📂 Bestaande Opdrachten</div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Titel</th>
                            <th style="width: 100px;">Bestand</th>
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
                                        <a href="<?= htmlspecialchars($a['artifact_file']) ?>" target="_blank" class="btn btn-outline" style="padding: 4px 8px; font-size: 0.8em;">Inzien</a>
                                    <?php else: ?>
                                        <span style="color:#ccc; font-size:0.8em;">Geen file</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Verwijder deze opdracht?');">
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 4px 8px;">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php include 'admin_footer.php'; ?>
