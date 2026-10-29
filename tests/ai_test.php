<?php
/**
 * Tests voor conf/ai.php (agentic AI). Geen framework: stopt met exitcode 1 bij een fout.
 * Uitvoeren: docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/ai_test.php
 */

require_once __DIR__ . '/../conf/ai.php';

$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    echo ($ok ? '  ok   ' : '  FOUT ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

/**
 * Alle tabellen van setup.php + migrate.php, inclusief de AI-kolommen en -tabellen.
 */
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
    $db->exec("CREATE TABLE completed_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, team_id INTEGER, team_name TEXT, assignment_number INTEGER, chat_history TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, leveled_by INTEGER, leveled_by_name TEXT, ai_evaluation_id INTEGER, accepted_message_id INTEGER, accepted_answer TEXT, level_started_at DATETIME)");
    $db->exec("CREATE TABLE team_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, sender TEXT, message TEXT, is_read INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, ai_evaluation_id INTEGER)");
    $db->exec("CREATE TABLE team_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, assignment_number INTEGER, download_count INTEGER DEFAULT 0, UNIQUE(team_id, assignment_number))");
    $db->exec("CREATE TABLE team_presence (team_id INTEGER, username TEXT, last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (team_id, username))");
    $db->exec("CREATE TABLE announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER, message TEXT NOT NULL, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_type TEXT, description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, key_name TEXT, api_key TEXT UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE ai_service_status (id INTEGER PRIMARY KEY AUTOINCREMENT, agent_id TEXT UNIQUE, last_heartbeat DATETIME DEFAULT CURRENT_TIMESTAMP, model_ok INTEGER DEFAULT 1)");
    $db->exec("CREATE TABLE ai_team_progress (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, assignment_number INTEGER NOT NULL, aspects_json TEXT, summary TEXT, last_message_id INTEGER, updated_at DATETIME, UNIQUE (team_id, assignment_number))");
    $db->exec("CREATE TABLE assignment_ai_dialog (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL, role TEXT NOT NULL, message TEXT NOT NULL, teacher_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE ai_evaluations (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER NOT NULL, team_id INTEGER NOT NULL, team_name TEXT, assignment_number INTEGER NOT NULL, message_id INTEGER NOT NULL, intent TEXT, intent_json TEXT, helper_json TEXT, assessor_json TEXT, validator_json TEXT, verdict TEXT, decision TEXT, reason TEXT, reply_text TEXT, escalation_reason TEXT, resolved_by INTEGER, resolved_at DATETIME, teacher_verdict TEXT, agent_id TEXT, models TEXT, duration_ms INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE INDEX idx_ai_evaluations_team ON ai_evaluations(team_id, assignment_number)");
    $db->exec("CREATE INDEX idx_assignment_ai_dialog ON assignment_ai_dialog(assignment_id)");
    return $db;
}

function addGame(PDO $db, string $status = 'running'): int {
    $db->prepare("INSERT INTO games (name, status, join_code) VALUES (?, ?, ?)")->execute(["Spel $status", $status, generateJoinCode($db)]);
    return (int)$db->lastInsertId();
}

function addAssignment(PDO $db, int $gameId, int $number, array $extra = []): int {
    $row = $extra + ['title' => "Opdracht $number", 'description' => 'Een moord in de school.', 'instruction' => 'Wie is de dader en waarom?',
                     'criteria' => 'Jan de Vries, de conciërge. Motief: gokschulden.', 'ai_rubric' => null, 'ai_rubric_status' => 'none', 'ai_auto_allowed' => 1];
    $db->prepare("INSERT INTO assignments (game_id, assignment_number, title, description, instruction, criteria, ai_rubric, ai_rubric_status, ai_auto_allowed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$gameId, $number, $row['title'], $row['description'], $row['instruction'], $row['criteria'], $row['ai_rubric'], $row['ai_rubric_status'], $row['ai_auto_allowed']]);
    return (int)$db->lastInsertId();
}

function addTeam(PDO $db, int $gameId, string $name, int $level = 1, string $mode = 'suggest'): int {
    $db->prepare("INSERT INTO teams (game_id, team_name, email, current_level, level_updated_at, access_token, ai_mode) VALUES (?, ?, ?, ?, datetime('now', '-1 hour'), ?, ?)")
       ->execute([$gameId, $name, strtolower($name) . '@school.nl', $level, bin2hex(random_bytes(8)), $mode]);
    return (int)$db->lastInsertId();
}

function addMessage(PDO $db, int $teamId, int $level, string $sender, string $message): int {
    $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message) VALUES (?, ?, ?, ?)")->execute([$teamId, $level, $sender, $message]);
    return (int)$db->lastInsertId();
}

