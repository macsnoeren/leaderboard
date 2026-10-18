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
$user = requireTeacher($db);
$userId = (int)$user['id'];

$error = '';
$setupSecret = null; // gezet zolang 2FA ingesteld wordt

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCsrf()) {
        flash('error', CSRF_ERROR);
        redirect('profile.php');
    }

    // Naam wijzigen
    if ($action === 'update_name') {
        $name = substr(trim($_POST['display_name'] ?? ''), 0, 100);
        $name = $name !== '' ? $name : null;
        if ($name !== $user['display_name']) {
            $db->prepare("UPDATE teachers SET display_name = ? WHERE id = ?")->execute([$name, $userId]);
            logAudit($db, $userId, 'PROFILE_UPDATE', 'Naam gewijzigd: ' . ($user['display_name'] ?? '(leeg)') . ' → ' . ($name ?? '(leeg)'));
        }
        flash('success', 'Je naam is opgeslagen.');
        redirect('profile.php');
    }

    // E-mailadres wijzigen: pas na bevestiging via een link naar het nieuwe adres (confirm_email.php)
    if ($action === 'change_email') {
        $email = normalizeEmail($_POST['new_email'] ?? '');
        if (!password_verify($_POST['current_password'] ?? '', $user['password_hash'])) {
            flash('error', 'Je huidige wachtwoord klopt niet. Er is niets gewijzigd.');
        } elseif ($email === null) {
            flash('error', 'Vul een geldig e-mailadres in.');
        } elseif ($email === $user['email']) {
            flash('error', 'Dit is al je huidige e-mailadres.');
        } elseif (emailTaken($db, $email, $userId)) {
            flash('error', 'E-mailadres is al in gebruik');
        } else {
            $token = createUserToken($db, $userId, 'email_change', EMAIL_CHANGE_TTL_MINUTES, null, $email);
            $link = BASE_URL . '/confirm_email.php?token=' . $token;
            $result = sendEmailChangeConfirmation($email, $link);
            logAudit($db, $userId, 'PROFILE_EMAIL_CHANGE_REQUEST', "Wijziging e-mailadres aangevraagd naar $email" . ($result === true ? '' : ' - mail niet verstuurd'));
            if (!isEmailEnabled()) {
                flash('success', "Testmodus: er is geen mail verstuurd. Open deze link om $email te bevestigen:", $link);
            } elseif ($result !== true) {
                flash('error', "De bevestigingsmail kon niet worden verstuurd ($result). Probeer het later opnieuw.");
            } else {
                flash('success', "We hebben een bevestigingslink gestuurd naar $email. Je adres verandert pas als je op die link klikt.");
            }
        }
        redirect('profile.php');
    }

    // Vanaf hier: tweestapsverificatie. Foute codes tellen mee voor de 2FA-limiet.
    if (in_array($action, ['2fa_confirm', '2fa_regenerate', '2fa_disable'], true) && isThrottled($db, '2fa', (string)$userId, 5, 15)) {
        flash('error', THROTTLE_ERROR);
        redirect('profile.php');
    }

    // 2FA inschakelen: de code moet kloppen met het secret uit de sessie
    if ($action === '2fa_confirm' && !(int)$user['totp_enabled']) {
        $secret = $_SESSION['totp_setup_secret'] ?? null;
        if (!$secret) {
            flash('error', 'Het instellen is verlopen. Begin opnieuw.');
            redirect('profile.php');
        }
        $step = totpVerify($secret, (string)($_POST['code'] ?? ''), null);
        if ($step === false) {
            recordAttempt($db, '2fa', (string)$userId);
            // Niet doorsturen: dezelfde QR-code blijft staan, zodat de gebruiker niet opnieuw hoeft te scannen
            $error = 'De code klopt niet. Controleer de tijd op je telefoon en probeer de nieuwste code.';
            $setupSecret = $secret;
        } else {
            $db->prepare("UPDATE teachers SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?")->execute([$secret, $step, $userId]);
            unset($_SESSION['totp_setup_secret']);
            $_SESSION['recovery_codes_once'] = generateRecoveryCodes($db, $userId);
            clearAttempts($db, '2fa', (string)$userId);
            logAudit($db, $userId, '2FA_ENABLE', 'Tweestapsverificatie ingeschakeld');
            flash('success', '2FA staat aan. Bewaar je herstelcodes op een veilige plek.');
            redirect('profile.php');
        }
    }

    // Nieuwe herstelcodes: vraagt een geldige TOTP-code
    if ($action === '2fa_regenerate' && (int)$user['totp_enabled']) {
        if (useTotpCode($db, $user, (string)($_POST['code'] ?? ''))) {
            $_SESSION['recovery_codes_once'] = generateRecoveryCodes($db, $userId);
            logAudit($db, $userId, '2FA_RECOVERY_REGENERATE', 'Nieuwe herstelcodes aangemaakt');
            flash('success', 'Je hebt nieuwe herstelcodes. De oude codes werken niet meer.');
        } else {
            recordAttempt($db, '2fa', (string)$userId);
            flash('error', 'De code klopt niet. Er zijn geen nieuwe herstelcodes gemaakt.');
        }
        redirect('profile.php');
    }

    // 2FA uitschakelen: wachtwoord én een code (of herstelcode)
    if ($action === '2fa_disable' && (int)$user['totp_enabled']) {
        $passwordOk = password_verify($_POST['current_password'] ?? '', $user['password_hash']);
        if (defined('REQUIRE_2FA_ADMIN') && REQUIRE_2FA_ADMIN && $user['role'] === 'admin') {
            flash('error', '2FA is verplicht voor beheerders en kan niet worden uitgeschakeld.');
        } elseif ($passwordOk && verifySecondFactor($db, $user, (string)($_POST['code'] ?? '')) !== null) {
            clearTwoFactor($db, $userId);
            logAudit($db, $userId, '2FA_DISABLE', 'Tweestapsverificatie uitgeschakeld');
            flash('success', '2FA staat uit. Bij het inloggen is geen code meer nodig.');
        } else {
            recordAttempt($db, '2fa', (string)$userId);
            flash('error', 'Wachtwoord of code klopt niet. 2FA staat nog aan.');
        }
        redirect('profile.php');
    }

    if ($setupSecret === null) {
        redirect('profile.php');
    }
}

