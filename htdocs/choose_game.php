<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);

// Spel kiezen (ook vanuit de spelkiezer in de zijbalk)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gameId = (int)($_POST['game_id'] ?? 0);
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('choose_game.php');
    }
    if (!canAccessGame($db, $user, $gameId)) {
        flash('error', 'Je hebt geen toegang tot dat spel.');
        redirect('choose_game.php');
    }
    $_SESSION['game_id'] = $gameId;
    // Terug naar dezelfde pagina, zonder parameters: een team of opdracht hoort bij het vorige spel
    $return = $_POST['return'] ?? '';
    redirect(preg_match('/^[a-z_]+\.php$/', $return) && $return !== 'choose_game.php' ? $return : 'teacher.php');
}

$choices = accessibleGames($db, $user);

$pageTitle = 'Spel kiezen';
$extraCSS = '<style>
    .game-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: var(--s-5); }
    .game-card { display: flex; flex-direction: column; gap: 14px; margin-bottom: 0; }
    .game-card .logo { height: 110px; border-radius: var(--radius-sm); background: #FFFFFF; border: 1px solid var(--line); display: grid; place-items: center; overflow: hidden; }
    .game-card .logo img { max-width: 92%; max-height: 100px; object-fit: contain; }
    .game-card .logo.is-empty { background: var(--surface-2); color: var(--text-muted); }
    .game-card .logo.is-empty .icon { width: 36px; height: 36px; stroke-width: 1.5; }
    .game-card .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
    .game-card form { margin-top: auto; }
    .game-card.is-active { border-color: var(--brand); box-shadow: inset 0 0 0 1px var(--brand); }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Kies een spel', '<span>Alle pagina’s onder “Spel” werken met het actieve spel</span>') ?>

    <?= renderFlash() ?>

    <?php if (empty($choices)): ?>
        <div class="card">
            <?php if ($user['role'] === 'admin'): ?>
                <?= emptyState('gamepad', 'Er zijn nog geen spellen.', '<a href="games.php" class="btn btn-primary">' . icon('plus') . 'Maak een spel aan</a>') ?>
            <?php else: ?>
                <?= emptyState('gamepad', 'Je bent nog niet aan een spel toegevoegd. Vraag een beheerder om je aan een spel te koppelen.') ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="game-list">
            <?php foreach ($choices as $choice): $logo = gameLogoUrl($choice); $isActive = isset($_SESSION['game_id']) && (int)$_SESSION['game_id'] === (int)$choice['id']; ?>
                <div class="card game-card<?= $isActive ? ' is-active' : '' ?>">
                    <div class="logo<?= $logo ? '' : ' is-empty' ?>"><?= $logo ? '<img src="' . htmlspecialchars($logo) . '" alt="">' : icon('gamepad') ?></div>
                    <div class="head">
                        <strong><?= htmlspecialchars($choice['name']) ?></strong>
                        <?= gameStatusBadge($choice['effective_status']) ?>
                    </div>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="game_id" value="<?= (int)$choice['id'] ?>">
                        <button type="submit" class="btn <?= $isActive ? 'btn-secondary' : 'btn-primary' ?> btn-block"><?= $isActive ? icon('check') . 'Actief spel' : 'Kies dit spel' ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php include 'admin_footer.php'; ?>
