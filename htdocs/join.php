<?php
/**
 * Publieke aanmeldpagina: een team meldt zich aan met de spelcode (of via de QR-code).
 */
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/mail_functions.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();

const JOIN_CODE_MAX_FAILS = 10;      // foute codes per IP per kwartier
const JOIN_REGISTER_MAX = 30;        // aanmeldingen per IP per uur (een hele klas zit vaak achter één IP)

$code = strtoupper(trim($_POST['code'] ?? $_GET['code'] ?? ''));
$teamName = trim($_POST['team_name'] ?? '');
$emailInput = trim($_POST['email'] ?? '');
$error = '';

$game = $code !== '' ? getGameByCode($db, $code) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalizeEmail($emailInput);
    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } elseif (isIpThrottled($db, 'join_code', JOIN_CODE_MAX_FAILS, 15) || isIpThrottled($db, 'join_register', JOIN_REGISTER_MAX, 60)) {
        $error = THROTTLE_ERROR;
    } elseif (!$game) {
        recordAttempt($db, 'join_code', $code);
        $error = 'Deze spelcode bestaat niet. Controleer de code op het scherm.';
    } elseif ($game['effective_status'] === 'finished') {
        $error = 'Dit spel is afgelopen; aanmelden kan niet meer.';
    } elseif (!(int)$game['registration_open']) {
        $error = 'De registratie voor dit spel is gesloten. Vraag de docent om hem te openen.';
    } elseif ($emailInput !== '' && $email === null) {
        $error = 'Vul een geldig e-mailadres in, of laat het veld leeg.';
    } else {
        $team = createTeam($db, $game, $teamName, $email, null);
        if (is_string($team)) {
            $error = $team;
        } else {
            recordAttempt($db, 'join_register', $code);
            $link = teamDashboardUrl($team['access_token']);
            if ($email !== null) {
                $result = sendTeamLinkEmail($email, $team['team_name'], $game['name'], $link);
                if ($result !== true) {
                    error_log("join.php: teamlink voor team {$team['id']} niet gemaild: $result");
                }
            }
            redirect('team.php?token=' . urlencode($team['access_token']) . '&welcome=1');
        }
    }
}

$logo = $game ? gameLogoUrl($game) : null;
?>
<?= authPageStart($game ? $game['name'] : 'Aanmelden', ['logo' => $logo, 'subtitle' => 'Meld je team aan en ga op onderzoek uit.', 'css' => '<style>
    .join-code { font-family: var(--font-mono); font-size: 30px; font-weight: 700; text-align: center; letter-spacing: .3em; text-transform: uppercase; padding: 12px; }
    .privacy { display: flex; gap: 8px; font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-top: 18px; }
    .privacy .icon { width: 14px; height: 14px; margin-top: 2px; }
</style>']) ?>
        <?php if ($error): ?><?= alertBox('error', htmlspecialchars($error)) ?><?php endif; ?>
        <form method="POST" action="join.php">
            <?= csrfField() ?>
            <div class="field">
                <label for="j-code">Spelcode</label>
                <input type="text" name="code" id="j-code" class="join-code" value="<?= htmlspecialchars($code) ?>" maxlength="<?= JOIN_CODE_LENGTH ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" required <?= $code === '' ? 'autofocus' : '' ?>>
                <div class="hint">De code staat op het scherm in het lokaal.</div>
            </div>
            <div class="field">
                <label for="j-team">Teamnaam</label>
                <input type="text" name="team_name" id="j-team" value="<?= htmlspecialchars($teamName) ?>" maxlength="<?= TEAM_NAME_MAX_LENGTH ?>" placeholder="Bijv. De Speurders" required <?= $code !== '' ? 'autofocus' : '' ?>>
            </div>
            <div class="field">
                <label for="j-email">E-mailadres <span class="muted">(optioneel)</span></label>
                <input type="email" name="email" id="j-email" value="<?= htmlspecialchars($emailInput) ?>" autocomplete="email">
                <div class="hint">Dan mailen we jullie de link naar het teamdashboard.</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block"><?= icon('login') ?>Meedoen</button>
        </form>
        <p class="privacy"><?= icon('lock') ?><span><?= htmlspecialchars(PRIVACY_NOTICE) ?></span></p>
<?= authPageEnd() ?>