// 2FA instellen: elke keer laden maakt een nieuw secret (alleen in de sessie, nog niet in de database)
if ($setupSecret === null && ($_GET['2fa'] ?? '') === 'setup' && !(int)$user['totp_enabled']) {
    $setupSecret = totpGenerateSecret();
    $_SESSION['totp_setup_secret'] = $setupSecret;
}

// Herstelcodes worden precies één keer getoond
$recoveryCodes = $_SESSION['recovery_codes_once'] ?? null;
unset($_SESSION['recovery_codes_once']);

$pendingEmail = $db->prepare("SELECT new_email FROM user_tokens WHERE user_id = ? AND type = 'email_change' AND used_at IS NULL AND expires_at > datetime('now') ORDER BY id DESC LIMIT 1");
$pendingEmail->execute([$userId]);
$pendingEmail = $pendingEmail->fetchColumn();

$twoFactorRequired = needsTwoFactorSetup($user);

$pageTitle = 'Profiel';
$loadMarked = false;
$extraCSS = '<style>
    dl.details { display: grid; grid-template-columns: 140px 1fr; gap: 8px 15px; font-size: 14px; margin-bottom: 20px; }
    dl.details dt { color: var(--text-muted); }
    .secret { font-family: var(--font-mono); font-size: 15px; letter-spacing: .08em; word-break: break-all; background: var(--surface-2); border: 1px solid var(--line); padding: 8px 10px; border-radius: var(--radius-sm); margin: 8px 0 20px; }
    .codes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-family: var(--font-mono); font-size: 16px; margin: 15px 0; }
    .codes span { background: var(--surface-2); border: 1px solid var(--line); padding: 6px 10px; border-radius: var(--radius-sm); text-align: center; }
    .qr-card { margin: 12px 0; }
    .steps { color: var(--text-muted); font-size: 14px; }
