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
            $html .= '<div class="muted small">' . htmlspecialchars(implode(' · ', $meta)) . '</div>';
        }
        if (isset($run['error'])) {
            $html .= '<div class="agent-error">' . htmlspecialchars((string)$run['error']) . '</div>';
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
$decisionClass = ['level_up' => 'badge-success', 'reply' => 'badge-info', 'escalate' => 'badge-warning', 'suggest' => 'badge-brand', 'stale' => '', 'ignored' => ''];
$extraCSS = '<style>
    .eval-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: flex-start; }
    .eval-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 14px; }
    .eval-meta .sep { color: var(--line-strong); }
    .eval-id { font-family: var(--font-mono); font-weight: 700; }
    .eval .quote { background: var(--brand-soft); border-radius: var(--radius-sm); padding: 8px 12px; margin: 12px 0 8px; font-size: 14px; }
    .eval-body { font-size: 14px; display: grid; gap: 4px; }
    .agents { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 10px; margin-top: 10px; }
    .agent { background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 10px 12px; font-size: 13px; min-width: 0; }
    .agent h4 { margin-bottom: 4px; font-size: 13px; }
    .agent pre { white-space: pre-wrap; word-break: break-word; font-family: var(--font-mono); font-size: 12px; background: var(--surface); border: 1px solid var(--line); padding: 6px 8px; border-radius: 4px; margin-top: 4px; }
    .agent pre.thinking { color: var(--text-muted); max-height: 300px; overflow-y: auto; }
    .agent-error { background: var(--danger-soft); color: var(--danger); border-radius: 4px; padding: 6px 8px; margin: 4px 0; }
    .eval details > summary { cursor: pointer; color: var(--brand); font-size: 14px; margin-top: 10px; }
    .agreement-cell { font-family: var(--font-mono); font-weight: 700; }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('AI-logboek', gameSubtitle($game)) ?>

    <?= renderFlash() ?>

    <div class="card">
        <div class="card-title"><?= icon('trending-up') ?>Overeenstemming per opdracht</div>
        <p class="muted text-sm" style="margin-bottom: 12px;">Markeer beoordelingen hieronder met <em>AI had gelijk</em> of <em>AI had ongelijk</em> (de escalatieknoppen in Berichten tellen ook mee). Voorstel: zet <strong>Automatisch</strong> pas aan bij ≥ 90 % overeenstemming en nul onterechte level-ups.</p>
        <?php if (!$agreement): ?>
            <?= emptyState('bot', 'Nog geen beoordelingen in dit spel.') ?>
        <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>#</th><th>Opdracht</th><th class="col-num">Beoordelingen</th><th class="col-num">Level-ups</th><th class="col-num">Escalaties</th><th class="col-num">Gemarkeerd</th><th class="col-num">Overeenstemming</th></tr></thead>
                <tbody>
                    <?php foreach ($agreement as $row): $marked = (int)$row['agree'] + (int)$row['disagree']; ?>
                        <tr>
                            <td class="mono"><?= (int)$row['assignment_number'] ?></td>
                            <td><?= htmlspecialchars((string)($row['title'] ?? '—')) ?></td>
                            <td class="col-num"><?= (int)$row['total'] ?></td>
                            <td class="col-num"><?= (int)$row['level_ups'] ?></td>
                            <td class="col-num"><?= (int)$row['escalations'] ?></td>
                            <td class="col-num"><?= $marked ?></td>
                            <td class="col-num agreement-cell"><?= $marked ? round((int)$row['agree'] / $marked * 100) . ' %' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <form method="GET" class="toolbar">
            <div class="field"><label for="f-team">Team</label><select name="team" id="f-team"><option value="">Alle teams</option>
                <?php foreach ($teams as $t): ?><option value="<?= (int)$t['team_id'] ?>" <?= $filters['team'] === (int)$t['team_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$t['team_name']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="field"><label for="f-asg">Opdracht</label><select name="assignment" id="f-asg"><option value="">Alle opdrachten</option>
                <?php foreach ($numbers as $n): ?><option value="<?= (int)$n ?>" <?= $filters['assignment'] === (int)$n ? 'selected' : '' ?>>Opdracht <?= (int)$n ?></option><?php endforeach; ?>
            </select></div>
            <div class="field"><label for="f-dec">Beslissing</label><select name="decision" id="f-dec"><option value="">Alle beslissingen</option>
                <?php foreach (AI_DECISION_LABELS as $key => $label): ?><option value="<?= $key ?>" <?= $filters['decision'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
            </select></div>
            <button type="submit" class="btn btn-primary"><?= icon('search') ?>Filteren</button>
            <?php if ($query !== ''): ?><a href="ai_log.php" class="btn btn-ghost"><?= icon('x') ?>Wissen</a><?php endif; ?>
        </form>
    </div>

    <?php foreach ($evaluations as $e): ?>
        <div class="card eval" id="eval-<?= (int)$e['id'] ?>">
            <div class="eval-head">
                <div class="eval-meta">
                    <span class="eval-id">#<?= (int)$e['id'] ?></span><span class="sep">·</span>
                    <span class="muted"><?= htmlspecialchars(formatDateTime($e['created_at'])) ?></span><span class="sep">·</span>
                    <a href="ai_log.php?team=<?= (int)$e['team_id'] ?>"><?= htmlspecialchars((string)$e['team_name']) ?></a><span class="sep">·</span>
                    <span>opdracht <?= (int)$e['assignment_number'] ?></span><span class="sep">·</span>
                    <span><?= htmlspecialchars((string)$e['intent']) ?></span>
                    <?php if ($e['verdict']): ?><span class="badge badge-<?= htmlspecialchars($e['verdict']) ?>"><?= htmlspecialchars($e['verdict']) ?></span><?php endif; ?>
                    <span class="badge <?= $decisionClass[$e['decision']] ?? '' ?>"><?= htmlspecialchars(AI_DECISION_LABELS[$e['decision']] ?? (string)$e['decision']) ?></span>
                    <?php if ($e['decision'] === 'escalate'): ?><span class="muted small"><?= $e['resolved_at'] ? '(afgehandeld ' . htmlspecialchars(formatDateTime($e['resolved_at'])) . ')' : '(open)' ?></span><?php endif; ?>
                </div>
                <div class="btn-row">
                    <?php foreach (['agree' => ['thumbs-up', 'AI had gelijk', 'btn-success'], 'disagree' => ['thumbs-down', 'AI had ongelijk', 'btn-danger']] as $value => [$iconName, $label, $class]): $selected = $e['teacher_verdict'] === $value; ?>
                        <form method="POST" action="<?= htmlspecialchars($back) ?>" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="teacher_verdict">
                            <input type="hidden" name="evaluation_id" value="<?= (int)$e['id'] ?>">
                            <input type="hidden" name="verdict" value="<?= $value ?>">
                            <button type="submit" class="btn btn-sm <?= $selected ? $class : 'btn-secondary' ?>" aria-pressed="<?= $selected ? 'true' : 'false' ?>"><?= icon($iconName) ?><?= $label ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($e['team_message'] !== null): ?><div class="quote"><strong>Team:</strong> <?= nl2br(htmlspecialchars($e['team_message'])) ?></div><?php endif; ?>
            <div class="eval-body">
                <?php if (trim((string)$e['reason']) !== ''): ?><div><strong>Reden:</strong> <?= nl2br(htmlspecialchars($e['reason'])) ?></div><?php endif; ?>
                <?php if (trim((string)$e['reply_text']) !== ''): ?><div><strong><?= in_array($e['decision'], ['reply', 'level_up'], true) ? 'Verstuurd aan het team' : 'Voorgestelde reactie' ?>:</strong> <?= nl2br(htmlspecialchars($e['reply_text'])) ?></div><?php endif; ?>
                <div class="muted small">Agent <?= htmlspecialchars((string)$e['agent_id']) ?> · <?= htmlspecialchars((string)$e['models']) ?><?= $e['duration_ms'] !== null ? ' · ' . round((int)$e['duration_ms'] / 1000, 1) . ' s' : '' ?></div>
            </div>
            <details>
                <summary>Uitvoer van de agents</summary>
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
        <div class="card"><?= emptyState('search', 'Geen beoordelingen gevonden.') ?></div>
    <?php endif; ?>
<?php include 'admin_footer.php'; ?>
