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

function gameButton(string $action, int $gameId, array $fields, string $label, string $class = 'btn-outline', ?string $confirm = null): string {
    $onsubmit = $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '';
    $html = '<form method="POST" style="display:inline;"' . $onsubmit . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
        . '<input type="hidden" name="game_id" value="' . $gameId . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '">';
    }
    return $html . '<button type="submit" class="btn btn-sm ' . $class . '">' . $label . '</button></form>';
}

$pageTitle = 'Spellen';
$extraCSS = '<style>
    .btn-sm { padding: 5px 10px; font-size: 0.8em; }
    .muted { color: #999; font-size: 0.85em; }
    .game-row { display: grid; grid-template-columns: 100px 1fr; gap: 20px; align-items: start; }
    .game-row img { max-width: 100px; max-height: 80px; object-fit: contain; }
    .teacher-chip { display: inline-flex; align-items: center; gap: 6px; background: #f0f4ff; border-radius: 15px; padding: 3px 4px 3px 12px; margin: 0 6px 6px 0; font-size: 0.85em; }
    .teacher-chip .btn { padding: 2px 8px; border-radius: 12px; }
    .add-teacher { display: flex; gap: 8px; margin-top: 8px; max-width: 450px; }
    .add-teacher select { padding: 6px; }
</style>';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">🎮 Spellen</h1>

    <?= renderFlash() ?>

    <div class="card" style="border-top: 5px solid #667eea;">
        <div class="card-title" style="color: #667eea; font-size: 1.2em; margin-bottom: 15px;">➕ Nieuw spel</div>
        <form method="POST" style="display: flex; gap: 10px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_game">
            <input type="text" name="name" placeholder="Bijv. Operation Gameplay klas 2B" maxlength="100" required>
            <button type="submit" class="btn btn-primary" style="white-space: nowrap;">🚀 Aanmaken</button>
        </form>
        <p class="muted" style="margin-top: 10px;">Een nieuw spel begint als concept. Beheerders kunnen elk spel besturen; docenten alleen de spellen waaraan je ze hieronder koppelt.</p>
    </div>

    <?php foreach ($gameRows as $row):
        $rowId = (int)$row['id'];
        $logo = gameLogoUrl($row);
        $linkedIds = array_map('intval', array_column($linked[$rowId] ?? [], 'id'));
        $unlinked = array_filter($teachers, function ($t) use ($linkedIds) { return !in_array((int)$t['id'], $linkedIds, true); });
    ?>
        <div class="card">
            <div class="game-row">
                <div><?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt=""><?php else: ?><span class="muted">geen logo</span><?php endif; ?></div>
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <div>
                            <strong style="font-size: 1.2em;"><?= htmlspecialchars($row['name']) ?></strong>
                            <?= gameStatusBadge($row['effective_status']) ?>
                            <span class="muted">· <?= (int)$row['team_count'] ?> teams · <?= (int)$row['assignment_count'] ?> opdrachten · code <?= htmlspecialchars((string)$row['join_code']) ?></span>
                        </div>
                        <form method="POST" action="choose_game.php" style="margin: 0;">
                            <?= csrfField() ?>
                            <input type="hidden" name="game_id" value="<?= $rowId ?>">
                            <input type="hidden" name="return" value="game.php">
                            <button type="submit" class="btn btn-sm btn-outline">⚙️ Openen</button>
                        </form>
                    </div>
                    <div style="margin-top: 15px;">
                        <div class="muted" style="margin-bottom: 6px;">Docenten</div>
                        <?php foreach ($linked[$rowId] ?? [] as $t): ?>
                            <span class="teacher-chip">
                                <?= htmlspecialchars(teacherLabel($t)) ?><?= $t['role'] === 'admin' ? ' <span class="muted">(admin)</span>' : '' ?>
                                <?= gameButton('remove_teacher', $rowId, ['teacher_id' => $t['id']], '✕', 'btn-danger', 'Docent ontkoppelen van dit spel?') ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (empty($linked[$rowId])): ?><span class="muted">Nog geen docenten gekoppeld (beheerders hebben altijd toegang).</span><?php endif; ?>
                        <?php if ($unlinked): ?>
                            <form method="POST" class="add-teacher">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="add_teacher">
                                <input type="hidden" name="game_id" value="<?= $rowId ?>">
                                <select name="teacher_id" required>
                                    <?php foreach ($unlinked as $t): ?>
                                        <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars(teacherLabel($t)) ?><?= $t['role'] === 'admin' ? ' (admin)' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary" style="white-space: nowrap;">Koppelen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($gameRows)): ?>
        <div class="card muted" style="text-align: center;">Nog geen spellen.</div>
    <?php endif; ?>
<?php include 'admin_footer.php'; ?>
