<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
// Autorisatie check: Alleen admin mag hier komen
$admin = requireAdmin($db);
$adminId = (int)$admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('games.php');
    }

    if ($action === 'create_game') {
        $name = trim($_POST['name'] ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length === 0 || $length > 100) {
            flash('error', 'Een spelnaam heeft 1 tot 100 tekens.');
            redirect('games.php');
        }
        $stmt = $db->prepare("INSERT INTO games (name, status, join_code, registration_open, created_at, created_by) VALUES (?, 'draft', ?, 0, datetime('now'), ?)");
        $stmt->execute([$name, generateJoinCode($db), $adminId]);
        $newId = (int)$db->lastInsertId();
        logAudit($db, $adminId, 'GAME_CREATE', "Spel '$name' (#$newId) aangemaakt");
        flash('success', "Spel '$name' is aangemaakt. Koppel docenten en kies het spel om opdrachten en teams toe te voegen.");
        redirect('games.php');
    }

    if ($action === 'import_game') {
        // Archief (ook van de eerste versie van bin/export_assignments.py) wordt een nieuw spel; de worker doet het werk
        $stored = storeArchiveUpload($_FILES['archive'] ?? []);
        if (is_array($stored)) {
            flash('error', $stored['error']);
            redirect('games.php');
        }
        $name = trim($_POST['name'] ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $original = basename((string)($_FILES['archive']['name'] ?? 'archief.tar.gz'));
        enqueueJob($db, 'game_import', null, ['upload' => $stored, 'original_name' => $original, 'name' => $length > 0 && $length <= 100 ? $name : null],
            $adminId, "Import van '$original' als nieuw spel");
        flash('success', 'Het archief staat in de wachtrij. Het nieuwe spel verschijnt zodra de import klaar is.');
        redirect('jobs.php');
    }

    $target = getGame($db, (int)($_POST['game_id'] ?? 0));
    if (!$target) {
        flash('error', 'Spel niet gevonden.');
        redirect('games.php');
    }
    $targetId = (int)$target['id'];

    if ($action === 'add_teacher' || $action === 'remove_teacher') {
        $teacher = findTeacher($db, (int)($_POST['teacher_id'] ?? 0));
        if (!$teacher) {
            flash('error', 'Docent niet gevonden.');
            redirect('games.php');
        }
        $label = teacherLabel($teacher);
        if ($action === 'add_teacher') {
            $db->prepare("INSERT OR IGNORE INTO game_teachers (game_id, teacher_id) VALUES (?, ?)")->execute([$targetId, $teacher['id']]);
            logAudit($db, $adminId, 'GAME_TEACHERS', "$label (ID {$teacher['id']}) gekoppeld aan spel '{$target['name']}' (#$targetId)");
            flash('success', "$label kan nu '{$target['name']}' besturen.");
        } else {
            $db->prepare("DELETE FROM game_teachers WHERE game_id = ? AND teacher_id = ?")->execute([$targetId, $teacher['id']]);
            logAudit($db, $adminId, 'GAME_TEACHERS', "$label (ID {$teacher['id']}) ontkoppeld van spel '{$target['name']}' (#$targetId)");
            flash('success', "$label is ontkoppeld van '{$target['name']}'.");
        }
    }
    redirect('games.php');
}

$gameRows = $db->query("SELECT " . gameSelectSql() . ",
        (SELECT COUNT(*) FROM teams WHERE game_id = g.id) AS team_count,
        (SELECT COUNT(*) FROM assignments WHERE game_id = g.id) AS assignment_count
    FROM games g ORDER BY g.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Gekoppelde docenten per spel, en alle docenten die gekoppeld kunnen worden
$linked = [];
foreach ($db->query("SELECT gt.game_id, t.* FROM game_teachers gt JOIN teachers t ON t.id = gt.teacher_id ORDER BY t.username")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $linked[(int)$row['game_id']][] = $row;
}
$teachers = $db->query("SELECT * FROM teachers WHERE is_active = 1 ORDER BY role DESC, username")->fetchAll(PDO::FETCH_ASSOC);

function gameButton(string $action, int $gameId, array $fields, string $label, string $class = 'btn-secondary', ?string $confirm = null, string $ariaLabel = ''): string {
    $onsubmit = $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '';
    $html = '<form method="POST" class="inline"' . $onsubmit . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
        . '<input type="hidden" name="game_id" value="' . $gameId . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '">';
    }
    return $html . '<button type="submit" class="btn btn-sm ' . $class . '"' . ($ariaLabel !== '' ? ' aria-label="' . htmlspecialchars($ariaLabel) . '" title="' . htmlspecialchars($ariaLabel) . '"' : '') . '>' . $label . '</button></form>';
}

