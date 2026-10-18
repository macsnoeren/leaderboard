<?php
/*
 Copyright (C) 2025 Maurice Snoeren

 This program is free software: you can redistribute it and/or modify it under the terms of
 the GNU General Public License as published by the Free Software Foundation, version 3.

 This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 See the GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along with this program.
 If not, see https://www.gnu.org/licenses/.
*/
// Publieke pagina: bevestigt een nieuw e-mailadres (link uit de mail naar dat adres, zie profile.php).
// Bevestigen gaat met een knop (POST), zodat een mailscanner die de link opent niets wijzigt.
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/mail_functions.php';
require_once '../conf/auth.php';

$db = getDB();

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$user = findUserToken($db, $token, 'email_change');

$error = '';
$done = false;
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newEmail = $user['new_email'];
    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } elseif (!$newEmail || emailTaken($db, $newEmail, (int)$user['id'])) {
        $error = 'Dit e-mailadres is inmiddels in gebruik bij een ander account. Kies in je profiel een ander adres.';
    } else {
        $db->beginTransaction();
        if (!markTokenUsed($db, (int)$user['token_id'])) {
            $db->rollBack();
            $user = null;
        } else {
            $db->prepare("UPDATE teachers SET email = ? WHERE id = ?")->execute([$newEmail, $user['id']]);
            logAudit($db, (int)$user['id'], 'PROFILE_EMAIL_CHANGE', 'E-mailadres gewijzigd van ' . ($user['email'] ?? '(leeg)') . " naar $newEmail");
            $db->commit();

            // Melding naar het oude adres, zodat een ongewenste wijziging opvalt
            if ($user['email']) {
                $result = sendEmailChangedNotice($user['email'], $newEmail);
                if ($result !== true) {
                    error_log("[leaderboard] Melding e-mailwijziging naar {$user['email']} kon niet worden verstuurd: $result");
                }
            }
            $done = true;
        }
    }
}
?>
<?= authPageStart('E-mailadres bevestigen', ['no_referrer' => true, 'icon' => 'mail']) ?>
        <?php if ($done): ?>
            <?= alertBox('success', 'Je e-mailadres is gewijzigd naar <strong>' . htmlspecialchars($user['new_email']) . '</strong>.') ?>
        <?php elseif (!$user): ?>
            <?= alertBox('error', 'Deze link is ongeldig of verlopen. Vraag de wijziging opnieuw aan in je profiel.') ?>
        <?php else: ?>
            <?php if ($error): ?>
                <?= alertBox('error', htmlspecialchars($error)) ?>
            <?php endif; ?>
            <p class="auth-sub">Wil je <strong><?= htmlspecialchars($user['new_email']) ?></strong> gebruiken als e-mailadres voor het account <strong><?= htmlspecialchars($user['username']) ?></strong>?</p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <button type="submit" class="btn btn-primary btn-lg btn-block">Bevestigen</button>
            </form>
        <?php endif; ?>
<?= authPageEnd('<a href="profile.php">← Naar je profiel</a>') ?>
