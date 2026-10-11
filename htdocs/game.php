<?php
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

const LOGO_MAX_BYTES = 5 * 1024 * 1024;
const LOGO_TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];

/**
 * Controleert en bewaart een geüpload logo. Geeft het nieuwe pad terug, of een foutmelding
 * als array ['error' => ...]. Alleen echte PNG/JPG/WebP-afbeeldingen (geen svg).
 */
function storeLogo(array $file, int $gameId) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Het uploaden van het logo is mislukt.'];
    }
    if ($file['size'] > LOGO_MAX_BYTES) {
        return ['error' => 'Het logo is groter dan 5 MB.'];
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset(LOGO_TYPES[$info[2]])) {
        return ['error' => 'Het logo moet een PNG-, JPG- of WebP-afbeelding zijn.'];
    }
    $dir = __DIR__ . '/' . LOGO_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = LOGO_DIR . '/game_' . $gameId . '_logo_' . time() . '.' . LOGO_TYPES[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $path)) {
        return ['error' => 'Het logo kon niet worden opgeslagen.'];
    }
    return $path;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('game.php');
    }
    $message = trim($_POST['status_message'] ?? '');
    $message = $message === '' ? null : substr($message, 0, 500);

    switch ($action) {
        case 'update_settings':
            $name = trim($_POST['name'] ?? '');
            $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            $endsInput = trim($_POST['ends_at'] ?? '');
            $endsAt = localInputToUtc($endsInput);
            if ($length === 0 || $length > 100) {
                flash('error', 'Een spelnaam heeft 1 tot 100 tekens.');
                break;
            }
            if ($endsInput !== '' && $endsAt === null) {
                flash('error', 'De eindtijd is ongeldig.');
                break;
            }
            $logo = $game['logo_file'];
            if (!empty($_FILES['logo']['name'])) {
                $stored = storeLogo($_FILES['logo'], $gameId);
                if (is_array($stored)) {
                    flash('error', $stored['error']);
                    break;
                }
                $logo = $stored;
            }
            $db->prepare("UPDATE games SET name = ?, ends_at = ?, logo_file = ? WHERE id = ?")->execute([$name, $endsAt, $logo, $gameId]);
            // Oud logo weg; alleen een eigen upload, niet een meegeleverd bestand zoals images/…
            $old = (string)$game['logo_file'];
            if ($logo !== $game['logo_file'] && strpos($old, LOGO_DIR . '/') === 0 && is_file(__DIR__ . '/' . $old)) {
                unlink(__DIR__ . '/' . $old);
            }
            logAudit($db, $userId, 'GAME_UPDATE', "Instellingen van spel '$name' (#$gameId) gewijzigd" . ($logo !== $game['logo_file'] ? ' (nieuw logo)' : ''));
            flash('success', 'De instellingen zijn opgeslagen.');
            break;

        case 'start':
            if (startGame($db, $gameId, $userId, $message)) {
                flash('success', 'Het spel is gestart: alle teams staan op level 1.');
            } else {
                flash('error', 'Het spel kan alleen vanuit concept gestart worden.');
            }
            break;

        case 'pause':
        case 'resume':
        case 'finish':
            $labels = ['pause' => 'Het spel is gepauzeerd.', 'resume' => 'Het spel is hervat.', 'finish' => 'Het spel is beëindigd.'];
            if (changeGameStatus($db, $gameId, $action, $message, $userId)) {
                flash('success', $labels[$action]);
            } else {
                flash('error', 'Deze statuswijziging is nu niet mogelijk.');
            }
            break;

        case 'new_code':
            $db->prepare("UPDATE games SET join_code = ? WHERE id = ?")->execute([generateJoinCode($db), $gameId]);
            logAudit($db, $userId, 'GAME_JOIN_CODE', "Nieuwe spelcode voor spel '{$game['name']}' (#$gameId)");
            flash('success', 'Er is een nieuwe spelcode. De oude code en QR-code werken niet meer.');
            break;

        case 'toggle_registration':
            $open = (int)$game['registration_open'] ? 0 : 1;
            $db->prepare("UPDATE games SET registration_open = ? WHERE id = ?")->execute([$open, $gameId]);
            logAudit($db, $userId, 'REGISTRATION_TOGGLE', "Registratie voor spel '{$game['name']}' (#$gameId) " . ($open ? 'geopend' : 'gesloten'));
            flash('success', $open ? 'Teams kunnen zich nu zelf aanmelden.' : 'De registratie is gesloten.');
            break;

        case 'reset':
            if (trim($_POST['confirm_name'] ?? '') !== $game['name']) {
                flash('error', 'De naam klopt niet; er is niets leeggemaakt.');
                break;
            }
            resetGame($db, $gameId, $userId);
            flash('success', 'Het spel is leeggemaakt en staat weer op concept. Het archief is bewaard.');
            break;

        default:
            flash('error', 'Onbekende actie.');
    }
    redirect('game.php');
}

