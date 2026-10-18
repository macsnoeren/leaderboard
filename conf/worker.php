<?php
/**
 * Worker-service (worker-service/README.md): wachtrij (worker_jobs), rechten op taken en downloads,
 * backups in de opslag en de onderhoudsmodus tijdens een restore.
 *
 * De website zet taken in de wachtrij en controleert de rechten; de Python-worker voert ze uit. De
 * functies krijgen een PDO mee zodat ze met SQLite in het geheugen te testen zijn (tests/worker_test.php).
 * Gebruikt canAccessGame() (game.php) en logAudit() (auth.php); database.php laadt dit bestand voor de
 * onderhoudscheck, daarom geen require van game.php hier.
 */

const WORKER_JOB_TYPES = [
    'game_export' => 'Spel exporteren',
    'game_import' => 'Spel importeren',
    'report' => 'Rapport (PDF)',
    'backup' => 'Backup maken',
    'backup_upload' => 'Backup uploaden',
    'restore' => 'Backup terugzetten',
];
// Alleen voor beheerders: een import maakt een spel, de rest raakt de hele omgeving
const WORKER_ADMIN_JOBS = ['game_import', 'backup', 'backup_upload', 'restore'];
const WORKER_JOB_AUDIT = [
    'game_export' => 'GAME_EXPORT',
    'game_import' => 'GAME_IMPORT',
    'report' => 'REPORT_CREATE',
    'backup' => 'BACKUP_CREATE',
    'backup_upload' => 'BACKUP_UPLOAD',
    'restore' => 'BACKUP_RESTORE',
];
const WORKER_STATUS_LABELS = [
    'queued' => 'in wachtrij',
    'running' => 'bezig',
    'done' => 'klaar',
    'failed' => 'mislukt',
    'cancelled' => 'geannuleerd',
];
const WORKER_ACTIVE_SECONDS = 120;
// Backups van vóór de naamswijziging heetten zebrawave-backup-…: die blijven geldig
const BACKUP_NAME_PATTERN = '/^(operationroom|zebrawave)-backup-[A-Za-z0-9._-]+\.tar\.gz$/';
const BACKUP_TRIGGER_LABELS = [
    'scheduled' => 'automatisch',
    'manual' => 'handmatig',
    'pre_restore' => 'veiligheid',
    'uploaded' => 'geüpload',
];

// ---------------------------------------------------------------------------
// Opslag en onderhoudsmodus
// ---------------------------------------------------------------------------

/**
 * Map buiten de webroot met incoming/, jobs/, backups/ en tmp/ (STORAGE_DIR in config.php).
 */
function storageDir(): string {
    return rtrim(defined('STORAGE_DIR') ? STORAGE_DIR : __DIR__ . '/../storage', '/');
}

function maintenanceFile(): string {
    return storageDir() . '/maintenance.json';
}

/**
 * Onderhoudsmodus: de worker zet een backup terug. Een bestand en geen databasevlag, omdat de
 * database tijdens de restore wordt vervangen.
 */
function isMaintenance(): bool {
    return is_file(maintenanceFile());
}

/**
 * Inhoud van maintenance.json (step, since, error …); leeg als het bestand ontbreekt of kapot is.
 */
function maintenanceInfo(): array {
    $raw = @file_get_contents(maintenanceFile());
    $info = $raw === false ? null : json_decode($raw, true);
    return is_array($info) ? $info : [];
}

/**
 * Antwoord tijdens onderhoud (stopt het script): JSON voor api.php, een lege 503 voor polling
 * (?ajax…), anders een pagina die zichzelf ververst. Raakt de database niet.
 */
function maintenanceResponse(): void {
    http_response_code(503);
    header('Retry-After: 10');
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'maintenance']);
        exit;
    }
    foreach (array_keys($_GET) as $key) {
        if (strncmp((string)$key, 'ajax', 4) === 0) {
            exit;
        }
    }
    require_once __DIR__ . '/theme.php';
    $info = maintenanceInfo();
    echo authPageStart('Even geduld', [
        'icon' => 'clock',
        'subtitle' => 'Er wordt een backup teruggezet. De pagina ververst vanzelf.',
        'css' => '<meta http-equiv="refresh" content="5">',
    ]);
    echo '<p role="status">' . htmlspecialchars((string)($info['step'] ?? 'Onderhoud')) . '</p>';
    if (!empty($info['error'])) {
        echo alertBox('error', 'Het terugzetten is mislukt; een beheerder moet dit op de server oplossen (zie de log van de worker).');
    }
    echo authPageEnd();
    exit;
}

