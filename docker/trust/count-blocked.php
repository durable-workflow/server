<?php

// Refuses a CA store that still trusts a root withdrawn under
// GHSA-6q48-6c7w-9gw2. Each argument is a PEM bundle or a directory of PEM
// certificates. Prints the counts and exits non-zero on any blocked root, or
// when a store is missing or holds no certificate.

const BLOCKED = ['AAA Certificate Services', 'emSign ECC Root CA - G3'];

$status = 0;
foreach (array_slice($argv, 1) as $store) {
    $files = is_dir($store) ? glob(rtrim($store, '/').'/*') : [$store];
    $fingerprints = [];
    $blocked = array_fill_keys(BLOCKED, 0);
    foreach ($files as $file) {
        $pem = is_file($file) ? (string) file_get_contents($file) : '';
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $matches);
        foreach ($matches[0] as $certificate) {
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
            if ($fingerprint === false || isset($fingerprints[$fingerprint])) {
                continue;
            }
            $fingerprints[$fingerprint] = true;
            $name = openssl_x509_parse($certificate)['subject']['CN'] ?? '';
            if (array_key_exists($name, $blocked)) {
                $blocked[$name]++;
            }
        }
    }
    $count = count($fingerprints);
    echo json_encode(['store' => $store, 'certificates' => $count, 'blocked' => $blocked]), PHP_EOL;
    if ($count === 0 || array_sum($blocked) > 0) {
        $status = 1;
    }
}
exit($status);
