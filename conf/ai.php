<?php
/**
 * Agentic AI: modus per team, rubric per opdracht, openstaande taken voor de agent, de serverregels
 * voor wat de AI mag, de tussenstand, beoordelingen, escalaties en de opdracht-assistent.
 * Ontwerp: agentic-service/README.md.
 *
 * Net als game.php: functies krijgen een PDO mee en gebruiken geen sessie, zodat ze met SQLite in het
 * geheugen te testen zijn (tests/ai_test.php). Tijden zijn UTC; vergelijk en reken in SQL.
 *
 * De PHP-app blijft de baas: de agent stelt een beslissing voor, submitEvaluation() controleert opnieuw
 * wat volgens het spel, het team en de opdracht mag (allowedDecision()) en stelt alleen omlaag bij.
 */

require_once __DIR__ . '/game.php';

const AI_MODES = ['off', 'suggest', 'reply', 'auto'];
const AI_MODE_LABELS = [
    'off' => 'Uit',
    'suggest' => 'Suggesties',
    'reply' => 'Antwoorden',
    'auto' => 'Automatisch',
];
const AI_MODE_DESCRIPTIONS = [
    'off' => 'De AI slaat het team over.',
    'suggest' => 'Alleen suggesties voor de docent.',
    'reply' => 'De AI antwoordt het team; level-up blijft bij de docent.',
    'auto' => 'De AI antwoordt en zet zelf een level omhoog als alles klopt.',
];
const AI_VERDICTS = ['onvoldoende', 'voldoende', 'goed'];
const AI_INTENTS = ['antwoord', 'vraag', 'overig'];
// Door de agent gevraagde beslissingen; 'stale' bepaalt alleen de server
const AI_DECISIONS = ['ignored', 'suggest', 'reply', 'level_up', 'escalate'];
// Volgorde voor "nooit hoger dan gevraagd"; escalate staat er los van
const AI_DECISION_RANK = ['ignored' => 0, 'suggest' => 1, 'reply' => 2, 'level_up' => 3];
const AI_CLAIM_MINUTES = 5;
const AI_RECENT_MINUTES = 10;
const AI_LIMIT_MIN = 1;
const AI_LIMIT_MAX = 50;
const AI_MAX_DESIGN_ROUNDS = 3;
const AI_MAX_DESIGN_QUESTIONS = 5;
const AI_JSON_MAX_BYTES = 200000;
const AI_TEXT_MAX_LENGTH = 4000;
const RUBRIC_TEXT_MAX_LENGTH = 2000;
const RUBRIC_NAME_MAX_LENGTH = 100;
const RUBRIC_DEFAULT_RULE = 'voldoende = alle verplichte aspecten minimaal voldoende; goed = alle aspecten goed';
const RUBRIC_STATUS_LABELS = ['none' => 'Geen rubric', 'draft' => 'Concept', 'approved' => 'AI-klaar ✓'];

// ---------------------------------------------------------------------------
// AI-modus per team en per spel
// ---------------------------------------------------------------------------

function isValidAiMode($mode): bool {
    return is_string($mode) && in_array($mode, AI_MODES, true);
}

function aiModeLabel(string $mode): string {
    return AI_MODE_LABELS[$mode] ?? $mode;
}

function aiModeBadge(string $mode): string {
    $class = ['off' => '', 'suggest' => ' badge-info', 'reply' => ' badge-brand', 'auto' => ' badge-success'][$mode] ?? '';
    return '<span class="badge' . $class . '" title="' . htmlspecialchars(AI_MODE_DESCRIPTIONS[$mode] ?? '') . '">' . icon('bot')
        . 'AI: ' . htmlspecialchars(aiModeLabel($mode)) . '</span>';
}

/**
 * Zet de modus van één team van dit spel. False bij een ongeldige modus of een team van een ander spel.
 */
function setTeamAiMode(PDO $db, int $teamId, int $gameId, string $mode, ?int $userId): bool {
    if (!isValidAiMode($mode)) {
        return false;
    }
    $team = findTeamInGame($db, $teamId, $gameId);
    if (!$team) {
        return false;
    }
    $db->prepare("UPDATE teams SET ai_mode = ? WHERE id = ? AND game_id = ?")->execute([$mode, $teamId, $gameId]);
    logAudit($db, $userId, 'TEAM_AI_MODE', "AI-modus van team '{$team['team_name']}' (ID $teamId) van " . ($team['ai_mode'] ?? '?') . " naar $mode");
    return true;
}

/**
 * Zet alle teams van het spel op dezelfde modus en bewaart die als standaard voor nieuwe teams.
 * Geeft het aantal gewijzigde teams terug, of null bij een ongeldige modus.
 */
function setAllTeamsAiMode(PDO $db, int $gameId, string $mode, ?int $userId): ?int {
    if (!isValidAiMode($mode)) {
        return null;
    }
    $stmt = $db->prepare("UPDATE teams SET ai_mode = ? WHERE game_id = ?");
    $stmt->execute([$mode, $gameId]);
    $db->prepare("UPDATE games SET ai_mode = ? WHERE id = ?")->execute([$mode, $gameId]);
    logAudit($db, $userId, 'GAME_AI_MODE', "AI-modus van alle {$stmt->rowCount()} teams van spel #$gameId (en de standaard) naar $mode");
    return $stmt->rowCount();
}

/**
 * AI-instellingen van een spel (blok "AI" in game.php). Geeft een foutmelding terug, of null.
 */
function updateGameAiSettings(PDO $db, int $gameId, string $mode, int $maxAttempts, int $maxQuestions, ?int $userId): ?string {
    if (!isValidAiMode($mode)) {
        return 'Kies een geldige AI-modus.';
    }
    foreach (['pogingen' => $maxAttempts, 'vragen' => $maxQuestions] as $label => $value) {
        if ($value < AI_LIMIT_MIN || $value > AI_LIMIT_MAX) {
            return "Het maximum aantal $label moet tussen " . AI_LIMIT_MIN . ' en ' . AI_LIMIT_MAX . ' liggen.';
        }
    }
    $db->prepare("UPDATE games SET ai_mode = ?, ai_max_attempts = ?, ai_max_questions = ? WHERE id = ?")
       ->execute([$mode, $maxAttempts, $maxQuestions, $gameId]);
    logAudit($db, $userId, 'GAME_AI_SETTINGS', "AI-instellingen van spel #$gameId: standaardmodus $mode, max. $maxAttempts pogingen en $maxQuestions vragen per 10 minuten");
    return null;
}

