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
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php'; // Correct include for database
require_once '../conf/mail_functions.php'; // New include for mail functions

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

if (isset($_SESSION['force_password_change'])) {
    header('Location: password.php');
    exit;
}

$db = getDB();

// AJAX endpoint voor live unread count updates
if (isset($_GET['ajax_unread'])) {
    echo $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();
    exit;
}

// AJAX endpoint voor AI Agent status heartbeat check
if (isset($_GET['ajax_ai_status'])) {
    if (!defined('POLL_INTERVAL')) define('POLL_INTERVAL', 30);
    // Tel unieke agents die in de afgelopen minuut een teken van leven gaven
    $stmt = $db->query("SELECT COUNT(*) FROM ai_service_status WHERE last_heartbeat > datetime('now', '-120 seconds')");
    $count = (int)$stmt->fetchColumn();
    
    echo $count > 0 ? "active ($count)" : "inactive";
    exit;
}

// Handle level changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (($_POST['action'] === 'level_up' || $_POST['action'] === 'resend_level_mail') && isset($_POST['team_id'])) {
        $team_id = (int)$_POST['team_id'];
        
        // Get team info
        $stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$team_id]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($team) {
            $new_level = $team['current_level'] + ($_POST['action'] === 'level_up' ? 1 : 0);

            // Archiveer de chatgeschiedenis als het een echte level up is
            if ($_POST['action'] === 'level_up') {
                $stmtMsgs = $db->prepare("SELECT sender, message FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender != 'suggestion' ORDER BY created_at ASC");
                $stmtMsgs->execute([$team_id, $team['current_level']]);
                $history = [];
                foreach ($stmtMsgs->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    $history[] = ($m['sender'] === 'team' ? 'Team: ' : 'Docent: ') . $m['message'];
                }
                $db->prepare("INSERT INTO completed_assignments (team_id, assignment_number, chat_history) VALUES (?, ?, ?)")
                   ->execute([$team_id, $team['current_level'], implode("\n", $history)]);
            }
            
            // Update level
            $sql = "UPDATE teams SET current_level = ?" . ($_POST['action'] === 'level_up' ? ", level_updated_at = CURRENT_TIMESTAMP" : "") . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$new_level, $team_id]);

            $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'TEAM_UPDATE', ?)")->execute([$_SESSION['teacher_id'], "Team '{$team['team_name']}' action: {$_POST['action']} (New level: $new_level)"]);
            
            // Send email with artifacts
            @sendLevelUpEmail($db, $team['email'], $team_id, $team['team_name'], $new_level);
            
            $_SESSION['success'] = "Team leveled up and email sent!";
        }
/*	
    } elseif ($_POST['action'] === 'resend_level_mail' && isset($_POST['team_id'])) {
        $team_id = (int)$_POST['team_id'];
        
        // Get team info
        $stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$team_id]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($team) {                        
            // Send email with artifacts
            sendLevelUpEmail($team['email'], $team_id, $team['team_name'], $team['current_level']);
            
            $_SESSION['success'] = "Resend level information email!";
        }
*/
    } elseif ($_POST['action'] === 'add_team') {
        $team_name = trim($_POST['team_name']);
        $email = trim($_POST['email']);
        $access_token = bin2hex(random_bytes(32));

        // Controleer op unieke teamnaam
        $stmt = $db->prepare("SELECT COUNT(*) FROM teams WHERE team_name = ?");
        $stmt->execute([$team_name]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = "Fout: Een team met deze naam bestaat al. Kies een andere naam.";
            header('Location: teacher.php');
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO teams (team_name, email, access_token, level_updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$team_name, $email, $access_token]);
        
        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'TEAM_ADD', ?)")->execute([$_SESSION['teacher_id'], "Added team: $team_name"]);
        $_SESSION['success'] = "Team added successfully!";
	sendWelcomeEmail($email, $team_name, $access_token);
	
    } elseif ($_POST['action'] === 'delete_team' && isset($_POST['team_id'])) {
        $team_id = (int)$_POST['team_id'];
        $stmt = $db->prepare("DELETE FROM teams WHERE id = ?");
        $stmt->execute([$team_id]);

	$stmt = $db->prepare("DELETE FROM download_tokens WHERE team_id = ?");
	$stmt->execute([$team_id]);

        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'TEAM_DELETE', ?)")->execute([$_SESSION['teacher_id'], "Deleted team ID: $team_id"]);
        $_SESSION['success'] = "Team deleted successfully!";
    }
    
    header('Location: teacher.php');
    exit;
}

