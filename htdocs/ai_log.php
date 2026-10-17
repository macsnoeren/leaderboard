<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';
require_once '../conf/game.php';

$db = getDB();
$user = requireTeacher($db);
$userId = (int)$user['id'];
$game = requireGame($db, $user);
$gameId = (int)$game['id'];

const AI_DECISION_LABELS = [
    'suggest' => 'Suggestie',
    'reply' => 'Antwoord aan team',
    'level_up' => 'Level-up',
    'escalate' => 'Escalatie',
    'stale' => 'Verouderd',
    'ignored' => 'Genegeerd',
];

// Filters uit de URL; alles wordt binnen het actieve spel gezocht
$filters = [
    'team' => (int)($_GET['team'] ?? 0),
    'assignment' => (int)($_GET['assignment'] ?? 0),
    'decision' => isset(AI_DECISION_LABELS[$_GET['decision'] ?? '']) ? $_GET['decision'] : '',
    'id' => (int)($_GET['id'] ?? 0),
];
$query = http_build_query(array_filter($filters));
$back = 'ai_log.php' . ($query !== '' ? "?$query" : '');

// Docent markeert of de AI gelijk had
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'teacher_verdict') {
    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
    } elseif (!setTeacherVerdict($db, (int)($_POST['evaluation_id'] ?? 0), $gameId, (string)($_POST['verdict'] ?? ''), $userId)) {
        flash('error', 'Deze beoordeling hoort niet bij het actieve spel.');
    } else {
        flash('success', 'Opgeslagen.');
    }
    redirect($back . '#eval-' . (int)($_POST['evaluation_id'] ?? 0));
}

