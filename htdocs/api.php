<?php
/**
 * API voor externe applicaties (zoals de Python AI bot)
 */
require_once '../conf/config.php';
require_once '../conf/database.php';

header('Content-Type: application/json');

// Authenticatie check via Headers of Query Parameter
$headers = getallheaders();
$receivedToken = $headers['X-API-Token'] ?? $_GET['token'] ?? '';

if (empty($receivedToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Geen API token opgegeven.']);
    exit;
}

$db = getDB();

// Valideer de token tegen de api_keys tabel
$stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = ?");
$stmt->execute([$receivedToken]);
if (!$stmt->fetch()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Ongeldige API token.']);
    exit;
}

$action = $_GET['action'] ?? '';

// Actie: Haal alle team-antwoorden op die nog geen AI suggestie hebben voor hun laatste bericht
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_pending') {
    $query = "
        SELECT 
            t.id as team_id, t.team_name, t.current_level,
            a.title as assignment_title, a.description, a.instruction, a.criteria, a.time_limit,
            tm.created_at as message_time,
            (
                SELECT GROUP_CONCAT(msg, CHAR(10))
                FROM (
                    SELECT (CASE WHEN sender = 'team' THEN 'Team: ' ELSE 'Docent: ' END) || message as msg
                    FROM team_messages 
                    WHERE team_id = t.id 
                    AND assignment_number = t.current_level 
                    AND sender IN ('team', 'teacher')
                    ORDER BY created_at ASC
                )
            ) as chat_history
        FROM teams t
        JOIN assignments a ON t.current_level = a.assignment_number
        JOIN team_messages tm ON t.id = tm.team_id AND t.current_level = tm.assignment_number
        WHERE tm.sender = 'team'
        -- Pak alleen het allerlaatste bericht van het team voor dit level
        AND tm.id IN (SELECT MAX(id) FROM team_messages WHERE sender = 'team' GROUP BY team_id, assignment_number)
        -- Controleer of er nog geen AI suggestie of docent-reactie bestaat die nieuwer is dan dit teambericht
        AND NOT EXISTS (
            SELECT 1 FROM team_messages 
            WHERE team_id = t.id AND assignment_number = t.current_level 
            AND sender IN ('suggestion', 'teacher') AND id > tm.id
        )
        ORDER BY tm.created_at DESC
    ";
    $stmt = $db->query($query);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Actie: Ontvang een suggestie van de AI en zet deze in de docenten-chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send_suggestion') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $team_id = (int)($input['team_id'] ?? 0);
    $message = trim($input['message'] ?? '');
    $suggest_level_up = (bool)($input['level_up'] ?? false);

    if ($team_id <= 0 || empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing team_id or message']);
        exit;
    }

    $stmt = $db->prepare("SELECT current_level FROM teams WHERE id = ?");
    $stmt->execute([$team_id]);
    $current_level = $stmt->fetchColumn();

    if ($current_level === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Team not found']);
        exit;
    }

    $prefix = $suggest_level_up ? "🤖 [ADVIES: LEVEL UP] " : "🤖 [SUGGESTIE] ";
    $full_message = $prefix . $message;

    // Sla de suggestie op (is_read = 0 zorgt voor de rode badge bij de docent)
    $stmt = $db->prepare("INSERT INTO team_messages (team_id, assignment_number, sender, message, is_read) VALUES (?, ?, 'suggestion', ?, 0)");
    $stmt->execute([$team_id, $current_level, $full_message]);

    echo json_encode(['status' => 'success', 'message' => 'Suggestion added to database']);
    exit;
}

// Actie: Ontvang een heartbeat van de AI service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'heartbeat') {
    // Verwijder oude heartbeats (we houden er maar één bij)
    $db->exec("DELETE FROM ai_service_status");
    // Voeg nieuwe heartbeat toe
    $db->exec("INSERT INTO ai_service_status (last_heartbeat) VALUES (CURRENT_TIMESTAMP)");
    echo json_encode(['status' => 'success', 'message' => 'Heartbeat received']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Invalid action or method']);
exit;