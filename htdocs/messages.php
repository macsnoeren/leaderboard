<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/mail_functions.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}
$db = getDB();
$selected_team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;

// Handle antwoord van docent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    $team_id = (int)$_POST['team_id'];
    $lvl = (int)$_POST['level'];
    $msg = trim($_POST['message'] ?? '');
    
    if (!empty($msg)) {
        $stmt = $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message) VALUES (?, ?, 'teacher', ?)");
        $stmt->execute([$team_id, $lvl, $msg]);
        
        // Verwijder AI suggesties zodra de docent zelf antwoordt
        $db->prepare("DELETE FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender = 'suggestion'")
           ->execute([$team_id, $lvl]);

        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'MSG_REPLY', ?)")->execute([$_SESSION['teacher_id'], "Replied to team ID $team_id on level $lvl"]);
        header("Location: messages.php?team_id=$team_id");
        exit;
    }
}

// Handle verwijderen van suggestie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_suggestion') {
    $msg_id = (int)$_POST['message_id'];
    $stmt = $db->prepare("DELETE FROM team_messages WHERE id = ? AND sender = 'suggestion'");
    $stmt->execute([$msg_id]);
    $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'MSG_DELETE', ?)")->execute([$_SESSION['teacher_id'], "Rejected and deleted AI suggestion ID $msg_id"]);
    header("Location: messages.php" . ($selected_team_id ? "?team_id=$selected_team_id" : ""));
    exit;
}

