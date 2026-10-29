<?php
// De pagina heeft requireTeacher()/requireAdmin() al aangeroepen; dit is alleen een vangnet.
// Daar zit ook de controle op "wachtwoord wijzigen verplicht".
if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../conf/game.php';

$db = getDB();

// Spelkiezer: de pagina heeft $game al als hij requireGame() aanroept; anders het spel uit de sessie
$headerUser = findTeacher($db, (int)$_SESSION['teacher_id']);
$headerGames = $headerUser ? accessibleGames($db, $headerUser) : [];
$headerGame = $game ?? null;
if (!$headerGame) {
    foreach ($headerGames as $g) {
        if ((int)$g['id'] === (int)($_SESSION['game_id'] ?? 0)) {
            $headerGame = $g;
        }
    }
}
$unread_total = $headerGame ? countUnread($db, (int)$headerGame['id']) : 0;
$escalations_total = $headerGame ? countOpenEscalations($db, (int)$headerGame['id']) : 0;
$currentPage = basename($_SERVER['PHP_SELF']);
$isAdminUser = ($_SESSION['teacher_role'] ?? 'user') === 'admin';
// Darknet-chat onder "Tools"; leeg in config.php = niet tonen
$darknetUrl = defined('DARKNET_CHAT_URL') ? trim((string)DARKNET_CHAT_URL) : 'https://zebrawavesecurity.info/_dark_web_chat/';

// Navigatie: [pagina, label, icoon, pagina's die dit item actief maken]
$navGroups = [
    'Spel' => [
        ['teacher.php', 'Dashboard', 'dashboard', ['teacher.php']],
        ['messages.php', 'Berichten', 'message', ['messages.php']],
        ['assignments.php', 'Opdrachten', 'book', ['assignments.php']],
        ['announcements.php', 'Mededelingen', 'megaphone', ['announcements.php']],
        ['ai_log.php', 'AI-logboek', 'bot', ['ai_log.php']],
        ['game.php', 'Spelinstellingen', 'sliders', ['game.php', 'choose_game.php']],
        ['jobs.php', 'Taken', 'inbox', ['jobs.php']],
    ],
];
if ($isAdminUser) {
    $navGroups['Beheer'] = [
        ['games.php', 'Spellen', 'gamepad', ['games.php']],
        ['users.php', 'Gebruikers', 'users', ['users.php', 'user_edit.php']],
        ['api_keys.php', 'API-sleutels', 'key', ['api_keys.php']],
        ['archive.php', 'Archief', 'archive', ['archive.php']],
        ['audit.php', 'Auditlog', 'clipboard', ['audit.php']],
        ['backups.php', 'Backups', 'save', ['backups.php']],
    ];
}
$navGroups['Tools'] = [
    ['ai_uitleg.php', 'Zo werkt de AI', 'info', ['ai_uitleg.php']],
];

