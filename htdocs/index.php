<?php
/*
 Copyright (C) 2025 Maurice Snoeren

 This program is free software: you can redistribute it and/or modify it under the terms of
 the GNU General Public License as published by the Free Software Foundation, version 3.

 This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 See the GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along with this program.
 If not, see https://www.gnu.org/licenses/.
*/

require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/game.php';

$db = getDB();

// ?game=<spelcode>; zonder parameter het enige lopende spel, of een keuzelijst
$code = trim($_GET['game'] ?? '');
$game = null;
$choices = [];
$notFound = false;
if ($code !== '') {
    $game = getGameByCode($db, $code);
    $notFound = $game === null;
} else {
    $choices = $db->query("SELECT " . gameSelectSql() . " FROM games g WHERE " . effectiveStatusSql() . " IN ('running', 'paused') ORDER BY g.name")->fetchAll(PDO::FETCH_ASSOC);
    if (count($choices) === 1) {
        $game = $choices[0];
    }
}

function levelBadge(int $level, int $total): string {
    if ($level <= 0) {
        return 'Start';
    }
    return $level > $total ? 'Finish' : "Level $level/$total";
}

/**
 * Alles wat het bord nodig heeft. Alleen publieke velden: geen e-mail of toegangstoken.
 * De laatste level-ups komen uit het archief (een level terug haalt de regel daar weg),
 * alleen van teams die nog in het spel zitten.
 */
