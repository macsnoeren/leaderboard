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
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);
$userId = (int)$user['id'];
$game = requireGame($db, $user);
$gameId = (int)$game['id'];

function findAssignmentInGame(PDO $db, int $id, int $gameId): ?array {
    $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ? AND game_id = ?");
    $stmt->execute([$id, $gameId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Verwijdert een bestand alleen als geen andere opdracht het nog gebruikt
function removeArtifact(PDO $db, ?string $file): void {
    if (!$file) {
        return;
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM assignments WHERE artifact_file = ?");
    $stmt->execute([$file]);
    if ($stmt->fetchColumn() == 0 && is_file(__DIR__ . '/' . $file)) {
        unlink(__DIR__ . '/' . $file);
    }
}

// Haal opdracht op als er een bewerking is gevraagd
$edit_assignment = null;
if (isset($_GET['edit'])) {
    $edit_assignment = findAssignmentInGame($db, (int)$_GET['edit'], $gameId);
}

// Zorg dat de uploadmap bestaat
$uploadDir = __DIR__ . '/artifacts';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

// Handle toevoegen van assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('assignments.php');
    }

    if ($_POST['action'] === 'add_assignment' || $_POST['action'] === 'edit_assignment') {
        $assignment_number = (int)$_POST['assignment_number'];
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $instruction = trim($_POST['instruction'] ?? '');
        $criteria = trim($_POST['criteria'] ?? '');
        $time_limit = (int)($_POST['time_limit'] ?? 0);
        $id = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : null;
        $back = 'assignments.php' . ($id ? "?edit=$id" : '');

        // Bij bewerken: de opdracht moet bij het actieve spel horen; het bestandspad komt uit de database
        $artifact_file = null;
        if ($id) {
            $existing = findAssignmentInGame($db, $id, $gameId);
            if (!$existing) {
                flash('error', 'Deze opdracht hoort niet bij het actieve spel.');
                redirect('assignments.php');
            }
            $artifact_file = $existing['artifact_file'];
        }

        if ($assignment_number < 1) {
            flash('error', 'Het opdrachtnummer moet 1 of hoger zijn.');
            redirect($back);
        }
        // Nummers zijn uniek per spel
        $stmt = $db->prepare("SELECT COUNT(*) FROM assignments WHERE game_id = ? AND assignment_number = ? AND id != ?");
        $stmt->execute([$gameId, $assignment_number, $id ?? 0]);
        if ($stmt->fetchColumn() > 0) {
            flash('error', "Er is in dit spel al een opdracht met nummer $assignment_number.");
            redirect($back);
        }

        // File upload
        if (!empty($_FILES['artifact_file']['name'])) {
            $fileTmp = $_FILES['artifact_file']['tmp_name'];
            $fileName = basename($_FILES['artifact_file']['name']);
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Beveiliging: Whitelist extensies tegen RCE
            $allowed = ['pdf', 'zip', 'txt', 'jpg', 'png'];
            if (!in_array($ext, $allowed)) {
                flash('error', 'Bestandstype niet toegestaan.');
                redirect($back);
            }

            // Veilig bestandsnaam genereren, met het spel erin
            $safeName = 'game_' . $gameId . '_assignment_' . $assignment_number . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $safeName;

            if (move_uploaded_file($fileTmp, $targetPath)) {
                // Verwijder oud bestand nu het nieuwe er staat
                $old = $artifact_file;
                $artifact_file = 'artifacts/' . $safeName;
                if ($id && $old) {
                    $db->prepare("UPDATE assignments SET artifact_file = ? WHERE id = ?")->execute([$artifact_file, $id]);
                    removeArtifact($db, $old);
                }
            } else {
                flash('error', 'File upload failed.');
                redirect($back);
            }
        }

        if ($_POST['action'] === 'edit_assignment' && $id) {
            $stmt = $db->prepare("UPDATE assignments SET assignment_number = ?, title = ?, description = ?, instruction = ?, criteria = ?, time_limit = ?, artifact_file = ? WHERE id = ? AND game_id = ?");
            $stmt->execute([$assignment_number, $title, $description, $instruction, $criteria, $time_limit, $artifact_file, $id, $gameId]);
            logAudit($db, $userId, 'ASSIGNMENT_UPDATE', "Updated assignment #$assignment_number: $title (spel #$gameId)");
            flash('success', 'Opdracht succesvol bijgewerkt!');
        } else {
            $stmt = $db->prepare("INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, time_limit, artifact_file) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$gameId, $assignment_number, $title, $description, $instruction, $criteria, $time_limit, $artifact_file]);
            logAudit($db, $userId, 'ASSIGNMENT_ADD', "Added assignment #$assignment_number: $title (spel #$gameId)");
            flash('success', 'Assignment added successfully!');
        }

        redirect('assignments.php');
    }

    // Verwijderen van assignment
    if ($_POST['action'] === 'delete_assignment' && isset($_POST['assignment_id'])) {
        $assignment = findAssignmentInGame($db, (int)$_POST['assignment_id'], $gameId);
        if (!$assignment) {
            flash('error', 'Deze opdracht hoort niet bij het actieve spel.');
            redirect('assignments.php');
        }

        $db->prepare("DELETE FROM assignments WHERE id = ?")->execute([$assignment['id']]);
        removeArtifact($db, $assignment['artifact_file']);

        logAudit($db, $userId, 'ASSIGNMENT_DELETE', "Deleted assignment #{$assignment['assignment_number']}: {$assignment['title']} (ID {$assignment['id']}, spel #$gameId)");
        flash('success', 'Assignment deleted successfully!');
        redirect('assignments.php');
    }

    // Opdrachten kopiëren uit een ander spel waar de docent toegang toe heeft
    if ($_POST['action'] === 'copy_assignments') {
        $fromGame = (int)($_POST['from_game_id'] ?? 0);
        if ($fromGame === $gameId || !canAccessGame($db, $user, $fromGame)) {
            flash('error', 'Je kunt alleen kopiëren uit een ander spel waar je toegang toe hebt.');
            redirect('assignments.php');
        }
        try {
            $count = copyAssignments($db, $fromGame, $gameId, $userId);
            flash($count > 0 ? 'success' : 'error', $count > 0 ? "$count opdrachten gekopieerd." : 'Dat spel heeft geen opdrachten.');
        } catch (Throwable $e) {
            flash('error', 'Kopiëren mislukt: ' . $e->getMessage());
        }
        redirect('assignments.php');
    }
}

// Ophalen van alle assignments van dit spel
$stmt = $db->prepare("SELECT * FROM assignments WHERE game_id = ? ORDER BY assignment_number ASC");
$stmt->execute([$gameId]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$nextNumber = $assignments ? (int)end($assignments)['assignment_number'] + 1 : 1;

// Andere spellen om uit te kopiëren, met het aantal opdrachten
$copySources = [];
foreach (accessibleGames($db, $user) as $other) {
    if ((int)$other['id'] !== $gameId && ($n = totalAssignments($db, (int)$other['id'])) > 0) {
        $copySources[] = ['id' => (int)$other['id'], 'name' => $other['name'], 'count' => $n];
    }
}

$extraCSS = '
<style>
    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); overflow-y: auto; }
    .modal-content { background-color: white; margin: 5% auto; padding: 30px; border-radius: 15px; width: 70%; max-width: 800px; box-shadow: 0 5px 30px rgba(0,0,0,0.3); position: relative; }
    .close-modal { position: absolute; top: 20px; right: 25px; font-size: 28px; font-weight: bold; cursor: pointer; color: #aaa; transition: 0.2s; }
    .close-modal:hover { color: #333; }
    .modal-section-title { font-weight: bold; color: #667eea; border-bottom: 2px solid #f0f4ff; padding-bottom: 5px; margin-top: 20px; margin-bottom: 10px; }
    .markdown-body { font-size: 1rem; line-height: 1.6; color: #4b4f56; }
    .markdown-body h1 { font-size: 1.4em; margin: 15px 0 10px 0; color: #333; }
    .markdown-body h2 { font-size: 1.25em; margin: 12px 0 8px 0; color: #333; }
    .markdown-body h3 { font-size: 1.1em; margin: 10px 0 5px 0; color: #333; }
    .markdown-body p { margin-bottom: 12px; }
    .markdown-body ul, .markdown-body ol { margin-left: 20px; margin-bottom: 15px; }
    .markdown-body li { margin-bottom: 5px; }
    .markdown-body code { background: #f0f2f5; padding: 2px 5px; border-radius: 4px; font-family: monospace; font-size: 0.9em; color: #e83e8c; }
    .markdown-body pre { background: #2d2d2d; color: #ccc; padding: 15px; border-radius: 8px; overflow-x: auto; margin-bottom: 15px; }
    .markdown-body pre code { background: transparent; padding: 0; color: inherit; font-size: 0.85em; }
    .clickable-title { color: #667eea; cursor: pointer; text-decoration: none; transition: 0.2s; }
    .clickable-title:hover { color: #5568d3; text-decoration: underline; }
</style>';

$pageTitle = 'Assignments';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">📘 Opdrachten Beheer <span style="font-size: 0.6em; color: #888; font-weight: normal;"><?= htmlspecialchars($game['name']) ?></span></h1>

    <?= renderFlash() ?>

    <div class="dashboard-grid">
        <div class="side-panel">
            <div class="card">
                <div class="card-title"><?= $edit_assignment ? "✏️ Opdracht Bewerken" : "➕ Nieuwe Opdracht" ?></div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $edit_assignment ? 'edit_assignment' : 'add_assignment' ?>">
                    <?php if ($edit_assignment): ?>
                        <input type="hidden" name="assignment_id" value="<?= $edit_assignment['id'] ?>">
                    <?php endif; ?>

                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Opdracht Nummer</label>
                        <input type="number" name="assignment_number" min="1" value="<?= $edit_assignment['assignment_number'] ?? $nextNumber ?>" required>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Titel</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($edit_assignment['title'] ?? '') ?>" required>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Beschrijving</label>
                        <textarea name="description" rows="3"><?= htmlspecialchars($edit_assignment['description'] ?? '') ?></textarea>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">De Echte Opdracht (Instructie)</label>
                        <textarea name="instruction" rows="4" placeholder="Wat moeten ze precies doen?"><?= htmlspecialchars($edit_assignment['instruction'] ?? '') ?></textarea>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Beoordelingscriteria</label>
                        <textarea name="criteria" rows="3" placeholder="Waar moet het antwoord aan voldoen?"><?= htmlspecialchars($edit_assignment['criteria'] ?? '') ?></textarea>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Tijdslimiet (minuten)</label>
                        <input type="number" name="time_limit" value="<?= $edit_assignment['time_limit'] ?? '' ?>" placeholder="0 = onbeperkt">
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Bestand (PDF/ZIP) <?= $edit_assignment && $edit_assignment['artifact_file'] ? '<span style="color: #4caf50;">(Huidig bestand behouden indien leeg)</span>' : '' ?></label>
                        <input type="file" name="artifact_file" accept=".pdf,.zip">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;"><?= $edit_assignment ? "Bijwerken" : "Toevoegen" ?></button>
                    <?php if ($edit_assignment): ?>
                        <a href="assignments.php" class="btn btn-outline" style="width: 100%; margin-top: 10px; display: block;">Annuleren</a>
                    <?php endif; ?>
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
                                <td>
                                    <a href="javascript:void(0)" class="clickable-title" onclick='openViewModal(<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <strong><?= htmlspecialchars($a['title']) ?></strong>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($a['artifact_file']): ?>
                                        <a href="<?= htmlspecialchars($a['artifact_file']) ?>" target="_blank" class="btn btn-outline" style="padding: 4px 8px; font-size: 0.8em;">Inzien</a>
                                    <?php else: ?>
                                        <span style="color:#ccc; font-size:0.8em;">Geen file</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="?edit=<?= $a['id'] ?>" class="btn btn-outline" style="padding: 4px 8px;" title="Bewerken">✏️</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Verwijder deze opdracht?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 4px 8px;">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($assignments)): ?>
                            <tr><td colspan="4" style="text-align: center; color: #999; padding: 30px;">Nog geen opdrachten in dit spel.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($copySources): ?>
            <div class="card">
                <div class="card-title">📋 Opdrachten kopiëren uit een ander spel</div>
                <p style="color: #888; font-size: 0.9em; margin: 10px 0 15px;">De opdrachten en hun bestanden worden gekopieerd; de nummering loopt door na nummer <?= $nextNumber - 1 ?>. Daarna staan beide spellen los van elkaar.</p>
                <form method="POST" style="display: flex; gap: 10px;" onsubmit="return confirm('Opdrachten kopiëren naar dit spel?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="copy_assignments">
                    <select name="from_game_id" required style="flex: 1;">
                        <?php foreach ($copySources as $src): ?>
                            <option value="<?= $src['id'] ?>"><?= htmlspecialchars($src['name']) ?> (<?= $src['count'] ?> opdrachten)</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Kopiëren</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal voor het inzien van de opdracht -->
    <div id="viewAssignmentModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeViewModal()">&times;</span>
            <h2 id="v-title" style="color: #333; margin-bottom: 20px;"></h2>
            
            <div class="modal-section-title">Beschrijving</div>
            <div id="v-desc" class="markdown-body"></div>
            
            <div class="modal-section-title">📍 Instructie (De Echte Opdracht)</div>
            <div id="v-instr" class="markdown-body" style="background: #f8f9fa; padding: 15px; border-radius: 8px;"></div>
            
            <div class="modal-section-title">📋 Beoordelingscriteria (Intern)</div>
            <div id="v-crit" class="markdown-body" style="color: #d32f2f; background: #fff1f0; padding: 15px; border-radius: 8px;"></div>

            <div style="margin-top: 30px; text-align: right;">
                <button class="btn btn-primary" onclick="closeViewModal()">Sluiten</button>
            </div>
        </div>
    </div>

<?php
$extraJS = "
<script>
    function openViewModal(assignment) {
        document.getElementById('v-title').innerText = 'Opdracht Details: ' + assignment.title;
        document.getElementById('v-desc').textContent = assignment.description || 'Geen beschrijving.';
        document.getElementById('v-instr').textContent = assignment.instruction || 'Geen specifieke instructies.';
        document.getElementById('v-crit').textContent = assignment.criteria || 'Geen criteria opgegeven.';
        
        document.getElementById('viewAssignmentModal').style.display = 'block';
        
        marked.setOptions({ breaks: true });
        document.querySelectorAll('#viewAssignmentModal .markdown-body').forEach(el => {
            el.innerHTML = marked.parse(el.textContent.trim());
        });
    }

    function closeViewModal() {
        document.getElementById('viewAssignmentModal').style.display = 'none';
    }

    window.onclick = function(event) {
        if (event.target == document.getElementById('viewAssignmentModal')) closeViewModal();
    }
</script>";
include 'admin_footer.php'; ?>
