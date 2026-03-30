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

// Haal logs op gecombineerd met de gebruikersnaam van de docent
$logs = $db->query("
    SELECT al.*, t.username 
    FROM audit_logs al 
    LEFT JOIN teachers t ON al.user_id = t.id 
    ORDER BY al.created_at DESC 
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Audit Logs';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">📋 Audit Logs</h1>

    <div class="card">
        <p style="margin-bottom: 20px; color: #666;">Recente activiteiten (laatste 500 gebeurtenissen):</p>
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
    </div>
<?php include 'admin_footer.php'; ?>