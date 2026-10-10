<?php
/**
 * Tweestapsverificatie met TOTP volgens RFC 6238: SHA1, 6 cijfers, stappen van 30 seconden.
 * Pure functies zonder database, zodat ze los te testen zijn (tests/totp_test.php).
 */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const TOTP_WINDOW = 1; // aantal stappen speling voor en na de huidige stap
const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * Codeert bytes als base32 (RFC 4648) zonder '='-padding.
 */
function base32Encode(string $data): string {
    if ($data === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($data) as $char) {
        $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

/**
 * Decodeert base32. Spaties, '=' en kleine letters zijn toegestaan.
 * Geeft false terug bij een ongeldig teken.
 */
function base32Decode(string $encoded) {
    $encoded = strtoupper(preg_replace('/[\s=]/', '', $encoded));
    if ($encoded === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($encoded) as $char) {
        $value = strpos(BASE32_ALPHABET, $char);
        if ($value === false) {
            return false;
        }
        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/**
 * Nieuw secret van 160 bits, base32-gecodeerd (32 tekens).
 */
function totpGenerateSecret(): string {
    return base32Encode(random_bytes(20));
}

/**
 * HOTP-code (RFC 4226) voor een ruwe sleutel en tijdstap.
 */
function totpCodeAtStep(string $key, int $step): string {
    $hash = hash_hmac('sha1', pack('J', $step), $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string)($binary % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * De code voor een base32-secret op een tijdstip (Unix-tijd in seconden).
 */
function totpCode(string $secret, int $time): string {
    $key = base32Decode($secret);
    if ($key === false || $key === '') {
        return '';
    }
    return totpCodeAtStep($key, intdiv($time, TOTP_PERIOD));
}

/**
 * Controleert een code met ±TOTP_WINDOW stappen speling. Een stap die niet groter is
 * dan $lastStep wordt geweigerd, zodat een code maar één keer werkt.
 * Geeft de gebruikte tijdstap terug, of false.
 */
function totpVerify(string $secret, string $code, ?int $lastStep, ?int $time = null) {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{' . TOTP_DIGITS . '}$/', $code)) {
        return false;
    }
    $key = base32Decode($secret);
    if ($key === false || $key === '') {
        return false;
    }
    $current = intdiv($time ?? time(), TOTP_PERIOD);
    for ($step = $current - TOTP_WINDOW; $step <= $current + TOTP_WINDOW; $step++) {
        if ($lastStep !== null && $step <= $lastStep) {
            continue;
        }
        if (hash_equals(totpCodeAtStep($key, $step), $code)) {
            return $step;
        }
    }
    return false;
}

/**
 * otpauth://-URI voor de QR-code in een authenticator-app.
 */
function totpUri(string $secret, string $account, string $issuer = 'Zebrawave'): string {
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    $query = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => TOTP_DIGITS,
        'period' => TOTP_PERIOD,
    ], '', '&', PHP_QUERY_RFC3986);
    return "otpauth://totp/$label?$query";
}
