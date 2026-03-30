<?php
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

// Tel ongelezen berichten voor de sidebar badge
$unread_total = $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();

function sendLevelUpEmail($db, $to, $team_id, $team_name, $level) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Timeout = 10;
        $mail->SMTPConnectTimeout = 5;
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to, $team_name);

        $mail->isHTML(true);
        $mail->Subject = "$team_name: Gefeliciteerd! Level $level is behaald!";

        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + 86400);

        $stmt = $db->prepare("INSERT INTO download_tokens (team_id, level, token, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$team_id, $level, $token, $expires_at]);

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

// Handle antwoord van docent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    $team_id = (int)$_POST['team_id'];
    $lvl = (int)$_POST['level'];
    $msg = trim($_POST['message'] ?? '');
    
    if (!empty($msg)) {
        $stmt = $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message) VALUES (?, ?, 'teacher', ?)");
        $stmt->execute([$team_id, $lvl, $msg]);
        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'MSG_REPLY', ?)")->execute([$_SESSION['teacher_id'], "Replied to team ID $team_id on level $lvl"]);
        header("Location: messages.php?team_id=$team_id");
        exit;
    }
}

// Handle level up vanuit chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'level_up') {
    $team_id = (int)$_POST['team_id'];
    
    $stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($team) {
        $new_level = $team['current_level'] + 1;
        $db->prepare("UPDATE teams SET current_level = ?, level_updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$new_level, $team_id]);
        
        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'TEAM_UPDATE', ?)")
           ->execute([$_SESSION['teacher_id'], "Team '{$team['team_name']}' level up via chat naar $new_level"]);
        
        @sendLevelUpEmail($db, $team['email'], $team_id, $team['team_name'], $new_level);
        header("Location: messages.php?team_id=$team_id");
        exit;
    }
}

