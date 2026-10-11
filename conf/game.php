<?php
/**
 * Spellen: status, levels, teams en opdrachten per spel.
 *
 * De functies krijgen een PDO mee en gebruiken geen sessie of mail (behalve requireGame()),
 * zodat ze met SQLite in het geheugen te testen zijn (tests/game_test.php).
 * Tijden zijn UTC; vergelijk en reken in SQL.
 *
 * Levels: 0 = nog niet gestart, 1..N = bezig met opdracht N, N+1 = Finish.
 */

require_once __DIR__ . '/auth.php';

const GAME_STATUS_LABELS = [
    'draft' => 'concept',
    'running' => 'bezig',
    'paused' => 'pauze',
    'finished' => 'afgelopen',
];
const GAME_STATUS_COLORS = [
    'draft' => '#888',
    'running' => '#4caf50',
    'paused' => '#ff9800',
    'finished' => '#667eea',
];
// Geen tekens die op elkaar lijken: 0/O, 1/I/L
const JOIN_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const JOIN_CODE_LENGTH = 6;
const TEAM_NAME_MAX_LENGTH = 50;
const LOGO_DIR = 'uploads';

// ---------------------------------------------------------------------------
// Spel ophalen, status en toegang
// ---------------------------------------------------------------------------

/**
 * SQL-expressie voor de effectieve status: een lopend of gepauzeerd spel is afgelopen zodra
 * de eindtijd verstreken is. Zo is er geen cronjob nodig.
 */
function effectiveStatusSql(string $alias = 'g'): string {
    return "(CASE WHEN $alias.status IN ('running', 'paused') AND $alias.ends_at IS NOT NULL AND $alias.ends_at <= datetime('now')"
        . " THEN 'finished' ELSE $alias.status END)";
}

/**
 * Kolommen die bij elk opgehaald spel horen: effectieve status en de tijden ten opzichte van nu,
 * berekend in SQL zodat een verkeerde klok bij een team niet uitmaakt.
 */
function gameSelectSql(string $alias = 'g'): string {
    return "$alias.*, " . effectiveStatusSql($alias) . " AS effective_status,"
        . " CASE WHEN $alias.ends_at IS NULL THEN NULL ELSE MAX(0, CAST(strftime('%s', $alias.ends_at) AS INTEGER) - CAST(strftime('%s', 'now') AS INTEGER)) END AS seconds_left,"
        . " CASE WHEN $alias.started_at IS NULL THEN NULL ELSE MAX(0, CAST(strftime('%s', 'now') AS INTEGER) - CAST(strftime('%s', $alias.started_at) AS INTEGER)) END AS seconds_elapsed";
}