$teams = $db->query("SELECT * FROM teams ORDER BY current_level DESC, level_updated_at ASC, team_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$total_assignments = $db->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
$unread_total = $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();

$pageTitle = 'Dashboard';
include 'admin_header.php';
?>
        <header class="content-header">
            <h1>Dashboard</h1>
            <a target="_leaderboard" href="index.php" class="btn btn-outline">View Public Leaderboard</a>
        </header>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success">
                <?= $_SESSION['success'] ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-label">Total Teams</div>
                <div class="stat-value"><?= count($teams) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Assignments</div>
                <div class="stat-value"><?= $total_assignments ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Unread Messages</div>
                <div class="stat-value" id="unread-stat"><?= $unread_total ?></div>
            </div>
        </div>
        
        <div class="dashboard-grid">
            <div class="card">
                <div class="card-title">🛡️ Geregistreerde Teams</div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Team Details</th>
                                <th style="text-align: center;">Level</th>
                                <th>Progress</th>
                                <th style="text-align: right;">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $index => $team): ?>
                                <tr>
                                    <td style="font-weight: bold; color: #667eea; vertical-align: middle;">
                                        <?= $index + 1 ?>
                                    </td>
                                    <td>
                                        <div class="team-main-info">
                                            <a href="team.php?token=<?= $team['access_token'] ?>" target="_blank" class="team-name-link">
                                                <?= htmlspecialchars($team['team_name']) ?>
                                            </a>
                                            <div style="display: flex; gap: 10px; align-items: center; margin-top: 4px;">
                                                <span class="team-email" style="font-size: 0.85em; color: #666;">📧 <?= htmlspecialchars($team['email']) ?></span>
                                                <span style="font-size: 0.75em; color: #aaa;">🕒 <?= date('H:i', strtotime($team['level_updated_at'])) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="text-align: center; vertical-align: middle;">
                                        <span style="background: #f0f4ff; color: #667eea; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 1.1em;">
                                            <?= $team['current_level'] ?>
                                        </span>
                                    </td>
                                    <td style="vertical-align: middle;">
                                        <?php 
                                        $progress = ($total_assignments > 0) ? (min($total_assignments, max(0, $team['current_level'] - 1)) / $total_assignments) * 100 : 0; ?>
                                        <div class="progress-wrapper">
                                            <div class="progress-bg" style="height: 6px; background: #eee;">
                                                <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                                            </div>
                                            <div class="progress-text" style="font-size: 0.75em; font-weight: 600; color: #888;"><?= round($progress) ?>% voltooid</div>
                                        </div>
                                    </td>
                                    <td style="text-align: right; vertical-align: middle;">
                                        <div class="action-btns" style="justify-content: flex-end;">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="level_up">
                                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                                <button type="submit" class="btn btn-success" style="padding: 6px 12px; border-radius: 6px;"
                                                    <?= $team['current_level'] > $total_assignments ? 'disabled' : '' ?>>
                                                    ⬆️
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="resend_level_mail">
                                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                                <button type="submit" class="btn btn-outline" style="padding: 6px 12px; border-radius: 6px;" title="Resend Level Mail">
                                                    📧
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete team?');">
                                                <input type="hidden" name="action" value="delete_team">
                                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                                <button type="submit" class="btn btn-danger" style="padding: 6px 12px; border-radius: 6px;">
                                                    🗑️
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="side-panel">
                <div class="card" style="border-top: 5px solid #667eea; background: #fff;">
                    <div class="card-title" style="color: #667eea; font-size: 1.4em;">➕ Nieuw Team</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_team">
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label style="color: #555; font-size: 0.9em;">Team Naam</label>
                            <input type="text" name="team_name" placeholder="Bijv. De Speurders" style="border: 1px solid #ddd; padding: 12px;" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 25px;">
                            <label style="color: #555; font-size: 0.9em;">E-mail adres</label>
                            <input type="email" name="email" placeholder="begeleider@school.nl" style="border: 1px solid #ddd; padding: 12px;" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 1em; text-transform: uppercase; letter-spacing: 1px;">🚀 Team Toevoegen</button>
                    </form>
                </div>
            </div>
        </div>
<?php include 'admin_footer.php'; ?>
