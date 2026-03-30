<?php
require_once '../conf/config.php';
require_once '../conf/database.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// Haal logs op gecombineerd met de gebruikersnaam van de docent
$logs = $db->query("
    SELECT al.*, t.username 
    FROM audit_logs al 
    LEFT JOIN teachers t ON al.user_id = t.id 
    ORDER BY al.created_at DESC 
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: white; border-radius: 10px; padding: 30px; box-shadow: 0 2px 15px rgba(0,0,0,0.1); }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        h1 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #eee; font-size: 0.9em; }
        th { background: #f8f9fa; color: #555; }
        .event-type { font-weight: bold; color: #667eea; font-size: 0.8em; }
        .timestamp { color: #888; white-space: nowrap; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #667eea; text-decoration: none; font-weight: 600; }
        .tag {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8em;
            background: #eee;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📋 Audit Logs</h1>
            <a href="teacher.php" style="color: #667eea; text-decoration: none; font-weight: 600;">← Back</a>
        </header>

        <p>Recent activities within the system (last 500 events):</p>

        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Event</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="4" style="text-align:center;">No logs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="timestamp"><?= $log['created_at'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($log['username'] ?? 'System/Deleted') ?></strong>
                                <br><small class="timestamp">ID: <?= $log['user_id'] ?></small>
                            </td>
                            <td><span class="event-type"><?= htmlspecialchars($log['event_type']) ?></span></td>
                            <td><?= htmlspecialchars($log['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="back-link">
            <a href="teacher.php">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>