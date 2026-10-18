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
require_once '../conf/mail_functions.php';
require_once '../conf/auth.php';

$db = getDB();
// Autorisatie check: Alleen admin mag hier komen
$admin = requireAdmin($db);
$adminId = (int)$admin['id'];

/**
 * Flash-melding na het versturen van een mail met een link. In testmodus of als de mail
 * mislukt, krijgt de beheerder de link te zien zodat hij hem zelf kan doorgeven.
 */
function flashMailResult($result, string $sentMessage, string $createdMessage, string $link): void {
    if (!isEmailEnabled()) {
        flash('success', "$createdMessage Testmodus: er is geen mail verstuurd. Geef deze link zelf door:", $link);
    } elseif ($result !== true) {
        flash('error', "$createdMessage De mail kon niet worden verstuurd ($result). Geef deze link zelf door:", $link);
    } else {
        flash('success', $sentMessage);
    }
}

function sendInviteFor(PDO $db, array $admin, array $user, string $auditType): void {
    $token = createUserToken($db, (int)$user['id'], 'invite', INVITE_TTL_MINUTES, (int)$admin['id']);
    $link = BASE_URL . '/activate.php?token=' . $token;
    $result = sendInviteEmail($user['email'], $link, teacherLabel($admin));
    logAudit($db, (int)$admin['id'], $auditType, "Uitnodiging voor {$user['email']} (rol: {$user['role']})" . ($result === true ? '' : ' - mail niet verstuurd'));
    flashMailResult($result, "Uitnodiging verstuurd naar {$user['email']}.", "Uitnodiging voor {$user['email']} aangemaakt (72 uur geldig).", $link);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('users.php');
    }

    // Uitnodigen: gebruiker zonder wachtwoord en gebruikersnaam, met een invite-token
    if ($action === 'invite') {
        $email = normalizeEmail($_POST['email'] ?? '');
        $name = substr(trim($_POST['display_name'] ?? ''), 0, 100);
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';

        if ($email === null) {
            flash('error', 'Vul een geldig e-mailadres in.');
        } elseif (emailTaken($db, $email)) {
            flash('error', 'E-mailadres is al in gebruik');
        } else {
            $stmt = $db->prepare("INSERT INTO teachers (username, password_hash, email, display_name, role, force_password_change, is_active, session_version, totp_enabled, created_at)
                                  VALUES (NULL, NULL, ?, ?, ?, 0, 1, 0, 0, datetime('now'))");
            $stmt->execute([$email, $name !== '' ? $name : null, $role]);
            sendInviteFor($db, $admin, findTeacher($db, (int)$db->lastInsertId()), 'USER_INVITE');
        }
        redirect('users.php');
    }

    $user = findTeacher($db, (int)($_POST['id'] ?? 0));
    if (!$user) {
        flash('error', 'Gebruiker niet gevonden');
        redirect('users.php');
    }
    $id = (int)$user['id'];
    $label = teacherLabel($user);
    $isSelf = $id === $adminId;
    $isInvited = empty($user['password_hash']);

    switch ($action) {
        case 'resend_invite':
            if (!$isInvited || !(int)$user['is_active'] || !$user['email']) {
                flash('error', 'Deze gebruiker heeft geen openstaande uitnodiging.');
            } else {
                sendInviteFor($db, $admin, $user, 'USER_INVITE_RESEND');
            }
            break;

        case 'revoke_invite':
            if (!$isInvited) {
                flash('error', 'Deze gebruiker is al geactiveerd. Gebruik deactiveren of verwijderen.');
            } else {
                $db->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM game_teachers WHERE teacher_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM teachers WHERE id = ?")->execute([$id]);
                logAudit($db, $adminId, 'USER_INVITE_REVOKE', "Uitnodiging ingetrokken voor {$user['email']}");
                flash('success', "Uitnodiging voor {$user['email']} is ingetrokken.");
            }
            break;

        case 'force_pwd':
        case 'unforce_pwd':
            if ($isInvited) {
                flash('error', 'Deze gebruiker heeft nog geen wachtwoord.');
                break;
            }
            $force = $action === 'force_pwd' ? 1 : 0;
            $db->prepare("UPDATE teachers SET force_password_change = ? WHERE id = ?")->execute([$force, $id]);
            if ($force) {
                logAudit($db, $adminId, 'USER_FORCE_PWD', "Wachtwoordwijziging verplicht voor $label (ID $id)");
                flash('success', "$label moet bij de volgende klik een nieuw wachtwoord kiezen.");
            } else {
                logAudit($db, $adminId, 'USER_UNFORCE_PWD', "Verplichte wachtwoordwijziging opgeheven voor $label (ID $id)");
                flash('success', "Verplichte wachtwoordwijziging voor $label is opgeheven.");
            }
            break;

        case 'send_reset':
            if ($isInvited || !(int)$user['is_active'] || !$user['email']) {
                flash('error', 'Een reset-link kan alleen naar een actieve gebruiker met een e-mailadres.');
                break;
            }
            $token = createUserToken($db, $id, 'reset', RESET_TTL_MINUTES, $adminId);
            $link = BASE_URL . '/reset_password.php?token=' . $token;
            $result = sendPasswordResetEmail($user['email'], $link);
            logAudit($db, $adminId, 'USER_SEND_RESET', "Reset-link gestuurd naar $label (ID $id)" . ($result === true ? '' : ' - mail niet verstuurd'));
            flashMailResult($result, "Reset-link verstuurd naar {$user['email']}.", "Reset-link voor $label aangemaakt (60 minuten geldig).", $link);
            break;

        case 'enable_user':
            // Voorkom dat je jezelf deactiveert/activeert (voor de veiligheid)
            if ($isSelf) {
                flash('error', 'Je kunt je eigen account status niet wijzigen.');
            } else {
                $db->prepare("UPDATE teachers SET is_active = 1 WHERE id = ?")->execute([$id]);
                logAudit($db, $adminId, 'USER_ENABLE', "Admin heeft operator $label (ID $id) geactiveerd.");
                flash('success', "$label is geactiveerd.");
            }
            break;

        case 'disable_user':
            if ($isSelf) {
                flash('error', 'Je kunt je eigen account niet deactiveren.');
            } elseif (isLastActiveAdmin($db, $user)) {
                flash('error', 'Je kunt de laatste actieve beheerder niet deactiveren.');
            } else {
                $db->prepare("UPDATE teachers SET is_active = 0 WHERE id = ?")->execute([$id]);
                bumpSessionVersion($db, $id);
                logAudit($db, $adminId, 'USER_DISABLE', "Admin heeft operator $label (ID $id) gedeactiveerd.");
                flash('success', "$label is gedeactiveerd en uitgelogd.");
            }
            break;

        case 'delete_user':
            if ($isSelf) {
                flash('error', 'Je kunt je eigen account niet verwijderen');
            } elseif (isLastActiveAdmin($db, $user)) {
                flash('error', 'Je kunt de laatste actieve beheerder niet verwijderen.');
            } else {
                $db->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM game_teachers WHERE teacher_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM teachers WHERE id = ?")->execute([$id]);
                logAudit($db, $adminId, 'USER_DELETE', "Operator $label (ID $id) verwijderd");
                flash('success', "$label is verwijderd.");
            }
            break;

        default:
            flash('error', 'Onbekende actie.');
    }
    redirect('users.php');
}

