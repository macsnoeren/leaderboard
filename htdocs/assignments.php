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
        $html .= '<div class="bubble ' . ($ai ? 'bubble-suggestion' : 'bubble-teacher') . '"><div class="bubble-meta">' . ($ai ? icon('bot') . '<strong>AI</strong>' : '<strong>Docent</strong>')
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
                flash('error', 'Het uploaden van het bestand is mislukt.');
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
</style>';

$pageTitle = 'Opdrachten';
include 'admin_header.php';
?>
    <?= pageHeader('Opdrachten', gameSubtitle($game), $edit_assignment ? '<a href="assignments.php" class="btn btn-secondary">' . icon('plus') . 'Nieuwe opdracht</a>' : '') ?>

    <?= renderFlash() ?>

    <div class="grid-main">
        <div>
            <div class="card">
                <div class="card-title"><?= icon($edit_assignment ? 'pencil' : 'plus') ?><?= $edit_assignment ? 'Opdracht ' . (int)$edit_assignment['assignment_number'] . ' bewerken' : 'Nieuwe opdracht' ?></div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $edit_assignment ? 'edit_assignment' : 'add_assignment' ?>">
                    <?php if ($edit_assignment): ?>
                        <input type="hidden" name="assignment_id" value="<?= $edit_assignment['id'] ?>">
                    <?php endif; ?>

                    <div class="field-row">
                        <div class="field">
                            <label for="f-number">Nummer</label>
                            <input type="number" name="assignment_number" id="f-number" min="1" value="<?= $edit_assignment['assignment_number'] ?? $nextNumber ?>" required>
                        </div>
                        <div class="field">
                            <label for="f-time">Verwachte tijd (minuten)</label>
                            <input type="number" name="time_limit" id="f-time" value="<?= $edit_assignment['time_limit'] ?? '' ?>" placeholder="0 = niet tonen">
                        </div>
                    </div>
                    <div class="field">
                        <label for="f-title">Titel</label>
                        <input type="text" name="title" id="f-title" value="<?= htmlspecialchars($edit_assignment['title'] ?? '') ?>" required>
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
                        <div class="hint">Alleen voor docenten en de AI; teams zien dit nooit.</div>
                    </div>
                    <div class="field">
                        <label for="f-file">Bestand (PDF, ZIP, TXT, JPG of PNG)</label>
                        <input type="file" name="artifact_file" id="f-file" accept=".pdf,.zip,.txt,.jpg,.png">
                        <?php if ($edit_assignment && $edit_assignment['artifact_file']): ?><div class="hint">Leeg laten = het huidige bestand behouden.</div><?php endif; ?>
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
                <p class="muted text-sm" style="margin-bottom: 14px;">Hiermee beoordeelt de AI de antwoorden van teams. Zonder goedgekeurde rubric doet de AI alleen suggesties aan de docent.</p>
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
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Titel</th>
                                <th scope="col">AI</th>
                                <th scope="col" class="col-actions">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr<?= $edit_assignment && (int)$edit_assignment['id'] === (int)$a['id'] ? ' style="background: var(--brand-soft);"' : '' ?>>
                                    <td class="mono"><?= (int)$a['assignment_number'] ?></td>
                                    <td>
                                        <button type="button" class="title-link" title="Opdracht bekijken" onclick='openViewModal(<?= json_encode(['number' => (int)$a['assignment_number'], 'title' => $a['title'], 'description' => $a['description'], 'instruction' => $a['instruction'], 'criteria' => $a['criteria'], 'file' => $a['artifact_file']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><?= htmlspecialchars($a['title']) ?></button>
                                        <?php if ($a['artifact_file']): ?><div class="small muted"><?= icon('paperclip') ?> bijlage</div><?php endif; ?>
                                    </td>
                                    <td>
                                        <?= rubricStatusBadge($a['ai_rubric_status'] ?: 'none') ?>
                                        <?php if ((int)$a['ai_auto_allowed'] === 0): ?><span class="badge" title="Altijd door een mens"><?= icon('user') ?>mens</span><?php endif; ?>
                                    </td>
                                    <td class="col-actions">
                                        <a href="?edit=<?= (int)$a['id'] ?>" class="btn btn-secondary btn-sm btn-icon" title="Bewerken" aria-label="Opdracht <?= (int)$a['assignment_number'] ?> bewerken"><?= icon('pencil') ?></a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Verwijder deze opdracht?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_assignment">
                                            <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Verwijderen" aria-label="Opdracht <?= (int)$a['assignment_number'] ?> verwijderen"><?= icon('trash') ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
            <div class="section-label">Beoordelingscriteria (alleen voor docenten)</div>
            <div id="v-crit" class="panel panel-danger markdown" data-md></div>
            <div id="v-file-wrap" hidden>
                <div class="section-label">Bijlage</div>
                <a id="v-file" href="#" target="_blank" class="btn btn-secondary btn-sm"><?= icon('download') ?>Bestand openen</a>
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
    function openViewModal(assignment) {
        const dialog = document.getElementById('view-dialog');
        document.getElementById('v-title').textContent = 'Opdracht ' + assignment.number + ': ' + assignment.title;
        const fields = { 'v-desc': assignment.description || 'Geen beschrijving.', 'v-instr': assignment.instruction || 'Geen instructie.', 'v-crit': assignment.criteria || 'Geen criteria opgegeven.' };
        Object.keys(fields).forEach(id => {
            const el = document.getElementById(id);
            el.textContent = fields[id];
            el.removeAttribute('data-rendered');
        });
        document.getElementById('v-file-wrap').hidden = !assignment.file;
        if (assignment.file) document.getElementById('v-file').href = assignment.file;
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
include 'admin_footer.php'; ?>
