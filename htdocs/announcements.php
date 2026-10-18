<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);
$userId = (int)$user['id'];
$game = requireGame($db, $user);
$gameId = (int)$game['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('announcements.php');
    }
    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        flash('error', 'Typ eerst een bericht.');
        redirect('announcements.php');
    }

    // Alle teams (één rij met team_id NULL) of een selectie (één rij per team)
    $recipients = [null];
    if (($_POST['recipients'] ?? 'all') === 'selection') {
        $recipients = [];
        foreach ((array)($_POST['team_ids'] ?? []) as $teamId) {
            $team = findTeamInGame($db, (int)$teamId, $gameId);
            if (!$team) {
                flash('error', 'Een van de gekozen teams hoort niet bij het actieve spel.');
                redirect('announcements.php');
            }
            $recipients[(int)$team['id']] = (int)$team['id'];
        }
        if (!$recipients) {
            flash('error', 'Kies minstens één team, of stuur het bericht aan alle teams.');
            redirect('announcements.php');
        }
    }

    // Eén tijdstip voor alle rijen, zodat ze in de lijst als één bericht verschijnen
    $now = $db->query("SELECT datetime('now')")->fetchColumn();
    $insert = $db->prepare("INSERT INTO announcements (game_id, team_id, message, created_by, created_at) VALUES (?, ?, ?, ?, ?)");
    foreach ($recipients as $teamId) {
        $insert->execute([$gameId, $teamId, $message, $userId, $now]);
    }
    $to = $recipients === [null] ? 'alle teams' : count($recipients) . ' team(s) (ID ' . implode(', ', $recipients) . ')';
    logAudit($db, $userId, 'ANNOUNCEMENT_SEND', "Mededeling verstuurd aan $to in spel #$gameId");
    flash('success', 'De mededeling is verstuurd. Teams zien hem binnen enkele seconden als pop-up.');
    redirect('announcements.php');
}

$teams = rankedTeams($db, $gameId);
usort($teams, function ($a, $b) { return strcasecmp($a['team_name'], $b['team_name']); });

// Verstuurde berichten, één regel per verzending
$stmt = $db->prepare("SELECT a.message, a.created_at, a.created_by,
        MAX(CASE WHEN a.team_id IS NULL THEN 1 ELSE 0 END) AS to_all,
        GROUP_CONCAT(t.team_name, ', ') AS team_names,
        COALESCE(te.display_name, te.username, te.email) AS sender
    FROM announcements a
    LEFT JOIN teams t ON t.id = a.team_id
    LEFT JOIN teachers te ON te.id = a.created_by
    WHERE a.game_id = ?
    GROUP BY a.created_at, a.created_by, a.message
    ORDER BY a.created_at DESC, MAX(a.id) DESC");
$stmt->execute([$gameId]);
$sent = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Mededelingen';
$extraCSS = '<style>
    .team-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px 16px; margin: 10px 0 0 26px; }
    .announcement { border-left: 3px solid var(--brand); background: var(--surface-2); padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 12px; }
    .announcement .meta { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
    .announcement .meta time { font-family: var(--font-mono); }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Mededelingen', gameSubtitle($game)) ?>

    <?= renderFlash() ?>

    <div class="grid-main">
        <div class="card">
            <div class="card-title"><?= icon('megaphone') ?>Verstuurde mededelingen</div>
            <?php foreach ($sent as $a): ?>
                <div class="announcement">
                    <div class="meta">
                        <time><?= formatDateTime($a['created_at']) ?></time> · <span><?= htmlspecialchars($a['sender'] ?? 'onbekend') ?></span> ·
                        <?= (int)$a['to_all'] ? '<span class="badge badge-brand">alle teams</span>' : '<span>aan ' . htmlspecialchars($a['team_names'] ?? 'verwijderd team') . '</span>' ?>
                    </div>
                    <div class="markdown" data-md><?= htmlspecialchars($a['message']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($sent)): ?>
                <?= emptyState('megaphone', 'Nog geen mededelingen verstuurd.') ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-title"><?= icon('pencil') ?>Nieuwe mededeling</div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send">
                <div class="field">
                    <label for="a-msg">Bericht</label>
                    <textarea name="message" id="a-msg" rows="6" placeholder="Bijv. **Over 10 minuten** verzamelen in lokaal 1.12" required></textarea>
                    <div class="hint">Markdown, net als bij de opdrachten.</div>
                </div>
                <fieldset class="field" style="border: none;">
                    <legend class="field-label">Aan</legend>
                    <label class="check"><input type="radio" name="recipients" value="all" checked onchange="toggleTeams()"> Alle teams</label>
                    <label class="check" style="margin-top: 8px;"><input type="radio" name="recipients" value="selection" onchange="toggleTeams()" <?= empty($teams) ? 'disabled' : '' ?>> Alleen deze teams:</label>
                    <div class="team-checks" id="team-checks" hidden>
                        <?php foreach ($teams as $t): ?>
                            <label class="check"><input type="checkbox" name="team_ids[]" value="<?= (int)$t['id'] ?>"> <?= htmlspecialchars($t['team_name']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <button type="submit" class="btn btn-primary btn-block"><?= icon('send') ?>Versturen</button>
                <p class="muted small" style="margin-top: 10px;">Teams zien een nieuwe mededeling als pop-up en kunnen hem daarna teruglezen onder "Mededelingen".</p>
            </form>
        </div>
    </div>
<?php
$extraJS = "
<script>
    function toggleTeams() {
        const selection = document.querySelector('input[name=recipients]:checked').value === 'selection';
        document.getElementById('team-checks').hidden = !selection;
    }
</script>";
include 'admin_footer.php'; ?>
