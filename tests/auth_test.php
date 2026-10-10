<?php
/**
 * Tests voor conf/auth.php. Geen framework: stopt met exitcode 1 bij een fout.
 * Uitvoeren: docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/auth_test.php
 */

require_once __DIR__ . '/../conf/auth.php';

$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    echo ($ok ? '  ok   ' : '  FOUT ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

echo "validatePassword\n";
check('"kort" is te kort', validatePassword('kort', 'kort') !== null);
check('7 tekens is te kort', validatePassword('1234567', '1234567') !== null);
check('8 tekens is goed', validatePassword('12345678', '12345678') === null);
check('verschillende invoer geeft een fout', validatePassword('lang genoeg 1', 'lang genoeg 2') !== null);
check('8 tekens met accenten telt als 8', validatePassword('éééééééé', 'éééééééé') === null);

echo "normalizeEmail\n";
check('"  Jan@School.NL " wordt "jan@school.nl"', normalizeEmail('  Jan@School.NL ') === 'jan@school.nl');
check('"geen-mail" wordt null', normalizeEmail('geen-mail') === null);
check('lege string wordt null', normalizeEmail('') === null);

echo "validateUsername / usernameFromEmail\n";
check('"m.janssen" is geldig', validateUsername('m.janssen') === null);
check('"ab" is te kort', validateUsername('ab') !== null);
check('"jan@school" is ongeldig', validateUsername('jan@school') !== null);
check('voorstel uit e-mail', usernameFromEmail('m.de+jong@school.nl') === 'm.dejong');

echo "tokens (SQLite in het geheugen)\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE teachers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT 'user', force_password_change INTEGER DEFAULT 0, is_active INTEGER DEFAULT 1, email TEXT, display_name TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME, session_version INTEGER DEFAULT 0, totp_secret TEXT, totp_enabled INTEGER DEFAULT 0, totp_last_step INTEGER)");
$db->exec("CREATE TABLE user_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, new_email TEXT, expires_at DATETIME NOT NULL, used_at DATETIME, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT NOT NULL, identifier TEXT, ip TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL, used_at DATETIME)");
$db->exec("INSERT INTO teachers (id, username, password_hash, email) VALUES (1, 'jan', 'x', 'jan@school.nl'), (2, 'piet', 'x', 'piet@school.nl')");

$token = createUserToken($db, 1, 'reset', 60, null);
$hash = $db->query("SELECT token_hash FROM user_tokens WHERE user_id = 1")->fetchColumn();
check('token is 64 hex-tekens', (bool)preg_match('/^[0-9a-f]{64}$/', $token));
check('opgeslagen hash heeft 64 tekens en is niet het token', strlen($hash) === 64 && $hash !== $token);
$found = findUserToken($db, $token, 'reset');
check('geldig token wordt gevonden', $found !== null && (int)$found['id'] === 1);
check('verkeerd type geeft null', findUserToken($db, $token, 'invite') === null);
check('onzin-token geeft null', findUserToken($db, 'abc', 'reset') === null);

check('markTokenUsed lukt de eerste keer', markTokenUsed($db, (int)$found['token_id']));
check('markTokenUsed lukt geen tweede keer', !markTokenUsed($db, (int)$found['token_id']));
check('gebruikt token geeft null', findUserToken($db, $token, 'reset') === null);

$expired = createUserToken($db, 1, 'reset', 60, null);
$db->exec("UPDATE user_tokens SET expires_at = datetime('now', '-1 minute') WHERE used_at IS NULL");
check('verlopen token geeft null', findUserToken($db, $expired, 'reset') === null);

$first = createUserToken($db, 2, 'invite', 60, 1);
$second = createUserToken($db, 2, 'invite', 60, 1);
check('nieuw token maakt het oude ongeldig', findUserToken($db, $first, 'invite') === null && findUserToken($db, $second, 'invite') !== null);
$db->exec("UPDATE teachers SET is_active = 0 WHERE id = 2");
check('token van gedeactiveerde gebruiker geeft null', findUserToken($db, $second, 'invite') === null);

echo "pogingen beperken\n";
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
for ($i = 0; $i < 4; $i++) {
    recordAttempt($db, 'login', 'user:1');
}
check('4 pogingen: nog niet geblokkeerd', !isThrottled($db, 'login', 'user:1', 5, 15));
recordAttempt($db, 'login', 'user:1');
check('5 pogingen: geblokkeerd', isThrottled($db, 'login', 'user:1', 5, 15));
check('andere identifier niet geblokkeerd', !isThrottled($db, 'login', 'user:2', 5, 15));
check('IP-limiet van 20 nog niet bereikt', !isIpThrottled($db, 'login', 20, 15));
$db->exec("UPDATE login_attempts SET created_at = datetime('now', '-16 minutes')");
check('na 16 minuten niet meer geblokkeerd', !isThrottled($db, 'login', 'user:1', 5, 15));

echo "herstelcodes\n";
$codes = generateRecoveryCodes($db, 1);
check('10 codes van het formaat xxxxx-xxxxx', count($codes) === 10 && count(preg_grep('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $codes)) === 10);
check('codes staan niet leesbaar in de database', $db->query("SELECT COUNT(*) FROM user_recovery_codes WHERE code_hash IN ('" . implode("','", $codes) . "')")->fetchColumn() == 0);
check('code werkt (hoofdletters, zonder streepje)', useRecoveryCode($db, 1, strtoupper(str_replace('-', '', $codes[0]))));
check('code werkt geen tweede keer', !useRecoveryCode($db, 1, $codes[0]));
check('9 codes over', countRecoveryCodes($db, 1) === 9);
$newCodes = generateRecoveryCodes($db, 1);
check('na opnieuw genereren werken oude codes niet meer', !useRecoveryCode($db, 1, $codes[1]));

echo "TOTP als tweede stap\n";
$secret = totpGenerateSecret();
$db->prepare("UPDATE teachers SET totp_secret = ?, totp_enabled = 1, totp_last_step = NULL WHERE id = 1")->execute([$secret]);
$user = findTeacher($db, 1);
$code = totpCode($secret, time());
check('geldige code wordt geaccepteerd', verifySecondFactor($db, $user, $code) === 'totp');
check('dezelfde code werkt geen tweede keer', verifySecondFactor($db, findTeacher($db, 1), $code) === null);
check('herstelcode werkt als tweede stap', verifySecondFactor($db, findTeacher($db, 1), $newCodes[0]) === 'recovery');

if ($failures > 0) {
    echo "\n$failures test(s) mislukt\n";
    exit(1);
}
echo "\nAlle tests geslaagd\n";