function auditCount(PDO $db, string $type): int {
    return (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = " . $db->quote($type))->fetchColumn();
}

function teamLevel(PDO $db, int $teamId): int {
    return (int)$db->query("SELECT current_level FROM teams WHERE id = $teamId")->fetchColumn();
}

function validRubric(): array {
    return [
        'kern' => 'Het team moet de dader én het motief noemen.',
        'aspecten' => [
            ['naam' => 'Dader', 'verplicht' => true, 'onvoldoende' => 'Verkeerde persoon.', 'voldoende' => 'De conciërge.', 'goed' => 'Jan de Vries (de conciërge).'],
            ['naam' => 'Motief', 'verplicht' => false, 'onvoldoende' => 'Geen motief.', 'voldoende' => 'Geldproblemen.', 'goed' => 'Gokschuld en de kas.'],
        ],
        'ook_goed' => ['de conciërge'],
        'veelgemaakte_fouten' => ['De tuinman'],
        'verduidelijkingen_vraag' => ["Met 'motief' bedoelen we: waarom?"],
    ];
}

function messageCount(PDO $db, int $teamId, string $sender): int {
    return (int)$db->query("SELECT COUNT(*) FROM team_messages WHERE team_id = $teamId AND sender = " . $db->quote($sender))->fetchColumn();
}

/**
 * Speelveld voor submitEvaluation: lopend spel, opdracht met goedgekeurde rubric, team met een
 * teambericht dat door 'agent-1' is geclaimd.
 */
function evalSetup(string $mode = 'auto', array $assignment = [], string $status = 'running'): array {
    $db = freshDb();
    $game = addGame($db, $status);
    addAssignment($db, $game, 1, $assignment + ['ai_rubric' => json_encode(validRubric()), 'ai_rubric_status' => 'approved']);
    addAssignment($db, $game, 2);
    $team = addTeam($db, $game, 'Speurders', 1, $mode);
    $msg = addMessage($db, $team, 1, 'team', 'Het was Jan, de conciërge. Hij had gokschulden.');
    claimTeam($db, $team, 'agent-1');
    return [$db, $game, $team, $msg];
}

function payload(int $team, int $msg, array $extra = []): array {
    return $extra + [
        'agent_id' => 'agent-1', 'team_id' => $team, 'level' => 1, 'message_id' => $msg,
        'intent' => 'antwoord', 'verdict' => 'goed', 'decision' => 'level_up',
        'reason' => 'Dader en motief goed.', 'reply_text' => 'Uitstekend speurwerk!',
        'models' => ['gpt-oss:20b-cloud', 'gpt-oss:120b-cloud'], 'duration_ms' => 1234,
        'intent_json' => ['output' => ['intentie' => 'antwoord', 'zeker' => true], 'thinking' => 'denk'],
        'progress' => ['aspects' => [['naam' => 'Dader', 'niveau' => 'goed', 'bewijs' => 'het was Jan, de conciërge', 'bericht' => $msg]], 'summary' => 'Dader genoemd.'],
    ];
}

// ---------------------------------------------------------------------------
echo "AI-modus per team\n";
$db = freshDb();
$g1 = addGame($db);
$g2 = addGame($db);
$a = addTeam($db, $g1, 'A');
$b = addTeam($db, $g1, 'B');
$c = addTeam($db, $g2, 'C');
check('geldige modi', isValidAiMode('off') && isValidAiMode('suggest') && isValidAiMode('reply') && isValidAiMode('auto'));
check('ongeldige modus geweigerd', !isValidAiMode('yolo') && !isValidAiMode(null) && !setTeamAiMode($db, $a, $g1, 'yolo', 1));
check('team op reply', setTeamAiMode($db, $a, $g1, 'reply', 1) && $db->query("SELECT ai_mode FROM teams WHERE id = $a")->fetchColumn() === 'reply');
check('team van een ander spel wordt niet gewijzigd', !setTeamAiMode($db, $c, $g1, 'auto', 1) && $db->query("SELECT ai_mode FROM teams WHERE id = $c")->fetchColumn() === 'suggest');
check('één TEAM_AI_MODE-regel (alleen de geslaagde actie)', auditCount($db, 'TEAM_AI_MODE') === 1);
check('alle teams: ongeldige modus geweigerd', setAllTeamsAiMode($db, $g1, 'nope', 1) === null && auditCount($db, 'GAME_AI_MODE') === 0);
check('alle teams: 2 teams gewijzigd', setAllTeamsAiMode($db, $g1, 'off', 1) === 2);
check('alleen teams van dat spel', $db->query("SELECT GROUP_CONCAT(ai_mode) FROM teams WHERE game_id = $g1")->fetchColumn() === 'off,off'
    && $db->query("SELECT ai_mode FROM teams WHERE id = $c")->fetchColumn() === 'suggest');
check('games.ai_mode gezet, ander spel niet', getGame($db, $g1)['ai_mode'] === 'off' && getGame($db, $g2)['ai_mode'] === 'suggest');
check('één GAME_AI_MODE-regel', auditCount($db, 'GAME_AI_MODE') === 1);
check('instellingen: buiten 1–50 geweigerd', updateGameAiSettings($db, $g1, 'reply', 0, 5, 1) !== null && updateGameAiSettings($db, $g1, 'reply', 5, 51, 1) !== null);
check('instellingen: ongeldige modus geweigerd', updateGameAiSettings($db, $g1, 'x', 5, 5, 1) !== null);
check('instellingen opgeslagen', updateGameAiSettings($db, $g1, 'reply', 3, 7, 1) === null
    && getGame($db, $g1)['ai_max_attempts'] == 3 && getGame($db, $g1)['ai_max_questions'] == 7 && getGame($db, $g1)['ai_mode'] === 'reply');

echo "Rubric\n";
check('geldige rubric', validateRubric(validRubric()) === null);
$broken = function (callable $f): array { $r = validRubric(); $f($r); return $r; };
$cases = [
    'zonder kern' => $broken(function (&$r) { $r['kern'] = '  '; }),
    'zonder aspecten' => $broken(function (&$r) { $r['aspecten'] = []; }),
    'aspect zonder naam' => $broken(function (&$r) { $r['aspecten'][0]['naam'] = ''; }),
    'verplicht geen bool' => $broken(function (&$r) { $r['aspecten'][0]['verplicht'] = 'ja'; }),
    'niveautekst ontbreekt' => $broken(function (&$r) { unset($r['aspecten'][1]['goed']); }),
    'geen verplicht aspect' => $broken(function (&$r) { $r['aspecten'][0]['verplicht'] = false; }),
    'dubbele aspectnaam' => $broken(function (&$r) { $r['aspecten'][1]['naam'] = 'dader'; }),
    'ook_goed geen lijst' => $broken(function (&$r) { $r['ook_goed'] = 'x'; }),
    'lijst met getal' => $broken(function (&$r) { $r['verduidelijkingen_vraag'] = [3]; }),
];
foreach ($cases as $name => $rubric) {
    $error = validateRubric($rubric);
    check("ongeldig: $name → duidelijke fout", is_string($error) && strlen($error) > 10);
}
check('optionele lijsten mogen ontbreken', validateRubric($broken(function (&$r) { unset($r['ook_goed'], $r['veelgemaakte_fouten'], $r['verduidelijkingen_vraag']); })) === null);

$db = freshDb();
$g1 = addGame($db);
$g2 = addGame($db);
$as = addAssignment($db, $g1, 1);
$other = addAssignment($db, $g2, 1);
$criteriaBefore = $db->query("SELECT criteria FROM assignments WHERE id = $as")->fetchColumn();
check('goedkeuren zonder rubric kan niet', approveRubric($db, $as, $g1, 1) !== null);
check('ongeldige rubric opslaan geweigerd', saveRubric($db, $as, $g1, ['kern' => 'x', 'aspecten' => []], 1) !== null
    && $db->query("SELECT ai_rubric_status FROM assignments WHERE id = $as")->fetchColumn() === 'none');
check('opslaan → draft', saveRubric($db, $as, $g1, validRubric(), 1) === null
    && $db->query("SELECT ai_rubric_status FROM assignments WHERE id = $as")->fetchColumn() === 'draft');
$saved = decodeRubric($db->query("SELECT ai_rubric FROM assignments WHERE id = $as")->fetchColumn());
check('opgeslagen rubric heeft de standaard niveauregel', $saved['niveau_regel'] === RUBRIC_DEFAULT_RULE);
check('goedkeuren → approved', approveRubric($db, $as, $g1, 1) === null && $db->query("SELECT ai_rubric_status FROM assignments WHERE id = $as")->fetchColumn() === 'approved');
$db->exec("UPDATE assignments SET ai_rubric = '{\"kern\":\"x\"}', ai_rubric_status = 'draft' WHERE id = $as");
check('goedkeuren van een ongeldige opgeslagen rubric kan niet', approveRubric($db, $as, $g1, 1) !== null
    && $db->query("SELECT ai_rubric_status FROM assignments WHERE id = $as")->fetchColumn() === 'draft');
check('opdracht van een ander spel: opslaan geweigerd', saveRubric($db, $other, $g1, validRubric(), 1) !== null
    && $db->query("SELECT ai_rubric FROM assignments WHERE id = $other")->fetchColumn() === null);
check('opdracht van een ander spel: goedkeuren geweigerd', approveRubric($db, $other, $g1, 1) !== null);
check('rubric weghalen → none', saveRubric($db, $as, $g1, null, 1) === null
    && $db->query("SELECT ai_rubric_status FROM assignments WHERE id = $as")->fetchColumn() === 'none');
check('criteria nooit aangeraakt', $db->query("SELECT criteria FROM assignments WHERE id = $as")->fetchColumn() === $criteriaBefore);
check('audit opslaan en goedkeuren', auditCount($db, 'ASSIGNMENT_RUBRIC_SAVE') === 2 && auditCount($db, 'ASSIGNMENT_RUBRIC_APPROVE') === 1);

$old = ['instruction' => 'Wie?', 'criteria' => 'Jan'];
foreach (['none', 'draft', 'approved'] as $status) {
    foreach ([['Wie?', 'Jan', false], ['Wie? ', 'Jan', false], ['Wie en waarom?', 'Jan', true], ['Wie?', 'Piet', true], ['Anders', 'Anders', true]] as [$instr, $crit, $changed]) {
        $expected = $status === 'approved' && $changed ? 'draft' : $status;
        check("rubricStatusAfterEdit($status, " . ($changed ? 'gewijzigd' : 'gelijk') . " '$instr'/'$crit') → $expected",
            rubricStatusAfterEdit($old + ['ai_rubric_status' => $status], ['instruction' => $instr, 'criteria' => $crit]) === $expected);
    }
}
$form = ['rubric_kern' => ' Kern ', 'aspect_naam' => ['Dader', '', 'Motief'], 'aspect_verplicht' => ['1', '0', '0'],
         'aspect_onvoldoende' => ['a', '', 'd'], 'aspect_voldoende' => ['b', '', 'e'], 'aspect_goed' => ['c', '', 'f'],
         'rubric_ook_goed' => "de conciërge\r\n\r\nJan", 'rubric_fouten' => '', 'rubric_verduidelijkingen' => 'Motief = waarom'];
$fromForm = rubricFromForm($form);
check('formulier → rubric: lege rij overgeslagen, verplicht als bool', count($fromForm['aspecten']) === 2 && $fromForm['aspecten'][0]['verplicht'] === true && $fromForm['aspecten'][1]['verplicht'] === false);
check('formulier → rubric is geldig en lijsten zonder lege regels', validateRubric($fromForm) === null && normalizeRubric($fromForm)['ook_goed'] === ['de conciërge', 'Jan']);
check('leeg formulier → null', rubricFromForm(['rubric_kern' => '', 'aspect_naam' => [''], 'rubric_ook_goed' => "\n"]) === null);

echo "Opruimen bij verwijderen/leegmaken\n";
$db = freshDb();
$g1 = addGame($db);
$g2 = addGame($db);
addAssignment($db, $g1, 1);
$t1 = addTeam($db, $g1, 'Een');
$t2 = addTeam($db, $g1, 'Twee');
$keep = addTeam($db, $g2, 'Ander');
foreach ([[$t1, $g1], [$t2, $g1], [$keep, $g2]] as [$t, $g]) {
    $db->exec("INSERT INTO ai_team_progress (game_id, team_id, assignment_number, summary) VALUES ($g, $t, 1, 'x')");
    $db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, decision) VALUES ($g, $t, 1, 1, 'suggest')");
}
deleteTeam($db, $t1);
check('deleteTeam: AI-rijen van dat team weg', (int)$db->query("SELECT (SELECT COUNT(*) FROM ai_team_progress WHERE team_id = $t1) + (SELECT COUNT(*) FROM ai_evaluations WHERE team_id = $t1)")->fetchColumn() === 0);
check('deleteTeam: andere teams houden hun rijen', (int)$db->query("SELECT COUNT(*) FROM ai_evaluations WHERE team_id IN ($t2, $keep)")->fetchColumn() === 2);
resetGame($db, $g1, 1);
check('resetGame: AI-rijen van dit spel weg', (int)$db->query("SELECT (SELECT COUNT(*) FROM ai_team_progress WHERE team_id = $t2) + (SELECT COUNT(*) FROM ai_evaluations WHERE team_id = $t2)")->fetchColumn() === 0);
check('resetGame: ander spel ongemoeid', (int)$db->query("SELECT (SELECT COUNT(*) FROM ai_team_progress WHERE team_id = $keep) + (SELECT COUNT(*) FROM ai_evaluations WHERE team_id = $keep)")->fetchColumn() === 2);

