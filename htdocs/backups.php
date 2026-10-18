<?php
/**
 * Backups (worker-service/DESIGN.md §7–§8): lijst, nu een backup maken, downloaden, vastzetten,
 * verwijderen, uploaden en terugzetten. Alleen beheerders. Het werk doet de worker (wachtrij).
 */
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$admin = requireAdmin($db);
$adminId = (int)$admin['id'];

// Download van een backup (geen taak nodig: het bestand staat al in de opslag)
if (isset($_GET['download'])) {
    $backup = findBackup((string)$_GET['download']);
    if (!$backup) {
        http_response_code(404);
        exit('Backup niet gevonden.');
    }
    logAudit($db, $adminId, 'BACKUP_DOWNLOAD', "Backup {$backup['file']} gedownload");
    session_write_close();
    $path = backupsDir() . '/' . $backup['file'];
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $backup['file'] . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('backups.php');
    }
    $action = $_POST['action'];
    $name = (string)($_POST['backup'] ?? '');

    if ($action === 'create') {
        enqueueJob($db, 'backup', null, ['trigger' => 'manual'], $adminId, 'Handmatige backup');
        flash('success', 'De backup staat in de wachtrij.');
        redirect('jobs.php');
    }

    if ($action === 'upload') {
        $stored = storeArchiveUpload($_FILES['archive'] ?? []);
        if (is_array($stored)) {
            flash('error', $stored['error']);
            redirect('backups.php');
        }
        $original = basename((string)($_FILES['archive']['name'] ?? 'backup.tar.gz'));
        enqueueJob($db, 'backup_upload', null, ['upload' => $stored, 'original_name' => $original], $adminId, "Backup '$original' geüpload");
        flash('success', 'De backup wordt gecontroleerd. Daarna staat hij in de lijst.');
        redirect('jobs.php');
    }

    $backup = findBackup($name);
    if (!$backup) {
        flash('error', 'Backup niet gevonden.');
        redirect('backups.php');
    }

    if ($action === 'pin' || $action === 'unpin') {
        $pinned = $action === 'pin';
        if (setBackupPinned($name, $pinned)) {
            logAudit($db, $adminId, 'BACKUP_PIN', "Backup $name " . ($pinned ? 'vastgezet' : 'losgemaakt'));
            flash('success', $pinned ? 'De backup is vastgezet: hij wordt nooit automatisch verwijderd.' : 'De backup valt weer onder de bewaartermijn.');
        } else {
            flash('error', 'Dat is niet gelukt.');
        }
    } elseif ($action === 'delete') {
        if (deleteBackup($name)) {
            logAudit($db, $adminId, 'BACKUP_DELETE', "Backup $name verwijderd");
            flash('success', 'De backup is verwijderd.');
        } else {
            flash('error', 'De backup kon niet worden verwijderd.');
        }
    } elseif ($action === 'restore') {
        // Opnieuw het eigen wachtwoord: een restore vervangt alles. Fout wachtwoord telt mee voor de blokkade.
        $scope = 'restore';
        if (isThrottled($db, $scope, (string)$adminId, 5, 15)) {
            flash('error', THROTTLE_ERROR);
        } elseif (!password_verify((string)($_POST['password'] ?? ''), (string)$admin['password_hash'])) {
            recordAttempt($db, $scope, (string)$adminId);
            flash('error', 'Het wachtwoord klopt niet; er is niets teruggezet.');
        } else {
            clearAttempts($db, $scope, (string)$adminId);
            enqueueJob($db, 'restore', null, ['backup' => $name, 'username' => teacherLabel($admin)], $adminId, "Restore van backup $name aangevraagd");
            flash('success', 'De restore staat in de wachtrij. De site gaat zo in onderhoudsmodus en is daarna weer bereikbaar.');
            redirect('jobs.php');
        }
    }
    redirect('backups.php');
}

$backups = listBackups();
$totalSize = array_sum(array_column($backups, 'size'));
$free = @disk_free_space(backupsDir());
$lowSpace = $free !== false && $free < 500 * 1024 * 1024;
$active = workerActive($db);

