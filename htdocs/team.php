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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Dashboard - <?= htmlspecialchars($team['team_name']) ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            width: 100%;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            text-align: center;
        }
        h1 { color: #333; margin-bottom: 10px; }
        .level-info {
            font-size: 1.5em;
            color: #667eea;
            font-weight: bold;
            margin-bottom: 30px;
        }
        .assignment-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: left;
        }
        .assignment-title { font-weight: bold; font-size: 1.2em; margin-bottom: 10px; color: #333; }
        .download-btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background 0.2s;
        }
        .download-btn:hover { background: #5568d3; }
        .download-btn.disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .remaining {
            margin-top: 15px;
            font-size: 0.9em;
            color: #666;
        }
        .timer {
            margin-top: 10px;
            font-size: 1.1em;
            color: #333;
            font-family: monospace;
        }
        .chat-container {
            margin-top: 40px;
            text-align: left;
            border-top: 2px solid #eee;
            padding-top: 20px;
        }
        .message {
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 8px;
            max-width: 80%;
        }
        .message.team {
            background: #e3f2fd;
            margin-left: auto;
            border: 1px solid #bbdefb;
        }
        .message.teacher {
            background: #f1f8e9;
            margin-right: auto;
            border: 1px solid #dcedc8;
        }
        .msg-meta { font-size: 0.75em; color: #888; margin-bottom: 4px; }
        textarea {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Team: <?= htmlspecialchars($team['team_name']) ?></h1>
        <div class="level-info">
            Huidig Level: <?= $team['current_level'] ?><br>
            Ranking: #<?= $rank ?><br>
            <div class="timer" id="live-timer">Tijd bezig: --:--:--</div>
        </div>

        <?php if ($assignment): ?>
            <div class="assignment-box">
                <div class="assignment-title"><?= htmlspecialchars($assignment['title']) ?></div>
                <p><?= nl2br(htmlspecialchars($assignment['description'])) ?></p>
            </div>
            
            <?php if ($download_count < $max_downloads): ?>
                <a href="team_download.php?token=<?= $token ?>" class="download-btn">Download Opdracht Bestanden</a>
            <?php else: ?>
                <div class="download-btn disabled">Download Limiet Bereikt</div>
            <?php endif; ?>
            <div class="remaining">Downloads gebruikt: <?= $download_count ?> / <?= $max_downloads ?></div>
        <?php else: ?>
            <p>Geen opdracht beschikbaar voor dit level. Wacht op instructies.</p>
        <?php endif; ?>

        <?php if ($team['current_level'] > 0): ?>
            <div class="chat-container">
                <h3>Stuur je antwoord of stel een vraag</h3>
                <div class="messages">
                    <?php foreach ($messages as $m): ?>
                        <div class="message <?= $m['sender'] ?>">
                            <div class="msg-meta"><?= $m['sender'] === 'team' ? 'Jullie' : 'Docent' ?> - <?= $m['created_at'] ?></div>
                            <div><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="send_message">
                    <textarea name="message" rows="3" placeholder="Typ hier je antwoord..." required></textarea>
                    <button type="submit" class="download-btn" style="width:100%; margin-top:10px; border:none;">Bericht versturen</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const startTime = <?= $startTime ?> * 1000;

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

            document.getElementById('live-timer').innerText = "Tijd bezig: " + display;
        }

        setInterval(updateTimer, 1000);
        updateTimer();
    </script>
</body>
</html>