echo "Openstaande taken\n";
$db = freshDb();
$run = addGame($db, 'running');
$paused = addGame($db, 'paused');
$draft = addGame($db, 'draft');
$fin = addGame($db, 'finished');
foreach ([$run, $paused, $draft, $fin] as $g) {
    addAssignment($db, $g, 1, $g === $run ? ['ai_rubric' => json_encode(validRubric()), 'ai_rubric_status' => 'approved', 'ai_auto_allowed' => 0] : []);
    addAssignment($db, $g, 2);
}
$asId = (int)$db->query("SELECT id FROM assignments WHERE game_id = $run AND assignment_number = 1")->fetchColumn();
$db->exec("INSERT INTO assignment_ai_dialog (assignment_id, role, message) VALUES ($asId, 'ai', 'Telt de conciërge ook?'), ($asId, 'teacher', 'Ja, de naam hoeft niet.')");
$ok = addTeam($db, $run, 'Wacht op AI', 1, 'reply');
$m1 = addMessage($db, $ok, 1, 'team', 'Was het de tuinman?');
addMessage($db, $ok, 1, 'teacher', 'Nee, kijk naar camera 3.');
addMessage($db, $ok, 1, 'suggestion', 'GEHEIME SUGGESTIE');
addMessage($db, $ok, 1, 'ai', 'Goed bezig.');
$m2 = addMessage($db, $ok, 1, 'team', 'Dan de conciërge!');
addMessage($db, $ok, 2, 'team', 'Bericht van een ander level');
$pausedTeam = addTeam($db, $paused, 'Pauze');
addMessage($db, $pausedTeam, 1, 'team', 'hallo');
$offTeam = addTeam($db, $run, 'Uit', 1, 'off');
addMessage($db, $offTeam, 1, 'team', 'hallo');
foreach ([$draft, $fin] as $g) {
    addMessage($db, addTeam($db, $g, "Team $g"), 1, 'team', 'hallo');
}
$claimed = addTeam($db, $run, 'Geclaimd');
addMessage($db, $claimed, 1, 'team', 'hallo');
claimTeam($db, $claimed, 'agent-x');
$oldClaim = addTeam($db, $run, 'Oude claim');
addMessage($db, $oldClaim, 1, 'team', 'hallo');
$db->exec("UPDATE teams SET ai_processing_by = 'weg', ai_processing_at = datetime('now', '-6 minutes') WHERE id = $oldClaim");
$answered = [];
foreach (['teacher', 'ai', 'suggestion'] as $sender) {
    $answered[$sender] = addTeam($db, $run, "Beantwoord $sender");
    addMessage($db, $answered[$sender], 1, 'team', 'vraag');
    addMessage($db, $answered[$sender], 1, $sender, 'reactie');
}
$evaluated = addTeam($db, $run, 'Al beoordeeld');
$em = addMessage($db, $evaluated, 1, 'team', 'ok');
$db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, intent, decision) VALUES ($run, $evaluated, 1, $em, 'overig', 'ignored')");
$level0 = addTeam($db, $run, 'Level 0', 0);
addMessage($db, $level0, 0, 'team', 'hallo');

