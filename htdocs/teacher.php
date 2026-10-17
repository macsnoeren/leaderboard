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
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);
$userId = (int)$user['id'];

// AJAX endpoint voor AI Agent status heartbeat check (geldt voor alle spellen): active (n), model_down (n) of inactive
if (isset($_GET['ajax_ai_status'])) {
    $agentStatus = aiAgentStatus($db);
    echo $agentStatus['status'] === 'inactive' ? 'inactive' : "{$agentStatus['status']} ({$agentStatus['count']})";
    exit;
}

$game = requireGame($db, $user);
$gameId = (int)$game['id'];

// AJAX endpoint voor live unread count updates (alleen het huidige level van elk team)
if (isset($_GET['ajax_unread'])) {
    echo countUnread($db, $gameId);
    exit;
}

// AJAX endpoint voor het aantal teams met een open AI-escalatie ("🙋 Docent nodig")
if (isset($_GET['ajax_escalations'])) {
    echo countOpenEscalations($db, $gameId);
    exit;
}

// Handle level changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('teacher.php');
    }

    if ($action === 'add_team') {
        $emailInput = trim($_POST['email'] ?? '');
        $email = normalizeEmail($emailInput);
        if ($emailInput !== '' && $email === null) {
            flash('error', 'Vul een geldig e-mailadres in, of laat het veld leeg.');
            redirect('teacher.php');
        }
        $team = createTeam($db, $game, $_POST['team_name'] ?? '', $email, $userId);
        if (is_string($team)) {
            flash('error', $team);
            redirect('teacher.php');
        }
        if ($email !== null && !empty($_POST['send_link'])) {
            $result = sendTeamLinkEmail($email, $team['team_name'], $game['name'], teamDashboardUrl($team['access_token']));
            if ($result !== true) {
                flash('error', "Team '{$team['team_name']}' is toegevoegd, maar de mail kon niet worden verstuurd ($result). Kopieer de teamlink en geef hem zelf door.");
                redirect('teacher.php');
            }
            flash('success', "Team '{$team['team_name']}' is toegevoegd" . (isEmailEnabled() ? " en de teamlink is gemaild naar $email." : '. Testmodus: er is geen mail verstuurd.'));
        } else {
            flash('success', "Team '{$team['team_name']}' is toegevoegd.");
        }
        redirect('teacher.php');
    }

    if ($action === 'set_all_ai_mode') {
        $mode = (string)($_POST['ai_mode'] ?? '');
        $count = setAllTeamsAiMode($db, $gameId, $mode, $userId);
        if ($count === null) {
            flash('error', 'Kies een geldige AI-modus.');
        } else {
            flash('success', "AI-modus '" . aiModeLabel($mode) . "' ingesteld voor alle $count teams; nieuwe teams krijgen deze modus ook.");
        }
        redirect('teacher.php');
    }

    // Alle andere acties werken op een team van het actieve spel
    $team = findTeamInGame($db, (int)($_POST['team_id'] ?? 0), $gameId);
    if (!$team) {
        flash('error', 'Dit team hoort niet bij het actieve spel.');
        redirect('teacher.php');
    }

    if ($action === 'level_up') {
        if (levelUp($db, (int)$team['id'], $userId)) {
            flash('success', "Team '{$team['team_name']}' is een level omhoog gegaan.");
        } else {
            flash('error', "Team '{$team['team_name']}' kan niet verder omhoog.");
        }
    } elseif ($action === 'level_down') {
        if (levelDown($db, (int)$team['id'], $userId)) {
            flash('success', "Team '{$team['team_name']}' is een level teruggezet.");
        } else {
            flash('error', "Team '{$team['team_name']}' staat al op het laagste level.");
        }
    } elseif ($action === 'send_link') {
        // Teamlink opnieuw mailen, voor een team dat niet meer bij het dashboard kan
        if (!$team['email']) {
            flash('error', "Team '{$team['team_name']}' heeft geen e-mailadres.");
        } else {
            $result = sendTeamLinkEmail($team['email'], $team['team_name'], $game['name'], teamDashboardUrl($team['access_token']));
            logAudit($db, $userId, 'TEAM_SEND_LINK', "Teamlink gemaild naar team '{$team['team_name']}' (ID {$team['id']})" . ($result === true ? '' : ' - mail niet verstuurd'));
            if ($result !== true) {
                flash('error', "De mail kon niet worden verstuurd ($result). Kopieer de teamlink en geef hem zelf door.");
            } else {
                flash('success', isEmailEnabled() ? "Teamlink gemaild naar {$team['email']}." : 'Testmodus: er is geen mail verstuurd.');
            }
        }
    } elseif ($action === 'set_ai_mode') {
        $mode = (string)($_POST['ai_mode'] ?? '');
        if (setTeamAiMode($db, (int)$team['id'], $gameId, $mode, $userId)) {
            flash('success', "AI-modus van team '{$team['team_name']}' is nu '" . aiModeLabel($mode) . "'.");
        } else {
            flash('error', 'Kies een geldige AI-modus.');
        }
    } elseif ($action === 'delete_team') {
        deleteTeam($db, (int)$team['id']);
        logAudit($db, $userId, 'TEAM_DELETE', "Team '{$team['team_name']}' (ID {$team['id']}) verwijderd uit spel #$gameId");
        flash('success', "Team '{$team['team_name']}' is verwijderd.");
    }

    redirect('teacher.php');
}

