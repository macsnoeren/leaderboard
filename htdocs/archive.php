<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
// Autorisatie check: Alleen admin mag hier komen
$user = requireAdmin($db);
$game = requireGame($db, $user);

// Haal gearchiveerde opdrachten van het actieve spel op; de teamnaam staat in het archief zelf
$stmt = $db->prepare("
    SELECT ca.*, COALESCE(ca.team_name, t.team_name) AS team_name
    FROM completed_assignments ca 
    LEFT JOIN teams t ON ca.team_id = t.id 
    WHERE ca.game_id = ?
    ORDER BY ca.created_at DESC
");
$stmt->execute([(int)$game['id']]);
$archives = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Archief';
$extraCSS = '<style>
    .chat-preview { max-width: 420px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-muted); font-size: 13px; }
    .chat-log { background: var(--surface-2); border: 1px solid var(--line); padding: 16px 18px; border-radius: var(--radius-sm); white-space: pre-wrap; line-height: 1.6; font-size: 14px; }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Archief', gameSubtitle($game),
        // Rapport van dit spel (worker-service): dezelfde actie als bij Spelinstellingen
        '<form method="POST" action="game.php" class="inline">' . csrfField() . '<input type="hidden" name="action" value="report">'
        . '<input type="hidden" name="sections[]" value="answers"><input type="hidden" name="sections[]" value="teams">'
        . '<button type="submit" class="btn btn-secondary">' . icon('file') . 'Rapport (PDF)</button></form>') ?>

    <div class="card">
        <p class="muted text-sm" style="margin-bottom: 16px;">Hier staan de chats van alle levels die teams hebben afgerond.</p>
        <?php if (empty($archives)): ?>
            <?= emptyState('archive', 'Nog geen afgeronde levels gearchiveerd.') ?>
        <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Datum</th>
                        <th scope="col">Team</th>
                        <th scope="col">Level</th>
                        <th scope="col">Chat</th>
                        <th scope="col" class="col-actions">Actie</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($archives as $a): ?>
                        <tr>
                            <td class="muted nowrap"><?= formatDateTime($a['created_at']) ?></td>
                            <td><strong><?= htmlspecialchars($a['team_name'] ?? 'Verwijderd team') ?></strong></td>
                            <td><span class="badge badge-brand mono">Level <?= (int)$a['assignment_number'] ?></span></td>
                            <td><div class="chat-preview"><?= htmlspecialchars($a['chat_history']) ?></div></td>
                            <td class="col-actions">
                                <button type="button" class="btn btn-secondary btn-sm" onclick='viewArchive(<?= json_encode(['team_name' => $a['team_name'], 'created_at' => formatDateTime($a['created_at']), 'assignment_number' => (int)$a['assignment_number'], 'chat_history' => $a['chat_history']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'><?= icon('eye') ?>Bekijken</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <dialog class="modal" id="archive-dialog" aria-labelledby="m-title">
        <div class="modal-header">
            <h2 id="m-title"></h2>
            <button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Sluiten"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <p id="m-meta" class="muted text-sm" style="margin-bottom: 14px;"></p>
            <div id="m-chat" class="chat-log"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-close>Sluiten</button></div>
    </dialog>

<script>
    function viewArchive(data) {
        document.getElementById('m-title').textContent = 'Chat van ' + (data.team_name || 'verwijderd team');
        document.getElementById('m-meta').textContent = 'Afgerond op ' + data.created_at + ' · level ' + data.assignment_number;
        document.getElementById('m-chat').textContent = data.chat_history;
        document.getElementById('archive-dialog').showModal();
    }
</script>
<?php include 'admin_footer.php'; ?>
