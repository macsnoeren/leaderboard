<?php
/**
 * Tests voor conf/game.php. Geen framework: stopt met exitcode 1 bij een fout.
 * Uitvoeren: docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/game_test.php
 */

require_once __DIR__ . '/../conf/game.php';

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
    $db->exec("CREATE TABLE games (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', join_code TEXT UNIQUE, registration_open INTEGER DEFAULT 0, logo_file TEXT, status_message TEXT, started_at DATETIME, ends_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by INTEGER, ai_mode TEXT DEFAULT 'suggest', ai_max_attempts INTEGER DEFAULT 5, ai_max_questions INTEGER DEFAULT 5, finish_message TEXT)");
    $db->exec("CREATE TABLE game_teachers (game_id INTEGER NOT NULL, teacher_id INTEGER NOT NULL, PRIMARY KEY (game_id, teacher_id))");
    $db->exec("CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_name TEXT, email TEXT, current_level INTEGER DEFAULT 0, level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, access_token TEXT UNIQUE, ai_processing_by TEXT, ai_processing_at DATETIME, ai_mode TEXT DEFAULT 'suggest')");
    $db->exec("CREATE UNIQUE INDEX idx_teams_game_name ON teams(game_id, team_name)");
    $db->exec("CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, assignment_number INTEGER, title TEXT, description TEXT, instruction TEXT, criteria TEXT, time_limit INTEGER, artifact_file TEXT, artifact_name TEXT, ai_rubric TEXT, ai_rubric_status TEXT DEFAULT 'none', ai_design_status TEXT DEFAULT 'idle', ai_design_round INTEGER DEFAULT 0, ai_auto_allowed INTEGER DEFAULT 1, ai_processing_by TEXT, ai_processing_at DATETIME)");
    $db->exec("CREATE UNIQUE INDEX idx_assignments_game_number ON assignments(game_id, assignment_number)");
    $db->exec("CREATE TABLE completed_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_id INTEGER, team_name TEXT, assignment_number INTEGER, chat_history TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, leveled_by INTEGER, leveled_by_name TEXT, ai_evaluation_id INTEGER, accepted_message_id INTEGER, accepted_answer TEXT, level_started_at DATETIME)");
    $db->exec("CREATE TABLE team_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, sender TEXT, message TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, ai_evaluation_id INTEGER)");
    $db->exec("CREATE TABLE download_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, level INTEGER, token TEXT, expires_at DATETIME)");
    $db->exec("CREATE TABLE team_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, download_count INTEGER DEFAULT 0, UNIQUE(team_id, assignment_number))");
    $db->exec("CREATE TABLE team_presence (team_id INTEGER, username TEXT, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (team_id, username))");
    $db->exec("CREATE TABLE announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER, message TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE ai_team_progress (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, assignment_number INTEGER NOT NULL, aspects_json TEXT, summary TEXT, last_message_id INTEGER, updated_at DATETIME, UNIQUE (team_id, assignment_number))");
    $db->exec("CREATE TABLE assignment_ai_dialog (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL, role TEXT NOT NULL, message TEXT NOT NULL, teacher_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE worker_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, game_id INTEGER, params_json TEXT, status TEXT NOT NULL DEFAULT 'queued', progress INTEGER DEFAULT 0, message TEXT, result_file TEXT, result_name TEXT, result_size INTEGER, result_game_id INTEGER, created_by INTEGER, created_at DATETIME, started_at DATETIME, heartbeat_at DATETIME, finished_at DATETIME, expires_at DATETIME, worker_id TEXT)");
    $db->exec("CREATE TABLE ai_evaluations (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, team_name TEXT, assignment_number INTEGER NOT NULL, message_id INTEGER NOT NULL, intent TEXT, intent_json TEXT, helper_json TEXT, assessor_json TEXT, validator_json TEXT, verdict TEXT, decision TEXT, reason TEXT, reply_text TEXT, escalation_reason TEXT, resolved_by INTEGER, resolved_at DATETIME, teacher_verdict TEXT, agent_id TEXT, models TEXT, duration_ms INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    return $db;
}

function addGame(PDO $db, string $status = 'draft', ?string $endsAt = null): int {
    $db->prepare("INSERT INTO games (name, status, join_code, ends_at) VALUES (?, ?, ?, " . ($endsAt === null ? 'NULL' : "datetime('now', ?)") . ")")
       ->execute(array_merge(["Spel $status", $status, generateJoinCode($db)], $endsAt === null ? [] : [$endsAt]));
    return (int)$db->lastInsertId();
}

function addAssignments(PDO $db, int $gameId, int $count, int $from = 1): void {
    for ($i = $from; $i < $from + $count; $i++) {
        $db->prepare("INSERT INTO assignments (game_id, assignment_number, title) VALUES (?, ?, ?)")->execute([$gameId, $i, "Opdracht $i"]);
    }
}

function addTeam(PDO $db, int $gameId, string $name, int $level): int {
    $db->prepare("INSERT INTO teams (game_id, team_name, current_level, level_updated_at, access_token) VALUES (?, ?, ?, datetime('now', '-1 hour'), ?)")
       ->execute([$gameId, $name, $level, bin2hex(random_bytes(8))]);
    return (int)$db->lastInsertId();
}

function addMessage(PDO $db, int $teamId, int $level, string $sender, string $message): void {
    $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message) VALUES (?, ?, ?, ?)")->execute([$teamId, $level, $sender, $message]);
}

function teamLevel(PDO $db, int $teamId): int {
    return (int)$db->query("SELECT current_level FROM teams WHERE id = $teamId")->fetchColumn();
}

