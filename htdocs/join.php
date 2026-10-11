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
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $game ? htmlspecialchars($game['name']) . ' - ' : '' ?>Aanmelden</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .box { background: white; padding: 35px; border-radius: 15px; width: 100%; max-width: 420px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .logo { display: block; max-width: 100%; max-height: 160px; object-fit: contain; margin: 0 auto 20px; }
        h1 { text-align: center; color: #333; margin-bottom: 5px; font-size: 1.6em; }
        .sub { text-align: center; color: #888; margin-bottom: 25px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; font-weight: bold; color: #444; }
        .hint { font-weight: normal; color: #999; font-size: 0.85em; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 1em; }
        input:focus { border-color: #667eea; outline: none; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15); }
        input.code { font-family: monospace; font-size: 1.6em; text-align: center; letter-spacing: 6px; text-transform: uppercase; }
        button { width: 100%; padding: 14px; background: #667eea; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 1.05em; }
        button:hover { background: #5568d3; }
        .error { background: #ffebee; color: #c62828; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #f44336; }
    </style>
</head>
<body>
    <div class="box">
        <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="logo"><?php endif; ?>
        <h1>🕵️ Aanmelden</h1>
        <p class="sub"><?= $game ? htmlspecialchars($game['name']) : 'Doe mee met je team' ?></p>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" action="join.php">
            <?= csrfField() ?>
            <div class="form-group">
                <label>Spelcode</label>
                <input type="text" name="code" class="code" value="<?= htmlspecialchars($code) ?>" maxlength="<?= JOIN_CODE_LENGTH ?>" autocomplete="off" autocapitalize="characters" required <?= $code === '' ? 'autofocus' : '' ?>>
            </div>
            <div class="form-group">
                <label>Teamnaam</label>
                <input type="text" name="team_name" value="<?= htmlspecialchars($teamName) ?>" maxlength="<?= TEAM_NAME_MAX_LENGTH ?>" placeholder="Bijv. De Speurders" required <?= $code !== '' ? 'autofocus' : '' ?>>
            </div>
            <div class="form-group">
                <label>E-mailadres <span class="hint">(optioneel: dan mailen we jullie de teamlink)</span></label>
                <input type="email" name="email" value="<?= htmlspecialchars($emailInput) ?>" autocomplete="email">
            </div>
            <button type="submit">🚀 Meedoen</button>
        </form>
    </div>
</body>
</html>
