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
        $html .= '<option value="' . $mode . '"' . ($mode === $current ? ' selected' : '') . ' title="' . htmlspecialchars(AI_MODE_DESCRIPTIONS[$mode]) . '">' . htmlspecialchars(aiModeLabel($mode)) . '</option>';
    }
    return $html . '</select>';
}

// Iconknop in een eigen POST-formulier met CSRF-token
function teamButton(string $action, int $teamId, string $iconName, string $class, string $title, bool $disabled = false, ?string $confirm = null): string {
    $onsubmit = $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '';
    return '<form method="POST" class="inline"' . $onsubmit . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . $action . '">'
        . '<input type="hidden" name="team_id" value="' . $teamId . '">'
        . '<button type="submit" class="btn btn-sm btn-icon ' . $class . '" title="' . htmlspecialchars($title) . '" aria-label="' . htmlspecialchars($title) . '"' . ($disabled ? ' disabled' : '') . '>' . icon($iconName) . '</button>'
        . '</form>';
}

// Regel in het ⋯-menu als POST-formulier
function teamMenuItem(string $action, int $teamId, string $iconName, string $label, ?string $confirm = null, bool $danger = false): string {
    $onsubmit = $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '';
    return '<form method="POST"' . $onsubmit . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . $action . '">'
        . '<input type="hidden" name="team_id" value="' . $teamId . '">'
        . '<button type="submit" class="menu-item' . ($danger ? ' is-danger' : '') . '" role="menuitem">' . icon($iconName) . htmlspecialchars($label) . '</button>'
        . '</form>';
}

$escalationCount = count($escalations);
$pageTitle = 'Dashboard';
$extraCSS = '<style>
    .team-cell { display: flex; flex-direction: column; gap: 3px; min-width: 180px; }
    .team-name-link { font-weight: 650; color: var(--text); text-decoration: none; }
    .team-name-link:hover { color: var(--brand); text-decoration: underline; }
    .team-sub { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 12px; color: var(--text-muted); }
    .team-sub span { display: inline-flex; align-items: center; gap: 4px; }
    .team-sub .icon { width: 13px; height: 13px; }
    .needs-teacher { text-decoration: none; }
    .dash { grid-template-columns: minmax(0, 1fr) 320px; }
    @media (max-width: 1280px) { .dash { grid-template-columns: minmax(0, 1fr); } .dash-aside { display: grid; grid-template-columns: 1fr 1fr; gap: var(--s-5); } }
    @media (max-width: 800px) { .dash-aside { grid-template-columns: 1fr; } }
    .progress-cell { min-width: 130px; }
    .progress-cell .progress { margin: 4px 0; }
    .level { font-family: var(--font-mono); font-weight: 700; font-variant-numeric: tabular-nums; font-size: 13px; }
    .ai-mode-select { width: auto; padding: 5px 8px; font-size: 13px; }
    .row-actions { display: inline-flex; gap: 4px; align-items: center; }
    .mode-list { list-style: none; display: grid; gap: 6px; margin-top: 14px; font-size: 13px; color: var(--text-muted); }
    .mode-list strong { color: var(--text); }
    .inline-form { display: flex; gap: 8px; }
    .inline-form select { flex: 1; }
