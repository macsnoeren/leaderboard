<?php
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/game.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("Toegang geweigerd: Geen token opgegeven.");
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM teams WHERE access_token = ?");
$stmt->execute([$token]);
$team = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$team) {
    die("Toegang geweigerd: Ongeldige token.");
}

$game = getGame($db, (int)$team['game_id']);
if (!$game) {
    die("Toegang geweigerd: Dit team hoort niet bij een spel.");
}
$status = $game['effective_status'];
$level = (int)$team['current_level'];

// Het totale aantal opdrachten van dit spel bepaalt het laatste level (N+1 = Finish)
$total_assignments = totalAssignments($db, (int)$game['id']);

// De chat is alleen open in een lopend of gepauzeerd spel, tijdens een opdracht
$chatOpen = in_array($status, ['running', 'paused'], true) && $level >= 1 && $level <= $total_assignments;

// Handle bericht versturen; de server weigert berichten bij een concept- of afgelopen spel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $msg = trim($_POST['message'] ?? '');
    if ($chatOpen && $msg !== '') {
        $stmt = $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message) VALUES (?, ?, 'team', ?)");
        $stmt->execute([$team['id'], $level, $msg]);
    }
    header("Location: team.php?token=" . urlencode($team['access_token']));
    exit;
}

// Positie binnen het spel, met dezelfde criteria als het leaderboard
$rank = teamRank($db, $team);