$users = $db->query("SELECT t.id, t.username, t.email, t.display_name, t.role, t.force_password_change, t.is_active,
        t.totp_enabled, t.last_login_at, (t.password_hash IS NULL OR t.password_hash = '') AS is_invited,
        (SELECT COUNT(*) FROM user_tokens ut WHERE ut.user_id = t.id AND ut.type = 'invite'
            AND ut.used_at IS NULL AND ut.expires_at > datetime('now')) AS open_invites
    FROM teachers t ORDER BY t.id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Status volgens het ontwerp: Gedeactiveerd, Uitgenodigd, Uitnodiging verlopen of Actief
function userStatus(array $u): array {
    if (!(int)$u['is_active']) {
        return ['Gedeactiveerd', 'badge-danger'];
    }
    if ((int)$u['is_invited']) {
        return (int)$u['open_invites'] > 0 ? ['Uitgenodigd', 'badge-info'] : ['Uitnodiging verlopen', 'badge-warning'];
    }
    return ['Actief', 'badge-success'];
}

// Knop in een eigen POST-formulier met CSRF-token
function actionButton(string $action, int $id, string $label, string $class = 'btn-secondary', ?string $confirm = null, ?string $title = null): string {
    $onsubmit = $confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '';
    return '<form method="POST" class="inline"' . $onsubmit . '>' . csrfField()
        . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="btn btn-sm ' . $class . '"' . ($title ? ' title="' . htmlspecialchars($title) . '" aria-label="' . htmlspecialchars($title) . '"' : '') . '>' . $label . '</button>'
        . '</form>';
}

$pageTitle = 'Gebruikers';
$extraCSS = '<style>
    .actions { display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; }
    .invite-form { display: grid; grid-template-columns: 2fr 2fr 1fr auto; gap: 12px; align-items: end; }
    .invite-form .field { margin-bottom: 0; }
    .user-badges { display: flex; gap: 4px; flex-wrap: wrap; }
    @media (max-width: 900px) { .invite-form { grid-template-columns: 1fr; } }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Gebruikers', '<span>Operators en beheerders</span>') ?>

        <?= renderFlash() ?>

        <div class="card">
            <div class="card-title"><?= icon('mail') ?>Operator uitnodigen</div>
            <form method="POST" class="invite-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="invite">
                <div class="field">
                    <label for="u-email">E-mailadres</label>
                    <input type="email" name="email" id="u-email" placeholder="m.janssen@school.nl" required>
                </div>
                <div class="field">
                    <label for="u-name">Naam <span class="muted">(optioneel)</span></label>
                    <input type="text" name="display_name" id="u-name" placeholder="Bijv. M. Janssen" maxlength="100">
                </div>
                <div class="field">
                    <label for="u-role">Rol</label>
                    <select name="role" id="u-role">
                        <option value="user">Operator</option>
                        <option value="admin">Beheerder</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('send') ?>Uitnodigen</button>
            </form>
            <p class="muted small" style="margin-top: 10px;">De nieuwe gebruiker krijgt een link (72 uur geldig) om zelf een gebruikersnaam en wachtwoord te kiezen.</p>
        </div>

        <div class="card">
            <div class="card-title"><?= icon('users') ?>Operators en beheerders</div>
            <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Naam</th>
                        <th scope="col">Gebruikersnaam</th>
                        <th scope="col">E-mail</th>
                        <th scope="col">Rol</th>
                        <th scope="col">Status</th>
                        <th scope="col">Laatst ingelogd</th>
                        <th scope="col" class="col-actions">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $uid = (int)$u['id'];
                        [$statusLabel, $statusClass] = userStatus($u);
                        $invited = (int)$u['is_invited'] === 1;
                        $active = (int)$u['is_active'] === 1;
                    ?>
                        <tr>
                            <td>
                                <?= $u['display_name'] !== null && $u['display_name'] !== '' ? htmlspecialchars($u['display_name']) : '<span class="muted">—</span>' ?>
                                <?php if ($uid === $adminId): ?><span class="muted small">(jij)</span><?php endif; ?>
                            </td>
                            <td><?= $u['username'] !== null ? '<strong>' . htmlspecialchars($u['username']) . '</strong>' : '<span class="muted">nog niet gekozen</span>' ?></td>
                            <td><?= $u['email'] !== null ? htmlspecialchars($u['email']) : '<span class="muted">—</span>' ?></td>
                            <td><span class="badge<?= $u['role'] === 'admin' ? ' badge-brand' : '' ?>"><?= $u['role'] === 'admin' ? 'Beheerder' : 'Operator' ?></span></td>
                            <td>
                                <div class="user-badges">
                                    <span class="badge <?= $statusClass ?>"><span class="dot"></span><?= $statusLabel ?></span>
                                    <?php if ((int)$u['force_password_change']): ?>
                                        <span class="badge badge-warning"><?= icon('alert') ?>Wachtwoord wijzigen</span>
                                    <?php endif; ?>
                                    <?php if ((int)$u['totp_enabled']): ?>
                                        <span class="badge badge-signal"><?= icon('shield-check') ?>2FA</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="muted nowrap"><?= formatDateTime($u['last_login_at']) ?></td>
                            <td>
                                <div class="actions">
                                    <a href="user_edit.php?id=<?= $uid ?>" class="btn btn-sm btn-secondary"><?= icon('pencil') ?>Bewerken</a>
                                    <?php if ($invited): ?>
                                        <?php if ($active): ?>
                                            <?= actionButton('resend_invite', $uid, icon('send') . 'Opnieuw versturen') ?>
                                        <?php endif; ?>
                                        <?= actionButton('revoke_invite', $uid, 'Intrekken', 'btn-danger', 'Uitnodiging intrekken? De gebruiker wordt verwijderd.') ?>
                                    <?php else: ?>
                                        <?php if ((int)$u['force_password_change']): ?>
                                            <?= actionButton('unforce_pwd', $uid, 'Opheffen', 'btn-secondary', null, 'Verplichte wachtwoordwijziging opheffen') ?>
                                        <?php else: ?>
                                            <?= actionButton('force_pwd', $uid, 'Wachtwoord wijzigen verplichten') ?>
                                        <?php endif; ?>
                                        <?php if ($active && $u['email']): ?>
                                            <?= actionButton('send_reset', $uid, icon('key') . 'Reset-link') ?>
                                        <?php elseif ($active): ?>
                                            <button type="button" class="btn btn-sm btn-secondary" disabled title="Geen e-mailadres bekend. Vul eerst een e-mailadres in via Bewerken."><?= icon('key') ?>Reset-link</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($active): ?>
                                        <?= actionButton('disable_user', $uid, icon('ban'), 'btn-secondary btn-icon', null, 'Deactiveren') ?>
                                    <?php else: ?>
                                        <?= actionButton('enable_user', $uid, icon('check'), 'btn-secondary btn-icon', null, 'Activeren') ?>
                                    <?php endif; ?>
                                    <?php if (!$invited): ?>
                                        <?= actionButton('delete_user', $uid, icon('trash'), 'btn-danger btn-icon', 'Permanent verwijderen?', 'Verwijderen') ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
<?php include 'admin_footer.php'; ?>
