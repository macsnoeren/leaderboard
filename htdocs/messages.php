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
    $current_teacher = $_SESSION['teacher_username'] ?? 'Onbekende operator';

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
    $html = '<form method="POST" class="inline"' . ($confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '') . '>' . csrfField()
        . '<input type="hidden" name="team_id" value="' . $teamId . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '">';
    }
    return $html . '<button type="submit" class="btn btn-sm ' . $class . '">' . $label . '</button></form>';
}

function chatTime(?string $utc): string {
    $when = formatDateTime($utc);
    return '<time title="' . $when . '">' . substr($when, -5) . '</time>';
}

/**
 * De chat van een level als HTML. Suggesties met een beoordeling (ai_evaluation_id) krijgen een vaste
 * opmaak; bij een open escalatie staat het teamantwoord naast het voorlopige oordeel, met de knoppen.
 * Heeft de AI het team al geantwoord (reply/level_up), dan is het kader alleen ter informatie.
 * Zelfde bubbels als op team.php: het team links, docent en AI rechts.
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
        $label = ['team' => '<strong>Team</strong>', 'teacher' => '<strong>Jij</strong>', 'ai' => icon('bot') . '<strong>AI</strong>'][$m['sender']] ?? icon('bot') . '<strong>AI-suggestie</strong>';
        $e = $m['sender'] === 'suggestion' ? ($evaluations[(int)$m['ai_evaluation_id']] ?? null) : null;
        if (!$e) {
            $sender = in_array($m['sender'], ['team', 'teacher', 'ai', 'suggestion'], true) ? $m['sender'] : 'teacher';
            $html .= '<div class="bubble bubble-' . $sender . '">';
            $html .= '<div class="bubble-meta">' . $label . chatTime($m['created_at']) . '</div>';
            $html .= '<div>' . nl2br(htmlspecialchars($m['message'])) . '</div>';
            if ($m['sender'] === 'suggestion') {
                $html .= '<div class="bubble-actions"><button type="button" class="btn btn-sm btn-danger" onclick="rejectSuggestion(' . (int)$m['id'] . ')">' . icon('x') . 'Verwerpen</button></div>';
            }
            $html .= '</div>';
            continue;
        }

        $escalation = $e['decision'] === 'escalate';
        $open = $escalation && $e['resolved_at'] === null;
        $levelUpAdvice = in_array($e['verdict'], ['voldoende', 'goed'], true);
        // De reactie staat dan al als AI-bericht in de chat
        $sent = in_array($e['decision'], ['reply', 'level_up'], true);
        $reply = $sent ? '' : (string)$e['reply_text'];
        $title = $escalation ? icon('hand') . '<strong>Operator nodig' . ($open ? '' : ' (afgehandeld)') . '</strong>'
            : icon('bot') . '<strong>' . ($sent ? 'AI ter info (reactie al verstuurd)' : 'AI-suggestie') . '</strong>';
        $html .= '<div class="bubble bubble-suggestion' . ($escalation ? ' bubble-escalation' : '') . ($sent ? ' bubble-info' : '') . '">';
        $html .= '<div class="bubble-meta">' . $title . chatTime($m['created_at']) . '</div>';
        $html .= '<dl class="ai-fields">';
        $html .= '<dt>Intentie</dt><dd>' . htmlspecialchars($e['intent']) . '</dd>';
        if ($e['verdict']) {
            $html .= '<dt>Oordeel</dt><dd><span class="badge badge-' . htmlspecialchars($e['verdict']) . '">' . htmlspecialchars($e['verdict']) . '</span>'
                . ($levelUpAdvice ? ' <span class="badge badge-success">' . icon('arrow-up') . 'level-up-advies</span>' : '') . '</dd>';
        }
        if (trim((string)$e['reason']) !== '') {
            $html .= '<dt>Reden</dt><dd>' . nl2br(htmlspecialchars($e['reason'])) . '</dd>';
        }
        if ($reply !== '') {
            $html .= '<dt>Voorstel</dt><dd class="proposal">' . nl2br(htmlspecialchars($reply)) . '</dd>';
        }
        $html .= '</dl>';

        if ($open) {
            $answer = $teamTexts[(int)$e['message_id']] ?? null;
            $html .= '<div class="escalation-compare">';
            $html .= '<div><div class="section-label">Antwoord van het team</div><div class="quote">' . ($answer === null ? '<em>(bericht niet meer in deze chat)</em>' : nl2br(htmlspecialchars($answer))) . '</div></div>';
            $html .= '<div><div class="section-label">Voorlopig oordeel van de AI</div><div class="quote">' . htmlspecialchars($e['verdict'] ?: $e['intent']) . '</div></div>';
            $html .= '</div><div class="bubble-actions">';
            if ($levelUpAdvice) {
                $html .= chatButton($teamId, ['action' => 'escalation', 'evaluation_id' => $e['id'], 'choice' => 'agree_level_up'], icon('arrow-up') . 'Eens → level up', 'btn-success', 'Eens met de AI: dit team gaat een level omhoog?');
            }
            if ($reply !== '') {
                $html .= chatButton($teamId, ['action' => 'escalation', 'evaluation_id' => $e['id'], 'choice' => 'agree_reply'], icon('send') . 'Eens → reactie sturen', 'btn-primary', 'De voorgestelde reactie naar het team sturen?');
            }
            $html .= chatButton($teamId, ['action' => 'escalation', 'evaluation_id' => $e['id'], 'choice' => 'disagree'], 'Oneens → zelf antwoorden', 'btn-secondary');
            $html .= chatButton($teamId, ['action' => 'escalation', 'evaluation_id' => $e['id'], 'choice' => 'handled'], icon('check') . 'Afgehandeld', 'btn-secondary');
            $html .= '</div>';
        } elseif ($sent) {
            $html .= '<div class="bubble-actions">';
            if ($levelUpAdvice) {
                $html .= chatButton($teamId, ['action' => 'level_up'], icon('arrow-up') . 'Level up', 'btn-success', 'Dit team een level omhoog zetten?');
            }
            $html .= '<button type="button" class="btn btn-sm btn-secondary" onclick="rejectSuggestion(' . (int)$m['id'] . ')">Sluiten</button>';
            $html .= '</div>';
        } else {
            $html .= '<div class="bubble-actions">';
            if ($reply !== '') {
                $html .= chatButton($teamId, ['action' => 'send_suggestion', 'evaluation_id' => $e['id']], icon('send') . 'Versturen', 'btn-primary', 'De voorgestelde reactie ongewijzigd naar het team sturen?');
                $html .= '<button type="button" class="btn btn-sm btn-secondary" data-reply="' . htmlspecialchars($reply) . '" onclick="useSuggestion(this)">' . icon('pencil') . 'Overnemen</button>';
            }
            $html .= '<button type="button" class="btn btn-sm btn-danger" onclick="rejectSuggestion(' . (int)$m['id'] . ')">' . icon('x') . 'Verwerpen</button>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    return $html;
}

// Tussenstand van het huidige level (alleen voor docenten, nooit op team.php)
function renderProgress(?array $progress, int $teamId): string {
    if (!$progress) {
        return '<p class="muted text-sm">Nog geen tussenstand: de AI heeft op dit level nog geen antwoord beoordeeld.</p>';
    }
    $html = $progress['summary'] !== '' ? '<p class="text-sm">' . nl2br(htmlspecialchars($progress['summary'])) . '</p>' : '';
    if ($progress['aspects']) {
        $html .= '<div class="table-scroll"><table class="table table-compact progress-table"><thead><tr><th>Aspect</th><th>Niveau</th><th>Citaat</th></tr></thead><tbody>';
        foreach ($progress['aspects'] as $a) {
            $html .= '<tr><td>' . htmlspecialchars((string)$a['naam']) . '</td><td><span class="badge badge-' . htmlspecialchars((string)$a['niveau']) . '">' . htmlspecialchars((string)$a['niveau']) . '</span></td>'
                . '<td>“' . htmlspecialchars((string)$a['bewijs']) . '” <span class="muted">(#' . (int)$a['bericht'] . ')</span></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '<div class="card-actions">' . chatButton($teamId, ['action' => 'clear_progress'], icon('eraser') . 'Tussenstand wissen', 'btn-danger', 'De AI-tussenstand van dit level wissen? De AI begint dan opnieuw met de hele chat.') . '</div>';
    return $html;
}

function renderSidebar(array $teams, ?int $selectedId, array $escalations): string {
    if (!$teams) {
        return '<p class="muted text-sm team-list-empty">Nog geen berichten van teams.</p>';
    }
    $html = '';
    foreach ($teams as $t) {
        $name = (string)$t['team_name'];
        $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $active = $selectedId == $t['id'];
        $html .= '<a href="?team_id=' . (int)$t['id'] . '" class="team-item' . ($active ? ' active' : '') . '" data-name="' . htmlspecialchars($lower) . '"' . ($active ? ' aria-current="page"' : '') . '>';
        $html .= '<span class="team-item-name">' . htmlspecialchars($name) . '</span>';
        $html .= '<span class="team-item-badges">';
        if ($t['unread_count'] > 0) {
            $html .= '<span class="count" title="Ongelezen berichten">' . (int)$t['unread_count'] . '<span class="sr-only"> ongelezen</span></span>';
        }
        if (isset($escalations[(int)$t['id']])) {
            $html .= '<span class="count count-warning" title="Operator nodig">' . icon('hand') . '<span class="sr-only">Operator nodig</span></span>';
        }
        $html .= '</span>';
        $html .= '<span class="team-item-level">Level ' . (int)$t['current_level'] . '</span>';
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
            echo alertBox('warning', '<strong>Let op:</strong> ' . htmlspecialchars(implode(', ', $other_teachers)) . ' bekijkt deze chat op dit moment ook.');
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
$mainClass = 'is-app';
$extraCSS = '
<style>
    .msg-layout { flex: 1; min-height: 0; display: grid; grid-template-columns: 280px minmax(0, 1fr) 340px; }
    .msg-layout.no-team { grid-template-columns: 280px minmax(0, 1fr); }
    .msg-teams { grid-column: 1; grid-row: 1; }
    .msg-chat { grid-column: 2; grid-row: 1; }
    .msg-ai { grid-column: 3; grid-row: 1; }
    /* Teamlijst */
    .msg-teams { display: flex; flex-direction: column; min-height: 0; background: var(--surface); border-right: 1px solid var(--line); }
    .msg-teams-head { padding: 18px 16px 12px; border-bottom: 1px solid var(--line); }
    .msg-teams-head h1 { font-size: 18px; margin-bottom: 10px; }
    .search { position: relative; }
    .search .icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-muted); pointer-events: none; }
    .search input { padding-left: 34px; }
    .msg-team-list { flex: 1; overflow-y: auto; padding: 6px; }
    .team-item { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 2px 8px; padding: 10px 12px; border-radius: var(--radius-sm); color: var(--text); text-decoration: none; }
    .team-item:hover { background: var(--surface-2); color: var(--text); }
    .team-item.active { background: var(--brand-soft); box-shadow: inset 3px 0 0 var(--brand); }
    .team-item-name { font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .team-item-badges { display: inline-flex; gap: 4px; justify-self: end; }
    .team-item-badges .icon { width: 12px; height: 12px; }
    .team-item-level { grid-column: 1 / -1; font-family: var(--font-mono); font-size: 12px; color: var(--text-muted); }
    .team-list-empty { padding: 14px; }
    /* Chat */
    .msg-chat { display: flex; flex-direction: column; min-height: 0; min-width: 0; }
    .msg-chat-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 24px; border-bottom: 1px solid var(--line); background: var(--surface); }
    .msg-chat-head h1 { font-size: 20px; }
    .msg-chat-head .page-subtitle { margin-top: 2px; }
    .msg-chat > .alert { margin: 12px 24px 0; }
    .chat-watermark { position: relative; flex: 1; min-height: 0; display: flex; flex-direction: column; }
    .chat-watermark::before { content: ""; position: absolute; inset: 12%; background: var(--logo) center / contain no-repeat; opacity: .06; pointer-events: none; }
    @media (prefers-color-scheme: dark) { .chat-watermark::before { filter: grayscale(1) invert(1); } }
    .msg-scroll { position: relative; z-index: 1; flex: 1; min-height: 0; overflow-y: auto; padding: 20px 24px; }
    .msg-reply { padding: 14px 24px 18px; border-top: 1px solid var(--line); background: var(--surface); display: flex; flex-direction: column; gap: 8px; }
    .msg-reply textarea { min-height: 72px; }
    .msg-reply-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .ai-fields { display: grid; grid-template-columns: max-content 1fr; gap: 4px 12px; margin: 8px 0; font-size: 14px; }
    .ai-fields dt { font-weight: 600; color: var(--text-muted); }
    .ai-fields dd { margin: 0; min-width: 0; }
    .ai-fields .proposal { background: var(--surface); border: 1px solid var(--line); padding: 6px 10px; border-radius: var(--radius-sm); }
    .escalation-compare { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 10px 0 4px; font-size: 14px; }
    .escalation-compare .section-label { margin: 0 0 4px; }
    .escalation-compare .quote { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 6px 10px; }
    .chat > .alert { margin-bottom: 0; }
    /* AI-paneel */
    .msg-ai { background: var(--surface); border-left: 1px solid var(--line); overflow-y: auto; min-height: 0; }
    .msg-ai > summary { display: none; }
    .msg-ai-inner { padding: 20px; display: flex; flex-direction: column; gap: 22px; }
    .msg-ai h2 { font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
    .msg-ai h2 .icon { width: 15px; height: 15px; }
    .msg-ai .alert { margin-bottom: 0; }
    .progress-table td { font-size: 13px; }
    @media (max-width: 1400px) {
        .msg-layout { grid-template-columns: 260px minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr); }
        .msg-teams { grid-row: 1 / 3; }
        .msg-layout.no-team .msg-chat { grid-row: 1 / 3; }
        .msg-ai { grid-column: 2; grid-row: 1; border-left: none; border-bottom: 1px solid var(--line); max-height: 34vh; }
        .msg-ai > summary { display: flex; align-items: center; gap: 8px; padding: 10px 24px; cursor: pointer; font-weight: 600; font-size: 14px; }
        .msg-ai-inner { padding: 4px 24px 18px; }
        .msg-ai > summary .chev { margin-left: auto; transition: transform var(--t-fast); }
        .msg-ai[open] > summary .chev { transform: rotate(180deg); }
        .msg-chat { grid-column: 2; grid-row: 2; }
    }
    @media (max-width: 999px) {
        .msg-layout, .msg-layout.no-team { display: flex; flex-direction: column; }
        .msg-teams { border-right: none; border: 1px solid var(--line); border-radius: var(--radius); margin-bottom: 16px; }
        .msg-team-list { max-height: 220px; }
        .msg-ai { border: 1px solid var(--line); border-radius: var(--radius); margin-bottom: 16px; max-height: none; }
        .msg-chat { border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
        .msg-scroll { max-height: 65vh; min-height: 300px; }
        .escalation-compare { grid-template-columns: 1fr; }
    }
</style>';

$chatHtml = $selected_team ? renderChat($db, $chat_messages, $gameId, $selected_team_id) : '';
$selectedLevel = $selected_team ? (int)$selected_team['current_level'] : 0;
$hasOpenEscalation = $selected_team && isset($escalations[$selected_team_id]);
include 'admin_header.php';
?>
    <div class="msg-layout<?= $selected_team ? '' : ' no-team' ?>">
        <aside class="msg-teams" aria-label="Teams">
            <div class="msg-teams-head">
                <h1>Berichten</h1>
                <div class="search">
                    <?= icon('search') ?>
                    <input type="search" id="team-search" placeholder="Zoek een team…" aria-label="Zoek een team" autocomplete="off">
                </div>
            </div>
            <nav class="msg-team-list" id="sidebar-list"><?= renderSidebar($teams_with_msgs, $selected_team_id, $escalations) ?></nav>
        </aside>

        <?php if ($selected_team): ?>
        <details class="msg-ai" open>
            <summary><?= icon('bot') ?>AI-paneel<?= $hasOpenEscalation ? ' <span class="badge badge-warning">' . icon('hand') . 'Operator nodig</span>' : '' ?><?= icon('chevron-down', 'chev') ?></summary>
            <div class="msg-ai-inner">
                <section>
                    <h2><?= icon('bot') ?>AI-modus</h2>
                    <p><?= aiModeBadge($selected_team['ai_mode'] ?: 'suggest') ?></p>
                    <p class="small muted" style="margin-top: 6px;">Wijzigen doe je op het <a href="teacher.php">dashboard</a>.</p>
                </section>
                <?php if ($hasOpenEscalation): ?>
                    <?= alertBox('warning', '<strong>Operator nodig.</strong> De AI wacht op jou: gebruik de knoppen bij de melding in de chat, of antwoord het team zelf.') ?>
                <?php endif; ?>
                <section>
                    <h2><?= icon('trending-up') ?>Tussenstand van dit level</h2>
                    <div id="ai-progress"><?= renderProgress(getTeamProgress($db, $selected_team_id, $selectedLevel), $selected_team_id) ?></div>
                </section>
                <?php if ($assignment): ?>
                    <section>
                        <h2><?= icon('book') ?>Opdracht <?= (int)$assignment['assignment_number'] ?></h2>
                        <p class="text-sm"><strong><?= htmlspecialchars($assignment['title']) ?></strong></p>
                        <p style="margin-top: 6px;"><?= rubricStatusBadge($assignment['ai_rubric_status'] ?: 'none') ?></p>
                        <div class="card-actions"><button type="button" class="btn btn-secondary btn-sm" data-open="assignment-dialog"><?= icon('eye') ?>Opdracht bekijken</button></div>
                    </section>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>

        <section class="msg-chat">
            <?php if ($selected_team): ?>
                <header class="msg-chat-head">
                    <div>
                        <h1><?= htmlspecialchars($selected_team['team_name']) ?></h1>
                        <div class="page-subtitle">
                            <span class="mono">Level <?= htmlspecialchars(levelLabel($selectedLevel, $total_assignments)) ?><?= $selectedLevel >= 1 && $selectedLevel <= $total_assignments ? '/' . $total_assignments : '' ?></span>
                            <?= aiModeBadge($selected_team['ai_mode'] ?: 'suggest') ?>
                        </div>
                    </div>
                    <div class="btn-row">
                        <?php if ($assignment): ?>
                            <button type="button" class="btn btn-secondary" data-open="assignment-dialog"><?= icon('book') ?>Opdracht</button>
                        <?php endif; ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Team een level terugzetten? Het gesprek van het vorige level komt terug en de archiefregel van dat level verdwijnt.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="level_down">
                            <input type="hidden" name="team_id" value="<?= $selected_team_id ?>">
                            <button type="submit" class="btn btn-secondary btn-icon" title="Level terug" aria-label="Level terug" <?= $selectedLevel <= 1 ? 'disabled' : '' ?>><?= icon('arrow-down') ?></button>
                        </form>
                        <?php if ($assignment): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Weet je zeker dat dit team een level omhoog mag?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="level_up">
                                <input type="hidden" name="team_id" value="<?= $selected_team_id ?>">
                                <button type="submit" class="btn btn-success"><?= icon('arrow-up') ?>Level up</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </header>
                <?= renderFlash() ?>
                <div class="chat-watermark"<?= $logo ? ' style="--logo: url(\'' . htmlspecialchars($logo) . '\')"' : '' ?>>
                    <div class="msg-scroll" id="chat-scroll">
                        <div class="chat" id="chat-box"><?= $chatHtml ?></div>
                    </div>
                </div>
                <form method="POST" class="msg-reply" id="reply-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="team_id" value="<?= $selected_team_id ?>">
                    <label for="teacher-reply-box" class="sr-only">Je reactie</label>
                    <textarea id="teacher-reply-box" name="message" rows="3" placeholder="Typ je reactie…" required></textarea>
                    <div class="msg-reply-foot">
                        <span class="small muted"><span class="kbd">Ctrl</span> + <span class="kbd">Enter</span> om te versturen</span>
                        <button type="submit" class="btn btn-primary" id="reply-send"><?= icon('send') ?>Versturen</button>
                    </div>
                </form>
            <?php else: ?>
                <?= renderFlash() ?>
                <?= emptyState('message', 'Kies links een team om de chat te openen.') ?>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($assignment): ?>
        <dialog class="modal" id="assignment-dialog" aria-labelledby="assignment-dialog-title">
            <div class="modal-header">
                <h2 id="assignment-dialog-title">Opdracht <?= (int)$assignment['assignment_number'] ?>: <?= htmlspecialchars($assignment['title']) ?></h2>
                <button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Sluiten"><?= icon('x') ?></button>
            </div>
            <div class="modal-body">
                <div class="section-label">Beschrijving</div>
                <div class="markdown" data-md><?= htmlspecialchars((string)$assignment['description']) ?></div>
                <?php if (!empty($assignment['instruction'])): ?>
                    <div class="section-label">Instructie</div>
                    <div class="panel markdown" data-md><?= htmlspecialchars($assignment['instruction']) ?></div>
                <?php endif; ?>
                <?php if (!empty($assignment['criteria'])): ?>
                    <div class="section-label">Beoordelingscriteria (alleen voor operators)</div>
                    <div class="panel panel-danger markdown" data-md><?= htmlspecialchars($assignment['criteria']) ?></div>
                <?php endif; ?>
                <?php if (!empty($assignment['artifact_file'])): ?>
                    <div class="section-label">Bijlage</div>
                    <div class="panel btn-row" style="justify-content: space-between;">
                        <span class="text-sm"><?= icon('file') ?> <?= htmlspecialchars(artifactDisplayName($assignment)) ?></span>
                        <a href="<?= htmlspecialchars($assignment['artifact_file']) ?>" download="<?= htmlspecialchars(artifactDisplayName($assignment)) ?>" class="btn btn-secondary btn-sm"><?= icon('download') ?>Bestand downloaden</a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-primary" data-close>Sluiten</button></div>
        </dialog>
    <?php endif; ?>

<?php
$extraJS = "
    <script>
        function scrollToBottom() {
            const scroller = document.getElementById('chat-scroll');
            if (scroller) setTimeout(() => { scroller.scrollTop = scroller.scrollHeight; }, 50);
        }
        // Teamlijst filteren op naam (ook na het verversen van de lijst)
        function filterTeams() {
            const q = (document.getElementById('team-search').value || '').trim().toLowerCase();
            document.querySelectorAll('#sidebar-list .team-item').forEach(a => { a.hidden = q !== '' && !a.dataset.name.includes(q); });
        }
        document.getElementById('team-search').addEventListener('input', filterTeams);

        let lastChatHtml = " . json_encode($chatHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";
        function updateChat() {
            " . ($selected_team_id ? "
            fetch('messages.php?team_id=$selected_team_id&ajax=chat')
                .then(r => r.ok ? r.text() : null).then(html => {
                    // Vergelijk met het vorige antwoord: innerHTML schrijft entiteiten soms anders terug
                    if (html === null) return;
                    if (html !== lastChatHtml) {
                        document.getElementById('chat-box').innerHTML = html;
                        scrollToBottom();
                        lastChatHtml = html;
                    }
                });
            fetch('messages.php?team_id=$selected_team_id&ajax=progress')
                .then(r => r.ok ? r.text() : null).then(html => {
                    const p = document.getElementById('ai-progress');
                    if (p && html !== null && p.innerHTML !== html) p.innerHTML = html;
                });
            " : "") . "

            const teamId = " . ($selected_team_id ?: '0') . ";
            fetch('messages.php?ajax=sidebar' + (teamId ? '&team_id=' + teamId : ''))
                .then(r => r.ok ? r.text() : null).then(html => {
                    const sl = document.getElementById('sidebar-list');
                    if (sl && html !== null) { sl.innerHTML = html; filterTeams(); }
                });
        }
        setInterval(updateChat, 5000);
        // Op een smaller scherm begint het AI-paneel ingeklapt, zodat de chat de ruimte heeft
        const aiPanel = document.querySelector('.msg-ai');
        if (aiPanel && window.matchMedia('(max-width: 1400px)').matches) aiPanel.open = false;
        scrollToBottom();
        " . (isset($_GET['focus']) && $_GET['focus'] === 'reply' ? "document.getElementById('teacher-reply-box')?.focus();" : '') . "

        // Versturen met Ctrl/⌘+Enter; de knop uit tijdens het versturen
        const replyForm = document.getElementById('reply-form');
        if (replyForm) {
            document.getElementById('teacher-reply-box').addEventListener('keydown', e => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); replyForm.requestSubmit(); }
            });
            replyForm.addEventListener('submit', () => { document.getElementById('reply-send').disabled = true; });
        }

        // De voorgestelde reactie komt uit de beoordeling (data-reply), niet uit de tekst van de suggestie
        function useSuggestion(btn) {
            const textarea = document.getElementById('teacher-reply-box');
            if (textarea) {
                textarea.value = btn.dataset.reply || '';
                textarea.focus();
            }
        }

        function rejectSuggestion(id) {
            if (!confirm('Weet je zeker dat je deze AI-suggestie wilt verwijderen?')) return;
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
    </script>";
include 'admin_footer.php'; ?>
