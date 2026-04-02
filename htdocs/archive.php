<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// Autorisatie check: Alleen admin mag hier komen
if (($_SESSION['teacher_role'] ?? 'user') !== 'admin') {
    header('Location: teacher.php');
    exit;
}

// Haal gearchiveerde opdrachten op
$stmt = $db->query("
    SELECT ca.*, t.team_name 
    FROM completed_assignments ca 
    LEFT JOIN teams t ON ca.team_id = t.id 
    ORDER BY ca.created_at DESC
");
$archives = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Archief';
$extraCSS = '
<style>
    .chat-preview { 
        max-width: 400px; 
        white-space: nowrap; 
        overflow: hidden; 
        text-overflow: ellipsis; 
        color: #666; 
        font-size: 0.85em; 
    }
    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); overflow-y: auto; }
    .modal-content { background-color: white; margin: 5% auto; padding: 30px; border-radius: 15px; width: 70%; max-width: 800px; box-shadow: 0 5px 30px rgba(0,0,0,0.3); position: relative; }
    .close-modal { position: absolute; top: 20px; right: 25px; font-size: 28px; font-weight: bold; cursor: pointer; color: #aaa; transition: 0.2s; }
    .close-modal:hover { color: #333; }
    .chat-log { background: #f8f9fa; padding: 20px; border-radius: 10px; border: 1px solid #eee; white-space: pre-wrap; font-family: inherit; line-height: 1.6; }
</style>';

include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">📦 Archief Voltooide Opdrachten</h1>

    <div class="card">
        <p style="margin-bottom: 20px; color: #666;">Hier staan alle chats van teams die een level succesvol hebben afgerond.</p>
        <table>
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Team</th>
                    <th style="text-align: center;">Level</th>
                    <th>Chat Geschiedenis</th>
                    <th style="text-align: right;">Actie</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($archives as $a): ?>
                    <tr>
                        <td style="color: #888; font-size: 0.9em;"><?= $a['created_at'] ?></td>
                        <td><strong><?= htmlspecialchars($a['team_name'] ?? 'Verwijderd Team') ?></strong></td>
                        <td style="text-align: center;"><span class="badge" style="background:#667eea;">Level <?= $a['assignment_number'] ?></span></td>
                        <td><div class="chat-preview"><?= htmlspecialchars($a['chat_history']) ?></div></td>
                        <td style="text-align: right;">
                            <button class="btn btn-outline" onclick='viewArchive(<?= json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Bekijken</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($archives)): ?>
                    <tr><td colspan="5" style="text-align: center; color: #999; padding: 40px;">Nog geen voltooide opdrachten gearchiveerd.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="archiveModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h2 id="m-title" style="margin-bottom: 10px;"></h2>
            <p id="m-meta" style="color: #888; margin-bottom: 20px; font-size: 0.9em;"></p>
            <div id="m-chat" class="chat-log"></div>
        </div>
    </div>

<script>
    function viewArchive(data) {
        document.getElementById('m-title').innerText = "Chat van Team: " + (data.team_name || 'Verwijderd');
        document.getElementById('m-meta').innerText = "Voltooid op: " + data.created_at + " | Betreft Level: " + data.assignment_number;
        document.getElementById('m-chat').innerText = data.chat_history;
        document.getElementById('archiveModal').style.display = 'block';
    }
    function closeModal() {
        document.getElementById('archiveModal').style.display = 'none';
    }
    window.onclick = function(e) { if(e.target == document.getElementById('archiveModal')) closeModal(); }
</script>
<?php include 'admin_footer.php'; ?>