</style>';
include 'admin_header.php';
?>
    <?= pageHeader('Profiel', '<span>' . htmlspecialchars($user['username']) . '</span>') ?>

    <?= renderFlash() ?>
    <?php if ($error): ?>
        <?= alertBox('error', htmlspecialchars($error)) ?>
    <?php endif; ?>
    <?php if ($twoFactorRequired): ?>
        <?= alertBox('warning', '<strong>2FA verplicht:</strong> als beheerder moet je tweestapsverificatie instellen voordat je verder kunt.') ?>
    <?php endif; ?>

    <?php if ($recoveryCodes): ?>
        <div class="card" style="border-color: var(--warning);">
            <div class="card-title"><?= icon('key') ?>Je herstelcodes</div>
            <p class="muted text-sm">Gebruik een herstelcode als je je authenticator-app kwijt bent. Elke code werkt één keer. Bewaar ze nu op een veilige plek: <strong>ze worden niet nog een keer getoond.</strong></p>
            <div class="codes" id="recovery-codes">
                <?php foreach ($recoveryCodes as $code): ?>
                    <span><?= htmlspecialchars($code) ?></span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-secondary" data-copy="<?= htmlspecialchars(implode("\n", $recoveryCodes)) ?>"><?= icon('copy') ?><span class="copy-label">Kopiëren</span></button>
        </div>
    <?php endif; ?>

    <div class="grid-halves">
        <div>
            <div class="card">
                <div class="card-title"><?= icon('user') ?>Mijn gegevens</div>
                <dl class="details">
                    <dt>Gebruikersnaam</dt><dd><?= htmlspecialchars($user['username']) ?></dd>
                    <dt>E-mailadres</dt><dd><?= $user['email'] ? htmlspecialchars($user['email']) : '<span class="muted">nog niet ingesteld</span>' ?></dd>
                    <dt>Rol</dt><dd><?= $user['role'] === 'admin' ? 'Beheerder' : 'Docent' ?></dd>
                    <dt>Laatst ingelogd</dt><dd><?= formatDateTime($user['last_login_at']) ?></dd>
                </dl>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_name">
                    <div class="field">
                        <label for="p-name">Naam</label>
                        <input type="text" name="display_name" id="p-name" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" maxlength="100">
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('save') ?>Naam opslaan</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><?= icon('mail') ?>E-mailadres wijzigen</div>
                <?php if ($pendingEmail): ?>
                    <?= alertBox('info', 'Er wacht een bevestiging voor <strong>' . htmlspecialchars($pendingEmail) . '</strong>. Klik op de link in die mail om de wijziging af te ronden.') ?>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_email">
                    <div class="field">
                        <label for="p-email">Nieuw e-mailadres</label>
                        <input type="email" name="new_email" id="p-email" autocomplete="email" required>
                    </div>
                    <div class="field">
                        <label for="p-cur">Huidig wachtwoord</label>
                        <input type="password" name="current_password" id="p-cur" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('send') ?>Bevestigingslink sturen</button>
                    <p class="muted small" style="margin-top: 10px;">Je adres verandert pas na een klik op de link in de mail naar het nieuwe adres. Je oude adres krijgt daarna een melding.</p>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><?= icon('key') ?>Wachtwoord</div>
                <a href="password.php" class="btn btn-secondary">Wachtwoord wijzigen</a>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-title"><?= icon('shield') ?>Tweestapsverificatie (2FA)</div>
                <?php if ((int)$user['totp_enabled']): ?>
                    <p style="margin-bottom: 10px;"><span class="badge badge-signal"><?= icon('shield-check') ?>Aan</span></p>
                    <p class="muted text-sm" style="margin-bottom: 20px;">Bij het inloggen vragen we een code uit je authenticator-app. Je hebt nog <strong><?= countRecoveryCodes($db, $userId) ?></strong> ongebruikte herstelcodes.</p>

                    <form method="POST" style="margin-bottom: 24px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="2fa_regenerate">
                        <div class="field">
                            <label for="p-regen">Nieuwe herstelcodes (code uit je app)</label>
                            <input type="text" name="code" id="p-regen" class="input-mono" inputmode="numeric" autocomplete="one-time-code" maxlength="10" placeholder="123456" required>
                        </div>
                        <button type="submit" class="btn btn-secondary">Nieuwe herstelcodes</button>
                    </form>

                    <?php if (!(defined('REQUIRE_2FA_ADMIN') && REQUIRE_2FA_ADMIN && $user['role'] === 'admin')): ?>
                        <form method="POST" onsubmit="return confirm('2FA uitschakelen?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="2fa_disable">
                            <div class="field">
                                <label for="p-dis-pw">Huidig wachtwoord</label>
                                <input type="password" name="current_password" id="p-dis-pw" autocomplete="current-password" required>
                            </div>
                            <div class="field">
                                <label for="p-dis-code">Code uit je app of een herstelcode</label>
                                <input type="text" name="code" id="p-dis-code" class="input-mono" autocomplete="one-time-code" maxlength="20" required>
                            </div>
                            <button type="submit" class="btn btn-danger">2FA uitschakelen</button>
                        </form>
                    <?php endif; ?>

                <?php elseif ($setupSecret !== null): ?>
                    <p class="steps">1. Scan deze QR-code met een authenticator-app (bijvoorbeeld Google of Microsoft Authenticator, 1Password of Bitwarden).</p>
                    <div class="qr-card" id="qrcode"></div>
                    <p class="steps">Scannen lukt niet? Voer dan dit secret handmatig in:</p>
                    <p class="secret"><?= htmlspecialchars(trim(chunk_split($setupSecret, 4, ' '))) ?></p>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="2fa_confirm">
                        <div class="field">
                            <label for="p-confirm">2. Voer de code uit de app in</label>
                            <input type="text" name="code" id="p-confirm" class="input-mono" inputmode="numeric" autocomplete="one-time-code" maxlength="10" placeholder="123456" required autofocus>
                        </div>
                        <div class="btn-row">
                            <button type="submit" class="btn btn-primary"><?= icon('shield-check') ?>2FA inschakelen</button>
                            <a href="profile.php" class="btn btn-secondary">Annuleren</a>
                        </div>
                    </form>
                    <script src="<?= QRCODE_SRC ?>"></script>
                    <script>
                        // De QR-code wordt in de browser getekend: het secret gaat niet naar een externe dienst
                        new QRCode(document.getElementById('qrcode'), {
                            text: <?= json_encode(totpUri($setupSecret, $user['username'])) ?>,
                            width: 200,
                            height: 200,
                            correctLevel: QRCode.CorrectLevel.M
                        });
                    </script>

                <?php else: ?>
                    <p class="muted text-sm" style="margin-bottom: 20px;">Met 2FA heb je bij het inloggen naast je wachtwoord ook een code uit een app op je telefoon nodig.</p>
                    <a href="profile.php?2fa=setup" class="btn btn-primary"><?= icon('shield-check') ?>2FA inschakelen</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php include 'admin_footer.php'; ?>