// Haal lijst met actieve teams op met aantal ongelezen berichten
$teams_with_msgs = $db->query("
    SELECT t.id, t.team_name, t.current_level, 
    (SELECT COUNT(*) FROM team_messages WHERE team_id = t.id AND sender = 'team' AND is_read = 0) as unread_count
    FROM teams t 
    WHERE t.id IN (SELECT team_id FROM team_messages)
    ORDER BY (SELECT MAX(created_at) FROM team_messages WHERE team_id = t.id) DESC
")->fetchAll(PDO::FETCH_ASSOC);

$selected_team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;
$chat_messages = [];
$selected_team = null;
$assignment = null;

if ($selected_team_id) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
    $stmt->execute([$selected_team_id]);
    $selected_team = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_team) {
        // Markeer berichten als gelezen
        $db->prepare("UPDATE team_messages SET is_read = 1 WHERE team_id = ? AND sender = 'team' AND assignment_number = ?")
           ->execute([$selected_team_id, $selected_team['current_level']]);

        // Haal assignment info op
        $stmt = $db->prepare("SELECT * FROM assignments WHERE assignment_number = ?");
        $stmt->execute([$selected_team['current_level']]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT * FROM team_messages WHERE team_id = ? AND assignment_number = ? ORDER BY created_at ASC");
        $stmt->execute([$selected_team_id, $selected_team['current_level']]);
        $chat_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// AJAX endpoints voor live updates
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'chat' && $selected_team) {
        // Markeer ook nieuwe berichten die via AJAX binnenkomen als gelezen
        $db->prepare("UPDATE team_messages SET is_read = 1 WHERE team_id = ? AND sender = 'team' AND assignment_number = ?")
           ->execute([$selected_team_id, $selected_team['current_level']]);

        foreach ($chat_messages as $m) {
            echo '<div class="message ' . $m['sender'] . '">';
            echo '<small>' . ($m['sender'] === 'team' ? 'Team' : 'Jij') . ' - ' . $m['created_at'] . '</small><br>';
            echo nl2br(htmlspecialchars($m['message']));
            echo '</div>';
        }
    } elseif ($_GET['ajax'] === 'sidebar') {
        foreach ($teams_with_msgs as $t) {
            $active = ($selected_team_id == $t['id'] ? 'active' : '');
            echo '<a href="?team_id=' . $t['id'] . '" class="team-item ' . $active . '">';
            echo '<strong>' . htmlspecialchars($t['team_name']) . '</strong>';
            if ($t['unread_count'] > 0) {
                echo ' <span style="background:red; color:white; padding:1px 5px; border-radius:10px; font-size:0.7em;">' . $t['unread_count'] . '</span>';
            }
            echo '<br><small>Level: ' . $t['current_level'] . '</small>';
            echo '</a>';
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Berichten Beheer</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; display: flex; min-height: 100vh; }
        
        /* Sidebar Navigation */
        .sidebar-main { width: 260px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; position: fixed; height: 100vh; }
        .sidebar-header { padding: 30px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-align: center; font-weight: bold; font-size: 1.2em; }
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .nav-item { display: flex; align-items: center; padding: 12px 25px; color: #4b4f56; text-decoration: none; transition: 0.2s; font-weight: 500; }
        .nav-item:hover { background: #f0f2f5; color: #667eea; }
        .nav-item.active { background: #f0f4ff; color: #667eea; border-left: 4px solid #667eea; }
        .badge { background: #f44336; color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75em; margin-left: auto; }

        /* Messages Layout */
        .main-content { margin-left: 260px; flex: 1; display: flex; height: 100vh; overflow: hidden; }
        .team-list-panel { width: 300px; background: white; border-right: 1px solid #ddd; overflow-y: auto; display: flex; flex-direction: column; }
        .team-list-header { padding: 20px; border-bottom: 1px solid #eee; font-weight: bold; }
        .chat-area { flex: 1; padding: 40px; display: flex; flex-direction: column; overflow-y: auto; }

        .team-item { padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; text-decoration: none; color: #333; display: block; }
        .team-item:hover, .team-item.active { background: #f0f4ff; }
        .chat-window { background: white; border-radius: 10px; padding: 20px; flex: 1; display: flex; flex-direction: column; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .messages-box { 
            flex: 1; 
            height: 500px; 
            overflow-y: auto; 
            margin-bottom: 20px; 
            padding: 10px; 
            display: flex; 
            flex-direction: column; 
            min-height: 0;
        }
        .message { margin-bottom: 10px; padding: 10px; border-radius: 5px; max-width: 70%; }
        .message.team { background: #e3f2fd; align-self: flex-start; }
        .message.teacher { background: #f1f8e9; align-self: flex-end; margin-left: auto; }
        textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        button { background: #667eea; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-top: 10px; }
        .assignment-info { background: #fffde7; padding: 15px; border-radius: 10px; border-left: 5px solid #fbc02d; margin-bottom: 20px; font-size: 0.9em; }
    </style>
</head>
<body>
    <nav class="sidebar-main">
        <div class="sidebar-header">Zebrawave Admin</div>
        <div class="sidebar-nav">
            <a href="teacher.php" class="nav-item">📊 Dashboard</a>
            <a href="assignments.php" class="nav-item">📘 Assignments</a>
            <a href="messages.php" class="nav-item active">
                ✉️ Messages
                <span id="unread-badge-container">
                    <?php if ($unread_total > 0): ?>
                        <span class="badge"><?= $unread_total ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <a href="users.php" class="nav-item">👥 Users</a>
            <a href="audit.php" class="nav-item">📋 Audit Logs</a>
            <div style="margin-top: 20px; padding: 0 25px; font-size: 0.7em; color: #bbb; text-transform: uppercase;">Settings</div>
            <a href="password.php" class="nav-item">🔑 Password</a>
            <a href="logout.php" class="nav-item" style="margin-top: auto; color: #c62828;">🚪 Logout</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="team-list-panel" id="sidebar-list">
            <div class="team-list-header">Teams</div>
            <?php foreach ($teams_with_msgs as $t): ?>
                <a href="?team_id=<?= $t['id'] ?>" class="team-item <?= $selected_team_id == $t['id'] ? 'active' : '' ?>">
                    <strong><?= htmlspecialchars($t['team_name']) ?></strong>
                    <?php if ($t['unread_count'] > 0): ?>
                        <span style="background:red; color:white; padding:1px 5px; border-radius:10px; font-size:0.7em;"><?= $t['unread_count'] ?></span>
                    <?php endif; ?>
                    <br>
                    <small>Level: <?= $t['current_level'] ?></small>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="chat-area">
            <?php if ($selected_team): ?>
                <h1 style="margin-bottom: 20px;">Chat met <?= htmlspecialchars($selected_team['team_name']) ?> (Level <?= $selected_team['current_level'] ?>)</h1>
                
                <?php if ($assignment): ?>
                    <div class="assignment-info">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <strong>Huidige Opdracht: <?= htmlspecialchars($assignment['title']) ?></strong><br>
                                <?= nl2br(htmlspecialchars($assignment['description'])) ?>
                            </div>
                            <form method="POST" onsubmit="return confirm('Weet je zeker dat dit team een level omhoog mag?');">
                                <input type="hidden" name="action" value="level_up">
                                <input type="hidden" name="team_id" value="<?= $selected_team['id'] ?>">
                                <button type="submit" style="background: #4caf50; margin-top: 0;">Level Up!</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="chat-window">
                    <div class="messages-box" id="chat-box">
                        <?php foreach ($chat_messages as $m): ?>
                            <div class="message <?= $m['sender'] ?>">
                                <small><?= $m['sender'] === 'team' ? 'Team' : 'Jij' ?> - <?= $m['created_at'] ?></small><br>
                                <?= nl2br(htmlspecialchars($m['message'])) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="team_id" value="<?= $selected_team_id ?>">
                        <input type="hidden" name="level" value="<?= $selected_team['current_level'] ?>">
                        <textarea name="message" rows="3" placeholder="Type je reactie..." required></textarea>
                        <button type="submit">Beantwoorden</button>
                    </form>
                </div>
            <?php else: ?>
                <div style="flex: 1; display: flex; align-items: center; justify-content: center; color: #888;">
                    <h2>Selecteer een team om de chat te openen</h2>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function scrollToBottom() {
            const chatBox = document.getElementById('chat-box');
            if (chatBox) {
                setTimeout(() => {
                    chatBox.scrollTop = chatBox.scrollHeight;
                }, 50);
            }
        }

        function updateChat() {
            <?php if ($selected_team_id): ?>
            fetch('messages.php?team_id=<?= $selected_team_id ?>&ajax=chat')
                .then(r => r.text()).then(html => {
                    const cb = document.getElementById('chat-box');
                    if (cb.innerHTML !== html) {
                        cb.innerHTML = html;
                        scrollToBottom();
                    }
                });
            <?php endif; ?>
            
            fetch('messages.php?ajax=sidebar' + (<?= $selected_team_id ?: '0' ?> ? '&team_id=<?= $selected_team_id ?>' : ''))
                .then(r => r.text()).then(html => document.getElementById('sidebar-list').innerHTML = `<div class="team-list-header">Teams</div>${html}`);
        }

        setInterval(updateChat, 5000);
        scrollToBottom();
    </script>
</body>
</html>