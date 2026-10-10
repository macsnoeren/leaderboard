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

$pageTitle = 'Audit Logs';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">📋 Audit Logs</h1>

    <div class="card">
        <p style="margin-bottom: 20px; color: #666;">Recente activiteiten (pagina <?= $pageNumber ?> van <?= $totalPages ?>):</p>
        <table>
            <thead>
                <tr>
                    <th>Tijdstip</th>
                    <th>Gebruiker</th>
                    <th>Actie</th>
                    <th>Omschrijving</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="color: #888;"><?= $log['created_at'] ?></td>
                        <td><strong><?= htmlspecialchars($log['username'] ?? 'Systeem/Verwijderd') ?></strong></td>
                        <td><span style="color: #667eea; font-weight: bold;"><?= htmlspecialchars($log['event_type']) ?></span></td>
                        <td><?= htmlspecialchars($log['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top: 30px; text-align: center;">
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($pageNumber > 1): ?>
                        <a href="?page=<?= $pageNumber - 1 ?>" class="btn btn-outline">Vorige</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $pageNumber): ?>
                            <span class="btn btn-primary" style="pointer-events: none;"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>" class="btn btn-outline"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($pageNumber < $totalPages): ?>
                        <a href="?page=<?= $pageNumber + 1 ?>" class="btn btn-outline">Volgende</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php include 'admin_footer.php'; ?>