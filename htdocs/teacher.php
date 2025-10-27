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
            $stmt = $db->prepare("UPDATE teams SET current_level = ? WHERE id = ?");
            $stmt->execute([$new_level, $team_id]);
            
            // Send email with artifacts
            sendLevelUpEmail($team['email'], $team_id, $team['team_name'], $new_level);
            
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
        
        $stmt = $db->prepare("INSERT INTO teams (team_name, email) VALUES (?, ?)");
        $stmt->execute([$team_name, $email]);
        
        $_SESSION['success'] = "Team added successfully!";
	sendWelcomeEmail($email, $team_name);
	
    } elseif ($_POST['action'] === 'delete_team' && isset($_POST['team_id'])) {
        $team_id = (int)$_POST['team_id'];
        $stmt = $db->prepare("DELETE FROM teams WHERE id = ?");
        $stmt->execute([$team_id]);

	$stmt = $db->prepare("DELETE FROM download_tokens WHERE team_id = ?");
	$stmt->execute([$team_id]);

        $_SESSION['success'] = "Team deleted successfully!";
    }
    
    header('Location: teacher.php');
    exit;
}

$teams = $db->query("SELECT * FROM teams ORDER BY current_level DESC, team_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$total_assignments = $db->query("SELECT COUNT(*) FROM assignments")->fetchColumn();

function sendLevelUpEmail($to, $team_id, $team_name, $level) {
  $mail = new PHPMailer(true);

  try {
    // SMTP configuration
    $mail->isSMTP();
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
    $stmt = getDB()->prepare("
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

function sendWelcomeEmail($to, $team_name) {
  $mail = new PHPMailer(true);

  try {
    // SMTP configuration
    $mail->isSMTP();
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

    $mail->Body = "
      <html>
      <body style='font-family: Arial, sans-serif;'>
      <h2>Gefeliciteerd $team_name!</h2>
	 <p>Je bent aangemeld als recherche team!</p>
	 <p>Dit is een test e-mail om te controleren of alles in orde is.</p>
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
    <title>Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 { color: #333; }
        .logout { color: #667eea; text-decoration: none; font-weight: 600; }
        .success {
            background: #4caf50;
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .add-team {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .add-team h2 { margin-bottom: 20px; color: #333; }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        input[type="text"], input[type="email"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 1em;
        }
        button, .btn {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
        }
        button:hover, .btn:hover {
            background: #5568d3;
        }
        .teams-list {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
        }
        .level-up-btn {
            background: #4caf50;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .level-up-btn:hover {
            background: #45a049;
        }
        .level-up-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .delete-btn {
            background: #f44336;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
        }
        .delete-btn:hover {
            background: #da190b;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Dashboard</h1>
	    <span>
             <a target="_leaderboard" href="index.php" class="logout">Leaderboard</a> |
             <a href="assignments.php" class="logout">Assignments</a> |
             <a href="users.php" class="logout">Users</a> |
             <a href="password.php" class="logout">Change password</a> |
             <a href="logout.php" class="logout">Logout</a>
	    </span>
        </header>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success">
                <?= $_SESSION['success'] ?>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <div class="add-team">
            <h2>Add New Team</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_team">
                <div class="form-group">
                    <label>Team Name:</label>
                    <input type="text" name="team_name" required>
                </div>
                <div class="form-group">
                    <label>Team Email:</label>
                    <input type="email" name="email" required>
                </div>
                <button type="submit">Add Team</button>
            </form>
        </div>
        
        <div class="teams-list">
            <h2>Manage Teams</h2>
            <table>
                <thead>
                    <tr>
                        <th>Team Name</th>
                        <th>Email</th>
                        <th>Current Level</th>
                        <th>Progress</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teams as $team): ?>
                        <tr>
                            <td><?= htmlspecialchars($team['team_name']) ?></td>
                            <td><?= htmlspecialchars($team['email']) ?></td>
                            <td><?= $team['current_level'] ?>/<?= $total_assignments ?></td>
                            <td>
                                <?php 
                                $progress = ($total_assignments > 0) ? ($team['current_level'] / $total_assignments) * 100 : 0;
                                echo round($progress) . '%';
                                ?>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="level_up">
                                    <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                    <button type="submit" class="level-up-btn" 
                                        <?= $team['current_level'] >= $total_assignments ? 'disabled' : '' ?>>
                                        Level Up
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="resend_level_mail">
                                    <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                    <button type="submit" class="level-up-btn" 
                                        <?= $team['current_level'] >= $total_assignments ? 'disabled' : '' ?>>
                                        Resend Mail
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this team?');">
                                    <input type="hidden" name="action" value="delete_team">
                                    <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                    <button type="submit" class="delete-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