// Haal het huidige assignment van dit spel op
$stmt = $db->prepare("SELECT * FROM assignments WHERE game_id = ? AND assignment_number = ?");
$stmt->execute([$game['id'], $level]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

// Haal download count op
$stmt = $db->prepare("SELECT download_count FROM team_downloads WHERE team_id = ? AND assignment_number = ?");
$stmt->execute([$team['id'], $level]);
$download_count = $stmt->fetchColumn() ?: 0;
$max_downloads = 10;

// Haal chatberichten op voor dit level
$stmt = $db->prepare("SELECT * FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender != 'suggestion' ORDER BY created_at ASC");
$stmt->execute([$team['id'], $level]);
// AI-berichten zien er voor het team precies uit als docentberichten
$messages = array_map(function ($m) {
    if ($m['sender'] === 'ai') {
        $m['sender'] = 'teacher';
    }
    return $m;
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

// Mededelingen voor alle teams van het spel of voor dit team
$stmt = $db->prepare("SELECT id, message, created_at FROM announcements WHERE game_id = ? AND (team_id IS NULL OR team_id = ?) ORDER BY id ASC");
$stmt->execute([$game['id'], $team['id']]);
$announcements = array_map(function ($a) {
    return ['id' => (int)$a['id'], 'message' => $a['message'], 'time' => formatDateTime($a['created_at'])];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$logo = gameLogoUrl($game);
$hasEnd = $game['ends_at'] !== null;

$levelText = $level >= 1 && $level <= $total_assignments ? "$level/$total_assignments" : levelLabel($level, $total_assignments);
$allDone = $level > $total_assignments && $status !== 'draft';

/**
 * De chat als bubbels. Tijden in lokale tijd via formatDateTime() (de database is UTC).
 */
function teamChatHtml(array $messages): string {
    $html = '';
    foreach ($messages as $m) {
        if ($m['sender'] === 'suggestion') continue;
        $mine = $m['sender'] === 'team';
        $when = formatDateTime($m['created_at']);
        $html .= '<div class="bubble bubble-' . ($mine ? 'team' : 'teacher') . '">'
            . '<div class="bubble-meta"><strong>' . ($mine ? 'Jullie' : 'Operator') . '</strong><time title="' . $when . '">' . substr($when, -5) . '</time></div>'
            . '<div>' . nl2br(htmlspecialchars($m['message'])) . '</div></div>';
    }
    return $html;
}

// AJAX endpoint voor live updates. Tijden komen van de server, zodat de klok van het team niet uitmaakt.
if (isset($_GET['ajax'])) {
    $lastMessage = end($messages);
    header('Content-Type: application/json');
    echo json_encode([
        'html' => teamChatHtml($messages),
        'message_count' => count($messages),
        'last_sender' => $lastMessage ? $lastMessage['sender'] : null,
        'last_message' => $lastMessage ? $lastMessage['message'] : null,
        'level' => $level,
        'rank' => $rank,
        'total_assignments' => $total_assignments,
        'status' => $status,
        'status_message' => $game['status_message'],
        'seconds_left' => $game['seconds_left'] === null ? null : (int)$game['seconds_left'],
        'seconds_elapsed' => $game['seconds_elapsed'] === null ? null : (int)$game['seconds_elapsed'],
        'announcements' => $announcements,
    ]);
    exit;
}
$chatHtml = teamChatHtml($messages);
$leaderboardUrl = 'index.php?game=' . urlencode((string)$game['join_code']);
?>
<!DOCTYPE html>
<html lang="nl" data-theme="ops">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team <?= htmlspecialchars($team['team_name']) ?> - <?= htmlspecialchars($game['name']) ?></title>
    <?= themeMeta('ops') ?>
    <script src="<?= CONFETTI_SRC ?>"></script>
    <?= markedScript() ?>
    <?= themeStyles() ?>
    <style>
        body { height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        /* Kopbalk */
        .team-head { display: flex; align-items: center; gap: clamp(12px, 2vw, 32px); padding: 14px clamp(16px, 2.5vw, 32px); background-color: var(--surface); flex-shrink: 0; flex-wrap: wrap; }
        .th-left { display: flex; align-items: center; gap: 14px; min-width: 0; flex: 1; }
        .th-logo { height: 44px; width: auto; max-width: 140px; object-fit: contain; }
        .team-title { font-size: clamp(18px, 2vw, 26px); font-weight: 800; line-height: 1.15; overflow-wrap: anywhere; }
        .th-stats { display: flex; gap: clamp(14px, 2vw, 32px); }
        .th-stat { display: flex; flex-direction: column; align-items: flex-end; }
        .th-stat .label { font-family: var(--font-mono); font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: var(--text-muted); }
        .th-stat .value { font-family: var(--font-mono); font-size: clamp(18px, 1.8vw, 24px); font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.2; }
        .th-stat .value.urgent { color: var(--danger); }
        .th-actions { display: flex; gap: 6px; }
        .th-actions .count { margin-left: 2px; }
        .th-progress { padding: 0 clamp(16px, 2.5vw, 32px) 12px; background: var(--surface); border-bottom: 1px solid var(--line); flex-shrink: 0; }
        .th-progress .progress { height: 6px; }
        /* Banners */
        .strip { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 10px clamp(16px, 2.5vw, 32px); font-weight: 600; font-size: 15px; border-bottom: 1px solid; flex-shrink: 0; }
        .strip-warning { background: var(--warning-soft); color: var(--warning); border-color: var(--warning); }
        .strip-success { background: var(--success-soft); color: var(--text); border-color: var(--success); }
        .strip-success > .icon { color: var(--success); }
        .strip .copy-field { flex: 1; min-width: 240px; margin: 0; }
        /* Tabs (alleen mobiel) */
        .tabs { display: none; }
        /* Dossier en chat */
        .team-main { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(360px, 1fr); }
        .dossier { overflow-y: auto; padding: clamp(20px, 3vw, 40px); border-right: 1px solid var(--line); }
        .dossier-inner { max-width: 820px; }
        .dossier h1 { font-size: clamp(24px, 2.6vw, 34px); font-weight: 800; letter-spacing: -.01em; margin: 6px 0 10px; }
        .dossier-meta { display: flex; gap: 16px; flex-wrap: wrap; color: var(--text-muted); font-size: 14px; margin-bottom: 20px; }
        .dossier-meta span { display: inline-flex; align-items: center; gap: 6px; }
        .dossier .markdown { font-size: 16px; }
        .instruction { position: relative; margin-top: 28px; padding: 22px 24px 20px; border: 1px solid var(--brand); border-radius: var(--radius); background: var(--brand-soft); }
        .instruction-label { position: absolute; top: -11px; left: 18px; padding: 2px 10px; border-radius: 4px; background: var(--brand); color: var(--on-brand); font-family: var(--font-mono); font-size: 11px; font-weight: 700; letter-spacing: .14em; }
        .download { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 24px; padding: 16px 18px; border: 1px dashed var(--line-strong); border-radius: var(--radius); }
        .download .muted { font-size: 13px; }
        .all-done { text-align: center; padding: 40px 0; display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .all-done .icon { width: 64px; height: 64px; color: var(--gold); stroke-width: 1.5; }
        .chat-panel { display: flex; flex-direction: column; min-height: 0; background: var(--bg); }
        .chat-head { display: flex; justify-content: space-between; align-items: center; padding: 12px 18px; border-bottom: 1px solid var(--line); font-weight: 700; }
        .chat-scroll { position: relative; flex: 1; min-height: 0; overflow-y: auto; padding: 18px; }
        /* Watermerk: logo van het spel vast achter de berichten */
        .chat-watermark { position: relative; flex: 1; min-height: 0; display: flex; flex-direction: column; }
        /* Omgekeerd en grijs: een logo met witte achtergrond wordt zo geen lichte rechthoek op donker */
        .chat-watermark::before { content: ""; position: absolute; inset: 12%; background: var(--logo) center / contain no-repeat; opacity: .07; filter: grayscale(1) invert(1); pointer-events: none; }
        .chat-watermark .chat-scroll { position: relative; z-index: 1; }
        .chat-empty { color: var(--text-muted); text-align: center; margin: auto; padding: 30px; }
        .chat-form { padding: 14px 18px 16px; border-top: 1px solid var(--line); background: var(--surface); display: flex; flex-direction: column; gap: 10px; flex-shrink: 0; }
        .chat-form textarea { min-height: 76px; max-height: 300px; font-size: 15px; }
        .chat-form-foot { display: flex; align-items: center; gap: 12px; }
        .privacy-notice { font-size: 12px; color: var(--text-muted); line-height: 1.4; flex: 1; display: flex; gap: 6px; }
        .privacy-notice .icon { width: 14px; height: 14px; margin-top: 1px; }
        .chat-closed { margin: auto; text-align: center; padding: 40px 24px; color: var(--text-muted); display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .chat-closed .icon { width: 40px; height: 40px; stroke-width: 1.5; }
        .chat-panel.large .bubble { font-size: 19px; max-width: 92%; }
        .chat-panel.large textarea { font-size: 18px; }
        /* Mededelingen */
        .announcement { border-left: 3px solid var(--brand); background: var(--surface-2); padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 12px; }
        .announcement time { display: block; font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); margin-bottom: 6px; }
        /* Schermvullend: wachten, einde, level-up */
        .screen.overlay { position: fixed; inset: 0; z-index: 9000; overflow-y: auto; }
        #level-up-overlay { z-index: 10000; background: rgba(11, 16, 32, .94); display: none; }
        #level-up-overlay.is-open { display: flex; animation: fade-in var(--t) var(--ease); }
        #level-up-overlay .screen-title { font-family: var(--font-mono); text-transform: uppercase; letter-spacing: .04em; }
        @keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
        .rank-big { font-family: var(--font-mono); font-weight: 800; font-size: clamp(40px, 8vw, 96px); line-height: 1; color: var(--gold); }

        @media (max-width: 900px) {
            body { height: auto; min-height: 100vh; overflow: auto; }
            .th-left { flex-basis: 100%; }
            .th-stats { flex: 1; }
            .th-stat { align-items: flex-start; }
            .tabs { display: flex; position: sticky; top: 0; z-index: 50; background: var(--surface); border-bottom: 1px solid var(--line); }
            .tab { flex: 1; display: inline-flex; justify-content: center; align-items: center; gap: 8px; padding: 12px; background: none; border: none; border-bottom: 3px solid transparent; color: var(--text-muted); font: inherit; font-weight: 600; cursor: pointer; }
            .tab[aria-selected="true"] { color: var(--text); border-bottom-color: var(--brand); }
            .team-main { display: block; }
            .dossier { border-right: none; }
            .team-main[data-tab="dossier"] .chat-panel, .team-main[data-tab="chat"] .dossier { display: none; }
            .chat-panel { min-height: calc(100vh - 180px); }
            .chat-scroll { max-height: none; }
        }
    </style>
</head>
<body>
    <header class="team-head zebra-band">
        <div class="th-left">
            <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="th-logo"><?php else: ?><?= brandMark(40) ?><?php endif; ?>
            <div>
                <div class="eyebrow"><?= htmlspecialchars($game['name']) ?></div>
                <div class="team-title">Team <?= htmlspecialchars($team['team_name']) ?></div>
            </div>
        </div>
        <div class="th-stats">
            <div class="th-stat"><span class="label">Positie</span><span class="value">#<span id="display-rank"><?= $rank ?></span></span></div>
            <div class="th-stat"><span class="label">Level</span><span class="value" id="display-level"><?= htmlspecialchars($levelText) ?></span></div>
            <div class="th-stat"><span class="label" id="timer-label"><?= $hasEnd ? 'Resterend' : 'Speeltijd' ?></span><span class="value" id="live-timer">00:00:00</span></div>
        </div>
        <div class="th-actions">
            <button type="button" class="btn btn-ghost" onclick="showAnnouncements(false)"><?= icon('megaphone') ?>Mededelingen<span class="count" id="ann-count" hidden></span></button>
            <a href="<?= htmlspecialchars($leaderboardUrl) ?>" target="_leaderboard" class="btn btn-ghost"><?= icon('trophy') ?>Leaderboard</a>
        </div>
    </header>
    <div class="th-progress"><?= progressBar($level, $total_assignments) ?></div>

    <div class="strip strip-warning" id="pause-banner" role="status"<?= $status === 'paused' ? '' : ' hidden' ?>>
        <?= icon('pause') ?><span id="pause-text"><?= htmlspecialchars($game['status_message'] ?: 'Het spel is even gepauzeerd. Jullie kunnen gewoon doorwerken.') ?></span>
    </div>

    <?php if (isset($_GET['welcome'])): $dashUrl = htmlspecialchars(teamDashboardUrl($team['access_token'])); ?>
        <div class="strip strip-success" id="welcome-banner" role="status">
            <?= icon('check-circle') ?><span>Jullie team is aangemeld! Bewaar deze link, daarmee komen jullie altijd terug op dit dashboard:</span>
            <div class="copy-field"><input type="text" readonly value="<?= $dashUrl ?>" onclick="this.select()" aria-label="Link naar jullie dashboard">
                <button type="button" class="btn btn-secondary" data-copy="<?= $dashUrl ?>"><?= icon('copy') ?><span class="copy-label">Kopiëren</span></button></div>
        </div>
    <?php endif; ?>

    <div class="tabs" role="tablist" aria-label="Weergave">
        <button type="button" class="tab" role="tab" id="tab-dossier" aria-controls="panel-dossier" aria-selected="true" onclick="selectTab('dossier')"><?= icon('file') ?>Dossier</button>
        <button type="button" class="tab" role="tab" id="tab-chat" aria-controls="panel-chat" aria-selected="false" onclick="selectTab('chat')"><?= icon('message') ?>Chat<span class="count" id="chat-badge" hidden>!</span></button>
    </div>

    <main class="team-main" id="team-main" data-tab="dossier">
        <section class="dossier" id="panel-dossier" role="tabpanel" aria-labelledby="tab-dossier">
            <div class="dossier-inner">
                <?php if ($assignment && $chatOpen): ?>
                    <div class="eyebrow">Dossier <?= $level ?> van <?= $total_assignments ?></div>
                    <h1><?= htmlspecialchars($assignment['title']) ?></h1>
                    <?php if ((int)$assignment['time_limit'] > 0): ?>
                        <div class="dossier-meta"><span><?= icon('clock') ?>Verwachte tijd: <?= (int)$assignment['time_limit'] ?> min</span></div>
                    <?php endif; ?>
                    <div class="markdown" data-md><?= htmlspecialchars((string)$assignment['description']) ?></div>
                    <?php if (!empty($assignment['instruction'])): ?>
                        <div class="instruction">
                            <span class="instruction-label">INSTRUCTIE</span>
                            <div class="markdown" data-md><?= htmlspecialchars($assignment['instruction']) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($assignment['artifact_file'])): ?>
                        <div class="download">
                            <?php if ($download_count >= $max_downloads): ?>
                                <button type="button" class="btn btn-primary" disabled><?= icon('download') ?>Bestanden downloaden</button>
                                <span class="muted">Het maximum van <?= $max_downloads ?> downloads voor dit dossier is bereikt.</span>
                            <?php else: ?>
                                <a href="team_download.php?token=<?= htmlspecialchars(urlencode($team['access_token'])) ?>" class="btn btn-primary"><?= icon('download') ?>Bestanden downloaden</a>
                                <span class="muted"><span class="mono"><?= (int)$download_count ?>/<?= $max_downloads ?></span> downloads gebruikt</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($allDone): ?>
                    <div class="all-done">
                        <?= icon('trophy') ?>
                        <div class="eyebrow">Missie voltooid</div>
                        <h1>Alle dossiers gesloten</h1>
                        <p class="muted">Jullie staan op positie <strong>#<?= $rank ?></strong>. Bekijk het leaderboard voor de stand van de andere teams.</p>
                        <a href="<?= htmlspecialchars($leaderboardUrl) ?>" target="_leaderboard" class="btn btn-primary"><?= icon('trophy') ?>Naar het leaderboard</a>
                    </div>
                <?php else: ?>
                    <?= emptyState('file', 'Er is nog geen dossier. Wacht op instructies van de operator.') ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="chat-panel" id="panel-chat" role="tabpanel" aria-labelledby="tab-chat">
            <div class="chat-head">
                <span>Berichten</span>
                <button type="button" class="btn btn-ghost btn-sm" id="btn-zoom" aria-pressed="false" onclick="toggleChatSize()" title="Tekst vergroten">A+</button>
            </div>
            <?php if ($chatOpen): ?>
                <div class="chat-watermark"<?= $logo ? ' style="--logo: url(\'' . htmlspecialchars($logo) . '\')"' : '' ?>>
                    <div class="chat-scroll" id="chat-scroll">
                        <div class="chat chat-team-view" id="chat-box"><?= $chatHtml ?></div>
                        <p class="chat-empty" id="chat-empty"<?= $messages ? ' hidden' : '' ?>>Nog geen berichten. Stel hier een vraag of stuur jullie antwoord.</p>
                    </div>
                </div>
                <div class="sr-only" aria-live="polite" id="chat-live"></div>
                <form method="POST" class="chat-form" id="chat-form">
                    <input type="hidden" name="action" value="send_message">
                    <label for="chat-message" class="sr-only">Bericht</label>
                    <textarea name="message" id="chat-message" rows="3" placeholder="Typ hier jullie antwoord of vraag…" required></textarea>
                    <div class="chat-form-foot">
                        <p class="privacy-notice"><?= icon('lock') ?><span><?= htmlspecialchars(PRIVACY_NOTICE) ?></span></p>
                        <button type="submit" class="btn btn-primary" id="chat-send" title="Versturen (Ctrl+Enter)"><?= icon('send') ?>Versturen</button>
                    </div>
                </form>
            <?php elseif ($allDone): ?>
                <div class="chat-closed"><?= icon('check-circle') ?><p>Jullie hebben alle opdrachten voltooid. De chat is nu gesloten.</p></div>
            <?php else: ?>
                <div class="chat-closed"><?= icon('message') ?><p>De chat wordt geactiveerd zodra het spel begint.</p></div>
            <?php endif; ?>
        </section>
    </main>

    <dialog class="modal" id="announcement-modal" aria-labelledby="announcement-title">
        <div class="modal-header">
            <h2 id="announcement-title">Mededelingen</h2>
            <button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Sluiten"><?= icon('x') ?></button>
        </div>
        <div class="modal-body" id="announcement-list"></div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-close autofocus>Gelezen</button></div>
    </dialog>

    <?php if ($status === 'draft'): ?>
        <div class="screen overlay" id="waiting-screen">
            <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="screen-logo"><?php else: ?><?= brandMark(72) ?><?php endif; ?>
            <div class="eyebrow"><?= htmlspecialchars($game['name']) ?></div>
            <h1 class="screen-title">Team <?= htmlspecialchars($team['team_name']) ?></h1>
            <p class="screen-sub">Wacht op het startsein<span class="waiting-dots" aria-hidden="true"></span></p>
            <button type="button" class="btn btn-secondary" onclick="showAnnouncements(false)"><?= icon('megaphone') ?>Mededelingen</button>
        </div>
    <?php elseif ($status === 'finished'): ?>
        <div class="screen overlay" id="finished-screen">
            <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="screen-logo"><?php else: ?><?= brandMark(72) ?><?php endif; ?>
            <div class="eyebrow">Het spel is afgelopen</div>
            <h1 class="screen-title">Team <?= htmlspecialchars($team['team_name']) ?></h1>
            <div class="rank-big">#<?= $rank ?></div>
            <p class="screen-sub"><?= $level > $total_assignments ? 'Alle opdrachten voltooid' : 'Level ' . max(1, $level) . ' van ' . $total_assignments ?></p>
            <?php if ($game['status_message']): ?>
                <div class="screen-box"><?= htmlspecialchars($game['status_message']) ?></div>
            <?php endif; ?>
            <div class="btn-row" style="justify-content: center;">
                <a href="<?= htmlspecialchars($leaderboardUrl) ?>" class="btn btn-primary"><?= icon('trophy') ?>Eindstand</a>
                <button type="button" class="btn btn-secondary" onclick="showAnnouncements(false)"><?= icon('megaphone') ?>Mededelingen</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="screen overlay" id="level-up-overlay" role="dialog" aria-modal="true" aria-labelledby="overlay-title">
        <div class="eyebrow" id="overlay-eyebrow">Level up</div>
        <h1 class="screen-title" id="overlay-title">Dossier gesloten</h1>
        <p class="screen-sub" id="overlay-message"></p>
        <p class="screen-sub" id="overlay-rank"></p>
        <div class="btn-row" id="overlay-actions" hidden>
            <a href="<?= htmlspecialchars($leaderboardUrl) ?>" target="_leaderboard" class="btn btn-primary"><?= icon('trophy') ?>Leaderboard</a>
            <button type="button" class="btn btn-secondary" onclick="closeOverlay()">Sluiten</button>
        </div>
    </div>

    <?= themeScript() ?>
    <script>
        const teamId = <?= (int)$team['id'] ?>;
        let currentLevel = <?= $level ?>;
        let totalAssignments = <?= $total_assignments ?>;
        let gameStatus = <?= json_encode($status) ?>;
        let announcements = <?= json_encode($announcements, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        let messageCount = <?= count($messages) ?>;
        const chatOpen = <?= $chatOpen ? 'true' : 'false' ?>;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Tijden zijn relatief aan de server: alleen het verschil met nu telt, niet de klok van deze laptop
        let hasEnd = <?= $hasEnd ? 'true' : 'false' ?>;
        let deadline = Date.now() + <?= (int)($game['seconds_left'] ?? 0) ?> * 1000;
        let startedAt = Date.now() - <?= (int)($game['seconds_elapsed'] ?? 0) ?> * 1000;

        function syncClock(data) {
            hasEnd = data.seconds_left !== null;
            if (hasEnd) deadline = Date.now() + data.seconds_left * 1000;
            if (data.seconds_elapsed !== null) startedAt = Date.now() - data.seconds_elapsed * 1000;
            document.getElementById('timer-label').textContent = hasEnd ? 'Resterend' : 'Speeltijd';
        }

        function updateTimer() {
            let diff;
            if (gameStatus === 'draft') diff = 0;
            else if (hasEnd) diff = Math.max(0, deadline - Date.now());
            else diff = Math.max(0, Date.now() - startedAt);
            const s = Math.floor(diff / 1000);
            const timer = document.getElementById('live-timer');
            timer.textContent = [Math.floor(s / 3600), Math.floor(s % 3600 / 60), s % 60].map(v => String(v).padStart(2, '0')).join(':');
            // Laatste 10 minuten rood
            timer.classList.toggle('urgent', hasEnd && gameStatus !== 'draft' && diff <= 10 * 60 * 1000);
        }

        // --- Level-up: overlay met confetti, daarna het nieuwe dossier ---
        function showOverlay(eyebrow, title, message, rankText, isFinal) {
            document.getElementById('overlay-eyebrow').textContent = eyebrow;
            document.getElementById('overlay-title').textContent = title;
            document.getElementById('overlay-message').textContent = message;
            document.getElementById('overlay-rank').textContent = rankText;
            document.getElementById('overlay-actions').hidden = !isFinal;
            document.getElementById('level-up-overlay').classList.add('is-open');
            if (!reduceMotion && window.confetti) confetti({ particleCount: 150, spread: 70, origin: { y: 0.6 } });
            if (isFinal) { try { sessionStorage.setItem('final_seen_' + teamId, '1'); } catch (e) {} }
            else setTimeout(() => location.reload(), 3000);
        }
        function closeOverlay() {
            document.getElementById('level-up-overlay').classList.remove('is-open');
        }

        // --- Tabs op mobiel ---
        function selectTab(tab) {
            document.getElementById('team-main').dataset.tab = tab;
            ['dossier', 'chat'].forEach(t => document.getElementById('tab-' + t).setAttribute('aria-selected', t === tab ? 'true' : 'false'));
            if (tab === 'chat') { document.getElementById('chat-badge').hidden = true; scrollToBottom(); }
            try { sessionStorage.setItem('team_tab_' + teamId, tab); } catch (e) {}
        }
        function isMobile() { return window.matchMedia('(max-width: 900px)').matches; }

        function scrollToBottom() {
            const scroller = document.getElementById('chat-scroll');
            if (scroller) setTimeout(() => { scroller.scrollTop = scroller.scrollHeight; }, 50);
        }

        // --- Mededelingen: nieuwe (hoger id dan de laatst gesloten) verschijnen als pop-up ---
        const annKey = 'announcements_closed_' + teamId;
        const annModal = document.getElementById('announcement-modal');
        let closedInMemory = 0;
        function lastClosedId() {
            try { return Math.max(closedInMemory, parseInt(localStorage.getItem(annKey) || '0', 10) || 0); }
            catch (e) { return closedInMemory; }
        }
        function showAnnouncements(onlyNew) {
            const list = onlyNew ? announcements.filter(a => a.id > lastClosedId()) : announcements;
            const box = document.getElementById('announcement-list');
            document.getElementById('announcement-title').textContent = onlyNew ? 'Nieuwe mededeling' : 'Mededelingen';
            box.innerHTML = '';
            if (list.length === 0) {
                box.innerHTML = '<p class="muted">Er zijn nog geen mededelingen.</p>';
            }
            if (window.marked) marked.setOptions({ breaks: true });
            list.slice().reverse().forEach(a => {
                const item = document.createElement('div');
                item.className = 'announcement';
                const time = document.createElement('time');
                time.textContent = a.time;
                const body = document.createElement('div');
                body.className = 'markdown';
                if (window.marked) body.innerHTML = marked.parse(a.message); else body.textContent = a.message;
                item.appendChild(time);
                item.appendChild(body);
                box.appendChild(item);
            });
            if (!annModal.open) annModal.showModal();
        }
        // Sluiten (knop, Esc of klik ernaast) = gelezen
        annModal.addEventListener('close', () => {
            const maxId = announcements.reduce((max, a) => Math.max(max, a.id), 0);
            closedInMemory = Math.max(closedInMemory, maxId);
            try { localStorage.setItem(annKey, String(closedInMemory)); } catch (e) {}
            checkAnnouncements();
        });
        function checkAnnouncements() {
            const fresh = announcements.filter(a => a.id > lastClosedId()).length;
            const badge = document.getElementById('ann-count');
            badge.hidden = fresh === 0;
            badge.textContent = fresh;
            if (fresh > 0 && !annModal.open) showAnnouncements(true);
        }

        function updatePauseBanner(data) {
            document.getElementById('pause-banner').hidden = data.status !== 'paused';
            document.getElementById('pause-text').textContent = data.status_message || 'Het spel is even gepauzeerd. Jullie kunnen gewoon doorwerken.';
        }

        let lastChatHtml = <?= json_encode($chatHtml, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        function fetchMessages() {
            fetch(window.location.href + '&ajax=1')
                .then(response => response.json())
                .then(data => {
                    syncClock(data);
                    announcements = data.announcements;
                    checkAnnouncements();
                    document.getElementById('display-rank').textContent = data.rank;

                    const chatBox = document.getElementById('chat-box');
                    // Alleen bijwerken en scrollen als er echt iets veranderd is
                    if (chatBox && data.html !== lastChatHtml) {
                        chatBox.innerHTML = data.html;
                        document.getElementById('chat-empty').hidden = data.message_count > 0;
                        scrollToBottom();
                        if (data.message_count > messageCount && data.last_sender && data.last_sender !== 'team') {
                            // Voorlezen en, op mobiel in het dossier, een badge op de chat-tab
                            document.getElementById('chat-live').textContent = 'Nieuw bericht van de operator: ' + data.last_message;
                            if (isMobile() && document.getElementById('team-main').dataset.tab !== 'chat') document.getElementById('chat-badge').hidden = false;
                        }
                        messageCount = data.message_count;
                    }
                    lastChatHtml = data.html;

                    if (data.level > currentLevel) {
                        const closed = data.level - 1;
                        const isFinal = data.level > data.total_assignments;
                        gameStatus = data.status;
                        if (isFinal) {
                            showOverlay('Missie voltooid', 'Alle dossiers gesloten', 'Jullie hebben alle opdrachten opgelost!', `Jullie eindigen op positie #${data.rank}. Fantastisch werk!`, true);
                        } else if (data.level === 1) {
                            showOverlay('Het spel is begonnen', 'Dossier 1 geopend', 'Jullie kunnen beginnen!', '', false);
                        } else {
                            showOverlay('Level up', `Dossier ${closed} gesloten`, `Op naar dossier ${data.level}.`, `Jullie staan nu op positie #${data.rank}.`, false);
                        }
                        currentLevel = data.level;
                        // Na de kampioens-overlay toch herladen als het spel intussen afgelopen is
                        if (isFinal) setTimeout(() => location.reload(), data.status === 'finished' ? 5000 : 6000);
                    } else if (data.level < currentLevel) {
                        // Level terug door de docent: opdracht en gesprek van het vorige level laden
                        location.reload();
                    } else if (data.status !== gameStatus) {
                        const live = ['running', 'paused'];
                        if (live.includes(data.status) && live.includes(gameStatus)) {
                            // Pauze aan of uit: alleen de banner, zodat getypte tekst niet verloren gaat
                            gameStatus = data.status;
                            updatePauseBanner(data);
                        } else {
                            // Start of einde: de pagina ziet er anders uit
                            location.reload();
                        }
                    } else if (gameStatus === 'paused') {
                        updatePauseBanner(data);
                    }
                })
                .catch(err => console.error('Fout bij ophalen updates:', err));
        }

        function toggleChatSize() {
            const chat = document.getElementById('panel-chat');
            const large = chat.classList.toggle('large');
            const btn = document.getElementById('btn-zoom');
            btn.setAttribute('aria-pressed', large ? 'true' : 'false');
            btn.textContent = large ? 'A−' : 'A+';
            btn.title = large ? 'Tekst verkleinen' : 'Tekst vergroten';
            scrollToBottom();
        }

        // Versturen: Ctrl/⌘+Enter, en de knop uit tijdens het versturen (geen dubbele berichten)
        const form = document.getElementById('chat-form');
        if (form) {
            const textarea = document.getElementById('chat-message');
            textarea.addEventListener('keydown', e => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit(); }
            });
            form.addEventListener('submit', () => {
                const btn = document.getElementById('chat-send');
                btn.disabled = true;
                btn.lastChild.textContent = 'Versturen…';
                try { sessionStorage.setItem('team_tab_' + teamId, 'chat'); } catch (e) {}
            });
        }

        // Op mobiel na het versturen terug op de chat-tab
        try { if (sessionStorage.getItem('team_tab_' + teamId) === 'chat') selectTab('chat'); } catch (e) {}

        setInterval(updateTimer, 1000);
        setInterval(fetchMessages, 5000);
        updateTimer();
        window.renderMarkdown();
        scrollToBottom();
        checkAnnouncements();

        // Als het team al op het eindlevel zit bij het laden van de pagina (en het spel nog loopt), feestelijk tonen
        if (currentLevel > totalAssignments && totalAssignments > 0 && (gameStatus === 'running' || gameStatus === 'paused')) {
            const key = 'final_seen_' + teamId;
            let seen = false;
            try { seen = sessionStorage.getItem(key) === '1'; } catch (e) {}
            if (!seen) setTimeout(() => showOverlay('Missie voltooid', 'Kampioenen!', 'Jullie hebben alle levels voltooid!', 'Eindpositie: #' + document.getElementById('display-rank').textContent, true), 500);
        }
    </script>
</body>
</html>
