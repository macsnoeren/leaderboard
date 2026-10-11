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
    .game-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
    .game-card { display: flex; flex-direction: column; gap: 10px; }
    .game-card img { max-width: 100%; max-height: 120px; object-fit: contain; align-self: center; }
    .muted { color: #999; font-size: 0.85em; }
</style>';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">🎮 Kies een spel</h1>

    <?= renderFlash() ?>

    <?php if (empty($choices)): ?>
        <div class="card">
            <?php if ($user['role'] === 'admin'): ?>
                <p>Er zijn nog geen spellen. <a href="games.php" style="color: #667eea;">Maak een spel aan</a> om te beginnen.</p>
            <?php else: ?>
                <p><strong>Je bent nog niet aan een spel toegevoegd.</strong></p>
                <p class="muted" style="margin-top: 10px;">Vraag een beheerder om je aan een spel te koppelen.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="game-list">
            <?php foreach ($choices as $choice): $logo = gameLogoUrl($choice); ?>
                <div class="card game-card">
                    <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt=""><?php endif; ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                        <strong><?= htmlspecialchars($choice['name']) ?></strong>
                        <?= gameStatusBadge($choice['effective_status']) ?>
                    </div>
                    <form method="POST" style="margin: 0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="game_id" value="<?= (int)$choice['id'] ?>">
                        <button type="submit" class="btn btn-primary" style="width: 100%;"><?= isset($_SESSION['game_id']) && (int)$_SESSION['game_id'] === (int)$choice['id'] ? '✔ Actief spel' : 'Kies dit spel' ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php include 'admin_footer.php'; ?>
