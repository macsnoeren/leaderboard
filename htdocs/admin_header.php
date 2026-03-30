<?php
if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Beveiliging: Forceer wachtwoordwijziging op ELKE pagina als de vlag aan staat
if (isset($_SESSION['force_password_change']) && basename($_SERVER['PHP_SELF']) !== 'password.php' && basename($_SERVER['PHP_SELF']) !== 'logout.php') {
    header('Location: password.php');
    exit;
}

$db = getDB();
$unread_total = $db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'team' AND is_read = 0")->fetchColumn();
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Teacher Dashboard' ?> - Zebrawave</title>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; display: flex; min-height: 100vh; }
        
        .sidebar { width: 260px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 1000; }
        .sidebar-header { padding: 30px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-align: center; font-weight: bold; font-size: 1.2em; }
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .nav-item { display: flex; align-items: center; padding: 12px 25px; color: #4b4f56; text-decoration: none; transition: 0.2s; font-weight: 500; }
        .nav-item:hover { background: #f0f2f5; color: #667eea; }
        .nav-item.active { background: #f0f4ff; color: #667eea; border-left: 4px solid #667eea; }
        .badge { background: #f44336; color: white; padding: 2px 7px; border-radius: 10px; font-size: 0.75em; margin-left: auto; }

        .main-content { margin-left: 260px; flex: 1; padding: 40px; min-width: 0; }
        header.content-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        h1 { font-size: 1.8em; color: #333; }
        
        /* Dashboard Grid Layout */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: start; }
        .stats-overview { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center; }
        .stat-label { font-size: 0.8em; color: #888; text-transform: uppercase; letter-spacing: 1px; }
        .stat-value { font-size: 1.8em; font-weight: bold; color: #667eea; margin-top: 5px; }

        .card { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 0.9em; text-decoration: none; text-align: center; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
        .btn-outline { background: transparent; border: 1px solid #ddd; color: #666; }
        .btn-outline:hover { background: #f8f9fa; border-color: #ccc; }
        .btn-danger { background: #ffebee; color: #c62828; }
        .btn-danger:hover { background: #ffcdd2; }
        
        .success { background: #e8f5e9; color: #2e7d32; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; border-left: 5px solid #4caf50; font-weight: 500; }
        .error-msg { background: #ffebee; color: #c62828; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; border-left: 5px solid #f44336; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; border-bottom: 2px solid #f0f2f5; color: #888; font-size: 0.85em; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid #f0f2f5; }

        /* Progress Bar Styles */
        .progress-wrapper { width: 100%; min-width: 100px; }
        .progress-fill { background: #667eea; height: 100%; border-radius: 3px; transition: width 0.3s ease; }

        input, select, textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 0.95em; transition: all 0.2s; }
        input:focus, select:focus, textarea:focus { border-color: #667eea; outline: none; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
    </style>
    <?php if (isset($extraCSS)) echo $extraCSS; ?>
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">Zebrawave Admin</div>
        <div class="sidebar-nav">
            <a href="teacher.php" class="nav-item <?= $currentPage == 'teacher.php' ? 'active' : '' ?>">📊 Dashboard</a>
            <a href="assignments.php" class="nav-item <?= $currentPage == 'assignments.php' ? 'active' : '' ?>">📘 Assignments</a>
            <a href="messages.php" class="nav-item <?= $currentPage == 'messages.php' ? 'active' : '' ?>">
                ✉️ Messages
                <span id="unread-badge-container">
                    <?php if ($unread_total > 0): ?>
                        <span class="badge"><?= $unread_total ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <?php if (($_SESSION['teacher_role'] ?? 'user') === 'admin'): ?>
            <a href="users.php" class="nav-item <?= $currentPage == 'users.php' ? 'active' : '' ?>">👥 Users</a>
            <a href="api_keys.php" class="nav-item <?= $currentPage == 'api_keys.php' ? 'active' : '' ?>">🔑 API Keys</a>
            <a href="audit.php" class="nav-item <?= $currentPage == 'audit.php' ? 'active' : '' ?>">📋 Audit Logs</a>
            <?php endif; ?>
            <div style="margin-top: 20px; padding: 0 25px; font-size: 0.7em; color: #bbb; text-transform: uppercase;">Settings</div>
            <a href="password.php" class="nav-item <?= $currentPage == 'password.php' ? 'active' : '' ?>">🔑 Password</a>
            <a href="logout.php" class="nav-item" style="margin-top: auto; color: #c62828;">🚪 Logout</a>
        </div>
    </nav>
    <div class="main-content">
        <?php if (defined('ENABLE_EMAIL') && ENABLE_EMAIL === false): ?>
            <div style="background: #fff3e0; color: #e65100; padding: 10px 20px; border-radius: 8px; margin-bottom: 25px; border-left: 5px solid #ff9800; font-size: 0.9em; display: flex; align-items: center; gap: 10px;">
                <span>⚠️</span>
                <span><strong>Test Modus:</strong> E-mail verzending staat uitgeschakeld in de configuratie. Er worden geen uitnodigingen of level-up berichten verstuurd.</span>
            </div>
        <?php endif; ?>