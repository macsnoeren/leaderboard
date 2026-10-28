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

// Download van de bijlage voor operators (de map artifacts/ is van buitenaf dicht)
if (isset($_GET['download'])) {
    $assignment = findAssignmentInGame($db, (int)$_GET['download'], $gameId);
    $path = $assignment ? artifactPath($assignment) : null;
    if (!$path) {
        http_response_code(404);
        exit('Bijlage niet gevonden.');
    }
    session_write_close();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: ' . attachmentDisposition(artifactDisplayName($assignment)));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
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

const DESIGN_STATUS_TEXT = [
    'idle' => '',
    'pending' => 'De AI kijkt mee… dit duurt meestal minder dan een minuut.',
    'questions' => 'De AI heeft vragen. Beantwoord ze hieronder; daarna kijkt ze opnieuw.',
    'failed' => 'De AI kon deze opdracht niet bekijken (model niet bereikbaar?). Probeer het opnieuw.',
];

// Dialoog van de opdracht-assistent als HTML (ook voor de live update)
function renderDialog(array $dialog): string {
    if (!$dialog) {
        return '<p class="muted text-sm">Nog geen vragen van de AI.</p>';
    }
    $html = '<div class="chat">';
    foreach ($dialog as $d) {
        $ai = $d['role'] === 'ai';
        $html .= '<div class="bubble ' . ($ai ? 'bubble-suggestion' : 'bubble-teacher') . '"><div class="bubble-meta">' . ($ai ? icon('bot') . '<strong>AI</strong>' : '<strong>Operator</strong>')
            . '<time>' . htmlspecialchars(formatDateTime($d['created_at'])) . '</time></div>'
            . '<div>' . nl2br(htmlspecialchars($d['message'])) . '</div></div>';
    }
    return $html . '</div>';
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

// Nieuwe volgorde na slepen of de pijltjes: POST assignments.php?ajax=reorder met order[] = id's.
// Bij een fout laadt de pagina opnieuw en toont de melding.
if (($_GET['ajax'] ?? '') === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = verifyCsrf() ? reorderAssignments($db, $gameId, (array)($_POST['order'] ?? []), $userId) : CSRF_ERROR;
    if ($error) {
        flash('error', $error);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => $error === null]);
    exit;
}

// Bijlage uploaden in stukken, met voortgang (zo maakt upload_max_filesize niet uit):
// POST ?ajax=upload_start (name, size, assignment_id) → id en stukgrootte; daarna POST ?ajax=upload_chunk&id=…&offset=…
// met het stuk als body en de CSRF-token in X-CSRF-Token. Het formulier stuurt daarna upload_id mee.
if (in_array($_GET['ajax'] ?? '', ['upload_start', 'upload_chunk'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploads = $_SESSION['attachment_uploads'] ?? [];
    if (!verifyCsrf()) {
        $result = ['error' => CSRF_ERROR];
    } elseif ($_GET['ajax'] === 'upload_start') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        if ($assignmentId && !findAssignmentInGame($db, $assignmentId, $gameId)) {
            $result = ['error' => 'Deze opdracht hoort niet bij het actieve spel.'];
        } else {
            $result = startAttachmentUpload($db, $uploads, $gameId, (string)($_POST['name'] ?? ''), (int)($_POST['size'] ?? 0), $assignmentId ?: null);
        }
    } else {
        // Een stuk verandert de sessie niet: niet op slot houden tijdens het ontvangen
        session_write_close();
        $result = appendAttachmentChunk($uploads, (string)($_GET['id'] ?? ''), $gameId, (int)($_GET['offset'] ?? -1), fopen('php://input', 'rb'));
    }
    $_SESSION['attachment_uploads'] = $uploads;
    header('Content-Type: application/json');
    echo json_encode(['ok' => !isset($result['error'])] + $result);
    exit;
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

// Groter dan post_max_size (formulier zonder JavaScript): PHP gooit dan het hele formulier weg, ook de CSRF-token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('error', 'Het bestand is te groot om in één keer te uploaden (maximaal ' . formatBytes(phpUploadLimitBytes()) . '). Met JavaScript aan uploadt de pagina grote bestanden in stukken.');
    redirect('assignments.php' . ($edit_assignment ? '?edit=' . (int)$edit_assignment['id'] : ''));
}

// Handle toevoegen van assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('assignments.php');
    }

    if ($_POST['action'] === 'add_assignment' || $_POST['action'] === 'edit_assignment') {
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
        $artifact_name = null;
        $existing = null;
        if ($id) {
            $existing = findAssignmentInGame($db, $id, $gameId);
            if (!$existing) {
                flash('error', 'Deze opdracht hoort niet bij het actieve spel.');
                redirect('assignments.php');
            }
            $artifact_file = $existing['artifact_file'];
            $artifact_name = $existing['artifact_name'] ?? null;
            $assignment_number = (int)$existing['assignment_number'];
        } else {
            // Een nieuwe opdracht komt achteraan; de volgorde wijzig je in de lijst
            $stmt = $db->prepare("SELECT COALESCE(MAX(assignment_number), 0) + 1 FROM assignments WHERE game_id = ?");
            $stmt->execute([$gameId]);
            $assignment_number = (int)$stmt->fetchColumn();
        }

        // Bijlage: in stukken geüpload (upload_id, met JavaScript) of als gewoon formulierveld
        $incoming = null;
        if (!empty($_POST['upload_id'])) {
            $uploads = $_SESSION['attachment_uploads'] ?? [];
            $incoming = takeAttachmentUpload($db, $uploads, (string)$_POST['upload_id'], $gameId, $id);
            $_SESSION['attachment_uploads'] = $uploads;
        } elseif (!empty($_FILES['artifact_file']['name'])) {
            $file = $_FILES['artifact_file'];
            $fileName = cleanFileName((string)$file['name']);
            $uploadError = [
                UPLOAD_ERR_OK => null,
                UPLOAD_ERR_INI_SIZE => 'Het bestand is te groot om in één keer te uploaden (maximaal ' . formatBytes(phpUploadLimitBytes()) . '). Met JavaScript aan uploadt de pagina grote bestanden in stukken.',
                UPLOAD_ERR_PARTIAL => 'Het bestand is maar half aangekomen. Probeer het opnieuw.',
            ];
            $error = array_key_exists($file['error'], $uploadError) ? $uploadError[$file['error']] : 'Het uploaden van het bestand is mislukt.';
            $incoming = $error !== null ? ['error' => $error]
                : ['path' => $file['tmp_name'], 'name' => $fileName, 'ext' => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), 'form' => true];
            // Beveiliging: Whitelist extensies tegen RCE
            if (!isset($incoming['error']) && !in_array($incoming['ext'], ARTIFACT_EXTENSIONS, true)) {
                $incoming = ['error' => 'Bestandstype niet toegestaan.'];
            }
            if (!isset($incoming['error']) && ($sizeError = attachmentSizeError($db, $gameId, (int)$file['size'], $id))) {
                $incoming = ['error' => $sizeError];
            }
        }
        if (isset($incoming['error'])) {
            flash('error', $incoming['error']);
            redirect($back);
        }

        if ($incoming) {
            // Veilig bestandsnaam genereren, met het spel erin
            $safeName = 'game_' . $gameId . '_assignment_' . $assignment_number . '_' . time() . '.' . $incoming['ext'];
            $targetPath = $uploadDir . '/' . $safeName;

            // rename() kopieert zelf als storage/ op een ander volume staat dan artifacts/ (Docker)
            if (!empty($incoming['form']) ? move_uploaded_file($incoming['path'], $targetPath) : @rename($incoming['path'], $targetPath)) {
                // Verwijder oud bestand nu het nieuwe er staat
                $old = $artifact_file;
                $artifact_file = 'artifacts/' . $safeName;
                // De oorspronkelijke naam is alleen voor de operator; op de server staat de eigen naam
                $artifact_name = $incoming['name'] ?: null;
                if ($id && $old) {
                    $db->prepare("UPDATE assignments SET artifact_file = ?, artifact_name = ? WHERE id = ?")->execute([$artifact_file, $artifact_name, $id]);
                    removeArtifact($db, $old);
                }
            } else {
                if (empty($incoming['form'])) {
                    @unlink($incoming['path']);
                }
                flash('error', 'Het opslaan van de bijlage is mislukt.');
                redirect($back);
            }
        }

        if ($_POST['action'] === 'edit_assignment' && $id) {
            // Een goedgekeurde rubric past misschien niet meer bij een gewijzigde instructie of antwoord
            $rubricStatus = rubricStatusAfterEdit($existing, ['instruction' => $instruction, 'criteria' => $criteria]);
            $stmt = $db->prepare("UPDATE assignments SET title = ?, description = ?, instruction = ?, criteria = ?, time_limit = ?, artifact_file = ?, artifact_name = ?, ai_auto_allowed = ?, ai_rubric_status = ? WHERE id = ? AND game_id = ?");
            $stmt->execute([$title, $description, $instruction, $criteria, $time_limit, $artifact_file, $artifact_name, $autoAllowed, $rubricStatus, $id, $gameId]);
            logAudit($db, $userId, 'ASSIGNMENT_UPDATE', "Updated assignment #$assignment_number: $title (spel #$gameId)" . ($rubricStatus !== $existing['ai_rubric_status'] ? ', rubric terug naar concept' : ''));
            if ($rubricStatus !== $existing['ai_rubric_status']) {
                flash('error', 'Opdracht gewijzigd, controleer de rubric. De opdracht is niet meer AI-klaar tot je de rubric opnieuw goedkeurt.');
            } else {
                flash('success', 'Opdracht succesvol bijgewerkt!');
            }
            redirect("assignments.php?edit=$id");
        }

        $stmt = $db->prepare("INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, time_limit, artifact_file, artifact_name, ai_auto_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$gameId, $assignment_number, $title, $description, $instruction, $criteria, $time_limit, $artifact_file, $artifact_name, $autoAllowed]);
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

    // Verwijderen van assignment; de opdrachten erna schuiven één op
    if ($_POST['action'] === 'delete_assignment' && isset($_POST['assignment_id'])) {
        $assignment = findAssignmentInGame($db, (int)$_POST['assignment_id'], $gameId);
        $error = deleteAssignment($db, (int)$_POST['assignment_id'], $gameId, $userId);
        if ($error) {
            flash('error', $error);
            redirect('assignments.php');
        }
        removeArtifact($db, $assignment['artifact_file']);
        flash('success', 'De opdracht is verwijderd.');
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
// Opdrachten 1..K liggen vast (teams zijn er al mee bezig geweest); de rest kun je verslepen
$lockLevel = assignmentLockLevel($db, $gameId);
$movableCount = count(array_filter($assignments, fn($a) => (int)$a['assignment_number'] > $lockLevel));
$lockedCount = count($assignments) - $movableCount;
if ($lockedCount === 0) {
    $lockedText = '';
} elseif ($lockedCount === count($assignments)) {
    $lockedText = 'Alle opdrachten liggen vast';
} else {
    $lockedText = $lockedCount === 1 ? 'Opdracht 1 ligt vast' : "Opdrachten 1–$lockedCount liggen vast";
}

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
// Huidige bijlage van de opdracht die bewerkt wordt: naam, grootte (null = ontbreekt op de server) en link
$attachment = null;
if ($edit_assignment && $edit_assignment['artifact_file']) {
    $path = artifactPath($edit_assignment);
    $attachment = [
        'name' => artifactDisplayName($edit_assignment),
        'href' => artifactDownloadUrl($edit_assignment),
        'size' => $path ? (int)filesize($path) : null,
    ];
}
$attachmentsUsed = gameAttachmentBytes($db, $gameId);
$listField = function (string $field) use ($rubric): string {
    return htmlspecialchars(implode("\n", $rubric[$field] ?? []));
};

$extraCSS = '
<style>
    .title-link { background: none; border: none; padding: 0; font: inherit; font-weight: 650; color: var(--text); cursor: pointer; text-align: left; }
    .title-link:hover { color: var(--brand); text-decoration: underline; }
    .ai-status { margin-bottom: 12px; }
    .ai-status:empty { display: none; }
    #rubric-notice { display: none; }
    .rubric-table { min-width: 640px; }
    .rubric-table th { white-space: normal; }
    .rubric-table td { vertical-align: top; }
    .rubric-table textarea, .rubric-table input, .rubric-table select { padding: 6px 8px; font-size: 13px; }
    .rubric-table textarea { min-height: 58px; }
    .rubric-table .col-name { width: 18%; }
    .rubric-table .col-req { width: 90px; }
    .rubric-table .col-del { width: 44px; }
    .col-onv { border-top: 3px solid var(--danger); }
    .col-vol { border-top: 3px solid var(--warning); }
    .col-goed { border-top: 3px solid var(--success); }
    .dialog-box { max-height: 360px; overflow-y: auto; margin-bottom: 4px; }
    .dialog-box .bubble { font-size: 14px; }
    .order-cell { white-space: nowrap; }
    .order-cell > span { vertical-align: middle; }
    .order-cell .icon { color: var(--text-muted); }
    .order-arrows { display: inline-flex; flex-direction: column; margin-left: 2px; }
    .order-arrows .btn { width: 22px; height: 18px; padding: 0; }
    .assignment-table td { padding-top: 10px; padding-bottom: 10px; }
    .order-arrows .btn:hover .icon, .order-arrows .btn:focus-visible .icon { color: var(--text); }
    .drag-handle { cursor: grab; touch-action: none; }
    .drag-handle:hover .icon { color: var(--text); }
    #assignment-rows tr.dragging td { opacity: .5; background: var(--brand-soft); }
    .order-hint { margin-bottom: 12px; }
    .attachment { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 12px; margin-bottom: 12px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--surface-2); }
    .attachment > .icon { color: var(--text-muted); }
    .attachment-name { font-weight: 600; overflow-wrap: anywhere; }
    .attachment .btn { margin-left: auto; }
    .attachment-hint { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .upload-progress { margin-top: 10px; }
    .upload-bar { height: 8px; border-radius: var(--radius-pill); background: var(--surface-2); overflow: hidden; }
    .upload-bar span { display: block; width: 0; height: 100%; background: var(--brand); transition: width var(--t) var(--ease); }
    .upload-status { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 6px; }
    #upload-error { margin: 10px 0 0; }
    @media (prefers-reduced-motion: reduce) { .upload-bar span { transition: none; } }
</style>';

$pageTitle = 'Opdrachten';
include 'admin_header.php';
?>
    <?= pageHeader('Opdrachten', gameSubtitle($game), $edit_assignment ? '<a href="assignments.php" class="btn btn-secondary">' . icon('plus') . 'Nieuwe opdracht</a>' : '') ?>

    <?= renderFlash() ?>

    <div class="grid-main">
        <div>
            <div class="card">
                <div class="card-title"><?= icon($edit_assignment ? 'pencil' : 'plus') ?><?= $edit_assignment ? 'Opdracht <span data-number-of="' . (int)$edit_assignment['id'] . '">' . (int)$edit_assignment['assignment_number'] . '</span> bewerken' : 'Nieuwe opdracht' ?></div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $edit_assignment ? 'edit_assignment' : 'add_assignment' ?>">
                    <?php if ($edit_assignment): ?>
                        <input type="hidden" name="assignment_id" value="<?= $edit_assignment['id'] ?>">
                    <?php endif; ?>

                    <div class="field-row">
                        <div class="field">
                            <label for="f-title">Titel</label>
                            <input type="text" name="title" id="f-title" value="<?= htmlspecialchars($edit_assignment['title'] ?? '') ?>" required>
                            <?php if (!$edit_assignment): ?><div class="hint">Komt achteraan als opdracht <?= $nextNumber ?>. De volgorde wijzig je in de lijst.</div><?php endif; ?>
                        </div>
                        <div class="field">
                            <label for="f-time">Verwachte tijd (minuten)</label>
                            <input type="number" name="time_limit" id="f-time" value="<?= $edit_assignment['time_limit'] ?? '' ?>" placeholder="0 = niet tonen">
                        </div>
                    </div>
                    <div class="field">
                        <label for="f-desc">Beschrijving</label>
                        <textarea name="description" id="f-desc" rows="4"><?= htmlspecialchars($edit_assignment['description'] ?? '') ?></textarea>
                        <div class="hint">Markdown. Dit is het verhaal dat het team als dossier ziet.</div>
                    </div>
                    <div class="field">
                        <label for="f-instr">Instructie (de echte opdracht)</label>
                        <textarea name="instruction" id="f-instr" rows="4" placeholder="Wat moeten ze precies doen?"><?= htmlspecialchars($edit_assignment['instruction'] ?? '') ?></textarea>
                    </div>
                    <div class="field">
                        <label for="f-crit">Beoordelingscriteria (het antwoord)</label>
                        <textarea name="criteria" id="f-crit" rows="3" placeholder="Waar moet het antwoord aan voldoen?"><?= htmlspecialchars($edit_assignment['criteria'] ?? '') ?></textarea>
                        <div class="hint">Alleen voor operators en de AI; teams zien dit nooit.</div>
                    </div>
                    <div class="field">
                        <?php if ($attachment): ?>
                            <span class="field-label">Bijlage</span>
                            <div class="attachment">
                                <?= icon('file') ?>
                                <span class="attachment-name"><?= htmlspecialchars($attachment['name']) ?></span>
                                <?php if ($attachment['size'] !== null): ?>
                                    <span class="muted small"><?= formatBytes($attachment['size']) ?></span>
                                    <a href="<?= htmlspecialchars($attachment['href']) ?>" class="btn btn-secondary btn-sm"><?= icon('download') ?>Downloaden</a>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?= icon('alert') ?>ontbreekt op de server</span>
                                <?php endif; ?>
                            </div>
                            <label for="f-file">Vervangen door een ander bestand (PDF, ZIP, TXT, JPG of PNG)</label>
                        <?php else: ?>
                            <label for="f-file">Bestand (PDF, ZIP, TXT, JPG of PNG)</label>
                        <?php endif; ?>
                        <input type="file" name="artifact_file" id="f-file" accept=".pdf,.zip,.txt,.jpg,.png">
                        <input type="hidden" name="upload_id" id="f-upload-id" value="">
                        <?php if ($attachment): ?><div class="hint">Leeg laten = de huidige bijlage behouden. Teams krijgen het bestand onder een eigen naam, niet onder deze.</div><?php endif; ?>
                        <div class="hint">Max. <?= formatBytes(maxAttachmentBytes()) ?> per bestand. Alle bijlagen van dit spel samen max. <?= formatBytes(maxGameAttachmentsBytes()) ?> (nu <?= formatBytes($attachmentsUsed) ?>).</div>
                        <div class="upload-progress" id="upload-progress" hidden>
                            <div class="upload-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Voortgang van de upload"><span></span></div>
                            <div class="upload-status">
                                <span class="small" id="upload-text" aria-live="polite"></span>
                                <button type="button" class="btn btn-ghost btn-sm" id="upload-cancel"><?= icon('x') ?>Afbreken</button>
                            </div>
                        </div>
                        <div class="alert alert-error" id="upload-error" role="alert" hidden><?= icon('alert-circle') ?><div id="upload-error-text"></div></div>
                    </div>
                    <label class="check field">
                        <input type="checkbox" name="ai_human_only" value="1" <?= $edit_assignment && (int)$edit_assignment['ai_auto_allowed'] === 0 ? 'checked' : '' ?>>
                        <span>Altijd door een mens: de AI zet bij deze opdracht nooit zelf een level omhoog</span>
                    </label>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary"><?= icon('save') ?><?= $edit_assignment ? 'Opslaan' : 'Toevoegen' ?></button>
                        <?php if ($edit_assignment): ?>
                            <a href="assignments.php" class="btn btn-secondary">Annuleren</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if ($edit_assignment): ?>
            <div class="card" id="ai-panel">
                <div class="card-title"><?= icon('bot') ?>AI-assistent</div>
                <p class="muted text-sm" style="margin-bottom: 12px;">De AI leest de opdracht en je antwoord mee, stelt vragen als iets niet eenduidig is en stelt daarna een rubric voor. Je antwoord (de criteria) blijft altijd zoals jij het schreef. De bijlage leest de AI niet: ze vraagt naar feiten die alleen daar staan.</p>
                <div class="ai-status alert alert-info" id="ai-status" role="status"><?= htmlspecialchars(DESIGN_STATUS_TEXT[$designStatus] ?? '') ?></div>
                <div class="dialog-box" id="ai-dialog"><?= renderDialog(assignmentDialog($db, (int)$edit_assignment['id'])) ?></div>
                <form method="POST" id="ai-answer-form" style="margin-top: 12px;"<?= $designStatus === 'questions' ? '' : ' hidden' ?>>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ai_answer">
                    <input type="hidden" name="assignment_id" value="<?= (int)$edit_assignment['id'] ?>">
                    <label for="ai-answer" class="sr-only">Je antwoord</label>
                    <textarea name="answer" id="ai-answer" rows="3" placeholder="Je antwoord op de vragen van de AI…" required></textarea>
                    <div class="card-actions"><button type="submit" class="btn btn-primary"><?= icon('send') ?>Antwoord versturen</button></div>
                </form>
                <form method="POST" id="ai-request-form" class="card-actions"<?= in_array($designStatus, ['idle', 'failed'], true) ? '' : ' hidden' ?>>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ai_request">
                    <input type="hidden" name="assignment_id" value="<?= (int)$edit_assignment['id'] ?>">
                    <button type="submit" class="btn btn-primary" <?= $canRequestAi ? '' : 'disabled' ?>><?= icon('bot') ?>Laat de AI meekijken</button>
                    <?php if (!$canRequestAi): ?><span class="muted small">Vul eerst de instructie en de criteria in en sla op.</span><?php endif; ?>
                </form>
            </div>

            <div class="card" id="rubric">
                <div class="card-header">
                    <div class="card-title"><?= icon('clipboard') ?>Rubric</div>
                    <span id="rubric-badge"><?= rubricStatusBadge($edit_assignment['ai_rubric_status'] ?: 'none') ?></span>
                </div>
                <p class="muted text-sm" style="margin-bottom: 14px;">Hiermee beoordeelt de AI de antwoorden van teams. Zonder goedgekeurde rubric doet de AI alleen suggesties aan de operator.</p>
                <div class="alert alert-info" id="rubric-notice" role="status"><?= icon('info') ?><div>Er is een nieuw rubric-voorstel. <a href="assignments.php?edit=<?= (int)$edit_assignment['id'] ?>#rubric"><strong>Herlaad</strong></a> om het te zien (je niet-opgeslagen wijzigingen gaan dan verloren).</div></div>
                <form method="POST" id="rubric-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="assignment_id" value="<?= (int)$edit_assignment['id'] ?>">
                    <div class="field">
                        <label for="r-kern">Kern: wat moet het team in elk geval geven?</label>
                        <textarea name="rubric_kern" id="r-kern" rows="2" placeholder="Bijv. Het team moet de dader én het motief noemen."><?= htmlspecialchars($rubric['kern'] ?? '') ?></textarea>
                    </div>
                    <div class="table-scroll">
                        <table class="table table-compact rubric-table">
                            <thead>
                                <tr><th class="col-name">Aspect</th><th class="col-req">Verplicht</th><th class="col-onv">Onvoldoende</th><th class="col-vol">Voldoende</th><th class="col-goed">Goed</th><th class="col-del"><span class="sr-only">Verwijderen</span></th></tr>
                            </thead>
                            <tbody id="rubric-rows">
                                <?php foreach ($rubricRows as $row): ?>
                                    <tr>
                                        <td><input type="text" name="aspect_naam[]" value="<?= htmlspecialchars($row['naam']) ?>" placeholder="Bijv. Dader" maxlength="<?= RUBRIC_NAME_MAX_LENGTH ?>" aria-label="Aspect"></td>
                                        <td><select name="aspect_verplicht[]" aria-label="Verplicht"><option value="1" <?= !empty($row['verplicht']) ? 'selected' : '' ?>>ja</option><option value="0" <?= empty($row['verplicht']) ? 'selected' : '' ?>>nee</option></select></td>
                                        <td><textarea name="aspect_onvoldoende[]" placeholder="Geen of een verkeerde persoon" aria-label="Onvoldoende"><?= htmlspecialchars($row['onvoldoende']) ?></textarea></td>
                                        <td><textarea name="aspect_voldoende[]" placeholder="De conciërge, zonder naam" aria-label="Voldoende"><?= htmlspecialchars($row['voldoende']) ?></textarea></td>
                                        <td><textarea name="aspect_goed[]" placeholder="Jan de Vries (de conciërge)" aria-label="Goed"><?= htmlspecialchars($row['goed']) ?></textarea></td>
                                        <td><button type="button" class="btn btn-danger btn-sm btn-icon" title="Aspect verwijderen" aria-label="Aspect verwijderen" onclick="removeRubricRow(this)"><?= icon('x') ?></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-actions" style="margin-top: 8px;"><button type="button" class="btn btn-secondary btn-sm" onclick="addRubricRow()"><?= icon('plus') ?>Aspect toevoegen</button></div>
                    <div class="field-row" style="margin-top: 16px;">
                        <div class="field">
                            <label for="r-ook">Ook goed <span class="muted">(één per regel)</span></label>
                            <textarea name="rubric_ook_goed" id="r-ook" rows="3" placeholder="de conciërge&#10;meneer De Vries"><?= $listField('ook_goed') ?></textarea>
                            <div class="hint">Varianten en synoniemen.</div>
                        </div>
                        <div class="field">
                            <label for="r-fout">Veelgemaakte fouten <span class="muted">(één per regel)</span></label>
                            <textarea name="rubric_fouten" id="r-fout" rows="3" placeholder="De tuinman: alibi bevestigd op camera 3"><?= $listField('veelgemaakte_fouten') ?></textarea>
                        </div>
                    </div>
                    <div class="field">
                        <label for="r-verd">Verduidelijkingen van de vraag <span class="muted">(één per regel)</span></label>
                        <textarea name="rubric_verduidelijkingen" id="r-verd" rows="2" placeholder="Met 'motief' bedoelen we: waarom deed de dader het?"><?= $listField('verduidelijkingen_vraag') ?></textarea>
                        <div class="hint">Mag de AI aan teams geven; niets dat het antwoord dichterbij brengt.</div>
                    </div>
                    <div class="field">
                        <label for="r-regel">Niveauregel</label>
                        <input type="text" name="rubric_niveau_regel" id="r-regel" value="<?= htmlspecialchars($rubric['niveau_regel'] ?? '') ?>" placeholder="<?= htmlspecialchars(RUBRIC_DEFAULT_RULE) ?>">
                    </div>
                    <div class="btn-row">
                        <button type="submit" name="action" value="save_rubric" class="btn btn-secondary"><?= icon('save') ?>Opslaan als concept</button>
                        <button type="submit" name="action" value="approve_rubric" class="btn btn-primary"><?= icon('check') ?>Rubric goedkeuren</button>
                    </div>
                </form>
                <template id="rubric-row-template">
                    <tr>
                        <td><input type="text" name="aspect_naam[]" placeholder="Aspect" maxlength="<?= RUBRIC_NAME_MAX_LENGTH ?>" aria-label="Aspect"></td>
                        <td><select name="aspect_verplicht[]" aria-label="Verplicht"><option value="1">ja</option><option value="0" selected>nee</option></select></td>
                        <td><textarea name="aspect_onvoldoende[]" aria-label="Onvoldoende"></textarea></td>
                        <td><textarea name="aspect_voldoende[]" aria-label="Voldoende"></textarea></td>
                        <td><textarea name="aspect_goed[]" aria-label="Goed"></textarea></td>
                        <td><button type="button" class="btn btn-danger btn-sm btn-icon" title="Aspect verwijderen" aria-label="Aspect verwijderen" onclick="removeRubricRow(this)"><?= icon('x') ?></button></td>
                    </tr>
                </template>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <div class="card-title"><?= icon('book') ?>Opdrachten in dit spel</div>
                <?php if (empty($assignments)): ?>
                    <?= emptyState('book', 'Nog geen opdrachten in dit spel.') ?>
                <?php else: ?>
                <?php if ($movableCount > 1 || $lockedText): ?>
                    <p class="muted text-sm order-hint">
                        <?php if ($movableCount > 1): ?>Sleep een opdracht aan het handvat (<?= icon('grip') ?>) of gebruik de pijltjes ernaast om de volgorde te wijzigen; de nummering past zich aan.<?php endif; ?>
                        <?php if ($lockedText): ?><?= $lockedText ?> (<?= icon('lock') ?>): daar zijn teams al mee bezig (geweest).<?php endif; ?>
                    </p>
                <?php endif; ?>
                <div class="table-scroll">
                    <table class="table table-compact assignment-table">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Titel</th>
                                <th scope="col">AI</th>
                                <th scope="col" class="col-actions">Acties</th>
                            </tr>
                        </thead>
                        <tbody id="assignment-rows" data-csrf="<?= htmlspecialchars(csrfToken()) ?>">
                            <?php foreach ($assignments as $a):
                                $number = (int)$a['assignment_number'];
                                $locked = $number <= $lockLevel;
                            ?>
                                <tr data-id="<?= (int)$a['id'] ?>" data-title="<?= htmlspecialchars($a['title']) ?>"<?= $locked ? ' data-locked="1"' : '' ?><?= $edit_assignment && (int)$edit_assignment['id'] === (int)$a['id'] ? ' style="background: var(--brand-soft);"' : '' ?>>
                                    <td class="order-cell">
                                        <?php if ($locked): ?>
                                            <span title="Ligt vast: teams zijn al met deze opdracht bezig (geweest)"><?= icon('lock') ?><span class="sr-only">vergrendeld,</span></span>
                                        <?php elseif ($movableCount > 1): ?>
                                            <span class="drag-handle" title="Sleep om te verplaatsen"><?= icon('grip') ?></span>
                                        <?php endif; ?>
                                        <span class="mono js-number"><?= $number ?></span>
                                        <?php if (!$locked && $movableCount > 1): ?>
                                            <span class="order-arrows">
                                                <button type="button" class="btn btn-ghost js-up" title="Omhoog" data-label="omhoog" aria-label="Opdracht <?= $number ?> omhoog"><?= icon('chevron-up') ?></button>
                                                <button type="button" class="btn btn-ghost js-down" title="Omlaag" data-label="omlaag" aria-label="Opdracht <?= $number ?> omlaag"><?= icon('chevron-down') ?></button>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="title-link" title="Opdracht bekijken" onclick='openViewModal(this, <?= json_encode(['title' => $a['title'], 'description' => $a['description'], 'instruction' => $a['instruction'], 'criteria' => $a['criteria'], 'file' => $a['artifact_file'] ? artifactDownloadUrl($a) : null, 'file_name' => artifactDisplayName($a)], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><?= htmlspecialchars($a['title']) ?></button>
                                        <?php if ($a['artifact_file']): ?><div class="small muted attachment-hint" title="<?= htmlspecialchars(artifactDisplayName($a)) ?>"><?= icon('paperclip') ?> <?= htmlspecialchars(artifactDisplayName($a)) ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <?= rubricStatusBadge($a['ai_rubric_status'] ?: 'none') ?>
                                        <?php if ((int)$a['ai_auto_allowed'] === 0): ?><span class="badge" title="Altijd door een mens"><?= icon('user') ?>mens</span><?php endif; ?>
                                    </td>
                                    <td class="col-actions">
                                        <a href="?edit=<?= (int)$a['id'] ?>" class="btn btn-secondary btn-sm btn-icon" title="Bewerken" data-label="bewerken" aria-label="Opdracht <?= $number ?> bewerken"><?= icon('pencil') ?></a>
                                        <?php if ($locked): ?>
                                            <span title="Kan niet weg: teams zijn al met deze opdracht bezig (geweest)"><button type="button" class="btn btn-danger btn-sm btn-icon" disabled aria-label="Opdracht <?= $number ?> verwijderen (kan niet: ligt vast)"><?= icon('trash') ?></button></span>
                                        <?php else: ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Verwijder deze opdracht? De opdrachten erna schuiven één plek op.');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_assignment">
                                                <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Verwijderen" data-label="verwijderen" aria-label="Opdracht <?= $number ?> verwijderen"><?= icon('trash') ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="sr-only" role="status" id="order-status"></div>
                <?php endif; ?>
            </div>

            <?php if ($copySources): ?>
            <div class="card">
                <div class="card-title"><?= icon('copy') ?>Kopiëren uit een ander spel</div>
                <p class="muted text-sm" style="margin-bottom: 14px;">De opdrachten, hun bestanden en rubrics worden gekopieerd; de nummering loopt door na nummer <?= $nextNumber - 1 ?>. Daarna staan beide spellen los van elkaar.</p>
                <form method="POST" class="btn-row" onsubmit="return confirm('Opdrachten kopiëren naar dit spel?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="copy_assignments">
                    <select name="from_game_id" required style="flex: 1; width: auto;" aria-label="Spel om uit te kopiëren">
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

    <dialog class="modal" id="view-dialog" aria-labelledby="v-title">
        <div class="modal-header">
            <h2 id="v-title"></h2>
            <button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Sluiten"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <div class="section-label">Beschrijving</div>
            <div id="v-desc" class="markdown" data-md></div>
            <div class="section-label">Instructie (de echte opdracht)</div>
            <div id="v-instr" class="panel markdown" data-md></div>
            <div class="section-label">Beoordelingscriteria (alleen voor operators)</div>
            <div id="v-crit" class="panel panel-danger markdown" data-md></div>
            <div id="v-file-wrap" hidden>
                <div class="section-label">Bijlage</div>
                <a id="v-file" href="#" class="btn btn-secondary btn-sm"><?= icon('download') ?><span id="v-file-name">Bestand downloaden</span></a>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-close>Sluiten</button></div>
    </dialog>

<?php
$editId = $edit_assignment ? (int)$edit_assignment['id'] : 0;
$rubricVersionJs = json_encode($edit_assignment ? rubricVersion($edit_assignment) : '');
$designStatusJs = json_encode($designStatus);
$extraJS = "
<script>
    function openViewModal(btn, assignment) {
        const dialog = document.getElementById('view-dialog');
        // Het nummer uit de rij: dat verandert na slepen
        const number = btn.closest('tr').querySelector('.js-number').textContent;
        document.getElementById('v-title').textContent = 'Opdracht ' + number + ': ' + assignment.title;
        const fields = { 'v-desc': assignment.description || 'Geen beschrijving.', 'v-instr': assignment.instruction || 'Geen instructie.', 'v-crit': assignment.criteria || 'Geen criteria opgegeven.' };
        Object.keys(fields).forEach(id => {
            const el = document.getElementById(id);
            el.textContent = fields[id];
            el.removeAttribute('data-rendered');
        });
        document.getElementById('v-file-wrap').hidden = !assignment.file;
        if (assignment.file) {
            const link = document.getElementById('v-file');
            link.href = assignment.file;
            document.getElementById('v-file-name').textContent = assignment.file_name;
        }
        window.renderMarkdown(dialog);
        dialog.showModal();
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
                document.getElementById('ai-answer-form').hidden = data.design_status !== 'questions';
                document.getElementById('ai-request-form').hidden = !['idle', 'failed'].includes(data.design_status);
                if (data.rubric_version !== rubricVersion) {
                    // Nieuw voorstel: zonder eigen wijzigingen meteen tonen, anders eerst vragen
                    if (!rubricDirty) { location.hash = 'rubric'; location.reload(); return; }
                    document.getElementById('rubric-notice').style.display = 'flex';
                    rubricVersion = data.rubric_version;
                }
                designStatus = data.design_status;
            });
    }
    if (editId) setInterval(() => { if (designStatus === 'pending' || designStatus === 'questions') updateAiPanel(); }, 5000);
</script>";
$extraJS .= <<<'JS'
<script>
    // Volgorde van de opdrachten: slepen aan het handvat of de pijltjes (toetsenbord, touch).
    // Elke wijziging wordt meteen opgeslagen; vergrendelde rijen staan bovenaan en bewegen niet.
    (function () {
        const body = document.getElementById('assignment-rows');
        if (!body) return;
        const status = document.getElementById('order-status');
        const rows = () => Array.from(body.querySelectorAll('tr[data-id]'));
        const order = () => rows().map(r => r.dataset.id).join(',');
        let dragRow = null;
        let orderBefore = '';

        function updateButtons() {
            const movable = rows().filter(r => !r.dataset.locked);
            movable.forEach((row, i) => {
                const up = row.querySelector('.js-up'), down = row.querySelector('.js-down');
                if (up) up.disabled = i === 0;
                if (down) down.disabled = i === movable.length - 1;
            });
        }

        function renumber() {
            rows().forEach((row, i) => {
                const n = i + 1;
                row.querySelector('.js-number').textContent = n;
                row.querySelectorAll('[data-label]').forEach(el => el.setAttribute('aria-label', 'Opdracht ' + n + ' ' + el.dataset.label));
                document.querySelectorAll('[data-number-of="' + row.dataset.id + '"]').forEach(el => { el.textContent = n; });
            });
            updateButtons();
        }

        // Bij een fout zet de server een melding klaar; herladen toont die en de echte volgorde
        function save(row) {
            renumber();
            const data = new FormData();
            data.append('csrf_token', body.dataset.csrf);
            rows().forEach(r => data.append('order[]', r.dataset.id));
            fetch('assignments.php?ajax=reorder', { method: 'POST', body: data })
                .then(r => r.ok ? r.json() : null)
                .then(res => {
                    if (!res || !res.ok) { location.reload(); return; }
                    status.textContent = '“' + row.dataset.title + '” is nu opdracht ' + (rows().indexOf(row) + 1) + '.';
                })
                .catch(() => location.reload());
        }

        body.addEventListener('click', e => {
            const btn = e.target.closest('.js-up, .js-down');
            if (!btn) return;
            const row = btn.closest('tr');
            const up = btn.classList.contains('js-up');
            const other = up ? row.previousElementSibling : row.nextElementSibling;
            if (!other || other.dataset.locked) return;
            body.insertBefore(row, up ? other : other.nextElementSibling);
            save(row);
            // Focus blijft op de knop; is die nu uitgeschakeld (bovenaan/onderaan), dan op de andere
            (btn.disabled ? row.querySelector(up ? '.js-down' : '.js-up') : btn).focus();
        });

        // Alleen het handvat maakt een rij versleepbaar, zodat tekst en knoppen gewoon werken
        body.addEventListener('pointerdown', e => {
            const handle = e.target.closest('.drag-handle');
            if (handle) handle.closest('tr').draggable = true;
        });
        body.addEventListener('pointerup', () => rows().forEach(r => { r.draggable = false; }));

        body.addEventListener('dragstart', e => {
            const row = e.target.closest('tr[data-id]');
            if (!row || row.dataset.locked) { e.preventDefault(); return; }
            dragRow = row;
            orderBefore = order();
            row.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', row.dataset.id);
        });
        body.addEventListener('dragover', e => {
            if (!dragRow) return;
            const target = e.target.closest('tr[data-id]');
            if (!target || target.dataset.locked) return;
            e.preventDefault();
            if (target === dragRow) return;
            const box = target.getBoundingClientRect();
            body.insertBefore(dragRow, e.clientY > box.top + box.height / 2 ? target.nextElementSibling : target);
        });
        body.addEventListener('drop', e => { if (dragRow) e.preventDefault(); });
        body.addEventListener('dragend', () => {
            if (!dragRow) return;
            const row = dragRow;
            dragRow = null;
            row.classList.remove('dragging');
            row.draggable = false;
            if (order() !== orderBefore) save(row);
        });

        updateButtons();
    })();

    // Bijlage in stukken uploaden met voortgang; daarna gaat het formulier gewoon verder met upload_id.
    // Zonder JavaScript gaat het bestand als gewoon formulierveld mee (dan geldt upload_max_filesize).
    (function () {
        const input = document.getElementById('f-file');
        if (!input || !window.fetch || !window.AbortController) return;
        const form = input.form;
        const csrf = form.querySelector('[name="csrf_token"]').value;
        const assignment = form.querySelector('[name="assignment_id"]');
        const uploadId = document.getElementById('f-upload-id');
        const box = document.getElementById('upload-progress');
        const bar = box.querySelector('.upload-bar');
        const text = document.getElementById('upload-text');
        const errorBox = document.getElementById('upload-error');
        const buttons = form.querySelectorAll('button[type="submit"]');
        let controller = null;

        const mb = bytes => (bytes / 1048576).toLocaleString('nl-NL', { maximumFractionDigits: 1 }) + ' MB';
        const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

        function show(done, total) {
            const pct = total ? Math.floor(done * 100 / total) : 0;
            bar.firstElementChild.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', pct);
            text.textContent = mb(done) + ' van ' + mb(total) + ' (' + pct + '%)';
        }

        function setBusy(busy) {
            buttons.forEach(b => { b.disabled = busy; });
            input.disabled = busy;
            box.hidden = !busy;
        }

        // POST, met nieuwe pogingen bij netwerk- en serverfouten: een stuk opnieuw sturen kan veilig
        async function post(url, body, headers) {
            for (let attempt = 1; ; attempt++) {
                let response = null;
                try {
                    response = await fetch(url, { method: 'POST', body: body, headers: headers || {}, signal: controller.signal });
                } catch (e) {
                    if (e.name === 'AbortError') throw new Error('De upload is afgebroken.');
                }
                if (response && response.ok) return response.json().catch(() => { throw new Error('Onverwacht antwoord van de server. Probeer het opnieuw.'); });
                if (response && (response.status === 401 || response.status === 403)) throw new Error('Je sessie is verlopen of je hebt geen toegang meer. Laad de pagina opnieuw.');
                if (response && response.status === 413) throw new Error('De webserver weigert stukken van deze grootte (413). Vraag de beheerder de limiet te verhogen.');
                if (attempt >= 4) throw new Error('De verbinding met de server is verbroken. Probeer het opnieuw.');
                await wait(1000 * attempt);
            }
        }

        async function upload(file) {
            const start = new FormData();
            start.append('csrf_token', csrf);
            start.append('name', file.name);
            start.append('size', file.size);
            if (assignment) start.append('assignment_id', assignment.value);
            let res = await post('assignments.php?ajax=upload_start', start);
            if (!res.ok) throw new Error(res.error);
            const id = res.id, chunk = res.chunk;
            let received = 0, stuck = 0;
            show(0, file.size);
            while (received < file.size) {
                res = await post('assignments.php?ajax=upload_chunk&id=' + id + '&offset=' + received, file.slice(received, received + chunk),
                                 { 'X-CSRF-Token': csrf, 'Content-Type': 'application/octet-stream' });
                if (!res.ok) throw new Error(res.error);
                stuck = res.received > received ? 0 : stuck + 1;
                if (stuck >= 3) throw new Error('De server neemt de bijlage niet aan. Probeer het opnieuw.');
                received = res.received;
                show(received, file.size);
            }
            return id;
        }

        form.addEventListener('submit', async e => {
            const file = input.files[0];
            if (!file || uploadId.value) return;
            e.preventDefault();
            if (controller) return;
            controller = new AbortController();
            errorBox.hidden = true;
            setBusy(true);
            try {
                uploadId.value = await upload(file);
                text.textContent = 'Geüpload; de opdracht wordt opgeslagen…';
                document.getElementById('upload-cancel').disabled = true;
                // input blijft disabled, dus het bestand gaat niet nog een keer mee
                form.submit();
            } catch (err) {
                controller = null;
                setBusy(false);
                document.getElementById('upload-error-text').textContent = err.message;
                errorBox.hidden = false;
            }
        });

        document.getElementById('upload-cancel').addEventListener('click', () => { if (controller) controller.abort(); });
        window.addEventListener('beforeunload', e => {
            if (controller && !uploadId.value) e.preventDefault();
        });
    })();
</script>
JS;
include 'admin_footer.php'; ?>