$pageTitle = 'Spellen';
$extraCSS = '<style>
    .game-row { display: grid; grid-template-columns: 96px minmax(0, 1fr); gap: 20px; align-items: start; }
    .game-logo { width: 96px; height: 72px; border-radius: var(--radius-sm); border: 1px solid var(--line); background: #FFFFFF; display: grid; place-items: center; overflow: hidden; }
    .game-logo img { max-width: 90px; max-height: 66px; object-fit: contain; }
    .game-logo.is-empty { background: var(--surface-2); color: var(--text-muted); }
    .game-name { font-size: 17px; font-weight: 700; }
    .game-facts { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; color: var(--text-muted); margin-top: 4px; }
    .game-facts span { display: inline-flex; align-items: center; gap: 4px; }
    .game-facts .icon { width: 14px; height: 14px; }
    .chip .btn { width: 22px; height: 22px; border-radius: 50%; }
    .chip .btn .icon { width: 12px; height: 12px; }
    .add-teacher { display: flex; gap: 8px; margin-top: 8px; max-width: 460px; }
    @media (max-width: 600px) { .game-row { grid-template-columns: 1fr; } }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Spellen', '<span>Beheerders kunnen elk spel besturen; docenten alleen de spellen waaraan ze gekoppeld zijn</span>') ?>

    <?= renderFlash() ?>

    <div class="card">
        <div class="card-title"><?= icon('plus') ?>Nieuw spel</div>
        <form method="POST" class="btn-row">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_game">
            <input type="text" name="name" placeholder="Bijv. Operation Gameplay klas 2B" maxlength="100" required style="flex: 1; width: auto; min-width: 220px;" aria-label="Naam van het spel">
            <button type="submit" class="btn btn-primary"><?= icon('plus') ?>Aanmaken</button>
        </form>
        <p class="muted small" style="margin-top: 10px;">Een nieuw spel begint als concept.</p>
    </div>

    <div class="card">
        <div class="card-title"><?= icon('inbox') ?>Spel importeren</div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="import_game">
            <div class="field-row">
                <div class="field">
                    <label for="imp-file">Archief (.tar.gz)</label>
                    <input type="file" name="archive" id="imp-file" accept=".gz,.tgz,application/gzip" required>
                    <div class="hint">Een export uit Spelinstellingen, of een archief van het oude <span class="mono">export_assignments.py</span>. Max. <?= maxArchiveMb() ?> MB.</div>
                </div>
                <div class="field">
                    <label for="imp-name">Naam van het nieuwe spel</label>
                    <input type="text" name="name" id="imp-name" maxlength="100" placeholder="Leeg = naam uit het archief">
                </div>
            </div>
            <button type="submit" class="btn btn-secondary"><?= icon('plus') ?>Importeren als nieuw spel</button>
        </form>
        <p class="muted small" style="margin-top: 10px;">Een import maakt altijd een nieuw spel (concept; met voortgang: gepauzeerd). Bestaande spellen worden nooit overschreven. Teams krijgen nieuwe links.</p>
    </div>

    <?php foreach ($gameRows as $row):
        $rowId = (int)$row['id'];
        $logo = gameLogoUrl($row);
        $linkedIds = array_map('intval', array_column($linked[$rowId] ?? [], 'id'));
        $unlinked = array_filter($teachers, function ($t) use ($linkedIds) { return !in_array((int)$t['id'], $linkedIds, true); });
    ?>
        <div class="card">
            <div class="game-row">
                <div class="game-logo<?= $logo ? '' : ' is-empty' ?>"><?= $logo ? '<img src="' . htmlspecialchars($logo) . '" alt="">' : icon('gamepad') ?></div>
                <div>
                    <div class="card-header" style="margin-bottom: 12px;">
                        <div>
                            <div class="btn-row"><span class="game-name"><?= htmlspecialchars($row['name']) ?></span><?= gameStatusBadge($row['effective_status']) ?></div>
                            <div class="game-facts">
                                <span><?= icon('users') ?><?= (int)$row['team_count'] ?> teams</span>
                                <span><?= icon('book') ?><?= (int)$row['assignment_count'] ?> opdrachten</span>
                                <span><?= icon('qr') ?>code <span class="mono"><?= htmlspecialchars((string)$row['join_code']) ?></span></span>
                            </div>
                        </div>
                        <form method="POST" action="choose_game.php" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="game_id" value="<?= $rowId ?>">
                            <input type="hidden" name="return" value="game.php">
                            <button type="submit" class="btn btn-sm btn-secondary"><?= icon('sliders') ?>Openen</button>
                        </form>
                    </div>
                    <div class="section-label" style="margin-top: 0;">Docenten</div>
                    <?php foreach ($linked[$rowId] ?? [] as $t): ?>
                        <span class="chip">
                            <?= htmlspecialchars(teacherLabel($t)) ?><?= $t['role'] === 'admin' ? ' <span class="muted">(admin)</span>' : '' ?>
                            <?= gameButton('remove_teacher', $rowId, ['teacher_id' => $t['id']], icon('x'), 'btn-ghost btn-icon', 'Docent ontkoppelen van dit spel?', 'Ontkoppelen') ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if (empty($linked[$rowId])): ?><p class="muted small">Nog geen docenten gekoppeld (beheerders hebben altijd toegang).</p><?php endif; ?>
                    <?php if ($unlinked): ?>
                        <form method="POST" class="add-teacher">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="add_teacher">
                            <input type="hidden" name="game_id" value="<?= $rowId ?>">
                            <select name="teacher_id" required aria-label="Docent om te koppelen">
                                <?php foreach ($unlinked as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars(teacherLabel($t)) ?><?= $t['role'] === 'admin' ? ' (admin)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-secondary"><?= icon('plus') ?>Koppelen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($gameRows)): ?>
        <div class="card"><?= emptyState('gamepad', 'Nog geen spellen.') ?></div>
    <?php endif; ?>
<?php include 'admin_footer.php'; ?>