$teams = rankedTeams($db, $gameId);
$total_assignments = totalAssignments($db, $gameId);
$unread_total = countUnread($db, $gameId);
$canAddTeam = initialLevel($game) !== null;
$escalations = openEscalationsByTeam($db, $gameId);

// Keuzelijst voor de AI-modus; $submitOnChange voor de keuze per team
function aiModeSelect(string $current, bool $submitOnChange): string {
    $html = '<select name="ai_mode" class="ai-mode-select"' . ($submitOnChange ? ' onchange="this.form.submit()"' : '') . ' aria-label="AI-modus">';
    foreach (AI_MODES as $mode) {
        $html .= '<option value="' . $mode . '"' . ($mode === $current ? ' selected' : '') . ' title="' . htmlspecialchars(AI_MODE_DESCRIPTIONS[$mode]) . '">🤖 ' . htmlspecialchars(aiModeLabel($mode)) . '</option>';
    }
    return $html . '</select>';
}

// Knop in een eigen POST-formulier met CSRF-token
function teamButton(string $action, int $teamId, string $label, string $class, string $title, bool $disabled = false, ?string $confirm = null): string {
    $onsubmit = $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '';
    return '<form method="POST" style="display: inline;"' . $onsubmit . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . $action . '">'
        . '<input type="hidden" name="team_id" value="' . $teamId . '">'
        . '<button type="submit" class="btn ' . $class . '" style="padding: 6px 12px; border-radius: 6px;" title="' . htmlspecialchars($title) . '"' . ($disabled ? ' disabled' : '') . '>' . $label . '</button>'
        . '</form>';
}

$pageTitle = 'Dashboard';
$extraCSS = '<style>
    .disabled-btn { opacity: 0.4; pointer-events: none; }
    .btn[disabled] { opacity: 0.35; cursor: not-allowed; }
    .stats-overview { grid-template-columns: repeat(4, 1fr); }
    .ai-mode-select { padding: 4px 6px; font-size: 0.8em; width: auto; border-radius: 6px; }
    .needs-teacher { background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 10px; font-size: 0.75em; font-weight: bold; text-decoration: none; white-space: nowrap; }
