<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$expected = $argv[1] ?? '';
if (! in_array($expected, ['verified', 'encrypted', 'plaintext', 'refused'], true)) {
    fwrite(STDERR, "Usage: php scripts/ci/database-tls-probe.php verified|encrypted|plaintext|refused\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$request = Request::create('/api/ready');
$response = $kernel->handle($request);
$payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
$database = $payload['checks']['database'] ?? [];
$tls = $database['tls'] ?? [];
$transport = null;
if ($expected !== 'refused' && ($database['status'] ?? '') === 'ok') {
    $connection = $app->make('db')->connection();
    if ($connection->getDriverName() === 'pgsql') {
        $row = $connection->selectOne('SELECT ssl, version, cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid()', [], useReadPdo: false);
        $transport = ['encrypted' => in_array($row->ssl ?? null, [true, 1, '1', 't'], true), 'protocol' => $row->version ?? null, 'cipher' => $row->cipher ?? null];
    } else {
        $cipher = $connection->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'", [], useReadPdo: false);
        $version = $connection->selectOne("SHOW SESSION STATUS LIKE 'Ssl_version'", [], useReadPdo: false);
        $transport = ['encrypted' => ($cipher->Value ?? '') !== '', 'protocol' => $version->Value ?? null, 'cipher' => $cipher->Value ?? null];
    }
}

$certificateVerified = $expected === 'verified';
if ($expected === 'plaintext') {
    $certificateVerified = null;
}
$valid = $expected === 'refused'
    ? $response->getStatusCode() === 503
        && ($database['status'] ?? '') === 'unavailable'
        && in_array($database['reason'] ?? '', ['database_tls_configuration_invalid', 'database_tls_connection_failed'], true)
    : $response->getStatusCode() === 200
        && ($database['status'] ?? '') === 'ok'
        && ($tls['encrypted'] ?? null) === ($expected !== 'plaintext')
        && ($tls['ca_verified'] ?? null) === $certificateVerified
        && ($tls['hostname_verified'] ?? null) === $certificateVerified
        && ($transport['encrypted'] ?? null) === ($expected !== 'plaintext')
        && ($tls['scope'] ?? '') === 'php_to_configured_database_endpoint';

echo json_encode([
    'expected' => $expected,
    'pass' => $valid,
    'http_status' => $response->getStatusCode(),
    'database_status' => $database['status'] ?? null,
    'reason' => $database['reason'] ?? null,
    'tls' => $tls,
    'direct_backend_fixture_transport' => $transport,
    'blocked_checks' => array_keys(array_filter($payload['checks'] ?? [], static fn (array $check): bool => ! in_array($check['status'] ?? null, ['ok', 'warning'], true))),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
$kernel->terminate($request, $response);
exit($valid ? 0 : 1);
