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
    .muted { color: #999; font-size: 0.85em; }
    .team-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 6px 15px; margin: 10px 0 0 25px; }
    .team-checks label, .recipient { display: flex; align-items: center; gap: 8px; font-size: 0.95em; }
    .team-checks input, .recipient input { width: auto; }
    .announcement { border-left: 4px solid #667eea; background: #f8f9ff; padding: 15px 20px; border-radius: 8px; margin-bottom: 15px; }
    .announcement .meta { font-size: 0.8em; color: #888; margin-bottom: 8px; }
    .markdown-body p { margin-bottom: 8px; }
    .markdown-body ul, .markdown-body ol { margin-left: 20px; }
</style>';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">📣 Mededelingen <span style="font-size: 0.6em; color: #888; font-weight: normal;"><?= htmlspecialchars($game['name']) ?></span></h1>

    <?= renderFlash() ?>

    <div class="dashboard-grid">
        <div class="card">
            <div class="card-title" style="margin-bottom: 15px;">📤 Verstuurde mededelingen</div>
            <?php foreach ($sent as $a): ?>
                <div class="announcement">
                    <div class="meta">
                        <?= formatDateTime($a['created_at']) ?> · <?= htmlspecialchars($a['sender'] ?? 'onbekend') ?> ·
                        aan <?= (int)$a['to_all'] ? '<strong>alle teams</strong>' : htmlspecialchars($a['team_names'] ?? 'verwijderd team') ?>
                    </div>
                    <div class="markdown-body"><?= htmlspecialchars($a['message']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($sent)): ?>
                <p class="muted">Nog geen mededelingen verstuurd.</p>
            <?php endif; ?>
        </div>

        <div class="card" style="border-top: 5px solid #667eea;">
            <div class="card-title" style="color: #667eea; font-size: 1.2em; margin-bottom: 15px;">✏️ Nieuwe mededeling</div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 0.9em;">Bericht (markdown, net als bij de opdrachten)</label>
                    <textarea name="message" rows="6" placeholder="Bijv. **Over 10 minuten** verzamelen in lokaal 1.12" required></textarea>
                </div>
                <div style="margin-bottom: 20px;">
                    <label class="recipient"><input type="radio" name="recipients" value="all" checked onchange="toggleTeams()"> Alle teams</label>
                    <label class="recipient" style="margin-top: 6px;"><input type="radio" name="recipients" value="selection" onchange="toggleTeams()" <?= empty($teams) ? 'disabled' : '' ?>> Alleen deze teams:</label>
                    <div class="team-checks" id="team-checks" style="display: none;">
                        <?php foreach ($teams as $t): ?>
                            <label><input type="checkbox" name="team_ids[]" value="<?= (int)$t['id'] ?>"> <?= htmlspecialchars($t['team_name']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">📣 Versturen</button>
                <p class="muted" style="margin-top: 10px;">Teams zien een nieuwe mededeling als pop-up en kunnen hem daarna teruglezen onder "📣 Mededelingen".</p>
            </form>
        </div>
    </div>
<?php
$extraJS = "
<script>
    function toggleTeams() {
        const selection = document.querySelector('input[name=recipients]:checked').value === 'selection';
        document.getElementById('team-checks').style.display = selection ? 'grid' : 'none';
    }
    marked.setOptions({ breaks: true });
    document.querySelectorAll('.markdown-body').forEach(el => { el.innerHTML = marked.parse(el.textContent.trim()); });
</script>";
include 'admin_footer.php'; ?>
