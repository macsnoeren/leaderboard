<?php
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';

if (!isset($_SESSION['teacher_logged_in'])) {
    header('Location: login.php');
    exit;
}

$db = getDB();

// Autorisatie check: Alleen admin mag hier komen
if (($_SESSION['teacher_role'] ?? 'user') !== 'admin') {
    header('Location: teacher.php');
    exit;
}

// Paginering instellingen
$logsPerPage = 20; // Aantal logs per pagina
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;

$offset = ($currentPage - 1) * $logsPerPage;

// Totaal aantal logs ophalen
$totalLogs = $db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$totalPages = ceil($totalLogs / $logsPerPage);

// Haal logs op gecombineerd met de gebruikersnaam van de docent, met paginering
$stmt = $db->prepare("
    SELECT al.*, t.username 
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
        <p style="margin-bottom: 20px; color: #666;">Recente activiteiten (pagina <?= $currentPage ?> van <?= $totalPages ?>):</p>
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
                    <?php if ($currentPage > 1): ?>
                        <a href="?page=<?= $currentPage - 1 ?>" class="btn btn-outline">Vorige</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $currentPage): ?>
                            <span class="btn btn-primary" style="pointer-events: none;"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>" class="btn btn-outline"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?page=<?= $currentPage + 1 ?>" class="btn btn-outline">Volgende</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php include 'admin_footer.php'; ?>