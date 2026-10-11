<?php
/*
 Copyright (C) 2025 Maurice Snoeren

 This program is free software: you can redistribute it and/or modify it under the terms of
 the GNU General Public License as published by the Free Software Foundation, version 3.

 This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 See the GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along with this program.
 If not, see https://www.gnu.org/licenses/.
*/

require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/game.php';

$db = getDB();

// ?game=<spelcode>; zonder parameter het enige lopende spel, of een keuzelijst
$code = trim($_GET['game'] ?? '');
$game = null;
$choices = [];
$notFound = false;
if ($code !== '') {
    $game = getGameByCode($db, $code);
    $notFound = $game === null;
} else {
    $choices = $db->query("SELECT " . gameSelectSql() . " FROM games g WHERE " . effectiveStatusSql() . " IN ('running', 'paused') ORDER BY g.name")->fetchAll(PDO::FETCH_ASSOC);
    if (count($choices) === 1) {
        $game = $choices[0];
    }
}

$teams = [];
$total_assignments = 0;
$status = null;
if ($game) {
    $teams = rankedTeams($db, (int)$game['id']);
    $total_assignments = totalAssignments($db, (int)$game['id']);
    $status = $game['effective_status'];
}

function levelBadge(int $level, int $total): string {
    if ($level <= 0) {
        return 'Start';
    }
    return $level > $total ? 'Finish' : "Level $level/$total";
}