$tasks = aiPendingTasks($db);
$ids = array_column($tasks, 'team_id');
check('team met nieuw bericht staat erin', in_array($ok, $ids, true));
check('gepauzeerd spel staat erin', in_array($pausedTeam, $ids, true));
check('ai_mode off niet', !in_array($offTeam, $ids, true));
check('draft- en finished-spellen niet', count(array_intersect($ids, array_map('intval', $db->query("SELECT id FROM teams WHERE game_id IN ($draft, $fin)")->fetchAll(PDO::FETCH_COLUMN)))) === 0);
check('geclaimd (< 5 min) niet, verlopen claim wel', !in_array($claimed, $ids, true) && in_array($oldClaim, $ids, true));
check('al beantwoord door docent, ai of suggestie niet', !array_intersect(array_values($answered), $ids));
check('al beoordeeld bericht niet', !in_array($evaluated, $ids, true));
check('level 0 (geen opdracht) niet', !in_array($level0, $ids, true));
$task = $tasks[array_search($ok, $ids, true)];
check('message_id is het laatste teambericht van dit level', $task['message_id'] === $m2);
check('ai_mode, ai_auto_allowed, rubric en status', $task['ai_mode'] === 'reply' && $task['ai_auto_allowed'] === false && $task['rubric']['kern'] === validRubric()['kern'] && $task['rubric_status'] === 'approved');
check('verduidelijkingen uit de dialoog', $task['clarifications'] === [['role' => 'ai', 'message' => 'Telt de conciërge ook?'], ['role' => 'teacher', 'message' => 'Ja, de naam hoeft niet.']]);
check('messages zonder suggesties, op volgorde, alleen dit level', array_column($task['messages'], 'sender') === ['team', 'teacher', 'ai', 'team']
    && array_column($task['messages'], 'id') === array_values(array_filter(array_column($task['messages'], 'id'), function () { return true; }))
    && strpos(json_encode($task), 'GEHEIME') === false && strpos(json_encode($task), 'ander level') === false);
check('messages hebben id, sender, time en text', array_keys($task['messages'][0]) === ['id', 'sender', 'time', 'text'] && $task['messages'][0]['id'] === $m1);
check('geen e-mail of token in de taak', strpos(json_encode($task), '@school.nl') === false && !isset($task['access_token']));
check('nog geen escalatie of tussenstand', $task['escalation_open'] === false && $task['progress'] === null);
$db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, intent, decision, created_at) VALUES
    ($run, $ok, 1, $m1, 'antwoord', 'suggest', datetime('now', '-2 minutes')),
    ($run, $ok, 1, $m1, 'antwoord', 'escalate', datetime('now', '-3 minutes')),
    ($run, $ok, 1, $m1, 'antwoord', 'stale', datetime('now', '-3 minutes')),
    ($run, $ok, 1, $m1, 'antwoord', 'suggest', datetime('now', '-11 minutes')),
    ($run, $ok, 1, $m1, 'vraag', 'reply', datetime('now', '-1 minutes')),
    ($run, $ok, 1, $m1, 'overig', 'ignored', datetime('now', '-1 minutes')),
    ($run, $ok, 2, $m1, 'antwoord', 'suggest', datetime('now', '-1 minutes'))");
$db->exec("INSERT INTO ai_team_progress (game_id, team_id, assignment_number, aspects_json, summary, last_message_id) VALUES ($run, $ok, 1, '[]', 'Dader nog niet', $m1)");
$task = array_values(array_filter(aiPendingTasks($db), function ($t) use ($ok) { return $t['team_id'] === $ok; }))[0];
check('recent_attempts telt antwoorden van 10 min, dit level, zonder stale', $task['recent_attempts'] === 2);
check('recent_questions telt vragen', $task['recent_questions'] === 1);
check('escalation_open en progress', $task['escalation_open'] === true && $task['progress']['summary'] === 'Dader nog niet');

echo "Wachtrij en reactietijd\n";
$db = freshDb();
$g = addGame($db, 'running');
$other = addGame($db, 'running');
addAssignment($db, $g, 1);
addAssignment($db, $other, 1);
check('lege wachtrij', aiQueueStats($db, $g) === ['waiting' => 0, 'oldest_seconds' => null, 'evaluated' => 0, 'avg_response_seconds' => null, 'avg_processing_seconds' => null]);
$a = addTeam($db, $g, 'A');
$ma = addMessage($db, $a, 1, 'team', 'eerste');
$b = addTeam($db, $g, 'B');
$mb = addMessage($db, $b, 1, 'team', 'tweede');
$db->exec("UPDATE team_messages SET created_at = datetime('now', '-300 seconds') WHERE id = $ma");
$db->exec("UPDATE team_messages SET created_at = datetime('now', '-60 seconds') WHERE id = $mb");
$c = addTeam($db, $g, 'C');
addMessage($db, $c, 1, 'team', 'derde');
claimTeam($db, $c, 'agent-1');
addMessage($db, addTeam($db, $other, 'Ander spel'), 1, 'team', 'ander');
$stats = aiQueueStats($db, $g);
check('wachtrij telt ook teams die een agent nu verwerkt, alleen dit spel', $stats['waiting'] === 3);
check('oudste bericht wacht sinds 5 min', $stats['oldest_seconds'] >= 300 && $stats['oldest_seconds'] < 310);
check('limit: alleen de oudste, zonder geclaimde', array_column(aiPendingTasks($db, 1), 'team_id') === [$a] && count(aiPendingTasks($db)) === 3);
// A na 40 s beoordeeld (verwerking 30 s), B na 20 s (10 s); een verouderde en een van ruim een uur geleden tellen niet
$db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, intent, decision, duration_ms, created_at) VALUES
    ($g, $a, 1, $ma, 'antwoord', 'suggest', 30000, datetime('now', '-260 seconds')),
    ($g, $b, 1, $mb, 'antwoord', 'suggest', 10000, datetime('now', '-40 seconds')),
    ($g, $a, 1, $ma, 'antwoord', 'stale', 99000, datetime('now', '-100 seconds')),
    ($g, $c, 1, $ma, 'antwoord', 'suggest', 99000, datetime('now', '-61 minutes'))");
$stats = aiQueueStats($db, $g);
check('beoordeelde teams uit de wachtrij', $stats['waiting'] === 1);
check('afgelopen uur: aantal, gemiddelde reactie- en verwerkingstijd', $stats['evaluated'] === 2 && $stats['avg_response_seconds'] === 30 && $stats['avg_processing_seconds'] === 20);
check('ander spel heeft eigen cijfers', aiQueueStats($db, $other)['waiting'] === 1 && aiQueueStats($db, $other)['evaluated'] === 0);
check('duur kort weergegeven', formatWaitSeconds(45) === '45 s' && formatWaitSeconds(185) === '3 min' && formatWaitSeconds(3900) === '1 u 5 min' && formatWaitSeconds(7200) === '2 u');

