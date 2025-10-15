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
<?php
require_once 'config.php';

$db = getDB();
$teams = $db->query("SELECT * FROM teams ORDER BY current_level DESC, team_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$total_assignments = $db->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment Leaderboard</title>
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
        }
        .team-row {
            display: flex;
            align-items: center;
            padding: 20px;
            margin-bottom: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .team-row:hover {
            transform: translateX(5px);
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
            <h1>🏆 Assignment Leaderboard</h1>
            <p class="subtitle">Track your team's progress through all assignments</p>
        </header>
        
        <div class="leaderboard">
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
                    
                    $progress = ($total_assignments > 0) ? ($team['current_level'] / $total_assignments) * 100 : 0;
                    ?>
                    <div class="team-row">
                        <div class="rank <?= $rankClass ?>">#<?= $rank ?></div>
                        <div class="team-info">
                            <div class="team-name"><?= htmlspecialchars($team['team_name']) ?></div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $progress ?>%">
                                    <?= round($progress, 1) ?>%
                                </div>
                            </div>
                        </div>
                        <div class="level-badge">
                            Level <?= $team['current_level'] ?>/<?= $total_assignments ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="admin-link">
            <a href="teacher.php">👨‍🏫 Teacher Login</a>
        </div>
    </div>
</body>
</html>