// AJAX endpoint om de gegevens live op te halen zonder de pagina te verversen.
// Alleen publieke velden: geen e-mail of toegangstoken.
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $data = [];
    foreach ($teams as $index => $team) {
        $data[] = [
            'id' => (int)$team['id'],
            'team_name' => $team['team_name'],
            'current_level' => (int)$team['current_level'],
            'rank' => $index + 1,
            'progress' => progressPercent((int)$team['current_level'], $total_assignments),
            'badge' => levelBadge((int)$team['current_level'], $total_assignments),
        ];
    }
    echo json_encode(['status' => $status, 'teams' => $data, 'total_assignments' => $total_assignments]);
    exit;
}
$logo = $game ? gameLogoUrl($game) : null;
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $game ? htmlspecialchars($game['name']) . ' - ' : '' ?>Leaderboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        h1 { font-size: 2.5em; margin-bottom: 10px; }
        .subtitle { opacity: 0.9; font-size: 1.1em; }
        .leaderboard {
            padding: 30px;
            position: relative;
        }
        .team-row {
            display: flex;
            align-items: center;
            padding: 20px;
            margin-bottom: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s;
        }
        .team-row:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .rank {
            font-size: 2em;
            font-weight: bold;
            width: 60px;
            text-align: center;
            color: #667eea;
        }
        .rank.first { color: #FFD700; }
        .rank.second { color: #C0C0C0; }
        .rank.third { color: #CD7F32; }
        .team-info {
            flex: 1;
            margin: 0 20px;
        }
        .team-name {
            font-size: 1.3em;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .progress-bar {
            background: #e0e0e0;
            height: 25px;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            transition: width 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.85em;
        }
        .level-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: bold;
            min-width: 80px;
            text-align: center;
        }
        .soon { text-align: center; padding: 50px 30px; }
        .soon img { max-width: min(90%, 600px); max-height: 50vh; object-fit: contain; margin-bottom: 30px; }
        .soon h2 { font-size: 2.2em; color: #667eea; }
        .status-pill { display: inline-block; background: rgba(255,255,255,0.2); padding: 6px 16px; border-radius: 20px; margin-top: 10px; font-weight: 600; }
        .choice { display: block; padding: 20px; margin-bottom: 15px; background: #f8f9fa; border-radius: 10px; color: #333; text-decoration: none; font-size: 1.2em; font-weight: 600; }
        .choice:hover { background: #f0f4ff; color: #667eea; }
        @media (max-width: 600px) {
            body { padding: 10px; }
            h1 { font-size: 1.8em; }
            .leaderboard { padding: 15px; }
            .team-row { padding: 12px; flex-wrap: wrap; }
            .rank { width: 40px; font-size: 1.5em; }
            .team-info { margin: 0 10px; min-width: 0; }
            .level-badge { padding: 6px 12px; min-width: 0; }
        }
        .admin-link {
            text-align: center;
            padding: 20px;
            border-top: 2px solid #f0f0f0;
        }
        .admin-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><?= $game ? htmlspecialchars($game['name']) : 'Leaderboard Zebrawave Studios' ?></h1>
            <?php if ($status === 'finished'): ?>
                <div class="status-pill">🏁 Afgelopen – eindstand</div>
            <?php elseif ($status === 'paused'): ?>
                <div class="status-pill">⏸️ Pauze</div>
            <?php endif; ?>
        </header>

        <?php if (!$game): ?>
            <div class="leaderboard">
                <?php if ($notFound): ?>
                    <p style="text-align: center; padding: 40px; color: #999;">Dit spel bestaat niet.</p>
                <?php elseif (empty($choices)): ?>
                    <p style="text-align: center; padding: 40px; color: #999;">Er is op dit moment geen spel bezig.</p>
                <?php else: ?>
                    <p style="margin-bottom: 20px; color: #666;">Kies een spel:</p>
                    <?php foreach ($choices as $choice): ?>
                        <a class="choice" href="index.php?game=<?= urlencode((string)$choice['join_code']) ?>"><?= htmlspecialchars($choice['name']) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php elseif ($status === 'draft'): ?>
            <div class="soon">
                <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt=""><?php endif; ?>
                <h2>Binnenkort van start</h2>
            </div>
        <?php else: ?>
        <div class="leaderboard" id="leaderboard-container">
            <?php if (empty($teams)): ?>
                <p style="text-align: center; padding: 40px; color: #999;">No teams registered yet.</p>
            <?php else: ?>
                <?php foreach ($teams as $index => $team): ?>
                    <?php 
                    $rank = $index + 1;
                    $rankClass = '';
                    if ($rank === 1) $rankClass = 'first';
                    elseif ($rank === 2) $rankClass = 'second';
                    elseif ($rank === 3) $rankClass = 'third';
                    
                    $progress = progressPercent((int)$team['current_level'], $total_assignments);
                    ?>
                    <div class="team-row" data-id="<?= $team['id'] ?>">
                        <div class="rank <?= $rankClass ?>" id="rank-<?= $team['id'] ?>">#<?= $rank ?></div>
                        <div class="team-info">
                            <div class="team-name"><?= htmlspecialchars($team['team_name']) ?></div>
                            <div class="progress-bar">
                                <div class="progress-fill" id="prog-<?= $team['id'] ?>" style="width: <?= $progress ?>%">
                                    <?= $progress > 0 ? round($progress, 1) . '%' : '' ?>
                                </div>
                            </div>
                        </div>
                        <div class="level-badge" id="lvl-<?= $team['id'] ?>">
                            <?= levelBadge((int)$team['current_level'], $total_assignments) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($game): ?>
    <script>
        const gameCode = <?= json_encode((string)$game['join_code']) ?>;
        const gameStatus = <?= json_encode($status) ?>;
        // Spelcode in de URL, zodat herladen hetzelfde spel toont (ook als het afgelopen is)
        if (!new URLSearchParams(location.search).has('game')) {
            history.replaceState(null, '', 'index.php?game=' + encodeURIComponent(gameCode));
        }

        function fetchLeaderboard() {
            fetch('index.php?ajax=1&game=' + encodeURIComponent(gameCode))
                .then(response => response.json())
                .then(data => {
                    // Start, pauze of einde: de pagina ziet er anders uit
                    if (data.status !== gameStatus) { location.reload(); return; }
                    const container = document.getElementById('leaderboard-container');
                    if (!container) return;
                    const rows = Array.from(container.querySelectorAll('.team-row'));
                    // Team erbij of weg: ververs de hele pagina (simpelste oplossing)
                    if (rows.length !== data.teams.length) { location.reload(); return; }
                    
                    // Sla de huidige posities op (First)
                    const oldPositions = {};
                    rows.forEach(row => {
                        oldPositions[row.dataset.id] = row.getBoundingClientRect().top;
                    });

                    // Update de data en volgorde
                    data.teams.forEach((teamData, index) => {
                        let row = container.querySelector(`.team-row[data-id="${teamData.id}"]`);
                        
                        // Als team nieuw is, ververs dan de hele pagina (simpelste oplossing)
                        if (!row) { location.reload(); return; }

                        // Update rank tekst en kleuren
                        const rankDiv = document.getElementById(`rank-${teamData.id}`);
                        const rank = index + 1;
                        rankDiv.innerText = `#${rank}`;
                        rankDiv.className = 'rank ' + (rank === 1 ? 'first' : rank === 2 ? 'second' : rank === 3 ? 'third' : '');

                        // Update progress bar en level badge
                        document.getElementById(`prog-${teamData.id}`).style.width = teamData.progress + '%';
                        document.getElementById(`prog-${teamData.id}`).innerText = teamData.progress > 0 ? Math.round(teamData.progress * 10) / 10 + '%' : '';
                        document.getElementById(`lvl-${teamData.id}`).innerText = teamData.badge;
                        
                        // Zet ze in de juiste volgorde in de DOM
                        container.appendChild(row);
                    });

                    // Bereken nieuwe posities en animeer (Last, Invert, Play)
                    requestAnimationFrame(() => {
                        const newRows = Array.from(container.querySelectorAll('.team-row'));
                        newRows.forEach(row => {
                            const id = row.dataset.id;
                            const oldTop = oldPositions[id];
                            const newTop = row.getBoundingClientRect().top;
                            const deltaY = oldTop - newTop;

                            if (deltaY !== 0) {
                                // Zet het element direct terug naar de oude positie (Invert)
                                row.style.transition = 'none';
                                row.style.transform = `translateY(${deltaY}px)`;
                                
                                // Forceer een reflow
                                row.offsetHeight; 

                                // Laat het element naar de nieuwe positie glijden (Play)
                                row.style.transition = 'transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1)';
                                row.style.transform = '';
                            }
                        });
                    });
                })
                .catch(err => console.error('Fout bij ophalen leaderboard:', err));
        }

        // Elke 10 seconden verversen, net als de oude meta-refresh
        setInterval(fetchLeaderboard, 10000);
    </script>
    <?php endif; ?>
</body>
</html>