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
    $db->exec("CREATE TABLE games (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', join_code TEXT UNIQUE, registration_open INTEGER DEFAULT 0, logo_file TEXT, status_message TEXT, started_at DATETIME, ends_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by INTEGER, ai_mode TEXT DEFAULT 'suggest', ai_max_attempts INTEGER DEFAULT 5, ai_max_questions INTEGER DEFAULT 5)");
    $db->exec("CREATE TABLE game_teachers (game_id INTEGER NOT NULL, teacher_id INTEGER NOT NULL, PRIMARY KEY (game_id, teacher_id))");
    $db->exec("CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_name TEXT, email TEXT, current_level INTEGER DEFAULT 0, level_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, access_token TEXT UNIQUE, ai_processing_by TEXT, ai_processing_at DATETIME, ai_mode TEXT DEFAULT 'suggest')");
    $db->exec("CREATE UNIQUE INDEX idx_teams_game_name ON teams(game_id, team_name)");
    $db->exec("CREATE TABLE assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, assignment_number INTEGER, title TEXT, description TEXT, instruction TEXT, criteria TEXT, time_limit INTEGER, artifact_file TEXT, ai_rubric TEXT, ai_rubric_status TEXT DEFAULT 'none', ai_design_status TEXT DEFAULT 'idle', ai_design_round INTEGER DEFAULT 0, ai_auto_allowed INTEGER DEFAULT 1, ai_processing_by TEXT, ai_processing_at DATETIME)");
    $db->exec("CREATE UNIQUE INDEX idx_assignments_game_number ON assignments(game_id, assignment_number)");
    $db->exec("CREATE TABLE completed_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_id INTEGER, team_name TEXT, assignment_number INTEGER, chat_history TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE team_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, sender TEXT, message TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, ai_evaluation_id INTEGER)");
    $db->exec("CREATE TABLE download_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, level INTEGER, token TEXT, expires_at DATETIME)");
    $db->exec("CREATE TABLE team_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, download_count INTEGER DEFAULT 0, UNIQUE(team_id, assignment_number))");
    $db->exec("CREATE TABLE team_presence (team_id INTEGER, username TEXT, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (team_id, username))");
    $db->exec("CREATE TABLE announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER, message TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE ai_team_progress (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, assignment_number INTEGER NOT NULL, aspects_json TEXT, summary TEXT, last_message_id INTEGER, updated_at DATETIME, UNIQUE (team_id, assignment_number))");
    $db->exec("CREATE TABLE assignment_ai_dialog (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL, role TEXT NOT NULL, message TEXT NOT NULL, teacher_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
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
check('archief bevat de chat met AI: … en zonder suggestie', $archive[0]['chat_history'] === "Team: Ons antwoord\nDocent: Klopt!\nAI: Sterk speurwerk!");
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

echo "localInputToUtc / utcToLocalInput\n";
$tz = date_default_timezone_get();
date_default_timezone_set('Europe/Amsterdam');
check('14:00 lokaal (zomertijd) → 12:00 UTC', localInputToUtc('2026-07-01T14:00') === '2026-07-01 12:00:00');
check('14:00 lokaal (wintertijd) → 13:00 UTC', localInputToUtc('2026-12-01T14:00') === '2026-12-01 13:00:00');
check('terug naar 14:00 lokaal', utcToLocalInput('2026-07-01 12:00:00') === '2026-07-01T14:00');
check('leeg → null', localInputToUtc('') === null);
check('onzin → null', localInputToUtc('morgen') === null);
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
$db->exec("UPDATE assignments SET artifact_file = 'artifacts/bron.pdf' WHERE game_id = $from AND assignment_number = 1");
addAssignments($db, $filled, 2);
check('kopie naar leeg spel: 3 opdrachten', copyAssignments($db, $from, $empty, 1, $dir) === 3);
check('nummers 1..3', $db->query("SELECT assignment_number FROM assignments WHERE game_id = $empty ORDER BY assignment_number")->fetchAll(PDO::FETCH_COLUMN) == [1, 2, 3]);
copyAssignments($db, $from, $filled, 1, $dir);
check('kopie naar spel met 2 opdrachten: nummers 3..5', $db->query("SELECT assignment_number FROM assignments WHERE game_id = $filled AND assignment_number > 2 ORDER BY assignment_number")->fetchAll(PDO::FETCH_COLUMN) == [3, 4, 5]);
$copyFile = $db->query("SELECT artifact_file FROM assignments WHERE game_id = $empty AND assignment_number = 1")->fetchColumn();
check('bestand gekopieerd onder een nieuwe naam', $copyFile !== 'artifacts/bron.pdf' && strpos($copyFile, "artifacts/game_{$empty}_") === 0 && file_get_contents("$dir/$copyFile") === 'inhoud');
unlink("$dir/$copyFile");
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

if ($failures > 0) {
    echo "\n$failures test(s) mislukt\n";
    exit(1);
}
echo "\nAlle tests geslaagd\n";
