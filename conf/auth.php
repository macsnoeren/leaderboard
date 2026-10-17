<?php
/**
 * Authenticatie en accountbeheer voor docenten en beheerders (tabel teachers).
 *
 * Verwacht dat config.php en database.php al geladen zijn en dat de pagina session_start()
 * heeft aangeroepen. Tijden in de database zijn UTC (CURRENT_TIMESTAMP / datetime('now')),
 * terwijl PHP's date() lokale tijd gebruikt: reken en vergelijk daarom altijd in SQL.
 */

require_once __DIR__ . '/totp.php';

const PASSWORD_MIN_LENGTH = 8;
const INVITE_TTL_MINUTES = 72 * 60;
const RESET_TTL_MINUTES = 60;
const EMAIL_CHANGE_TTL_MINUTES = 24 * 60;
const PENDING_2FA_SECONDS = 5 * 60;
const RECOVERY_CODE_COUNT = 10;
// Hash van een willekeurig wachtwoord: bij een onbekende gebruiker toch een hash controleren,
// zodat de responstijd niet verraadt of het account bestaat
const DUMMY_PASSWORD_HASH = '$2y$10$cW5gvQcRaGVLqBwYTzYuTOxBzF/8eIJwvnHfTCUDouW/5xZHSDfV6';

const LOGIN_ERROR = 'Ongeldige gebruikersnaam of wachtwoord';
const THROTTLE_ERROR = 'Te veel pogingen, probeer het over 15 minuten opnieuw';
const CSRF_ERROR = 'Het formulier is verlopen of ongeldig. Laad de pagina opnieuw en probeer het nog eens.';

// ---------------------------------------------------------------------------
// Audit, validatie en kleine helpers
// ---------------------------------------------------------------------------

/**
 * Schrijft een audit-regel. Zet nooit wachtwoorden, tokens, secrets of codes in de omschrijving.
 */
function logAudit(PDO $db, ?int $userId, string $type, string $description): void {
    $db->prepare("INSERT INTO audit_logs (user_id, event_type, description) VALUES (?, ?, ?)")
       ->execute([$userId, $type, $description]);
}

/**
 * Wachtwoordbeleid: minimaal PASSWORD_MIN_LENGTH tekens en beide velden gelijk.
 * Geeft een foutmelding terug, of null als het wachtwoord goed is.
 */
function validatePassword(string $pw, string $confirm): ?string {
    $length = function_exists('mb_strlen') ? mb_strlen($pw, 'UTF-8') : strlen($pw);
    if ($length < PASSWORD_MIN_LENGTH) {
        return 'Het wachtwoord moet minimaal ' . PASSWORD_MIN_LENGTH . ' tekens lang zijn.';
    }
    if ($pw !== $confirm) {
        return 'De wachtwoorden komen niet overeen.';
    }
    return null;
}

/**
 * Trim + kleine letters. Geeft null terug voor een leeg of ongeldig adres.
 */
function normalizeEmail(string $email): ?string {
    $email = strtolower(trim($email));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return $email;
}

/**
 * Gebruikersnamen bevatten geen '@', zodat inloggen met gebruikersnaam óf e-mail eenduidig blijft.
 */
function validateUsername(string $username): ?string {
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        return 'Een gebruikersnaam heeft 3 tot 50 tekens: letters, cijfers, punt, streepje of underscore.';
    }
    return null;
}

/**
 * Voorstel voor een gebruikersnaam: het deel vóór de @, ontdaan van ongeldige tekens.
 */
function usernameFromEmail(string $email): string {
    $local = strstr($email, '@', true);
    return substr(preg_replace('/[^A-Za-z0-9._-]/', '', $local === false ? $email : $local), 0, 50);
}

function usernameTaken(PDO $db, string $username, int $exceptId = 0): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM teachers WHERE lower(username) = lower(?) AND id != ?");
    $stmt->execute([$username, $exceptId]);
    return $stmt->fetchColumn() > 0;
}

function emailTaken(PDO $db, string $email, int $exceptId = 0): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM teachers WHERE email = ? AND id != ?");
    $stmt->execute([$email, $exceptId]);
    return $stmt->fetchColumn() > 0;
}

