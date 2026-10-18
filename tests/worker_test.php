<?php
/**
 * Tests voor conf/worker.php. Geen framework: stopt met exitcode 1 bij een fout.
 * Uitvoeren: docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/worker_test.php
 */

$storage = sys_get_temp_dir() . '/worker_test_' . bin2hex(random_bytes(4));
mkdir($storage . '/backups', 0777, true);
define('STORAGE_DIR', $storage);

require_once __DIR__ . '/../conf/game.php';
require_once __DIR__ . '/../conf/worker.php';

$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    echo ($ok ? '  ok   ' : '  FOUT ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

function freshDb(): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE teachers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, role TEXT DEFAULT 'user')");
    $db->exec("CREATE TABLE games (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', join_code TEXT UNIQUE)");
    $db->exec("CREATE TABLE game_teachers (game_id INTEGER NOT NULL, teacher_id INTEGER NOT NULL, PRIMARY KEY (game_id, teacher_id))");
    $db->exec("CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE worker_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, game_id INTEGER, params_json TEXT, status TEXT NOT NULL DEFAULT 'queued', progress INTEGER DEFAULT 0, message TEXT, result_file TEXT, result_name TEXT, result_size INTEGER, result_game_id INTEGER, created_by INTEGER, created_at DATETIME, started_at DATETIME, heartbeat_at DATETIME, finished_at DATETIME, expires_at DATETIME, worker_id TEXT)");
    $db->exec("CREATE TABLE worker_status (worker_id TEXT PRIMARY KEY, version TEXT, last_heartbeat DATETIME, current_job_id INTEGER)");
    $db->exec("INSERT INTO teachers (id, username, role) VALUES (1, 'admin', 'admin'), (2, 'docent', 'user'), (3, 'ander', 'user')");
    $db->exec("INSERT INTO games (id, name, join_code) VALUES (1, 'Spel A', 'AAAAAA'), (2, 'Spel B', 'BBBBBB')");
    $db->exec("INSERT INTO game_teachers (game_id, teacher_id) VALUES (1, 2), (2, 3)");
    return $db;
}

$admin = ['id' => 1, 'role' => 'admin'];
$docent = ['id' => 2, 'role' => 'user'];
$ander = ['id' => 3, 'role' => 'user'];

function throws(callable $fn): bool {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}