echo "Serverregels\n";
$rank = ['ignored' => 0, 'suggest' => 1, 'reply' => 2, 'level_up' => 3];
$all = 0;
$neverHigher = true;
foreach (['running', 'paused', 'draft', 'finished'] as $status) {
    foreach (AI_MODES as $mode) {
        foreach ([true, false] as $autoAllowed) {
            foreach (['none', 'draft', 'approved'] as $rubricStatus) {
                foreach ([false, true] as $escalation) {
                    $ctx = ['game_status' => $status, 'ai_mode' => $mode, 'ai_auto_allowed' => $autoAllowed, 'rubric_status' => $rubricStatus, 'escalation_open' => $escalation];
                    foreach (['suggest', 'reply', 'level_up', 'escalate', 'ignored'] as $req) {
                        $out = allowedDecision($ctx, $req);
                        $all++;
                        if ($req === 'escalate') {
                            $neverHigher = $neverHigher && $out === 'escalate';
                            continue;
                        }
                        $neverHigher = $neverHigher && isset($rank[$out]) && $rank[$out] <= $rank[$req];
                        // Verwachting per regel
                        $expected = $req;
                        if ($req === 'ignored' || $mode === 'off' || !in_array($status, ['running', 'paused'], true)) {
                            $expected = 'ignored';
                        } elseif ($status === 'paused' || $rubricStatus !== 'approved' || $escalation || $mode === 'suggest') {
                            $expected = 'suggest';
                        } elseif (($mode === 'reply' || !$autoAllowed) && $req === 'level_up') {
                            $expected = 'reply';
                        }
                        if ($out !== $expected) {
                            check("allowedDecision($status, $mode, auto=" . (int)$autoAllowed . ", $rubricStatus, esc=" . (int)$escalation . ", $req) = $out, verwacht $expected", false);
                        }
                    }
                }
            }
        }
    }
}
check("nooit hoger dan gevraagd, escalate blijft escalate ($all combinaties)", $neverHigher);
check('paused → alleen suggestie', allowedDecision(['game_status' => 'paused', 'ai_mode' => 'auto', 'ai_auto_allowed' => true, 'rubric_status' => 'approved', 'escalation_open' => false], 'level_up') === 'suggest');
check('geen goedgekeurde rubric → suggest', allowedDecision(['game_status' => 'running', 'ai_mode' => 'auto', 'ai_auto_allowed' => true, 'rubric_status' => 'draft', 'escalation_open' => false], 'reply') === 'suggest');
check('auto + ai_auto_allowed 0 → als reply', allowedDecision(['game_status' => 'running', 'ai_mode' => 'auto', 'ai_auto_allowed' => false, 'rubric_status' => 'approved', 'escalation_open' => false], 'level_up') === 'reply');
check('open escalatie → alleen suggest', allowedDecision(['game_status' => 'running', 'ai_mode' => 'auto', 'ai_auto_allowed' => true, 'rubric_status' => 'approved', 'escalation_open' => true], 'reply') === 'suggest');
check('alles goed → level_up', allowedDecision(['game_status' => 'running', 'ai_mode' => 'auto', 'ai_auto_allowed' => true, 'rubric_status' => 'approved', 'escalation_open' => false], 'level_up') === 'level_up');
check('onbekende beslissing → suggest', allowedDecision(['game_status' => 'running', 'ai_mode' => 'auto', 'ai_auto_allowed' => true, 'rubric_status' => 'approved', 'escalation_open' => false], 'hack') === 'suggest');

echo "Tussenstand\n";
$db = freshDb();
$g = addGame($db);
addAssignment($db, $g, 1);
addAssignment($db, $g, 2);
$t = addTeam($db, $g, 'Een');
$u = addTeam($db, $g, 'Twee');
$p1 = addMessage($db, $t, 1, 'team', 'Het was  "Jan", de Conciërge!');
$p2 = addMessage($db, $t, 1, 'team', 'Nee wacht, het was Piet.');
$p3 = addMessage($db, $t, 1, 'team', 'Motief: gokschulden');
$otherLevel = addMessage($db, $t, 2, 'team', 'Motief: wraak');
$otherTeam = addMessage($db, $u, 1, 'team', 'Motief: jaloezie');
$teacherMsg = addMessage($db, $t, 1, 'teacher', 'De dader klopt');
check('citaat genormaliseerd (hoofdletters, witruimte, aanhalingstekens)', quoteInTeamMessage($db, $t, 1, $p1, '“het was jan, de conciërge”'));
check('citaat uit een docentbericht telt niet', !quoteInTeamMessage($db, $t, 1, $teacherMsg, 'De dader klopt'));
updateTeamProgress($db, $t, 1, [
    ['naam' => 'Dader', 'niveau' => 'goed', 'bewijs' => '"het was jan, de conciërge"', 'bericht' => $p1],
    ['naam' => 'Motief', 'niveau' => 'goed', 'bewijs' => 'Hij had een gokschuld van 10.000 euro', 'bericht' => $p3],
], 'Dader genoemd in bericht ' . $p1, $p1);
$progress = getTeamProgress($db, $t, 1);
check('geldig citaat overgenomen', count($progress['aspects']) === 1 && $progress['aspects'][0]['naam'] === 'Dader' && $progress['aspects'][0]['niveau'] === 'goed');
check('verzonnen citaat genegeerd', !in_array('Motief', array_column($progress['aspects'], 'naam'), true));
check('samenvatting en last_message_id opgeslagen', $progress['summary'] === "Dader genoemd in bericht $p1" && $progress['last_message_id'] === $p1);
updateTeamProgress($db, $t, 1, [
    ['naam' => 'Motief', 'niveau' => 'goed', 'bewijs' => 'Motief: wraak', 'bericht' => $otherLevel],
    ['naam' => 'Motief', 'niveau' => 'goed', 'bewijs' => 'Motief: jaloezie', 'bericht' => $otherTeam],
], null, $p2);
$progress = getTeamProgress($db, $t, 1);
check('citaat uit een ander level of team telt niet', !in_array('Motief', array_column($progress['aspects'], 'naam'), true));
check('zonder nieuwe samenvatting blijft de oude', $progress['summary'] === "Dader genoemd in bericht $p1");
updateTeamProgress($db, $t, 1, [['naam' => 'dader', 'niveau' => 'onvoldoende', 'bewijs' => 'het was Piet', 'bericht' => $p2]], 'Nu Piet', $p2);
$progress = getTeamProgress($db, $t, 1);
check('later standpunt overschrijft een eerder (ook lager)', count($progress['aspects']) === 1 && $progress['aspects'][0]['niveau'] === 'onvoldoende' && $progress['aspects'][0]['bericht'] === $p2);
updateTeamProgress($db, $t, 1, [['naam' => 'Dader', 'niveau' => 'goed', 'bewijs' => 'het was jan', 'bericht' => $p1]], null, $p3);
check('ouder citaat overschrijft een nieuwer standpunt niet', getTeamProgress($db, $t, 1)['aspects'][0]['bericht'] === $p2);
check('wissen', clearTeamProgress($db, $t, 1, 5) && getTeamProgress($db, $t, 1) === null && auditCount($db, 'AI_PROGRESS_CLEAR') === 1);