// ---------------------------------------------------------------------------
// Wachtrij
// ---------------------------------------------------------------------------

function jobTypeLabel(string $type): string {
    return WORKER_JOB_TYPES[$type] ?? $type;
}

function jobParams(array $job): array {
    $params = json_decode((string)($job['params_json'] ?? ''), true);
    return is_array($params) ? $params : [];
}

/**
 * Korte omschrijving van wat de taak doet, voor de takenlijst (platte tekst).
 */
function jobDescription(array $job): string {
    $p = jobParams($job);
    switch ($job['type']) {
        case 'game_export':
            return !empty($p['include_progress']) ? 'Met teams en voortgang' : 'Spelinformatie en opdrachten';
        case 'game_import':
            return trim(($p['original_name'] ?? 'archief') . (!empty($p['name']) ? ' → ' . $p['name'] : ''));
        case 'report':
            $labels = ['answers' => 'goedgekeurde antwoorden', 'teams' => 'verloop per team'];
            $parts = array_map(function ($s) use ($labels) { return $labels[$s] ?? $s; }, (array)($p['sections'] ?? array_keys($labels)));
            return ucfirst(implode(' en ', $parts));
        case 'backup':
            return ucfirst(backupTriggerLabel((string)($p['trigger'] ?? 'manual')));
        case 'backup_upload':
            return (string)($p['original_name'] ?? 'backup');
        case 'restore':
            return (string)($p['backup'] ?? $p['original_name'] ?? 'backup');
    }
    return '';
}

/**
 * Zit er ergens (ook genest) een sleutel met 'password' in? Die horen nooit in params_json.
 */
function workerParamsContainSecret(array $params): bool {
    foreach ($params as $key => $value) {
        if (stripos((string)$key, 'password') !== false || stripos((string)$key, 'token') !== false) {
            return true;
        }
        if (is_array($value) && workerParamsContainSecret($value)) {
            return true;
        }
    }
    return false;
}

/**
 * Zet een taak in de wachtrij, met een auditregel. Geeft het id terug. Rechten controleert de pagina
 * (requireAdmin()/canAccessGame()); hier alleen de vorm.
 */
function enqueueJob(PDO $db, string $type, ?int $gameId, array $params, ?int $userId, ?string $description = null): int {
    if (!isset(WORKER_JOB_TYPES[$type])) {
        throw new InvalidArgumentException("Onbekend soort taak: $type");
    }
    if (workerParamsContainSecret($params)) {
        throw new InvalidArgumentException('Parameters van een taak mogen geen wachtwoorden of tokens bevatten');
    }
    $db->prepare("INSERT INTO worker_jobs (type, game_id, params_json, status, progress, created_by, created_at) VALUES (?, ?, ?, 'queued', 0, ?, datetime('now'))")
       ->execute([$type, $gameId, json_encode($params, JSON_UNESCAPED_UNICODE), $userId]);
    $jobId = (int)$db->lastInsertId();
    logAudit($db, $userId, WORKER_JOB_AUDIT[$type], ($description ?? jobTypeLabel($type)) . " (taak #$jobId" . ($gameId ? ", spel #$gameId" : '') . ')');
    return $jobId;
}

/**
 * Mag deze gebruiker de taak zien? Admins alles; docenten alleen niet-beheertaken van hun eigen spellen.
 */
function jobVisible(PDO $db, array $job, array $user): bool {
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    return !in_array($job['type'], WORKER_ADMIN_JOBS, true) && $job['game_id'] !== null
        && canAccessGame($db, $user, (int)$job['game_id']);
}