function auditCount(PDO $db, string $type): int {
    return (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = " . $db->quote($type))->fetchColumn();
}

// ---------------------------------------------------------------------------
echo "getGame / effectieve status\n";
$db = freshDb();
check('lopend spel, eindtijd verstreken → finished', getGame($db, addGame($db, 'running', '-1 minute'))['effective_status'] === 'finished');
check('gepauzeerd spel, eindtijd verstreken → finished', getGame($db, addGame($db, 'paused', '-1 minute'))['effective_status'] === 'finished');
check('lopend spel, eindtijd in de toekomst → running', getGame($db, addGame($db, 'running', '+1 hour'))['effective_status'] === 'running');
check('lopend spel zonder eindtijd → running', getGame($db, addGame($db, 'running'))['effective_status'] === 'running');
check('concept met verlopen eindtijd blijft draft', getGame($db, addGame($db, 'draft', '-1 day'))['effective_status'] === 'draft');
$future = getGame($db, addGame($db, 'running', '+120 seconds'));
check('seconds_left wordt in SQL berekend (~120)', $future['seconds_left'] >= 118 && $future['seconds_left'] <= 120);
check('onbekend spel → null', getGame($db, 999) === null);

echo "canAccessGame / accessibleGames\n";
$db->exec("INSERT INTO teachers (id, username, role) VALUES (1, 'admin', 'admin'), (2, 'docent', 'user'), (3, 'ander', 'user')");
$g1 = addGame($db, 'draft');
$db->exec("INSERT INTO game_teachers (game_id, teacher_id) VALUES ($g1, 2)");
$admin = ['id' => 1, 'role' => 'admin'];
$docent = ['id' => 2, 'role' => 'user'];
$ander = ['id' => 3, 'role' => 'user'];
check('admin heeft toegang', canAccessGame($db, $admin, $g1));
check('gekoppelde docent heeft toegang', canAccessGame($db, $docent, $g1));
check('docent zonder koppeling heeft geen toegang', !canAccessGame($db, $ander, $g1));
check('admin heeft geen toegang tot een onbekend spel', !canAccessGame($db, $admin, 999));
check('docent ziet alleen zijn eigen spel', array_column(accessibleGames($db, $docent), 'id') == [$g1]);
check('docent zonder spel ziet niets', accessibleGames($db, $ander) === []);

echo "initialLevel\n";
check('concept → 0', initialLevel(['status' => 'draft', 'effective_status' => 'draft']) === 0);
check('bezig → 1', initialLevel(['status' => 'running', 'effective_status' => 'running']) === 1);
check('pauze → 1', initialLevel(['status' => 'paused', 'effective_status' => 'paused']) === 1);
check('afgelopen → null', initialLevel(['status' => 'running', 'effective_status' => 'finished']) === null);

echo "progressPercent\n";
check('level 0 → 0%', progressPercent(0, 4) == 0);
check('level 1 → 0%', progressPercent(1, 4) == 0);
check('level 3 van 4 → 50%', progressPercent(3, 4) == 50);
check('Finish (N+1) → 100%', progressPercent(5, 4) == 100);
check('geen opdrachten → 0%', progressPercent(3, 0) == 0);

echo "levelUp\n";
$db = freshDb();
$game = addGame($db, 'running');
addAssignments($db, $game, 3);
$team = addTeam($db, $game, 'De Speurders', 1);
addMessage($db, $team, 1, 'team', 'Ons antwoord');
addMessage($db, $team, 1, 'suggestion', 'AI vindt het goed');
addMessage($db, $team, 1, 'teacher', 'Klopt!');
addMessage($db, $team, 1, 'ai', 'Sterk speurwerk!');
check('level-up lukt', levelUp($db, $team, 7));
check('team staat op level 2', teamLevel($db, $team) === 2);
$archive = $db->query("SELECT * FROM completed_assignments")->fetchAll(PDO::FETCH_ASSOC);
check('één archiefregel met teamnaam, spel en level', count($archive) === 1 && $archive[0]['team_name'] === 'De Speurders' && (int)$archive[0]['game_id'] === $game && (int)$archive[0]['assignment_number'] === 1);
check('archief bevat de chat met AI: … en zonder suggestie', $archive[0]['chat_history'] === "Team: Ons antwoord\nOperator: Klopt!\nAI: Sterk speurwerk!");
check('teamberichten blijven staan', (int)$db->query("SELECT COUNT(*) FROM team_messages WHERE team_id = $team AND assignment_number = 1 AND sender != 'suggestion'")->fetchColumn() === 3);
check('suggesties van dat level zijn weg', (int)$db->query("SELECT COUNT(*) FROM team_messages WHERE sender = 'suggestion'")->fetchColumn() === 0);
check('audit-regel met user_id', (int)$db->query("SELECT user_id FROM audit_logs WHERE event_type = 'TEAM_LEVEL_UP'")->fetchColumn() === 7);
levelUp($db, $team, 7);
levelUp($db, $team, 7);
check('na 3 opdrachten op Finish (4)', teamLevel($db, $team) === 4);
check('level-up voorbij Finish doet niets', !levelUp($db, $team, 7) && teamLevel($db, $team) === 4);
check('niet bestaand team → false', !levelUp($db, 999, 7));
$waiting = addTeam($db, $game, 'Wachters', 0);
check('level-up vanaf level 0 doet niets', !levelUp($db, $waiting, 7) && teamLevel($db, $waiting) === 0);

echo "levelUp vult het archief (wie, welk antwoord, start)\n";
$db = freshDb();
$db->exec("INSERT INTO teachers (id, username, role) VALUES (7, 'jjansen', 'user')");
$game = addGame($db, 'running');
addAssignments($db, $game, 3);
$team = addTeam($db, $game, 'Archivarissen', 1);
$startedAt = $db->query("SELECT level_updated_at FROM teams WHERE id = $team")->fetchColumn();
addMessage($db, $team, 1, 'team', 'Eerste poging');
addMessage($db, $team, 1, 'teacher', 'Bijna');
addMessage($db, $team, 1, 'team', 'De conciërge');
$acceptedId = (int)$db->query("SELECT MAX(id) FROM team_messages WHERE sender = 'team'")->fetchColumn();
levelUp($db, $team, 7);
$row = $db->query("SELECT * FROM completed_assignments WHERE team_id = $team AND assignment_number = 1")->fetch(PDO::FETCH_ASSOC);
check('docent: leveled_by en naam', (int)$row['leveled_by'] === 7 && $row['leveled_by_name'] === 'jjansen');
check('docent: geen AI-beoordeling', $row['ai_evaluation_id'] === null);
check('goedgekeurd antwoord = laatste teambericht', (int)$row['accepted_message_id'] === $acceptedId && $row['accepted_answer'] === 'De conciërge');
check('level_started_at = oude level_updated_at', $row['level_started_at'] === $startedAt);

addMessage($db, $team, 2, 'team', 'Geldnood');
$msg = (int)$db->query("SELECT MAX(id) FROM team_messages")->fetchColumn();
$db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, intent, decision) VALUES ($game, $team, 2, $msg, 'antwoord', 'suggest')");
$db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, intent, decision) VALUES ($game, $team, 2, $msg, 'antwoord', 'level_up')");
$evalId = (int)$db->lastInsertId();
levelUp($db, $team, null);
$row = $db->query("SELECT * FROM completed_assignments WHERE team_id = $team AND assignment_number = 2")->fetch(PDO::FETCH_ASSOC);
check('AI: leveled_by NULL en de level-up-beoordeling', $row['leveled_by'] === null && $row['leveled_by_name'] === null && (int)$row['ai_evaluation_id'] === $evalId);