echo "submitEvaluation\n";
[$db, $game, $team, $msg] = evalSetup('suggest');
$r = submitEvaluation($db, payload($team, $msg, ['agent_id' => 'agent-2']));
check('claim van een andere agent → 409', $r['status'] === 409 && (int)$db->query("SELECT COUNT(*) FROM ai_evaluations")->fetchColumn() === 0);
check('ongeldige payload → 400', submitEvaluation($db, payload($team, $msg, ['intent' => 'hack']))['status'] === 400
    && submitEvaluation($db, payload($team, $msg, ['verdict' => 'super']))['status'] === 400
    && submitEvaluation($db, ['agent_id' => 'agent-1'])['status'] === 400);
check('onbekend team → 404', submitEvaluation($db, payload(999, $msg))['status'] === 404);
$r = submitEvaluation($db, payload($team, $msg));
check('suggest-modus: level_up gevraagd → suggest', $r['status'] === 200 && $r['decision'] === 'suggest' && teamLevel($db, $team) === 1);
$sugg = $db->query("SELECT * FROM team_messages WHERE sender = 'suggestion'")->fetch(PDO::FETCH_ASSOC);
check('suggestie heeft ai_evaluation_id', (int)$sugg['ai_evaluation_id'] === $r['evaluation_id'] && (int)$sugg['is_read'] === 0);
check('suggestie in vaste opmaak', strpos($sugg['message'], 'Intentie: antwoord') !== false && strpos($sugg['message'], 'Oordeel: goed → level-up-advies') !== false
    && strpos($sugg['message'], 'Reden: Dader en motief goed.') !== false && strpos($sugg['message'], "Voorgestelde reactie:\nUitstekend speurwerk!") !== false);
check('team daarna vrijgegeven', $db->query("SELECT ai_processing_by FROM teams WHERE id = $team")->fetchColumn() === null);
$eval = findEvaluationInGame($db, $r['evaluation_id'], $game);
check('beoordeling opgeslagen met modellen, duur en agent-json', $eval['models'] === 'gpt-oss:20b-cloud, gpt-oss:120b-cloud' && (int)$eval['duration_ms'] === 1234
    && json_decode($eval['intent_json'], true)['thinking'] === 'denk' && $eval['team_name'] === 'Speurders' && $eval['verdict'] === 'goed');
check('tussenstand bijgewerkt', getTeamProgress($db, $team, 1)['aspects'][0]['naam'] === 'Dader');
$audit = $db->query("SELECT * FROM audit_logs WHERE event_type = 'AI_SUGGEST'")->fetch(PDO::FETCH_ASSOC);
check('auditregel AI_SUGGEST met user_id NULL, agent en model', $audit && $audit['user_id'] === null && strpos($audit['description'], 'agent-1') !== false && strpos($audit['description'], 'gpt-oss:20b-cloud') !== false);
$tokens = $db->query("SELECT access_token FROM teams")->fetchAll(PDO::FETCH_COLUMN);
$auditText = implode(' ', $db->query("SELECT description FROM audit_logs")->fetchAll(PDO::FETCH_COLUMN));
check('audit bevat geen teamtekst, feedback of tokens', strpos($auditText, 'conciërge') === false && strpos($auditText, 'Uitstekend') === false
    && strpos($auditText, $tokens[0]) === false);
check('tweede inzending zonder claim → 409', submitEvaluation($db, payload($team, $msg))['status'] === 409);

[$db, $game, $team, $msg] = evalSetup('auto');
$newer = addMessage($db, $team, 1, 'team', 'En nog iets');
$r = submitEvaluation($db, payload($team, $msg));
check('stale (nieuwer teambericht): alleen opslaan', $r['decision'] === 'stale' && teamLevel($db, $team) === 1 && messageCount($db, $team, 'suggestion') === 0
    && messageCount($db, $team, 'ai') === 0 && getTeamProgress($db, $team, 1) === null && auditCount($db, 'AI_LEVEL_UP') === 0);
check('stale: beoordeling bewaard als stale en team vrij', $db->query("SELECT decision FROM ai_evaluations")->fetchColumn() === 'stale'
    && $db->query("SELECT ai_processing_by FROM teams WHERE id = $team")->fetchColumn() === null);
claimTeam($db, $team, 'agent-1');
check('stale (ander level)', submitEvaluation($db, payload($team, $newer, ['level' => 2]))['decision'] === 'stale');

[$db, $game, $team, $msg] = evalSetup('suggest');
$r = submitEvaluation($db, payload($team, $msg, ['decision' => 'escalate', 'reason' => 'Beoordelaar: voldoende, validator: onvoldoende']));
check('escalatie: suggestie met 🙋 en open escalatie', $r['decision'] === 'escalate' && hasOpenEscalation($db, $team, 1)
    && strpos($db->query("SELECT message FROM team_messages WHERE sender = 'suggestion'")->fetchColumn(), '🙋') === 0
    && $db->query("SELECT escalation_reason FROM ai_evaluations")->fetchColumn() === 'Beoordelaar: voldoende, validator: onvoldoende');
check('escalatie: team krijgt niets', messageCount($db, $team, 'ai') === 0 && auditCount($db, 'AI_ESCALATE') === 1);

[$db, $game, $team, $msg] = evalSetup('suggest');
$r = submitEvaluation($db, payload($team, $msg, ['intent' => 'overig', 'decision' => 'ignored', 'verdict' => null]));
check('overig/ignored: opgeslagen, geen bericht, team vrij', $r['decision'] === 'ignored' && messageCount($db, $team, 'suggestion') === 0
    && $db->query("SELECT ai_processing_by FROM teams WHERE id = $team")->fetchColumn() === null);
check('overig: daarna niet opnieuw in get_pending', !in_array($team, array_column(aiPendingTasks($db), 'team_id'), true));
$db->exec("UPDATE ai_evaluations SET decision = 'stale'");
check('een verouderde beoordeling blokkeert het bericht niet (bijv. na level terug)', in_array($team, array_column(aiPendingTasks($db), 'team_id'), true));

// reply
[$db, $game, $team, $msg] = evalSetup('reply');
$r = submitEvaluation($db, payload($team, $msg, ['decision' => 'reply', 'verdict' => 'onvoldoende', 'reply_text' => 'Kijk nog eens naar het motief.']));
check('reply + goedgekeurde rubric → ai-bericht', $r['decision'] === 'reply' && $db->query("SELECT message FROM team_messages WHERE sender = 'ai'")->fetchColumn() === 'Kijk nog eens naar het motief.');
check('reply onvoldoende: geen level-up-advies', messageCount($db, $team, 'suggestion') === 0 && auditCount($db, 'AI_REPLY') === 1);
[$db, $game, $team, $msg] = evalSetup('reply');
$r = submitEvaluation($db, payload($team, $msg, ['decision' => 'reply']));
check('reply voldoende/goed: ai-bericht + level-up-advies voor de docent', $r['decision'] === 'reply' && messageCount($db, $team, 'ai') === 1 && messageCount($db, $team, 'suggestion') === 1
    && strpos($db->query("SELECT message FROM team_messages WHERE sender = 'suggestion'")->fetchColumn(), 'level-up-advies') !== false && teamLevel($db, $team) === 1);
