<?php
require_once '../conf/config.php';
require_once '../conf/database.php';

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

// Handle bericht versturen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $msg = trim($_POST['message'] ?? '');
    if (!empty($msg)) {
        $stmt = $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message) VALUES (?, ?, 'team', ?)");
        $stmt->execute([$team['id'], $team['current_level'], $msg]);
        header("Location: team.php?token=" . $token);
        exit;
    }
}

// Bereken de ranking op basis van dezelfde criteria als het leaderboard
$allTeams = $db->query("SELECT id FROM teams ORDER BY current_level DESC, level_updated_at ASC, team_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$rank = array_search($team['id'], $allTeams) + 1;

// Verkrijg de timestamp van wanneer het huidige level is gestart
$startTime = strtotime($team['level_updated_at']);

// Haal het huidige assignment op
$stmt = $db->prepare("SELECT * FROM assignments WHERE assignment_number = ?");
$stmt->execute([$team['current_level']]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

// Haal download count op
$stmt = $db->prepare("SELECT download_count FROM team_downloads WHERE team_id = ? AND assignment_number = ?");
$stmt->execute([$team['id'], $team['current_level']]);
$download_count = $stmt->fetchColumn() ?: 0;
$max_downloads = 10;

// Haal chatberichten op voor dit level
$stmt = $db->prepare("SELECT * FROM team_messages WHERE team_id = ? AND assignment_number = ? ORDER BY created_at ASC");
$stmt->execute([$team['id'], $team['current_level']]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AJAX endpoint voor live updates
if (isset($_GET['ajax'])) {
    $msg_html = '';
    foreach ($messages as $m) {
        $msg_html .= '<div class="message ' . $m['sender'] . '">';
        $msg_html .= '<div class="msg-meta">' . ($m['sender'] === 'team' ? 'Jullie' : 'Docent') . ' - ' . $m['created_at'] . '</div>';
        $msg_html .= '<div>' . nl2br(htmlspecialchars($m['message'])) . '</div>';
        $msg_html .= '</div>';
    }
    header('Content-Type: application/json');
    echo json_encode([
        'html' => $msg_html, 
        'level' => (int)$team['current_level'],
        'rank' => $rank,
        'assignment_title' => $assignment ? htmlspecialchars($assignment['title']) : 'Geen opdracht beschikbaar',
        'assignment_desc' => $assignment ? nl2br(htmlspecialchars($assignment['description'])) : 'Wacht op instructies.',
        'start_time' => $startTime
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Dashboard - <?= htmlspecialchars($team['team_name']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; height: 100vh; display: flex; flex-direction: column; }
        
        /* Header Styles */
        header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 100; }
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .team-name { font-size: 1.8em; font-weight: bold; }
        .stats-bar { display: flex; gap: 30px; font-size: 1.1em; background: rgba(255,255,255,0.1); padding: 10px 20px; border-radius: 10px; }
        .stat-item { display: flex; flex-direction: column; align-items: center; }
        .stat-label { font-size: 0.7em; text-transform: uppercase; opacity: 0.8; letter-spacing: 1px; }
        .stat-value { font-weight: bold; }

        /* Main Content Layout */
        main { display: grid; grid-template-columns: 1fr 400px; flex: 1; overflow: hidden; gap: 0; }
        
        /* Assignment Section */
        .assignment-section { padding: 40px; overflow-y: auto; display: flex; flex-direction: column; gap: 20px; }
        .assignment-card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .assignment-title { font-size: 1.5em; font-weight: bold; color: #333; margin-bottom: 15px; border-left: 5px solid #667eea; padding-left: 15px; }
        .assignment-desc { font-size: 1.1em; line-height: 1.6; color: #4b4f56; margin-bottom: 25px; }
        
        .download-box { background: #f8f9fa; padding: 20px; border-radius: 10px; text-align: center; }
        .download-btn { display: inline-block; background: #667eea; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.3s; margin-bottom: 10px; border: none; cursor: pointer; }
        .download-btn:hover:not(.disabled) { background: #5568d3; transform: translateY(-2px); }
        .download-btn.disabled { background: #ccc; cursor: not-allowed; }
        .remaining { font-size: 0.85em; color: #888; }

        /* Chat Section */
        .chat-section { background: white; border-left: 1px solid #ddd; display: flex; flex-direction: column; overflow: hidden; }
        .chat-header { padding: 15px 20px; border-bottom: 1px solid #eee; font-weight: bold; color: #333; background: #fafafa; }
        .messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 10px; background: #f9f9f9; min-height: 0; }
        .message { padding: 10px 15px; border-radius: 12px; max-width: 85%; font-size: 0.95em; position: relative; }
        .message.team { align-self: flex-end; background: #667eea; color: white; border-bottom-right-radius: 2px; }
        .message.teacher { align-self: flex-start; background: #e4e6eb; color: #050505; border-bottom-left-radius: 2px; }
        .msg-meta { font-size: 0.7em; opacity: 0.7; margin-bottom: 4px; }
        
        .chat-input-area { padding: 20px; border-top: 1px solid #eee; flex-shrink: 0; }
        .chat-form { display: flex; flex-direction: column; gap: 10px; }
        textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; resize: none; font-family: inherit; font-size: 0.95em; }
        textarea:focus { outline: 2px solid #667eea; border-color: transparent; }

        @media (max-width: 900px) {
            main { grid-template-columns: 1fr; overflow-y: auto; }
            .chat-section { border-left: none; border-top: 1px solid #ddd; min-height: 500px; height: auto; }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-top">
            <div class="team-name">Team: <?= htmlspecialchars($team['team_name']) ?></div>
            <div class="stats-bar">
                <div class="stat-item">
                    <span class="stat-label">Positie</span>
                    <span class="stat-value">#<span id="display-rank"><?= $rank ?></span></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Level</span>
                    <span class="stat-value"><span id="display-level"><?= $team['current_level'] ?></span></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Tijd Bezig</span>
                    <span class="stat-value" id="live-timer">00:00:00</span>
                </div>
            </div>
        </div>
    </header>

    <main>
        <section class="assignment-section">
            <div class="assignment-card">
                <?php if ($assignment): ?>
                    <div class="assignment-title" id="assignment-title"><?= htmlspecialchars($assignment['title']) ?></div>
                    <div class="assignment-desc" id="assignment-desc"><?= nl2br(htmlspecialchars($assignment['description'])) ?></div>
                    
                    <div class="download-box">
                        <?php if ($download_count < $max_downloads): ?>
                            <a href="team_download.php?token=<?= $token ?>" class="download-btn">Download Bestanden</a>
                        <?php else: ?>
                            <button class="download-btn disabled" disabled>Download Limiet Bereikt</button>
                        <?php endif; ?>
                        <div class="remaining">Downloads gebruikt: <?= $download_count ?> / <?= $max_downloads ?></div>
                    </div>
                <?php else: ?>
                    <div class="assignment-title" id="assignment-title">Geen Opdracht</div>
                    <div class="assignment-desc" id="assignment-desc">Er is momenteel geen actieve opdracht voor dit level. Wacht op instructies van de docent.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="chat-section">
            <div class="chat-header">Berichten & Antwoorden</div>
            <?php if ($team['current_level'] > 0): ?>
                <div class="messages" id="chat-box">
                    <?php foreach ($messages as $m): ?>
                        <div class="message <?= $m['sender'] ?>">
                            <div class="msg-meta"><?= $m['sender'] === 'team' ? 'Jullie' : 'Docent' ?> - <?= $m['created_at'] ?></div>
                            <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="chat-input-area">
                    <form method="POST" class="chat-form">
                        <input type="hidden" name="action" value="send_message">
                        <textarea name="message" rows="3" placeholder="Typ hier je antwoord of vraag..." required></textarea>
                        <button type="submit" class="download-btn" style="width:100%; margin:0;">Bericht versturen</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="messages" style="justify-content: center; align-items: center; text-align: center; color: #888;">
                    De chat wordt geactiveerd zodra je aan level 1 begint.
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
        let startTime = <?= $startTime ?> * 1000;
        let currentLevel = <?= (int)$team['current_level'] ?>;

        function triggerLevelUp() {
            confetti({
                particleCount: 150,
                spread: 70,
                origin: { y: 0.6 }
            });
            setTimeout(() => {
                location.reload();
            }, 3000);
        }

        function updateTimer() {
            const now = new Date().getTime();
            const diff = Math.max(0, now - startTime);

            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            const display = 
                (hours < 10 ? "0" + hours : hours) + ":" + 
                (minutes < 10 ? "0" + minutes : minutes) + ":" + 
                (seconds < 10 ? "0" + seconds : seconds);

            document.getElementById('live-timer').innerText = display;
        }

        function scrollToBottom() {
            const chatBox = document.getElementById('chat-box');
            if (chatBox) {
                setTimeout(() => {
                    chatBox.scrollTop = chatBox.scrollHeight;
                }, 50);
            }
        }

        function fetchMessages() {
            fetch(window.location.href + '&ajax=1')
                .then(response => response.json())
                .then(data => {
                    const chatBox = document.getElementById('chat-box');
                    // Alleen scrollen en updaten als er daadwerkelijk nieuwe berichten zijn
                    if (chatBox && chatBox.innerHTML !== data.html) {
                        chatBox.innerHTML = data.html;
                        scrollToBottom();
                    }
                    if (data.level > currentLevel) {
                        currentLevel = data.level;
                        // Update UI onmiddellijk voor de reload
                        document.getElementById('display-level').innerText = data.level;
                        document.getElementById('display-rank').innerText = data.rank;
                        document.getElementById('assignment-title').innerText = data.assignment_title;
                        document.getElementById('assignment-desc').innerHTML = data.assignment_desc;
                        startTime = data.start_time * 1000;
                        
                        triggerLevelUp();
                    }
                });
        }

        setInterval(updateTimer, 1000);
        setInterval(fetchMessages, 5000);
        updateTimer();
        scrollToBottom();
    </script>
</body>
</html>