function boardData(PDO $db, array $game): array {
    $gameId = (int)$game['id'];
    $total = totalAssignments($db, $gameId);
    $teams = [];
    foreach (rankedTeams($db, $gameId) as $index => $team) {
        $level = (int)$team['current_level'];
        $teams[] = [
            'id' => (int)$team['id'],
            'team_name' => $team['team_name'],
            'current_level' => $level,
            'rank' => $index + 1,
            'progress' => progressPercent($level, $total),
            'badge' => levelBadge($level, $total),
            'finished' => $total > 0 && $level > $total,
        ];
    }
    $stmt = $db->prepare("SELECT ca.team_name, ca.assignment_number, ca.created_at FROM completed_assignments ca
        WHERE ca.game_id = ? AND ca.team_id IN (SELECT id FROM teams WHERE game_id = ?) ORDER BY ca.id DESC LIMIT 8");
    $stmt->execute([$gameId, $gameId]);
    $recent = array_map(function ($r) use ($total) {
        return [
            'team_name' => (string)$r['team_name'],
            'level' => levelLabel((int)$r['assignment_number'] + 1, $total),
            'time' => substr(formatDateTime($r['created_at']), -5),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    return [
        'status' => $game['effective_status'],
        'status_message' => $game['status_message'],
        'teams' => $teams,
        'total_assignments' => $total,
        'seconds_left' => $game['seconds_left'] === null ? null : (int)$game['seconds_left'],
        'seconds_elapsed' => $game['seconds_elapsed'] === null ? null : (int)$game['seconds_elapsed'],
        'registration_open' => (int)$game['registration_open'] === 1 && $game['effective_status'] !== 'finished',
        'recent' => $recent,
    ];
}

$data = $game ? boardData($db, $game) : null;
$status = $data['status'] ?? null;

// AJAX endpoint om de gegevens live op te halen zonder de pagina te verversen
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode($data ?? ['status' => null, 'teams' => [], 'total_assignments' => 0]);
    exit;
}
$logo = $game ? gameLogoUrl($game) : null;
$joinUrl = $game ? BASE_URL . '/join.php?code=' . urlencode((string)$game['join_code']) : '';
$showBoard = $game && $status !== 'draft';
$needsQr = $game && ($data['registration_open'] || $status === 'draft');
?>
<!DOCTYPE html>
<html lang="nl" data-theme="ops">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $game ? htmlspecialchars($game['name']) . ' - ' : '' ?>Leaderboard</title>
    <?= themeMeta('ops') ?>
    <?= themeStyles() ?>
    <style>
        body.board-page { overflow: hidden; }
        .screen.is-draft { gap: clamp(10px, 2.2vh, 24px); }
        .screen.is-draft .screen-logo { max-height: 26vh; }
        .board { height: 100vh; display: flex; flex-direction: column; }
        .board-head { display: flex; justify-content: space-between; align-items: center; gap: 24px; padding: clamp(10px, 2vh, 22px) clamp(16px, 3vw, 48px); background-color: var(--surface); border-bottom: 1px solid var(--line); flex-shrink: 0; }
        .head-left { display: flex; align-items: center; gap: clamp(12px, 1.5vw, 24px); min-width: 0; }
        .board-logo { height: clamp(40px, 7vh, 84px); width: auto; max-width: 220px; object-fit: contain; }
        .board-title { font-size: clamp(22px, 3vw, 46px); font-weight: 800; letter-spacing: .01em; line-height: 1.1; text-transform: uppercase; overflow-wrap: anywhere; }
        .head-right { display: flex; align-items: center; gap: clamp(16px, 2.5vw, 40px); flex-shrink: 0; }
        .head-stat { text-align: right; }
        .head-stat .label { font-family: var(--font-mono); font-size: clamp(11px, 1vw, 14px); letter-spacing: .14em; text-transform: uppercase; color: var(--text-muted); }
        .head-stat .value { font-family: var(--font-mono); font-size: clamp(20px, 2.4vw, 38px); font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.15; }
        .head-stat .value.urgent { color: var(--danger); }
        .live { display: inline-flex; align-items: center; gap: 10px; font-family: var(--font-mono); font-weight: 700; letter-spacing: .2em; color: var(--signal); font-size: clamp(14px, 1.4vw, 22px); }
        .state-chip { font-family: var(--font-mono); font-weight: 700; letter-spacing: .16em; text-transform: uppercase; font-size: clamp(13px, 1.3vw, 20px); padding: 4px 14px; border-radius: var(--radius-pill); }
        .state-chip.paused { background: var(--warning-soft); color: var(--warning); }
        .state-chip.finished { background: var(--info-soft); color: var(--info); }
        .head-qr { display: flex; align-items: center; gap: 12px; }
        .head-qr .qr-card { padding: 6px; border-radius: 8px; }
        .head-qr .code { font-family: var(--font-mono); font-weight: 700; font-size: clamp(16px, 1.8vw, 28px); letter-spacing: .12em; }
        .pause-bar { display: flex; gap: 12px; justify-content: center; align-items: center; padding: 12px 24px; background: var(--warning-soft); color: var(--warning); border-bottom: 1px solid var(--warning); font-weight: 700; font-size: clamp(16px, 1.6vw, 26px); flex-shrink: 0; text-align: center; }
        .pause-bar .icon { width: 1.2em; height: 1.2em; }
        .board-main { flex: 1; min-height: 0; display: flex; flex-direction: column; gap: clamp(10px, 2vh, 24px); padding: clamp(12px, 2.5vh, 32px) clamp(16px, 3vw, 48px); transition: opacity var(--t); }
        .status-paused .board-main { opacity: .5; filter: saturate(.5); }

        /* Podium: 2 – 1 – 3 */
        .podium { --podium-h: clamp(130px, 25vh, 280px); display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: clamp(12px, 2vw, 32px); align-items: end; flex-shrink: 0; }
        .status-finished .podium { --podium-h: clamp(180px, 40vh, 460px); }
        .podium-card { position: relative; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: clamp(12px, 2vh, 26px) clamp(14px, 1.6vw, 28px); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: clamp(6px, 1.2vh, 14px); min-width: 0; }
        .podium-card.place-1 { grid-column: 2; grid-row: 1; min-height: var(--podium-h); border-color: var(--gold); box-shadow: inset 0 0 0 1px var(--gold), var(--shadow-2); }
        .podium-card.place-2 { grid-column: 1; grid-row: 1; min-height: calc(var(--podium-h) * .84); }
        .podium-card.place-3 { grid-column: 3; grid-row: 1; min-height: calc(var(--podium-h) * .72); }
        .podium-card .rank { width: clamp(40px, 6vh, 72px); height: clamp(40px, 6vh, 72px); font-size: clamp(18px, 2.8vh, 32px); }
        .podium-card .name { font-size: clamp(18px, 2.3vw, 40px); font-weight: 800; line-height: 1.15; overflow-wrap: anywhere; }
        .status-finished .podium-card .name { font-size: clamp(22px, 3vw, 52px); }
        .podium-card .lvl { font-family: var(--font-mono); color: var(--text-muted); font-size: clamp(14px, 1.4vw, 24px); display: inline-flex; align-items: center; gap: 8px; }
        .podium-card .progress { width: 100%; height: clamp(8px, 1.2vh, 14px); }
        .lvl.is-finish { color: var(--success); font-weight: 700; }
        .lvl .icon { width: 1em; height: 1em; }

        /* Overige teams: compacte rijen, boven de 12 teams in twee kolommen */
        .rows { --row-h: 56px; flex: 1; min-height: 0; display: grid; gap: 8px clamp(16px, 2vw, 32px); grid-auto-flow: column; align-content: start; }
        .row { display: grid; grid-template-columns: auto minmax(0, 1.3fr) minmax(0, 1fr) auto; align-items: center; gap: clamp(10px, 1.4vw, 24px); height: var(--row-h); padding: 0 clamp(10px, 1.2vw, 20px); background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); font-size: calc(var(--row-h) * .36); min-width: 0; }
        .row .rank { width: calc(var(--row-h) * .62); height: calc(var(--row-h) * .62); font-size: calc(var(--row-h) * .28); }
        .row .name { font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .row .lvl { font-family: var(--font-mono); color: var(--text-muted); white-space: nowrap; text-align: right; display: inline-flex; gap: 8px; align-items: center; justify-content: flex-end; }
        .row .progress { height: max(6px, calc(var(--row-h) * .16)); }
        .tag-bumped { font-family: var(--font-mono); color: var(--signal); font-size: .7em; font-weight: 700; white-space: nowrap; }
        .is-bumped { animation: bump 3s var(--ease); }
        @keyframes bump { 0%, 70% { background: var(--signal-soft); border-color: var(--signal); box-shadow: 0 0 0 2px var(--signal); } 100% { background: var(--surface); } }
        .board-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 16px; color: var(--text-muted); font-size: clamp(18px, 2vw, 28px); }
        .board-empty .icon { width: 64px; height: 64px; color: var(--line-strong); stroke-width: 1.5; }

        /* Ticker met de laatste level-ups */
        .ticker { flex-shrink: 0; display: flex; align-items: center; gap: 20px; padding: 10px clamp(16px, 3vw, 48px); background: var(--surface); border-top: 1px solid var(--line); font-size: clamp(13px, 1.2vw, 19px); }
        .ticker-label { white-space: nowrap; }
        .ticker-viewport { flex: 1; min-width: 0; overflow: hidden; -webkit-mask-image: linear-gradient(90deg, #000 calc(100% - 60px), transparent); mask-image: linear-gradient(90deg, #000 calc(100% - 60px), transparent); }
        .ticker-track { display: inline-flex; gap: 40px; white-space: nowrap; }
        .ticker-track.is-moving { animation: ticker var(--ticker-dur, 30s) linear infinite; }
        @keyframes ticker { to { transform: translateX(-50%); } }
        .ticker-item { display: inline-flex; align-items: center; gap: 8px; }
        .ticker-item .icon { color: var(--signal); width: 1em; height: 1em; }
        .ticker-item time { font-family: var(--font-mono); color: var(--text-muted); }

        /* Keuzelijst en lege schermen */
        .choices { display: grid; gap: 12px; width: min(560px, 100%); }
        .choice { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 18px 22px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); color: var(--text); text-decoration: none; font-size: 18px; font-weight: 700; }
        .choice:hover { border-color: var(--brand); color: var(--text); }
        .screen .code { font-family: var(--font-mono); font-weight: 800; font-size: clamp(36px, 7vw, 96px); letter-spacing: .14em; line-height: 1; }
        .screen-foot { position: fixed; bottom: 16px; left: 0; right: 0; text-align: center; font-size: 13px; }
        .screen-foot a { color: var(--text-muted); text-decoration: none; }

        /* Telefoon: gewoon scrollen */
        @media (max-width: 700px) {
            body.board-page { overflow: auto; }
            .board { height: auto; min-height: 100vh; }
            .board-head { flex-direction: column; align-items: flex-start; }
            .head-right { flex-wrap: wrap; gap: 16px; }
            .head-stat { text-align: left; }
            .podium { grid-template-columns: 1fr; }
            .podium-card.place-1, .podium-card.place-2, .podium-card.place-3 { grid-column: 1; grid-row: auto; min-height: 0; }
            .podium-card.place-1 { order: 1; } .podium-card.place-2 { order: 2; } .podium-card.place-3 { order: 3; }
            .rows { display: flex; flex-direction: column; }
            .row { grid-template-columns: auto minmax(0, 1fr) auto; }
            .row .progress { display: none; }
            .head-qr { display: none; }
        }
    </style>
</head>
<body class="<?= $status ? 'status-' . htmlspecialchars($status) : '' ?><?= $showBoard ? ' board-page' : '' ?>">
<?php if (!$game): ?>
    <main class="screen">
        <?= brandMark(64) ?>
        <div class="eyebrow">OperationRoom</div>
        <h1 class="screen-title">Leaderboard</h1>
        <?php if ($notFound): ?>
            <p class="screen-sub">Dit spel bestaat niet. Controleer de link of de spelcode.</p>
        <?php elseif (empty($choices)): ?>
            <p class="screen-sub">Er is op dit moment geen spel bezig.</p>
        <?php else: ?>
            <p class="screen-sub">Kies een spel</p>
            <div class="choices">
                <?php foreach ($choices as $choice): ?>
                    <a class="choice" href="index.php?game=<?= urlencode((string)$choice['join_code']) ?>"><span><?= htmlspecialchars($choice['name']) ?></span><?= gameStatusBadge($choice['effective_status']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="screen-foot"><a href="login.php">Inloggen voor docenten</a></div>
    </main>
<?php elseif ($status === 'draft'): ?>
    <main class="screen is-draft">
        <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="screen-logo"><?php else: ?><?= brandMark(72) ?><?php endif; ?>
        <div class="eyebrow"><?= htmlspecialchars($game['name']) ?></div>
        <h1 class="screen-title">Binnenkort van start<span class="waiting-dots" aria-hidden="true"></span></h1>
        <?php if ($data['registration_open']): ?>
            <p class="screen-sub">Meld je team aan met de spelcode</p>
            <div class="code"><?= htmlspecialchars((string)$game['join_code']) ?></div>
            <div class="qr-card" id="qrcode" data-size="160"></div>
        <?php endif; ?>
    </main>
<?php else: ?>
    <div class="board">
        <header class="board-head zebra-band">
            <div class="head-left">
                <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="board-logo"><?php else: ?><?= brandMark(56) ?><?php endif; ?>
                <h1 class="board-title"><?= htmlspecialchars($game['name']) ?></h1>
            </div>
            <div class="head-right">
                <?php if ($status === 'running'): ?>
                    <span class="live"><span class="live-dot" aria-hidden="true"></span>LIVE</span>
                <?php elseif ($status === 'paused'): ?>
                    <span class="state-chip paused">Pauze</span>
                <?php else: ?>
                    <span class="state-chip finished">Eindstand</span>
                <?php endif; ?>
                <?php if ($status !== 'finished'): ?>
                    <div class="head-stat"><div class="label" id="timer-label"><?= $data['seconds_left'] !== null ? 'Resterend' : 'Speeltijd' ?></div><div class="value" id="timer">--:--:--</div></div>
                <?php endif; ?>
                <div class="head-stat"><div class="label">Teams</div><div class="value" id="team-count"><?= count($data['teams']) ?></div></div>
                <?php if ($data['registration_open']): ?>
                    <div class="head-qr" title="Scan om je team aan te melden">
                        <div class="head-stat"><div class="label">Spelcode</div><div class="code"><?= htmlspecialchars((string)$game['join_code']) ?></div></div>
                        <div class="qr-card" id="qrcode" data-size="76"></div>
                    </div>
                <?php endif; ?>
            </div>
        </header>
        <?php if ($status === 'paused'): ?>
            <div class="pause-bar" role="status"><?= icon('pause') ?><span><?= htmlspecialchars($game['status_message'] ?: 'Het spel is even gepauzeerd.') ?></span></div>
        <?php endif; ?>
        <main class="board-main" id="board" aria-live="polite" aria-busy="true">
            <noscript>
                <ol class="choices">
                    <?php foreach ($data['teams'] as $t): ?>
                        <li class="choice"><span><?= (int)$t['rank'] ?>. <?= htmlspecialchars($t['team_name']) ?></span><span class="mono"><?= htmlspecialchars($t['badge']) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            </noscript>
        </main>
        <footer class="ticker" id="ticker" hidden>
            <span class="eyebrow ticker-label">Laatste level-ups</span>
            <div class="ticker-viewport"><div class="ticker-track" id="ticker-track"></div></div>
        </footer>
    </div>
<?php endif; ?>

<?php if ($needsQr): ?>
    <script src="<?= QRCODE_SRC ?>"></script>
    <script>
        (function () {
            const el = document.getElementById('qrcode');
            if (!el || !window.QRCode) return;
            const size = parseInt(el.dataset.size, 10);
            new QRCode(el, { text: <?= json_encode($joinUrl) ?>, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
        })();
    </script>
<?php endif; ?>
<?php if ($game): ?>
    <script>
        const gameCode = <?= json_encode((string)$game['join_code']) ?>;
        const initial = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const ICONS = { check: <?= json_encode(icon('check')) ?>, up: <?= json_encode(icon('arrow-up')) ?>, users: <?= json_encode(icon('users')) ?> };
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        // Spelcode in de URL, zodat herladen hetzelfde spel toont (ook als het afgelopen is)
        if (!new URLSearchParams(location.search).has('game')) {
            history.replaceState(null, '', 'index.php?game=' + encodeURIComponent(gameCode));
        }

        function esc(s) {
            return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        // Zelfde blokjes als progressBar() in conf/theme.php
        function progressHtml(level, total) {
            const done = Math.max(0, Math.min(total, level - 1));
            const finished = total > 0 && level > total;
            let html = `<div class="progress${finished ? ' is-finished' : ''}" role="progressbar" aria-valuemin="0" aria-valuemax="${total}" aria-valuenow="${done}" aria-label="${done} van ${total} opdrachten afgerond">`;
            for (let i = 1; i <= Math.max(1, total); i++) {
                html += `<span class="seg${i <= done ? ' done' : (i === level && total > 0 ? ' active' : '')}"></span>`;
            }
            return html + '</div>';
        }
        function rankHtml(rank) {
            return `<span class="rank${rank <= 3 ? ' rank-' + rank : ''}" aria-label="Positie ${rank}">${rank}</span>`;
        }
        function levelHtml(t) {
            return t.finished ? `<span class="lvl is-finish">${ICONS.check}Finish</span>` : `<span class="lvl">${esc(t.badge)}</span>`;
        }

        let state = initial;
        const prevLevels = {};
        const bumpedUntil = {};

        function render(data) {
            const board = document.getElementById('board');
            const total = data.total_assignments;
            const now = Date.now();
            data.teams.forEach(t => {
                if (prevLevels[t.id] !== undefined && t.current_level > prevLevels[t.id]) bumpedUntil[t.id] = now + 3000;
                prevLevels[t.id] = t.current_level;
            });

            // Eerst de ticker: die bepaalt mee hoeveel ruimte de rijen hebben
            renderTicker(data.recent || []);

            // First: oude posities per team
            const oldRects = {};
            board.querySelectorAll('[data-id]').forEach(el => { oldRects[el.dataset.id] = el.getBoundingClientRect(); });

            if (data.teams.length === 0) {
                board.innerHTML = `<div class="board-empty">${ICONS.users}<p>Nog geen teams</p>${data.registration_open ? '<p class="muted">Meld je aan met de spelcode rechtsboven.</p>' : ''}</div>`;
            } else {
                const bumped = id => bumpedUntil[id] && bumpedUntil[id] > now;
                const podium = data.teams.slice(0, 3).map(t => `
                    <div class="podium-card place-${t.rank}${bumped(t.id) ? ' is-bumped' : ''}" data-id="${t.id}">
                        ${rankHtml(t.rank)}
                        <div class="name">${esc(t.team_name)}</div>
                        ${levelHtml(t)}
                        ${progressHtml(t.current_level, total)}
                    </div>`).join('');
                const rest = data.teams.slice(3);
                const rows = rest.map(t => `
                    <div class="row${bumped(t.id) ? ' is-bumped' : ''}" data-id="${t.id}">
                        ${rankHtml(t.rank)}
                        <span class="name">${esc(t.team_name)}</span>
                        ${progressHtml(t.current_level, total)}
                        <span class="lvl">${bumped(t.id) ? `<span class="tag-bumped">${ICONS.up}zojuist</span>` : ''}${t.finished ? ICONS.check + 'Finish' : esc(t.badge)}</span>
                    </div>`).join('');
                board.innerHTML = `<section class="podium" aria-label="Top 3">${podium}</section>` + (rest.length ? `<section class="rows" id="rows" aria-label="Overige teams">${rows}</section>` : '');
                fitRows();
            }
            board.setAttribute('aria-busy', 'false');
            document.getElementById('team-count').textContent = data.teams.length;

            // Last, Invert, Play
            if (!reduceMotion) {
                board.querySelectorAll('[data-id]').forEach(el => {
                    const old = oldRects[el.dataset.id];
                    if (!old) return;
                    const r = el.getBoundingClientRect();
                    const dx = old.left - r.left, dy = old.top - r.top;
                    if (dx === 0 && dy === 0) return;
                    el.style.transition = 'none';
                    el.style.transform = `translate(${dx}px, ${dy}px)`;
                    el.offsetHeight; // reflow
                    el.style.transition = 'transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1)';
                    el.style.transform = '';
                });
            }
            // Highlight na 3 seconden weg
            Object.keys(bumpedUntil).forEach(id => {
                const left = bumpedUntil[id] - now;
                if (left > 0) setTimeout(() => {
                    const el = board.querySelector(`[data-id="${id}"]`);
                    if (el) { el.classList.remove('is-bumped'); el.querySelector('.tag-bumped')?.remove(); }
                }, left);
            });
        }

        // Altijd op één scherm: rijhoogte schaalt mee, boven de 12 teams twee kolommen
        function fitRows() {
            const rows = document.getElementById('rows');
            if (!rows) return;
            const n = rows.children.length;
            if (window.innerWidth <= 700) {
                rows.style.removeProperty('grid-template-rows');
                rows.style.removeProperty('grid-template-columns');
                rows.style.setProperty('--row-h', '52px');
                return;
            }
            const cols = n + 3 > 12 ? 2 : 1;
            const perCol = Math.ceil(n / cols);
            const gap = 8;
            const avail = rows.clientHeight || 400;
            const h = Math.max(28, Math.min(76, Math.floor((avail - gap * (perCol - 1)) / perCol)));
            rows.style.setProperty('--row-h', h + 'px');
            rows.style.gridTemplateRows = `repeat(${perCol}, ${h}px)`;
            rows.style.gridTemplateColumns = `repeat(${cols}, minmax(0, 1fr))`;
        }
        window.addEventListener('resize', fitRows);

        function renderTicker(recent) {
            const ticker = document.getElementById('ticker');
            const track = document.getElementById('ticker-track');
            if (!ticker) return;
            ticker.hidden = recent.length === 0;
            const items = recent.map(r => `<span class="ticker-item">${ICONS.up}<strong>${esc(r.team_name)}</strong> → ${r.level === 'Finish' ? 'finish' : 'level ' + esc(r.level)} <time>${esc(r.time)}</time></span>`).join('');
            if (track.dataset.items === items) return;
            track.dataset.items = items;
            track.classList.remove('is-moving');
            track.innerHTML = items;
            // Past het niet, dan rustig doorlopen (niet bij prefers-reduced-motion)
            const viewport = track.parentElement;
            if (!reduceMotion && track.scrollWidth > viewport.clientWidth) {
                track.innerHTML = items + `<span aria-hidden="true" style="display:inline-flex; gap:40px;">${items}</span>`;
                track.style.setProperty('--ticker-dur', Math.max(20, track.scrollWidth / 2 / 60) + 's');
                track.classList.add('is-moving');
            }
        }

        // Klok: alleen het verschil met de server telt, niet de klok van dit scherm
        let deadline = null, startedAt = null;
        function syncClock(data) {
            deadline = data.seconds_left !== null ? Date.now() + data.seconds_left * 1000 : null;
            startedAt = data.seconds_elapsed !== null ? Date.now() - data.seconds_elapsed * 1000 : null;
            const label = document.getElementById('timer-label');
            if (label) label.textContent = deadline !== null ? 'Resterend' : 'Speeltijd';
        }
        function tick() {
            const el = document.getElementById('timer');
            if (!el) return;
            let ms = null;
            if (deadline !== null) ms = Math.max(0, deadline - Date.now());
            else if (startedAt !== null) ms = Math.max(0, Date.now() - startedAt);
            if (ms === null) { el.textContent = '--:--:--'; return; }
            const s = Math.floor(ms / 1000);
            el.textContent = [Math.floor(s / 3600), Math.floor(s % 3600 / 60), s % 60].map(v => String(v).padStart(2, '0')).join(':');
            el.classList.toggle('urgent', deadline !== null && ms <= 10 * 60 * 1000);
        }

        function fetchLeaderboard() {
            fetch('index.php?ajax=1&game=' + encodeURIComponent(gameCode))
                .then(response => response.json())
                .then(data => {
                    // Start, pauze, einde of registratie open/dicht: de pagina ziet er anders uit
                    if (data.status !== state.status || data.registration_open !== state.registration_open) { location.reload(); return; }
                    state = data;
                    syncClock(data);
                    render(data);
                })
                .catch(err => console.error('Fout bij ophalen leaderboard:', err));
        }

        if (document.getElementById('board')) {
            syncClock(initial);
            render(initial);
            tick();
            setInterval(tick, 1000);
        }
        // Elke 10 seconden verversen (ook op het wachtscherm, zodat de start meteen zichtbaar is)
        setInterval(fetchLeaderboard, 10000);
    </script>
<?php endif; ?>
</body>
</html>
