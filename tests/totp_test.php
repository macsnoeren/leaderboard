<?php
/**
 * Tests voor conf/totp.php met de vectoren uit RFC 6238. Stopt met exitcode 1 bij een fout.
 * Uitvoeren: docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/totp_test.php
 */

require_once __DIR__ . '/../conf/totp.php';

$failures = 0;
function check(string $name, bool $ok): void {
    global $failures;
    echo ($ok ? '  ok   ' : '  FOUT ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 van "12345678901234567890"

echo "base32\n";
check('encode van het RFC-secret', base32Encode('12345678901234567890') === $secret);
check('decode van het RFC-secret', base32Decode($secret) === '12345678901234567890');
check('decode accepteert kleine letters en spaties', base32Decode(strtolower(chunk_split($secret, 4, ' '))) === '12345678901234567890');
check('decode weigert ongeldige tekens', base32Decode('ABC1') === false);
$roundtrip = true;
for ($i = 0; $i < 200; $i++) {
    $bytes = random_bytes(random_int(1, 40));
    if (base32Decode(base32Encode($bytes)) !== $bytes) {
        $roundtrip = false;
    }
}
check('base32Decode(base32Encode($x)) === $x voor willekeurige bytes', $roundtrip);
check('gegenereerd secret heeft 32 tekens', strlen(totpGenerateSecret()) === 32);

echo "RFC 6238-vectoren (SHA1, 6 cijfers)\n";
$vectors = [
    59 => '287082',
    1111111109 => '081804',
    1111111111 => '050471',
    1234567890 => '005924',
    2000000000 => '279037',
    20000000000 => '353130',
];
foreach ($vectors as $time => $code) {
    check("tijd $time geeft $code", totpCode($secret, $time) === $code);
}

echo "totpVerify\n";
$time = 1111111111;
$step = intdiv($time, 30);
check('huidige code geeft de huidige stap', totpVerify($secret, totpCode($secret, $time), null, $time) === $step);
check('code van de vorige stap wordt geaccepteerd', totpVerify($secret, totpCode($secret, $time - 30), null, $time) === $step - 1);
check('code van de volgende stap wordt geaccepteerd', totpVerify($secret, totpCode($secret, $time + 30), null, $time) === $step + 1);
check('code van 2 stappen eerder wordt geweigerd', totpVerify($secret, totpCode($secret, $time - 60), null, $time) === false);
check('code van 2 stappen later wordt geweigerd', totpVerify($secret, totpCode($secret, $time + 60), null, $time) === false);
check('code met step == lastStep wordt geweigerd', totpVerify($secret, totpCode($secret, $time), $step, $time) === false);
check('code met step < lastStep wordt geweigerd', totpVerify($secret, totpCode($secret, $time - 30), $step, $time) === false);
check('invoer met spaties ("287 082") wordt geaccepteerd', totpVerify($secret, '287 082', null, 59) === 1);
check('foute code wordt geweigerd', totpVerify($secret, '000000', null, 59) === false);
check('te korte code wordt geweigerd', totpVerify($secret, '28708', null, 59) === false);

echo "totpUri\n";
$uri = totpUri($secret, 'jan@school.nl');
check('URI begint met otpauth://totp/Zebrawave:', strpos($uri, 'otpauth://totp/Zebrawave:jan%40school.nl?') === 0);
check('URI bevat het secret en de issuer', strpos($uri, "secret=$secret") !== false && strpos($uri, 'issuer=Zebrawave') !== false);

if ($failures > 0) {
    echo "\n$failures test(s) mislukt\n";
    exit(1);
}
echo "\nAlle tests geslaagd\n";
