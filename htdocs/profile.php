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
    .profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
    .form-row { margin-bottom: 15px; }
    .form-row label { display: block; margin-bottom: 5px; font-size: 0.9em; font-weight: 600; }
    .card-title { font-weight: bold; font-size: 1.1em; margin-bottom: 15px; }
    .muted { color: #888; font-size: 0.85em; line-height: 1.5; }
    dl.details { display: grid; grid-template-columns: 140px 1fr; gap: 8px 15px; }
    dl.details dt { color: #888; }
    .secret { font-family: monospace; font-size: 1.05em; letter-spacing: 1px; word-break: break-all; background: #f5f5f5; padding: 8px 10px; border-radius: 6px; }
    .codes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; font-family: monospace; font-size: 1.1em; margin: 15px 0; }
    .codes span { background: #f5f5f5; padding: 6px 10px; border-radius: 6px; text-align: center; }
    #qrcode { margin: 15px 0; }
    #qrcode img, #qrcode canvas { border: 8px solid white; }
</style>';
include 'admin_header.php';
?>
    <h1 style="margin-bottom: 30px;">👤 Profiel</h1>

    <?= renderFlash() ?>
    <?php if ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($twoFactorRequired): ?>
        <div class="error-msg"><strong>2FA verplicht:</strong> als beheerder moet je tweestapsverificatie instellen voordat je verder kunt.</div>
    <?php endif; ?>

    <?php if ($recoveryCodes): ?>
        <div class="card" style="border-top: 5px solid #fbc02d;">
            <div class="card-title">🧾 Je herstelcodes</div>
            <p class="muted">Gebruik een herstelcode als je je authenticator-app kwijt bent. Elke code werkt één keer. Bewaar ze nu op een veilige plek: <strong>ze worden niet nog een keer getoond.</strong></p>
            <div class="codes" id="recovery-codes">
                <?php foreach ($recoveryCodes as $code): ?>
                    <span><?= htmlspecialchars($code) ?></span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-outline" onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode(implode("\n", $recoveryCodes))) ?>); this.innerText='Gekopieerd';">Kopiëren</button>
        </div>
    <?php endif; ?>

    <div class="profile-grid">
        <div>
            <div class="card">
                <div class="card-title">Mijn gegevens</div>
                <dl class="details" style="margin-bottom: 20px;">
                    <dt>Gebruikersnaam</dt><dd><?= htmlspecialchars($user['username']) ?></dd>
                    <dt>E-mailadres</dt><dd><?= $user['email'] ? htmlspecialchars($user['email']) : '<span class="muted">nog niet ingesteld</span>' ?></dd>
                    <dt>Rol</dt><dd><?= $user['role'] === 'admin' ? 'Beheerder (admin)' : 'Docent (user)' ?></dd>
                    <dt>Laatst ingelogd</dt><dd><?= formatDateTime($user['last_login_at']) ?></dd>
                </dl>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_name">
                    <div class="form-row">
                        <label>Naam</label>
                        <input type="text" name="display_name" value="<?= htmlspecialchars($user['display_name'] ?? '') ?>" maxlength="100">
                    </div>
                    <button type="submit" class="btn btn-primary">Naam opslaan</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">✉️ E-mailadres wijzigen</div>
                <?php if ($pendingEmail): ?>
                    <p class="muted" style="margin-bottom: 15px;">Er wacht een bevestiging voor <strong><?= htmlspecialchars($pendingEmail) ?></strong>. Klik op de link in die mail om de wijziging af te ronden.</p>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_email">
                    <div class="form-row">
                        <label>Nieuw e-mailadres</label>
                        <input type="email" name="new_email" autocomplete="email" required>
                    </div>
                    <div class="form-row">
                        <label>Huidig wachtwoord</label>
                        <input type="password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Bevestigingslink sturen</button>
                    <p class="muted" style="margin-top: 10px;">Je adres verandert pas na een klik op de link in de mail naar het nieuwe adres. Je oude adres krijgt daarna een melding.</p>
                </form>
            </div>

            <div class="card">
                <div class="card-title">🔑 Wachtwoord</div>
                <a href="password.php" class="btn btn-outline">Wachtwoord wijzigen</a>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-title">🔐 Tweestapsverificatie (2FA)</div>
                <?php if ((int)$user['totp_enabled']): ?>
                    <p style="margin-bottom: 10px;"><span class="badge" style="background:#00897b; margin-left: 0;">Aan</span></p>
                    <p class="muted" style="margin-bottom: 20px;">Bij het inloggen vragen we een code uit je authenticator-app. Je hebt nog <strong><?= countRecoveryCodes($db, $userId) ?></strong> ongebruikte herstelcodes.</p>

                    <form method="POST" style="margin-bottom: 25px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="2fa_regenerate">
                        <div class="form-row">
                            <label>Nieuwe herstelcodes (code uit je app)</label>
                            <input type="text" name="code" autocomplete="one-time-code" maxlength="10" placeholder="123456" required>
                        </div>
                        <button type="submit" class="btn btn-outline">Nieuwe herstelcodes</button>
                    </form>

                    <?php if (!(defined('REQUIRE_2FA_ADMIN') && REQUIRE_2FA_ADMIN && $user['role'] === 'admin')): ?>
                        <form method="POST" onsubmit="return confirm('2FA uitschakelen?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="2fa_disable">
                            <div class="form-row">
                                <label>Huidig wachtwoord</label>
                                <input type="password" name="current_password" autocomplete="current-password" required>
                            </div>
                            <div class="form-row">
                                <label>Code uit je app of een herstelcode</label>
                                <input type="text" name="code" autocomplete="one-time-code" maxlength="20" required>
                            </div>
                            <button type="submit" class="btn btn-danger">2FA uitschakelen</button>
                        </form>
                    <?php endif; ?>

                <?php elseif ($setupSecret !== null): ?>
                    <p class="muted">1. Scan deze QR-code met een authenticator-app (bijvoorbeeld Google of Microsoft Authenticator, 1Password of Bitwarden).</p>
                    <div id="qrcode"></div>
                    <p class="muted">Scannen lukt niet? Voer dan dit secret handmatig in:</p>
                    <p class="secret" style="margin: 8px 0 20px;"><?= htmlspecialchars(trim(chunk_split($setupSecret, 4, ' '))) ?></p>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="2fa_confirm">
                        <div class="form-row">
                            <label>2. Voer de code uit de app in</label>
                            <input type="text" name="code" autocomplete="one-time-code" maxlength="10" placeholder="123456" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary">2FA inschakelen</button>
                        <a href="profile.php" class="btn btn-outline">Annuleren</a>
                    </form>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
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
                    <p class="muted" style="margin-bottom: 20px;">Met 2FA heb je bij het inloggen naast je wachtwoord ook een code uit een app op je telefoon nodig.</p>
                    <a href="profile.php?2fa=setup" class="btn btn-primary">2FA inschakelen</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php include 'admin_footer.php'; ?>