function findJobForUser(PDO $db, int $jobId, array $user): ?array {
    $stmt = $db->prepare("SELECT * FROM worker_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job && jobVisible($db, $job, $user) ? $job : null;
}

/**
 * Taken van het actieve spel (admins: plus de taken zonder spel, zoals backups), nieuwste eerst.
 */
function jobsForUser(PDO $db, array $user, ?int $gameId, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    if (($user['role'] ?? '') === 'admin') {
        $stmt = $db->prepare("SELECT * FROM worker_jobs WHERE game_id = ? OR game_id IS NULL ORDER BY id DESC LIMIT $limit");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($gameId === null || !canAccessGame($db, $user, $gameId)) {
        return [];
    }
    $admin = implode(', ', array_map(function ($t) { return "'$t'"; }, WORKER_ADMIN_JOBS));
    $stmt = $db->prepare("SELECT * FROM worker_jobs WHERE game_id = ? AND type NOT IN ($admin) ORDER BY id DESC LIMIT $limit");
    $stmt->execute([$gameId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Absoluut pad van het resultaat, of null als er geen (meer) is of het buiten de opslag zou liggen.
 */
function jobResultPath(array $job): ?string {
    $rel = (string)($job['result_file'] ?? '');
    if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') {
        return null;
    }
    $path = storageDir() . '/' . $rel;
    return is_file($path) ? $path : null;
}

/**
 * Downloaden mag wie de taak mag zien, als ze klaar is en er een resultaat is.
 */
function jobDownloadAllowed(PDO $db, array $job, array $user): bool {
    return $job['status'] === 'done' && !empty($job['result_file']) && jobVisible($db, $job, $user);
}

/**
 * Annuleren kan alleen zolang de taak in de wachtrij staat, door de aanvrager of een admin.
 */
function cancelJob(PDO $db, int $jobId, array $user): bool {
    $job = findJobForUser($db, $jobId, $user);
    if (!$job || $job['status'] !== 'queued') {
        return false;
    }
    if (($user['role'] ?? '') !== 'admin' && (int)$job['created_by'] !== (int)$user['id']) {
        return false;
    }
    $stmt = $db->prepare("UPDATE worker_jobs SET status = 'cancelled', finished_at = datetime('now') WHERE id = ? AND status = 'queued'");
    $stmt->execute([$jobId]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }
    logAudit($db, (int)$user['id'], 'JOB_CANCEL', jobTypeLabel($job['type']) . " geannuleerd (taak #$jobId)");
    return true;
}

/**
 * Draait er een worker (heartbeat van de laatste WORKER_ACTIVE_SECONDS seconden)?
 */
function workerActive(PDO $db): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM worker_status WHERE last_heartbeat > datetime('now', ?)");
    $stmt->execute(['-' . WORKER_ACTIVE_SECONDS . ' seconds']);
    return $stmt->fetchColumn() > 0;
}

function hasPendingJobs(array $jobs): bool {
    foreach ($jobs as $job) {
        if (in_array($job['status'], ['queued', 'running'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Status als badge, altijd met tekst.
 */
function jobStatusBadge(string $status): string {
    $class = ['queued' => '', 'running' => 'badge-info', 'done' => 'badge-success', 'failed' => 'badge-danger', 'cancelled' => ''][$status] ?? '';
    $icon = ['queued' => 'clock', 'running' => 'refresh', 'done' => 'check', 'failed' => 'alert-circle', 'cancelled' => 'x'][$status] ?? 'info';
    return '<span class="badge ' . $class . '">' . icon($icon) . htmlspecialchars(WORKER_STATUS_LABELS[$status] ?? $status) . '</span>';
}

function formatBytes(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes;
    foreach ($units as $unit) {
        $value /= 1024;
        if ($value < 1024) {
            return number_format($value, $value < 10 ? 1 : 0, ',', '.') . ' ' . $unit;
        }
    }
    return number_format($value, 0, ',', '.') . ' TB';
}

/**
 * Zoveel dagen bewaart de worker exports en rapporten (RESULT_TTL_DAYS, gelijk aan worker-service/config.py).
 */
function resultTtlDays(): int {
    return defined('RESULT_TTL_DAYS') ? (int)RESULT_TTL_DAYS : 7;
}

/**
 * Grootste upload voor import/restore in MB (MAX_ARCHIVE_MB in config.php, standaard 500).
 */
function maxArchiveMb(): int {
    return defined('MAX_ARCHIVE_MB') ? (int)MAX_ARCHIVE_MB : 500;
}

/**
 * Bewaart een geüpload archief (.tar.gz/.tgz) met een willekeurige naam in incoming/. Geeft het pad
 * relatief aan de opslag terug, of ['error' => …].
 */
function storeArchiveUpload(array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        return ['error' => 'Het bestand is te groot (maximaal ' . maxArchiveMb() . ' MB).'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Het uploaden is mislukt.'];
    }
    $name = strtolower((string)($file['name'] ?? ''));
    if (!preg_match('/\.(tar\.gz|tgz)$/', $name)) {
        return ['error' => 'Kies een .tar.gz-bestand.'];
    }
    if ((int)$file['size'] > maxArchiveMb() * 1024 * 1024) {
        return ['error' => 'Het bestand is te groot (maximaal ' . maxArchiveMb() . ' MB).'];
    }
    $dir = storageDir() . '/incoming';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return ['error' => 'De opslagmap bestaat niet; draai bin/setup.sh.'];
    }
    $rel = 'incoming/' . bin2hex(random_bytes(16)) . '.tar.gz';
    if (!move_uploaded_file($file['tmp_name'], storageDir() . '/' . $rel)) {
        return ['error' => 'Het bestand kon niet worden opgeslagen.'];
    }
    return $rel;
}

// ---------------------------------------------------------------------------
// Backups (storage/backups/<naam>.tar.gz met <naam>.tar.gz.json ernaast)
// ---------------------------------------------------------------------------

function backupsDir(): string {
    return storageDir() . '/backups';
}

function isValidBackupName(string $name): bool {
    return (bool)preg_match(BACKUP_NAME_PATTERN, $name);
}

/**
 * Alle backups met hun metadata, nieuwste eerst. Een .json zonder archief en vreemde namen tellen niet mee.
 */
function listBackups(?string $dir = null): array {
    $dir = $dir ?? backupsDir();
    $list = [];
    foreach (glob($dir . '/*.tar.gz.json') ?: [] as $jsonFile) {
        $name = substr(basename($jsonFile), 0, -5);
        if (!isValidBackupName($name) || !is_file($dir . '/' . $name)) {
            continue;
        }
        $meta = json_decode((string)file_get_contents($jsonFile), true);
        if (!is_array($meta)) {
            continue;
        }
        $list[] = [
            'file' => $name,
            'trigger' => (string)($meta['trigger'] ?? 'manual'),
            'created_at' => (string)($meta['created_at'] ?? ''),
            'size' => (int)filesize($dir . '/' . $name),
            'counts' => is_array($meta['counts'] ?? null) ? $meta['counts'] : [],
            'pinned' => !empty($meta['pinned']),
            'app_version' => (string)($meta['app_version'] ?? ''),
            'warnings' => is_array($meta['warnings'] ?? null) ? $meta['warnings'] : [],
        ];
    }
    usort($list, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['file'], $a['file']); });
    return $list;
}

function findBackup(string $name, ?string $dir = null): ?array {
    foreach (listBackups($dir) as $backup) {
        if ($backup['file'] === $name) {
            return $backup;
        }
    }
    return null;
}

/**
 * Vastzetten: een vastgezette backup valt buiten elke bewaartermijn.
 */
function setBackupPinned(string $name, bool $pinned, ?string $dir = null): bool {
    $dir = $dir ?? backupsDir();
    if (!findBackup($name, $dir)) {
        return false;
    }
    $jsonFile = $dir . '/' . $name . '.json';
    $meta = json_decode((string)file_get_contents($jsonFile), true);
    $meta['pinned'] = $pinned;
    $tmp = $jsonFile . '.tmp';
    if (file_put_contents($tmp, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        return false;
    }
    return rename($tmp, $jsonFile);
}

function deleteBackup(string $name, ?string $dir = null): bool {
    $dir = $dir ?? backupsDir();
    if (!findBackup($name, $dir)) {
        return false;
    }
    @unlink($dir . '/' . $name . '.json');
    return @unlink($dir . '/' . $name);
}

function backupTriggerLabel(string $trigger): string {
    return BACKUP_TRIGGER_LABELS[$trigger] ?? $trigger;
}
