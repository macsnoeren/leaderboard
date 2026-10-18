<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';

$db = getDB();
// Autorisatie check: Alleen admin mag hier komen
requireAdmin($db);

// Paginering instellingen
$logsPerPage = 20; // Aantal logs per pagina
// Niet $currentPage: die naam gebruikt admin_header.php voor de actieve menupagina
$pageNumber = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($pageNumber < 1) $pageNumber = 1;

$offset = ($pageNumber - 1) * $logsPerPage;

// Totaal aantal logs ophalen
$totalLogs = $db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$totalPages = ceil($totalLogs / $logsPerPage);

// Haal logs op gecombineerd met de gebruikersnaam van de docent, met paginering
$stmt = $db->prepare("
    SELECT al.*, COALESCE(t.username, t.email) AS username
    FROM audit_logs al 
    LEFT JOIN teachers t ON al.user_id = t.id 
    ORDER BY al.created_at DESC 
    LIMIT :limit OFFSET :offset
");
$stmt->bindParam(':limit', $logsPerPage, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Auditlog';
$extraCSS = '<style>
    .event { font-family: var(--font-mono); font-size: 12px; font-weight: 600; color: var(--brand); white-space: nowrap; }
    .pagination { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-top: 20px; }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Auditlog', '<span>Alle acties van docenten en de AI, nieuwste eerst</span>') ?>

    <div class="card">
        <?php if (empty($logs)): ?>
            <?= emptyState('clipboard', 'Nog geen activiteiten vastgelegd.') ?>
        <?php else: ?>
        <p class="muted text-sm" style="margin-bottom: 12px;">Pagina <?= $pageNumber ?> van <?= max(1, $totalPages) ?> · <?= (int)$totalLogs ?> regels</p>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Tijdstip</th>
                        <th scope="col">Gebruiker</th>
                        <th scope="col">Actie</th>
                        <th scope="col">Omschrijving</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="muted nowrap"><?= formatDateTime($log['created_at']) ?></td>
                            <td><?= $log['user_id'] === null ? '<span class="badge">' . icon('bot') . 'Systeem/AI</span>' : '<strong>' . htmlspecialchars($log['username'] ?? 'Verwijderd') . '</strong>' ?></td>
                            <td><span class="event"><?= htmlspecialchars($log['event_type']) ?></span></td>
                            <td><?= htmlspecialchars($log['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Paginering">
                <?php if ($pageNumber > 1): ?>
                    <a href="?page=<?= $pageNumber - 1 ?>" class="btn btn-secondary btn-sm">Vorige</a>
                <?php endif; ?>
                <?php for ($i = max(1, $pageNumber - 4); $i <= min($totalPages, $pageNumber + 4); $i++): ?>
                    <?php if ($i == $pageNumber): ?>
                        <span class="btn btn-primary btn-sm" aria-current="page"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?>" class="btn btn-secondary btn-sm"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($pageNumber < $totalPages): ?>
                    <a href="?page=<?= $pageNumber + 1 ?>" class="btn btn-secondary btn-sm">Volgende</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
<?php include 'admin_footer.php'; ?>
