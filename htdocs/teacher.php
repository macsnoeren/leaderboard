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
require_once '../conf/database.php';

require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// AJAX endpoint voor live unread count updates
if (isset($_GET['ajax_unread'])) {
    echo $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();
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

// Tel ongelezen berichten van teams
$unread_total = $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();

function sendLevelUpEmail($db, $to, $team_id, $team_name, $level) {
  $mail = new PHPMailer(true);

  try {
    // SMTP configuration
    $mail->isSMTP();
    $mail->Timeout = 10;
    $mail->SMTPConnectTimeout = 5;
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;

    // Recipients
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($to, $team_name);

    // Content
    $mail->isHTML(true);
    $mail->Subject = "$team_name: Gefeliciteerd! Level $level is behaald!";

    // Generate a unique secure token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + 86400); // 24h valid

    // Store token
    $stmt = $db->prepare("
			     INSERT INTO download_tokens (team_id, level, token, expires_at)
			     VALUES (?, ?, ?, ?)
                             ");
    $stmt->execute([$team_id, $level, $token, $expires_at]);

    // Build secure link
    $download_link = BASE_URL . "/download.php?token=$token";
    
    $mail->Body = "
      <html>
      <body style='font-family: Arial, sans-serif;'>
      <h2>Gefeliciteerd $team_name!</h2>
	 <p>Jullie hebben level $level behaald!</p>
	 <p>Klik op de onderstaande link om de documenten te krijgen voor de volgende opdracht:</p>
	 <p><a href='$download_link' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Download Artifacts</a></p>
	 <p>Ga vooral zo door! Goed bezig!!</p>
	 </body>
	</html>
    ";

    $mail->AltBody = "Gefeliciteerd $team_name! Je hebt level $level behaald. Download de volgende opdracht: $download_link";

    $mail->send();
    return true;
  } catch (Exception $e) {
    return $mail->ErrorInfo;
  }
}

function sendWelcomeEmail($to, $team_name, $token) {
  $mail = new PHPMailer(true);

  try {
    // SMTP configuration
    $mail->isSMTP();
    $mail->Timeout = 10;
    $mail->SMTPConnectTimeout = 5;
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;

    // Recipients
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($to, $team_name);

    // Content
    $mail->isHTML(true);
    $mail->Subject = "$team_name: Je bent aangemeld!";

    $leaderboard_link = BASE_URL;
    $dashboard_link = BASE_URL . "/team.php?token=$token";

    $mail->Body = "
      <html>
      <body style='font-family: Arial, sans-serif;'>
      <h2>Gefeliciteerd $team_name!</h2>
	 <p>Je bent aangemeld als recherche team!</p>
	 <p>Jullie hebben nu toegang tot jullie eigen dashboard waar opdrachten gedownload kunnen worden zodra jullie het juiste level bereiken.</p>
	 <p><strong>Jullie persoonlijke dashboard:</strong> <a href='$dashboard_link' style='color: #667eea;'>$dashboard_link</a></p>
	 <p>Weet waar je staat: <a href='$leaderboard_link' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Leaderboard</a></p>
	 <p>Heel veel succes!!</p>
	 </body>
	</html>
    ";

    $mail->AltBody = "Gefeliciteerd, $team_name! Dit is een controle om te kijken of je de mail krijgt!";

    $mail->send();
    return true;
  } catch (Exception $e) {
    return $mail->ErrorInfo;
  }
}

/*
function sendLevelUpEmail($to, $team_name, $level) {
    $subject = "Congratulations! Level $level Completed";
    $download_link = BASE_URL . "/download.php?level=$level&email=" . urlencode($to);
    
    $message = "
    <html>
    <body style='font-family: Arial, sans-serif;'>
        <h2>Congratulations, $team_name!</h2>
        <p>You've successfully completed level $level!</p>
        <p>Click the link below to download your assignment artifacts:</p>
        <p><a href='$download_link' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Download Artifacts</a></p>
        <p>Keep up the great work!</p>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
    
    if ( mail($to, $subject, $message, $headers) ) {
       print("HAHAHAHAHA");
    } else {
      print("NONONONONON");
    }
    
}*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - Leaderboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; display: flex; min-height: 100vh; }
        
        /* Sidebar Navigation */
        .sidebar { width: 260px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; position: fixed; height: 100vh; }
        .sidebar-header { padding: 30px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-align: center; font-weight: bold; font-size: 1.2em; }
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .nav-item { display: flex; align-items: center; padding: 12px 25px; color: #4b4f56; text-decoration: none; transition: 0.2s; font-weight: 500; }
        .nav-item:hover { background: #f0f2f5; color: #667eea; }
        .nav-item.active { background: #f0f4ff; color: #667eea; border-left: 4px solid #667eea; }
        .badge { background: #f44336; color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75em; margin-left: auto; }

        /* Main Content */
        .main-content { margin-left: 260px; flex: 1; padding: 40px; }
        header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        h1 { font-size: 1.8em; color: #333; }
        
        /* Success Message */
        .success { background: #e8f5e9; color: #2e7d32; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; border-left: 5px solid #4caf50; font-weight: 500; }

        /* Dashboard Grid */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
        .card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .card-title { font-size: 1.2em; font-weight: bold; color: #333; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }

        /* Stats Cards */
        .stats-overview { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center; }
        .stat-label { font-size: 0.8em; color: #888; text-transform: uppercase; letter-spacing: 1px; }
        .stat-value { font-size: 1.8em; font-weight: bold; color: #667eea; margin-top: 5px; }

        /* Form Styles */
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-size: 0.9em; font-weight: 600; color: #555; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.95em; }
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 0.9em; }
        .btn-primary { background: #667eea; color: white; width: 100%; }
        .btn-primary:hover { background: #5568d3; }
        .btn-success { background: #4caf50; color: white; }
        .btn-success:hover { background: #45a049; }
        .btn-outline { background: transparent; border: 1px solid #ddd; color: #666; }
        .btn-outline:hover { background: #f8f9fa; border-color: #ccc; }
        .btn-danger { background: #ffebee; color: #c62828; }
        .btn-danger:hover { background: #ffcdd2; }

        /* Table Styles */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; border-bottom: 2px solid #f0f2f5; color: #888; font-size: 0.85em; text-transform: uppercase; letter-spacing: 1px; }
        td { padding: 15px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
        .team-main-info { display: flex; flex-direction: column; }
        .team-name-link { font-weight: bold; color: #333; text-decoration: none; }
        .team-email { font-size: 0.8em; color: #888; }
        
        /* Progress Bar in Table */
        .progress-wrapper { width: 120px; }
        .progress-bg { background: #eee; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 5px; }
        .progress-fill { background: #667eea; height: 100%; border-radius: 4px; }
        .progress-text { font-size: 0.75em; color: #666; font-weight: 600; }

        .action-btns { display: flex; gap: 8px; }
    </style>
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">Zebrawave Admin</div>
        <div class="sidebar-nav">
            <a href="teacher.php" class="nav-item active">📊 Dashboard</a>
            <a href="assignments.php" class="nav-item">📘 Assignments</a>
            <a href="messages.php" class="nav-item">
                ✉️ Messages
                <span id="unread-badge-container">
                    <?php if ($unread_total > 0): ?>
                        <span class="badge"><?= $unread_total ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <?php if (($_SESSION['teacher_role'] ?? 'user') === 'admin'): ?>
            <a href="users.php" class="nav-item">👥 Users</a>
            <a href="audit.php" class="nav-item">📋 Audit Logs</a>
            <?php endif; ?>
            <div style="margin-top: 20px; padding: 0 25px; font-size: 0.7em; color: #bbb; text-transform: uppercase;">Settings</div>
            <a href="password.php" class="nav-item">🔑 Password</a>
            <a href="logout.php" class="nav-item" style="margin-top: auto; color: #c62828;">🚪 Logout</a>
        </div>
    </nav>

    <div class="main-content">
        <header>
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
                <div class="card-title">🛡️ Manage Teams</div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Level</th>
                                <th>Progress</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $team): ?>
                                <tr>
                                    <td>
                                        <div class="team-main-info">
                                            <a href="team.php?token=<?= $team['access_token'] ?>" target="_blank" class="team-name-link">
                                                <?= htmlspecialchars($team['team_name']) ?>
                                            </a>
                                            <span class="team-email"><?= htmlspecialchars($team['email']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?= $team['current_level'] ?></strong> / <?= $total_assignments ?>
                                    </td>
                                    <td>
                                        <?php $progress = ($total_assignments > 0) ? ($team['current_level'] / $total_assignments) * 100 : 0; ?>
                                        <div class="progress-wrapper">
                                            <div class="progress-bg">
                                                <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                                            </div>
                                            <div class="progress-text"><?= round($progress) ?>%</div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="level_up">
                                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                                <button type="submit" class="btn btn-success" style="padding: 5px 10px;"
                                                    <?= $team['current_level'] >= $total_assignments ? 'disabled' : '' ?>>
                                                    ⬆️
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="resend_level_mail">
                                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                                <button type="submit" class="btn btn-outline" style="padding: 5px 10px;" title="Resend Level Mail">
                                                    📧
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete team?');">
                                                <input type="hidden" name="action" value="delete_team">
                                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                                <button type="submit" class="btn btn-danger" style="padding: 5px 10px;">
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
                <div class="card">
                    <div class="card-title">➕ Add New Team</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_team">
                        <div class="form-group">
                            <label>Team Name:</label>
                            <input type="text" name="team_name" placeholder="e.g. Code Ninjas" required>
                        </div>
                        <div class="form-group">
                            <label>Team Email:</label>
                            <input type="email" name="email" placeholder="e.g. contact@codeninjas.com" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Create Team & Send Invite</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Live update voor de berichten badge
        function updateUnreadBadge() {
            fetch('teacher.php?ajax_unread=1')
                .then(r => r.text())
                .then(count => {
                    const container = document.getElementById('unread-badge-container');
                    const statDisplay = document.getElementById('unread-stat');
                    
                    if (statDisplay) statDisplay.innerText = count;

                    if (parseInt(count) > 0) {
                        container.innerHTML = `<span class="badge">${count}</span>`;
                    } else {
                        container.innerHTML = '';
                    }
                });
        }
        setInterval(updateUnreadBadge, 10000); // Check elke 10 seconden
    </script>
</body>
</html>
