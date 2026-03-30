<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// Tel ongelezen berichten voor de sidebar badge
$unread_total = $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();

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
    <title>Audit Logs - Zebrawave</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; position: fixed; height: 100vh; }
        .sidebar-header { padding: 30px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-align: center; font-weight: bold; font-size: 1.2em; }
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .nav-item { display: flex; align-items: center; padding: 12px 25px; color: #4b4f56; text-decoration: none; transition: 0.2s; font-weight: 500; }
        .nav-item:hover { background: #f0f2f5; color: #667eea; }
        .nav-item.active { background: #f0f4ff; color: #667eea; border-left: 4px solid #667eea; }
        .badge { background: #f44336; color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75em; margin-left: auto; }
        .main-content { margin-left: 260px; flex: 1; padding: 40px; }
        .card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; border-bottom: 2px solid #f0f2f5; color: #888; font-size: 0.85em; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid #f0f2f5; font-size: 0.95em; }
        .event-type { font-weight: bold; color: #667eea; }
        .timestamp { color: #888; }
    </style>
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">Zebrawave Admin</div>
        <div class="sidebar-nav">
            <a href="teacher.php" class="nav-item">📊 Dashboard</a>
            <a href="assignments.php" class="nav-item">📘 Assignments</a>
            <a href="messages.php" class="nav-item">
                ✉️ Messages
                <span id="unread-badge-container">
                    <?php if ($unread_total > 0): ?>
                        <span class="badge"><?= $unread_total ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <a href="users.php" class="nav-item">👥 Users</a>
            <a href="audit.php" class="nav-item active">📋 Audit Logs</a>
            <div style="margin-top: 20px; padding: 0 25px; font-size: 0.7em; color: #bbb; text-transform: uppercase;">Settings</div>
            <a href="password.php" class="nav-item">🔑 Password</a>
            <a href="logout.php" class="nav-item" style="margin-top: auto; color: #c62828;">🚪 Logout</a>
        </div>
    </nav>

    <div class="main-content">
        <h1 style="margin-bottom: 30px;">📋 Audit Logs</h1>

        <div class="card">
            <p style="margin-bottom: 20px; color: #666;">Recent activities within the system (last 500 events):</p>
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
        </div>
    </div>
</body>
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