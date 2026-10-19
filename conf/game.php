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
// Geen tekens die op elkaar lijken: 0/O, 1/I/L
const JOIN_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const JOIN_CODE_LENGTH = 6;
const TEAM_NAME_MAX_LENGTH = 50;
const FINISH_MESSAGE_MAX_LENGTH = 5000;
const LOGO_DIR = 'uploads';
// Privacytekst op join.php en team.php (AVG, zie agentic-service/README.md)
const PRIVACY_NOTICE = 'Deel geen persoonsgegevens of andere gevoelige informatie in de chat (zoals adressen, telefoonnummers, wachtwoorden of gegevens over gezondheid). Berichten kunnen met hulp van AI worden verwerkt.';

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

/**
 * Status als badge (.badge-draft/-running/-paused/-finished); altijd met tekst, niet alleen kleur.
 */
function gameStatusBadge(string $status): string {
    $class = isset(GAME_STATUS_LABELS[$status]) ? $status : 'draft';
    return '<span class="badge badge-' . $class . '">' . ($class === 'running' ? '<span class="dot"></span>' : '')
        . htmlspecialchars(gameStatusLabel($status)) . '</span>';
}

/**
 * Subtitel voor pageHeader(): spelnaam met status-badge.
 */
function gameSubtitle(array $game): string {
    return '<span>' . htmlspecialchars($game['name']) . '</span>' . gameStatusBadge($game['effective_status']);
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

function findAssignmentInGame(PDO $db, int $id, int $gameId): ?array {
    $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ? AND game_id = ?");
    $stmt->execute([$id, $gameId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
 * Voegt een team toe aan een spel, met het startlevel van initialLevel() en de standaard AI-modus van
 * het spel (games.ai_mode). Geeft het team terug, of een foutmelding. Zonder $userId is het een
 * zelfaanmelding (audit TEAM_REGISTER).
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
    // Uit de database, niet uit $game: dat kan van vóór een wijziging van de standaard zijn
    $stmt = $db->prepare("SELECT ai_mode FROM games WHERE id = ?");
    $stmt->execute([(int)$game['id']]);
    $aiMode = $stmt->fetchColumn();
    $aiMode = isValidAiMode($aiMode) ? $aiMode : 'suggest';
    try {
        $stmt = $db->prepare("INSERT INTO teams (game_id, team_name, email, current_level, level_updated_at, access_token, ai_mode) VALUES (?, ?, ?, ?, datetime('now'), ?, ?)");
        $stmt->execute([(int)$game['id'], $name, $email, $level, bin2hex(random_bytes(32)), $aiMode]);
    } catch (PDOException $e) {
        // Unieke index: tegelijk dezelfde naam aangemeld
        return 'Er is in dit spel al een team met deze naam. Kies een andere naam.';
    }
    $teamId = (int)$db->lastInsertId();
    logAudit($db, $userId, $userId === null ? 'TEAM_REGISTER' : 'TEAM_ADD', "Team '$name' (ID $teamId) toegevoegd aan spel #{$game['id']} op level $level");
    return findTeamInGame($db, $teamId, (int)$game['id']);
}

/**
 * Verwijdert een team met zijn berichten, downloads en AI-gegevens. Het archief blijft (met teamnaam).
 */
function deleteTeam(PDO $db, int $teamId): void {
    foreach (['team_messages', 'download_tokens', 'team_downloads', 'team_presence', 'ai_team_progress', 'ai_evaluations'] as $table) {
        $db->prepare("DELETE FROM $table WHERE team_id = ?")->execute([$teamId]);
    }
    $db->prepare("DELETE FROM announcements WHERE team_id = ?")->execute([$teamId]);
    $db->prepare("DELETE FROM teams WHERE id = ?")->execute([$teamId]);
}

/**
 * Level omhoog: archiveert de chat van het huidige level (zonder AI-suggesties; AI-berichten als
 * "AI: …") met teamnaam, wie goedkeurde (docent of AI-beoordeling), het goedgekeurde antwoord (laatste
 * teambericht) en de starttijd van het level. Verwijdert alleen de suggesties van dat level, sluit open escalaties en
 * verhoogt het level. De berichten zelf blijven staan, zodat ze bij een level terug weer zichtbaar
 * zijn. Stopt bij N+1 (Finish). $userId null = de AI (submitEvaluation()).
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

        $msgs = $db->prepare("SELECT id, sender, message FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender != 'suggestion' ORDER BY created_at ASC, id ASC");
        $msgs->execute([$teamId, $level]);
        $history = [];
        $accepted = null; // laatste teambericht: het goedgekeurde antwoord
        foreach ($msgs->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $prefix = ['team' => 'Team: ', 'ai' => 'AI: '][$m['sender']] ?? 'Operator: ';
            $history[] = $prefix . $m['message'];
            if ($m['sender'] === 'team') {
                $accepted = $m;
            }
        }
        // Wie keurde goed: de docent (naam blijft na verwijderen), of de AI met de beoordeling die tot de level-up leidde
        $leveledByName = null;
        $evaluationId = null;
        if ($userId !== null) {
            $teacher = findTeacher($db, $userId);
            $leveledByName = $teacher ? teacherLabel($teacher + ['display_name' => null, 'username' => null, 'email' => null]) : null;
        } else {
            $stmt = $db->prepare("SELECT id FROM ai_evaluations WHERE team_id = ? AND assignment_number = ? AND decision = 'level_up' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$teamId, $level]);
            $evaluationId = $stmt->fetchColumn() ?: null;
        }
        $db->prepare("INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history, created_at,
                          leveled_by, leveled_by_name, ai_evaluation_id, accepted_message_id, accepted_answer, level_started_at)
                      VALUES (?, ?, ?, ?, ?, datetime('now'), ?, ?, ?, ?, ?, ?)")
           ->execute([(int)$team['game_id'], $teamId, $team['team_name'], $level, implode("\n", $history),
                      $userId, $leveledByName, $evaluationId === null ? null : (int)$evaluationId,
                      $accepted ? (int)$accepted['id'] : null, $accepted ? $accepted['message'] : null, $team['level_updated_at']]);

        $db->prepare("DELETE FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender = 'suggestion'")->execute([$teamId, $level]);
        resolveEscalations($db, $teamId, $level, $userId);
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
        resolveEscalations($db, $teamId, (int)$team['current_level'], $userId);

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
 * Datum (dd/mm/jjjj; ook - of . als scheiding) en tijd (uu:mm of uu.mm, 24 uur) in lokale tijd naar
 * UTC voor de database. Null als een van beide ontbreekt of ongeldig is (bijv. 31/02).
 */
function localDateTimeToUtc(string $date, string $time): ?string {
    if (!preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$~', trim($date), $d)
        || !preg_match('~^(\d{1,2})[:.](\d{2})$~', trim($time), $t)
        || !checkdate((int)$d[2], (int)$d[1], (int)$d[3]) || (int)$t[1] > 23 || (int)$t[2] > 59) {
        return null;
    }
    $local = new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:00', $d[3], $d[2], $d[1], $t[1], $t[2]), new DateTimeZone(date_default_timezone_get()));
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * UTC-tijd uit de database naar ['date' => 'dd/mm/jjjj', 'time' => 'uu:mm'] in lokale tijd; leeg zonder tijd.
 */
function utcToLocalParts(?string $utc): array {
    $time = $utc ? strtotime($utc . ' UTC') : false;
    return $time === false ? ['date' => '', 'time' => ''] : ['date' => date('d/m/Y', $time), 'time' => date('H:i', $time)];
}

/**
 * Eindtekst (Markdown) die teams zien als ze alle opdrachten hebben opgelost. Leeg = de standaardtekst.
 * Geeft een foutmelding terug, of null.
 */
function updateFinishMessage(PDO $db, int $gameId, string $text, ?int $userId): ?string {
    $text = trim(str_replace("\r\n", "\n", $text));
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > FINISH_MESSAGE_MAX_LENGTH) {
        return 'De eindtekst mag maximaal ' . FINISH_MESSAGE_MAX_LENGTH . ' tekens lang zijn.';
    }
    $game = getGame($db, $gameId);
    if (!$game) {
        return 'Dit spel bestaat niet.';
    }
    $db->prepare("UPDATE games SET finish_message = ? WHERE id = ?")->execute([$text === '' ? null : $text, $gameId]);
    logAudit($db, $userId, 'GAME_UPDATE', "Eindtekst van spel '{$game['name']}' (#$gameId) " . ($text === '' ? 'verwijderd' : 'gewijzigd'));
    return null;
}

/**
 * Maakt een spel leeg: teams met hun berichten, downloads, AI-gegevens en de mededelingen gaan weg;
 * archief, opdrachten en instellingen blijven. Het spel gaat terug naar concept.
 */
function resetGame(PDO $db, int $gameId, ?int $userId): void {
    $db->beginTransaction();
    try {
        $teamIds = "SELECT id FROM teams WHERE game_id = ?";
        foreach (['team_messages', 'download_tokens', 'team_downloads', 'team_presence', 'ai_team_progress', 'ai_evaluations'] as $table) {
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
 * Verwijdert een spel met alles erop en eraan: teams (met berichten, downloads, AI-gegevens), opdrachten
 * (met bijlagen en de dialoog van de opdracht-assistent), het archief, mededelingen, gekoppelde docenten,
 * het geüploade logo en de taken van de worker (met hun exports en rapporten). Backups blijven.
 * Niet bij een lopend of gepauzeerd spel, en niet zolang er een taak van de worker voor dit spel loopt.
 * Geeft null bij succes, anders een foutmelding.
 */
function deleteGame(PDO $db, int $gameId, ?int $userId, ?string $htdocsDir = null): ?string {
    $htdocsDir = $htdocsDir ?? __DIR__ . '/../htdocs';
    $game = getGame($db, $gameId);
    if (!$game) {
        return 'Spel niet gevonden.';
    }
    if (in_array($game['effective_status'], ['running', 'paused'], true)) {
        return 'Een lopend of gepauzeerd spel kun je niet verwijderen. Beëindig het spel eerst.';
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM worker_jobs WHERE game_id = ? AND status IN ('queued', 'running')");
    $stmt->execute([$gameId]);
    if ($stmt->fetchColumn() > 0) {
        return 'Er loopt nog een export of rapport voor dit spel. Wacht tot die klaar is (zie Taken).';
    }

    // Bestanden pas weggooien als de database klaar is
    $files = [];
    $stmt = $db->prepare("SELECT artifact_file FROM assignments WHERE game_id = ? AND artifact_file IS NOT NULL AND artifact_file != ''");
    $stmt->execute([$gameId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $files[] = $htdocsDir . '/' . $file;
    }
    // Alleen een eigen upload, niet een meegeleverd logo zoals images/…
    if (strpos((string)$game['logo_file'], LOGO_DIR . '/') === 0) {
        $files[] = $htdocsDir . '/' . $game['logo_file'];
    }
    $stmt = $db->prepare("SELECT result_file FROM worker_jobs WHERE game_id = ? AND result_file LIKE 'jobs/%'");
    $stmt->execute([$gameId]);
    $results = function_exists('storageDir') ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    $db->beginTransaction();
    try {
        $teamIds = "SELECT id FROM teams WHERE game_id = ?";
        foreach (['team_messages', 'download_tokens', 'team_downloads', 'team_presence', 'ai_team_progress', 'ai_evaluations'] as $table) {
            $db->prepare("DELETE FROM $table WHERE team_id IN ($teamIds)")->execute([$gameId]);
        }
        $db->prepare("DELETE FROM assignment_ai_dialog WHERE assignment_id IN (SELECT id FROM assignments WHERE game_id = ?)")->execute([$gameId]);
        foreach (['ai_team_progress', 'ai_evaluations', 'announcements', 'completed_assignments', 'assignments', 'teams', 'game_teachers', 'worker_jobs'] as $table) {
            $db->prepare("DELETE FROM $table WHERE game_id = ?")->execute([$gameId]);
        }
        $db->prepare("DELETE FROM games WHERE id = ?")->execute([$gameId]);
        logAudit($db, $userId, 'GAME_DELETE', "Spel '{$game['name']}' (#$gameId) verwijderd");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    foreach ($files as $path) {
        // Een bijlage kan door een ander spel gedeeld worden (oude kopieën): alleen weg als niemand hem meer gebruikt
        $rel = substr($path, strlen($htdocsDir) + 1);
        $used = $db->prepare("SELECT (SELECT COUNT(*) FROM assignments WHERE artifact_file = ?) + (SELECT COUNT(*) FROM games WHERE logo_file = ?)");
        $used->execute([$rel, $rel]);
        if ((int)$used->fetchColumn() === 0 && strpos($rel, '..') === false && is_file($path)) {
            @unlink($path);
        }
    }
    foreach ($results as $rel) {
        if (strpos((string)$rel, '..') === false && is_file(storageDir() . '/' . $rel)) {
            @unlink(storageDir() . '/' . $rel);
            @rmdir(dirname(storageDir() . '/' . $rel));
        }
    }
    return null;
}

/**
 * Kopieert de opdrachten van een ander spel, met een eigen kopie van elk bestand en de rubric
 * (zonder de dialoog van de opdracht-assistent). De nummering loopt door na het hoogste bestaande
 * nummer. Geeft het aantal gekopieerde opdrachten terug.
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

    $insert = $db->prepare("INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, time_limit, artifact_file, artifact_name, ai_rubric, ai_rubric_status, ai_auto_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
            $insert->execute([$toGame, $number, $a['title'], $a['description'], $a['instruction'], $a['criteria'], $a['time_limit'], $file,
                              $file ? ($a['artifact_name'] ?? null) : null, $a['ai_rubric'] ?? null, $a['ai_rubric_status'] ?? 'none', (int)($a['ai_auto_allowed'] ?? 1)]);
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
 * Hoogste opdrachtnummer waar data van de huidige teams van het spel aan hangt (level, berichten,
 * downloads, archief, AI). Opdrachten 1..K liggen vast: verplaatsen of verwijderen zou een team aan
 * een andere opdracht koppelen. 0 = alles mag; kan N+1 zijn als een team op Finish staat.
 * Archiefregels van verwijderde teams (bijv. na resetGame()) tellen niet: die horen bij geen team meer.
 */
function assignmentLockLevel(PDO $db, int $gameId): int {
    $stmt = $db->prepare("SELECT MAX(n) FROM (
        SELECT MAX(current_level) AS n FROM teams WHERE game_id = :g
        UNION ALL SELECT MAX(x.assignment_number) FROM team_messages x JOIN teams t ON t.id = x.team_id WHERE t.game_id = :g
        UNION ALL SELECT MAX(x.assignment_number) FROM team_downloads x JOIN teams t ON t.id = x.team_id WHERE t.game_id = :g
        UNION ALL SELECT MAX(x.assignment_number) FROM completed_assignments x JOIN teams t ON t.id = x.team_id WHERE t.game_id = :g
        UNION ALL SELECT MAX(x.assignment_number) FROM ai_evaluations x JOIN teams t ON t.id = x.team_id WHERE t.game_id = :g
        UNION ALL SELECT MAX(x.assignment_number) FROM ai_team_progress x JOIN teams t ON t.id = x.team_id WHERE t.game_id = :g)");
    $stmt->execute([':g' => $gameId]);
    return max(0, (int)$stmt->fetchColumn());
}

/**
 * Nieuwe volgorde van de opdrachten van een spel ($orderedIds = alle opdracht-id's, van eerste naar
 * laatste). Nummert 1..N zonder gaten; vergrendelde opdrachten (assignmentLockLevel()) moeten op
 * hun plek blijven. Geeft een foutmelding terug, of null.
 */
function reorderAssignments(PDO $db, int $gameId, array $orderedIds, ?int $userId): ?string {
    $orderedIds = array_map('intval', array_values($orderedIds));
    $stmt = $db->prepare("SELECT id, assignment_number FROM assignments WHERE game_id = ? ORDER BY assignment_number ASC, id ASC");
    $stmt->execute([$gameId]);
    $current = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $sorted = $orderedIds;
    sort($sorted);
    $known = array_keys($current);
    sort($known);
    if ($sorted !== $known) {
        return 'De lijst met opdrachten is intussen gewijzigd. Laad de pagina opnieuw en probeer het nog eens.';
    }

    $lock = assignmentLockLevel($db, $gameId);
    foreach ($orderedIds as $i => $id) {
        $number = (int)$current[$id];
        if ($number <= $lock && $number !== $i + 1) {
            return "Opdracht $number ligt vast: teams zijn al met deze opdracht bezig (geweest).";
        }
    }
    if (!$current || array_keys($current) === $orderedIds && array_map('intval', array_values($current)) === range(1, count($current))) {
        return null;
    }

    $db->beginTransaction();
    try {
        // Eerst negatief, zodat de unieke index (game_id, assignment_number) tussendoor niet botst
        $db->prepare("UPDATE assignments SET assignment_number = -assignment_number WHERE game_id = ?")->execute([$gameId]);
        $update = $db->prepare("UPDATE assignments SET assignment_number = ? WHERE id = ? AND game_id = ?");
        foreach ($orderedIds as $i => $id) {
            $update->execute([$i + 1, $id, $gameId]);
        }
        logAudit($db, $userId, 'ASSIGNMENT_REORDER', "Volgorde van de opdrachten gewijzigd (spel #$gameId), id's: " . implode(', ', $orderedIds));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return null;
}

/**
 * Verwijdert een opdracht (met de dialoog van de opdracht-assistent) en schuift de opdrachten erna
 * één op, zodat de nummering 1..N blijft. Een vergrendelde opdracht (assignmentLockLevel()) kan
 * niet weg. Het bestand ruimt de aanroeper op. Geeft een foutmelding terug, of null.
 */
function deleteAssignment(PDO $db, int $assignmentId, int $gameId, ?int $userId): ?string {
    $assignment = findAssignmentInGame($db, $assignmentId, $gameId);
    if (!$assignment) {
        return 'Deze opdracht hoort niet bij het actieve spel.';
    }
    $number = (int)$assignment['assignment_number'];
    if ($number <= assignmentLockLevel($db, $gameId)) {
        return "Opdracht $number kan niet weg: teams zijn al met deze opdracht bezig (geweest).";
    }

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM assignments WHERE id = ?")->execute([$assignmentId]);
        $db->prepare("DELETE FROM assignment_ai_dialog WHERE assignment_id = ?")->execute([$assignmentId]);
        // Via negatieve nummers, zodat de unieke index tussendoor niet botst
        $db->prepare("UPDATE assignments SET assignment_number = -(assignment_number - 1) WHERE game_id = ? AND assignment_number > ?")->execute([$gameId, $number]);
        $db->prepare("UPDATE assignments SET assignment_number = -assignment_number WHERE game_id = ? AND assignment_number < 0")->execute([$gameId]);
        logAudit($db, $userId, 'ASSIGNMENT_DELETE', "Deleted assignment #$number: {$assignment['title']} (ID $assignmentId, spel #$gameId)");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return null;
}

/**
 * Oorspronkelijke naam van een geüpload bestand, veilig om te bewaren en te tonen: zonder pad en
 * stuurtekens, max. 200 tekens. Leeg als er niets overblijft.
 */
function cleanFileName(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');
    return function_exists('mb_substr') ? mb_substr($name, 0, 200, 'UTF-8') : substr($name, 0, 200);
}

/**
 * Naam van de bijlage van een opdracht voor de operator: de oorspronkelijke naam, of (bij bijlagen
 * van vóór artifact_name) de naam op de server. Leeg zonder bijlage.
 */
function artifactDisplayName(array $assignment): string {
    if (empty($assignment['artifact_file'])) {
        return '';
    }
    return (string)($assignment['artifact_name'] ?? '') !== '' ? (string)$assignment['artifact_name'] : basename((string)$assignment['artifact_file']);
}

/**
 * Link naar het dashboard van een team.
 */
function teamDashboardUrl(string $token): string {
    return BASE_URL . '/team.php?token=' . $token;
}

// AI-functies (resolveEscalations(), isValidAiMode(), …). Onderaan, omdat ai.php zelf game.php nodig heeft.
require_once __DIR__ . '/ai.php';
