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

// Haal het totale aantal opdrachten op voor het bepalen van het laatste level
$total_assignments = $db->query("SELECT COUNT(*) FROM assignments")->fetchColumn();

// Handle bericht versturen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    // Als het team al gewonnen heeft, negeer het bericht
    if ($team['current_level'] > $total_assignments) {
        header("Location: team.php?token=" . $token);
        exit;
    }

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
$stmt = $db->prepare("SELECT * FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender != 'suggestion' ORDER BY created_at ASC");
$stmt->execute([$team['id'], $team['current_level']]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AJAX endpoint voor live updates
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
        'level' => (int)$team['current_level'],
        'rank' => $rank,
        'assignment_title' => $assignment ? htmlspecialchars($assignment['title']) : 'Geen opdracht beschikbaar',
        'assignment_desc' => $assignment ? $assignment['description'] : 'Wacht op instructies.',
        'assignment_instruction' => $assignment ? $assignment['instruction'] : '',
        'time_limit' => $assignment ? (int)$assignment['time_limit'] : 0,
        'has_file' => !empty($assignment['artifact_file']),
        'start_time' => $startTime,
        'total_assignments' => (int)$total_assignments // Voeg totaal aantal opdrachten toe
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
    </style>
</head>
<body>
    <header>
        <div class="header-top">
            <div class="team-name">Team: <?= htmlspecialchars($team['team_name']) ?></div>
            <div class="header-actions">
                <?php if ($assignment): ?>
                    <button class="btn-assignment" onclick="openAssignmentModal()">📄 Bekijk Opdracht</button>
                <?php endif; ?>
                
                <a href="index.php" target="_leaderboard" class="header-link">View Public Leaderboard</a>
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
        </div>
    </header>

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
                        <a href="team_download.php?token=<?= $token ?>" class="download-btn" id="dl-link">📄 Bestanden Downloaden (PDF/ZIP)</a>
                        <div class="remaining">Downloads gebruikt: <span id="display-dl-count"><?= $download_count ?></span> / <?= $max_downloads ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="chat-section" id="chat-container">
            <div class="chat-header">
                <span>Berichten & Antwoorden</span>
                <button class="btn-zoom" onclick="toggleChatSize()">🔍 Tekst vergroten</button>
            </div>
            <?php if ($team['current_level'] > 0 && $team['current_level'] <= $total_assignments): ?>
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
            <?php elseif ($team['current_level'] > $total_assignments): ?>
                <div class="messages" style="justify-content: center; align-items: center; text-align: center; color: #2e7d32; padding: 40px;">
                    <div>
                        <h2 style="margin-bottom: 10px;">🏆 Gefeliciteerd!</h2>
                        <p>Jullie hebben alle opdrachten voltooid. De chat is nu gesloten.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="messages" style="justify-content: center; align-items: center; text-align: center; color: #888;">
                    De chat wordt geactiveerd zodra je aan level 1 begint.
                </div>
            <?php endif; ?>
        </section>
    </main>

    <div id="level-up-overlay">
        <h1 id="overlay-title" style="font-size: 4em; margin-bottom: 20px;">🎉 GEFELICITEERD! 🎉</h1>
        <p id="overlay-message" style="font-size: 2em; opacity: 0.9;">Jullie zijn naar het volgende level!</p>
        <p id="overlay-rank" style="font-size: 1.5em; opacity: 0.8; margin-top: 10px;"></p>
    </div>

    <script>
        let startTime = <?= $startTime ?> * 1000;
        let currentLevel = <?= (int)$team['current_level'] ?>;
        let totalAssignments = <?= (int)$total_assignments ?>; // Initialiseer met PHP waarde

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

                        startTime = data.start_time * 1000;
                        
                        const isFinal = data.level > data.total_assignments;
                        triggerLevelUp(isFinal);
                        if (!isFinal) setTimeout(openAssignmentModal, 1500);
                    }
                    renderMarkdown();
                });
        }

        setInterval(updateTimer, 1000);
        setInterval(fetchMessages, 5000);
        updateTimer();
        renderMarkdown();
        scrollToBottom();

        // Open de opdracht popup alleen de eerste keer dat een level geladen wordt in deze browser
        const modalKey = 'assignment_seen_lvl_' + currentLevel;
        if (currentLevel > 0 && currentLevel <= totalAssignments && localStorage.getItem(modalKey) !== 'true') {
            openAssignmentModal();
            localStorage.setItem(modalKey, 'true');
        }

        // Als het team al op het eindlevel zit bij het laden van de pagina, toon de overlay direct
        if (currentLevel > totalAssignments) {
            const rank = document.getElementById('display-rank').innerText;
            document.getElementById('overlay-title').innerText = "🏆 KAMPIOENEN! 🏆";
            document.getElementById('overlay-message').innerText = "Jullie hebben ALLE levels voltooid!";
            document.getElementById('overlay-rank').innerText = `Eindpositie: #${rank}`;
            
            // Kleine vertraging voor de visuele impact
            setTimeout(() => triggerLevelUp(true), 500);
        }

        // Sluit modal als je buiten de box klikt
        window.onclick = function(event) {
            const modal = document.getElementById('assignment-modal');
            if (event.target == modal) closeAssignmentModal();
        }

        function toggleChatSize() {
            const chat = document.getElementById('chat-container');
            chat.classList.toggle('large-mode');
            const btn = document.querySelector('.btn-zoom');
            btn.innerText = chat.classList.contains('large-mode') ? '🔍 Tekst verkleinen' : '🔍 Tekst vergroten';
            scrollToBottom();
        }
    </script>
</body>
</html>