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
session_start();

require_once '../conf/config.php';
require_once '../conf/database.php';
require_once '../conf/auth.php';

$db = getDB();
// Autorisatie check: Alleen admin mag hier komen
$admin = requireAdmin($db);
$adminId = (int)$admin['id'];

$user = findTeacher($db, (int)($_GET['id'] ?? 0));
if (!$user) {
    flash('error', 'Gebruiker niet gevonden');
    redirect('users.php');
}
$id = (int)$user['id'];
$isSelf = $id === $adminId;
$isInvited = empty($user['password_hash']);
$label = teacherLabel($user);
$self = 'user_edit.php?id=' . $id;

$error = '';
$form = [
    'display_name' => $user['display_name'] ?? '',
    'email' => $user['email'] ?? '',
    'username' => $user['username'] ?? '',
    'role' => $user['role'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect($self);
    }

    if ($action === 'save') {
        // Bij een fout blijft het formulier ingevuld (geen redirect)
        $form = [
            'display_name' => substr(trim($_POST['display_name'] ?? ''), 0, 100),
            'email' => trim($_POST['email'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'role' => ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user',
        ];
        $email = $form['email'] === '' ? null : normalizeEmail($form['email']);
        $username = $form['username'] === '' ? null : $form['username'];

        if ($form['email'] !== '' && $email === null) {
            $error = 'Vul een geldig e-mailadres in.';
        } elseif ($email === null && $isInvited) {
            $error = 'Een uitgenodigde gebruiker heeft een e-mailadres nodig.';
        } elseif ($email !== null && emailTaken($db, $email, $id)) {
            $error = 'E-mailadres is al in gebruik';
        } elseif ($username === null && !$isInvited) {
            $error = 'Vul een gebruikersnaam in.';
        } elseif ($username !== null && $username !== $user['username'] && ($usernameError = validateUsername($username))) {
            $error = $usernameError;
        } elseif ($username !== null && usernameTaken($db, $username, $id)) {
            $error = 'Gebruikersnaam is al in gebruik';
        } elseif ($form['role'] !== $user['role'] && $isSelf) {
            $error = 'Je kunt je eigen rol niet wijzigen.';
        } elseif ($form['role'] !== 'admin' && isLastActiveAdmin($db, $user)) {
            $error = 'Je kunt de laatste actieve beheerder niet terugzetten naar user.';
        } else {
            $new = [
                'display_name' => $form['display_name'] !== '' ? $form['display_name'] : null,
                'email' => $email,
                'username' => $username,
                'role' => $form['role'],
            ];
            $changes = [];
            foreach ($new as $field => $value) {
                if ($value !== $user[$field]) {
                    $changes[] = "$field: " . ($user[$field] ?? '(leeg)') . ' → ' . ($value ?? '(leeg)');
                }
            }
            if (!$changes) {
                flash('success', 'Er is niets gewijzigd.');
            } else {
                $db->prepare("UPDATE teachers SET display_name = ?, email = ?, username = ?, role = ? WHERE id = ?")
                   ->execute([$new['display_name'], $new['email'], $new['username'], $new['role'], $id]);
                logAudit($db, $adminId, 'USER_EDIT', "Gebruiker $label (ID $id) gewijzigd: " . implode('; ', $changes));
                flash('success', 'De gegevens zijn opgeslagen.');
            }
            redirect($self);
        }
    }

    // Wachtwoord instellen door de beheerder
    if ($action === 'set_password') {
        if ($isSelf) {
            flash('error', 'Wijzig je eigen wachtwoord via je profiel.');
        } elseif ($isInvited) {
            flash('error', 'Deze gebruiker is nog niet geactiveerd. Stuur de uitnodiging opnieuw.');
        } elseif ($passwordError = validatePassword($_POST['new_password'] ?? '', $_POST['confirm_password'] ?? '')) {
            flash('error', $passwordError);
        } else {
            $force = isset($_POST['force_change']) ? 1 : 0;
            $db->prepare("UPDATE teachers SET password_hash = ?, force_password_change = ? WHERE id = ?")
               ->execute([password_hash($_POST['new_password'], PASSWORD_DEFAULT), $force, $id]);
            bumpSessionVersion($db, $id);
            logAudit($db, $adminId, 'USER_RESET_PWD', "Admin heeft het wachtwoord van $label (ID $id) ingesteld (wijzigen verplicht: " . ($force ? 'ja' : 'nee') . ')');
            flash('success', "Het wachtwoord van $label is ingesteld en open sessies zijn uitgelogd.");
        }
        redirect($self);
    }

    // 2FA resetten: secret en herstelcodes wissen, de gebruiker logt opnieuw in zonder code
    if ($action === 'reset_2fa') {
        if ($isSelf) {
            flash('error', 'Beheer je eigen 2FA via je profiel.');
        } elseif (!(int)$user['totp_enabled']) {
            flash('error', '2FA staat voor deze gebruiker al uit.');
        } else {
            clearTwoFactor($db, $id);
            bumpSessionVersion($db, $id);
            logAudit($db, $adminId, 'USER_2FA_RESET', "Admin heeft 2FA gereset voor $label (ID $id)");
            flash('success', "2FA van $label is gereset. De gebruiker is uitgelogd en kan inloggen zonder code.");
        }
        redirect($self);
    }
}

$pageTitle = 'Gebruiker bewerken';
$extraCSS = '<style>
    .edit-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: var(--s-5); align-items: start; }
    @media (max-width: 1100px) { .edit-grid { grid-template-columns: minmax(0, 1fr); } }
    .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; text-decoration: none; margin-bottom: 12px; }
    dl.details { display: grid; grid-template-columns: 140px 1fr; gap: 6px 14px; font-size: 14px; }
    dl.details dt { color: var(--text-muted); }
