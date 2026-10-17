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

const DESIGN_STATUS_TEXT = [
    'idle' => '',
    'pending' => '⏳ De AI kijkt mee… dit duurt meestal minder dan een minuut.',
    'questions' => '❓ De AI heeft vragen. Beantwoord ze hieronder; daarna kijkt ze opnieuw.',
    'failed' => '⚠️ De AI kon deze opdracht niet bekijken (model niet bereikbaar?). Probeer het opnieuw.',
];

// Dialoog van de opdracht-assistent als HTML (ook voor de live update)
function renderDialog(array $dialog): string {
    if (!$dialog) {
        return '<p class="muted">Nog geen vragen van de AI.</p>';
    }
    $html = '';
    foreach ($dialog as $d) {
        $html .= '<div class="dialog-msg ' . ($d['role'] === 'ai' ? 'ai' : 'teacher') . '"><small>' . ($d['role'] === 'ai' ? '🤖 AI' : '👤 Docent') . ' – ' . htmlspecialchars(formatDateTime($d['created_at'])) . '</small>'
            . '<div>' . nl2br(htmlspecialchars($d['message'])) . '</div></div>';
    }
    return $html;
}

// Versie van de rubric: verandert als de AI (of een collega) een nieuw voorstel opslaat
function rubricVersion(array $a): string {
    return md5((string)$a['ai_rubric'] . '|' . $a['ai_rubric_status']);
}

// Haal opdracht op als er een bewerking is gevraagd
$edit_assignment = null;
if (isset($_GET['edit'])) {
    $edit_assignment = findAssignmentInGame($db, (int)$_GET['edit'], $gameId);
}

