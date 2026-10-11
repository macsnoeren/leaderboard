<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);
$game = requireGame($db, $user);

$logo = gameLogoUrl($game);
$joinUrl = BASE_URL . '/join.php?code=' . urlencode((string)$game['join_code']);
$open = (int)$game['registration_open'] === 1 && $game['effective_status'] !== 'finished';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($game['name']) ?> - Aanmelden</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 3vh 3vw; }
        .wrap { display: grid; grid-template-columns: 1fr auto; gap: 6vw; align-items: center; max-width: 1600px; width: 100%; }
        .left { text-align: center; }
        .logo { max-width: 100%; max-height: 40vh; object-fit: contain; margin-bottom: 3vh; filter: drop-shadow(0 10px 30px rgba(0,0,0,0.3)); }
        h1 { font-size: 4vw; margin-bottom: 2vh; }
        .url { font-size: 1.8vw; opacity: 0.9; word-break: break-all; }
        .code-label { font-size: 1.6vw; text-transform: uppercase; letter-spacing: 4px; opacity: 0.8; margin-top: 4vh; }
        .code { font-family: monospace; font-size: 8vw; font-weight: bold; letter-spacing: 1.5vw; }
        .qr { background: white; padding: 2vw; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .closed { background: rgba(0,0,0,0.35); padding: 2vh 2vw; border-radius: 12px; font-size: 2vw; margin-top: 3vh; }
        @media (max-width: 800px) { .wrap { grid-template-columns: 1fr; } h1 { font-size: 8vw; } .url, .code-label { font-size: 4vw; } .code { font-size: 14vw; } }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="left">
            <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="logo"><?php endif; ?>
            <h1><?= htmlspecialchars($game['name']) ?></h1>
            <div class="url">Scan de QR-code of ga naar<br><strong><?= htmlspecialchars(BASE_URL . '/join.php') ?></strong></div>
            <div class="code-label">Spelcode</div>
            <div class="code"><?= htmlspecialchars((string)$game['join_code']) ?></div>
            <?php if (!$open): ?><div class="closed">🔒 De registratie is nu gesloten.</div><?php endif; ?>
        </div>
        <div class="qr" id="qrcode"></div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        const size = Math.min(window.innerHeight * 0.6, 520);
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($joinUrl) ?>,
            width: size,
            height: size,
            correctLevel: QRCode.CorrectLevel.M
        });
    </script>
</body>
</html>
