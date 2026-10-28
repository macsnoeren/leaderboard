<?php
/**
 * API voor de AI-agent (agentic-service/main.py). Authenticatie met de header X-API-Token of ?token=,
 * gecontroleerd tegen api_keys. De logica staat in conf/ai.php; het protocol staat in CLAUDE.md.
 */
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/ai.php';

header('Content-Type: application/json');

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// JSON-body van een POST; een ongeldige body geeft 400
function jsonInput(): array {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond(400, ['error' => 'Ongeldige JSON-body']);
    }
    return $input;
}

// Authenticatie check via Headers of Query Parameter
$headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$receivedToken = $headers['x-api-token'] ?? $_GET['token'] ?? '';

if (empty($receivedToken) || !is_string($receivedToken)) {
    respond(401, ['error' => 'Unauthorized: Geen API token opgegeven.']);
}

$db = getDB();

// Valideer de token tegen de api_keys tabel
$stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = ?");
$stmt->execute([$receivedToken]);
if (!$stmt->fetch()) {
    respond(401, ['error' => 'Unauthorized: Ongeldige API token.']);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Teams waarvan het laatste bericht nog door de AI bekeken moet worden, met alles wat de agent nodig heeft
// (?limit=N: alleen de oudste N; de agent haalt zo vlak voor elk team een verse lijst op)
if ($method === 'GET' && $action === 'get_pending') {
    respond(200, aiPendingTasks($db, isset($_GET['limit']) ? (int)$_GET['limit'] : null));
}

// Claim een team voor een agent (lock van 5 minuten)
if ($method === 'POST' && $action === 'claim_task') {
    $input = jsonInput();
    $agentId = trim((string)($input['agent_id'] ?? ''));
    if ($agentId === '' || claimTeam($db, (int)($input['team_id'] ?? 0), $agentId) === false) {
        respond(409, ['error' => 'Task already claimed or not found']);
    }
    respond(200, ['status' => 'success', 'message' => 'Task claimed']);
}

// Beoordeling van de agent: de server controleert opnieuw wat mag en voert dat uit
if ($method === 'POST' && $action === 'submit_evaluation') {
    $result = submitEvaluation($db, jsonInput());
    $status = $result['status'];
    unset($result['status']);
    respond($status, $status === 200 ? ['status' => 'success'] + $result : $result);
}

// Opdrachten waar de opdracht-assistent naar moet kijken
if ($method === 'GET' && $action === 'get_design_jobs') {
    respond(200, pendingDesignJobs($db));
}

if ($method === 'POST' && $action === 'claim_design') {
    $input = jsonInput();
    $agentId = trim((string)($input['agent_id'] ?? ''));
    if ($agentId === '' || !claimDesign($db, (int)($input['assignment_id'] ?? 0), $agentId)) {
        respond(409, ['error' => 'Design job already claimed or not found']);
    }
    respond(200, ['status' => 'success', 'message' => 'Design job claimed']);
}

// Vragen, een rubric-voorstel of een fout van de opdracht-assistent
if ($method === 'POST' && $action === 'submit_design') {
    $result = submitDesign($db, jsonInput());
    $status = $result['status'];
    unset($result['status']);
    respond($status, $status === 200 ? ['status' => 'success'] + $result : $result);
}

// Heartbeat van de agent, met model_ok: lukte de laatste modelaanroep?
if ($method === 'POST' && $action === 'heartbeat') {
    $input = jsonInput();
    $agentId = trim((string)($input['agent_id'] ?? ''));
    if ($agentId === '') {
        respond(400, ['error' => 'agent_id ontbreekt']);
    }
    recordHeartbeat($db, $agentId, !array_key_exists('model_ok', $input) || (bool)$input['model_ok']);
    respond(200, ['status' => 'success', 'message' => 'Heartbeat received']);
}

// Meld een AI agent af (bij afsluiten)
if ($method === 'POST' && $action === 'unregister_agent') {
    $input = jsonInput();
    $agentId = trim((string)($input['agent_id'] ?? ''));
    if ($agentId !== '') {
        $db->prepare("DELETE FROM ai_service_status WHERE agent_id = ?")->execute([$agentId]);
    }
    respond(200, ['status' => 'success', 'message' => 'Agent unregistered']);
}

respond(404, ['error' => 'Invalid action or method']);
