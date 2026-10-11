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
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mededelingen voor alle teams van het spel of voor dit team
$stmt = $db->prepare("SELECT id, message, created_at FROM announcements WHERE game_id = ? AND (team_id IS NULL OR team_id = ?) ORDER BY id ASC");
$stmt->execute([$game['id'], $team['id']]);
$announcements = array_map(function ($a) {
    return ['id' => (int)$a['id'], 'message' => $a['message'], 'time' => formatDateTime($a['created_at'])];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$logo = gameLogoUrl($game);
$hasEnd = $game['ends_at'] !== null;

// AJAX endpoint voor live updates. Tijden komen van de server, zodat de klok van het team niet uitmaakt.
if (isset($_GET['ajax'])) {
    $msg_html = '';
    foreach ($messages as $m) {
        if ($m['sender'] === 'suggestion') continue;
        $msg_html .= '<div class="message ' . $m['sender'] . '">';
        $msg_html .= '<div class="msg-meta">' . ($m['sender'] === 'team' ? 'Jullie' : 'Docent') . ' - ' . $m['created_at'] . '</div>';
        $msg_html .= '<div>' . nl2br(htmlspecialchars($m['message'])) . '</div>';
        $msg_html .= '</div>';
    }
    header('Content-Type: application/json');
    echo json_encode([
        'html' => $msg_html, 
        'level' => $level,
        'rank' => $rank,
        'assignment_title' => $assignment ? htmlspecialchars($assignment['title']) : 'Geen opdracht beschikbaar',
        'assignment_desc' => $assignment ? $assignment['description'] : 'Wacht op instructies.',
        'assignment_instruction' => $assignment ? $assignment['instruction'] : '',
        'time_limit' => $assignment ? (int)$assignment['time_limit'] : 0,
        'has_file' => !empty($assignment['artifact_file']),
        'total_assignments' => $total_assignments,
        'status' => $status,
        'status_message' => $game['status_message'],
        'seconds_left' => $game['seconds_left'] === null ? null : (int)$game['seconds_left'],
        'seconds_elapsed' => $game['seconds_elapsed'] === null ? null : (int)$game['seconds_elapsed'],
        'announcements' => $announcements,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Dashboard - <?= htmlspecialchars($team['team_name']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; height: 100vh; display: flex; flex-direction: column; }
        
        /* Header Styles */
        header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 100; }
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .team-name { font-size: 1.8em; font-weight: bold; }
        .stats-bar { display: flex; gap: 30px; font-size: 1.1em; background: rgba(255,255,255,0.1); padding: 10px 20px; border-radius: 10px; }
        .header-actions { display: flex; align-items: center; gap: 15px; }
        .header-link { color: white; text-decoration: none; font-size: 0.85em; padding: 8px 15px; border: 1px solid rgba(255,255,255,0.4); border-radius: 8px; transition: 0.2s; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .header-link:hover { background: rgba(255,255,255,0.2); border-color: white; }
        
        .btn-assignment { 
            background: #4caf50; 
            color: white; 
            border: none; 
            padding: 8px 20px; 
            border-radius: 8px; 
            font-weight: bold; 
            cursor: pointer; 
            text-transform: uppercase; 
            font-size: 0.85em;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .btn-assignment:hover { background: #43a047; }

        .stat-item { display: flex; flex-direction: column; align-items: center; min-width: 80px; }
        .stat-label { font-size: 0.7em; text-transform: uppercase; opacity: 0.8; letter-spacing: 1px; }
        .stat-value { font-weight: bold; }

        .assignment-meta { display: flex; gap: 20px; margin-top: 15px; font-size: 0.9em; color: #667eea; font-weight: bold; }
        .assignment-detail-title { font-weight: bold; color: #333; margin-top: 25px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .instruction-box { background: #ffffff; padding: 20px 25px; border-radius: 12px; margin-top: 10px; border: 2px solid #667eea; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1); position: relative; }
        .instruction-box::before { content: "INSTRUCTIE"; position: absolute; top: -10px; left: 20px; background: #667eea; color: white; font-size: 0.65em; padding: 2px 8px; border-radius: 4px; font-weight: bold; letter-spacing: 1px; }

        /* Markdown Styling Fixes */
        .assignment-desc, .instruction-box { font-size: 1rem; line-height: 1.6; color: #4b4f56; }
        .assignment-desc h1, .instruction-box h1 { font-size: 1.4em; margin: 15px 0 10px 0; color: #333; }
        .assignment-desc h2, .instruction-box h2 { font-size: 1.25em; margin: 12px 0 8px 0; color: #333; }
        .assignment-desc h3, .instruction-box h3 { font-size: 1.1em; margin: 10px 0 5px 0; color: #333; }
        .assignment-desc p, .instruction-box p { margin-bottom: 12px; }
        .assignment-desc ul, .instruction-box ul, .assignment-desc ol, .instruction-box ol { margin-left: 20px; margin-bottom: 15px; }
        .assignment-desc li, .instruction-box li { margin-bottom: 5px; }
        .assignment-desc code, .instruction-box code { background: #f0f2f5; padding: 2px 5px; border-radius: 4px; font-family: monospace; font-size: 0.9em; color: #e83e8c; }
        .assignment-desc pre, .instruction-box pre { background: #2d2d2d; color: #ccc; padding: 15px; border-radius: 8px; overflow-x: auto; margin-bottom: 15px; }
        .assignment-desc pre code, .instruction-box pre code { background: transparent; padding: 0; color: inherit; font-size: 0.85em; }

        /* Main Content Layout */
        main { display: flex; flex: 1; overflow: hidden; }
        
        /* Assignment Modal */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); overflow-y: auto; align-items: flex-start; justify-content: center; padding: 40px 20px; }
        .modal-content { background: white; width: 100%; max-width: 800px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); position: relative; animation: modalIn 0.3s ease; margin-bottom: 40px; }
        @keyframes modalIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .close-modal { position: absolute; top: 20px; right: 25px; font-size: 30px; font-weight: bold; color: #aaa; cursor: pointer; }
        .assignment-card { padding: 40px; }
        .assignment-title { font-size: 1.5em; font-weight: bold; color: #333; margin-bottom: 15px; border-left: 5px solid #667eea; padding-left: 15px; }
        .assignment-desc { font-size: 1.1em; line-height: 1.6; color: #4b4f56; margin-bottom: 25px; }
        .download-box { background: #f8f9fa; padding: 20px; border-radius: 10px; text-align: center; }
        .download-btn { display: inline-block; background: #667eea; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.3s; margin-bottom: 10px; border: none; cursor: pointer; }
        .download-btn:hover:not(.disabled) { background: #5568d3; transform: translateY(-2px); }
        .download-btn.disabled { background: #ccc; cursor: not-allowed; transform: none; }
        .remaining { font-size: 0.85em; color: #888; }

        /* Chat Section */
        .chat-section { flex: 1; width: 100%; background: white; border-left: none; display: flex; flex-direction: column; overflow: hidden; transition: 0.3s; }
        .chat-header { padding: 15px 20px; border-bottom: 1px solid #eee; font-weight: bold; color: #333; background: #fafafa; display: flex; justify-content: space-between; align-items: center; }
        .messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 10px; background: #f9f9f9; min-height: 0; }
        .message { padding: 10px 15px; border-radius: 12px; max-width: 85%; font-size: 0.95em; position: relative; }
        .message.team { align-self: flex-end; background: #667eea; color: white; border-bottom-right-radius: 2px; }
        .message.teacher { align-self: flex-start; background: #e4e6eb; color: #050505; border-bottom-left-radius: 2px; }

        /* Grote Chat Modus */
        .chat-section.large-mode .message { font-size: 1.25em; padding: 15px 20px; max-width: 90%; }
        .chat-section.large-mode textarea { font-size: 1.2em; }
        .btn-zoom { background: white; border: 1px solid #ddd; padding: 4px 12px; border-radius: 6px; cursor: pointer; font-size: 0.8em; color: #666; transition: 0.2s; }
        .btn-zoom:hover { background: #f0f0f0; border-color: #bbb; }

        .msg-meta { font-size: 0.7em; opacity: 0.7; margin-bottom: 4px; }
        
        .chat-input-area { padding: 20px; border-top: 1px solid #eee; flex-shrink: 0; }
        .chat-form { display: flex; flex-direction: column; gap: 10px; }
        textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; resize: vertical; min-height: 80px; max-height: 400px; font-family: inherit; font-size: 0.95em; }
        textarea:focus { outline: 2px solid #667eea; border-color: transparent; }

        /* Level Up Overlay */
        #level-up-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            color: white;
            text-align: center;
            animation: fadeIn 0.5s ease;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        @media (max-width: 900px) {
            main { overflow-y: auto; }
            .chat-section { border-left: none; border-top: 1px solid #ddd; min-height: 500px; height: auto; }
        }

        /* Pauzebanner en welkomstmelding */
        .notice-banner { padding: 12px 40px; font-weight: 600; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .notice-banner.pause { background: #fff3e0; color: #e65100; border-bottom: 3px solid #ff9800; }
        .notice-banner.welcome { background: #e8f5e9; color: #2e7d32; border-bottom: 3px solid #4caf50; }
        .notice-banner input { flex: 1; min-width: 200px; padding: 6px 10px; border: 1px solid #a5d6a7; border-radius: 6px; font-family: monospace; }
        .notice-banner button { padding: 6px 12px; border: none; border-radius: 6px; background: #4caf50; color: white; font-weight: bold; cursor: pointer; }

        /* Aftelklok: laatste 10 minuten rood */
        .stat-value.urgent { color: #ff5252; text-shadow: 0 0 8px rgba(255,82,82,0.6); }

        /* Watermerk: logo van het spel vast achter de berichten */
        .chat-watermark { position: relative; flex: 1; display: flex; flex-direction: column; min-height: 0; background: #f9f9f9; }
        .chat-watermark::before { content: ""; position: absolute; inset: 10%; background: var(--logo) center / contain no-repeat; opacity: 0.08; pointer-events: none; }
        .chat-watermark .messages { background: transparent; position: relative; z-index: 1; }

        /* Schermvullend wachtscherm en eindscherm */
        .fullscreen { position: fixed; inset: 0; z-index: 9000; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 30px; gap: 20px; overflow-y: auto; }
        .fullscreen .big-logo { max-width: min(80vw, 600px); max-height: 45vh; object-fit: contain; filter: drop-shadow(0 10px 30px rgba(0,0,0,0.3)); }
        .fullscreen h1 { font-size: clamp(1.8em, 5vw, 3.2em); }
        .fullscreen .sub { font-size: clamp(1.1em, 3vw, 1.6em); opacity: 0.9; }
        .fullscreen .status-text { background: rgba(0,0,0,0.25); padding: 15px 25px; border-radius: 12px; font-size: clamp(1em, 2.5vw, 1.4em); max-width: 800px; white-space: pre-line; }
        .fullscreen .header-link { margin-top: 10px; }
        .waiting-dots::after { content: ''; animation: dots 1.5s steps(4, end) infinite; }
        @keyframes dots { 0% { content: ''; } 25% { content: '.'; } 50% { content: '..'; } 75% { content: '...'; } }

        /* Mededelingen */
        .btn-announce { position: relative; background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.4); padding: 8px 15px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.85em; }
        .btn-announce:hover { background: rgba(255,255,255,0.25); }
        .ann-count { position: absolute; top: -8px; right: -8px; background: #f44336; color: white; border-radius: 10px; padding: 1px 6px; font-size: 0.8em; }
        #announcement-modal { z-index: 11000; }
        .announcement { border-left: 4px solid #667eea; background: #f8f9ff; padding: 15px 20px; border-radius: 8px; margin-bottom: 15px; }
        .announcement .ann-time { font-size: 0.8em; color: #888; margin-bottom: 6px; }
        .announcement p { margin-bottom: 8px; }
        .announcement ul, .announcement ol { margin-left: 20px; }

        @media (max-width: 700px) {
            header { padding: 15px; }
            .header-top { flex-direction: column; align-items: flex-start; gap: 10px; }
            .header-actions { flex-wrap: wrap; }
            .stats-bar { gap: 10px; padding: 8px 10px; }
            .notice-banner { padding: 10px 15px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-top">
            <div class="team-name">Team: <?= htmlspecialchars($team['team_name']) ?></div>
            <div class="header-actions">
                <?php if ($assignment && $chatOpen): ?>
                    <button class="btn-assignment" onclick="openAssignmentModal()">📄 Bekijk Opdracht</button>
                <?php endif; ?>
                <button class="btn-announce" onclick="showAnnouncements(false)">📣 Mededelingen<span class="ann-count" id="ann-count" style="display:none;"></span></button>
                
                <a href="index.php?game=<?= urlencode((string)$game['join_code']) ?>" target="_leaderboard" class="header-link">View Public Leaderboard</a>
                <div class="stats-bar">
                    <div class="stat-item">
                        <span class="stat-label">Positie</span>
                        <span class="stat-value">#<span id="display-rank"><?= $rank ?></span></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Level</span>
                        <span class="stat-value"><span id="display-level"><?= levelLabel($level, $total_assignments) ?></span></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label" id="timer-label"><?= $hasEnd ? 'Resterende tijd' : 'Tijd bezig' ?></span>
                        <span class="stat-value" id="live-timer">00:00:00</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="notice-banner pause" id="pause-banner" style="<?= $status === 'paused' ? '' : 'display: none;' ?>">
        ⏸️ <span id="pause-text"><?= htmlspecialchars($game['status_message'] ?: 'Het spel is even gepauzeerd. Jullie kunnen gewoon doorwerken.') ?></span>
    </div>

    <?php if (isset($_GET['welcome'])): ?>
        <div class="notice-banner welcome" id="welcome-banner">
            ✅ Jullie team is aangemeld! Bewaar deze link, daarmee komen jullie altijd terug op dit dashboard:
            <input type="text" readonly value="<?= htmlspecialchars(teamDashboardUrl($team['access_token'])) ?>" onclick="this.select()">
            <button type="button" onclick="copyLink(this)">Kopiëren</button>
        </div>
    <?php endif; ?>

    <main>
        <section id="assignment-modal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeAssignmentModal()">&times;</span>
                <div class="assignment-card">
                    <div class="assignment-title" id="assignment-title">
                        <?= $assignment ? htmlspecialchars($assignment['title']) : 'Geen Opdracht' ?>
                    </div>
                    <div class="assignment-desc" id="assignment-desc"><?= $assignment ? htmlspecialchars($assignment['description']) : 'Er is momenteel geen actieve opdracht voor dit level. Wacht op instructies van de docent.' ?></div>
                    
                    <div class="assignment-meta">
                        <span id="display-time-limit"><?= ($assignment && $assignment['time_limit'] > 0) ? "Verwacht benodigde tijd: " . $assignment['time_limit'] . " min" : "" ?></span>
                    </div>

                    <div id="instruction-wrapper" style="<?= (!$assignment || empty($assignment['instruction'])) ? 'display:none;' : '' ?>">
                        <div class="instruction-box" id="assignment-instruction"><?= $assignment ? htmlspecialchars($assignment['instruction']) : '' ?></div>
                    </div>

                    <div class="download-box" id="download-box" style="<?= (!$assignment || empty($assignment['artifact_file'])) ? 'display: none;' : '' ?>">
                        <p style="margin-bottom: 15px; font-weight: bold; color: #333;">Download de opdrachtdocumentatie:</p>
                        <a href="team_download.php?token=<?= htmlspecialchars($team['access_token']) ?>" class="download-btn" id="dl-link">📄 Bestanden Downloaden (PDF/ZIP)</a>
                        <div class="remaining">Downloads gebruikt: <span id="display-dl-count"><?= $download_count ?></span> / <?= $max_downloads ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section id="announcement-modal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeAnnouncements()">&times;</span>
                <div class="assignment-card">
                    <div class="assignment-title" id="announcement-title">📣 Mededelingen</div>
                    <div id="announcement-list"></div>
                    <div style="text-align: right; margin-top: 10px;">
                        <button class="download-btn" style="margin: 0;" onclick="closeAnnouncements()">Gelezen</button>
                    </div>
                </div>
            </div>
        </section>

        <section class="chat-section" id="chat-container">
            <div class="chat-header">
                <span>Berichten & Antwoorden</span>
                <button class="btn-zoom" onclick="toggleChatSize()">🔍 Tekst vergroten</button>
            </div>
            <?php if ($chatOpen): ?>
                <div class="chat-watermark"<?= $logo ? ' style="--logo: url(\'' . htmlspecialchars($logo) . '\')"' : '' ?>>
                    <div class="messages" id="chat-box">
                        <?php foreach ($messages as $m): ?>
                            <div class="message <?= $m['sender'] ?>">
                                <div class="msg-meta"><?= $m['sender'] === 'team' ? 'Jullie' : 'Docent' ?> - <?= $m['created_at'] ?></div>
                                <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="chat-input-area">
                    <form method="POST" class="chat-form">
                        <input type="hidden" name="action" value="send_message">
                        <textarea name="message" rows="3" placeholder="Typ hier je antwoord of vraag..." required></textarea>
                        <button type="submit" class="download-btn" style="width:100%; margin:0;">Bericht versturen</button>
                    </form>
                </div>
            <?php elseif ($level > $total_assignments && $status !== 'draft'): ?>
                <div class="messages" style="justify-content: center; align-items: center; text-align: center; color: #2e7d32; padding: 40px;">
                    <div>
                        <h2 style="margin-bottom: 10px;">🏆 Gefeliciteerd!</h2>
                        <p>Jullie hebben alle opdrachten voltooid. De chat is nu gesloten.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="messages" style="justify-content: center; align-items: center; text-align: center; color: #888;">
                    De chat wordt geactiveerd zodra het spel begint.
                </div>
            <?php endif; ?>
        </section>
    </main>

    <?php if ($status === 'draft'): ?>
        <div class="fullscreen" id="waiting-screen">
            <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="big-logo"><?php endif; ?>
            <h1>Team <?= htmlspecialchars($team['team_name']) ?></h1>
            <div class="sub">Wacht op het startsein<span class="waiting-dots"></span></div>
            <button class="btn-announce" onclick="showAnnouncements(false)">📣 Mededelingen</button>
        </div>
    <?php elseif ($status === 'finished'): ?>
        <div class="fullscreen" id="finished-screen">
            <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="big-logo"><?php endif; ?>
            <h1>🏁 Het spel is afgelopen</h1>
            <?php if ($game['status_message']): ?>
                <div class="status-text"><?= htmlspecialchars($game['status_message']) ?></div>
            <?php endif; ?>
            <div class="sub">Team <?= htmlspecialchars($team['team_name']) ?> eindigt op positie <strong>#<?= $rank ?></strong>
                (<?= $level > $total_assignments ? 'alle opdrachten voltooid' : 'level ' . max(1, $level) . ' van ' . $total_assignments ?>)</div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;">
                <a href="index.php?game=<?= urlencode((string)$game['join_code']) ?>" class="header-link">🏆 Eindstand</a>
                <button class="btn-announce" onclick="showAnnouncements(false)">📣 Mededelingen</button>
            </div>
        </div>
    <?php endif; ?>

    <div id="level-up-overlay">
        <h1 id="overlay-title" style="font-size: 4em; margin-bottom: 20px;">🎉 GEFELICITEERD! 🎉</h1>
        <p id="overlay-message" style="font-size: 2em; opacity: 0.9;">Jullie zijn naar het volgende level!</p>
        <p id="overlay-rank" style="font-size: 1.5em; opacity: 0.8; margin-top: 10px;"></p>
    </div>

    <script>
        const teamId = <?= (int)$team['id'] ?>;
        let currentLevel = <?= $level ?>;
        let totalAssignments = <?= $total_assignments ?>;
        let gameStatus = <?= json_encode($status) ?>;
        let announcements = <?= json_encode($announcements) ?>;

        // Tijden zijn relatief aan de server: alleen het verschil met nu telt, niet de klok van deze laptop
        let hasEnd = <?= $hasEnd ? 'true' : 'false' ?>;
        let deadline = Date.now() + <?= (int)($game['seconds_left'] ?? 0) ?> * 1000;
        let startedAt = Date.now() - <?= (int)($game['seconds_elapsed'] ?? 0) ?> * 1000;

        function syncClock(data) {
            hasEnd = data.seconds_left !== null;
            if (hasEnd) deadline = Date.now() + data.seconds_left * 1000;
            if (data.seconds_elapsed !== null) startedAt = Date.now() - data.seconds_elapsed * 1000;
            document.getElementById('timer-label').innerText = hasEnd ? 'Resterende tijd' : 'Tijd bezig';
        }

        function triggerLevelUp(isFinal = false) {
            const overlay = document.getElementById('level-up-overlay');
            if (overlay) overlay.style.display = 'flex';

            confetti({
                particleCount: 150,
                spread: 70,
                origin: { y: 0.6 }
            });
            
            if (!isFinal) {
                setTimeout(() => {
                    location.reload();
                }, 3000);
            }
        }

        function updateTimer() {
            let diff;
            if (gameStatus === 'draft') {
                diff = 0;
            } else if (hasEnd) {
                diff = Math.max(0, deadline - Date.now());
            } else {
                diff = Math.max(0, Date.now() - startedAt);
            }

            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            const display = 
                (hours < 10 ? "0" + hours : hours) + ":" + 
                (minutes < 10 ? "0" + minutes : minutes) + ":" + 
                (seconds < 10 ? "0" + seconds : seconds);

            const timer = document.getElementById('live-timer');
            timer.innerText = display;
            // Laatste 10 minuten rood
            timer.classList.toggle('urgent', hasEnd && gameStatus !== 'draft' && diff <= 10 * 60 * 1000);
        }

        function renderMarkdown() {
            marked.setOptions({ breaks: true });
            const fields = ['assignment-desc', 'assignment-instruction'];
            fields.forEach(id => {
                const el = document.getElementById(id);
                if (el && el.getAttribute('data-rendered') !== 'true') {
                    const raw = el.textContent;
                    el.innerHTML = marked.parse(raw);
                    el.setAttribute('data-rendered', 'true');
                }
            });
        }

        function openAssignmentModal() {
            document.getElementById('assignment-modal').style.display = 'flex';
            renderMarkdown();
        }

        function closeAssignmentModal() {
            document.getElementById('assignment-modal').style.display = 'none';
        }

        function scrollToBottom() {
            const chatBox = document.getElementById('chat-box');
            if (chatBox) {
                setTimeout(() => {
                    chatBox.scrollTop = chatBox.scrollHeight;
                }, 50);
            }
        }

        // --- Mededelingen: nieuwe (hoger id dan de laatst gesloten) verschijnen als pop-up ---
        const annKey = 'announcements_closed_' + teamId;
        let closedInMemory = 0;
        function lastClosedId() {
            try { return Math.max(closedInMemory, parseInt(localStorage.getItem(annKey) || '0', 10) || 0); }
            catch (e) { return closedInMemory; }
        }
        function annModalOpen() {
            return document.getElementById('announcement-modal').style.display === 'flex';
        }
        function showAnnouncements(onlyNew) {
            const list = onlyNew ? announcements.filter(a => a.id > lastClosedId()) : announcements;
            const box = document.getElementById('announcement-list');
            document.getElementById('announcement-title').innerText = onlyNew ? '📣 Nieuwe mededeling' : '📣 Mededelingen';
            box.innerHTML = '';
            if (list.length === 0) {
                box.innerHTML = '<p style="color: #888;">Er zijn nog geen mededelingen.</p>';
            }
            marked.setOptions({ breaks: true });
            list.slice().reverse().forEach(a => {
                const item = document.createElement('div');
                item.className = 'announcement';
                const time = document.createElement('div');
                time.className = 'ann-time';
                time.innerText = a.time;
                const body = document.createElement('div');
                body.innerHTML = marked.parse(a.message);
                item.appendChild(time);
                item.appendChild(body);
                box.appendChild(item);
            });
            document.getElementById('announcement-modal').style.display = 'flex';
        }
        function closeAnnouncements() {
            const maxId = announcements.reduce((max, a) => Math.max(max, a.id), 0);
            closedInMemory = Math.max(closedInMemory, maxId);
            try { localStorage.setItem(annKey, String(closedInMemory)); } catch (e) {}
            document.getElementById('announcement-modal').style.display = 'none';
            checkAnnouncements();
        }
        function checkAnnouncements() {
            const fresh = announcements.filter(a => a.id > lastClosedId()).length;
            const badge = document.getElementById('ann-count');
            badge.style.display = fresh > 0 ? 'inline' : 'none';
            badge.innerText = fresh;
            if (fresh > 0 && !annModalOpen()) showAnnouncements(true);
        }

        function updatePauseBanner(data) {
            document.getElementById('pause-banner').style.display = data.status === 'paused' ? '' : 'none';
            document.getElementById('pause-text').innerText = data.status_message || 'Het spel is even gepauzeerd. Jullie kunnen gewoon doorwerken.';
        }

        function fetchMessages() {
            fetch(window.location.href + '&ajax=1')
                .then(response => response.json())
                .then(data => {
                    syncClock(data);
                    announcements = data.announcements;
                    checkAnnouncements();

                    const chatBox = document.getElementById('chat-box');
                    // Alleen scrollen en updaten als er daadwerkelijk nieuwe berichten zijn
                    if (chatBox && chatBox.innerHTML !== data.html) {
                        chatBox.innerHTML = data.html;
                        scrollToBottom();
                    }
                    if (data.level > currentLevel) {
                        currentLevel = data.level;
                        gameStatus = data.status;
                        // Update UI onmiddellijk voor de reload
                        document.getElementById('display-level').innerText = data.level > data.total_assignments ? 'Finish' : data.level;
                        document.getElementById('display-rank').innerText = data.rank;
                        document.getElementById('assignment-title').innerText = data.assignment_title;
                        document.getElementById('assignment-desc').textContent = data.assignment_desc;
                        document.getElementById('assignment-desc').removeAttribute('data-rendered');

                        document.getElementById('display-time-limit').innerText = data.time_limit > 0 ? "⏳ Beschikbare tijd: " + data.time_limit + " min" : "";
                        
                        const instrWrapper = document.getElementById('instruction-wrapper');
                        instrWrapper.style.display = data.assignment_instruction ? 'block' : 'none';
                        document.getElementById('assignment-instruction').textContent = data.assignment_instruction;
                        document.getElementById('assignment-instruction').removeAttribute('data-rendered');
                      
                        const downloadBox = document.getElementById('download-box');

                        // Check for final level completion
                        if (data.level > data.total_assignments) { // Aangepast: > in plaats van >=
                            document.getElementById('overlay-title').innerText = "🏆 GEFELICITEERD KAMPIOENEN! 🏆";
                            document.getElementById('overlay-message').innerText = "Jullie hebben ALLE levels voltooid!";
                            document.getElementById('overlay-rank').innerText = `Jullie eindigen op positie #${data.rank}! Fantastisch werk!`;
                        } else if (data.level === 1) {
                            document.getElementById('overlay-title').innerText = "🕵️ OPDRACHT BINNEN! 🕵️";
                            document.getElementById('overlay-message').innerText = "Jullie kunnen beginnen!";
                            document.getElementById('overlay-rank').innerText = '';
                        } else {
                            document.getElementById('overlay-title').innerText = "🎉 GEFELICITEERD! 🎉";
                            document.getElementById('overlay-message').innerText = `Jullie zijn naar level ${data.level}!`; // Toont het nieuwe level
                            document.getElementById('overlay-rank').innerText = ''; // Leeg maken voor normale level-up
                        }

                        if (downloadBox) {
                            downloadBox.style.display = data.has_file ? 'block' : 'none';
                        }

                        const isFinal = data.level > data.total_assignments;
                        triggerLevelUp(isFinal);
                        if (!isFinal) setTimeout(openAssignmentModal, 1500);
                        // Na de kampioens-overlay toch herladen als het spel intussen afgelopen is
                        if (isFinal && data.status === 'finished') setTimeout(() => location.reload(), 5000);
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
                    renderMarkdown();
                })
                .catch(err => console.error('Fout bij ophalen updates:', err));
        }

        setInterval(updateTimer, 1000);
        setInterval(fetchMessages, 5000);
        updateTimer();
        renderMarkdown();
        scrollToBottom();
        checkAnnouncements();

        // Open de opdracht popup alleen de eerste keer dat een level geladen wordt in deze browser
        const modalKey = 'assignment_seen_' + teamId + '_lvl_' + currentLevel;
        let seen = false;
        try { seen = localStorage.getItem(modalKey) === 'true'; } catch (e) {}
        if (<?= $chatOpen ? 'true' : 'false' ?> && !seen && !annModalOpen()) {
            openAssignmentModal();
            try { localStorage.setItem(modalKey, 'true'); } catch (e) {}
        }

        // Als het team al op het eindlevel zit bij het laden van de pagina (en het spel nog loopt), toon de overlay direct
        if (currentLevel > totalAssignments && (gameStatus === 'running' || gameStatus === 'paused')) {
            const rank = document.getElementById('display-rank').innerText;
            document.getElementById('overlay-title').innerText = "🏆 KAMPIOENEN! 🏆";
            document.getElementById('overlay-message').innerText = "Jullie hebben ALLE levels voltooid!";
            document.getElementById('overlay-rank').innerText = `Eindpositie: #${rank}`;
            
            // Kleine vertraging voor de visuele impact
            setTimeout(() => triggerLevelUp(true), 500);
        }

        // Sluit modals als je buiten de box klikt
        window.onclick = function(event) {
            if (event.target == document.getElementById('assignment-modal')) closeAssignmentModal();
            if (event.target == document.getElementById('announcement-modal')) closeAnnouncements();
        }

        function toggleChatSize() {
            const chat = document.getElementById('chat-container');
            chat.classList.toggle('large-mode');
            const btn = document.querySelector('.btn-zoom');
            btn.innerText = chat.classList.contains('large-mode') ? '🔍 Tekst verkleinen' : '🔍 Tekst vergroten';
            scrollToBottom();
        }

        function copyLink(btn) {
            const input = btn.previousElementSibling;
            input.select();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(() => { btn.innerText = 'Gekopieerd'; });
            } else {
                document.execCommand('copy');
                btn.innerText = 'Gekopieerd';
            }
        }
    </script>
</body>
</html>