[$db, $game, $team, $msg] = evalSetup('reply');
$r = submitEvaluation($db, payload($team, $msg, ['decision' => 'level_up']));
check('reply-modus: level_up → advies, geen level-up', $r['decision'] === 'reply' && teamLevel($db, $team) === 1);
[$db, $game, $team, $msg] = evalSetup('reply', ['ai_rubric_status' => 'draft']);
check('reply zonder goedgekeurde rubric → alleen suggestie', submitEvaluation($db, payload($team, $msg, ['decision' => 'reply']))['decision'] === 'suggest' && messageCount($db, $team, 'ai') === 0);
[$db, $game, $team, $msg] = evalSetup('reply', [], 'paused');
check('reply bij paused → alleen suggestie', submitEvaluation($db, payload($team, $msg, ['decision' => 'reply']))['decision'] === 'suggest' && messageCount($db, $team, 'ai') === 0);
[$db, $game, $team, $msg] = evalSetup('reply');
$db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, decision) VALUES ($game, $team, 1, 1, 'escalate')");
check('reply bij open escalatie → alleen suggestie', submitEvaluation($db, payload($team, $msg, ['decision' => 'reply']))['decision'] === 'suggest' && messageCount($db, $team, 'ai') === 0);
[$db, $game, $team, $msg] = evalSetup('reply');
check('reply zonder tekst → suggestie', submitEvaluation($db, payload($team, $msg, ['decision' => 'reply', 'reply_text' => '  ']))['decision'] === 'suggest');
[$db, $game, $team, $msg] = evalSetup('auto');
$r = submitEvaluation($db, payload($team, $msg, ['intent' => 'vraag', 'decision' => 'level_up', 'verdict' => 'goed', 'reply_text' => 'Met motief bedoelen we waarom.']));
check('vraag levert nooit level-up of oordeel', $r['decision'] === 'reply' && teamLevel($db, $team) === 1 && $db->query("SELECT verdict FROM ai_evaluations")->fetchColumn() === null);

// level_up
[$db, $game, $team, $msg] = evalSetup('auto');
$r = submitEvaluation($db, payload($team, $msg));
check('auto + goedgekeurde rubric + running → level +1', $r['decision'] === 'level_up' && teamLevel($db, $team) === 2);
check('archief van level 1', (int)$db->query("SELECT COUNT(*) FROM completed_assignments WHERE team_id = $team AND assignment_number = 1")->fetchColumn() === 1);
check('ai-bericht met feedback op het nieuwe level', $db->query("SELECT assignment_number || ':' || message FROM team_messages WHERE sender = 'ai'")->fetchColumn() === '2:Uitstekend speurwerk!');
$audit = $db->query("SELECT * FROM audit_logs WHERE event_type = 'AI_LEVEL_UP'")->fetch(PDO::FETCH_ASSOC);
check('audit AI_LEVEL_UP met agent en model, user_id NULL', $audit && $audit['user_id'] === null && strpos($audit['description'], 'agent-1') !== false && strpos($audit['description'], 'gpt-oss') !== false);
check('TEAM_LEVEL_UP door de AI zonder user_id', $db->query("SELECT user_id FROM audit_logs WHERE event_type = 'TEAM_LEVEL_UP'")->fetchColumn() === null);
claimTeam($db, $team, 'agent-1');
$r = submitEvaluation($db, payload($team, $msg));
check('twee keer dezelfde payload → maar één level-up', $r['decision'] === 'stale' && teamLevel($db, $team) === 2 && auditCount($db, 'AI_LEVEL_UP') === 1);
[$db, $game, $team, $msg] = evalSetup('auto', ['ai_auto_allowed' => 0]);
check('auto + ai_auto_allowed 0 → reply', submitEvaluation($db, payload($team, $msg))['decision'] === 'reply' && teamLevel($db, $team) === 1 && messageCount($db, $team, 'ai') === 1);
[$db, $game, $team, $msg] = evalSetup('auto', [], 'paused');
check('auto bij paused → suggestie', submitEvaluation($db, payload($team, $msg))['decision'] === 'suggest' && teamLevel($db, $team) === 1);
[$db, $game, $team, $msg] = evalSetup('auto');
$db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, decision) VALUES ($game, $team, 1, 1, 'escalate')");
check('auto bij open escalatie → suggestie', submitEvaluation($db, payload($team, $msg))['decision'] === 'suggest' && teamLevel($db, $team) === 1);
[$db, $game, $team, $msg] = evalSetup('auto');
$db->exec("UPDATE teams SET current_level = 2 WHERE id = $team");
claimTeam($db, $team, 'agent-1');
check('auto, maar stale → niets', submitEvaluation($db, payload($team, $msg))['decision'] === 'stale' && teamLevel($db, $team) === 2);

echo "Escalaties afhandelen\n";
function escalationSetup(): array {
    $db = freshDb();
    $g = addGame($db);
    addAssignment($db, $g, 1);
    addAssignment($db, $g, 2);
    $t = addTeam($db, $g, 'Een', 1);
    $o = addTeam($db, $g, 'Ander', 1);
    addMessage($db, $t, 1, 'team', 'antwoord');
    foreach ([$t, $o] as $team) {
        $db->exec("INSERT INTO ai_evaluations (game_id, team_id, assignment_number, message_id, decision) VALUES ($g, $team, 1, 1, 'escalate')");
    }
    return [$db, $g, $t, $o];
}
[$db, $g, $t, $o] = escalationSetup();
check('twee teams met een open escalatie', countOpenEscalations($db, $g) === 2 && openEscalationsByTeam($db, $g) === [$t => 1, $o => 1]);
check('docentantwoord (resolveEscalations) sluit de escalatie', resolveEscalations($db, $t, 1, 7) === 1 && !hasOpenEscalation($db, $t, 1));
$row = $db->query("SELECT * FROM ai_evaluations WHERE team_id = $t")->fetch(PDO::FETCH_ASSOC);
check('resolved_by en resolved_at gevuld', (int)$row['resolved_by'] === 7 && $row['resolved_at'] !== null);
check('escalatie van een ander team blijft open', hasOpenEscalation($db, $o, 1) && countOpenEscalations($db, $g) === 1);
[$db, $g, $t, $o] = escalationSetup();
levelUp($db, $t, 7);
check('levelUp() sluit de escalatie', !hasOpenEscalation($db, $t, 1) && $db->query("SELECT resolved_by FROM ai_evaluations WHERE team_id = $t")->fetchColumn() == 7 && hasOpenEscalation($db, $o, 1));
[$db, $g, $t, $o] = escalationSetup();
$db->exec("UPDATE teams SET current_level = 2 WHERE id = $t");
$db->exec("UPDATE ai_evaluations SET assignment_number = 2 WHERE team_id = $t");
levelDown($db, $t, 7);
check('levelDown() sluit de escalatie van het verlaten level', !hasOpenEscalation($db, $t, 2) && $db->query("SELECT resolved_at FROM ai_evaluations WHERE team_id = $t")->fetchColumn() !== null && hasOpenEscalation($db, $o, 1));
[$db, $g, $t, $o] = escalationSetup();
resolveEscalations($db, $t, 1, 7, 'agree');
check('escalatieknop vult teacher_verdict', $db->query("SELECT teacher_verdict FROM ai_evaluations WHERE team_id = $t")->fetchColumn() === 'agree' && hasOpenEscalation($db, $o, 1));
$id = (int)$db->query("SELECT id FROM ai_evaluations WHERE team_id = $o")->fetchColumn();
$g2 = addGame($db);
check('teacher_verdict alleen binnen het eigen spel', !setTeacherVerdict($db, $id, $g2, 'agree', 1) && setTeacherVerdict($db, $id, $g, 'disagree', 1)
    && $db->query("SELECT teacher_verdict FROM ai_evaluations WHERE id = $id")->fetchColumn() === 'disagree' && !setTeacherVerdict($db, $id, $g, 'misschien', 1));

