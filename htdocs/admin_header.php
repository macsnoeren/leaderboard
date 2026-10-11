<?php
// De pagina heeft requireTeacher()/requireAdmin() al aangeroepen; dit is alleen een vangnet.
// Daar zit ook de controle op "wachtwoord wijzigen verplicht".
if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../conf/game.php';

$db = getDB();

// Spelkiezer: de pagina heeft $game al als hij requireGame() aanroept; anders het spel uit de sessie
$headerUser = findTeacher($db, (int)$_SESSION['teacher_id']);
$headerGames = $headerUser ? accessibleGames($db, $headerUser) : [];
$headerGame = $game ?? null;
if (!$headerGame) {
    foreach ($headerGames as $g) {
        if ((int)$g['id'] === (int)($_SESSION['game_id'] ?? 0)) {
            $headerGame = $g;
        }
    }
}
$unread_total = $headerGame ? countUnread($db, (int)$headerGame['id']) : 0;
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Teacher Dashboard') ?> - Zebrawave</title>
    <?php if ($loadMarked ?? true): // profile.php zet dit uit: de 2FA-pagina laadt alleen scripts van cdnjs ?>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <?php endif; ?>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; color: #1c1e21; display: flex; min-height: 100vh; }
        
        .sidebar { width: 260px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 1000; }
        .sidebar-header { 
            padding: 25px 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; text-align: center; font-weight: bold; font-size: 1.2em; 
            display: flex; flex-direction: column; gap: 5px;
        }
        .sidebar-nav { flex: 1; padding: 20px 0; display: flex; flex-direction: column; overflow-y: auto; }
        .game-picker { padding: 12px 20px; border-bottom: 1px solid #eee; font-size: 0.85em; }
        .game-picker label { display: block; color: #999; font-size: 0.75em; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .game-picker select { padding: 8px; font-size: 0.95em; }
        .game-picker .game-name { font-weight: bold; color: #333; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
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
        <div class="sidebar-header">
            <span>Zebrawave Admin</span>
            <div style="font-size: 0.65em; font-weight: normal; opacity: 0.9; margin-top: 5px; display: flex; align-items: center; justify-content: center; gap: 5px;">
                👤 <?= htmlspecialchars($_SESSION['teacher_display_name'] ?? $_SESSION['teacher_username'] ?? 'Docent') ?>
            </div>
        </div>
        <div class="game-picker">
            <label>Spel</label>
            <?php if (count($headerGames) > 1): ?>
                <form method="POST" action="choose_game.php" style="margin: 0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="return" value="<?= htmlspecialchars($currentPage) ?>">
                    <select name="game_id" onchange="this.form.submit()" aria-label="Kies een spel">
                        <?php if (!$headerGame): ?><option value="">Kies een spel…</option><?php endif; ?>
                        <?php foreach ($headerGames as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= $headerGame && (int)$headerGame['id'] === (int)$g['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['name']) ?> (<?= htmlspecialchars(gameStatusLabel($g['effective_status'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn-outline" style="margin-top: 5px; padding: 4px 10px;">Wissel</button></noscript>
                </form>
            <?php elseif ($headerGame): ?>
                <div class="game-name"><span><?= htmlspecialchars($headerGame['name']) ?></span><?= gameStatusBadge($headerGame['effective_status']) ?></div>
            <?php else: ?>
                <span style="color: #999;">Nog geen spel</span>
            <?php endif; ?>
        </div>
        <div class="sidebar-nav">
            <a href="teacher.php" class="nav-item <?= $currentPage == 'teacher.php' ? 'active' : '' ?>">📊 Dashboard</a>
            <a href="game.php" class="nav-item <?= in_array($currentPage, ['game.php', 'choose_game.php']) ? 'active' : '' ?>">⚙️ Spel</a>
            <a href="assignments.php" class="nav-item <?= $currentPage == 'assignments.php' ? 'active' : '' ?>">📘 Assignments</a>
            <a href="messages.php" class="nav-item <?= $currentPage == 'messages.php' ? 'active' : '' ?>">
                ✉️ Messages
                <span id="unread-badge-container">
                    <?php if ($unread_total > 0): ?>
                        <span class="badge"><?= $unread_total ?></span>
                    <?php endif; ?>
                </span>
            </a>
            <a href="announcements.php" class="nav-item <?= $currentPage == 'announcements.php' ? 'active' : '' ?>">📣 Mededelingen</a>
            <?php if (($_SESSION['teacher_role'] ?? 'user') === 'admin'): ?>
            <a href="games.php" class="nav-item <?= $currentPage == 'games.php' ? 'active' : '' ?>">🎮 Spellen</a>
            <a href="users.php" class="nav-item <?= in_array($currentPage, ['users.php', 'user_edit.php']) ? 'active' : '' ?>">👥 Users</a>
            <a href="api_keys.php" class="nav-item <?= $currentPage == 'api_keys.php' ? 'active' : '' ?>">🔑 API Keys</a>
            <a href="archive.php" class="nav-item <?= $currentPage == 'archive.php' ? 'active' : '' ?>">📦 Archief</a>
            <a href="audit.php" class="nav-item <?= $currentPage == 'audit.php' ? 'active' : '' ?>">📋 Audit Logs</a>
            <?php endif; ?>
            <div style="margin-top: 20px; padding: 0 25px; font-size: 0.7em; color: #bbb; text-transform: uppercase;">Settings</div>
            <a href="profile.php" class="nav-item <?= in_array($currentPage, ['profile.php', 'password.php']) ? 'active' : '' ?>">👤 Profiel</a>
            <a href="https://darkwebchat.zebrawavesecurity.info/hacker-chat.html" target="_blank" class="nav-item" style="margin-top: auto; color: #4a148c; font-weight: bold;">💀 DARKNET CHAT</a>
            <a href="logout.php" class="nav-item" style="color: #c62828;">🚪 Logout</a>
        </div>
        <div style="padding: 15px 25px; border-top: 1px solid #eee; font-size: 0.8em; color: #888;">
            AI Agent: <span id="ai-agent-status" style="font-weight: bold; color: #888;">Controleren...</span>
        </div>
    </nav>
    <script>
    function updateAIStatus() {
        // Voeg een timestamp toe om caching te voorkomen
        fetch('teacher.php?ajax_ai_status=1&t=' + new Date().getTime())
            .then(r => r.text())
            .then(status => {
                const el = document.getElementById('ai-agent-status');
                if (!el || !status) return;
                
                el.innerText = status.charAt(0).toUpperCase() + status.slice(1);
                // Gebruik startsWith om verwarring met 'inactive' te voorkomen
                el.style.color = status.startsWith('active') ? '#2e7d32' : '#c62828';
            });
    }
    updateAIStatus();
    setInterval(updateAIStatus, 10000);
    </script>
    <div class="main-content">
        <?php if (defined('ENABLE_EMAIL') && ENABLE_EMAIL === false): ?>
            <div style="background: #fff3e0; color: #e65100; padding: 10px 20px; border-radius: 8px; margin-bottom: 25px; border-left: 5px solid #ff9800; font-size: 0.9em; display: flex; align-items: center; gap: 10px;">
                <span>⚠️</span>
                <span><strong>Test Modus:</strong> E-mail verzending staat uitgeschakeld in de configuratie. Er worden geen uitnodigingen of teamlinks verstuurd.</span>
            </div>
        <?php endif; ?>