// ---------------------------------------------------------------------------
// Rubric per opdracht
// ---------------------------------------------------------------------------

function rubricStatusBadge(string $status): string {
    $class = ['none' => '', 'draft' => ' badge-warning', 'approved' => ' badge-success'][$status] ?? '';
    return '<span class="badge rubric-badge' . $class . '">' . htmlspecialchars(RUBRIC_STATUS_LABELS[$status] ?? $status) . '</span>';
}

function textLength(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function textCut(string $text, int $max): string {
    return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}

/**
 * Controleert een rubric (als array). Geeft een foutmelding in gewone taal terug, of null.
 */
function validateRubric(array $rubric): ?string {
    if (!isset($rubric['kern']) || !is_string($rubric['kern']) || trim($rubric['kern']) === '') {
        return 'Vul de kern van het antwoord in.';
    }
    if (textLength($rubric['kern']) > RUBRIC_TEXT_MAX_LENGTH) {
        return 'De kern is te lang (maximaal ' . RUBRIC_TEXT_MAX_LENGTH . ' tekens).';
    }
    if (!isset($rubric['aspecten']) || !is_array($rubric['aspecten']) || count($rubric['aspecten']) === 0) {
        return 'Een rubric heeft minimaal één aspect.';
    }
    $names = [];
    $required = 0;
    foreach (array_values($rubric['aspecten']) as $i => $aspect) {
        $nr = $i + 1;
        if (!is_array($aspect)) {
            return "Aspect $nr is ongeldig.";
        }
        if (!isset($aspect['naam']) || !is_string($aspect['naam']) || trim($aspect['naam']) === '') {
            return "Aspect $nr heeft geen naam.";
        }
        $name = trim($aspect['naam']);
        if (textLength($name) > RUBRIC_NAME_MAX_LENGTH) {
            return "De naam van aspect $nr is te lang (maximaal " . RUBRIC_NAME_MAX_LENGTH . ' tekens).';
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (isset($names[$key])) {
            return "Er zijn twee aspecten met de naam '$name'.";
        }
        $names[$key] = true;
        if (!isset($aspect['verplicht']) || !is_bool($aspect['verplicht'])) {
            return "Geef bij aspect '$name' aan of het verplicht is.";
        }
        $required += $aspect['verplicht'] ? 1 : 0;
        foreach (AI_VERDICTS as $level) {
            if (!isset($aspect[$level]) || !is_string($aspect[$level]) || trim($aspect[$level]) === '') {
                return "Vul bij aspect '$name' de omschrijving voor '$level' in.";
            }
            if (textLength($aspect[$level]) > RUBRIC_TEXT_MAX_LENGTH) {
                return "De omschrijving '$level' bij aspect '$name' is te lang.";
            }
        }
    }
    if ($required === 0) {
        return 'Maak minimaal één aspect verplicht.';
    }
    foreach (['ook_goed' => 'Ook goed', 'veelgemaakte_fouten' => 'Veelgemaakte fouten', 'verduidelijkingen_vraag' => 'Verduidelijkingen van de vraag'] as $field => $label) {
        if (!isset($rubric[$field])) {
            continue;
        }
        if (!is_array($rubric[$field])) {
            return "'$label' moet een lijst zijn.";
        }
        foreach ($rubric[$field] as $item) {
            if (!is_string($item) || textLength($item) > RUBRIC_TEXT_MAX_LENGTH) {
                return "'$label' bevat een ongeldige of te lange regel.";
            }
        }
    }
    if (isset($rubric['niveau_regel']) && (!is_string($rubric['niveau_regel']) || textLength($rubric['niveau_regel']) > RUBRIC_TEXT_MAX_LENGTH)) {
        return 'De niveauregel is ongeldig.';
    }
    return null;
}

/**
 * Vaste vorm van een (geldige) rubric: getrimde teksten, lege regels uit de lijsten, alleen bekende velden.
 */
function normalizeRubric(array $rubric): array {
    $list = function ($items): array {
        return array_values(array_filter(array_map('trim', is_array($items) ? array_filter($items, 'is_string') : []), function ($s) { return $s !== ''; }));
    };
    $aspects = [];
    foreach (array_values($rubric['aspecten'] ?? []) as $a) {
        $aspects[] = [
            'naam' => trim((string)$a['naam']),
            'verplicht' => (bool)$a['verplicht'],
            'onvoldoende' => trim((string)$a['onvoldoende']),
            'voldoende' => trim((string)$a['voldoende']),
            'goed' => trim((string)$a['goed']),
        ];
    }
    $rule = trim((string)($rubric['niveau_regel'] ?? ''));
    return [
        'kern' => trim((string)$rubric['kern']),
        'aspecten' => $aspects,
        'ook_goed' => $list($rubric['ook_goed'] ?? []),
        'veelgemaakte_fouten' => $list($rubric['veelgemaakte_fouten'] ?? []),
        'verduidelijkingen_vraag' => $list($rubric['verduidelijkingen_vraag'] ?? []),
        'niveau_regel' => $rule !== '' ? $rule : RUBRIC_DEFAULT_RULE,
    ];
}

function decodeRubric(?string $json): ?array {
    if ($json === null || $json === '') {
        return null;
    }
    $rubric = json_decode($json, true);
    return is_array($rubric) ? $rubric : null;
}

/**
 * Een rubric uit het formulier in assignments.php (tabel + lijstvelden, één regel per item).
 * Een helemaal leeg formulier geeft null (= geen rubric).
 */
function rubricFromForm(array $post): ?array {
    $lines = function (string $field) use ($post): array {
        return preg_split('/\R/u', (string)($post[$field] ?? '')) ?: [];
    };
    $aspects = [];
    $names = (array)($post['aspect_naam'] ?? []);
    foreach ($names as $i => $name) {
        $row = [
            'naam' => trim((string)$name),
            'verplicht' => ($post['aspect_verplicht'][$i] ?? '0') === '1',
            'onvoldoende' => trim((string)($post['aspect_onvoldoende'][$i] ?? '')),
            'voldoende' => trim((string)($post['aspect_voldoende'][$i] ?? '')),
            'goed' => trim((string)($post['aspect_goed'][$i] ?? '')),
        ];
        // Een helemaal lege rij is een niet-gebruikte rij
        if ($row['naam'] === '' && $row['onvoldoende'] === '' && $row['voldoende'] === '' && $row['goed'] === '') {
            continue;
        }
        $aspects[] = $row;
    }
    $rubric = [
        'kern' => trim((string)($post['rubric_kern'] ?? '')),
        'aspecten' => $aspects,
        'ook_goed' => $lines('rubric_ook_goed'),
        'veelgemaakte_fouten' => $lines('rubric_fouten'),
        'verduidelijkingen_vraag' => $lines('rubric_verduidelijkingen'),
        'niveau_regel' => trim((string)($post['rubric_niveau_regel'] ?? '')),
    ];
    $empty = $rubric['kern'] === '' && !$aspects && !array_filter(array_map('trim', array_merge($rubric['ook_goed'], $rubric['veelgemaakte_fouten'], $rubric['verduidelijkingen_vraag'])));
    return $empty ? null : $rubric;
}

/**
 * Slaat een rubric op als concept. Null als rubric haalt hem weg (status none). Geeft een foutmelding
 * terug, of null. `criteria` (het antwoord van de docent) wordt nooit aangeraakt.
 */
function saveRubric(PDO $db, int $assignmentId, int $gameId, ?array $rubric, ?int $userId, string $source = 'docent'): ?string {
    $assignment = findAssignmentInGame($db, $assignmentId, $gameId);
    if (!$assignment) {
        return 'Deze opdracht hoort niet bij het actieve spel.';
    }
    if ($rubric === null) {
        $db->prepare("UPDATE assignments SET ai_rubric = NULL, ai_rubric_status = 'none' WHERE id = ? AND game_id = ?")->execute([$assignmentId, $gameId]);
        logAudit($db, $userId, 'ASSIGNMENT_RUBRIC_SAVE', "Rubric van opdracht #{$assignment['assignment_number']} (ID $assignmentId, spel #$gameId) verwijderd");
        return null;
    }
    if ($error = validateRubric($rubric)) {
        return $error;
    }
    $json = json_encode(normalizeRubric($rubric), JSON_UNESCAPED_UNICODE);
    $db->prepare("UPDATE assignments SET ai_rubric = ?, ai_rubric_status = 'draft' WHERE id = ? AND game_id = ?")->execute([$json, $assignmentId, $gameId]);
    logAudit($db, $userId, 'ASSIGNMENT_RUBRIC_SAVE', "Rubric van opdracht #{$assignment['assignment_number']} (ID $assignmentId, spel #$gameId) opgeslagen als concept ($source)");
    return null;
}

/**
 * Keurt de opgeslagen rubric goed (alleen als die geldig is). Geeft een foutmelding terug, of null.
 */
function approveRubric(PDO $db, int $assignmentId, int $gameId, ?int $userId): ?string {
    $assignment = findAssignmentInGame($db, $assignmentId, $gameId);
    if (!$assignment) {
        return 'Deze opdracht hoort niet bij het actieve spel.';
    }
    $rubric = decodeRubric($assignment['ai_rubric']);
    if ($rubric === null) {
        return 'Er is nog geen rubric om goed te keuren.';
    }
    if ($error = validateRubric($rubric)) {
        return "De rubric is niet geldig: $error";
    }
    $db->prepare("UPDATE assignments SET ai_rubric_status = 'approved' WHERE id = ? AND game_id = ?")->execute([$assignmentId, $gameId]);
    logAudit($db, $userId, 'ASSIGNMENT_RUBRIC_APPROVE', "Rubric van opdracht #{$assignment['assignment_number']} (ID $assignmentId, spel #$gameId) goedgekeurd");
    return null;
}

/**
 * Status van de rubric nadat de docent de opdracht opslaat: een goedgekeurde rubric gaat terug naar
 * concept als de instructie of de criteria (het antwoord) veranderd zijn.
 */
function rubricStatusAfterEdit(array $old, array $new): string {
    $status = $old['ai_rubric_status'] ?? 'none';
    if ($status !== 'approved') {
        return $status;
    }
    foreach (['instruction', 'criteria'] as $field) {
        if (trim((string)($old[$field] ?? '')) !== trim((string)($new[$field] ?? ''))) {
            return 'draft';
        }
    }
    return 'approved';
}

// ---------------------------------------------------------------------------
// Openstaande taken voor de agent (get_pending)
// ---------------------------------------------------------------------------

/**
 * Berichten van een level voor de agent: team, docent en AI, nooit suggesties. Geen namen.
 */
function aiTaskMessages(PDO $db, int $teamId, int $level): array {
    $stmt = $db->prepare("SELECT id, sender, created_at, message FROM team_messages
        WHERE team_id = ? AND assignment_number = ? AND sender IN ('team', 'teacher', 'ai') ORDER BY created_at ASC, id ASC");
    $stmt->execute([$teamId, $level]);
    return array_map(function ($m) {
        return ['id' => (int)$m['id'], 'sender' => $m['sender'], 'time' => $m['created_at'], 'text' => $m['message']];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Aantal beoordeelde antwoorden en vragen van dit team op dit level in de laatste 10 minuten.
 */
function aiRecentCounts(PDO $db, int $teamId, int $level): array {
    $stmt = $db->prepare("SELECT intent, COUNT(*) FROM ai_evaluations
        WHERE team_id = ? AND assignment_number = ? AND intent IN ('antwoord', 'vraag')
        AND decision NOT IN ('stale', 'ignored') AND created_at > datetime('now', ?)
        GROUP BY intent");
    $stmt->execute([$teamId, $level, '-' . AI_RECENT_MINUTES . ' minutes']);
    $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['attempts' => (int)($counts['antwoord'] ?? 0), 'questions' => (int)($counts['vraag'] ?? 0)];
}

function hasOpenEscalation(PDO $db, int $teamId, int $level): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM ai_evaluations WHERE team_id = ? AND assignment_number = ? AND decision = 'escalate' AND resolved_at IS NULL");
    $stmt->execute([$teamId, $level]);
    return $stmt->fetchColumn() > 0;
}

function getTeamProgress(PDO $db, int $teamId, int $level): ?array {
    $stmt = $db->prepare("SELECT * FROM ai_team_progress WHERE team_id = ? AND assignment_number = ?");
    $stmt->execute([$teamId, $level]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $aspects = json_decode((string)$row['aspects_json'], true);
    return [
        'aspects' => is_array($aspects) ? $aspects : [],
        'summary' => (string)$row['summary'],
        'last_message_id' => $row['last_message_id'] === null ? null : (int)$row['last_message_id'],
        'updated_at' => $row['updated_at'],
    ];
}

function assignmentDialog(PDO $db, int $assignmentId): array {
    $stmt = $db->prepare("SELECT id, role, message, created_at FROM assignment_ai_dialog WHERE assignment_id = ? ORDER BY id ASC");
    $stmt->execute([$assignmentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Teams waarvan het laatste teambericht nog geen reactie (docent, AI of suggestie) en nog geen
 * (niet-verouderde) beoordeling heeft, in lopende of gepauzeerde spellen, met AI-modus ≠ off en zonder actieve claim.
 * Per team alles wat de agent nodig heeft.
 */
function aiPendingTasks(PDO $db): array {
    $stmt = $db->prepare("
        SELECT t.id AS team_id, t.team_name, t.game_id, t.current_level, t.ai_mode,
               " . effectiveStatusSql('g') . " AS game_status, g.ai_max_attempts, g.ai_max_questions,
               a.id AS assignment_id, a.title AS assignment_title, a.description, a.instruction, a.criteria, a.time_limit,
               a.ai_rubric, a.ai_rubric_status, a.ai_auto_allowed,
               tm.id AS message_id, tm.created_at AS message_time
        FROM teams t
        JOIN games g ON g.id = t.game_id
        JOIN assignments a ON a.game_id = t.game_id AND a.assignment_number = t.current_level
        JOIN team_messages tm ON tm.id = (SELECT MAX(id) FROM team_messages WHERE team_id = t.id AND assignment_number = t.current_level AND sender = 'team')
        WHERE " . effectiveStatusSql('g') . " IN ('running', 'paused')
        AND COALESCE(t.ai_mode, 'suggest') != 'off'
        AND NOT EXISTS (SELECT 1 FROM team_messages r WHERE r.team_id = t.id AND r.assignment_number = t.current_level
                        AND r.sender IN ('teacher', 'ai', 'suggestion') AND r.id > tm.id)
        AND NOT EXISTS (SELECT 1 FROM ai_evaluations e WHERE e.team_id = t.id AND e.message_id = tm.id AND e.decision != 'stale')
        AND (t.ai_processing_at IS NULL OR t.ai_processing_at <= datetime('now', ?))
        ORDER BY tm.created_at ASC, tm.id ASC
    ");
    $stmt->execute(['-' . AI_CLAIM_MINUTES . ' minutes']);
    $tasks = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $teamId = (int)$row['team_id'];
        $level = (int)$row['current_level'];
        $counts = aiRecentCounts($db, $teamId, $level);
        $tasks[] = [
            'team_id' => $teamId,
            'team_name' => $row['team_name'], // alleen voor logging; gaat nooit naar het model
            'game_id' => (int)$row['game_id'],
            'game_status' => $row['game_status'],
            'current_level' => $level,
            'assignment_id' => (int)$row['assignment_id'],
            'assignment_title' => $row['assignment_title'],
            'description' => (string)$row['description'],
            'instruction' => (string)$row['instruction'],
            'criteria' => (string)$row['criteria'],
            'time_limit' => (int)$row['time_limit'],
            'message_id' => (int)$row['message_id'],
            'message_time' => $row['message_time'],
            'ai_mode' => $row['ai_mode'] ?: 'suggest',
            'ai_auto_allowed' => (int)$row['ai_auto_allowed'] === 1,
            'rubric' => decodeRubric($row['ai_rubric']),
            'rubric_status' => $row['ai_rubric_status'] ?: 'none',
            'clarifications' => array_map(function ($d) {
                return ['role' => $d['role'], 'message' => $d['message']];
            }, assignmentDialog($db, (int)$row['assignment_id'])),
            'recent_attempts' => $counts['attempts'],
            'recent_questions' => $counts['questions'],
            'max_attempts' => (int)($row['ai_max_attempts'] ?? 5),
            'max_questions' => (int)($row['ai_max_questions'] ?? 5),
            'escalation_open' => hasOpenEscalation($db, $teamId, $level),
            'progress' => getTeamProgress($db, $teamId, $level),
            'messages' => aiTaskMessages($db, $teamId, $level),
        ];
    }
    return $tasks;
}

/**
 * Claimt een team voor een agent (lock van 5 minuten, of verlengen door dezelfde agent).
 */
function claimTeam(PDO $db, int $teamId, string $agentId): bool {
    $stmt = $db->prepare("UPDATE teams SET ai_processing_by = ?, ai_processing_at = datetime('now')
        WHERE id = ? AND (ai_processing_at IS NULL OR ai_processing_at <= datetime('now', ?) OR ai_processing_by = ?)");
    $stmt->execute([$agentId, $teamId, '-' . AI_CLAIM_MINUTES . ' minutes', $agentId]);
    return $stmt->rowCount() > 0;
}

function releaseTeam(PDO $db, int $teamId, string $agentId): void {
    $db->prepare("UPDATE teams SET ai_processing_by = NULL, ai_processing_at = NULL WHERE id = ? AND ai_processing_by = ?")->execute([$teamId, $agentId]);
}

// ---------------------------------------------------------------------------
// Serverregels: wat mag de AI?
// ---------------------------------------------------------------------------

/**
 * Bijgestelde beslissing. $ctx: game_status, ai_mode, ai_auto_allowed, rubric_status, escalation_open.
 * De uitkomst is nooit hoger dan gevraagd (ignored < suggest < reply < level_up); escalate blijft escalate.
 */
function allowedDecision(array $ctx, string $requested): string {
    if ($requested === 'escalate') {
        return 'escalate';
    }
    if (!isset(AI_DECISION_RANK[$requested])) {
        return 'suggest';
    }
    $mode = $ctx['ai_mode'] ?? 'suggest';
    $status = $ctx['game_status'] ?? '';
    if ($requested === 'ignored' || $mode === 'off' || !in_array($status, ['running', 'paused'], true)) {
        return 'ignored';
    }
    $max = 'level_up';
    if ($mode === 'reply' || ($mode === 'auto' && empty($ctx['ai_auto_allowed']))) {
        $max = 'reply';
    }
    if (!in_array($mode, ['reply', 'auto'], true) || $status !== 'running'
        || ($ctx['rubric_status'] ?? 'none') !== 'approved' || !empty($ctx['escalation_open'])) {
        $max = 'suggest';
    }
    return AI_DECISION_RANK[$requested] <= AI_DECISION_RANK[$max] ? $requested : $max;
}

// ---------------------------------------------------------------------------
// Tussenstand per team per level
// ---------------------------------------------------------------------------

/**
 * Tekst voor de citaat-check: kleine letters, aanhalingstekens weg, witruimte samengevoegd.
 */
function normalizeQuoteText(string $text): string {
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = str_replace(['"', "'", '“', '”', '„', '‘', '’', '‚', '«', '»', '`', '´'], '', $text);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/**
 * Staat het citaat echt in dat teambericht van dit team en dit level?
 */
function quoteInTeamMessage(PDO $db, int $teamId, int $level, int $messageId, string $quote): bool {
    $needle = normalizeQuoteText($quote);
    if ($needle === '' || $messageId <= 0) {
        return false;
    }
    $stmt = $db->prepare("SELECT message FROM team_messages WHERE id = ? AND team_id = ? AND assignment_number = ? AND sender = 'team'");
    $stmt->execute([$messageId, $teamId, $level]);
    $message = $stmt->fetchColumn();
    return $message !== false && strpos(normalizeQuoteText((string)$message), $needle) !== false;
}

/**
 * Werkt de tussenstand bij. Per aspect telt het nieuwste standpunt (hoogste bericht-id), maar alleen
 * met een citaat dat echt in het genoemde teambericht van dit team en dit level staat.
 */
function updateTeamProgress(PDO $db, int $teamId, int $level, array $aspects, ?string $summary, int $messageId): void {
    $team = $db->prepare("SELECT game_id FROM teams WHERE id = ?");
    $team->execute([$teamId]);
    $gameId = $team->fetchColumn();
    if ($gameId === false) {
        return;
    }
    $current = getTeamProgress($db, $teamId, $level);
    $byName = [];
    foreach ($current['aspects'] ?? [] as $a) {
        $byName[normalizeQuoteText((string)($a['naam'] ?? ''))] = $a;
    }
    foreach ($aspects as $a) {
        if (!is_array($a) || !isset($a['naam'], $a['niveau'], $a['bewijs'], $a['bericht']) || !in_array($a['niveau'], AI_VERDICTS, true)) {
            continue;
        }
        $key = normalizeQuoteText((string)$a['naam']);
        $msgId = (int)$a['bericht'];
        if ($key === '' || !quoteInTeamMessage($db, $teamId, $level, $msgId, (string)$a['bewijs'])) {
            continue;
        }
        if (isset($byName[$key]) && (int)$byName[$key]['bericht'] > $msgId) {
            continue; // een later standpunt staat er al
        }
        $byName[$key] = ['naam' => trim((string)$a['naam']), 'niveau' => $a['niveau'], 'bewijs' => (string)$a['bewijs'], 'bericht' => $msgId];
    }
    $summary = $summary !== null && trim($summary) !== '' ? textCut(trim($summary), AI_TEXT_MAX_LENGTH) : ($current['summary'] ?? '');
    $db->prepare("INSERT INTO ai_team_progress (game_id, team_id, assignment_number, aspects_json, summary, last_message_id, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
                  ON CONFLICT(team_id, assignment_number) DO UPDATE SET aspects_json = excluded.aspects_json,
                  summary = excluded.summary, last_message_id = excluded.last_message_id, updated_at = excluded.updated_at")
       ->execute([(int)$gameId, $teamId, $level, json_encode(array_values($byName), JSON_UNESCAPED_UNICODE), $summary, $messageId]);
}

function clearTeamProgress(PDO $db, int $teamId, int $level, ?int $userId = null): bool {
    $stmt = $db->prepare("DELETE FROM ai_team_progress WHERE team_id = ? AND assignment_number = ?");
    $stmt->execute([$teamId, $level]);
    if ($stmt->rowCount() > 0) {
        logAudit($db, $userId, 'AI_PROGRESS_CLEAR', "AI-tussenstand van team ID $teamId op level $level gewist");
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Beoordelingen indienen (submit_evaluation)
// ---------------------------------------------------------------------------

function aiString($value, int $max = AI_TEXT_MAX_LENGTH): string {
    return is_string($value) ? textCut(trim($value), $max) : '';
}

function aiJson($value): ?string {
    if ($value === null) {
        return null;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        return null;
    }
    // Afkappen zou ongeldige JSON geven; dan liever een melding
    return strlen($json) <= AI_JSON_MAX_BYTES ? $json : json_encode(['error' => 'Uitvoer te groot om op te slaan (' . strlen($json) . ' bytes)']);
}

/**
 * Vaste opmaak van een suggestie in de docentenchat. messages.php toont de beoordeling zelf via
 * ai_evaluation_id; deze tekst is het spoor in de database en de terugval.
 */
function formatSuggestion(array $e): string {
    $lines = [$e['decision'] === 'escalate' ? '🙋 Docent nodig (AI)' : '🤖 AI-suggestie'];
    $lines[] = 'Intentie: ' . $e['intent'];
    if ($e['verdict']) {
        $lines[] = 'Oordeel: ' . $e['verdict'] . (in_array($e['verdict'], ['voldoende', 'goed'], true) ? ' → level-up-advies' : '');
    }
    if ($e['reason'] !== '') {
        $lines[] = 'Reden: ' . $e['reason'];
    }
    if ($e['reply_text'] !== '') {
        $lines[] = 'Voorgestelde reactie:';
        $lines[] = $e['reply_text'];
    }
    return implode("\n", $lines);
}

function insertTeamMessage(PDO $db, int $teamId, int $level, string $sender, string $message, ?int $evaluationId = null): void {
    $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message, is_read, ai_evaluation_id) VALUES (?, ?, ?, ?, 0, ?)")
       ->execute([$teamId, $level, $sender, $message, $evaluationId]);
}

/**
 * Verwerkt een beoordeling van de agent. Geeft ['status' => HTTP-code, ...] terug: bij 200 ook
 * 'decision' (wat er echt is uitgevoerd) en 'evaluation_id', anders 'error'.
 */
function submitEvaluation(PDO $db, array $p): array {
    $agentId = aiString($p['agent_id'] ?? '', 100);
    $teamId = (int)($p['team_id'] ?? 0);
    $level = (int)($p['level'] ?? 0);
    $messageId = (int)($p['message_id'] ?? 0);
    $intent = $p['intent'] ?? '';
    $requested = $p['decision'] ?? '';
    $verdict = $p['verdict'] ?? null;
    if ($agentId === '' || $teamId <= 0 || $level <= 0 || $messageId <= 0) {
        return ['status' => 400, 'error' => 'agent_id, team_id, level en message_id zijn verplicht'];
    }
    if (!in_array($intent, AI_INTENTS, true) || !in_array($requested, AI_DECISIONS, true)) {
        return ['status' => 400, 'error' => 'Ongeldige intent of decision'];
    }
    if ($verdict !== null && !in_array($verdict, AI_VERDICTS, true)) {
        return ['status' => 400, 'error' => 'Ongeldig verdict'];
    }
    if ($intent !== 'antwoord') {
        $verdict = null; // een vraag of overig levert nooit een oordeel
    }
    if ($intent === 'vraag' && $requested === 'level_up') {
        $requested = 'reply'; // een vraag levert nooit een level-up
    }
    $stmt = $db->prepare("SELECT t.*, " . effectiveStatusSql('g') . " AS game_status FROM teams t JOIN games g ON g.id = t.game_id WHERE t.id = ?");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        return ['status' => 404, 'error' => 'Team niet gevonden'];
    }
    if ((string)$team['ai_processing_by'] !== $agentId) {
        return ['status' => 409, 'error' => 'Dit team is niet door deze agent geclaimd'];
    }

    $currentLevel = (int)$team['current_level'];
    $stmt = $db->prepare("SELECT MAX(id) FROM team_messages WHERE team_id = ? AND assignment_number = ? AND sender = 'team'");
    $stmt->execute([$teamId, $currentLevel]);
    $lastMessage = (int)$stmt->fetchColumn();
    $stale = $level !== $currentLevel || $messageId !== $lastMessage;

    $assignment = null;
    if (!$stale) {
        $stmt = $db->prepare("SELECT * FROM assignments WHERE game_id = ? AND assignment_number = ?");
        $stmt->execute([(int)$team['game_id'], $level]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stale = $assignment === null;
    }
    $escalationOpen = !$stale && hasOpenEscalation($db, $teamId, $level);
    $decision = $stale ? 'stale' : allowedDecision([
        'game_status' => $team['game_status'],
        'ai_mode' => $team['ai_mode'] ?: 'suggest',
        'ai_auto_allowed' => (int)$assignment['ai_auto_allowed'] === 1,
        'rubric_status' => $assignment['ai_rubric_status'] ?: 'none',
        'escalation_open' => $escalationOpen,
    ], $requested);

    $replyText = aiString($p['reply_text'] ?? '');
    $reason = aiString($p['reason'] ?? '');
    if (in_array($decision, ['reply', 'level_up'], true) && $replyText === '') {
        $decision = 'suggest'; // zonder tekst valt er niets naar het team te sturen
    }
    $models = is_array($p['models'] ?? null) ? implode(', ', array_filter($p['models'], 'is_string')) : aiString($p['models'] ?? '', 500);
    $models = textCut($models, 500);

    $db->prepare("INSERT INTO ai_evaluations (game_id, team_id, team_name, assignment_number, message_id, intent, intent_json, helper_json,
                  assessor_json, validator_json, verdict, decision, reason, reply_text, escalation_reason, agent_id, models, duration_ms, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))")
       ->execute([(int)$team['game_id'], $teamId, $team['team_name'], $level, $messageId, $intent,
                  aiJson($p['intent_json'] ?? null), aiJson($p['helper_json'] ?? null), aiJson($p['assessor_json'] ?? null),
                  aiJson($p['validator_json'] ?? null), $verdict, $decision, $reason, $replyText,
                  $decision === 'escalate' ? ($reason !== '' ? $reason : 'Onzeker') : null,
                  $agentId, $models, isset($p['duration_ms']) ? (int)$p['duration_ms'] : null]);
    $evaluationId = (int)$db->lastInsertId();

    if ($stale) {
        releaseTeam($db, $teamId, $agentId);
        return ['status' => 200, 'decision' => 'stale', 'evaluation_id' => $evaluationId];
    }

    if ($intent === 'antwoord' && is_array($p['progress'] ?? null)) {
        updateTeamProgress($db, $teamId, $level, is_array($p['progress']['aspects'] ?? null) ? $p['progress']['aspects'] : [],
            is_string($p['progress']['summary'] ?? null) ? $p['progress']['summary'] : null, $messageId);
    }

    $e = ['intent' => $intent, 'verdict' => $verdict, 'decision' => $decision, 'reason' => $reason, 'reply_text' => $replyText];
    $who = "agent $agentId" . ($models !== '' ? ", model $models" : '');
    $where = "team ID $teamId, level $level, beoordeling #$evaluationId";
    $levelUpAdvice = in_array($verdict, ['voldoende', 'goed'], true);

    switch ($decision) {
        case 'suggest':
        case 'escalate':
            insertTeamMessage($db, $teamId, $level, 'suggestion', formatSuggestion($e), $evaluationId);
            logAudit($db, null, $decision === 'escalate' ? 'AI_ESCALATE' : 'AI_SUGGEST', ($decision === 'escalate' ? 'Escalatie' : 'Suggestie') . " ($where, $who)");
            break;
        case 'reply':
            insertTeamMessage($db, $teamId, $level, 'ai', $replyText, $evaluationId);
            if ($levelUpAdvice) {
                // In reply beslist de docent over het level: advies in de docentenchat
                insertTeamMessage($db, $teamId, $level, 'suggestion', formatSuggestion(['decision' => 'suggest', 'reason' => $reason, 'reply_text' => ''] + $e), $evaluationId);
            }
            logAudit($db, null, 'AI_REPLY', "AI-reactie aan het team ($where, $who)" . ($levelUpAdvice ? ', met level-up-advies' : ''));
            break;
        case 'level_up':
            if (!levelUp($db, $teamId, null)) {
                // Kan niet (bijv. al op Finish): dan alleen een suggestie
                $db->prepare("UPDATE ai_evaluations SET decision = 'suggest' WHERE id = ?")->execute([$evaluationId]);
                $e['decision'] = $decision = 'suggest';
                insertTeamMessage($db, $teamId, $level, 'suggestion', formatSuggestion($e), $evaluationId);
                logAudit($db, null, 'AI_SUGGEST', "Suggestie, level-up niet mogelijk ($where, $who)");
                break;
            }
            // Feedback op het nieuwe level, zodat het team hem ziet
            insertTeamMessage($db, $teamId, $level + 1, 'ai', $replyText, $evaluationId);
            logAudit($db, null, 'AI_LEVEL_UP', "AI zette het team een level omhoog ($where, $who)");
            break;
    }
    releaseTeam($db, $teamId, $agentId);
    return ['status' => 200, 'decision' => $decision, 'evaluation_id' => $evaluationId];
}

// ---------------------------------------------------------------------------
// Escalaties
// ---------------------------------------------------------------------------

/**
 * Sluit de open escalaties van een team op een level (docent antwoordt, level verandert of een
 * escalatieknop). $teacherVerdict: 'agree' | 'disagree' | null. Geeft het aantal gesloten escalaties.
 */
function resolveEscalations(PDO $db, int $teamId, int $level, ?int $userId, ?string $teacherVerdict = null): int {
    $stmt = $db->prepare("UPDATE ai_evaluations SET resolved_by = ?, resolved_at = datetime('now'), teacher_verdict = COALESCE(?, teacher_verdict)
        WHERE team_id = ? AND assignment_number = ? AND decision = 'escalate' AND resolved_at IS NULL");
    $stmt->execute([$userId, $teacherVerdict, $teamId, $level]);
    return $stmt->rowCount();
}

/**
 * Open escalaties per team in het spel (alleen het huidige level van elk team): [team_id => aantal].
 */
function openEscalationsByTeam(PDO $db, int $gameId): array {
    $stmt = $db->prepare("SELECT e.team_id, COUNT(*) FROM ai_evaluations e JOIN teams t ON t.id = e.team_id
        WHERE t.game_id = ? AND e.decision = 'escalate' AND e.resolved_at IS NULL AND e.assignment_number = t.current_level
        GROUP BY e.team_id");
    $stmt->execute([$gameId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Aantal teams met een open escalatie ("🙋 Docent nodig").
 */
function countOpenEscalations(PDO $db, int $gameId): int {
    return count(openEscalationsByTeam($db, $gameId));
}

function findEvaluationInGame(PDO $db, int $id, int $gameId): ?array {
    $stmt = $db->prepare("SELECT * FROM ai_evaluations WHERE id = ? AND game_id = ?");
    $stmt->execute([$id, $gameId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Docent markeert een beoordeling als juist ('agree') of onjuist ('disagree') in ai_log.php.
 */
function setTeacherVerdict(PDO $db, int $evaluationId, int $gameId, string $verdict, ?int $userId): bool {
    if (!in_array($verdict, ['agree', 'disagree'], true) || !findEvaluationInGame($db, $evaluationId, $gameId)) {
        return false;
    }
    $db->prepare("UPDATE ai_evaluations SET teacher_verdict = ? WHERE id = ? AND game_id = ?")->execute([$verdict, $evaluationId, $gameId]);
    logAudit($db, $userId, 'AI_TEACHER_VERDICT', "AI-beoordeling #$evaluationId gemarkeerd als " . ($verdict === 'agree' ? 'juist' : 'onjuist'));
    return true;
}

// ---------------------------------------------------------------------------
// Opdracht-assistent
// ---------------------------------------------------------------------------

/**
 * Docent vraagt de AI om mee te kijken (alleen met instructie en criteria). Foutmelding of null.
 */
function requestDesign(PDO $db, int $assignmentId, int $gameId, ?int $userId): ?string {
    $a = findAssignmentInGame($db, $assignmentId, $gameId);
    if (!$a) {
        return 'Deze opdracht hoort niet bij het actieve spel.';
    }
    if (trim((string)$a['instruction']) === '' || trim((string)$a['criteria']) === '') {
        return 'Vul eerst de instructie en de beoordelingscriteria (het antwoord) in en sla de opdracht op.';
    }
    if (!in_array($a['ai_design_status'] ?: 'idle', ['idle', 'failed'], true)) {
        return 'De AI is al met deze opdracht bezig.';
    }
    $db->prepare("UPDATE assignments SET ai_design_status = 'pending', ai_design_round = 0, ai_processing_by = NULL, ai_processing_at = NULL WHERE id = ?")->execute([$assignmentId]);
    logAudit($db, $userId, 'ASSIGNMENT_AI_REQUEST', "AI gevraagd mee te kijken bij opdracht #{$a['assignment_number']} (ID $assignmentId, spel #$gameId)");
    return null;
}

/**
 * Antwoord van de docent op de vragen van de AI; daarna een nieuwe ronde. Foutmelding of null.
 */
function addTeacherAnswer(PDO $db, int $assignmentId, int $gameId, string $message, ?int $userId): ?string {
    $a = findAssignmentInGame($db, $assignmentId, $gameId);
    if (!$a) {
        return 'Deze opdracht hoort niet bij het actieve spel.';
    }
    $message = trim($message);
    if ($message === '') {
        return 'Typ eerst een antwoord.';
    }
    if (($a['ai_design_status'] ?? '') !== 'questions') {
        return 'De AI wacht niet op een antwoord.';
    }
    $db->prepare("INSERT INTO assignment_ai_dialog (assignment_id, role, message, teacher_id) VALUES (?, 'teacher', ?, ?)")
       ->execute([$assignmentId, textCut($message, AI_TEXT_MAX_LENGTH), $userId]);
    $db->prepare("UPDATE assignments SET ai_design_status = 'pending' WHERE id = ?")->execute([$assignmentId]);
    logAudit($db, $userId, 'ASSIGNMENT_AI_ANSWER', "Docent beantwoordde de vragen van de AI bij opdracht #{$a['assignment_number']} (ID $assignmentId)");
    return null;
}

/**
 * Opdrachten waar de AI naar moet kijken (pending, niet geclaimd), met de dialoog.
 */
function pendingDesignJobs(PDO $db): array {
    $stmt = $db->prepare("SELECT * FROM assignments WHERE ai_design_status = 'pending'
        AND (ai_processing_at IS NULL OR ai_processing_at <= datetime('now', ?)) ORDER BY id ASC");
    $stmt->execute(['-' . AI_CLAIM_MINUTES . ' minutes']);
    return array_map(function ($a) use ($db) {
        return [
            'assignment_id' => (int)$a['id'],
            'game_id' => (int)$a['game_id'],
            'assignment_number' => (int)$a['assignment_number'],
            'title' => (string)$a['title'],
            'description' => (string)$a['description'],
            'instruction' => (string)$a['instruction'],
            'criteria' => (string)$a['criteria'],
            'has_attachment' => !empty($a['artifact_file']),
            'rubric' => decodeRubric($a['ai_rubric']),
            'round' => (int)$a['ai_design_round'] + 1,
            'max_rounds' => AI_MAX_DESIGN_ROUNDS,
            'dialog' => array_map(function ($d) {
                return ['role' => $d['role'], 'message' => $d['message']];
            }, assignmentDialog($db, (int)$a['id'])),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function claimDesign(PDO $db, int $assignmentId, string $agentId): bool {
    $stmt = $db->prepare("UPDATE assignments SET ai_processing_by = ?, ai_processing_at = datetime('now')
        WHERE id = ? AND ai_design_status = 'pending'
        AND (ai_processing_at IS NULL OR ai_processing_at <= datetime('now', ?) OR ai_processing_by = ?)");
    $stmt->execute([$agentId, $assignmentId, '-' . AI_CLAIM_MINUTES . ' minutes', $agentId]);
    return $stmt->rowCount() > 0;
}

/**
 * Uitkomst van de opdracht-assistent: {assignment_id, agent_id} plus `questions` (1–5 vragen),
 * `rubric` (voorstel, wordt concept) of `error` (status failed). `criteria` blijft altijd ongewijzigd.
 */
function submitDesign(PDO $db, array $p): array {
    $agentId = aiString($p['agent_id'] ?? '', 100);
    $assignmentId = (int)($p['assignment_id'] ?? 0);
    if ($agentId === '' || $assignmentId <= 0) {
        return ['status' => 400, 'error' => 'agent_id en assignment_id zijn verplicht'];
    }
    $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
    $stmt->execute([$assignmentId]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        return ['status' => 404, 'error' => 'Opdracht niet gevonden'];
    }
    if ($a['ai_design_status'] !== 'pending' || (string)$a['ai_processing_by'] !== $agentId) {
        return ['status' => 409, 'error' => 'Deze opdracht is niet door deze agent geclaimd'];
    }
    $where = "opdracht #{$a['assignment_number']} (ID $assignmentId, spel #{$a['game_id']}), agent $agentId";
    $release = ", ai_processing_by = NULL, ai_processing_at = NULL";

    if (isset($p['error'])) {
        $db->prepare("UPDATE assignments SET ai_design_status = 'failed'$release WHERE id = ?")->execute([$assignmentId]);
        logAudit($db, null, 'ASSIGNMENT_AI_FAILED', "Opdracht-assistent mislukt bij $where");
        return ['status' => 200, 'result' => 'failed'];
    }

    if (isset($p['questions'])) {
        $questions = is_array($p['questions']) ? array_values(array_filter(array_map(function ($q) {
            return aiString($q, 1000);
        }, $p['questions']), function ($q) { return $q !== ''; })) : [];
        if (!$questions || count($questions) > AI_MAX_DESIGN_QUESTIONS) {
            return ['status' => 400, 'error' => 'Geef 1 tot ' . AI_MAX_DESIGN_QUESTIONS . ' vragen'];
        }
        if ((int)$a['ai_design_round'] >= AI_MAX_DESIGN_ROUNDS) {
            return ['status' => 400, 'error' => 'Na ' . AI_MAX_DESIGN_ROUNDS . ' vraagrondes alleen nog een rubric'];
        }
        $text = count($questions) === 1 ? $questions[0] : implode("\n", array_map(function ($q, $i) {
            return ($i + 1) . '. ' . $q;
        }, $questions, array_keys($questions)));
        $db->prepare("INSERT INTO assignment_ai_dialog (assignment_id, role, message) VALUES (?, 'ai', ?)")->execute([$assignmentId, $text]);
        $db->prepare("UPDATE assignments SET ai_design_status = 'questions', ai_design_round = ai_design_round + 1$release WHERE id = ?")->execute([$assignmentId]);
        logAudit($db, null, 'ASSIGNMENT_AI_QUESTIONS', count($questions) . " vragen van de opdracht-assistent bij $where");
        return ['status' => 200, 'result' => 'questions'];
    }

    if (isset($p['rubric'])) {
        if (!is_array($p['rubric'])) {
            return ['status' => 400, 'error' => 'De rubric moet een object zijn'];
        }
        if ($error = validateRubric($p['rubric'])) {
            return ['status' => 400, 'error' => "Ongeldige rubric: $error"];
        }
        $json = json_encode(normalizeRubric($p['rubric']), JSON_UNESCAPED_UNICODE);
        $db->prepare("UPDATE assignments SET ai_rubric = ?, ai_rubric_status = 'draft', ai_design_status = 'idle'$release WHERE id = ?")->execute([$json, $assignmentId]);
        logAudit($db, null, 'ASSIGNMENT_AI_RUBRIC', "Rubric-voorstel van de opdracht-assistent bij $where");
        return ['status' => 200, 'result' => 'rubric'];
    }

    return ['status' => 400, 'error' => 'Geef questions, rubric of error mee'];
}

// ---------------------------------------------------------------------------
// AI-agentstatus (heartbeat)
// ---------------------------------------------------------------------------

function recordHeartbeat(PDO $db, string $agentId, bool $modelOk): void {
    $db->prepare("REPLACE INTO ai_service_status (agent_id, last_heartbeat, model_ok) VALUES (?, strftime('%s', 'now'), ?)")
       ->execute([$agentId, $modelOk ? 1 : 0]);
    // Hartslagen ouder dan 10 minuten opruimen
    $db->exec("DELETE FROM ai_service_status WHERE last_heartbeat < (strftime('%s', 'now') - 600)");
}

/**
 * 'active' als een agent in de laatste 120 s een hartslag stuurde met een werkend model, 'model_down'
 * als er wel agents zijn maar geen enkel model werkt, anders 'inactive'. Plus het aantal agents.
 */
function aiAgentStatus(PDO $db): array {
    $row = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN COALESCE(model_ok, 1) = 1 THEN 1 ELSE 0 END), 0) AS ok
        FROM ai_service_status WHERE last_heartbeat > (strftime('%s', 'now') - 120)")->fetch(PDO::FETCH_ASSOC);
    $n = (int)$row['n'];
    return ['status' => $n === 0 ? 'inactive' : ((int)$row['ok'] > 0 ? 'active' : 'model_down'), 'count' => $n];
}