levelUp($db, $team, 7);
$row = $db->query("SELECT * FROM completed_assignments WHERE team_id = $team AND assignment_number = 3")->fetch(PDO::FETCH_ASSOC);
check('level zonder teambericht: antwoord NULL', $row['accepted_message_id'] === null && $row['accepted_answer'] === null);

$db->exec("INSERT INTO teachers (id, username, role) VALUES (8, 'weg', 'user')");
$gone = addTeam($db, $game, 'Vertrekkers', 1);
addMessage($db, $gone, 1, 'team', 'Ons laatste antwoord');
levelUp($db, $gone, 8);
deleteTeam($db, $gone);
$db->exec("DELETE FROM teachers WHERE id = 8");
$row = $db->query("SELECT * FROM completed_assignments WHERE team_id = $gone")->fetch(PDO::FETCH_ASSOC);
check('na deleteTeam() blijven antwoord en docentnaam in het archief', $row['accepted_answer'] === 'Ons laatste antwoord' && $row['leveled_by_name'] === 'weg');

echo "levelDown\n";
$db = freshDb();
$game = addGame($db, 'running');
addAssignments($db, $game, 3);
$team = addTeam($db, $game, 'Terugvallers', 2);
addMessage($db, $team, 2, 'team', 'Antwoord level 2');
levelUp($db, $team, 1);
addMessage($db, $team, 3, 'team', 'Antwoord level 3');
check('team op level 3', teamLevel($db, $team) === 3);
check('level terug lukt', levelDown($db, $team, 1));
check('team op level 2', teamLevel($db, $team) === 2);
$msgs = $db->query("SELECT message FROM team_messages WHERE team_id = $team AND assignment_number = 2")->fetchAll(PDO::FETCH_COLUMN);
check('gesprek van level 2 is terug', $msgs === ['Antwoord level 2']);
check('archiefregel van level 2 is weg', (int)$db->query("SELECT COUNT(*) FROM completed_assignments WHERE team_id = $team AND assignment_number = 2")->fetchColumn() === 0);
check('audit TEAM_LEVEL_DOWN', auditCount($db, 'TEAM_LEVEL_DOWN') === 1);
levelDown($db, $team, 1);
check('level terug naar 1', teamLevel($db, $team) === 1);
check('niet onder level 1', !levelDown($db, $team, 1) && teamLevel($db, $team) === 1);

echo "startGame\n";
$db = freshDb();
$game = addGame($db, 'draft');
$other = addGame($db, 'draft');
$a = addTeam($db, $game, 'A', 0);
$b = addTeam($db, $game, 'B', 0);
$c = addTeam($db, $other, 'C', 0);
check('start lukt vanuit concept', startGame($db, $game, 1, 'Succes!'));
$g = getGame($db, $game);
check('status running met starttijd en bericht', $g['status'] === 'running' && $g['started_at'] !== null && $g['status_message'] === 'Succes!');
$rows = $db->query("SELECT current_level, level_updated_at FROM teams WHERE game_id = $game")->fetchAll(PDO::FETCH_ASSOC);
check('alle teams op level 1', array_unique(array_column($rows, 'current_level')) == [1]);
check('alle teams dezelfde tijd, gelijk aan started_at', count(array_unique(array_column($rows, 'level_updated_at'))) === 1 && $rows[0]['level_updated_at'] === $g['started_at']);
check('team van een ander spel blijft op 0', teamLevel($db, $c) === 0);
check('tweede start wordt geweigerd', !startGame($db, $game, 1));
check('één GAME_START in de audit', auditCount($db, 'GAME_START') === 1);

echo "changeGameStatus\n";
check('pauze vanuit running', changeGameStatus($db, $game, 'pause', 'Pauze tot 11:00', 1) && getGame($db, $game)['status'] === 'paused');
check('pauze vanuit paused wordt geweigerd', !changeGameStatus($db, $game, 'pause', null, 1));
check('hervatten', changeGameStatus($db, $game, 'resume', null, 1) && getGame($db, $game)['status'] === 'running');
check('beëindigen', changeGameStatus($db, $game, 'finish', 'Kom naar lokaal X', 1) && getGame($db, $game)['status'] === 'finished');
check('hervatten na einde wordt geweigerd', !changeGameStatus($db, $game, 'resume', null, 1));
check('audit van alle overgangen', auditCount($db, 'GAME_PAUSE') === 1 && auditCount($db, 'GAME_RESUME') === 1 && auditCount($db, 'GAME_FINISH') === 1);
check('concept kan alleen starten', allowedGameActions(getGame($db, $other)) === ['start']);

