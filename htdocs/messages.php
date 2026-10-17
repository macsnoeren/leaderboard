<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/mail_functions.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);
$userId = (int)$user['id'];
$game = requireGame($db, $user);
$gameId = (int)$game['id'];
$selected_team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;

/**
 * Antwoord van de docent op het huidige level: suggesties van dat level verdwijnen en open
 * AI-escalaties zijn daarmee afgehandeld.
 */
function teacherReply(PDO $db, array $team, string $msg, int $userId): void {
    $teamId = (int)$team['id'];
    $lvl = (int)$team['current_level'];
    $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message) VALUES (?, ?, 'teacher', ?)")->execute([$teamId, $lvl, $msg]);
    // Verwijder AI suggesties zodra de docent zelf antwoordt
    $db->prepare("DELETE FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender = 'suggestion'")->execute([$teamId, $lvl]);
    resolveEscalations($db, $teamId, $lvl, $userId);
    logAudit($db, $userId, 'MSG_REPLY', "Replied to team ID $teamId on level $lvl");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $back = 'messages.php' . ($selected_team_id ? "?team_id=$selected_team_id" : '');
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect($back);
    }

    // Handle verwijderen van suggestie (alleen van een team in het actieve spel)
    if ($action === 'reject_suggestion') {
        $msg_id = (int)$_POST['message_id'];
        $stmt = $db->prepare("SELECT tm.* FROM team_messages tm JOIN teams t ON t.id = tm.team_id WHERE tm.id = ? AND tm.sender = 'suggestion' AND t.game_id = ?");
        $stmt->execute([$msg_id, $gameId]);
        $suggestion = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($suggestion) {
            $db->prepare("DELETE FROM team_messages WHERE id = ?")->execute([$msg_id]);
            // Een verworpen escalatie is ook afgehandeld; anders blijft de AI geblokkeerd zonder knoppen
            if ($suggestion['ai_evaluation_id']) {
                $db->prepare("UPDATE ai_evaluations SET resolved_by = ?, resolved_at = datetime('now') WHERE id = ? AND decision = 'escalate' AND resolved_at IS NULL")
                   ->execute([$userId, (int)$suggestion['ai_evaluation_id']]);
            }
            logAudit($db, $userId, 'MSG_DELETE', "Rejected and deleted AI suggestion ID $msg_id");
        }
        redirect($back);
    }

    $team = findTeamInGame($db, (int)($_POST['team_id'] ?? 0), $gameId);
    if (!$team) {
        flash('error', 'Dit team hoort niet bij het actieve spel.');
        redirect('messages.php');
    }
    $team_id = (int)$team['id'];
    $lvl = (int)$team['current_level'];

    // Beoordeling bij een suggestie of escalatie: alleen uit dit spel, van dit team
    $evaluation = null;
    if (isset($_POST['evaluation_id'])) {
        $evaluation = findEvaluationInGame($db, (int)$_POST['evaluation_id'], $gameId);
        if (!$evaluation || (int)$evaluation['team_id'] !== $team_id) {
            flash('error', 'Deze AI-beoordeling hoort niet bij dit team.');
            redirect("messages.php?team_id=$team_id");
        }
    }

    // Handle antwoord van docent: altijd op het huidige level van het team
    if ($action === 'reply') {
        $msg = trim($_POST['message'] ?? '');
        if ($msg !== '') {
            teacherReply($db, $team, $msg, $userId);
        }
    } elseif ($action === 'send_suggestion' && $evaluation) {
        // De voorgestelde reactie van de AI ongewijzigd versturen
        if (trim((string)$evaluation['reply_text']) === '') {
            flash('error', 'Deze suggestie heeft geen voorgestelde reactie.');
        } else {
            teacherReply($db, $team, $evaluation['reply_text'], $userId);
        }
    } elseif ($action === 'escalation' && $evaluation) {
        $choice = $_POST['choice'] ?? '';
        $labels = ['agree_level_up' => 'Eens → level up', 'agree_reply' => 'Eens → reactie sturen', 'disagree' => 'Oneens → zelf antwoorden', 'handled' => 'Afgehandeld'];
        if (!isset($labels[$choice])) {
            flash('error', 'Onbekende keuze.');
            redirect("messages.php?team_id=$team_id");
        }
        $verdict = ['agree_level_up' => 'agree', 'agree_reply' => 'agree', 'disagree' => 'disagree', 'handled' => null][$choice];
        resolveEscalations($db, $team_id, (int)$evaluation['assignment_number'], $userId, $verdict);
        if ($verdict !== null) {
            // Ook de beoordeling waar de knop bij stond, als die van een eerder level is
            $db->prepare("UPDATE ai_evaluations SET teacher_verdict = ? WHERE id = ?")->execute([$verdict, (int)$evaluation['id']]);
        }
        logAudit($db, $userId, 'AI_ESCALATION_RESOLVE', "AI-escalatie (beoordeling #{$evaluation['id']}) van team ID $team_id afgehandeld: {$labels[$choice]}");
        if ($choice === 'agree_level_up' && !levelUp($db, $team_id, $userId)) {
            flash('error', "Team '{$team['team_name']}' kan niet verder omhoog.");
        } elseif ($choice === 'agree_reply') {
            if (trim((string)$evaluation['reply_text']) === '') {
                flash('error', 'De AI had geen voorgestelde reactie; schrijf zelf een antwoord.');
            } else {
                teacherReply($db, $team, $evaluation['reply_text'], $userId);
            }
        } elseif ($choice === 'disagree') {
            redirect("messages.php?team_id=$team_id&focus=reply");
        }
    } elseif ($action === 'clear_progress') {
        clearTeamProgress($db, $team_id, $lvl, $userId);
        flash('success', 'De AI-tussenstand van dit level is gewist.');
    } elseif ($action === 'level_up') {
        if (!levelUp($db, $team_id, $userId)) {
            flash('error', "Team '{$team['team_name']}' kan niet verder omhoog.");
        }
    } elseif ($action === 'level_down') {
        if (!levelDown($db, $team_id, $userId)) {
            flash('error', "Team '{$team['team_name']}' staat al op het laagste level.");
        }
    }
    redirect("messages.php?team_id=$team_id");
}