$where = ['e.game_id = ?'];
$params = [$gameId];
foreach (['team' => 'e.team_id = ?', 'assignment' => 'e.assignment_number = ?', 'decision' => 'e.decision = ?', 'id' => 'e.id = ?'] as $key => $sql) {
    if ($filters[$key]) {
        $where[] = $sql;
        $params[] = $filters[$key];
    }
}
$stmt = $db->prepare("SELECT e.*, tm.message AS team_message FROM ai_evaluations e
    LEFT JOIN team_messages tm ON tm.id = e.message_id AND tm.team_id = e.team_id
    WHERE " . implode(' AND ', $where) . " ORDER BY e.id DESC LIMIT 200");
$stmt->execute($params);
$evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Overeenstemming per opdracht: hoe vaak was de docent het met de AI eens?
$stmt = $db->prepare("SELECT e.assignment_number, a.title,
        COUNT(*) AS total,
        SUM(CASE WHEN e.decision = 'level_up' THEN 1 ELSE 0 END) AS level_ups,
        SUM(CASE WHEN e.decision = 'escalate' THEN 1 ELSE 0 END) AS escalations,
        SUM(CASE WHEN e.teacher_verdict = 'agree' THEN 1 ELSE 0 END) AS agree,
        SUM(CASE WHEN e.teacher_verdict = 'disagree' THEN 1 ELSE 0 END) AS disagree
    FROM ai_evaluations e LEFT JOIN assignments a ON a.game_id = e.game_id AND a.assignment_number = e.assignment_number
    WHERE e.game_id = ? AND e.decision NOT IN ('stale', 'ignored')
    GROUP BY e.assignment_number ORDER BY e.assignment_number");
$stmt->execute([$gameId]);
$agreement = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teams = $db->prepare("SELECT DISTINCT team_id, team_name FROM ai_evaluations WHERE game_id = ? ORDER BY team_name");
$teams->execute([$gameId]);
$teams = $teams->fetchAll(PDO::FETCH_ASSOC);
$numbers = $db->prepare("SELECT DISTINCT assignment_number FROM ai_evaluations WHERE game_id = ? ORDER BY assignment_number");
$numbers->execute([$gameId]);
$numbers = $numbers->fetchAll(PDO::FETCH_COLUMN);

// Agentuitvoer leesbaar: per agent de uitvoer en (ingeklapt) de redenering van het model
function renderAgentJson(string $title, ?string $json): string {
    if ($json === null || $json === '') {
        return '';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return '';
    }
    $runs = isset($data['output']) || isset($data['error']) ? [$data] : array_values(array_filter($data, 'is_array'));
    $html = '<div class="agent"><h4>' . htmlspecialchars($title) . '</h4>';
    foreach ($runs as $run) {
        $meta = array_filter([$run['model'] ?? null, isset($run['think']) ? 'think ' . $run['think'] : null, isset($run['duration_ms']) ? round($run['duration_ms'] / 1000, 1) . ' s' : null]);
        if ($meta) {
            $html .= '<div class="muted">' . htmlspecialchars(implode(' · ', $meta)) . '</div>';
        }
        if (isset($run['error'])) {
            $html .= '<div class="error-msg" style="margin: 5px 0; padding: 8px;">' . htmlspecialchars((string)$run['error']) . '</div>';
        }
        if (isset($run['output'])) {
            $html .= '<pre>' . htmlspecialchars(json_encode($run['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }
        if (!empty($run['thinking'])) {
            $html .= '<details><summary>Redenering</summary><pre class="thinking">' . htmlspecialchars((string)$run['thinking']) . '</pre></details>';
        }
    }
    return $html . '</div>';
}

$pageTitle = 'AI-logboek';
$extraCSS = '<style>
    .filters { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
    .filters label { font-size: 0.8em; color: #888; display: block; margin-bottom: 4px; }
    .filters select { width: auto; min-width: 160px; }
    .eval { border: 1px solid #eee; border-radius: 10px; padding: 15px; margin-bottom: 12px; }
    .eval-head { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: center; }
    .decision { padding: 2px 8px; border-radius: 10px; font-size: 0.8em; font-weight: bold; color: white; background: #888; }
    .decision.level_up { background: #43a047; } .decision.reply { background: #1e88e5; } .decision.escalate { background: #fb8c00; }
    .decision.suggest { background: #8e24aa; } .decision.stale, .decision.ignored { background: #bbb; }
    .verdict { font-weight: bold; }
    .verdict.onvoldoende { color: #e53935; } .verdict.voldoende { color: #fb8c00; } .verdict.goed { color: #43a047; }
    .quote { background: #e3f2fd; border-radius: 6px; padding: 8px 10px; margin: 8px 0; font-size: 0.9em; }
    .agents { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 10px; margin-top: 10px; }
    .agent { background: #fafafa; border-radius: 8px; padding: 10px; font-size: 0.85em; min-width: 0; }
    .agent h4 { margin-bottom: 4px; color: #6a1b9a; }
    .agent pre { white-space: pre-wrap; word-break: break-word; font-size: 0.9em; background: white; padding: 6px; border-radius: 6px; margin-top: 4px; }
    .agent pre.thinking { color: #666; max-height: 300px; overflow-y: auto; }
    .muted { color: #999; font-size: 0.85em; }
    .verdict-btns form { display: inline; }
    .verdict-btns .btn { padding: 4px 10px; font-size: 0.8em; }
    .btn.selected { outline: 3px solid #667eea; }
</style>';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">🤖 AI-logboek <span style="font-size: 0.6em; color: #888; font-weight: normal;"><?= htmlspecialchars($game['name']) ?></span></h1>

    <?= renderFlash() ?>

    <div class="card">
        <div class="card-title" style="font-size: 1.2em; margin-bottom: 10px;">📈 Overeenstemming per opdracht</div>
        <p class="muted" style="margin-bottom: 10px;">Markeer beoordelingen hieronder met <em>AI had gelijk</em> of <em>AI had ongelijk</em> (de escalatieknoppen in ✉️ Messages tellen ook mee). Voorstel: zet <strong>Automatisch</strong> pas aan bij ≥ 90 % overeenstemming en nul onterechte level-ups.</p>
        <table>
            <thead><tr><th>#</th><th>Opdracht</th><th>Beoordelingen</th><th>Level-ups</th><th>Escalaties</th><th>Gemarkeerd</th><th>Overeenstemming</th></tr></thead>
            <tbody>
                <?php foreach ($agreement as $row): $marked = (int)$row['agree'] + (int)$row['disagree']; ?>
                    <tr>
                        <td><?= (int)$row['assignment_number'] ?></td>
                        <td><?= htmlspecialchars((string)($row['title'] ?? '—')) ?></td>
                        <td><?= (int)$row['total'] ?></td>
                        <td><?= (int)$row['level_ups'] ?></td>
                        <td><?= (int)$row['escalations'] ?></td>
                        <td><?= $marked ?></td>
                        <td><strong><?= $marked ? round((int)$row['agree'] / $marked * 100) . ' %' : '—' ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$agreement): ?><tr><td colspan="7" style="text-align: center; color: #999;">Nog geen beoordelingen in dit spel.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <form method="GET" class="filters">
            <div><label>Team</label><select name="team"><option value="">Alle teams</option>
                <?php foreach ($teams as $t): ?><option value="<?= (int)$t['team_id'] ?>" <?= $filters['team'] === (int)$t['team_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$t['team_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div><label>Opdracht</label><select name="assignment"><option value="">Alle opdrachten</option>
                <?php foreach ($numbers as $n): ?><option value="<?= (int)$n ?>" <?= $filters['assignment'] === (int)$n ? 'selected' : '' ?>>Opdracht <?= (int)$n ?></option><?php endforeach; ?>
            </select></div>
            <div><label>Beslissing</label><select name="decision"><option value="">Alle beslissingen</option>
                <?php foreach (AI_DECISION_LABELS as $key => $label): ?><option value="<?= $key ?>" <?= $filters['decision'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
            </select></div>
            <button type="submit" class="btn btn-primary">Filteren</button>
            <?php if ($query !== ''): ?><a href="ai_log.php" class="btn btn-outline">Wissen</a><?php endif; ?>
        </form>
    </div>

    <?php foreach ($evaluations as $e): ?>
        <div class="card eval" id="eval-<?= (int)$e['id'] ?>">
            <div class="eval-head">
                <div>
                    <strong>#<?= (int)$e['id'] ?></strong> · <?= htmlspecialchars(formatDateTime($e['created_at'])) ?> ·
                    <a href="ai_log.php?team=<?= (int)$e['team_id'] ?>" style="color: #667eea;"><?= htmlspecialchars((string)$e['team_name']) ?></a> ·
                    opdracht <?= (int)$e['assignment_number'] ?> ·
                    <?= htmlspecialchars((string)$e['intent']) ?>
                    <?php if ($e['verdict']): ?> · <span class="verdict <?= htmlspecialchars($e['verdict']) ?>"><?= htmlspecialchars($e['verdict']) ?></span><?php endif; ?>
                    <span class="decision <?= htmlspecialchars((string)$e['decision']) ?>"><?= htmlspecialchars(AI_DECISION_LABELS[$e['decision']] ?? (string)$e['decision']) ?></span>
                    <?php if ($e['decision'] === 'escalate'): ?><span class="muted"><?= $e['resolved_at'] ? '(afgehandeld ' . htmlspecialchars(formatDateTime($e['resolved_at'])) . ')' : '(open)' ?></span><?php endif; ?>
                </div>
                <div class="verdict-btns">
                    <?php foreach (['agree' => '👍 AI had gelijk', 'disagree' => '👎 AI had ongelijk'] as $value => $label): ?>
                        <form method="POST" action="<?= htmlspecialchars($back) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="teacher_verdict">
                            <input type="hidden" name="evaluation_id" value="<?= (int)$e['id'] ?>">
                            <input type="hidden" name="verdict" value="<?= $value ?>">
                            <button type="submit" class="btn <?= $value === 'agree' ? 'btn-success' : 'btn-danger' ?><?= $e['teacher_verdict'] === $value ? ' selected' : '' ?>"><?= $label ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($e['team_message'] !== null): ?><div class="quote"><strong>Team:</strong> <?= nl2br(htmlspecialchars($e['team_message'])) ?></div><?php endif; ?>
            <?php if (trim((string)$e['reason']) !== ''): ?><div><strong>Reden:</strong> <?= nl2br(htmlspecialchars($e['reason'])) ?></div><?php endif; ?>
            <?php if (trim((string)$e['reply_text']) !== ''): ?><div style="margin-top: 5px;"><strong><?= in_array($e['decision'], ['reply', 'level_up'], true) ? 'Verstuurd aan het team' : 'Voorgestelde reactie' ?>:</strong> <?= nl2br(htmlspecialchars($e['reply_text'])) ?></div><?php endif; ?>
            <div class="muted" style="margin-top: 5px;">Agent <?= htmlspecialchars((string)$e['agent_id']) ?> · <?= htmlspecialchars((string)$e['models']) ?><?= $e['duration_ms'] !== null ? ' · ' . round((int)$e['duration_ms'] / 1000, 1) . ' s' : '' ?></div>
            <details style="margin-top: 8px;">
                <summary style="cursor: pointer; color: #667eea;">Uitvoer van de agents</summary>
                <div class="agents">
                    <?= renderAgentJson('Intentie', $e['intent_json']) ?>
                    <?= renderAgentJson('Hulp-agent', $e['helper_json']) ?>
                    <?= renderAgentJson('Beoordelaar', $e['assessor_json']) ?>
                    <?= renderAgentJson('Validator', $e['validator_json']) ?>
                </div>
            </details>
        </div>
    <?php endforeach; ?>
    <?php if (!$evaluations): ?>
        <div class="card" style="text-align: center; color: #999;">Geen beoordelingen gevonden.</div>
    <?php endif; ?>
<?php include 'admin_footer.php'; ?>
