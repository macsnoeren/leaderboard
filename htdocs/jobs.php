<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);
$userId = (int)$user['id'];
$game = requireGame($db, $user);
$gameId = (int)$game['id'];
$isAdmin = $user['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('jobs.php');
    }
    if ($_POST['action'] === 'cancel') {
        if (cancelJob($db, (int)($_POST['job_id'] ?? 0), $user)) {
            flash('success', 'De taak is geannuleerd.');
        } else {
            flash('error', 'Deze taak kan niet (meer) worden geannuleerd.');
        }
    }
    redirect('jobs.php');
}

/**
 * Rijen van de takentabel; ook gebruikt door de polling (?ajax=jobs).
 */
function jobRowsHtml(PDO $db, array $jobs, array $user): string {
    $html = '';
    foreach ($jobs as $job) {
        $id = (int)$job['id'];
        $status = $job['status'];
        $actions = '';
        if ($status === 'done' && jobDownloadAllowed($db, $job, $user)) {
            $actions .= jobResultPath($job)
                ? '<a class="btn btn-sm btn-secondary" href="job_download.php?id=' . $id . '">' . icon('download') . 'Download</a>'
                : '<span class="muted small">verlopen</span>';
        }
        if ($status === 'done' && $job['type'] === 'game_import' && $job['result_game_id']) {
            $actions .= '<form method="POST" action="choose_game.php" class="inline">' . csrfField()
                . '<input type="hidden" name="game_id" value="' . (int)$job['result_game_id'] . '">'
                . '<input type="hidden" name="return" value="game.php">'
                . '<button type="submit" class="btn btn-sm btn-secondary">' . icon('gamepad') . 'Naar het spel</button></form>';
        }
        if ($status === 'queued' && ($user['role'] === 'admin' || (int)$job['created_by'] === (int)$user['id'])) {
            $actions .= '<form method="POST" class="inline" onsubmit="return confirm(\'Deze taak annuleren?\');">' . csrfField()
                . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="job_id" value="' . $id . '">'
                . '<button type="submit" class="btn btn-sm btn-ghost">' . icon('x') . 'Annuleren</button></form>';
        }
        $progress = '';
        if ($status === 'running') {
            $pct = max(0, min(100, (int)$job['progress']));
            $progress = '<div class="job-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="Voortgang"><span style="width: ' . $pct . '%"></span></div><span class="muted small">' . $pct . '%</span>';
        }
        $size = $job['result_size'] !== null && $status === 'done' ? ' <span class="muted small">(' . formatBytes((int)$job['result_size']) . ')</span>' : '';
        $html .= '<tr>'
            . '<td><strong>' . htmlspecialchars(jobTypeLabel($job['type'])) . '</strong><div class="muted small">' . htmlspecialchars(jobDescription($job)) . '</div></td>'
            . '<td class="muted nowrap">' . formatDateTime($job['created_at']) . '</td>'
            . '<td><div class="job-status">' . jobStatusBadge($status) . $progress . '</div></td>'
            . '<td class="job-message">' . htmlspecialchars((string)$job['message']) . $size . '</td>'
            . '<td class="col-actions"><div class="btn-row">' . $actions . '</div></td>'
            . '</tr>';
    }
    return $html;
}

$jobs = jobsForUser($db, $user, $gameId);
$active = workerActive($db);
$pending = hasPendingJobs($jobs);

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(['html' => jobRowsHtml($db, $jobs, $user), 'pending' => $pending, 'worker' => $active]);
    exit;
}

$pageTitle = 'Taken';
$extraCSS = '<style>
    .job-status { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .job-progress { width: 90px; height: 6px; border-radius: var(--radius-pill); background: var(--surface-2); overflow: hidden; }
    .job-progress span { display: block; height: 100%; background: var(--brand); transition: width var(--t) var(--ease); }
    .job-message { max-width: 420px; font-size: 13px; overflow-wrap: anywhere; }
    @media (prefers-reduced-motion: reduce) { .job-progress span { transition: none; } }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Taken', gameSubtitle($game), $isAdmin ? '<a href="backups.php" class="btn btn-secondary">' . icon('archive') . 'Backups</a>' : '') ?>

    <?= renderFlash() ?>

    <div id="worker-warning"<?= $pending && !$active ? '' : ' hidden' ?>>
        <?= alertBox('warning', 'Er draait op dit moment geen worker: taken blijven in de wachtrij staan tot de worker-service weer draait.') ?>
    </div>

    <div class="card">
        <p class="muted text-sm" style="margin-bottom: 16px;">Exports, imports, rapporten<?= $isAdmin ? ' en backups' : '' ?> worden in de achtergrond gemaakt. Een export of rapport kun je <?= resultTtlDays() ?> dagen downloaden.</p>
        <?php if (empty($jobs)): ?>
            <?= emptyState('inbox', 'Nog geen taken. Exporteren en rapporten maken doe je bij Spelinstellingen.') ?>
        <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Taak</th>
                        <th scope="col">Aangevraagd</th>
                        <th scope="col">Status</th>
                        <th scope="col">Melding</th>
                        <th scope="col" class="col-actions">Actie</th>
                    </tr>
                </thead>
                <tbody id="job-rows"><?= jobRowsHtml($db, $jobs, $user) ?></tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Verversen zolang er iets in de wachtrij staat of bezig is
        (function () {
            let pending = <?= json_encode($pending) ?>;
            function poll() {
                if (!pending) return;
                fetch('jobs.php?ajax=jobs&t=' + Date.now())
                    .then(r => r.ok ? r.json() : null)
                    .then(data => {
                        if (!data) return;
                        const rows = document.getElementById('job-rows');
                        if (rows) rows.innerHTML = data.html;
                        document.getElementById('worker-warning').hidden = !(data.pending && !data.worker);
                        pending = data.pending;
                    })
                    .finally(() => { if (pending) setTimeout(poll, 3000); });
            }
            setTimeout(poll, 3000);
        })();
    </script>
<?php include 'admin_footer.php'; ?>