// Teams van dit spel met berichten; ongelezen telt alleen het huidige level
$stmt = $db->prepare("
    SELECT t.id, t.team_name, t.current_level,
    (SELECT COUNT(*) FROM team_messages WHERE team_id = t.id AND sender = 'team' AND is_read = 0 AND assignment_number = t.current_level) as unread_count
    FROM teams t
    WHERE t.game_id = ? AND t.id IN (SELECT team_id FROM team_messages)
    ORDER BY (SELECT MAX(created_at) FROM team_messages WHERE team_id = t.id) DESC
");
$stmt->execute([$gameId]);
$teams_with_msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$escalations = openEscalationsByTeam($db, $gameId);

$chat_messages = [];
$selected_team = null;
$assignment = null;
$total_assignments = totalAssignments($db, $gameId);
$logo = gameLogoUrl($game);

function chatMessages(PDO $db, int $teamId, int $level): array {
    $stmt = $db->prepare("SELECT * FROM team_messages WHERE team_id = ? AND assignment_number = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([$teamId, $level]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($selected_team_id) {
    $selected_team = findTeamInGame($db, $selected_team_id, $gameId);
    if (!$selected_team) {
        $selected_team_id = null;
    }

    if ($selected_team) {
        // Markeer berichten als gelezen
        $db->prepare("UPDATE team_messages SET is_read = 1 WHERE team_id = ? AND sender = 'team' AND assignment_number = ?")
           ->execute([$selected_team_id, $selected_team['current_level']]);

        // Haal assignment info op
        $stmt = $db->prepare("SELECT * FROM assignments WHERE game_id = ? AND assignment_number = ?");
        $stmt->execute([$gameId, $selected_team['current_level']]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

        $chat_messages = chatMessages($db, $selected_team_id, (int)$selected_team['current_level']);
    }
}

// Check Presence: Wie kijkt er nog meer? (korter dan 15 seconden geleden)
$other_teachers = [];
if ($selected_team_id) {
    $current_teacher = $_SESSION['teacher_username'] ?? 'Onbekende Docent';

    // Opschonen oude sessies en eigen aanwezigheid registreren
    $db->exec("DELETE FROM team_presence WHERE last_seen < datetime('now', '-30 seconds')");
    $db->prepare("INSERT OR REPLACE INTO team_presence (team_id, username, last_seen) VALUES (?, ?, CURRENT_TIMESTAMP)")
       ->execute([$selected_team_id, $current_teacher]);

    // Zoek andere actieve docenten voor dit team
    $stmt = $db->prepare("SELECT username FROM team_presence WHERE team_id = ? AND username != ? AND last_seen > datetime('now', '-15 seconds')");
    $stmt->execute([$selected_team_id, $current_teacher]);
    $other_teachers = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Knop in een eigen POST-formulier met CSRF-token
function chatButton(int $teamId, array $fields, string $label, string $class, ?string $confirm = null): string {
    $html = '<form method="POST" style="display:inline; margin:0;"' . ($confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '') . '>' . csrfField()
        . '<input type="hidden" name="team_id" value="' . $teamId . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '">';
    }
    return $html . '<button type="submit" class="btn ' . $class . '" style="padding: 2px 8px; font-size: 0.75em;">' . $label . '</button></form>';
}

/**
 * De chat van een level als HTML. Suggesties met een beoordeling (ai_evaluation_id) krijgen een vaste
 * opmaak; bij een open escalatie staat het teamantwoord naast het voorlopige oordeel, met de knoppen.
 */
function renderChat(PDO $db, array $messages, int $gameId, int $teamId): string {
    $evalIds = array_filter(array_map(function ($m) { return (int)$m['ai_evaluation_id']; }, $messages));
    $evaluations = [];
    if ($evalIds) {
        $stmt = $db->prepare("SELECT * FROM ai_evaluations WHERE game_id = ? AND id IN (" . implode(',', array_fill(0, count($evalIds), '?')) . ")");
        $stmt->execute(array_merge([$gameId], array_values($evalIds)));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $evaluations[(int)$e['id']] = $e;
        }
    }
    $teamTexts = [];
    foreach ($messages as $m) {
        if ($m['sender'] === 'team') {
            $teamTexts[(int)$m['id']] = $m['message'];
        }
    }

    $html = '';
    foreach ($messages as $m) {
        $label = ['team' => 'Team', 'teacher' => 'Jij', 'ai' => '🤖 AI'][$m['sender']] ?? '🤖 AI-suggestie';
        $e = $m['sender'] === 'suggestion' ? ($evaluations[(int)$m['ai_evaluation_id']] ?? null) : null;
        if (!$e) {
            $html .= '<div class="message ' . htmlspecialchars($m['sender']) . '">';
            $html .= '<small>' . $label . ' - ' . htmlspecialchars($m['created_at']) . '</small><br>';
            $html .= '<div class="msg-body">' . nl2br(htmlspecialchars($m['message'])) . '</div>';
            if ($m['sender'] === 'suggestion') {
                $html .= '<div style="display:flex; gap:5px; margin-top:8px;">';
                $html .= '<button class="btn btn-danger" style="padding: 2px 8px; font-size: 0.75em; background:#fff1f0;" onclick="rejectSuggestion(' . (int)$m['id'] . ')">Verwerpen</button>';
                $html .= '</div>';
            }
            $html .= '</div>';
            continue;
        }

        $escalation = $e['decision'] === 'escalate';
        $open = $escalation && $e['resolved_at'] === null;
        $levelUpAdvice = in_array($e['verdict'], ['voldoende', 'goed'], true);
        $reply = (string)$e['reply_text'];
        $html .= '<div class="message suggestion' . ($escalation ? ' escalation' : '') . '">';
        $html .= '<small>' . ($escalation ? '🙋 Docent nodig' . ($open ? '' : ' (afgehandeld)') : '🤖 AI-suggestie') . ' - ' . htmlspecialchars($m['created_at']) . '</small>';
        $html .= '<dl class="ai-fields">';
        $html .= '<dt>Intentie</dt><dd>' . htmlspecialchars($e['intent']) . '</dd>';
        if ($e['verdict']) {
            $html .= '<dt>Oordeel</dt><dd><span class="verdict ' . htmlspecialchars($e['verdict']) . '">' . htmlspecialchars($e['verdict']) . '</span>'
                . ($levelUpAdvice ? ' <strong class="levelup-advice">⬆️ level-up-advies</strong>' : '') . '</dd>';
        }
        if (trim((string)$e['reason']) !== '') {
            $html .= '<dt>Reden</dt><dd>' . nl2br(htmlspecialchars($e['reason'])) . '</dd>';
        }
        if ($reply !== '') {
            $html .= '<dt>Voorgestelde reactie</dt><dd class="proposal">' . nl2br(htmlspecialchars($reply)) . '</dd>';
        }
        $html .= '</dl>';

        if ($open) {
            $answer = $teamTexts[(int)$e['message_id']] ?? null;
            $html .= '<div class="escalation-compare">';
            $html .= '<div><strong>Antwoord van het team</strong><div class="quote">' . ($answer === null ? '<em>(bericht niet meer in deze chat)</em>' : nl2br(htmlspecialchars($answer))) . '</div></div>';
            $html .= '<div><strong>Voorlopig oordeel van de AI</strong><div class="quote">' . htmlspecialchars($e['verdict'] ?: $e['intent']) . '</div></div>';
            $html .= '</div><div class="ai-buttons">';
            if ($levelUpAdvice) {
                $html .= chatButton($teamId, ['action' => 'escalation', 'evaluation_id' => $e['id'], 'choice' => 'agree_level_up'], 'Eens → level up', 'btn-success', 'Eens met de AI: dit team gaat een level omhoog?');
            }
            if ($reply !== '') {
                $html .= chatButton($teamId, ['action' => 'escalation', 'evaluation_id' => $e['id'], 'choice' => 'agree_reply'], 'Eens → reactie sturen', 'btn-primary', 'De voorgestelde reactie naar het team sturen?');
            }
            $html .= chatButton($teamId, ['action' => 'escalation', 'evaluation_id' => $e['id'], 'choice' => 'disagree'], 'Oneens → zelf antwoorden', 'btn-outline');
            $html .= chatButton($teamId, ['action' => 'escalation', 'evaluation_id' => $e['id'], 'choice' => 'handled'], 'Afgehandeld', 'btn-outline');
            $html .= '</div>';
        } else {
            $html .= '<div class="ai-buttons">';
            if ($reply !== '') {
                $html .= chatButton($teamId, ['action' => 'send_suggestion', 'evaluation_id' => $e['id']], 'Versturen', 'btn-primary', 'De voorgestelde reactie ongewijzigd naar het team sturen?');
                $html .= '<button class="btn btn-outline" style="padding: 2px 8px; font-size: 0.75em;" data-reply="' . htmlspecialchars($reply) . '" onclick="useSuggestion(this)">Overnemen</button>';
            }
            $html .= '<button class="btn btn-danger" style="padding: 2px 8px; font-size: 0.75em; background:#fff1f0;" onclick="rejectSuggestion(' . (int)$m['id'] . ')">Verwerpen</button>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    return $html;
}

// Tussenstand van het huidige level (alleen voor docenten, nooit op team.php)
function renderProgress(?array $progress, int $teamId): string {
    if (!$progress) {
        return '<p class="muted">Nog geen tussenstand: de AI heeft op dit level nog geen antwoord beoordeeld.</p>';
    }
    $html = $progress['summary'] !== '' ? '<p>' . nl2br(htmlspecialchars($progress['summary'])) . '</p>' : '';
    if ($progress['aspects']) {
        $html .= '<table class="progress-table"><tr><th>Aspect</th><th>Niveau</th><th>Citaat (bericht)</th></tr>';
        foreach ($progress['aspects'] as $a) {
            $html .= '<tr><td>' . htmlspecialchars((string)$a['naam']) . '</td><td><span class="verdict ' . htmlspecialchars((string)$a['niveau']) . '">' . htmlspecialchars((string)$a['niveau']) . '</span></td>'
                . '<td>“' . htmlspecialchars((string)$a['bewijs']) . '” (#' . (int)$a['bericht'] . ')</td></tr>';
        }
        $html .= '</table>';
    }
    $html .= '<div style="margin-top: 8px;">' . chatButton($teamId, ['action' => 'clear_progress'], '🧹 Wissen', 'btn-danger', 'De AI-tussenstand van dit level wissen? De AI begint dan opnieuw met de hele chat.') . '</div>';
    return $html;
}

function renderSidebar(array $teams, ?int $selectedId, array $escalations): string {
    $html = '';
    foreach ($teams as $t) {
        $html .= '<a href="?team_id=' . (int)$t['id'] . '" class="team-item ' . ($selectedId == $t['id'] ? 'active' : '') . '">';
        $html .= '<strong>' . htmlspecialchars($t['team_name']) . '</strong>';
        if ($t['unread_count'] > 0) {
            $html .= ' <span style="background:red; color:white; padding:1px 5px; border-radius:10px; font-size:0.7em;">' . (int)$t['unread_count'] . '</span>';
        }
        if (isset($escalations[(int)$t['id']])) {
            $html .= ' <span class="needs-teacher">🙋 Docent nodig</span>';
        }
        $html .= '<br><small>Level: ' . (int)$t['current_level'] . '</small>';
        $html .= '</a>';
    }
    return $html;
}

// AJAX endpoints voor live updates
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] === 'chat' && $selected_team) {
        // Markeer ook nieuwe berichten die via AJAX binnenkomen als gelezen
        $db->prepare("UPDATE team_messages SET is_read = 1 WHERE team_id = ? AND sender = 'team' AND assignment_number = ?")
           ->execute([$selected_team_id, $selected_team['current_level']]);

        if (!empty($other_teachers)) {
            echo '<div style="background: #fff9c4; color: #827717; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 0.85em; border: 1px solid #fbc02d;">';
            echo '⚠️ <strong>Let op:</strong> ' . htmlspecialchars(implode(', ', $other_teachers)) . ' bekijkt deze chat op dit moment ook.';
            echo '</div>';
        }
        // Haal de berichten OPNIEUW op binnen de AJAX call voor de meest verse data
        echo renderChat($db, chatMessages($db, $selected_team_id, (int)$selected_team['current_level']), $gameId, $selected_team_id);
    } elseif ($_GET['ajax'] === 'progress' && $selected_team) {
        echo renderProgress(getTeamProgress($db, $selected_team_id, (int)$selected_team['current_level']), $selected_team_id);
    } elseif ($_GET['ajax'] === 'sidebar') {
        echo renderSidebar($teams_with_msgs, $selected_team_id, $escalations);
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
    .needs-teacher { background: #fff3e0; color: #e65100; padding: 1px 6px; border-radius: 10px; font-size: 0.7em; font-weight: bold; white-space: nowrap; }
    .chat-window { background: white; border-radius: 10px; padding: 20px; flex: 1; display: flex; flex-direction: column; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .messages-box { flex: 1; height: 500px; overflow-y: auto; margin-bottom: 20px; padding: 10px; display: flex; flex-direction: column; min-height: 0; position: relative; z-index: 1; }
    /* Logo van het spel als vast watermerk achter de berichten */
    .chat-watermark { position: relative; flex: 1; display: flex; flex-direction: column; min-height: 0; }
    .chat-watermark::before { content: ""; position: absolute; inset: 10%; background: var(--logo) center / contain no-repeat; opacity: 0.08; pointer-events: none; }
    .btn[disabled] { opacity: 0.35; cursor: not-allowed; }
    .message { margin-bottom: 15px; padding: 12px; border-radius: 10px; max-width: 75%; font-size: 0.95em; line-height: 1.4; }
    .message.team { background: #e3f2fd; align-self: flex-start; }
    .message.teacher { background: #f1f8e9; align-self: flex-end; margin-left: auto; }
    .message.ai { background: #e0f2f1; border-left: 4px solid #00897b; align-self: flex-end; margin-left: auto; }
    .message.ai small { color: #00695c; font-weight: bold; }
    .message.suggestion { background: #f3e5f5; border: 1px dashed #9c27b0; align-self: flex-start; color: #4a148c; }
    .message.suggestion.escalation { background: #fff3e0; border: 2px solid #ff9800; color: #5d4037; }
    .ai-fields { display: grid; grid-template-columns: max-content 1fr; gap: 3px 10px; margin: 8px 0; font-size: 0.92em; }
    .ai-fields dt { font-weight: bold; }
    .ai-fields dd { margin: 0; }
    .ai-fields .proposal { background: rgba(255,255,255,0.7); padding: 6px 8px; border-radius: 6px; }
    .verdict { padding: 1px 7px; border-radius: 8px; font-size: 0.85em; font-weight: bold; color: white; background: #999; }
    .verdict.onvoldoende { background: #e53935; }
    .verdict.voldoende { background: #fb8c00; }
    .verdict.goed { background: #43a047; }
    .levelup-advice { color: #2e7d32; }
    .escalation-compare { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 8px 0; font-size: 0.9em; }
    .escalation-compare .quote { background: white; border-radius: 6px; padding: 6px 8px; margin-top: 3px; }
    .ai-buttons { display: flex; gap: 5px; margin-top: 8px; flex-wrap: wrap; }
    .ai-progress { background: white; border-radius: 10px; padding: 12px 20px; margin-bottom: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); font-size: 0.9em; }
    .ai-progress summary { cursor: pointer; font-weight: bold; color: #6a1b9a; }
    .progress-table { margin-top: 8px; font-size: 0.9em; }
    .progress-table th, .progress-table td { padding: 5px 8px; }
    .muted { color: #999; }

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

$chatHtml = $selected_team ? renderChat($db, $chat_messages, $gameId, $selected_team_id) : '';
include 'admin_header.php';
?>
    <?php if (defined('ENABLE_EMAIL') && ENABLE_EMAIL === false): ?>
        <div class="test-mode-banner"></div> <!-- CSS zorgt voor de correcte uitlijning van de banner uit de header -->
    <?php endif; ?>

    <div class="messages-layout-wrapper">
        <div class="team-list-panel" id="sidebar-list">
            <div class="team-list-header">Teams</div>
            <?= renderSidebar($teams_with_msgs, $selected_team_id, $escalations) ?>
        </div>

        <div class="chat-area">
            <?php if ($selected_team): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h1 style="margin-bottom: 0;">Chat met <?= htmlspecialchars($selected_team['team_name']) ?> (Level <?= levelLabel((int)$selected_team['current_level'], $total_assignments) ?>)
                        <span style="font-size: 0.45em; color: #888; font-weight: normal; vertical-align: middle;">🤖 <?= htmlspecialchars(aiModeLabel($selected_team['ai_mode'] ?: 'suggest')) ?></span>
                    </h1>
                    <div style="display: flex; gap: 10px;">
                        <?php if ($assignment): ?>
                            <button class="btn btn-outline" onclick="openAssignmentModal()">📄 Opdracht</button>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Team een level terugzetten? Het gesprek van het vorige level komt terug en de archiefregel van dat level verdwijnt.');" style="margin:0;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="level_down">
                            <input type="hidden" name="team_id" value="<?= $selected_team['id'] ?>">
                            <button type="submit" class="btn btn-outline" style="margin-top: 0; padding: 8px 16px;" title="Level terug" <?= (int)$selected_team['current_level'] <= 1 ? 'disabled' : '' ?>>⬇️</button>
                        </form>
                        <?php if ($assignment): ?>
                            <form method="POST" onsubmit="return confirm('Weet je zeker dat dit team een level omhoog mag?');" style="margin:0;">
                                <?= csrfField() ?>
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

                            <?php if (!empty($assignment['artifact_file'])): ?>
                                <div class="modal-section-title">📁 Bijlage</div>
                                <div style="background: #f0f4ff; padding: 15px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-size: 0.9em; color: #444;">Er is een bestand gekoppeld aan deze opdracht.</span>
                                    <a href="<?= htmlspecialchars($assignment['artifact_file']) ?>" target="_blank" class="btn btn-outline" style="padding: 6px 15px; font-size: 0.85em; background: white; text-decoration: none;">⬇️ Download Bestand</a>
                                </div>
                            <?php endif; ?>

                            <div style="margin-top: 30px; text-align: right;">
                                <button class="btn btn-primary" onclick="closeAssignmentModal()">Sluiten</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?= renderFlash() ?>
                <details class="ai-progress">
                    <summary>🧠 AI-tussenstand van dit level</summary>
                    <div id="ai-progress"><?= renderProgress(getTeamProgress($db, $selected_team_id, (int)$selected_team['current_level']), $selected_team_id) ?></div>
                </details>
                <div class="chat-window">
                    <div class="chat-watermark"<?= $logo ? ' style="--logo: url(\'' . htmlspecialchars($logo) . '\')"' : '' ?>>
                    <div class="messages-box" id="chat-box"><?= $chatHtml ?></div>
                    </div>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="team_id" value="<?= $selected_team_id ?>">
                        <textarea id="teacher-reply-box" name="message" rows="3" placeholder="Type je reactie..." required></textarea>
                        <button type="submit" class="btn btn-primary" style="margin-top: 10px; width: 100%;">Verstuur Antwoord</button>
                    </form>
                </div>
            <?php else: ?>
                <?= renderFlash() ?>
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
        let lastChatHtml = " . json_encode($chatHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";
        function updateChat() {
            " . ($selected_team_id ? "
            fetch('messages.php?team_id=$selected_team_id&ajax=chat')
                .then(r => r.text()).then(html => {
                    // Vergelijk met het vorige antwoord: innerHTML schrijft entiteiten soms anders terug
                    const cb = document.getElementById('chat-box');
                    if (html !== lastChatHtml) {
                        cb.innerHTML = html;
                        scrollToBottom();
                        lastChatHtml = html;
                    }
                });
            fetch('messages.php?team_id=$selected_team_id&ajax=progress')
                .then(r => r.text()).then(html => {
                    const p = document.getElementById('ai-progress');
                    if (p && p.innerHTML !== html) p.innerHTML = html;
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
        " . (isset($_GET['focus']) && $_GET['focus'] === 'reply' ? "document.getElementById('teacher-reply-box')?.focus();" : '') . "

        // De voorgestelde reactie komt uit de beoordeling (data-reply), niet uit de tekst van de suggestie
        function useSuggestion(btn) {
            const textarea = document.getElementById('teacher-reply-box');
            if (textarea) {
                textarea.value = btn.dataset.reply || '';
                textarea.focus();
            }
        }

        function rejectSuggestion(id) {
            if (!confirm('Weet je zeker dat je deze AI suggestie wilt verwijderen?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type=\"hidden\" name=\"csrf_token\" value=\"" . csrfToken() . "\">
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
