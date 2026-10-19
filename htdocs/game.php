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
            // Eindtijd: datum (dd/mm/jjjj) en tijd (uu:mm) apart; allebei leeg = geen eindtijd
            $endsDate = trim($_POST['ends_date'] ?? '');
            $endsTime = trim($_POST['ends_time'] ?? '');
            $endsAt = localDateTimeToUtc($endsDate, $endsTime);
            if ($length === 0 || $length > 100) {
                flash('error', 'Een spelnaam heeft 1 tot 100 tekens.');
                break;
            }
            if (($endsDate === '') !== ($endsTime === '')) {
                flash('error', 'Vul voor de eindtijd zowel een datum als een tijd in, of laat beide leeg.');
                break;
            }
            if ($endsDate !== '' && $endsAt === null) {
                flash('error', 'De eindtijd is ongeldig. Gebruik dd/mm/jjjj voor de datum en uu:mm voor de tijd.');
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

        case 'update_ai':
            $error = updateGameAiSettings($db, $gameId, (string)($_POST['ai_mode'] ?? ''),
                (int)($_POST['ai_max_attempts'] ?? 0), (int)($_POST['ai_max_questions'] ?? 0), $userId);
            flash($error ? 'error' : 'success', $error ?? 'De AI-instellingen zijn opgeslagen.');
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

        case 'export':
            // Spel als archief (worker-service): standaard alleen spelinformatie, optioneel met teams en voortgang
            $withProgress = !empty($_POST['include_progress']);
            enqueueJob($db, 'game_export', $gameId, ['game_id' => $gameId, 'include_progress' => $withProgress], $userId,
                "Export van spel '{$game['name']}'" . ($withProgress ? ' met teams en voortgang' : ''));
            flash('success', 'De export staat in de wachtrij. Zodra hij klaar is, kun je hem hier downloaden.');
            redirect('jobs.php');

        case 'report':
            $sections = array_values(array_intersect(['answers', 'teams'], (array)($_POST['sections'] ?? [])));
            if (!$sections) {
                flash('error', 'Kies minstens één onderdeel voor het rapport.');
                break;
            }
            enqueueJob($db, 'report', $gameId, ['game_id' => $gameId, 'sections' => $sections], $userId, "Rapport van spel '{$game['name']}'");
            flash('success', 'Het rapport wordt gemaakt. Zodra het klaar is, kun je het hier downloaden.');
            redirect('jobs.php');

        case 'delete_game':
            // Alleen beheerders: zij maken spellen aan (games.php), dus zij verwijderen ze ook
            if ($user['role'] !== 'admin') {
                flash('error', 'Alleen een beheerder kan een spel verwijderen.');
                break;
            }
            if (trim($_POST['confirm_delete_name'] ?? '') !== $game['name']) {
                flash('error', 'De naam klopt niet; er is niets verwijderd.');
                break;
            }
            $error = deleteGame($db, $gameId, $userId);
            if ($error !== null) {
                flash('error', $error);
                break;
            }
            unset($_SESSION['game_id']);
            flash('success', "Het spel '{$game['name']}' is verwijderd.");
            redirect('games.php');

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
$ends = utcToLocalParts($game['ends_at']);
$status = $game['effective_status'];
$actions = allowedGameActions($game);
$teamCount = count(rankedTeams($db, $gameId));

$statusForms = [
    'start' => ['play', 'Start', 'btn-primary', 'Bericht bij de start (optioneel)', 'Het spel starten? Alle ' . $teamCount . ' teams gaan tegelijk naar level 1.'],
    'pause' => ['pause', 'Pauze', 'btn-secondary', 'Bijv. "We hebben pauze tot 11:00"', null],
    'resume' => ['play', 'Hervat', 'btn-primary', 'Bericht (optioneel)', null],
    'finish' => ['flag', 'Beëindig', 'btn-danger', 'Bijv. "Kom naar lokaal X"', 'Het spel beëindigen? Teams kunnen daarna geen berichten meer sturen.'],
];

$pageTitle = 'Spelinstellingen';
$extraCSS = '<style>
    .status-form { display: flex; gap: 8px; margin-top: 12px; }
    .status-form input { flex: 1; }
    .status-form .btn { min-width: 130px; }
    .join-code { font-family: var(--font-mono); font-size: 32px; letter-spacing: .14em; font-weight: 800; line-height: 1.1; }
    .join-grid { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 20px; align-items: start; margin-top: 16px; }
    .join-url { overflow-wrap: anywhere; font-size: 13px; }
    .logo-preview { max-width: 200px; max-height: 120px; object-fit: contain; display: block; margin-bottom: 10px; border-radius: var(--radius-sm); background: #FFFFFF; padding: 6px; border: 1px solid var(--line); }
    .state-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .ends-row { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 12px; }
    .ends-row .field { margin-bottom: 0; }
    .date-input { position: relative; display: flex; gap: 6px; }
    .date-input input[type="text"] { flex: 1; min-width: 0; }
    .picker-proxy { position: absolute; left: 0; bottom: 0; width: 1px; height: 1px; padding: 0; border: 0; opacity: 0; pointer-events: none; }
    .link-button { background: none; border: none; padding: 0; font: inherit; color: var(--brand); text-decoration: underline; cursor: pointer; }
    @media (max-width: 600px) { .status-form { flex-direction: column; } .join-grid { grid-template-columns: 1fr; } }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Spelinstellingen', gameSubtitle($game),
        '<a href="beamer.php" target="_beamer" class="btn btn-secondary">' . icon('monitor') . 'Beamer' . icon('external') . '</a>') ?>

    <?= renderFlash() ?>

    <div class="grid-halves">
        <div>
            <div class="card">
                <div class="card-title"><?= icon('play') ?>Status</div>
                <p class="state-line">Het spel is <?= gameStatusBadge($status) ?>
                    <?php if ($status === 'finished' && $game['status'] !== 'finished'): ?><span class="muted text-sm">De eindtijd is verstreken; zet een latere eindtijd om door te gaan.</span><?php endif; ?>
                </p>
                <?php if ($game['status_message']): ?>
                    <p class="muted text-sm" style="margin-top: 6px;">Statustekst voor de teams: “<?= htmlspecialchars($game['status_message']) ?>”</p>
                <?php endif; ?>
                <?php if ($status === 'draft' && $game['ends_at'] && $game['seconds_left'] !== null && (int)$game['seconds_left'] === 0): ?>
                    <div style="margin-top: 12px;"><?= alertBox('warning', 'Let op: de eindtijd ligt in het verleden. Na de start is het spel meteen afgelopen.') ?></div>
                <?php endif; ?>
                <?php foreach ($actions as $act): [$iconName, $label, $class, $placeholder, $confirm] = $statusForms[$act]; ?>
                    <form method="POST" class="status-form"<?= $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '' ?>>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="<?= $act ?>">
                        <input type="text" name="status_message" maxlength="500" placeholder="<?= htmlspecialchars($placeholder) ?>" aria-label="Bericht bij <?= htmlspecialchars(strtolower($label)) ?>">
                        <button type="submit" class="btn <?= $class ?>"><?= icon($iconName) ?><?= $label ?></button>
                    </form>
                <?php endforeach; ?>
                <p class="muted small" style="margin-top: 12px;">Pauze toont de tekst als banner bij de teams; ze kunnen doorwerken. Beëindigen sluit de chat en toont het eindscherm.</p>
            </div>

            <div class="card">
                <div class="card-title"><?= icon('sliders') ?>Algemeen</div>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_settings">
                    <div class="field">
                        <label for="g-name">Naam</label>
                        <input type="text" name="name" id="g-name" value="<?= htmlspecialchars($game['name']) ?>" maxlength="100" required>
                    </div>
                    <div class="field">
                        <div class="ends-row">
                            <div class="field">
                                <label for="g-ends-date">Einddatum</label>
                                <div class="date-input">
                                    <input type="text" name="ends_date" id="g-ends-date" value="<?= htmlspecialchars($ends['date']) ?>" placeholder="dd/mm/jjjj" inputmode="numeric" autocomplete="off"
                                           pattern="\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4}" title="Datum als dd/mm/jjjj, bijv. 24/10/2026" aria-describedby="g-ends-hint">
                                    <button type="button" class="btn btn-secondary btn-icon" id="g-ends-pick" title="Kies in de kalender" aria-label="Einddatum kiezen in de kalender" hidden><?= icon('calendar') ?></button>
                                    <input type="date" id="g-ends-picker" class="picker-proxy" tabindex="-1" aria-hidden="true">
                                </div>
                            </div>
                            <div class="field">
                                <label for="g-ends-time">Eindtijd</label>
                                <input type="text" name="ends_time" id="g-ends-time" value="<?= htmlspecialchars($ends['time']) ?>" placeholder="uu:mm" inputmode="numeric" autocomplete="off" list="g-ends-times"
                                       pattern="([01]?\d|2[0-3])[:.][0-5]\d" title="Tijd als uu:mm (24 uur), bijv. 16:30" aria-describedby="g-ends-hint">
                                <datalist id="g-ends-times">
                                    <?php for ($m = 0; $m < 24 * 60; $m += 15): ?><option value="<?= sprintf('%02d:%02d', intdiv($m, 60), $m % 60) ?>"><?php endfor; ?>
                                </datalist>
                            </div>
                        </div>
                        <div class="hint" id="g-ends-hint">Beide leeg = geen eindtijd.<?= $game['ends_at'] ? ' Nu: ' . formatDateTime($game['ends_at']) . '.' : '' ?>
                            <?php if ($game['ends_at']): ?><button type="button" class="link-button" id="g-ends-clear">Eindtijd wissen</button><?php endif; ?></div>
                    </div>
                    <div class="field">
                        <label for="g-logo">Logo</label>
                        <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="Huidig logo" class="logo-preview"><?php endif; ?>
                        <input type="file" name="logo" id="g-logo" accept="image/png,image/jpeg,image/webp">
                        <div class="hint">PNG, JPG of WebP, max. 5 MB. Komt terug op het leaderboard, de beamer en het teamdashboard.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('save') ?>Opslaan</button>
                </form>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-title"><?= icon('bot') ?>AI</div>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_ai">
                    <div class="field">
                        <label for="g-ai">Standaardmodus voor nieuwe teams</label>
                        <select name="ai_mode" id="g-ai">
                            <?php foreach (AI_MODES as $mode): ?>
                                <option value="<?= $mode ?>" <?= ($game['ai_mode'] ?: 'suggest') === $mode ? 'selected' : '' ?>><?= htmlspecialchars(aiModeLabel($mode) . ' – ' . AI_MODE_DESCRIPTIONS[$mode]) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint">Bestaande teams houden hun eigen modus; die zet je op het <a href="teacher.php">dashboard</a> (per team of voor alle teams).</div>
                    </div>
                    <div class="field-row">
                        <div class="field">
                            <label for="g-att">Max. antwoorden per 10 min</label>
                            <input type="number" name="ai_max_attempts" id="g-att" min="<?= AI_LIMIT_MIN ?>" max="<?= AI_LIMIT_MAX ?>" value="<?= (int)($game['ai_max_attempts'] ?? 5) ?>" required>
                        </div>
                        <div class="field">
                            <label for="g-q">Max. vragen per 10 min</label>
                            <input type="number" name="ai_max_questions" id="g-q" min="<?= AI_LIMIT_MIN ?>" max="<?= AI_LIMIT_MAX ?>" value="<?= (int)($game['ai_max_questions'] ?? 5) ?>" required>
                        </div>
                    </div>
                    <p class="muted small" style="margin: -6px 0 16px;">Per team per level. Daarboven beoordeelt de AI niet zelf meer, maar vraagt ze een operator.</p>
                    <button type="submit" class="btn btn-primary"><?= icon('save') ?>Opslaan</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title"><?= icon('phone') ?>Zelf aanmelden</div>
                    <?= (int)$game['registration_open'] ? '<span class="badge badge-success">' . icon('unlock') . 'open</span>' : '<span class="badge">' . icon('lock') . 'gesloten</span>' ?>
                </div>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_registration">
                    <button type="submit" class="btn <?= (int)$game['registration_open'] ? 'btn-secondary' : 'btn-primary' ?>"><?= (int)$game['registration_open'] ? icon('lock') . 'Registratie sluiten' : icon('unlock') . 'Registratie openen' ?></button>
                </form>
                <div class="join-grid">
                    <div>
                        <div class="section-label" style="margin-top: 0;">Spelcode</div>
                        <div class="join-code"><?= htmlspecialchars((string)$game['join_code']) ?></div>
                        <p class="join-url" style="margin-top: 10px;"><a href="<?= htmlspecialchars($joinUrl) ?>" target="_blank"><?= htmlspecialchars($joinUrl) ?></a></p>
                        <div class="btn-row" style="margin-top: 14px;">
                            <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= htmlspecialchars($joinUrl) ?>"><?= icon('copy') ?><span class="copy-label">Link kopiëren</span></button>
                            <form method="POST" class="inline" onsubmit="return confirm('Nieuwe spelcode maken? De oude code en QR-code werken dan niet meer.');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="new_code">
                                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('refresh') ?>Nieuwe code</button>
                            </form>
                        </div>
                    </div>
                    <div class="qr-card" id="qrcode" role="img" aria-label="QR-code naar de aanmeldpagina"></div>
                </div>
                <?php if ($status === 'finished'): ?><p class="muted small" style="margin-top: 12px;">Het spel is afgelopen: aanmelden kan niet meer.</p><?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title"><?= icon('download') ?>Exporteren en rapport</div>
                    <a href="jobs.php" class="btn btn-sm btn-ghost"><?= icon('inbox') ?>Taken</a>
                </div>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="export">
                    <label class="check field">
                        <input type="checkbox" name="include_progress" value="1">
                        <span>Met teams en voortgang (berichten, archief, AI-beoordelingen; zonder e-mailadressen)</span>
                    </label>
                    <button type="submit" class="btn btn-secondary"><?= icon('download') ?>Spel exporteren</button>
                    <p class="muted small" style="margin-top: 8px;">Zonder vinkje: alleen de spelinstellingen, het logo en de opdrachten met bijlagen en rubric. Een beheerder kan het archief als nieuw spel importeren.</p>
                </form>
                <div class="section-label">Rapport (PDF)</div>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="report">
                    <label class="check field" style="margin-bottom: 6px;">
                        <input type="checkbox" name="sections[]" value="answers" checked>
                        <span>Goedgekeurde antwoorden per opdracht, naast het modelantwoord</span>
                    </label>
                    <label class="check field">
                        <input type="checkbox" name="sections[]" value="teams" checked>
                        <span>Verloop per team</span>
                    </label>
                    <button type="submit" class="btn btn-secondary"><?= icon('file') ?>Rapport maken</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card card-danger">
        <div class="card-title"><?= icon('alert') ?>Gevarenzone</div>
        <div class="section-label">Alles leegmaken</div>
        <p class="text-sm" style="margin-bottom: 16px;">Verwijdert alle <?= $teamCount ?> teams met hun chats, downloads en de mededelingen, en zet het spel terug op concept. Het archief, de opdrachten en de instellingen blijven. Dit kan niet ongedaan worden gemaakt.</p>
        <form method="POST" class="btn-row" style="align-items: flex-end;" onsubmit="return confirm('Weet je het zeker? Dit kan niet ongedaan worden gemaakt.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset">
            <div class="field" style="margin: 0; flex: 1; min-width: 240px; max-width: 420px;">
                <label for="g-confirm">Typ ter bevestiging de naam van het spel: <strong><?= htmlspecialchars($game['name']) ?></strong></label>
                <input type="text" name="confirm_name" id="g-confirm" autocomplete="off" required>
            </div>
            <button type="submit" class="btn btn-danger-solid"><?= icon('trash') ?>Alles leegmaken</button>
        </form>

        <?php if ($user['role'] === 'admin'): ?>
            <div class="section-label">Spel verwijderen</div>
            <p class="text-sm" style="margin-bottom: 16px;">Verwijdert het hele spel: alle <?= $teamCount ?> teams met hun chats, de opdrachten met bijlagen, het archief, de mededelingen, het logo en de exports en rapporten van dit spel. Operators verliezen de toegang. Dit kan niet ongedaan worden gemaakt; maak eerst een export (met teams en voortgang) of een backup als je het later nog wilt terugzien.</p>
            <?php if (in_array($status, ['running', 'paused'], true)): ?>
                <?= alertBox('info', 'Dit spel is ' . htmlspecialchars(gameStatusLabel($status)) . '. Beëindig het spel eerst; daarna kun je het verwijderen.') ?>
            <?php else: ?>
                <form method="POST" class="btn-row" style="align-items: flex-end;" onsubmit="return confirm('Het spel definitief verwijderen? Dit kan niet ongedaan worden gemaakt.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_game">
                    <div class="field" style="margin: 0; flex: 1; min-width: 240px; max-width: 420px;">
                        <label for="g-delete-confirm">Typ ter bevestiging de naam van het spel: <strong><?= htmlspecialchars($game['name']) ?></strong></label>
                        <input type="text" name="confirm_delete_name" id="g-delete-confirm" autocomplete="off" required>
                    </div>
                    <button type="submit" class="btn btn-danger-solid"><?= icon('trash') ?>Spel verwijderen</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="<?= QRCODE_SRC ?>"></script>
    <script>
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($joinUrl) ?>,
            width: 150,
            height: 150,
            correctLevel: QRCode.CorrectLevel.M
        });

        // Eindtijd: datum als dd/mm/jjjj en tijd als uu:mm. De kalenderknop opent de datumkiezer van
        // de browser via een verborgen datumveld (dat zelf de notatie van de browser zou tonen).
        (function () {
            const date = document.getElementById('g-ends-date');
            const time = document.getElementById('g-ends-time');
            const picker = document.getElementById('g-ends-picker');
            const pick = document.getElementById('g-ends-pick');
            const pad = n => String(n).padStart(2, '0');
            const parseDate = v => {
                const m = v.trim().match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/);
                return m ? { d: +m[1], m: +m[2], y: +m[3] } : null;
            };
            // Netjes maken bij verlaten: 1-7-2026 → 01/07/2026, 9.5 blijft fout, 9:05 → 09:05
            date.addEventListener('blur', () => {
                const p = parseDate(date.value);
                if (p) date.value = pad(p.d) + '/' + pad(p.m) + '/' + p.y;
            });
            time.addEventListener('blur', () => {
                const m = time.value.trim().match(/^(\d{1,2})[:.](\d{2})$/);
                if (m && +m[1] < 24 && +m[2] < 60) time.value = pad(m[1]) + ':' + m[2];
            });
            if (typeof picker.showPicker === 'function') {
                pick.hidden = false;
                pick.addEventListener('click', () => {
                    const p = parseDate(date.value);
                    picker.value = p ? p.y + '-' + pad(p.m) + '-' + pad(p.d) : '';
                    try { picker.showPicker(); } catch (e) { date.focus(); }
                });
                picker.addEventListener('change', () => {
                    if (!picker.value) return;
                    const [y, m, d] = picker.value.split('-');
                    date.value = d + '/' + m + '/' + y;
                    (time.value ? date : time).focus();
                });
            }
            const clear = document.getElementById('g-ends-clear');
            if (clear) clear.addEventListener('click', () => { date.value = ''; time.value = ''; date.focus(); });
        })();
    </script>
<?php include 'admin_footer.php'; ?>