function findTeacher(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Naam voor in de UI en de audit-log: weergavenaam, gebruikersnaam of e-mail.
 */
function teacherLabel(array $user): string {
    return $user['display_name'] ?: ($user['username'] ?: ($user['email'] ?: '#' . $user['id']));
}

/**
 * Is dit de laatste beheerder die kan inloggen? Die mag niet verwijderd, gedeactiveerd of teruggezet worden.
 */
function isLastActiveAdmin(PDO $db, array $user): bool {
    if ($user['role'] !== 'admin' || !(int)$user['is_active'] || empty($user['password_hash'])) {
        return false;
    }
    $count = $db->query("SELECT COUNT(*) FROM teachers WHERE role = 'admin' AND is_active = 1 AND password_hash IS NOT NULL")->fetchColumn();
    return $count <= 1;
}

/**
 * Maakt alle sessies van een gebruiker ongeldig. Geeft de nieuwe versie terug.
 */
function bumpSessionVersion(PDO $db, int $userId): int {
    $db->prepare("UPDATE teachers SET session_version = COALESCE(session_version, 0) + 1 WHERE id = ?")->execute([$userId]);
    $stmt = $db->prepare("SELECT session_version FROM teachers WHERE id = ?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Een UTC-tijdstip uit de database in lokale tijd, of '—'.
 */
function formatDateTime(?string $utc): string {
    if (!$utc) {
        return '—';
    }
    $time = strtotime($utc . ' UTC');
    return $time === false ? '—' : date('d-m-Y H:i', $time);
}

function isEmailEnabled(): bool {
    return !(defined('ENABLE_EMAIL') && ENABLE_EMAIL === false);
}

function redirect(string $location): void {
    header('Location: ' . $location);
    exit;
}

/**
 * Flash-melding voor het PRG-patroon. $link wordt apart en kopieerbaar getoond
 * (bijvoorbeeld een uitnodigingslink in testmodus).
 */
function flash(string $type, string $message, ?string $link = null): void {
    $_SESSION[$type === 'success' ? 'success' : 'error'] = $message;
    if ($link !== null) {
        $_SESSION['flash_link'] = $link;
    }
}

function renderFlash(): string {
    $html = '';
    if (isset($_SESSION['success'])) {
        $html .= '<div class="success">' . htmlspecialchars($_SESSION['success']) . '</div>';
    }
    if (isset($_SESSION['error'])) {
        $html .= '<div class="error-msg">' . htmlspecialchars($_SESSION['error']) . '</div>';
    }
    if (isset($_SESSION['flash_link'])) {
        $link = htmlspecialchars($_SESSION['flash_link']);
        $html .= '<div class="card" style="display:flex; gap:10px; align-items:center;">'
            . '<input type="text" readonly value="' . $link . '" onclick="this.select()" style="flex:1; font-family:monospace;">'
            . '<button type="button" class="btn btn-outline" onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.innerText=\'Gekopieerd\';">Kopiëren</button>'
            . '</div>';
    }
    unset($_SESSION['success'], $_SESSION['error'], $_SESSION['flash_link']);
    return $html;
}

// ---------------------------------------------------------------------------
// Tokens voor uitnodigingen, wachtwoordherstel en e-mailbevestiging
// ---------------------------------------------------------------------------

/**
 * Maakt een eenmalig token (32 willekeurige bytes, hex). Alleen de sha256-hash gaat de database in;
 * het token zelf staat alleen in de mail. Oudere ongebruikte tokens van hetzelfde type vervallen,
 * zodat alleen de nieuwste link werkt.
 */
function createUserToken(PDO $db, int $userId, string $type, int $ttlMinutes, ?int $createdBy, ?string $newEmail = null): string {
    invalidateUserTokens($db, $userId, $type);
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, new_email, expires_at, created_by, created_at)
                  VALUES (?, ?, ?, ?, datetime('now', ?), ?, datetime('now'))")
       ->execute([$userId, $type, hash('sha256', $token), $newEmail, "+$ttlMinutes minutes", $createdBy]);
    return $token;
}

/**
 * Zoekt een geldig, ongebruikt token van een actieve gebruiker. Geeft de gebruiker terug
 * (alle kolommen van teachers) plus token_id en new_email, of null.
 */
function findUserToken(PDO $db, string $token, string $type): ?array {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $stmt = $db->prepare("SELECT t.*, ut.id AS token_id, ut.new_email
        FROM user_tokens ut
        JOIN teachers t ON t.id = ut.user_id
        WHERE ut.token_hash = ? AND ut.type = ? AND ut.used_at IS NULL
          AND ut.expires_at > datetime('now') AND t.is_active = 1");
    $stmt->execute([hash('sha256', $token), $type]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Markeert een token als gebruikt. Geeft false terug als een ander verzoek het net gebruikt heeft.
 */
function markTokenUsed(PDO $db, int $tokenId): bool {
    $stmt = $db->prepare("UPDATE user_tokens SET used_at = datetime('now') WHERE id = ? AND used_at IS NULL");
    $stmt->execute([$tokenId]);
    return $stmt->rowCount() === 1;
}

function invalidateUserTokens(PDO $db, int $userId, string $type): void {
    $db->prepare("DELETE FROM user_tokens WHERE user_id = ? AND type = ? AND used_at IS NULL")->execute([$userId, $type]);
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool {
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}

// ---------------------------------------------------------------------------
// Pogingen beperken (login_attempts bevat alleen mislukte pogingen en herstelaanvragen)
// ---------------------------------------------------------------------------

function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function isThrottled(PDO $db, string $scope, string $identifier, int $max, int $minutes): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND identifier = ? AND created_at > datetime('now', ?)");
    $stmt->execute([$scope, $identifier, "-$minutes minutes"]);
    return $stmt->fetchColumn() >= $max;
}

function isIpThrottled(PDO $db, string $scope, int $max, int $minutes): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND ip = ? AND created_at > datetime('now', ?)");
    $stmt->execute([$scope, clientIp(), "-$minutes minutes"]);
    return $stmt->fetchColumn() >= $max;
}

function recordAttempt(PDO $db, string $scope, string $identifier): void {
    $db->exec("DELETE FROM login_attempts WHERE created_at < datetime('now', '-24 hours')");
    $db->prepare("INSERT INTO login_attempts (scope, identifier, ip, created_at) VALUES (?, ?, ?, datetime('now'))")
       ->execute([$scope, $identifier, clientIp()]);
}

function clearAttempts(PDO $db, string $scope, string $identifier): void {
    $db->prepare("DELETE FROM login_attempts WHERE scope = ? AND identifier = ?")->execute([$scope, $identifier]);
}

// ---------------------------------------------------------------------------
// Sessies
// ---------------------------------------------------------------------------

/**
 * Polling-verzoeken herken je aan een GET-parameter die met 'ajax' begint.
 */
function isAjaxRequest(): bool {
    foreach (array_keys($_GET) as $key) {
        if (strncmp((string)$key, 'ajax', 4) === 0) {
            return true;
        }
    }
    return false;
}

function endSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Stuurt door, of geeft bij een AJAX-verzoek alleen een lege statuscode terug.
 */
function denyRequest(string $location, int $ajaxStatus): void {
    if (isAjaxRequest()) {
        http_response_code($ajaxStatus);
        exit;
    }
    redirect($location);
}

/**
 * Moet deze gebruiker eerst 2FA instellen (REQUIRE_2FA_ADMIN)?
 */
function needsTwoFactorSetup(array $user): bool {
    return defined('REQUIRE_2FA_ADMIN') && REQUIRE_2FA_ADMIN
        && $user['role'] === 'admin' && !(int)$user['totp_enabled'];
}

/**
 * Leest de ingelogde docent bij elk verzoek opnieuw uit de database. Een verwijderde,
 * gedeactiveerde of niet-geactiveerde gebruiker en een verouderde sessieversie worden uitgelogd.
 * Rol en naam in de sessie worden ververst, en "wachtwoord wijzigen verplicht" blokkeert
 * alles behalve password.php (ook POST-handlers).
 */
function requireTeacher(PDO $db): array {
    $loggedIn = !empty($_SESSION['teacher_logged_in']) && isset($_SESSION['teacher_id']);
    $user = $loggedIn ? findTeacher($db, (int)$_SESSION['teacher_id']) : null;

    $valid = $user
        && (int)$user['is_active'] === 1
        && !empty($user['password_hash'])
        && (int)$user['session_version'] === (int)($_SESSION['session_version'] ?? -1);

    if (!$valid) {
        // Alleen een ingelogde sessie beëindigen: een oude tab die blijft pollen mag de
        // CSRF-token van een login- of setupformulier in dezelfde sessie niet wissen
        if ($loggedIn) {
            endSession();
        }
        denyRequest('login.php' . ($loggedIn ? '?expired=1' : ''), 401);
    }

    $_SESSION['teacher_role'] = $user['role'] ?: 'user';
    $_SESSION['teacher_username'] = $user['username'];
    $_SESSION['teacher_display_name'] = $user['display_name'] ?: $user['username'];

    $page = basename($_SERVER['PHP_SELF'] ?? '');
    if ((int)$user['force_password_change'] === 1) {
        if ($page !== 'password.php') {
            denyRequest('password.php', 403);
        }
    } elseif (needsTwoFactorSetup($user) && $page !== 'profile.php') {
        denyRequest('profile.php?2fa=setup', 403);
    }

    return $user;
}

function requireAdmin(PDO $db): array {
    $user = requireTeacher($db);
    if ($user['role'] !== 'admin') {
        denyRequest('teacher.php', 403);
    }
    return $user;
}

/**
 * Logt een gebruiker in na een geslaagde controle (wachtwoord en zo nodig 2FA).
 * De aanroeper stuurt daarna door naar loginRedirect($user).
 */
function completeLogin(PDO $db, array $user): void {
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_at'], $_SESSION['csrf_token']);

    $_SESSION['teacher_logged_in'] = true;
    $_SESSION['teacher_id'] = (int)$user['id'];
    $_SESSION['session_version'] = (int)$user['session_version'];
    $_SESSION['teacher_role'] = $user['role'] ?: 'user';
    $_SESSION['teacher_username'] = $user['username'];
    $_SESSION['teacher_display_name'] = $user['display_name'] ?: $user['username'];

    $db->prepare("UPDATE teachers SET last_login_at = datetime('now') WHERE id = ?")->execute([(int)$user['id']]);
    logAudit($db, (int)$user['id'], 'LOGIN', 'User logged in');
}

function loginRedirect(array $user): string {
    if ((int)$user['force_password_change'] === 1) {
        return 'password.php';
    }
    return needsTwoFactorSetup($user) ? 'profile.php?2fa=setup' : 'teacher.php';
}

// ---------------------------------------------------------------------------
// Tweestapsverificatie: herstelcodes en controle van de tweede stap
// ---------------------------------------------------------------------------

/**
 * Vervangt alle herstelcodes door RECOVERY_CODE_COUNT nieuwe (formaat xxxxx-xxxxx).
 * De codes worden gehasht opgeslagen en alleen deze ene keer teruggegeven.
 */
function generateRecoveryCodes(PDO $db, int $userId): array {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // zonder i, l, o, 0 en 1
    $db->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$userId]);
    $insert = $db->prepare("INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)");
    $codes = [];
    for ($i = 0; $i < RECOVERY_CODE_COUNT; $i++) {
        $raw = '';
        for ($j = 0; $j < 10; $j++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = substr($raw, 0, 5) . '-' . substr($raw, 5);
        $insert->execute([$userId, password_hash($code, PASSWORD_DEFAULT)]);
        $codes[] = $code;
    }
    return $codes;
}

function countRecoveryCodes(PDO $db, int $userId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Gebruikt een herstelcode (hoofdletters, spaties en een ontbrekend streepje zijn toegestaan).
 */
function useRecoveryCode(PDO $db, int $userId, string $code): bool {
    $clean = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (strlen($clean) !== 10) {
        return false;
    }
    $code = substr($clean, 0, 5) . '-' . substr($clean, 5);
    $stmt = $db->prepare("SELECT id, code_hash FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (password_verify($code, $row['code_hash'])) {
            $update = $db->prepare("UPDATE user_recovery_codes SET used_at = datetime('now') WHERE id = ? AND used_at IS NULL");
            $update->execute([$row['id']]);
            return $update->rowCount() === 1;
        }
    }
    return false;
}

/**
 * Controleert een TOTP-code en legt de gebruikte tijdstap vast, zodat de code niet nog eens werkt.
 */
function useTotpCode(PDO $db, array $user, string $code): bool {
    if (!(int)$user['totp_enabled'] || !$user['totp_secret']) {
        return false;
    }
    $lastStep = $user['totp_last_step'] === null ? null : (int)$user['totp_last_step'];
    $step = totpVerify($user['totp_secret'], $code, $lastStep);
    if ($step === false) {
        return false;
    }
    // Alleen bijwerken als geen ander verzoek deze stap intussen gebruikt heeft
    $stmt = $db->prepare("UPDATE teachers SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)");
    $stmt->execute([$step, $user['id'], $step]);
    return $stmt->rowCount() === 1;
}

/**
 * Tweede stap met een TOTP-code (6 cijfers) of een herstelcode.
 * Geeft 'totp', 'recovery' of null terug.
 */
function verifySecondFactor(PDO $db, array $user, string $input): ?string {
    if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $input))) {
        return useTotpCode($db, $user, $input) ? 'totp' : null;
    }
    return useRecoveryCode($db, (int)$user['id'], $input) ? 'recovery' : null;
}

function clearTwoFactor(PDO $db, int $userId): void {
    $db->prepare("UPDATE teachers SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = ?")->execute([$userId]);
    $db->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$userId]);
}
