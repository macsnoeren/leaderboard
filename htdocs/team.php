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

// Bereken de ranking op basis van dezelfde criteria als het leaderboard
$allTeams = $db->query("SELECT id FROM teams ORDER BY current_level DESC, level_updated_at ASC, team_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$rank = array_search($team['id'], $allTeams) + 1;

// Haal het huidige assignment op
$stmt = $db->prepare("SELECT * FROM assignments WHERE assignment_number = ?");
$stmt->execute([$team['current_level']]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

// Haal download count op
$stmt = $db->prepare("SELECT download_count FROM team_downloads WHERE team_id = ? AND assignment_number = ?");
$stmt->execute([$team['id'], $team['current_level']]);
$download_count = $stmt->fetchColumn() ?: 0;
$max_downloads = 10;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Team: <?= htmlspecialchars($team['team_name']) ?></h1>
        <div class="level-info">
            Huidig Level: <?= $team['current_level'] ?><br>
            Ranking: #<?= $rank ?>
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
    </div>
</body>
</html>