$logo = gameLogoUrl($game);
$joinUrl = BASE_URL . '/join.php?code=' . urlencode((string)$game['join_code']);
$status = $game['effective_status'];
$actions = allowedGameActions($game);
$teamCount = count(rankedTeams($db, $gameId));

$statusForms = [
    'start' => ['🚀 Start', 'btn-primary', 'Bericht bij de start (optioneel)', 'Het spel starten? Alle ' . $teamCount . ' teams gaan tegelijk naar level 1.'],
    'pause' => ['⏸️ Pauze', 'btn-outline', 'Bijv. "We hebben pauze tot 11:00"', null],
    'resume' => ['▶️ Hervat', 'btn-primary', 'Bericht (optioneel)', null],
    'finish' => ['🏁 Beëindig', 'btn-danger', 'Bijv. "Kom naar lokaal X"', 'Het spel beëindigen? Teams kunnen daarna geen berichten meer sturen.'],
];

$pageTitle = 'Spel';
$extraCSS = '<style>
    .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
    @media (max-width: 1100px) { .settings-grid { grid-template-columns: 1fr; } }
    .form-row { margin-bottom: 15px; }
    .form-row label { display: block; margin-bottom: 5px; font-size: 0.9em; }
    .muted { color: #999; font-size: 0.85em; }
    .status-form { display: flex; gap: 10px; margin-top: 12px; }
    .status-form input { flex: 1; }
    .join-code { font-family: monospace; font-size: 2em; letter-spacing: 6px; font-weight: bold; color: #333; }
    #qrcode { margin: 15px 0; }
    #qrcode img, #qrcode canvas { border: 8px solid white; }
    .danger-zone { border: 2px solid #ffcdd2; }
    .logo-preview { max-width: 200px; max-height: 120px; object-fit: contain; display: block; margin-bottom: 10px; }
</style>';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">⚙️ <?= htmlspecialchars($game['name']) ?> <?= gameStatusBadge($status) ?></h1>

    <?= renderFlash() ?>

    <div class="settings-grid">
        <div>
            <div class="card">
                <div class="card-title" style="font-size: 1.2em; margin-bottom: 10px;">🎬 Status</div>
                <p>Het spel is <strong><?= htmlspecialchars(gameStatusLabel($status)) ?></strong>.
                    <?php if ($status === 'finished' && $game['status'] !== 'finished'): ?>De eindtijd is verstreken; zet een latere eindtijd om door te gaan.<?php endif; ?>
                </p>
                <?php if ($game['status_message']): ?>
                    <p class="muted" style="margin-top: 5px;">Statustekst voor de teams: “<?= htmlspecialchars($game['status_message']) ?>”</p>
                <?php endif; ?>
                <?php if ($status === 'draft' && $game['ends_at'] && $game['seconds_left'] !== null && (int)$game['seconds_left'] === 0): ?>
                    <p class="error-msg" style="margin: 10px 0 0;">Let op: de eindtijd ligt in het verleden. Na de start is het spel meteen afgelopen.</p>
                <?php endif; ?>
                <?php foreach ($actions as $act): [$label, $class, $placeholder, $confirm] = $statusForms[$act]; ?>
                    <form method="POST" class="status-form"<?= $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '' ?>>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="<?= $act ?>">
                        <input type="text" name="status_message" maxlength="500" placeholder="<?= htmlspecialchars($placeholder) ?>">
                        <button type="submit" class="btn <?= $class ?>" style="white-space: nowrap; min-width: 120px;"><?= $label ?></button>
                    </form>
                <?php endforeach; ?>
                <p class="muted" style="margin-top: 12px;">Pauze toont de tekst als banner bij de teams; ze kunnen doorwerken. Beëindigen sluit de chat en toont het eindscherm.</p>
            </div>

            <div class="card">
                <div class="card-title" style="font-size: 1.2em; margin-bottom: 15px;">🛠️ Instellingen</div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_settings">
                    <div class="form-row">
                        <label>Naam</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($game['name']) ?>" maxlength="100" required>
                    </div>
                    <div class="form-row">
                        <label>Eindtijd (leeg = geen eindtijd)</label>
                        <input type="datetime-local" name="ends_at" value="<?= htmlspecialchars(utcToLocalInput($game['ends_at'])) ?>">
                        <?php if ($game['ends_at']): ?><div class="muted" style="margin-top: 4px;">Nu: <?= formatDateTime($game['ends_at']) ?></div><?php endif; ?>
                    </div>
                    <div class="form-row">
                        <label>Logo (PNG, JPG of WebP, max. 5 MB)</label>
                        <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="Huidig logo" class="logo-preview"><?php endif; ?>
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                    </div>
                    <button type="submit" class="btn btn-primary">Opslaan</button>
                </form>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-title" style="font-size: 1.2em; margin-bottom: 10px;">📱 Zelf aanmelden</div>
                <p>Registratie is <strong style="color: <?= (int)$game['registration_open'] ? '#2e7d32' : '#c62828' ?>;"><?= (int)$game['registration_open'] ? 'open' : 'dicht' ?></strong>.</p>
                <form method="POST" style="margin: 10px 0 20px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_registration">
                    <button type="submit" class="btn <?= (int)$game['registration_open'] ? 'btn-outline' : 'btn-primary' ?>"><?= (int)$game['registration_open'] ? '🔒 Registratie sluiten' : '🔓 Registratie openen' ?></button>
                </form>
                <div class="muted">Spelcode</div>
                <div class="join-code"><?= htmlspecialchars((string)$game['join_code']) ?></div>
                <div id="qrcode"></div>
                <p style="word-break: break-all;"><a href="<?= htmlspecialchars($joinUrl) ?>" target="_blank" style="color: #667eea;"><?= htmlspecialchars($joinUrl) ?></a></p>
                <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                    <a href="beamer.php" target="_blank" class="btn btn-primary">📺 Beamerweergave</a>
                    <form method="POST" style="margin: 0;" onsubmit="return confirm('Nieuwe spelcode maken? De oude code en QR-code werken dan niet meer.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="new_code">
                        <button type="submit" class="btn btn-outline">🔄 Nieuwe code</button>
                    </form>
                </div>
                <?php if ($status === 'finished'): ?><p class="muted" style="margin-top: 10px;">Het spel is afgelopen: aanmelden kan niet meer.</p><?php endif; ?>
            </div>

            <div class="card danger-zone">
                <div class="card-title" style="font-size: 1.2em; margin-bottom: 10px; color: #c62828;">🧹 Alles leegmaken</div>
                <p class="muted" style="margin-bottom: 15px;">Verwijdert alle <?= $teamCount ?> teams met hun chats, downloads en de mededelingen, en zet het spel terug op concept. Het archief, de opdrachten en de instellingen blijven.</p>
                <form method="POST" onsubmit="return confirm('Weet je het zeker? Dit kan niet ongedaan worden gemaakt.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset">
                    <div class="form-row">
                        <label>Typ de naam van het spel ter bevestiging: <strong><?= htmlspecialchars($game['name']) ?></strong></label>
                        <input type="text" name="confirm_name" autocomplete="off" required>
                    </div>
                    <button type="submit" class="btn btn-danger">Alles leegmaken</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($joinUrl) ?>,
            width: 180,
            height: 180,
            correctLevel: QRCode.CorrectLevel.M
        });
    </script>
<?php include 'admin_footer.php'; ?>
