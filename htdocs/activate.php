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
// Publieke pagina: een uitgenodigde docent kiest hier gebruikersnaam, naam en wachtwoord
session_start();
require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';

$db = getDB();

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$user = findUserToken($db, $token, 'invite');
if ($user && !empty($user['password_hash'])) {
    $user = null; // al geactiveerd
}

$error = '';
$form = [
    'username' => $user ? ($user['username'] ?: usernameFromEmail($user['email'] ?? '')) : '',
    'display_name' => $user['display_name'] ?? '',
];

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'username' => trim($_POST['username'] ?? ''),
        'display_name' => substr(trim($_POST['display_name'] ?? ''), 0, 100),
    ];
    $password = $_POST['password'] ?? '';

    if (!verifyCsrf()) {
        $error = CSRF_ERROR;
    } elseif ($usernameError = validateUsername($form['username'])) {
        $error = $usernameError;
    } elseif (usernameTaken($db, $form['username'], (int)$user['id'])) {
        $error = 'Deze gebruikersnaam is al in gebruik. Kies een andere.';
    } elseif ($passwordError = validatePassword($password, $_POST['confirm_password'] ?? '')) {
        $error = $passwordError;
    } else {
        $db->beginTransaction();
        if (!markTokenUsed($db, (int)$user['token_id'])) {
            $db->rollBack();
            $user = null; // tegelijk in een ander venster gebruikt
        } else {
            $db->prepare("UPDATE teachers SET username = ?, display_name = ?, password_hash = ?, force_password_change = 0 WHERE id = ?")
               ->execute([$form['username'], $form['display_name'] !== '' ? $form['display_name'] : null, password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            invalidateUserTokens($db, (int)$user['id'], 'invite');
            logAudit($db, (int)$user['id'], 'USER_ACTIVATED', "Account geactiveerd voor {$user['email']} met gebruikersnaam {$form['username']}");
            $db->commit();
            header('Location: login.php?activated=1');
            exit;
        }
    }
}
?>
<?= authPageStart('Account activeren', ['no_referrer' => true, 'icon' => 'user-plus']) ?>
        <?php if (!$user): ?>
            <?= alertBox('error', 'Deze link is ongeldig of verlopen. Vraag de beheerder om een nieuwe uitnodiging.') ?>
        <?php else: ?>
            <p class="auth-sub">Welkom! Kies een gebruikersnaam en wachtwoord voor <strong><?= htmlspecialchars($user['email']) ?></strong>.</p>
            <?php if ($error): ?>
                <?= alertBox('error', htmlspecialchars($error)) ?>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="field">
                    <label for="a-user">Gebruikersnaam</label>
                    <input type="text" name="username" id="a-user" value="<?= htmlspecialchars($form['username']) ?>" pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="username" required>
                    <div class="hint">3 tot 50 tekens: letters, cijfers, punt, streepje of underscore.</div>
                </div>
                <div class="field">
                    <label for="a-name">Naam</label>
                    <input type="text" name="display_name" id="a-name" value="<?= htmlspecialchars($form['display_name']) ?>" maxlength="100" placeholder="Zoals collega's en teams je zien">
                </div>
                <div class="field">
                    <label for="a-pw">Wachtwoord</label>
                    <input type="password" name="password" id="a-pw" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                    <div class="hint">Minimaal <?= PASSWORD_MIN_LENGTH ?> tekens.</div>
                </div>
                <div class="field">
                    <label for="a-pw2">Bevestig wachtwoord</label>
                    <input type="password" name="confirm_password" id="a-pw2" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block">Account activeren</button>
            </form>
        <?php endif; ?>
<?= authPageEnd('<a href="login.php">← Naar inloggen</a>') ?>