$pageTitle = 'Backups';
$extraCSS = '<style>
    .counts { font-size: 13px; color: var(--text-muted); }
    .backup-name { font-family: var(--font-mono); font-size: 13px; overflow-wrap: anywhere; }
    .restore-summary { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; font-size: 14px; margin-bottom: 16px; }
    .restore-summary dt { color: var(--text-muted); }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Backups', '<span>Database, bijlagen en logo\'s van alle spellen</span>',
        '<form method="POST" class="inline">' . csrfField() . '<input type="hidden" name="action" value="create"><button type="submit" class="btn btn-primary">' . icon('save') . 'Backup nu maken</button></form>') ?>

    <?= renderFlash() ?>

    <?php if (!$active): ?>
        <?= alertBox('warning', 'Er draait op dit moment geen worker. Nieuwe backups, uploads en restores blijven in de wachtrij staan tot de worker-service weer draait.') ?>
    <?php endif; ?>
    <?php if ($lowSpace): ?>
        <?= alertBox('warning', 'Weinig vrije schijfruimte (' . formatBytes((int)$free) . '). De nachtelijke backup wordt overgeslagen als er te weinig ruimte is.') ?>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <div class="card-title"><?= icon('archive') ?>Bewaarde backups</div>
            <span class="muted text-sm"><?= count($backups) ?> backups, samen <?= formatBytes((int)$totalSize) ?><?= $free !== false ? ' · ' . formatBytes((int)$free) . ' vrij' : '' ?></span>
        </div>
        <p class="muted text-sm" style="margin-bottom: 16px;">Elke nacht maakt de worker automatisch een backup; de laatste 14 blijven bewaard. Handmatige en geüploade backups blijven 90 dagen, veiligheidsbackups (vóór een restore) 30 dagen. De nieuwste en vastgezette backups worden nooit automatisch verwijderd. Een backup bevat ook wachtwoordhashes en API-sleutels: bewaar een download veilig.</p>
        <?php if (empty($backups)): ?>
            <?= emptyState('archive', 'Nog geen backups.') ?>
        <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Gemaakt</th>
                        <th scope="col">Soort</th>
                        <th scope="col">Inhoud</th>
                        <th scope="col">Grootte</th>
                        <th scope="col" class="col-actions">Actie</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $i => $b):
                        $c = $b['counts']; ?>
                        <tr>
                            <td class="nowrap"><?= formatDateTime($b['created_at']) ?><div class="backup-name muted"><?= htmlspecialchars($b['file']) ?></div></td>
                            <td>
                                <span class="badge"><?= htmlspecialchars(backupTriggerLabel($b['trigger'])) ?></span>
                                <?php if ($b['pinned']): ?><span class="badge badge-brand"><?= icon('lock') ?>vastgezet</span><?php endif; ?>
                                <?php if ($b['warnings']): ?><span class="badge badge-warning" title="<?= htmlspecialchars(implode("\n", $b['warnings'])) ?>"><?= icon('alert') ?><?= count($b['warnings']) ?> waarschuwing(en)</span><?php endif; ?>
                            </td>
                            <td class="counts"><?= (int)($c['games'] ?? 0) ?> spellen, <?= (int)($c['teams'] ?? 0) ?> teams, <?= (int)($c['files'] ?? 0) ?> bestanden</td>
                            <td class="nowrap"><?= formatBytes($b['size']) ?></td>
                            <td class="col-actions">
                                <div class="btn-row">
                                    <a href="backups.php?download=<?= urlencode($b['file']) ?>" class="btn btn-sm btn-secondary"><?= icon('download') ?>Download</a>
                                    <button type="button" class="btn btn-sm btn-secondary" data-open="restore-<?= $i ?>"><?= icon('rotate-ccw') ?>Terugzetten…</button>
                                    <form method="POST" class="inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="<?= $b['pinned'] ? 'unpin' : 'pin' ?>">
                                        <input type="hidden" name="backup" value="<?= htmlspecialchars($b['file']) ?>">
                                        <button type="submit" class="btn btn-sm btn-ghost"><?= $b['pinned'] ? icon('unlock') . 'Losmaken' : icon('lock') . 'Vastzetten' ?></button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('Deze backup definitief verwijderen?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="backup" value="<?= htmlspecialchars($b['file']) ?>">
                                        <button type="submit" class="btn btn-sm btn-ghost btn-icon" aria-label="Verwijderen" title="Verwijderen"><?= icon('trash') ?></button>
                                    </form>
                                </div>
                                <dialog class="modal" id="restore-<?= $i ?>" aria-labelledby="restore-title-<?= $i ?>">
                                    <form method="POST">
                                        <div class="modal-header">
                                            <h2 id="restore-title-<?= $i ?>">Backup terugzetten</h2>
                                            <button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Sluiten"><?= icon('x') ?></button>
                                        </div>
                                        <div class="modal-body">
                                            <dl class="restore-summary">
                                                <dt>Gemaakt</dt><dd><?= formatDateTime($b['created_at']) ?> (<?= htmlspecialchars(backupTriggerLabel($b['trigger'])) ?>)</dd>
                                                <dt>Inhoud</dt><dd><?= (int)($c['games'] ?? 0) ?> spellen, <?= (int)($c['teams'] ?? 0) ?> teams, <?= (int)($c['assignments'] ?? 0) ?> opdrachten, <?= (int)($c['files'] ?? 0) ?> bestanden</dd>
                                                <dt>App-versie</dt><dd class="mono"><?= htmlspecialchars($b['app_version'] ?: 'onbekend') ?></dd>
                                            </dl>
                                            <?= alertBox('warning', '<strong>Alles wordt vervangen</strong>: alle spellen, teams, berichten, gebruikers, bijlagen en logo\'s gaan terug naar de stand van deze backup. Wat daarna is veranderd, gaat verloren. Er wordt eerst automatisch een veiligheidsbackup gemaakt. Tijdens het terugzetten is de site even in onderhoud; daarna kun je uitgelogd zijn, en de AI-agent moet mogelijk opnieuw starten.') ?>
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="backup" value="<?= htmlspecialchars($b['file']) ?>">
                                            <div class="field" style="margin-top: 16px;">
                                                <label for="restore-pw-<?= $i ?>">Bevestig met je wachtwoord</label>
                                                <input type="password" name="password" id="restore-pw-<?= $i ?>" autocomplete="current-password" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-close>Annuleren</button>
                                            <button type="submit" class="btn btn-danger-solid"><?= icon('rotate-ccw') ?>Terugzetten</button>
                                        </div>
                                    </form>
                                </dialog>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><?= icon('inbox') ?>Backup uploaden</div>
        <form method="POST" enctype="multipart/form-data" class="btn-row">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload">
            <input type="file" name="archive" accept=".gz,.tgz,application/gzip" required aria-label="Backup (.tar.gz)" style="flex: 1; width: auto; min-width: 220px;">
            <button type="submit" class="btn btn-secondary"><?= icon('plus') ?>Uploaden</button>
        </form>
        <p class="muted small" style="margin-top: 10px;">Bijvoorbeeld een backup van een andere installatie (max. <?= maxArchiveMb() ?> MB). De worker controleert hem eerst; daarna kun je hem terugzetten.</p>
    </div>
<?php include 'admin_footer.php'; ?>