</style>';
include 'admin_header.php';
?>
        <?= pageHeader('Dashboard', gameSubtitle($game),
            '<a target="_leaderboard" href="index.php?game=' . urlencode((string)$game['join_code']) . '" class="btn btn-secondary">' . icon('trophy') . 'Publiek leaderboard' . icon('external') . '</a>'
            . '<a target="_beamer" href="beamer.php" class="btn btn-secondary">' . icon('monitor') . 'Beamer' . icon('external') . '</a>') ?>

        <?= renderFlash() ?>

        <?php if ($game['effective_status'] === 'draft'): ?>
            <?= alertBox('info', 'Dit spel is nog niet gestart: teams zien een wachtscherm. Start het spel via <a href="game.php">Spelinstellingen</a>.') ?>
        <?php endif; ?>

        <div class="stats">
            <div class="stat">
                <div class="stat-label"><?= icon('users') ?>Teams</div>
                <div class="stat-value"><?= count($teams) ?></div>
            </div>
            <div class="stat">
                <div class="stat-label"><?= icon('book') ?>Opdrachten</div>
                <div class="stat-value"><?= $total_assignments ?></div>
            </div>
            <div class="stat">
                <div class="stat-label"><?= icon('message') ?>Ongelezen</div>
                <div class="stat-value" id="unread-stat"><?= $unread_total ?></div>
                <div class="stat-hint"><a href="messages.php">Naar berichten</a></div>
            </div>
            <div class="stat<?= $escalationCount > 0 ? ' is-warning' : '' ?>" title="Teams waarbij de AI een docent nodig heeft">
                <div class="stat-label"><?= icon('hand') ?>Docent nodig</div>
                <div class="stat-value" id="escalation-stat"><?= $escalationCount ?></div>
                <div class="stat-hint">Teams die op een docent wachten</div>
            </div>
        </div>

        <div class="grid-main dash">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><?= icon('users') ?>Teams</div>
                    <span class="muted text-sm">Gerangschikt op level, daarna op tijd</span>
                </div>
                <?php if (empty($teams)): ?>
                    <?= emptyState('users', 'Nog geen teams in dit spel.', $canAddTeam ? '<p class="muted text-sm">Voeg rechts een team toe, of laat teams zich aanmelden via de spelcode (Spelinstellingen).</p>' : '') ?>
                <?php else: ?>
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Team</th>
                                <th scope="col">Level</th>
                                <th scope="col">AI</th>
                                <th scope="col" class="col-actions">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $index => $team):
                                $teamId = (int)$team['id'];
                                $level = (int)$team['current_level'];
                                $finished = $level > $total_assignments;
                                $progress = progressPercent($level, $total_assignments);
                            ?>
                                <tr>
                                    <td><?= rankBadge($index + 1) ?></td>
                                    <td>
                                        <div class="team-cell">
                                            <a href="team.php?token=<?= htmlspecialchars(urlencode($team['access_token'])) ?>" target="_blank" class="team-name-link" title="Teamdashboard openen"><?= htmlspecialchars($team['team_name']) ?></a>
                                            <div class="team-sub">
                                                <?php if ($team['email']): ?><span><?= icon('mail') ?><?= htmlspecialchars($team['email']) ?></span><?php endif; ?>
                                                <span title="Laatste levelwissel"><?= icon('clock') ?><?= htmlspecialchars(substr(formatDateTime($team['level_updated_at']), -5)) ?></span>
                                                <?php if (isset($escalations[$teamId])): ?>
                                                    <a href="messages.php?team_id=<?= $teamId ?>" class="badge badge-warning needs-teacher"><?= icon('hand') ?>Docent nodig</a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="progress-cell">
                                        <span class="level"><?= $level >= 1 && !$finished ? 'Level ' . $level . '/' . $total_assignments : htmlspecialchars(levelLabel($level, $total_assignments)) ?></span>
                                        <?= progressBar($level, $total_assignments) ?>
                                        <span class="small muted"><?= $finished ? 'Voltooid' : round($progress) . '% voltooid' ?></span>
                                    </td>
                                    <td>
                                        <form method="POST" class="inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="set_ai_mode">
                                            <input type="hidden" name="team_id" value="<?= $teamId ?>">
                                            <?= aiModeSelect($team['ai_mode'] ?: 'suggest', true) ?>
                                            <noscript><button type="submit" class="btn btn-secondary btn-sm">OK</button></noscript>
                                        </form>
                                    </td>
                                    <td class="col-actions">
                                        <div class="row-actions">
                                            <?= teamButton('level_up', $teamId, 'arrow-up', 'btn-success', 'Level omhoog', $level < 1 || $finished) ?>
                                            <?= teamButton('level_down', $teamId, 'arrow-down', 'btn-secondary', 'Level terug', $level <= 1, "Team '{$team['team_name']}' een level terugzetten? Het gesprek van het vorige level komt terug en de archiefregel van dat level verdwijnt.") ?>
                                            <div class="menu">
                                                <button type="button" class="btn btn-ghost btn-sm btn-icon" data-menu aria-haspopup="menu" aria-expanded="false" aria-label="Meer acties voor <?= htmlspecialchars($team['team_name']) ?>" title="Meer acties"><?= icon('more') ?></button>
                                                <div class="menu-list" role="menu" hidden>
                                                    <button type="button" class="menu-item" role="menuitem" data-copy="<?= htmlspecialchars(teamDashboardUrl($team['access_token'])) ?>"><?= icon('copy') ?><span class="copy-label">Teamlink kopiëren</span></button>
                                                    <?php if ($team['email']): ?>
                                                        <?= teamMenuItem('send_link', $teamId, 'mail', 'Teamlink opnieuw mailen', "Teamlink mailen naar {$team['email']}?") ?>
                                                    <?php endif; ?>
                                                    <div class="menu-sep"></div>
                                                    <?= teamMenuItem('delete_team', $teamId, 'trash', 'Team verwijderen', "Team '{$team['team_name']}' verwijderen? De chat gaat verloren; het archief blijft.", true) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div class="dash-aside">
                <div class="card">
                    <div class="card-title"><?= icon('user-plus') ?>Nieuw team</div>
                    <?php if (!$canAddTeam): ?>
                        <p class="muted">Dit spel is afgelopen; er kunnen geen teams meer bij.</p>
                    <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="add_team">
                        <div class="field">
                            <label for="team-name">Teamnaam</label>
                            <input type="text" name="team_name" id="team-name" placeholder="Bijv. De Speurders" maxlength="<?= TEAM_NAME_MAX_LENGTH ?>" required>
                        </div>
                        <div class="field">
                            <label for="team-email">E-mailadres <span class="muted">(optioneel)</span></label>
                            <input type="email" name="email" id="team-email" placeholder="begeleider@school.nl" oninput="toggleSendLink()">
                        </div>
                        <label class="check field" id="send-link-row" hidden>
                            <input type="checkbox" name="send_link" value="1" checked> Stuur de dashboardlink per mail
                        </label>
                        <button type="submit" class="btn btn-primary btn-block"><?= icon('plus') ?>Team toevoegen</button>
                        <p class="small muted" style="margin-top: 8px;">
                            <?= $game['effective_status'] === 'draft' ? 'Het team wacht tot het spel start.' : 'Het team begint direct op level 1.' ?>
                        </p>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-title"><?= icon('bot') ?>AI voor alle teams</div>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Alle teams van dit spel op deze AI-modus zetten? Nieuwe teams krijgen hem ook.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_all_ai_mode">
                        <?= aiModeSelect($game['ai_mode'] ?: 'suggest', false) ?>
                        <button type="submit" class="btn btn-primary">Toepassen</button>
                    </form>
                    <ul class="mode-list">
                        <?php foreach (AI_MODES as $mode): ?>
                            <li><strong><?= htmlspecialchars(aiModeLabel($mode)) ?></strong>: <?= htmlspecialchars(AI_MODE_DESCRIPTIONS[$mode]) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="small muted" style="margin-top: 10px;">Antwoorden en Automatisch werken alleen bij opdrachten met een goedgekeurde rubric (<a href="assignments.php">Opdrachten</a>); anders blijft het bij suggesties.</p>
                </div>
            </div>
        </div>
<?php
$extraJS = "
<script>
    function toggleSendLink() {
        document.getElementById('send-link-row').hidden = document.getElementById('team-email').value.trim() === '';
    }
</script>";
include 'admin_footer.php'; ?>