// Handle level up vanuit chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'level_up') {
    $team_id = (int)$_POST['team_id'];
    
    $stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($team) {
        // Archiveer de chatgeschiedenis voor de huidige opdracht
        $stmtMsgs = $db->prepare("SELECT sender, message FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender != 'suggestion' ORDER BY created_at ASC");
        $stmtMsgs->execute([$team_id, $team['current_level']]);
        $history = [];
        foreach ($stmtMsgs->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $history[] = ($m['sender'] === 'team' ? 'Team: ' : 'Docent: ') . $m['message'];
        }
        $db->prepare("INSERT INTO completed_assignments (team_id, assignment_number, chat_history) VALUES (?, ?, ?)")
           ->execute([$team_id, $team['current_level'], implode("\n", $history)]);

        // Voer de level up uit
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
            echo '<div class="msg-body">' . nl2br(htmlspecialchars($m['message'])) . '</div>';
            if ($m['sender'] === 'suggestion') {
                echo '<div style="display:flex; gap:5px; margin-top:8px;">';
                echo '<button class="btn btn-outline" style="padding: 2px 8px; font-size: 0.75em;" onclick="useSuggestion(this)">Overnemen</button>';
                echo '<button class="btn btn-danger" style="padding: 2px 8px; font-size: 0.75em; background:#fff1f0;" onclick="rejectSuggestion(' . $m['id'] . ')">Verwerpen</button>';
                echo '</div>';
            }
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

$pageTitle = 'Berichten';
$extraCSS = '
<style>
    .main-content { display: flex; flex-direction: column; height: 100vh; overflow: hidden; padding: 0 !important; }
    .test-mode-banner { padding: 15px 40px 0 40px; flex-shrink: 0; }
    .messages-layout-wrapper { display: flex; flex: 1; overflow: hidden; width: 100%; }
    .team-list-panel { width: 300px; background: white; border-right: 1px solid #ddd; overflow-y: auto; display: flex; flex-direction: column; }
    .team-list-header { padding: 20px; border-bottom: 1px solid #eee; font-weight: bold; }
    .chat-area { flex: 1; padding: 40px; display: flex; flex-direction: column; overflow-y: auto; }
    .team-item { padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; text-decoration: none; color: #333; display: block; }
    .team-item:hover, .team-item.active { background: #f0f4ff; }
    .chat-window { background: white; border-radius: 10px; padding: 20px; flex: 1; display: flex; flex-direction: column; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .messages-box { flex: 1; height: 500px; overflow-y: auto; margin-bottom: 20px; padding: 10px; display: flex; flex-direction: column; min-height: 0; }
    .message { margin-bottom: 15px; padding: 12px; border-radius: 10px; max-width: 75%; font-size: 0.95em; line-height: 1.4; }
    .message.team { background: #e3f2fd; align-self: flex-start; }
    .message.teacher { background: #f1f8e9; align-self: flex-end; margin-left: auto; }
    .message.suggestion { background: #f3e5f5; border: 1px dashed #9c27b0; align-self: flex-start; color: #4a148c; }

    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); overflow-y: auto; }
    .modal-content { background-color: white; margin: 5% auto; padding: 30px; border-radius: 15px; width: 70%; max-width: 800px; box-shadow: 0 5px 30px rgba(0,0,0,0.3); position: relative; }
    .close-modal { position: absolute; top: 20px; right: 25px; font-size: 28px; font-weight: bold; cursor: pointer; color: #aaa; transition: 0.2s; }
    .close-modal:hover { color: #333; }
    .modal-section-title { font-weight: bold; color: #667eea; border-bottom: 2px solid #f0f4ff; padding-bottom: 5px; margin-top: 20px; margin-bottom: 10px; }

    /* Markdown Styling binnen Modal */
    .markdown-body { font-size: 1rem; line-height: 1.6; color: #4b4f56; }
    .markdown-body h1 { font-size: 1.4em; margin: 15px 0 10px 0; color: #333; }
    .markdown-body h2 { font-size: 1.25em; margin: 12px 0 8px 0; color: #333; }
    .markdown-body h3 { font-size: 1.1em; margin: 10px 0 5px 0; color: #333; }
    .markdown-body p { margin-bottom: 12px; }
    .markdown-body ul, .markdown-body ol { margin-left: 20px; margin-bottom: 15px; }
    .markdown-body li { margin-bottom: 5px; }
    .markdown-body code { background: #f0f2f5; padding: 2px 5px; border-radius: 4px; font-family: monospace; font-size: 0.9em; color: #e83e8c; }
    .markdown-body pre { background: #2d2d2d; color: #ccc; padding: 15px; border-radius: 8px; overflow-x: auto; margin-bottom: 15px; }
    .markdown-body pre code { background: transparent; padding: 0; color: inherit; font-size: 0.85em; }
</style>';

include 'admin_header.php';
?>
    <?php if (defined('ENABLE_EMAIL') && ENABLE_EMAIL === false): ?>
        <div class="test-mode-banner"></div> <!-- CSS zorgt voor de correcte uitlijning van de banner uit de header -->
    <?php endif; ?>

    <div class="messages-layout-wrapper">
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1 style="margin-bottom: 0;">Chat met <?= htmlspecialchars($selected_team['team_name']) ?> (Level <?= $selected_team['current_level'] ?>)</h1>
                    <div style="display: flex; gap: 10px;">
                        <?php if ($assignment): ?>
                            <button class="btn btn-outline" onclick="openAssignmentModal()">📄 Opdracht</button>
                            <form method="POST" onsubmit="return confirm('Weet je zeker dat dit team een level omhoog mag?');" style="margin:0;">
                                <input type="hidden" name="action" value="level_up">
                                <input type="hidden" name="team_id" value="<?= $selected_team['id'] ?>">
                                <button type="submit" class="btn btn-success" style="margin-top: 0; padding: 8px 16px;">🚀 Level Up!</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($assignment): ?>
                    <div id="assignmentModal" class="modal">
                        <div class="modal-content">
                            <span class="close-modal" onclick="closeAssignmentModal()">&times;</span>
                            <h2 style="color: #333; margin-bottom: 20px;">Opdracht Details: <?= htmlspecialchars($assignment['title']) ?></h2>
                            
                            <div class="modal-section-title">Beschrijving</div>
                            <div class="markdown-body"><?= htmlspecialchars($assignment['description']) ?></div>
                            
                            <?php if (!empty($assignment['instruction'])): ?>
                                <div class="modal-section-title">📍 Instructie</div>
                                <div class="markdown-body" style="background: #f8f9fa; padding: 15px; border-radius: 8px;"><?= htmlspecialchars($assignment['instruction']) ?></div>
                            <?php endif; ?>
                            
                            <?php if (!empty($assignment['criteria'])): ?>
                                <div class="modal-section-title">📋 Beoordelingscriteria</div>
                                <div class="markdown-body" style="color: #d32f2f; background: #fff1f0; padding: 15px; border-radius: 8px;"><?= htmlspecialchars($assignment['criteria']) ?></div>
                            <?php endif; ?>

                            <div style="margin-top: 30px; text-align: right;">
                                <button class="btn btn-primary" onclick="closeAssignmentModal()">Sluiten</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="chat-window">
                    <div class="messages-box" id="chat-box">
                        <?php foreach ($chat_messages as $m): ?>
                            <div class="message <?= $m['sender'] ?>">
                                <small><?= $m['sender'] === 'team' ? 'Team' : 'Jij' ?> - <?= $m['created_at'] ?></small><br>
                                <div class="msg-body"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                                <?php if ($m['sender'] === 'suggestion'): ?>
                                    <div style="display:flex; gap:5px; margin-top:8px;">
                                        <button class="btn btn-outline" style="padding: 2px 8px; font-size: 0.75em;" onclick="useSuggestion(this)">Overnemen</button>
                                        <button class="btn btn-danger" style="padding: 2px 8px; font-size: 0.75em; background:#fff1f0;" onclick="rejectSuggestion(<?= $m['id'] ?>)">Verwerpen</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="team_id" value="<?= $selected_team_id ?>">
                        <input type="hidden" name="level" value="<?= $selected_team['current_level'] ?>">
                        <textarea id="teacher-reply-box" name="message" rows="3" placeholder="Type je reactie..." required></textarea>
                        <button type="submit" class="btn btn-primary" style="margin-top: 10px; width: 100%;">Verstuur Antwoord</button>
                    </form>
                </div>
            <?php else: ?>
                <div style="flex: 1; display: flex; align-items: center; justify-content: center; color: #888;">
                    <h2>Selecteer een team om de chat te openen</h2>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php
$extraJS = "
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
            " . ($selected_team_id ? "
            fetch('messages.php?team_id=$selected_team_id&ajax=chat')
                .then(r => r.text()).then(html => {
                    const cb = document.getElementById('chat-box');
                    if (cb.innerHTML !== html) {
                        cb.innerHTML = html;
                        scrollToBottom();
                    }
                });
            " : "") . "
            
            const teamId = " . ($selected_team_id ?: '0') . ";
            fetch('messages.php?ajax=sidebar' + (teamId ? '&team_id=' + teamId : ''))
                .then(r => r.text()).then(html => {
                    const sl = document.getElementById('sidebar-list');
                    if (sl) sl.innerHTML = '<div class=\"team-list-header\">Teams</div>' + html;
                });
        }
        setInterval(updateChat, 5000);
        scrollToBottom();

        function useSuggestion(btn) {
            const messageDiv = btn.closest('.message');
            if (!messageDiv) return;
            
            const msgBodyDiv = messageDiv.querySelector('.msg-body');
            if (!msgBodyDiv) return;

            const fullText = msgBodyDiv.innerText;
            // Split op de dubbele newline om de header (score/model) te scheiden van de feedback
            const parts = fullText.split('\\n');
            const feedback = parts.length > 1 ? parts.slice(1).join('\\n').trim() : fullText;

            const textarea = document.getElementById('teacher-reply-box');
            if (textarea) {
                textarea.value = feedback;
                textarea.focus();
            }
        }

        function rejectSuggestion(id) {
            if (!confirm('Weet je zeker dat je deze AI suggestie wilt verwijderen?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type=\"hidden\" name=\"action\" value=\"reject_suggestion\">
                <input type=\"hidden\" name=\"message_id\" value=\"\${id}\">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function openAssignmentModal() {
            document.getElementById('assignmentModal').style.display = 'block';
            renderModalMarkdown();
        }

        function closeAssignmentModal() {
            document.getElementById('assignmentModal').style.display = 'none';
        }

        // Sluit modal als je buiten de content klikt
        window.onclick = function(event) {
            const modal = document.getElementById('assignmentModal');
            if (event.target == modal) {
                closeAssignmentModal();
            }
        }

        function renderModalMarkdown() {
            marked.setOptions({ breaks: true });
            document.querySelectorAll('.markdown-body').forEach(el => {
                if (el.getAttribute('data-rendered') !== 'true') {
                    el.innerHTML = marked.parse(el.textContent.trim());
                    el.setAttribute('data-rendered', 'true');
                }
            });
        }
    </script>";
include 'admin_footer.php'; ?>