function getGame(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT " . gameSelectSql() . " FROM games g WHERE g.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getGameByCode(PDO $db, string $code): ?array {
    $code = strtoupper(preg_replace('/\s+/', '', $code));
    if ($code === '') {
        return null;
    }
    $stmt = $db->prepare("SELECT " . gameSelectSql() . " FROM games g WHERE g.join_code = ?");
    $stmt->execute([$code]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Admins mogen elk spel besturen, docenten alleen de spellen waaraan ze gekoppeld zijn.
 */
function canAccessGame(PDO $db, array $user, int $gameId): bool {
    if (($user['role'] ?? '') === 'admin') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM game_teachers WHERE game_id = ? AND teacher_id = ?");
        $stmt->execute([$gameId, (int)$user['id']]);
    }
    return $stmt->fetchColumn() > 0;
}

/**
 * Alle spellen die deze gebruiker mag besturen, nieuwste eerst.
 */
function accessibleGames(PDO $db, array $user): array {
    if (($user['role'] ?? '') === 'admin') {
        return $db->query("SELECT " . gameSelectSql() . " FROM games g ORDER BY g.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $db->prepare("SELECT " . gameSelectSql() . " FROM games g JOIN game_teachers gt ON gt.game_id = g.id WHERE gt.teacher_id = ? ORDER BY g.id DESC");
    $stmt->execute([(int)$user['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Het actieve spel uit de sessie ($_SESSION['game_id']). Wordt bij elk verzoek opnieuw
 * gecontroleerd, zodat een ontkoppelde docent meteen de toegang verliest. Bij precies één
 * spel wordt dat gekozen; anders naar de keuzepagina (bij ?ajax… een lege 403).
 */
function requireGame(PDO $db, array $user): array {
    $gameId = (int)($_SESSION['game_id'] ?? 0);
    if ($gameId > 0 && canAccessGame($db, $user, $gameId)) {
        return getGame($db, $gameId);
    }
    unset($_SESSION['game_id']);

    $games = accessibleGames($db, $user);
    if (count($games) === 1) {
        $_SESSION['game_id'] = (int)$games[0]['id'];
        return $games[0];
    }
    denyRequest('choose_game.php', 403);
}

function gameStatusLabel(string $status): string {
    return GAME_STATUS_LABELS[$status] ?? $status;
}

function gameStatusBadge(string $status): string {
    $color = GAME_STATUS_COLORS[$status] ?? '#888';
    return '<span class="status-badge" style="display:inline-block; color:white; padding:2px 8px; border-radius:10px; font-size:0.75em; background:' . $color . ';">'
        . htmlspecialchars(gameStatusLabel($status)) . '</span>';
}

/**
 * Pad van het logo (relatief aan htdocs) als het bestand bestaat, anders null.
 */
function gameLogoUrl(array $game, ?string $htdocsDir = null): ?string {
    $file = $game['logo_file'] ?? '';
    if ($file === '' || $file === null) {
        return null;
    }
    $htdocsDir = $htdocsDir ?? __DIR__ . '/../htdocs';
    return is_file($htdocsDir . '/' . $file) ? $file : null;
}

/**
 * Startlevel voor een nieuw team: 0 bij een concept-spel, 1 bij een lopend of gepauzeerd spel,
 * null als er geen team meer bij kan (afgelopen).
 */
function initialLevel(array $game): ?int {
    $status = $game['effective_status'] ?? $game['status'];
    if ($status === 'draft') {
        return 0;
    }
    if ($status === 'running' || $status === 'paused') {
        return 1;
    }
    return null;
}

/**
 * Statusovergangen die vanuit de effectieve status mogen.
 */
function allowedGameActions(array $game): array {
    switch ($game['effective_status']) {
        case 'draft':
            return ['start'];
        case 'running':
            return ['pause', 'finish'];
        case 'paused':
            return ['resume', 'finish'];
        default:
            return [];
    }
}

/**
 * Start een concept-spel: status running en alle teams op level 1 met één gezamenlijke starttijd.
 */
function startGame(PDO $db, int $gameId, ?int $userId, ?string $message = null): bool {
    $db->beginTransaction();
    try {
        $now = $db->query("SELECT datetime('now')")->fetchColumn();
        $stmt = $db->prepare("UPDATE games SET status = 'running', started_at = ?, status_message = ? WHERE id = ? AND status = 'draft'");
        $stmt->execute([$now, $message, $gameId]);
        if ($stmt->rowCount() !== 1) {
            $db->rollBack();
            return false;
        }
        $teams = $db->prepare("UPDATE teams SET current_level = 1, level_updated_at = ? WHERE game_id = ? AND current_level = 0");
        $teams->execute([$now, $gameId]);
        logAudit($db, $userId, 'GAME_START', "Spel #$gameId gestart ({$teams->rowCount()} teams naar level 1)");
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Pauze, hervatten of beëindigen. Geeft false terug bij een ongeldige overgang.
 */
function changeGameStatus(PDO $db, int $gameId, string $action, ?string $message, ?int $userId): bool {
    $map = [
        'pause' => ['paused', 'GAME_PAUSE', 'gepauzeerd'],
        'resume' => ['running', 'GAME_RESUME', 'hervat'],
        'finish' => ['finished', 'GAME_FINISH', 'beëindigd'],
    ];
    $game = getGame($db, $gameId);
    if (!$game || !isset($map[$action]) || !in_array($action, allowedGameActions($game), true)) {
        return false;
    }
    [$status, $type, $verb] = $map[$action];
    $db->prepare("UPDATE games SET status = ?, status_message = ? WHERE id = ?")->execute([$status, $message, $gameId]);
    logAudit($db, $userId, $type, "Spel '{$game['name']}' (#$gameId) $verb");
    return true;
}

// ---------------------------------------------------------------------------
// Teams en levels
// ---------------------------------------------------------------------------

function totalAssignments(PDO $db, int $gameId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM assignments WHERE game_id = ?");
    $stmt->execute([$gameId]);
    return (int)$stmt->fetchColumn();
}

function findTeamInGame(PDO $db, int $teamId, int $gameId): ?array {
    $stmt = $db->prepare("SELECT * FROM teams WHERE id = ? AND game_id = ?");
    $stmt->execute([$teamId, $gameId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Teams van een spel in volgorde van het leaderboard.
 */
function rankedTeams(PDO $db, int $gameId): array {
    $stmt = $db->prepare("SELECT * FROM teams WHERE game_id = ? ORDER BY current_level DESC, level_updated_at ASC, team_name ASC");
    $stmt->execute([$gameId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function teamRank(PDO $db, array $team): int {
    $ids = array_map('intval', array_column(rankedTeams($db, (int)$team['game_id']), 'id'));
    $index = array_search((int)$team['id'], $ids, true);
    return $index === false ? 0 : $index + 1;
}

/**
 * Voortgang in procenten: (level − 1) / N, dus 0% bij level 0 en 1, 100% bij Finish.
 */
function progressPercent(int $level, int $total): float {
    if ($total <= 0) {
        return 0.0;
    }
    return min($total, max(0, $level - 1)) / $total * 100;
}

function levelLabel(int $level, int $total): string {
    if ($level <= 0) {
        return 'Start';
    }
    return $level > $total ? 'Finish' : (string)$level;
}

/**
 * Ongelezen teamberichten van het huidige level van elk team in het spel.
 */
function countUnread(PDO $db, int $gameId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM team_messages tm JOIN teams t ON t.id = tm.team_id
        WHERE t.game_id = ? AND tm.sender = 'team' AND tm.is_read = 0 AND tm.assignment_number = t.current_level");
    $stmt->execute([$gameId]);
    return (int)$stmt->fetchColumn();
}

function validateTeamName(string $name): ?string {
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length === 0) {
        return 'Vul een teamnaam in.';
    }
    if ($length > TEAM_NAME_MAX_LENGTH) {
        return 'Een teamnaam heeft maximaal ' . TEAM_NAME_MAX_LENGTH . ' tekens.';
    }
    return null;
}

function teamNameTaken(PDO $db, int $gameId, string $name): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM teams WHERE game_id = ? AND lower(team_name) = lower(?)");
    $stmt->execute([$gameId, $name]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Voegt een team toe aan een spel, met het startlevel van initialLevel(). Geeft het team terug,
 * of een foutmelding. Zonder $userId is het een zelfaanmelding (audit TEAM_REGISTER).
 */
function createTeam(PDO $db, array $game, string $name, ?string $email, ?int $userId) {
    $name = trim($name);
    $level = initialLevel($game);
    if ($level === null) {
        return 'Dit spel is afgelopen; er kunnen geen teams meer bij.';
    }
    if ($error = validateTeamName($name)) {
        return $error;
    }
    if (teamNameTaken($db, (int)$game['id'], $name)) {
        return 'Er is in dit spel al een team met deze naam. Kies een andere naam.';
    }
    try {
        $stmt = $db->prepare("INSERT INTO teams (game_id, team_name, email, current_level, level_updated_at, access_token) VALUES (?, ?, ?, ?, datetime('now'), ?)");
        $stmt->execute([(int)$game['id'], $name, $email, $level, bin2hex(random_bytes(32))]);
    } catch (PDOException $e) {
        // Unieke index: tegelijk dezelfde naam aangemeld
        return 'Er is in dit spel al een team met deze naam. Kies een andere naam.';
    }
    $teamId = (int)$db->lastInsertId();
    logAudit($db, $userId, $userId === null ? 'TEAM_REGISTER' : 'TEAM_ADD', "Team '$name' (ID $teamId) toegevoegd aan spel #{$game['id']} op level $level");
    return findTeamInGame($db, $teamId, (int)$game['id']);
}

/**
 * Verwijdert een team met zijn berichten en downloads. Het archief blijft (met teamnaam).
 */
function deleteTeam(PDO $db, int $teamId): void {
    foreach (['team_messages', 'download_tokens', 'team_downloads', 'team_presence'] as $table) {
        $db->prepare("DELETE FROM $table WHERE team_id = ?")->execute([$teamId]);
    }
    $db->prepare("DELETE FROM announcements WHERE team_id = ?")->execute([$teamId]);
    $db->prepare("DELETE FROM teams WHERE id = ?")->execute([$teamId]);
}

/**
 * Level omhoog: archiveert de chat van het huidige level (zonder AI-suggesties) met teamnaam,
 * verwijdert alleen de suggesties van dat level en verhoogt het level. De berichten zelf blijven
 * staan, zodat ze bij een level terug weer zichtbaar zijn. Stopt bij N+1 (Finish).
 */
function levelUp(PDO $db, int $teamId, ?int $userId): bool {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        $level = $team ? (int)$team['current_level'] : 0;
        $total = $team ? totalAssignments($db, (int)$team['game_id']) : 0;
        if (!$team || $level < 1 || $level > $total) {
            $db->rollBack();
            return false;
        }

        $msgs = $db->prepare("SELECT sender, message FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender != 'suggestion' ORDER BY created_at ASC, id ASC");
        $msgs->execute([$teamId, $level]);
        $history = [];
        foreach ($msgs->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $history[] = ($m['sender'] === 'team' ? 'Team: ' : 'Docent: ') . $m['message'];
        }
        $db->prepare("INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
           ->execute([(int)$team['game_id'], $teamId, $team['team_name'], $level, implode("\n", $history)]);

        $db->prepare("DELETE FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender = 'suggestion'")->execute([$teamId, $level]);
        $db->prepare("UPDATE teams SET current_level = ?, level_updated_at = datetime('now'), ai_processing_by = NULL, ai_processing_at = NULL WHERE id = ?")
           ->execute([$level + 1, $teamId]);

        logAudit($db, $userId, 'TEAM_LEVEL_UP', "Team '{$team['team_name']}' (ID $teamId) naar level " . levelLabel($level + 1, $total));
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Level terug (minimaal 1): verwijdert de nieuwste archiefregel van het level waar het team naar
 * teruggaat. De chat van dat level komt terug omdat de berichten bewaard zijn.
 */
function levelDown(PDO $db, int $teamId, ?int $userId): bool {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        $team = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$team || (int)$team['current_level'] <= 1) {
            $db->rollBack();
            return false;
        }
        $newLevel = (int)$team['current_level'] - 1;

        $db->prepare("DELETE FROM completed_assignments WHERE id = (SELECT id FROM completed_assignments WHERE team_id = ? AND assignment_number = ? ORDER BY id DESC LIMIT 1)")
           ->execute([$teamId, $newLevel]);
        $db->prepare("UPDATE teams SET current_level = ?, level_updated_at = datetime('now'), ai_processing_by = NULL, ai_processing_at = NULL WHERE id = ?")
           ->execute([$newLevel, $teamId]);

        logAudit($db, $userId, 'TEAM_LEVEL_DOWN', "Team '{$team['team_name']}' (ID $teamId) terug naar level $newLevel");
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Spelcode, tijden, leegmaken en kopiëren
// ---------------------------------------------------------------------------

/**
 * Unieke spelcode van JOIN_CODE_LENGTH tekens. $nextCandidate is alleen voor tests.
 */
function generateJoinCode(PDO $db, ?callable $nextCandidate = null): string {
    $nextCandidate = $nextCandidate ?? function (): string {
        $code = '';
        for ($i = 0; $i < JOIN_CODE_LENGTH; $i++) {
            $code .= JOIN_CODE_ALPHABET[random_int(0, strlen(JOIN_CODE_ALPHABET) - 1)];
        }
        return $code;
    };
    $exists = $db->prepare("SELECT COUNT(*) FROM games WHERE join_code = ?");
    do {
        $code = $nextCandidate();
        $exists->execute([$code]);
    } while ($exists->fetchColumn() > 0);
    return $code;
}

/**
 * Waarde van een datetime-local veld (lokale tijd) naar UTC voor de database, of null.
 */
function localInputToUtc(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $local = new DateTimeZone(date_default_timezone_get());
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value, $local) ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $value, $local);
    if (!$dt) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * UTC-tijd uit de database naar de waarde voor een datetime-local veld (lokale tijd).
 */
function utcToLocalInput(?string $utc): string {
    if (!$utc) {
        return '';
    }
    $time = strtotime($utc . ' UTC');
    return $time === false ? '' : date('Y-m-d\TH:i', $time);
}

/**
 * Maakt een spel leeg: teams met hun berichten, downloads en de mededelingen gaan weg; archief,
 * opdrachten en instellingen blijven. Het spel gaat terug naar concept.
 */
function resetGame(PDO $db, int $gameId, ?int $userId): void {
    $db->beginTransaction();
    try {
        $teamIds = "SELECT id FROM teams WHERE game_id = ?";
        foreach (['team_messages', 'download_tokens', 'team_downloads', 'team_presence'] as $table) {
            $db->prepare("DELETE FROM $table WHERE team_id IN ($teamIds)")->execute([$gameId]);
        }
        $db->prepare("DELETE FROM announcements WHERE game_id = ?")->execute([$gameId]);
        $teams = $db->prepare("DELETE FROM teams WHERE game_id = ?");
        $teams->execute([$gameId]);
        $db->prepare("UPDATE games SET status = 'draft', started_at = NULL, status_message = NULL WHERE id = ?")->execute([$gameId]);
        logAudit($db, $userId, 'GAME_RESET', "Spel #$gameId leeggemaakt ({$teams->rowCount()} teams verwijderd)");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Kopieert de opdrachten van een ander spel, met een eigen kopie van elk bestand. De nummering
 * loopt door na het hoogste bestaande nummer. Geeft het aantal gekopieerde opdrachten terug.
 */
function copyAssignments(PDO $db, int $fromGame, int $toGame, ?int $userId = null, ?string $htdocsDir = null): int {
    $htdocsDir = $htdocsDir ?? __DIR__ . '/../htdocs';
    $stmt = $db->prepare("SELECT * FROM assignments WHERE game_id = ? ORDER BY assignment_number ASC, id ASC");
    $stmt->execute([$fromGame]);
    $source = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$source || $fromGame === $toGame) {
        return 0;
    }

    $stmt = $db->prepare("SELECT COALESCE(MAX(assignment_number), 0) FROM assignments WHERE game_id = ?");
    $stmt->execute([$toGame]);
    $number = (int)$stmt->fetchColumn();

    $insert = $db->prepare("INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, time_limit, artifact_file) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $copied = [];
    $db->beginTransaction();
    try {
        foreach ($source as $a) {
            $number++;
            $file = null;
            if (!empty($a['artifact_file']) && is_file($htdocsDir . '/' . $a['artifact_file'])) {
                $ext = strtolower(pathinfo($a['artifact_file'], PATHINFO_EXTENSION));
                $file = 'artifacts/game_' . $toGame . '_assignment_' . $number . '_' . time() . '_' . bin2hex(random_bytes(3)) . ($ext !== '' ? ".$ext" : '');
                if (!copy($htdocsDir . '/' . $a['artifact_file'], $htdocsDir . '/' . $file)) {
                    throw new RuntimeException("Kopiëren van {$a['artifact_file']} mislukt");
                }
                $copied[] = $htdocsDir . '/' . $file;
            }
            $insert->execute([$toGame, $number, $a['title'], $a['description'], $a['instruction'], $a['criteria'], $a['time_limit'], $file]);
        }
        logAudit($db, $userId, 'ASSIGNMENTS_COPY', count($source) . " opdrachten gekopieerd van spel #$fromGame naar spel #$toGame");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        foreach ($copied as $path) {
            @unlink($path);
        }
        throw $e;
    }
    return count($source);
}

/**
 * Link naar het dashboard van een team.
 */
function teamDashboardUrl(string $token): string {
    return BASE_URL . '/team.php?token=' . $token;
}