// Live update van het AI-paneel: assignments.php?edit=ID&ajax=ai
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] !== 'ai' || !$edit_assignment) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'design_status' => $edit_assignment['ai_design_status'] ?: 'idle',
        'status_text' => DESIGN_STATUS_TEXT[$edit_assignment['ai_design_status'] ?: 'idle'] ?? '',
        'rubric_status' => $edit_assignment['ai_rubric_status'] ?: 'none',
        'rubric_badge' => rubricStatusBadge($edit_assignment['ai_rubric_status'] ?: 'none'),
        'rubric_version' => rubricVersion($edit_assignment),
        'dialog_html' => renderDialog(assignmentDialog($db, (int)$edit_assignment['id'])),
    ]);
    exit;
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
        // "Altijd door een mens": de AI zet bij deze opdracht nooit zelf een level omhoog
        $autoAllowed = empty($_POST['ai_human_only']) ? 1 : 0;
        $id = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : null;
        $back = 'assignments.php' . ($id ? "?edit=$id" : '');

        // Bij bewerken: de opdracht moet bij het actieve spel horen; het bestandspad komt uit de database
        $artifact_file = null;
        $existing = null;
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
            // Een goedgekeurde rubric past misschien niet meer bij een gewijzigde instructie of antwoord
            $rubricStatus = rubricStatusAfterEdit($existing, ['instruction' => $instruction, 'criteria' => $criteria]);
            $stmt = $db->prepare("UPDATE assignments SET assignment_number = ?, title = ?, description = ?, instruction = ?, criteria = ?, time_limit = ?, artifact_file = ?, ai_auto_allowed = ?, ai_rubric_status = ? WHERE id = ? AND game_id = ?");
            $stmt->execute([$assignment_number, $title, $description, $instruction, $criteria, $time_limit, $artifact_file, $autoAllowed, $rubricStatus, $id, $gameId]);
            logAudit($db, $userId, 'ASSIGNMENT_UPDATE', "Updated assignment #$assignment_number: $title (spel #$gameId)" . ($rubricStatus !== $existing['ai_rubric_status'] ? ', rubric terug naar concept' : ''));
            if ($rubricStatus !== $existing['ai_rubric_status']) {
                flash('error', 'Opdracht gewijzigd, controleer de rubric. De opdracht is niet meer AI-klaar tot je de rubric opnieuw goedkeurt.');
            } else {
                flash('success', 'Opdracht succesvol bijgewerkt!');
            }
            redirect("assignments.php?edit=$id");
        }

        $stmt = $db->prepare("INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, time_limit, artifact_file, ai_auto_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$gameId, $assignment_number, $title, $description, $instruction, $criteria, $time_limit, $artifact_file, $autoAllowed]);
        $newId = (int)$db->lastInsertId();
        logAudit($db, $userId, 'ASSIGNMENT_ADD', "Added assignment #$assignment_number: $title (spel #$gameId)");
        flash('success', 'Opdracht toegevoegd. Vul nu de rubric in, of laat de AI meekijken.');
        redirect("assignments.php?edit=$newId");
    }

    // Rubric opslaan (concept) of opslaan en goedkeuren
    if ($_POST['action'] === 'save_rubric' || $_POST['action'] === 'approve_rubric') {
        $id = (int)($_POST['assignment_id'] ?? 0);
        $error = saveRubric($db, $id, $gameId, rubricFromForm($_POST), $userId);
        if (!$error && $_POST['action'] === 'approve_rubric') {
            $error = approveRubric($db, $id, $gameId, $userId);
        }
        if ($error) {
            flash('error', $error);
        } else {
            flash('success', $_POST['action'] === 'approve_rubric' ? 'Rubric goedgekeurd: deze opdracht is AI-klaar.' : 'Rubric opgeslagen als concept.');
        }
        redirect("assignments.php?edit=$id#rubric");
    }

    // Opdracht-assistent: laten meekijken of de vragen beantwoorden
    if ($_POST['action'] === 'ai_request' || $_POST['action'] === 'ai_answer') {
        $id = (int)($_POST['assignment_id'] ?? 0);
        $error = $_POST['action'] === 'ai_request'
            ? requestDesign($db, $id, $gameId, $userId)
            : addTeacherAnswer($db, $id, $gameId, (string)($_POST['answer'] ?? ''), $userId);
        flash($error ? 'error' : 'success', $error ?? ($_POST['action'] === 'ai_request' ? 'De AI kijkt mee.' : 'Antwoord verstuurd; de AI kijkt opnieuw.'));
        redirect("assignments.php?edit=$id#ai-panel");
    }

    // Verwijderen van assignment
    if ($_POST['action'] === 'delete_assignment' && isset($_POST['assignment_id'])) {
        $assignment = findAssignmentInGame($db, (int)$_POST['assignment_id'], $gameId);
        if (!$assignment) {
            flash('error', 'Deze opdracht hoort niet bij het actieve spel.');
            redirect('assignments.php');
        }

        $db->prepare("DELETE FROM assignments WHERE id = ?")->execute([$assignment['id']]);
        $db->prepare("DELETE FROM assignment_ai_dialog WHERE assignment_id = ?")->execute([$assignment['id']]);
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

// Rubric en AI-paneel van de opdracht die bewerkt wordt
$rubric = null;
$rubricRows = [];
$designStatus = 'idle';
$canRequestAi = false;
if ($edit_assignment) {
    $rubric = decodeRubric($edit_assignment['ai_rubric']);
    $rubricRows = $rubric['aspecten'] ?? [];
    if (!$rubricRows) {
        $rubricRows = [['naam' => '', 'verplicht' => true, 'onvoldoende' => '', 'voldoende' => '', 'goed' => '']];
    }
    $designStatus = $edit_assignment['ai_design_status'] ?: 'idle';
    $canRequestAi = trim((string)$edit_assignment['instruction']) !== '' && trim((string)$edit_assignment['criteria']) !== '';
}
$listField = function (string $field) use ($rubric): string {
    return htmlspecialchars(implode("\n", $rubric[$field] ?? []));
};

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
    .muted { color: #999; font-size: 0.85em; }
    .rubric-table th, .rubric-table td { padding: 6px; vertical-align: top; }
    .rubric-table th { font-size: 0.75em; }
    .rubric-table textarea, .rubric-table input, .rubric-table select { padding: 6px; font-size: 0.85em; }
    .rubric-table textarea { min-height: 54px; resize: vertical; }
    .rubric-list label { display: block; margin: 12px 0 5px; font-size: 0.9em; }
    .dialog-msg { padding: 10px 12px; border-radius: 8px; margin-bottom: 8px; font-size: 0.9em; line-height: 1.4; }
    .dialog-msg small { color: #888; display: block; margin-bottom: 3px; }
    .dialog-msg.ai { background: #f3e5f5; border-left: 4px solid #9c27b0; }
    .dialog-msg.teacher { background: #f1f8e9; border-left: 4px solid #7cb342; }
    .ai-status { background: #fff8e1; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; font-size: 0.9em; }
    .ai-status:empty { display: none; }
    .notice { background: #e3f2fd; color: #0d47a1; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; font-size: 0.9em; display: none; }
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
                        <label style="display:block; margin-bottom:5px; font-size:0.9em;">Beoordelingscriteria (het antwoord)</label>
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
                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px; font-size: 0.9em;">
                        <input type="checkbox" name="ai_human_only" value="1" style="width: auto;" <?= $edit_assignment && (int)$edit_assignment['ai_auto_allowed'] === 0 ? 'checked' : '' ?>>
                        Altijd door een mens: de AI zet bij deze opdracht nooit zelf een level omhoog
                    </label>
                    <button type="submit" class="btn btn-primary" style="width: 100%;"><?= $edit_assignment ? "Bijwerken" : "Toevoegen" ?></button>
                    <?php if ($edit_assignment): ?>
                        <a href="assignments.php" class="btn btn-outline" style="width: 100%; margin-top: 10px; display: block;">Annuleren</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($edit_assignment): ?>
            <div class="card" id="ai-panel">
                <div class="card-title">🤖 AI-assistent</div>
                <p class="muted" style="margin: 8px 0 12px;">De AI leest de opdracht en je antwoord mee, stelt vragen als iets niet eenduidig is en stelt daarna een rubric voor. Je antwoord (de criteria) blijft altijd zoals jij het schreef. De bijlage leest de AI niet: ze vraagt naar feiten die alleen daar staan.</p>
                <div class="ai-status" id="ai-status"><?= htmlspecialchars(DESIGN_STATUS_TEXT[$designStatus] ?? '') ?></div>
                <div id="ai-dialog"><?= renderDialog(assignmentDialog($db, (int)$edit_assignment['id'])) ?></div>
                <form method="POST" id="ai-answer-form" style="margin-top: 10px; <?= $designStatus === 'questions' ? '' : 'display: none;' ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ai_answer">
                    <input type="hidden" name="assignment_id" value="<?= (int)$edit_assignment['id'] ?>">
                    <textarea name="answer" rows="3" placeholder="Je antwoord op de vragen van de AI…" required></textarea>
                    <button type="submit" class="btn btn-primary" style="margin-top: 8px;">Antwoord versturen</button>
                </form>
                <form method="POST" id="ai-request-form" style="margin-top: 10px; <?= in_array($designStatus, ['idle', 'failed'], true) ? '' : 'display: none;' ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ai_request">
                    <input type="hidden" name="assignment_id" value="<?= (int)$edit_assignment['id'] ?>">
                    <button type="submit" class="btn btn-primary" <?= $canRequestAi ? '' : 'disabled title="Vul eerst de instructie en de criteria in en sla op"' ?>>🤖 Laat AI meekijken</button>
                    <?php if (!$canRequestAi): ?><span class="muted" style="margin-left: 8px;">Vul eerst de instructie en de criteria in en sla op.</span><?php endif; ?>
                </form>
            </div>

            <div class="card" id="rubric">
                <div class="card-title" style="display: flex; justify-content: space-between; align-items: center;">
                    <span>📏 Rubric</span><span id="rubric-badge"><?= rubricStatusBadge($edit_assignment['ai_rubric_status'] ?: 'none') ?></span>
                </div>
                <p class="muted" style="margin: 8px 0 12px;">Hiermee beoordeelt de AI de antwoorden van teams. Zonder goedgekeurde rubric doet de AI alleen suggesties aan de docent.</p>
                <div class="notice" id="rubric-notice">Er is een nieuw rubric-voorstel. <a href="assignments.php?edit=<?= (int)$edit_assignment['id'] ?>#rubric" style="color: #0d47a1; font-weight: bold;">Herlaad</a> om het te zien (je niet-opgeslagen wijzigingen gaan dan verloren).</div>
                <form method="POST" id="rubric-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="assignment_id" value="<?= (int)$edit_assignment['id'] ?>">
                    <label style="display:block; margin-bottom:5px; font-size:0.9em;">Kern: wat moet het team in elk geval geven?</label>
                    <textarea name="rubric_kern" rows="2" placeholder="Bijv. Het team moet de dader én het motief noemen."><?= htmlspecialchars($rubric['kern'] ?? '') ?></textarea>
                    <div class="table-container" style="margin-top: 12px; overflow-x: auto;">
                        <table class="rubric-table">
                            <thead>
                                <tr><th style="width: 16%;">Aspect</th><th style="width: 9%;">Verplicht</th><th>Onvoldoende</th><th>Voldoende</th><th>Goed</th><th></th></tr>
                            </thead>
                            <tbody id="rubric-rows">
                                <?php foreach ($rubricRows as $row): ?>
                                    <tr>
                                        <td><input type="text" name="aspect_naam[]" value="<?= htmlspecialchars($row['naam']) ?>" placeholder="Bijv. Dader" maxlength="<?= RUBRIC_NAME_MAX_LENGTH ?>"></td>
                                        <td><select name="aspect_verplicht[]"><option value="1" <?= !empty($row['verplicht']) ? 'selected' : '' ?>>ja</option><option value="0" <?= empty($row['verplicht']) ? 'selected' : '' ?>>nee</option></select></td>
                                        <td><textarea name="aspect_onvoldoende[]" placeholder="Geen of een verkeerde persoon"><?= htmlspecialchars($row['onvoldoende']) ?></textarea></td>
                                        <td><textarea name="aspect_voldoende[]" placeholder="De conciërge, zonder naam"><?= htmlspecialchars($row['voldoende']) ?></textarea></td>
                                        <td><textarea name="aspect_goed[]" placeholder="Jan de Vries (de conciërge)"><?= htmlspecialchars($row['goed']) ?></textarea></td>
                                        <td><button type="button" class="btn btn-danger" style="padding: 4px 8px;" title="Aspect verwijderen" onclick="removeRubricRow(this)">✕</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-outline" style="margin-top: 8px;" onclick="addRubricRow()">➕ Aspect toevoegen</button>
                    <div class="rubric-list">
                        <label>Ook goed (varianten en synoniemen, één per regel)</label>
                        <textarea name="rubric_ook_goed" rows="2" placeholder="de conciërge&#10;meneer De Vries"><?= $listField('ook_goed') ?></textarea>
                        <label>Veelgemaakte fouten (één per regel)</label>
                        <textarea name="rubric_fouten" rows="2" placeholder="De tuinman: alibi bevestigd op camera 3"><?= $listField('veelgemaakte_fouten') ?></textarea>
                        <label>Verduidelijkingen van de vraag (mag de AI aan teams geven; niets dat het antwoord dichterbij brengt)</label>
                        <textarea name="rubric_verduidelijkingen" rows="2" placeholder="Met 'motief' bedoelen we: waarom deed de dader het?"><?= $listField('verduidelijkingen_vraag') ?></textarea>
                        <label>Niveauregel</label>
                        <input type="text" name="rubric_niveau_regel" value="<?= htmlspecialchars($rubric['niveau_regel'] ?? '') ?>" placeholder="<?= htmlspecialchars(RUBRIC_DEFAULT_RULE) ?>">
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                        <button type="submit" name="action" value="save_rubric" class="btn btn-outline">💾 Opslaan als concept</button>
                        <button type="submit" name="action" value="approve_rubric" class="btn btn-primary">✅ Rubric goedkeuren</button>
                    </div>
                </form>
                <template id="rubric-row-template">
                    <tr>
                        <td><input type="text" name="aspect_naam[]" placeholder="Aspect" maxlength="<?= RUBRIC_NAME_MAX_LENGTH ?>"></td>
                        <td><select name="aspect_verplicht[]"><option value="1">ja</option><option value="0" selected>nee</option></select></td>
                        <td><textarea name="aspect_onvoldoende[]"></textarea></td>
                        <td><textarea name="aspect_voldoende[]"></textarea></td>
                        <td><textarea name="aspect_goed[]"></textarea></td>
                        <td><button type="button" class="btn btn-danger" style="padding: 4px 8px;" title="Aspect verwijderen" onclick="removeRubricRow(this)">✕</button></td>
                    </tr>
                </template>
            </div>
            <?php endif; ?>
        </div>

        <div class="main-panel">
            <div class="card">
                <div class="card-title">📂 Bestaande Opdrachten</div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Titel</th>
                            <th>AI</th>
                            <th style="width: 100px;">Bestand</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars($a['assignment_number']) ?></td>
                                <td>
                                    <a href="javascript:void(0)" class="clickable-title" onclick='openViewModal(<?= json_encode(['title' => $a['title'], 'description' => $a['description'], 'instruction' => $a['instruction'], 'criteria' => $a['criteria']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>
                                        <strong><?= htmlspecialchars($a['title']) ?></strong>
                                    </a>
                                </td>
                                <td>
                                    <?= rubricStatusBadge($a['ai_rubric_status'] ?: 'none') ?>
                                    <?php if ((int)$a['ai_auto_allowed'] === 0): ?><span title="Altijd door een mens" style="font-size: 0.85em;">👤</span><?php endif; ?>
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
                            <tr><td colspan="5" style="text-align: center; color: #999; padding: 30px;">Nog geen opdrachten in dit spel.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($copySources): ?>
            <div class="card">
                <div class="card-title">📋 Opdrachten kopiëren uit een ander spel</div>
                <p style="color: #888; font-size: 0.9em; margin: 10px 0 15px;">De opdrachten, hun bestanden en rubrics worden gekopieerd; de nummering loopt door na nummer <?= $nextNumber - 1 ?>. Daarna staan beide spellen los van elkaar.</p>
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
$editId = $edit_assignment ? (int)$edit_assignment['id'] : 0;
$rubricVersionJs = json_encode($edit_assignment ? rubricVersion($edit_assignment) : '');
$designStatusJs = json_encode($designStatus);
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

    // Rubric-tabel: rijen toevoegen en verwijderen (een lege rij wordt bij opslaan overgeslagen)
    let rubricDirty = false;
    function addRubricRow() {
        const row = document.getElementById('rubric-row-template').content.cloneNode(true);
        document.getElementById('rubric-rows').appendChild(row);
        rubricDirty = true;
    }
    function removeRubricRow(btn) {
        const rows = document.getElementById('rubric-rows');
        btn.closest('tr').remove();
        if (!rows.children.length) addRubricRow();
        rubricDirty = true;
    }
    const rubricForm = document.getElementById('rubric-form');
    if (rubricForm) rubricForm.addEventListener('input', () => { rubricDirty = true; });

    // AI-paneel live bijwerken zolang de AI bezig is of op een antwoord wacht
    const editId = $editId;
    let rubricVersion = $rubricVersionJs;
    let designStatus = $designStatusJs;
    function updateAiPanel() {
        if (!editId) return;
        fetch('assignments.php?edit=' + editId + '&ajax=ai')
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data) return;
                document.getElementById('ai-status').textContent = data.status_text;
                document.getElementById('ai-dialog').innerHTML = data.dialog_html;
                document.getElementById('rubric-badge').innerHTML = data.rubric_badge;
                document.getElementById('ai-answer-form').style.display = data.design_status === 'questions' ? '' : 'none';
                document.getElementById('ai-request-form').style.display = ['idle', 'failed'].includes(data.design_status) ? '' : 'none';
                if (data.rubric_version !== rubricVersion) {
                    // Nieuw voorstel: zonder eigen wijzigingen meteen tonen, anders eerst vragen
                    if (!rubricDirty) { location.hash = 'rubric'; location.reload(); return; }
                    document.getElementById('rubric-notice').style.display = 'block';
                    rubricVersion = data.rubric_version;
                }
                designStatus = data.design_status;
            });
    }
    if (editId) setInterval(() => { if (designStatus === 'pending' || designStatus === 'questions') updateAiPanel(); }, 5000);
</script>";
include 'admin_footer.php'; ?>
