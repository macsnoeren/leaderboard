<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

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

// Haal lijst met actieve teams op die berichten hebben
$teams_with_msgs = $db->query("SELECT DISTINCT t.id, t.team_name, t.current_level FROM teams t JOIN team_messages tm ON t.id = tm.team_id ORDER BY tm.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$selected_team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;
$chat_messages = [];
$selected_team = null;

if ($selected_team_id) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
    $stmt->execute([$selected_team_id]);
    $selected_team = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_team) {
        $stmt = $db->prepare("SELECT * FROM team_messages WHERE team_id = ? AND assignment_number = ? ORDER BY created_at ASC");
        $stmt->execute([$selected_team_id, $selected_team['current_level']]);
        $chat_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Berichten Beheer</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f5f5f5; display: flex; height: 100vh; margin: 0; }
        .sidebar { width: 300px; background: white; border-right: 1px solid #ddd; overflow-y: auto; padding: 20px; }
        .main { flex: 1; padding: 40px; display: flex; flex-direction: column; }
        .team-item { padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; text-decoration: none; color: #333; display: block; }
        .team-item:hover, .team-item.active { background: #f0f4ff; }
        .chat-window { background: white; border-radius: 10px; padding: 20px; flex: 1; display: flex; flex-direction: column; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .messages { flex: 1; overflow-y: auto; margin-bottom: 20px; padding: 10px; }
        .message { margin-bottom: 10px; padding: 10px; border-radius: 5px; max-width: 70%; }
        .message.team { background: #e3f2fd; align-self: flex-start; }
        .message.teacher { background: #f1f8e9; align-self: flex-end; margin-left: auto; }
        textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        button { background: #667eea; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Teams</h2>
        <a href="teacher.php" style="display:block; margin-bottom:20px; color:#667eea;">← Dashboard</a>
        <?php foreach ($teams_with_msgs as $t): ?>
            <a href="?team_id=<?= $t['id'] ?>" class="team-item <?= $selected_team_id == $t['id'] ? 'active' : '' ?>">
                <strong><?= htmlspecialchars($t['team_name']) ?></strong><br>
                <small>Level: <?= $t['current_level'] ?></small>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="main">
        <?php if ($selected_team): ?>
            <h1>Chat met <?= htmlspecialchars($selected_team['team_name']) ?> (Level <?= $selected_team['current_level'] ?>)</h1>
            <div class="chat-window">
                <div class="messages">
                    <?php foreach ($chat_messages as $m): ?>
                        <div class="message <?= $m['sender'] ?>">
                            <small><?= $m['sender'] === 'team' ? 'Team' : 'Jij' ?> - <?= $m['created_at'] ?></small><br>
                            <?= nl2br(htmlspecialchars($m['message'])) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="team_id" value="<?= $selected_team['id'] ?>">
                    <input type="hidden" name="level" value="<?= $selected_team['current_level'] ?>">
                    <textarea name="message" rows="3" placeholder="Type je reactie..." required></textarea>
                    <button type="submit">Beantwoorden</button>
                </form>
            </div>
        <?php else: ?>
            <h1>Selecteer een team om de berichten te bekijken</h1>
        <?php endif; ?>
    </div>
</body>
</html>