echo "enqueueJob\n";
$db = freshDb();
$job = enqueueJob($db, 'game_export', 1, ['game_id' => 1, 'include_progress' => false], 2);
$row = $db->query("SELECT * FROM worker_jobs WHERE id = $job")->fetch(PDO::FETCH_ASSOC);
check('taak staat in de wachtrij met created_at en aanvrager', $row['status'] === 'queued' && $row['created_at'] !== null && (int)$row['created_by'] === 2);
check('auditregel GAME_EXPORT met user_id', (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'GAME_EXPORT' AND user_id = 2")->fetchColumn() === 1);
check('onbekend type wordt geweigerd', throws(function () use ($db) { enqueueJob($db, 'rm_rf', null, [], 1); }));
check('params met een wachtwoord worden geweigerd', throws(function () use ($db) { enqueueJob($db, 'restore', null, ['backup' => 'x', 'password' => 'geheim'], 1); }));
check('ook genest', throws(function () use ($db) { enqueueJob($db, 'restore', null, ['opties' => ['admin_password' => 'x']], 1); }));
check('geen enkele params_json bevat "password"', (int)$db->query("SELECT COUNT(*) FROM worker_jobs WHERE params_json LIKE '%password%'")->fetchColumn() === 0);

echo "zichtbaarheid en downloaden\n";
$db = freshDb();
$export = enqueueJob($db, 'game_export', 1, ['game_id' => 1], 2);
$backup = enqueueJob($db, 'backup', null, ['trigger' => 'manual'], 1);
$import = enqueueJob($db, 'game_import', null, ['upload' => 'incoming/x.tar.gz'], 1);
check('docent ziet de export van zijn spel', findJobForUser($db, $export, $docent) !== null);
check('docent zonder toegang tot het spel ziet de taak niet', findJobForUser($db, $export, $ander) === null);
check('docent ziet geen backup', findJobForUser($db, $backup, $docent) === null);
check('docent ziet geen import', findJobForUser($db, $import, $docent) === null);
check('admin ziet alles', findJobForUser($db, $backup, $admin) !== null && findJobForUser($db, $export, $admin) !== null);
$db->exec("UPDATE worker_jobs SET status = 'done', result_file = 'jobs/$export/x.tar.gz' WHERE id = $export");
$db->exec("UPDATE worker_jobs SET status = 'done', result_file = 'backups/x.tar.gz' WHERE id = $backup");
$rows = $db->query("SELECT * FROM worker_jobs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$byId = array_column($rows, null, 'id');
check('docent mag zijn export downloaden', jobDownloadAllowed($db, $byId[$export], $docent));
check('docent zonder toegang mag niet downloaden', !jobDownloadAllowed($db, $byId[$export], $ander));
check('docent mag geen backup downloaden', !jobDownloadAllowed($db, $byId[$backup], $docent));
check('admin mag de backup downloaden', jobDownloadAllowed($db, $byId[$backup], $admin));
check('niet klaar → geen download', !jobDownloadAllowed($db, $byId[$import], $admin));
check('jobsForUser docent: alleen spel 1, geen beheertaken', array_column(jobsForUser($db, $docent, 1), 'id') == [$export]);
check('jobsForUser docent zonder toegang: niets', jobsForUser($db, $ander, 1) === []);
check('jobsForUser admin: spel 1 plus taken zonder spel', array_map('intval', array_column(jobsForUser($db, $admin, 1), 'id')) === [$import, $backup, $export]);
check('jobResultPath weigert ..', jobResultPath(['result_file' => '../etc/passwd']) === null);

echo "cancelJob\n";
$db = freshDb();
$queued = enqueueJob($db, 'report', 1, ['game_id' => 1], 2);
$running = enqueueJob($db, 'report', 1, ['game_id' => 1], 2);
$db->exec("UPDATE worker_jobs SET status = 'running' WHERE id = $running");
check('een running-taak annuleren lukt niet', !cancelJob($db, $running, $docent));
check('ander kan niet annuleren', !cancelJob($db, $queued, $ander));
check('aanvrager annuleert zijn taak in de wachtrij', cancelJob($db, $queued, $docent));
check('status cancelled + audit', $db->query("SELECT status FROM worker_jobs WHERE id = $queued")->fetchColumn() === 'cancelled'
    && (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'JOB_CANCEL'")->fetchColumn() === 1);
check('twee keer annuleren lukt niet', !cancelJob($db, $queued, $docent));

echo "workerActive\n";
$db = freshDb();
check('zonder heartbeat inactief', !workerActive($db));
$db->exec("INSERT INTO worker_status (worker_id, last_heartbeat) VALUES ('w1', datetime('now', '-119 seconds'))");
check('heartbeat van 119 s geleden → actief', workerActive($db));
$db->exec("UPDATE worker_status SET last_heartbeat = datetime('now', '-121 seconds')");
check('heartbeat van 121 s geleden → inactief', !workerActive($db));

echo "listBackups\n";
$dir = STORAGE_DIR . '/backups';
function writeBackup(string $dir, string $name, array $meta, bool $archive = true): void {
    if ($archive) {
        file_put_contents("$dir/$name", 'tar');
    }
    file_put_contents("$dir/$name.json", json_encode($meta));
}
writeBackup($dir, 'zebrawave-backup-20261001-030000.tar.gz', ['trigger' => 'scheduled', 'created_at' => '2026-10-01 01:00:00']);
writeBackup($dir, 'zebrawave-backup-20261003-120000.tar.gz', ['trigger' => 'manual', 'created_at' => '2026-10-03 10:00:00', 'counts' => ['games' => 2]]);
writeBackup($dir, 'zebrawave-backup-20261002-030000.tar.gz', ['trigger' => 'scheduled', 'created_at' => '2026-10-02 01:00:00']);
writeBackup($dir, 'zebrawave-backup-20261004-030000.tar.gz', ['trigger' => 'scheduled', 'created_at' => '2026-10-04 01:00:00'], false);
file_put_contents("$dir/geen-backup.tar.gz", 'x');
file_put_contents("$dir/geen-backup.tar.gz.json", '{}');
$list = listBackups($dir);
check('gesorteerd op datum, nieuwste eerst', array_column($list, 'file') === ['zebrawave-backup-20261003-120000.tar.gz', 'zebrawave-backup-20261002-030000.tar.gz', 'zebrawave-backup-20261001-030000.tar.gz']);
check('.json zonder archief telt niet mee', findBackup('zebrawave-backup-20261004-030000.tar.gz', $dir) === null);
check('vreemde naam telt niet mee', findBackup('geen-backup.tar.gz', $dir) === null);
check('pad met / wordt geweigerd', findBackup('../backups/zebrawave-backup-20261001-030000.tar.gz', $dir) === null && !isValidBackupName('a/zebrawave-backup-1.tar.gz'));
check('tellingen en grootte', $list[0]['counts'] === ['games' => 2] && $list[0]['size'] === 3);
check('vastzetten', setBackupPinned('zebrawave-backup-20261001-030000.tar.gz', true, $dir) && findBackup('zebrawave-backup-20261001-030000.tar.gz', $dir)['pinned'] === true);
check('losmaken', setBackupPinned('zebrawave-backup-20261001-030000.tar.gz', false, $dir) && findBackup('zebrawave-backup-20261001-030000.tar.gz', $dir)['pinned'] === false);
check('verwijderen haalt archief en .json weg', deleteBackup('zebrawave-backup-20261002-030000.tar.gz', $dir)
    && !is_file("$dir/zebrawave-backup-20261002-030000.tar.gz") && !is_file("$dir/zebrawave-backup-20261002-030000.tar.gz.json"));
check('onbekende backup verwijderen lukt niet', !deleteBackup('zebrawave-backup-19990101-000000.tar.gz', $dir));

echo "onderhoudsmodus\n";
check('zonder bestand geen onderhoud', !isMaintenance() && maintenanceInfo() === []);
file_put_contents(maintenanceFile(), json_encode(['step' => 'Database vervangen']));
check('met bestand onderhoud, met stap', isMaintenance() && maintenanceInfo()['step'] === 'Database vervangen');
file_put_contents(maintenanceFile(), '{kapot');
check('kapot JSON-bestand telt ook als onderhoud', isMaintenance() && maintenanceInfo() === []);
unlink(maintenanceFile());
check('na verwijderen geen onderhoud', !isMaintenance());

// Opruimen
foreach (glob("$dir/*") as $f) {
    unlink($f);
}
rmdir($dir);
rmdir(STORAGE_DIR);

if ($failures > 0) {
    echo "\n$failures test(s) mislukt\n";
    exit(1);
}
echo "\nAlle tests geslaagd\n";
