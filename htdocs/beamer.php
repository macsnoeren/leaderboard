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
<html lang="nl" data-theme="ops">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($game['name']) ?> - Aanmelden</title>
    <?= themeMeta('ops') ?>
    <?= themeStyles() ?>
    <style>
        body { min-height: 100vh; display: flex; flex-direction: column; background: radial-gradient(ellipse at 30% 0%, var(--surface-2) 0%, var(--bg) 65%); }
        .beamer-bar { height: 8px; background: repeating-linear-gradient(-45deg, var(--brand) 0 14px, transparent 14px 28px); opacity: .8; }
        .wrap { flex: 1; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6vw; align-items: center; max-width: 1700px; width: 100%; margin: 0 auto; padding: 4vh 5vw; }
        .left { display: flex; flex-direction: column; gap: 2.5vh; }
        .logo { max-width: min(100%, 640px); max-height: 30vh; object-fit: contain; align-self: flex-start; filter: drop-shadow(0 12px 30px rgba(0,0,0,.45)); }
        h1 { font-size: clamp(32px, 4.4vw, 88px); font-weight: 800; letter-spacing: -.02em; line-height: 1.05; }
        .url { font-size: clamp(16px, 1.8vw, 34px); color: var(--text-muted); overflow-wrap: anywhere; }
        .url strong { color: var(--text); font-family: var(--font-mono); font-weight: 600; }
        .eyebrow { font-size: clamp(13px, 1.4vw, 26px); margin-top: 2vh; }
        .code { font-family: var(--font-mono); font-size: clamp(56px, 9vw, 190px); font-weight: 800; letter-spacing: .12em; line-height: 1; color: var(--text); }
        .qr { background: #FFFFFF; padding: 2vw; border-radius: var(--radius-lg); box-shadow: var(--shadow-2); line-height: 0; }
        .closed { display: inline-flex; align-items: center; gap: 14px; align-self: flex-start; background: var(--warning-soft); color: var(--warning); border: 1px solid var(--warning); padding: 1.6vh 2vw; border-radius: var(--radius); font-size: clamp(16px, 2vw, 34px); font-weight: 700; }
        .closed .icon { width: 1.2em; height: 1.2em; }
        @media (max-width: 800px) { .wrap { grid-template-columns: 1fr; } .qr { justify-self: center; } }
    </style>
</head>
<body>
    <div class="beamer-bar" aria-hidden="true"></div>
    <main class="wrap">
        <div class="left">
            <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="logo"><?php else: ?><?= brandMark(96) ?><?php endif; ?>
            <h1><?= htmlspecialchars($game['name']) ?></h1>
            <div class="url">Scan de QR-code of ga naar<br><strong><?= htmlspecialchars(BASE_URL . '/join.php') ?></strong></div>
            <div class="eyebrow">Spelcode</div>
            <div class="code"><?= htmlspecialchars((string)$game['join_code']) ?></div>
            <?php if (!$open): ?><div class="closed" role="status"><?= icon('lock') ?>De registratie is nu gesloten.</div><?php endif; ?>
        </div>
        <div class="qr" id="qrcode" aria-label="QR-code naar de aanmeldpagina" role="img"></div>
    </main>
    <script src="<?= QRCODE_SRC ?>"></script>
    <script>
        const size = Math.min(window.innerHeight * 0.6, window.innerWidth * 0.4, 560);
        new QRCode(document.getElementById('qrcode'), {
            text: <?= json_encode($joinUrl) ?>,
            width: size,
            height: size,
            correctLevel: QRCode.CorrectLevel.M
        });
    </script>
</body>
</html>