function navBadges(int $unread, int $escalations): array {
    return [
        $unread > 0 ? '<span class="count" title="Ongelezen berichten">' . $unread . '<span class="sr-only"> ongelezen</span></span>' : '',
        $escalations > 0 ? '<span class="count count-warning" title="Operator nodig">' . icon('hand') . $escalations . '<span class="sr-only"> keer operator nodig</span></span>' : '',
    ];
}
[$unreadBadge, $escalationBadge] = navBadges($unread_total, $escalations_total);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - OperationRoom</title>
    <?= themeMeta() ?>
    <?php if ($loadMarked ?? true): // profile.php zet dit uit: de 2FA-pagina laadt alleen scripts van cdnjs ?>
    <?= markedScript() ?>
    <?php endif; ?>
    <?= themeStyles() ?>
    <style>
        /* ---------- Admin-shell ---------- */
        :root { --sidebar-w: 248px; }
        .skip-link { position: absolute; left: 8px; top: -60px; z-index: 5000; background: var(--brand); color: var(--on-brand); padding: 8px 14px; border-radius: var(--radius-sm); }
        .skip-link:focus { top: 8px; }
        .sidebar { position: fixed; inset: 0 auto 0 0; width: var(--sidebar-w); z-index: 1000; display: flex; flex-direction: column; background: var(--bg); color: var(--text); border-right: 1px solid var(--line); }
        .sidebar-brand { display: flex; align-items: center; gap: 10px; padding: 18px 20px; font-weight: 800; font-size: 17px; letter-spacing: .02em; color: var(--text); text-decoration: none; }
        .sidebar-brand:hover { color: var(--text); }
        .sidebar-brand small { display: block; font-weight: 500; font-size: 11px; color: var(--text-muted); letter-spacing: .08em; text-transform: uppercase; }
        .sidebar-zebra { height: 4px; background: repeating-linear-gradient(-45deg, var(--brand) 0 6px, transparent 6px 12px); opacity: .7; }
        .game-picker { padding: 14px 20px; border-bottom: 1px solid var(--line); }
        .game-picker .field-label { font-size: 11px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
        .game-picker select { background: var(--surface); }
        .game-picker .game-name { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 8px 10px 12px; }
        .nav-group-label { font-size: 11px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); padding: 14px 10px 6px; }
        .nav-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: var(--radius-sm); color: var(--text-muted); text-decoration: none; font-size: 14px; font-weight: 500; transition: background var(--t-fast), color var(--t-fast); }
        .nav-item:hover { background: var(--surface-2); color: var(--text); }
        .nav-item.active { background: var(--brand-soft); color: var(--text); font-weight: 600; box-shadow: inset 3px 0 0 var(--brand); }
        .nav-item.active .icon { color: var(--brand); }
        .nav-item .badges { margin-left: auto; display: inline-flex; gap: 4px; }
        .nav-item .count .icon { width: 12px; height: 12px; margin-right: 2px; }
        .nav-item .ext { width: 14px; height: 14px; margin-left: auto; opacity: .7; }
        .sidebar-foot { border-top: 1px solid var(--line); padding: 12px 10px 14px; }
        .user-block { display: flex; align-items: center; gap: 10px; padding: 6px 10px 10px; font-size: 14px; min-width: 0; }
        .user-block .avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--surface-2); border: 1px solid var(--line); display: grid; place-items: center; color: var(--text-muted); flex-shrink: 0; }
        .user-block .who { min-width: 0; }
        .user-block .who strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .user-block .who span { font-size: 12px; color: var(--text-muted); }
        .ai-pill { display: inline-flex; align-items: center; gap: 8px; margin: 8px 10px 0; padding: 4px 12px; border-radius: var(--radius-pill); border: 1px solid var(--line); background: var(--surface); font-size: 12px; color: var(--text-muted); max-width: calc(100% - 20px); }
        .ai-pill .icon { width: 14px; height: 14px; }
        .ai-pill .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--line-strong); flex-shrink: 0; }
        .ai-pill[data-state="active"] .dot { background: var(--success); }
        .ai-pill[data-state="active"] { color: var(--text); }
        .ai-pill[data-state="model_down"] .dot { background: var(--warning); }
        .ai-pill[data-state="model_down"] { color: var(--warning); }
        .main-content { margin-left: var(--sidebar-w); padding: 32px 40px 48px; min-width: 0; min-height: 100vh; }
        .main-content.is-app { padding: 0; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        .main-content.is-app > .alert { margin: 16px 24px 0; flex-shrink: 0; }
        .topbar { display: none; }
        .sidebar-overlay { position: fixed; inset: 0; background: rgba(8,12,24,.55); z-index: 999; }
        @media (max-width: 999px) {
            .topbar { display: flex; align-items: center; gap: 12px; position: sticky; top: 0; z-index: 900; padding: 10px 16px; background: var(--bg); color: var(--text); border-bottom: 1px solid var(--line); }
            .topbar .brand { display: flex; align-items: center; gap: 8px; font-weight: 800; }
            .topbar .game { margin-left: auto; font-size: 13px; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
            .sidebar { transform: translateX(-100%); transition: transform var(--t) var(--ease); box-shadow: var(--shadow-2); }
            .sidebar.is-open { transform: none; }
            .main-content { margin-left: 0; padding: 20px 16px 40px; }
            .main-content.is-app { height: auto; overflow: visible; display: block; padding: 20px 16px 40px; }
            .main-content.is-app > .alert { margin: 0 0 16px; }
        }
    </style>
    <?php if (isset($extraCSS)) echo $extraCSS; ?>
</head>
<body class="admin">
    <a class="skip-link" href="#main">Naar de inhoud</a>
    <header class="topbar" data-theme="ops">
        <button type="button" class="btn btn-ghost btn-icon" id="sidebar-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="Menu openen"><?= icon('menu') ?></button>
        <span class="brand"><?= brandMark(24) ?> OperationRoom</span>
        <?php if ($headerGame): ?><span class="game"><?= htmlspecialchars($headerGame['name']) ?></span><?php endif; ?>
    </header>
    <nav class="sidebar" id="sidebar" data-theme="ops" aria-label="Hoofdmenu">
        <a href="teacher.php" class="sidebar-brand"><?= brandMark(30) ?><span>OperationRoom<small>Operators</small></span></a>
        <div class="sidebar-zebra" aria-hidden="true"></div>
        <div class="game-picker">
            <div class="field-label" id="game-picker-label">Actief spel</div>
            <?php if (count($headerGames) > 1): ?>
                <form method="POST" action="choose_game.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="return" value="<?= htmlspecialchars($currentPage) ?>">
                    <select name="game_id" onchange="this.form.submit()" aria-labelledby="game-picker-label">
                        <?php if (!$headerGame): ?><option value="">Kies een spel…</option><?php endif; ?>
                        <?php foreach ($headerGames as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= $headerGame && (int)$headerGame['id'] === (int)$g['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['name']) ?> (<?= htmlspecialchars(gameStatusLabel($g['effective_status'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn-secondary btn-sm" style="margin-top: 6px;">Wissel</button></noscript>
                </form>
            <?php elseif ($headerGame): ?>
                <div class="game-name"><span><?= htmlspecialchars($headerGame['name']) ?></span><?= gameStatusBadge($headerGame['effective_status']) ?></div>
            <?php else: ?>
                <span class="muted text-sm">Nog geen spel</span>
            <?php endif; ?>
        </div>
        <div class="sidebar-nav">
            <?php foreach ($navGroups as $groupLabel => $items): ?>
                <div class="nav-group-label"><?= htmlspecialchars($groupLabel) ?></div>
                <?php foreach ($items as [$href, $label, $iconName, $activeOn]): $navActive = in_array($currentPage, $activeOn, true); ?>
                    <a href="<?= $href ?>" class="nav-item<?= $navActive ? ' active' : '' ?>"<?= $navActive ? ' aria-current="page"' : '' ?>>
                        <?= icon($iconName) ?><span><?= htmlspecialchars($label) ?></span>
                        <?php if ($href === 'messages.php'): ?>
                            <span class="badges"><span id="unread-badge-container"><?= $unreadBadge ?></span><span id="escalation-badge-container"><?= $escalationBadge ?></span></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($darknetUrl !== ''): ?>
                <a href="<?= htmlspecialchars($darknetUrl) ?>" target="_blank" rel="noopener" class="nav-item">
                    <?= icon('terminal') ?><span>Darknet-chat</span><?= icon('external', 'ext') ?><span class="sr-only">(opent in een nieuw tabblad)</span>
                </a>
            <?php endif; ?>
        </div>
        <div class="sidebar-foot">
            <div class="user-block">
                <span class="avatar"><?= icon('user') ?></span>
                <span class="who">
                    <strong><?= htmlspecialchars($_SESSION['teacher_display_name'] ?? $_SESSION['teacher_username'] ?? 'Operator') ?></strong>
                    <span><?= $isAdminUser ? 'Beheerder' : 'Operator' ?></span>
                </span>
            </div>
            <a href="profile.php" class="nav-item<?= in_array($currentPage, ['profile.php', 'password.php'], true) ? ' active' : '' ?>"><?= icon('user') ?><span>Profiel</span></a>
            <a href="logout.php" class="nav-item"><?= icon('logout') ?><span>Uitloggen</span></a>
            <span class="ai-pill" id="ai-agent-status" data-state="unknown" role="status" title="Status van de AI-agent">
                <span class="dot"></span><?= icon('bot') ?><span class="label">AI-agent: controleren…</span>
            </span>
        </div>
    </nav>
    <div class="sidebar-overlay" id="sidebar-overlay" hidden></div>
    <main class="main-content<?= !empty($mainClass) ? ' ' . htmlspecialchars($mainClass) : '' ?>" id="main">
        <?php if (!isEmailEnabled()): ?>
            <?= alertBox('warning', '<strong>Testmodus:</strong> het versturen van e-mail staat uit in de configuratie. Er worden geen uitnodigingen of teamlinks gemaild.') ?>
        <?php endif; ?>