echo "Opdracht-assistent\n";
$db = freshDb();
$g = addGame($db, 'draft');
$g2 = addGame($db, 'draft');
$as = addAssignment($db, $g, 1);
$empty = addAssignment($db, $g, 2, ['criteria' => '']);
$foreign = addAssignment($db, $g2, 1);
$criteria = $db->query("SELECT criteria FROM assignments WHERE id = $as")->fetchColumn();
$status = function () use ($db, $as) { return $db->query("SELECT ai_design_status FROM assignments WHERE id = $as")->fetchColumn(); };
check('zonder criteria geen aanvraag', requestDesign($db, $empty, $g, 1) !== null && $db->query("SELECT ai_design_status FROM assignments WHERE id = $empty")->fetchColumn() === 'idle');
check('opdracht van een ander spel geweigerd', requestDesign($db, $foreign, $g, 1) !== null);
check('aanvraag → pending', requestDesign($db, $as, $g, 1) === null && $status() === 'pending' && auditCount($db, 'ASSIGNMENT_AI_REQUEST') === 1);
check('dubbele aanvraag geweigerd', requestDesign($db, $as, $g, 1) !== null);
$jobs = pendingDesignJobs($db);
check('pendingDesignJobs geeft de opdracht met ronde 1', count($jobs) === 1 && $jobs[0]['assignment_id'] === $as && $jobs[0]['round'] === 1 && $jobs[0]['criteria'] === $criteria);
check('claim lukt', claimDesign($db, $as, 'agent-1'));
check('tweede agent krijgt de claim niet', !claimDesign($db, $as, 'agent-2') && pendingDesignJobs($db) === []);
check('submit door een andere agent → 409', submitDesign($db, ['agent_id' => 'agent-2', 'assignment_id' => $as, 'questions' => ['Vraag?']])['status'] === 409);
check('zes vragen → 400', submitDesign($db, ['agent_id' => 'agent-1', 'assignment_id' => $as, 'questions' => ['1', '2', '3', '4', '5', '6']])['status'] === 400);
check('ongeldige rubric geweigerd, status blijft pending', submitDesign($db, ['agent_id' => 'agent-1', 'assignment_id' => $as, 'rubric' => ['kern' => 'x', 'aspecten' => []]])['status'] === 400 && $status() === 'pending');
$r = submitDesign($db, ['agent_id' => 'agent-1', 'assignment_id' => $as, 'questions' => ['Telt "de conciërge" ook?', 'Staat de naam in de bijlage?']]);
check('vragen → dialoog en status questions', $r['status'] === 200 && $status() === 'questions'
    && assignmentDialog($db, $as)[0]['message'] === "1. Telt \"de conciërge\" ook?\n2. Staat de naam in de bijlage?" && assignmentDialog($db, $as)[0]['role'] === 'ai');
check('claim vrijgegeven', $db->query("SELECT ai_processing_by FROM assignments WHERE id = $as")->fetchColumn() === null);
check('antwoord zonder tekst geweigerd', addTeacherAnswer($db, $as, $g, '  ', 1) !== null);
check('antwoord → pending', addTeacherAnswer($db, $as, $g, 'Ja, de naam hoeft niet.', 1) === null && $status() === 'pending' && assignmentDialog($db, $as)[1]['role'] === 'teacher');
check('antwoord als de AI niet wacht geweigerd', addTeacherAnswer($db, $as, $g, 'Nog iets', 1) !== null);
foreach ([2, 3] as $round) {
    claimDesign($db, $as, 'agent-1');
    submitDesign($db, ['agent_id' => 'agent-1', 'assignment_id' => $as, 'questions' => ["Vraag ronde $round?"]]);
    addTeacherAnswer($db, $as, $g, "Antwoord ronde $round", 1);
}
check('na 3 rondes: ronde 4 in de taak', pendingDesignJobs($db)[0]['round'] === 4);
claimDesign($db, $as, 'agent-1');
check('ronde 4 met vragen geweigerd', submitDesign($db, ['agent_id' => 'agent-1', 'assignment_id' => $as, 'questions' => ['Nog een vraag?']])['status'] === 400 && $status() === 'pending');
$r = submitDesign($db, ['agent_id' => 'agent-1', 'assignment_id' => $as, 'rubric' => validRubric()]);
$row = $db->query("SELECT * FROM assignments WHERE id = $as")->fetch(PDO::FETCH_ASSOC);
check('rubric → concept en idle', $r['status'] === 200 && $row['ai_rubric_status'] === 'draft' && $row['ai_design_status'] === 'idle' && decodeRubric($row['ai_rubric'])['kern'] === validRubric()['kern']);
check('criteria ongewijzigd', $row['criteria'] === $criteria);
check('opnieuw aanvragen begint bij ronde 1', requestDesign($db, $as, $g, 1) === null && pendingDesignJobs($db)[0]['round'] === 1);
claimDesign($db, $as, 'agent-1');
check('fout → failed', submitDesign($db, ['agent_id' => 'agent-1', 'assignment_id' => $as, 'error' => 'LLM onbereikbaar'])['status'] === 200 && $status() === 'failed');
check('na failed opnieuw aanvragen kan', requestDesign($db, $as, $g, 1) === null);

echo "Heartbeat en agentstatus\n";
$db = freshDb();
check('geen agent → inactive', aiAgentStatus($db) === ['status' => 'inactive', 'count' => 0]);
recordHeartbeat($db, 'agent-1', false);
check('agent zonder werkend model → model_down', aiAgentStatus($db) === ['status' => 'model_down', 'count' => 1]);
recordHeartbeat($db, 'agent-2', true);
check('één agent met werkend model → active', aiAgentStatus($db) === ['status' => 'active', 'count' => 2]);
recordHeartbeat($db, 'agent-2', false);
check('heartbeat werkt model_ok bij (geen dubbele rij)', aiAgentStatus($db) === ['status' => 'model_down', 'count' => 2]);

if ($failures > 0) {
    echo "\n$failures test(s) mislukt\n";
    exit(1);
}
echo "\nAlle tests geslaagd\n";