echo "createTeam\n";
$db = freshDb();
$draft = getGame($db, addGame($db, 'draft'));
$running = getGame($db, addGame($db, 'running'));
$finished = getGame($db, addGame($db, 'finished'));
$t = createTeam($db, $running, '  Laatkomers ', null, 1);
check('team bij lopend spel begint op level 1', is_array($t) && (int)$t['current_level'] === 1 && $t['team_name'] === 'Laatkomers');
$t = createTeam($db, $draft, 'Vroeg', 'vroeg@school.nl', 1);
check('team bij concept-spel begint op level 0', is_array($t) && (int)$t['current_level'] === 0);
check('afgelopen spel weigert', is_string(createTeam($db, $finished, 'Te laat', null, 1)));
check('dubbele naam (andere hoofdletters) geweigerd', is_string(createTeam($db, $running, 'laatkomers', null, 1)));
check('zelfde naam in een ander spel mag', is_array(createTeam($db, $draft, 'Laatkomers', null, 1)));
check('lege naam geweigerd', is_string(createTeam($db, $running, '   ', null, 1)));
check('te lange naam geweigerd', is_string(createTeam($db, $running, str_repeat('x', 51), null, 1)));
createTeam($db, $running, 'Zelf aangemeld', null, null);
check('zelfaanmelding: TEAM_REGISTER zonder user_id', $db->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'TEAM_REGISTER' AND user_id IS NULL")->fetchColumn() == 1);
$t = createTeam($db, $running, 'Standaard', null, 1);
check('nieuw team krijgt standaard ai_mode suggest', is_array($t) && $t['ai_mode'] === 'suggest');
setAllTeamsAiMode($db, (int)$running['id'], 'reply', 1);
// $running is van vóór de wijziging: createTeam() leest de standaard uit de database
$t = createTeam($db, $running, 'Na alle teams', null, 1);
check('team na setAllTeamsAiMode(reply) krijgt reply', is_array($t) && $t['ai_mode'] === 'reply');
$t = createTeam($db, $running, 'Via join', null, null);
check('ook via zelfaanmelding (join.php-pad) reply', is_array($t) && $t['ai_mode'] === 'reply');
$t = createTeam($db, $draft, 'Ander spel', null, 1);
check('team in een ander spel houdt de eigen standaard', is_array($t) && $t['ai_mode'] === 'suggest');

echo "generateJoinCode\n";
$db = freshDb();
$codes = [];
for ($i = 0; $i < 1000; $i++) {
    $codes[] = generateJoinCode($db);
}
check('1000 codes van 6 tekens', count(preg_grep('/^[A-Z2-9]{6}$/', $codes)) === 1000);
check('geen verwarrende tekens (0, O, 1, I, L)', count(preg_grep('/[0O1IL]/', $codes)) === 0);
$db->exec("INSERT INTO games (name, join_code) VALUES ('Bestaand', 'ABCDEF')");
$candidates = ['ABCDEF', 'ABCDEF', 'GHJKMN'];
$code = generateJoinCode($db, function () use (&$candidates) { return array_shift($candidates); });
check('bestaande code wordt niet opnieuw uitgegeven', $code === 'GHJKMN');

echo "localDateTimeToUtc / utcToLocalParts\n";
$tz = date_default_timezone_get();
date_default_timezone_set('Europe/Amsterdam');
check('01/07/2026 14:00 lokaal (zomertijd) → 12:00 UTC', localDateTimeToUtc('01/07/2026', '14:00') === '2026-07-01 12:00:00');
check('01/12/2026 14:00 lokaal (wintertijd) → 13:00 UTC', localDateTimeToUtc('01/12/2026', '14:00') === '2026-12-01 13:00:00');
check('dag eerst: 02/03 is 2 maart', localDateTimeToUtc('02/03/2026', '12:00') === '2026-03-02 11:00:00');
check('ook d-m-jjjj, d.m.jjjj en u.mm', localDateTimeToUtc('1-7-2026', '9.05') === '2026-07-01 07:05:00' && localDateTimeToUtc('1.7.2026', '09:05') === '2026-07-01 07:05:00');
check('spaties eromheen mogen', localDateTimeToUtc(' 01/07/2026 ', ' 14:00 ') === '2026-07-01 12:00:00');
check('31/02 → null', localDateTimeToUtc('31/02/2026', '12:00') === null);
check('maand 13 → null', localDateTimeToUtc('01/13/2026', '12:00') === null);
check('24:00 en 12:60 → null', localDateTimeToUtc('01/07/2026', '24:00') === null && localDateTimeToUtc('01/07/2026', '12:60') === null);
check('jjjj-mm-dd → null', localDateTimeToUtc('2026-07-01', '12:00') === null);
check('alleen datum of alleen tijd → null', localDateTimeToUtc('01/07/2026', '') === null && localDateTimeToUtc('', '12:00') === null);
check('onzin → null', localDateTimeToUtc('morgen', 'straks') === null);
check('terug naar 01/07/2026 14:00 lokaal', utcToLocalParts('2026-07-01 12:00:00') === ['date' => '01/07/2026', 'time' => '14:00']);
check('geen eindtijd → leeg', utcToLocalParts(null) === ['date' => '', 'time' => '']);
date_default_timezone_set($tz);

echo "resetGame\n";
$db = freshDb();
$game = addGame($db, 'running');
$other = addGame($db, 'running');
addAssignments($db, $game, 2);
$t1 = addTeam($db, $game, 'Een', 1);
$t2 = addTeam($db, $game, 'Twee', 1);
$keep = addTeam($db, $other, 'Ander spel', 1);
addMessage($db, $t1, 1, 'team', 'hallo');
addMessage($db, $keep, 1, 'team', 'blijf');
levelUp($db, $t1, 1);
$db->exec("INSERT INTO team_downloads (team_id, assignment_number, download_count) VALUES ($t1, 1, 2), ($keep, 1, 1)");
$db->exec("INSERT INTO download_tokens (team_id, level, token) VALUES ($t2, 1, 'x')");
$db->exec("INSERT INTO announcements (game_id, team_id, message) VALUES ($game, NULL, 'Alle'), ($game, $t2, 'Twee'), ($other, NULL, 'Ander')");
$archiveBefore = $db->query("SELECT * FROM completed_assignments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
resetGame($db, $game, 1);
check('geen teams meer in dit spel', (int)$db->query("SELECT COUNT(*) FROM teams WHERE game_id = $game")->fetchColumn() === 0);
check('geen berichten, downloads of tokens van dit spel', (int)$db->query("SELECT (SELECT COUNT(*) FROM team_messages WHERE team_id IN ($t1, $t2)) + (SELECT COUNT(*) FROM team_downloads WHERE team_id IN ($t1, $t2)) + (SELECT COUNT(*) FROM download_tokens WHERE team_id IN ($t1, $t2))")->fetchColumn() === 0);
check('mededelingen van dit spel weg, ander spel niet', (int)$db->query("SELECT COUNT(*) FROM announcements WHERE game_id = $game")->fetchColumn() === 0 && (int)$db->query("SELECT COUNT(*) FROM announcements WHERE game_id = $other")->fetchColumn() === 1);
check('archief ongewijzigd (met teamnaam)', $db->query("SELECT * FROM completed_assignments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) === $archiveBefore && $archiveBefore[0]['team_name'] === 'Een');
check('opdrachten blijven', totalAssignments($db, $game) === 2);
check('team, berichten en downloads van ander spel ongemoeid', teamLevel($db, $keep) === 1 && (int)$db->query("SELECT COUNT(*) FROM team_messages WHERE team_id = $keep")->fetchColumn() === 1 && (int)$db->query("SELECT COUNT(*) FROM team_downloads WHERE team_id = $keep")->fetchColumn() === 1);
$g = getGame($db, $game);
check('spel terug naar concept zonder starttijd', $g['status'] === 'draft' && $g['started_at'] === null);
check('audit GAME_RESET', auditCount($db, 'GAME_RESET') === 1);

echo "copyAssignments\n";
$db = freshDb();
$dir = sys_get_temp_dir() . '/game_test_' . bin2hex(random_bytes(4));
mkdir($dir . '/artifacts', 0777, true);
file_put_contents($dir . '/artifacts/bron.pdf', 'inhoud');
$from = addGame($db);
$empty = addGame($db);
$filled = addGame($db);
addAssignments($db, $from, 3);
$db->exec("UPDATE assignments SET artifact_file = 'artifacts/bron.pdf', artifact_name = 'Plattegrond.pdf' WHERE game_id = $from AND assignment_number = 1");
$db->exec("UPDATE assignments SET artifact_file = 'artifacts/weg.pdf', artifact_name = 'Ontbreekt.pdf' WHERE game_id = $from AND assignment_number = 3");
addAssignments($db, $filled, 2);
check('kopie naar leeg spel: 3 opdrachten', copyAssignments($db, $from, $empty, 1, $dir) === 3);
check('nummers 1..3', $db->query("SELECT assignment_number FROM assignments WHERE game_id = $empty ORDER BY assignment_number")->fetchAll(PDO::FETCH_COLUMN) == [1, 2, 3]);
copyAssignments($db, $from, $filled, 1, $dir);
check('kopie naar spel met 2 opdrachten: nummers 3..5', $db->query("SELECT assignment_number FROM assignments WHERE game_id = $filled AND assignment_number > 2 ORDER BY assignment_number")->fetchAll(PDO::FETCH_COLUMN) == [3, 4, 5]);
$copyFile = $db->query("SELECT artifact_file FROM assignments WHERE game_id = $empty AND assignment_number = 1")->fetchColumn();
check('bestand gekopieerd onder een nieuwe naam', $copyFile !== 'artifacts/bron.pdf' && strpos($copyFile, "artifacts/game_{$empty}_") === 0 && file_get_contents("$dir/$copyFile") === 'inhoud');
unlink("$dir/$copyFile");
check('kopie houdt de oorspronkelijke naam', $db->query("SELECT artifact_name FROM assignments WHERE game_id = $empty AND assignment_number = 1")->fetchColumn() === 'Plattegrond.pdf');
check('ontbrekend bestand: geen bijlage en geen naam', $db->query("SELECT COALESCE(artifact_file, '') || COALESCE(artifact_name, '') FROM assignments WHERE game_id = $empty AND assignment_number = 3")->fetchColumn() === '');
check('origineel blijft na verwijderen van de kopie', is_file("$dir/artifacts/bron.pdf"));
check('kopie naar zichzelf doet niets', copyAssignments($db, $from, $from, 1, $dir) === 0);
$rubric = json_encode(['kern' => 'Dader', 'aspecten' => [['naam' => 'Dader', 'verplicht' => true, 'onvoldoende' => 'a', 'voldoende' => 'b', 'goed' => 'c']]]);
$db->prepare("UPDATE assignments SET ai_rubric = ?, ai_rubric_status = 'approved', ai_auto_allowed = 0 WHERE game_id = ? AND assignment_number = 2")->execute([$rubric, $from]);
$rubricTarget = addGame($db);
copyAssignments($db, $from, $rubricTarget, 1, $dir);
$copied = $db->query("SELECT ai_rubric, ai_rubric_status, ai_auto_allowed, ai_design_status FROM assignments WHERE game_id = $rubricTarget AND assignment_number = 2")->fetch(PDO::FETCH_ASSOC);
check('kopie neemt rubric, status en ai_auto_allowed mee', $copied['ai_rubric'] === $rubric && $copied['ai_rubric_status'] === 'approved' && (int)$copied['ai_auto_allowed'] === 0);
check('kopie zonder rubric: status none en auto_allowed 1', $db->query("SELECT ai_rubric_status || '/' || ai_auto_allowed FROM assignments WHERE game_id = $rubricTarget AND assignment_number = 1")->fetchColumn() === 'none/1');
check('dialoogstatus begint opnieuw (idle)', $copied['ai_design_status'] === 'idle');
foreach ($db->query("SELECT artifact_file FROM assignments WHERE game_id = $rubricTarget AND artifact_file IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $f) {
    @unlink("$dir/$f");
}
check('audit ASSIGNMENTS_COPY', auditCount($db, 'ASSIGNMENTS_COPY') === 3);
array_map('unlink', glob("$dir/artifacts/*"));
rmdir("$dir/artifacts");
rmdir($dir);

function assignmentOrder(PDO $db, int $gameId): array {
    return array_map('intval', $db->query("SELECT id FROM assignments WHERE game_id = $gameId ORDER BY assignment_number")->fetchAll(PDO::FETCH_COLUMN));
}
function assignmentNumbers(PDO $db, int $gameId): array {
    return array_map('intval', $db->query("SELECT assignment_number FROM assignments WHERE game_id = $gameId ORDER BY assignment_number")->fetchAll(PDO::FETCH_COLUMN));
}

echo "assignmentLockLevel\n";
$db = freshDb();
$game = addGame($db);
addAssignments($db, $game, 4);
check('geen teams → 0', assignmentLockLevel($db, $game) === 0);
$team = addTeam($db, $game, 'Wachtend', 0);
check('team op level 0 → 0', assignmentLockLevel($db, $game) === 0);
$db->exec("UPDATE teams SET current_level = 2 WHERE id = $team");
check('team op level 2 → 2', assignmentLockLevel($db, $game) === 2);
addMessage($db, $team, 3, 'team', 'bericht van level 3 (na level terug)');
check('bericht op level 3 telt mee', assignmentLockLevel($db, $game) === 3);
$other = addGame($db);
addAssignments($db, $other, 2);
addTeam($db, $other, 'Ander', 2);
foreach (["INSERT INTO completed_assignments (game_id, team_id, assignment_number) VALUES ($game, $team, 4)",
          "INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id) VALUES ($other, 99, 2, 1)"] as $sql) {
    $db->exec($sql);
}
check('archief telt mee', assignmentLockLevel($db, $game) === 4);
check('ander spel los: alleen eigen data', assignmentLockLevel($db, $other) === 2);
$db = freshDb();
$game = addGame($db);
addAssignments($db, $game, 3);
$team = addTeam($db, $game, 'Download', 0);
$db->exec("INSERT INTO team_downloads (team_id, assignment_number, download_count) VALUES ($team, 2, 1)");
check('downloads tellen mee', assignmentLockLevel($db, $game) === 2);
$db->exec("INSERT INTO ai_team_progress (game_id, team_id, assignment_number) VALUES ($game, $team, 3)");
check('AI-voortgang telt mee', assignmentLockLevel($db, $game) === 3);
$db = freshDb();
$game = addGame($db, 'running');
addAssignments($db, $game, 3);
$team = addTeam($db, $game, 'Vorige ronde', 1);
levelUp($db, $team, 1);
levelUp($db, $team, 1);
check('voor de reset: team en archief → 3', assignmentLockLevel($db, $game) === 3);
resetGame($db, $game, 1);
check('na resetGame: archief blijft, maar niets ligt vast', (int)$db->query("SELECT COUNT(*) FROM completed_assignments WHERE game_id = $game")->fetchColumn() === 2 && assignmentLockLevel($db, $game) === 0);
$ids = assignmentOrder($db, $game);
check('na resetGame: volgorde wijzigen mag', reorderAssignments($db, $game, array_reverse($ids), 1) === null);

echo "reorderAssignments\n";
$db = freshDb();
$game = addGame($db);
addAssignments($db, $game, 4);
[$a1, $a2, $a3, $a4] = assignmentOrder($db, $game);
check('concept: volgorde wijzigen mag', reorderAssignments($db, $game, [$a3, $a1, $a4, $a2], 1) === null);
check('nieuwe volgorde opgeslagen', assignmentOrder($db, $game) === [$a3, $a1, $a4, $a2]);
check('nummers 1..4', assignmentNumbers($db, $game) === [1, 2, 3, 4]);
check('audit ASSIGNMENT_REORDER', auditCount($db, 'ASSIGNMENT_REORDER') === 1);
check('zelfde volgorde: geen audit', reorderAssignments($db, $game, [$a3, $a1, $a4, $a2], 1) === null && auditCount($db, 'ASSIGNMENT_REORDER') === 1);
check('id-strings worden geaccepteerd', reorderAssignments($db, $game, ["$a1", "$a2", "$a3", "$a4"], 1) === null && assignmentOrder($db, $game) === [$a1, $a2, $a3, $a4]);
check('ontbrekende opdracht → fout', reorderAssignments($db, $game, [$a1, $a2, $a3], 1) !== null);
check('dubbele id → fout', reorderAssignments($db, $game, [$a1, $a1, $a2, $a3, $a4], 1) !== null);
$other = addGame($db);
addAssignments($db, $other, 1);
$foreign = assignmentOrder($db, $other)[0];
check('opdracht van ander spel → fout', reorderAssignments($db, $game, [$a1, $a2, $a3, $foreign], 1) !== null);
check('ander spel ongemoeid', assignmentNumbers($db, $other) === [1]);
check('na fouten volgorde ongewijzigd', assignmentOrder($db, $game) === [$a1, $a2, $a3, $a4]);

$team = addTeam($db, $game, 'Bezig', 2);
check('lopend: opdracht 1 en 2 liggen vast', reorderAssignments($db, $game, [$a2, $a1, $a3, $a4], 1) !== null);
check('lopend: vergrendelde opdracht naar achteren → fout', reorderAssignments($db, $game, [$a1, $a3, $a4, $a2], 1) !== null);
check('lopend: opdracht voor een vergrendelde zetten → fout', reorderAssignments($db, $game, [$a1, $a4, $a2, $a3], 1) !== null);
check('lopend: 3 en 4 wisselen mag', reorderAssignments($db, $game, [$a1, $a2, $a4, $a3], 1) === null && assignmentOrder($db, $game) === [$a1, $a2, $a4, $a3]);
check('level van het team ongewijzigd', teamLevel($db, $team) === 2);
$db->exec("UPDATE teams SET current_level = 5 WHERE id = $team");
check('team op Finish: alles ligt vast', reorderAssignments($db, $game, [$a1, $a2, $a3, $a4], 1) !== null);

$db = freshDb();
$game = addGame($db);
$db->exec("INSERT INTO assignments (game_id, assignment_number, title) VALUES ($game, 2, 'B'), ($game, 5, 'C'), ($game, 9, 'D')");
$ids = assignmentOrder($db, $game);
check('gaten: zelfde volgorde nummert 1..N', reorderAssignments($db, $game, $ids, 1) === null && assignmentNumbers($db, $game) === [1, 2, 3] && assignmentOrder($db, $game) === $ids);
check('leeg spel: niets te doen', reorderAssignments($db, addGame($db), [], 1) === null);

echo "deleteAssignment\n";
$db = freshDb();
$game = addGame($db);
addAssignments($db, $game, 4);
[$a1, $a2, $a3, $a4] = assignmentOrder($db, $game);
$db->exec("INSERT INTO assignment_ai_dialog (assignment_id, role, message) VALUES ($a2, 'ai', 'vraag')");
check('opdracht 2 verwijderen', deleteAssignment($db, $a2, $game, 1) === null);
check('gat dicht: nummers 1..3', assignmentNumbers($db, $game) === [1, 2, 3] && assignmentOrder($db, $game) === [$a1, $a3, $a4]);
check('dialoog mee weg', (int)$db->query("SELECT COUNT(*) FROM assignment_ai_dialog WHERE assignment_id = $a2")->fetchColumn() === 0);
check('audit ASSIGNMENT_DELETE', auditCount($db, 'ASSIGNMENT_DELETE') === 1);
check('onbekende opdracht → fout', deleteAssignment($db, 999, $game, 1) !== null);
$other = addGame($db);
check('opdracht van ander spel → fout', deleteAssignment($db, $a1, $other, 1) !== null && count(assignmentOrder($db, $game)) === 3);
addTeam($db, $game, 'Bezig', 1);
check('vergrendelde opdracht → fout', deleteAssignment($db, $a1, $game, 1) !== null && count(assignmentOrder($db, $game)) === 3);
check('laatste opdracht mag wel', deleteAssignment($db, $a4, $game, 1) === null && assignmentNumbers($db, $game) === [1, 2]);

echo "updateFinishMessage\n";
$db = freshDb();
$game = addGame($db);
check('eindtekst opslaan', updateFinishMessage($db, $game, "  ***GEFELICITEERD!***\r\n\r\nZaak opgelost.  ", 1) === null);
check('getrimd en met \\n', getGame($db, $game)['finish_message'] === "***GEFELICITEERD!***\n\nZaak opgelost.");
check('te lang → fout, oude tekst blijft', updateFinishMessage($db, $game, str_repeat('é', FINISH_MESSAGE_MAX_LENGTH + 1), 1) !== null && getGame($db, $game)['finish_message'] === "***GEFELICITEERD!***\n\nZaak opgelost.");
check('precies het maximum mag', updateFinishMessage($db, $game, str_repeat('é', FINISH_MESSAGE_MAX_LENGTH), 1) === null);
check('leeg → NULL (standaardtekst)', updateFinishMessage($db, $game, "  \n ", 1) === null && getGame($db, $game)['finish_message'] === null);
check('onbekend spel → fout', updateFinishMessage($db, 999, 'x', 1) !== null);
check('audit GAME_UPDATE per wijziging', auditCount($db, 'GAME_UPDATE') === 3);

echo "cleanFileName / artifactDisplayName\n";
check('gewone naam blijft', cleanFileName('Plattegrond kelder.pdf') === 'Plattegrond kelder.pdf');
check('pad (ook Windows) gaat eraf', cleanFileName('../../etc/passwd.txt') === 'passwd.txt' && cleanFileName('C:\\Users\\jan\\Bewijs.pdf') === 'Bewijs.pdf');
check('stuurtekens gaan eraf', cleanFileName("a\nb\x00.pdf") === 'ab.pdf');
check('accenten blijven', cleanFileName('Conciërge café.pdf') === 'Conciërge café.pdf');
check('max. 200 tekens', mb_strlen(cleanFileName(str_repeat('é', 300) . '.pdf')) === 200);
check('niets over → leeg', cleanFileName("\n") === '' && cleanFileName('') === '');
check('naam van de bijlage', artifactDisplayName(['artifact_file' => 'artifacts/game_1_x.pdf', 'artifact_name' => 'Kaart.pdf']) === 'Kaart.pdf');
check('oude bijlage zonder naam → naam op de server', artifactDisplayName(['artifact_file' => 'artifacts/game_1_x.pdf', 'artifact_name' => null]) === 'game_1_x.pdf');
check('geen bijlage → leeg', artifactDisplayName(['artifact_file' => null, 'artifact_name' => 'Kaart.pdf']) === '');

echo "artifactPath / attachmentDisposition\n";
$htdocs = sys_get_temp_dir() . '/game_test_' . bin2hex(random_bytes(4));
mkdir("$htdocs/artifacts", 0777, true);
file_put_contents("$htdocs/artifacts/kaart.pdf", 'x');
file_put_contents("$htdocs/geheim.txt", 'x');
check('bijlage in artifacts/', artifactPath(['artifact_file' => 'artifacts/kaart.pdf'], $htdocs) === realpath("$htdocs/artifacts/kaart.pdf"));
check('ontbrekend bestand → null', artifactPath(['artifact_file' => 'artifacts/weg.pdf'], $htdocs) === null);
check('geen bijlage → null', artifactPath(['artifact_file' => null], $htdocs) === null);
check('buiten artifacts/ → null', artifactPath(['artifact_file' => 'artifacts/../geheim.txt'], $htdocs) === null && artifactPath(['artifact_file' => 'geheim.txt'], $htdocs) === null);
check('de map zelf → null', artifactPath(['artifact_file' => 'artifacts'], $htdocs) === null);
check('downloadlink via PHP', artifactDownloadUrl(['id' => 7, 'artifact_file' => 'artifacts/kaart.pdf']) === 'assignments.php?download=7');
check('gewone naam', attachmentDisposition('Kaart.pdf') === 'attachment; filename="Kaart.pdf"; filename*=UTF-8\'\'Kaart.pdf');
check('accenten en aanhalingstekens', attachmentDisposition('Café "x".pdf') === 'attachment; filename="Caf_ _x_.pdf"; filename*=UTF-8\'\'Caf%C3%A9%20%22x%22.pdf');
unlink("$htdocs/artifacts/kaart.pdf");
unlink("$htdocs/geheim.txt");
rmdir("$htdocs/artifacts");
rmdir($htdocs);

echo "countUnread\n";
$db = freshDb();
$game = addGame($db, 'running');
addAssignments($db, $game, 3);
$team = addTeam($db, $game, 'Lezers', 1);
addMessage($db, $team, 1, 'team', 'ongelezen op level 1');
check('1 ongelezen bericht', countUnread($db, $game) === 1);
levelUp($db, $team, 1);
check('na level-up telt het oude bericht niet meer', countUnread($db, $game) === 0);
levelDown($db, $team, 1);
check('na level terug telt het weer', countUnread($db, $game) === 1);

echo "deleteGame\n";
$db = freshDb();
$htdocs = sys_get_temp_dir() . '/game_test_' . bin2hex(random_bytes(4));
mkdir("$htdocs/artifacts", 0777, true);
mkdir("$htdocs/uploads", 0777, true);
foreach (['artifacts/a.pdf', 'artifacts/b.pdf', 'artifacts/gedeeld.pdf', 'uploads/logo.png'] as $f) {
    file_put_contents("$htdocs/$f", 'x');
}
$db->exec("INSERT INTO teachers (id, username, role) VALUES (1, 'admin', 'admin')");
$weg = addGame($db, 'finished');
$blijft = addGame($db, 'running');
$db->exec("UPDATE games SET logo_file = 'uploads/logo.png' WHERE id = $weg");
addAssignments($db, $weg, 2);
addAssignments($db, $blijft, 1);
$db->exec("UPDATE assignments SET artifact_file = 'artifacts/a.pdf' WHERE game_id = $weg AND assignment_number = 1");
$db->exec("UPDATE assignments SET artifact_file = 'artifacts/gedeeld.pdf' WHERE game_id = $weg AND assignment_number = 2");
$db->exec("UPDATE assignments SET artifact_file = 'artifacts/gedeeld.pdf' WHERE game_id = $blijft");
$aId = (int)$db->query("SELECT id FROM assignments WHERE game_id = $weg LIMIT 1")->fetchColumn();
$db->exec("INSERT INTO assignment_ai_dialog (assignment_id, role, message) VALUES ($aId, 'teacher', 'x')");
$db->exec("INSERT INTO game_teachers (game_id, teacher_id) VALUES ($weg, 1), ($blijft, 1)");
foreach ([$weg, $blijft] as $g) {
    $t = addTeam($db, $g, "Team $g", 2);
    addMessage($db, $t, 1, 'team', 'hallo');
    $db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id) VALUES ($g, $t, 1, 1)");
    $db->exec("INSERT INTO ai_team_progress (game_id, team_id, assignment_number) VALUES ($g, $t, 1)");
    $db->exec("INSERT INTO team_downloads (team_id, assignment_number, download_count) VALUES ($t, 1, 1)");
    $db->exec("INSERT INTO download_tokens (team_id, level, token) VALUES ($t, 1, 'tok$g')");
    $db->exec("INSERT INTO team_presence (team_id, username) VALUES ($t, 'x')");
    $db->exec("INSERT INTO completed_assignments (game_id, team_id, team_name, assignment_number, chat_history) VALUES ($g, $t, 'Team $g', 1, 'Team: hallo')");
    $db->exec("INSERT INTO announcements (game_id, message) VALUES ($g, 'Welkom')");
    $db->exec("INSERT INTO worker_jobs (type, game_id, status) VALUES ('report', $g, 'done')");
}
$counts = function (PDO $db) {
    $out = [];
    foreach (['games', 'teams', 'assignments', 'assignment_ai_dialog', 'team_messages', 'ai_evaluations', 'ai_team_progress', 'team_downloads',
              'download_tokens', 'team_presence', 'completed_assignments', 'announcements', 'game_teachers', 'worker_jobs'] as $table) {
        $out[$table] = (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
    return $out;
};

check('lopend spel verwijderen wordt geweigerd', deleteGame($db, $blijft, 1, $htdocs) !== null && getGame($db, $blijft) !== null);
$db->exec("UPDATE games SET status = 'paused' WHERE id = $blijft");
check('gepauzeerd spel verwijderen wordt geweigerd', deleteGame($db, $blijft, 1, $htdocs) !== null);
$db->exec("UPDATE games SET status = 'running' WHERE id = $blijft");
$db->exec("INSERT INTO worker_jobs (type, game_id, status) VALUES ('game_export', $weg, 'running')");
check('niet zolang er een taak voor het spel loopt', deleteGame($db, $weg, 1, $htdocs) !== null && getGame($db, $weg) !== null);
$db->exec("UPDATE worker_jobs SET status = 'done' WHERE game_id = $weg");
$before = $counts($db);

check('afgelopen spel verwijderen lukt', deleteGame($db, $weg, 1, $htdocs) === null);
check('spel is weg', getGame($db, $weg) === null);
$after = $counts($db);
$expected = $before;
foreach ($expected as $table => $n) {
    $expected[$table] = ['games' => 1, 'assignments' => 1, 'assignment_ai_dialog' => 0, 'worker_jobs' => 1][$table] ?? (int)($n / 2);
}
check('alle rijen van het spel zijn weg, van het andere spel niet', $after === $expected);
check('andere spel is onaangeroerd', getGame($db, $blijft) !== null && totalAssignments($db, $blijft) === 1);
check('bijlage en geüpload logo van het spel zijn weg', !is_file("$htdocs/artifacts/a.pdf") && !is_file("$htdocs/uploads/logo.png"));
check('bijlage die een ander spel nog gebruikt blijft', is_file("$htdocs/artifacts/gedeeld.pdf"));
check('ander bestand blijft', is_file("$htdocs/artifacts/b.pdf"));
check('audit GAME_DELETE', auditCount($db, 'GAME_DELETE') === 1);
check('onbekend spel → foutmelding', deleteGame($db, 999, 1, $htdocs) !== null);
array_map('unlink', glob("$htdocs/*/*"));
rmdir("$htdocs/artifacts");
rmdir("$htdocs/uploads");
rmdir($htdocs);

if ($failures > 0) {
    echo "\n$failures test(s) mislukt\n";
    exit(1);
}
echo "\nAlle tests geslaagd\n";
