<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// Alleen admins mogen API keys beheren
if (($_SESSION['teacher_role'] ?? 'user') !== 'admin') {
    header('Location: teacher.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_key') {
        $name = trim($_POST['key_name']);
        if (empty($name)) {
            $message = "Geef de key een naam.";
        } else {
            // Genereer een veilige random key
            $new_key = bin2hex(random_bytes(32));
            $stmt = $db->prepare("INSERT INTO api_keys (key_name, api_key) VALUES (?, ?)");
            $stmt->execute([$name, $new_key]);
            $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'API_KEY_ADD', ?)")->execute([$_SESSION['teacher_id'], "Added API key: $name"]);
            $message = "Nieuwe API Key aangemaakt voor: $name";
        }
    }

    if ($_POST['action'] === 'delete_key' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM api_keys WHERE id = ?");
        $stmt->execute([$id]);
        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'API_KEY_DELETE', ?)")->execute([$_SESSION['teacher_id'], "Deleted API key ID: $id"]);
        $message = "API Key verwijderd.";
    }

    if ($_POST['action'] === 'reset_ai') {
        $db->exec("DELETE FROM ai_service_status");
        $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, 'AI_RESET', 'Handmatig de AI agent status gereset')")->execute([$_SESSION['teacher_id']]);
        $message = "AI Agent status gereset. De teller in de sidebar zal binnen 10 seconden bijwerken.";
    }
}

$keys = $db->query("SELECT * FROM api_keys ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'API Keys';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">🔑 API Key Management</h1>

    <?php if ($message): ?>
        <div class="success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">Nieuwe API Key Genereren</div>
        <form method="POST" style="display: flex; gap: 10px; align-items: center;">
            <input type="hidden" name="action" value="add_key">
            <input type="text" name="key_name" placeholder="Naam applicatie (bijv. Python AI Bot)" required style="flex: 1;">
            <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Key Genereren</button>
        </form>
    </div>

    <div class="card" style="border-left: 5px solid #f44336;">
        <div class="card-title" style="color: #f44336;">AI Agent Status Beheer</div>
        <p style="margin-bottom: 15px; color: #666; font-size: 0.9em;">Indien de teller in de sidebar onjuiste (oude) agents blijft tonen door onvoorziene crashes, kun je de status hier handmatig opschonen. Actieve agents zullen bij hun volgende heartbeat weer automatisch verschijnen.</p>
        <form method="POST" onsubmit="return confirm('Weet je zeker dat je alle AI agent sessies uit de database wilt verwijderen?')">
            <input type="hidden" name="action" value="reset_ai">
            <button type="submit" class="btn btn-danger">Reset AI Agent Status</button>
        </form>
    </div>

    <div class="card">
        <div class="card-title">Actieve Sleutels</div>
        <table style="width: 100%;">
            <thead>
                <tr>
                    <th>Naam</th>
                    <th>API Key</th>
                    <th>Aangemaakt op</th>
                    <th style="text-align: right;">Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keys as $k): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($k['key_name']) ?></strong></td>
                        <td><code style="background: #f0f2f5; padding: 4px 8px; border-radius: 4px; font-size: 0.9em;"><?= htmlspecialchars($k['api_key']) ?></code></td>
                        <td style="color: #888; font-size: 0.9em;"><?= $k['created_at'] ?></td>
                        <td style="text-align: right;">
                            <form method="POST" onsubmit="return confirm('Weet je zeker dat je deze sleutel wilt intrekken?')">
                                <input type="hidden" name="action" value="delete_key">
                                <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding: 6px 12px;">Intrekken</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($keys)): ?>
                    <tr><td colspan="4" style="text-align: center; color: #999; padding: 20px;">Geen actieve API keys gevonden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php include 'admin_footer.php'; ?>