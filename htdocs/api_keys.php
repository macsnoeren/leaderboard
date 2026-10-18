<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';

$db = getDB();
// Alleen admins mogen API keys beheren
$admin = requireAdmin($db);
$adminId = (int)$admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('api_keys.php');
    }

    if ($_POST['action'] === 'add_key') {
        $name = trim($_POST['key_name'] ?? '');
        if ($name === '') {
            flash('error', 'Geef de sleutel een naam.');
        } else {
            // Genereer een veilige random key
            $new_key = bin2hex(random_bytes(32));
            $stmt = $db->prepare("INSERT INTO api_keys (key_name, api_key) VALUES (?, ?)");
            $stmt->execute([$name, $new_key]);
            logAudit($db, $adminId, 'API_KEY_ADD', "Added API key: $name");
            flash('success', "Nieuwe API-sleutel aangemaakt voor: $name");
        }
    }

    if ($_POST['action'] === 'delete_key' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM api_keys WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($db, $adminId, 'API_KEY_DELETE', "Deleted API key ID: $id");
        flash('success', 'API-sleutel ingetrokken.');
    }

    if ($_POST['action'] === 'reset_ai') {
        $db->exec("DELETE FROM ai_service_status");
        logAudit($db, $adminId, 'AI_RESET', 'Handmatig de AI agent status gereset');
        flash('success', 'Status van de AI-agents gereset. De status in de zijbalk werkt binnen 10 seconden bij.');
    }
    redirect('api_keys.php');
}

$keys = $db->query("SELECT * FROM api_keys ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'API-sleutels';
$extraCSS = '<style>
    .key-cell { display: flex; gap: 6px; align-items: center; }
    .key-cell code { font-size: 12px; background: var(--surface-2); border: 1px solid var(--line); padding: 4px 8px; border-radius: 4px; max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('API-sleutels', '<span>Voor de AI-agent en andere koppelingen via api.php</span>') ?>

    <?= renderFlash() ?>

    <div class="card">
        <div class="card-title"><?= icon('plus') ?>Nieuwe sleutel</div>
        <form method="POST" class="btn-row">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_key">
            <input type="text" name="key_name" placeholder="Naam van de koppeling (bijv. Python AI-agent)" required style="flex: 1; width: auto; min-width: 220px;" aria-label="Naam van de sleutel">
            <button type="submit" class="btn btn-primary"><?= icon('key') ?>Sleutel maken</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title"><?= icon('key') ?>Actieve sleutels</div>
        <?php if (empty($keys)): ?>
            <?= emptyState('key', 'Geen actieve API-sleutels.') ?>
        <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Naam</th>
                        <th scope="col">Sleutel</th>
                        <th scope="col">Aangemaakt</th>
                        <th scope="col" class="col-actions">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $k): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($k['key_name']) ?></strong></td>
                            <td><div class="key-cell"><code title="<?= htmlspecialchars($k['api_key']) ?>"><?= htmlspecialchars($k['api_key']) ?></code>
                                <button type="button" class="btn btn-ghost btn-sm btn-icon" data-copy="<?= htmlspecialchars($k['api_key']) ?>" title="Kopiëren" aria-label="Sleutel kopiëren"><?= icon('copy') ?></button></div></td>
                            <td class="muted nowrap"><?= formatDateTime($k['created_at']) ?></td>
                            <td class="col-actions">
                                <form method="POST" class="inline" onsubmit="return confirm('Weet je zeker dat je deze sleutel wilt intrekken?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_key">
                                    <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash') ?>Intrekken</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card card-danger">
        <div class="card-title"><?= icon('bot') ?>Status van de AI-agents</div>
        <p class="text-sm" style="margin-bottom: 15px;">Blijft de zijbalk oude agents tonen na een crash, dan kun je de status hier opschonen. Actieve agents verschijnen bij hun volgende hartslag weer vanzelf.</p>
        <form method="POST" onsubmit="return confirm('Weet je zeker dat je alle AI-agentsessies uit de database wilt verwijderen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_ai">
            <button type="submit" class="btn btn-danger"><?= icon('rotate-ccw') ?>Status resetten</button>
        </form>
    </div>
<?php include 'admin_footer.php'; ?>