</style>';
include 'admin_header.php';
?>
    <a href="users.php" class="back-link"><?= icon('arrow-left') ?>Terug naar gebruikers</a>
    <?= pageHeader($label, '<span class="badge' . ($user['role'] === 'admin' ? ' badge-brand' : '') . '">' . ($user['role'] === 'admin' ? 'Beheerder' : 'Docent') . '</span>') ?>

    <?= renderFlash() ?>
    <?php if ($error): ?>
        <?= alertBox('error', htmlspecialchars($error)) ?>
    <?php endif; ?>

    <div class="edit-grid">
        <div class="card">
            <div class="card-title"><?= icon('user') ?>Gegevens</div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <div class="field">
                    <label for="e-name">Naam</label>
                    <input type="text" name="display_name" id="e-name" value="<?= htmlspecialchars($form['display_name']) ?>" maxlength="100">
                </div>
                <div class="field">
                    <label for="e-email">E-mailadres</label>
                    <input type="email" name="email" id="e-email" value="<?= htmlspecialchars($form['email']) ?>" <?= $isInvited ? 'required' : '' ?>>
                </div>
                <div class="field">
                    <label for="e-user">Gebruikersnaam</label>
                    <input type="text" name="username" id="e-user" value="<?= htmlspecialchars($form['username']) ?>" <?= $isInvited ? 'placeholder="Kiest de gebruiker zelf bij het activeren"' : 'required' ?>>
                </div>
                <div class="field">
                    <label for="e-role">Rol</label>
                    <select name="role" id="e-role" <?= $isSelf ? 'disabled' : '' ?>>
                        <option value="user" <?= $form['role'] === 'user' ? 'selected' : '' ?>>Docent</option>
                        <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>Beheerder</option>
                    </select>
                    <?php if ($isSelf): ?>
                        <input type="hidden" name="role" value="<?= htmlspecialchars($user['role']) ?>">
                        <div class="hint">Je kunt je eigen rol niet wijzigen.</div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('save') ?>Opslaan</button>
            </form>
        </div>

        <div>
            <?php if ($isInvited): ?>
                <div class="card">
                    <div class="card-title"><?= icon('mail') ?>Uitnodiging</div>
                    <p class="muted text-sm">Deze gebruiker heeft de uitnodiging nog niet geaccepteerd. Opnieuw versturen of intrekken doe je in het <a href="users.php">overzicht</a>.</p>
                </div>
            <?php elseif ($isSelf): ?>
                <div class="card">
                    <div class="card-title"><?= icon('user') ?>Je eigen account</div>
                    <p class="muted text-sm">Je wachtwoord en 2FA beheer je via je <a href="profile.php">profiel</a>.</p>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-title"><?= icon('key') ?>Wachtwoord instellen</div>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_password">
                        <div class="field">
                            <label for="e-pw">Nieuw wachtwoord</label>
                            <input type="password" name="new_password" id="e-pw" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                            <div class="hint">Minimaal <?= PASSWORD_MIN_LENGTH ?> tekens.</div>
                        </div>
                        <div class="field">
                            <label for="e-pw2">Bevestig wachtwoord</label>
                            <input type="password" name="confirm_password" id="e-pw2" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
                        </div>
                        <label class="check field">
                            <input type="checkbox" name="force_change" value="1" checked> Wijzigen verplicht bij volgende login
                        </label>
                        <button type="submit" class="btn btn-primary">Wachtwoord instellen</button>
                        <p class="muted small" style="margin-top: 10px;">Open sessies van deze gebruiker worden uitgelogd.</p>
                    </form>
                </div>

                <div class="card">
                    <div class="card-title"><?= icon('shield') ?>Tweestapsverificatie</div>
                    <?php if ((int)$user['totp_enabled']): ?>
                        <p style="margin-bottom: 10px;"><span class="badge badge-signal"><?= icon('shield-check') ?>2FA staat aan</span></p>
                        <p class="muted text-sm" style="margin-bottom: 15px;">Resetten wist het secret en de herstelcodes; de gebruiker wordt uitgelogd en kan daarna inloggen zonder code.</p>
                        <form method="POST" onsubmit="return confirm('2FA van deze gebruiker resetten?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reset_2fa">
                            <button type="submit" class="btn btn-danger"><?= icon('rotate-ccw') ?>2FA resetten</button>
                        </form>
                    <?php else: ?>
                        <p class="muted text-sm">2FA staat uit. De gebruiker kan het zelf aanzetten in het eigen profiel.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-title"><?= icon('info') ?>Overig</div>
                <dl class="details">
                    <dt>Aangemaakt</dt><dd><?= formatDateTime($user['created_at']) ?></dd>
                    <dt>Laatst ingelogd</dt><dd><?= formatDateTime($user['last_login_at']) ?></dd>
                </dl>
            </div>
        </div>
    </div>
<?php include 'admin_footer.php'; ?>