</style>';
include 'admin_header.php';
?>
        <header class="content-header">
            <h1>Dashboard <span style="font-size: 0.6em; color: #888; font-weight: normal;"><?= htmlspecialchars($game['name']) ?></span> <?= gameStatusBadge($game['effective_status']) ?></h1>
            <a target="_leaderboard" href="index.php?game=<?= urlencode((string)$game['join_code']) ?>" class="btn btn-outline">View Public Leaderboard</a>
        </header>
        
        <?= renderFlash() ?>

        <?php if ($game['effective_status'] === 'draft'): ?>
            <div class="card" style="background: #fff8e1; border-left: 5px solid #ffb300; padding: 15px 20px;">
                Dit spel is nog niet gestart: teams zien een wachtscherm. Start het spel via <a href="game.php" style="color: #667eea;">⚙️ Spel</a>.
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
            <div class="stat-card" title="Teams waarbij de AI een docent nodig heeft">
                <div class="stat-label">🙋 Docent nodig</div>
                <div class="stat-value" id="escalation-stat" style="color: #e65100;"><?= count($escalations) ?></div>
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
                                <th>AI</th>
                                <th style="text-align: right;">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $index => $team):
                                $level = (int)$team['current_level'];
                                $finished = $level > $total_assignments;
                                $progress = progressPercent($level, $total_assignments);
                            ?>
                                <tr>
                                    <td style="font-weight: bold; color: #667eea; vertical-align: middle;">
                                        <?= $index + 1 ?>
                                    </td>
                                    <td>
                                        <div class="team-main-info">
                                            <a href="team.php?token=<?= htmlspecialchars($team['access_token']) ?>" target="_blank" class="team-name-link">
                                                <?= htmlspecialchars($team['team_name']) ?>
                                            </a>
                                            <div style="display: flex; gap: 10px; align-items: center; margin-top: 4px;">
                                                <?php if ($team['email']): ?>
                                                    <span class="team-email" style="font-size: 0.85em; color: #666;">📧 <?= htmlspecialchars($team['email']) ?></span>
                                                <?php endif; ?>
                                                <span style="font-size: 0.75em; color: #aaa;">🕒 <?= htmlspecialchars(substr(formatDateTime($team['level_updated_at']), -5)) ?></span>
                                                <?php if (isset($escalations[(int)$team['id']])): ?>
                                                    <a href="messages.php?team_id=<?= (int)$team['id'] ?>" class="needs-teacher">🙋 Docent nodig</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="text-align: center; vertical-align: middle;">
                                        <span style="background: #f0f4ff; color: #667eea; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 1.1em;">
                                            <?= levelLabel($level, $total_assignments) ?>
                                        </span>
                                    </td>
                                    <td style="vertical-align: middle;">
                                        <div class="progress-wrapper">
                                            <div class="progress-bg" style="height: 6px; background: #eee;">
                                                <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                                            </div>
                                            <div class="progress-text" style="font-size: 0.75em; font-weight: 600; color: #888;"><?= $finished ? 'Voltooid!' : round($progress) . '% voltooid' ?></div>
                                        </div>
                                    </td>
                                    <td style="vertical-align: middle;">
                                        <form method="POST" style="margin: 0;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="set_ai_mode">
                                            <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                                            <?= aiModeSelect($team['ai_mode'] ?: 'suggest', true) ?>
                                            <noscript><button type="submit" class="btn btn-outline" style="padding: 4px 8px;">OK</button></noscript>
                                        </form>
                                    </td>
                                    <td style="text-align: right; vertical-align: middle;">
                                        <div class="action-btns" style="justify-content: flex-end; display: flex; gap: 4px; flex-wrap: wrap;">
                                            <?= teamButton('level_up', (int)$team['id'], '⬆️', 'btn-success', 'Level omhoog', $level < 1 || $finished) ?>
                                            <?= teamButton('level_down', (int)$team['id'], '⬇️', 'btn-outline', 'Level terug', $level <= 1, "Team '{$team['team_name']}' een level terugzetten? Het gesprek van het vorige level komt terug en de archiefregel van dat level verdwijnt.") ?>
                                            <button type="button" class="btn btn-outline" style="padding: 6px 12px; border-radius: 6px;" title="Kopieer teamlink"
                                                onclick="copyTeamLink(this, <?= htmlspecialchars(json_encode(teamDashboardUrl($team['access_token']))) ?>)">📋</button>
                                            <?php if ($team['email']): ?>
                                                <?= teamButton('send_link', (int)$team['id'], '📧', 'btn-outline', 'Teamlink opnieuw mailen', false, "Teamlink mailen naar {$team['email']}?") ?>
                                            <?php endif; ?>
                                            <?= teamButton('delete_team', (int)$team['id'], '🗑️', 'btn-danger', 'Team verwijderen', false, "Team '{$team['team_name']}' verwijderen? De chat gaat verloren; het archief blijft.") ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($teams)): ?>
                                <tr><td colspan="6" style="text-align: center; color: #999; padding: 40px;">Nog geen teams in dit spel.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="side-panel">
                <div class="card" style="border-top: 5px solid #667eea; background: #fff;">
                    <div class="card-title" style="color: #667eea; font-size: 1.4em;">➕ Nieuw Team</div>
                    <?php if (!$canAddTeam): ?>
                        <p style="color: #888;">Dit spel is afgelopen; er kunnen geen teams meer bij.</p>
                    <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="add_team">
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label style="color: #555; font-size: 0.9em;">Team Naam</label>
                            <input type="text" name="team_name" placeholder="Bijv. De Speurders" maxlength="<?= TEAM_NAME_MAX_LENGTH ?>" style="border: 1px solid #ddd; padding: 12px;" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="color: #555; font-size: 0.9em;">E-mail adres (optioneel)</label>
                            <input type="email" name="email" id="team-email" placeholder="begeleider@school.nl" style="border: 1px solid #ddd; padding: 12px;" oninput="toggleSendLink()">
                        </div>
                        <label id="send-link-row" style="display: none; align-items: center; gap: 8px; margin-bottom: 20px; font-size: 0.9em; color: #555;">
                            <input type="checkbox" name="send_link" value="1" checked style="width: auto;"> Stuur de dashboardlink per mail
                        </label>
                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 1em; text-transform: uppercase; letter-spacing: 1px;">🚀 Team Toevoegen</button>
                        <p style="margin-top: 10px; font-size: 0.8em; color: #999;">
                            <?= $game['effective_status'] === 'draft' ? 'Het team wacht tot het spel start.' : 'Het team begint direct op level 1.' ?>
                        </p>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="card" style="border-top: 5px solid #9c27b0;">
                    <div class="card-title" style="color: #6a1b9a; font-size: 1.2em;">🤖 AI voor alle teams</div>
                    <form method="POST" onsubmit="return confirm('Alle teams van dit spel op deze AI-modus zetten? Nieuwe teams krijgen hem ook.');" style="display: flex; gap: 8px; margin-top: 10px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_all_ai_mode">
                        <?= aiModeSelect($game['ai_mode'] ?: 'suggest', false) ?>
                        <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Voor alle teams</button>
                    </form>
                    <ul style="margin: 12px 0 0 18px; font-size: 0.8em; color: #777; line-height: 1.5;">
                        <?php foreach (AI_MODES as $mode): ?>
                            <li><strong><?= htmlspecialchars(aiModeLabel($mode)) ?></strong>: <?= htmlspecialchars(AI_MODE_DESCRIPTIONS[$mode]) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin-top: 8px; font-size: 0.8em; color: #999;">Antwoorden en Automatisch werken alleen bij opdrachten met een goedgekeurde rubric (📘 Assignments); anders blijft het bij suggesties.</p>
                </div>
            </div>
        </div>
<?php
$extraJS = "
<script>
    function toggleSendLink() {
        document.getElementById('send-link-row').style.display = document.getElementById('team-email').value.trim() !== '' ? 'flex' : 'none';
    }
    function copyTeamLink(btn, link) {
        // Het klembord werkt alleen via https of localhost
        if (!navigator.clipboard) { prompt('Kopieer de teamlink:', link); return; }
        navigator.clipboard.writeText(link).then(() => {
            btn.innerText = '✔';
            setTimeout(() => { btn.innerText = '📋'; }, 1500);
        }, () => { prompt('Kopieer de teamlink:', link); });
    }
</script>";
include 'admin_footer.php'; ?>