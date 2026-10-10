<?php

declare(strict_types=1);

// Launch the actual native executable with raw OS environment bytes. Docker's
// JSON environment transport cannot preserve an invalid UTF-8 value.
$keys = ['DW_WORKER_TOKEN', 'DW_OPERATOR_TOKEN', 'DW_ADMIN_TOKEN',
    'DW_PRINCIPAL_TOKENS', 'DW_AUTH_PROVIDER', 'DW_AUTH_DRIVER',
    'DW_RUNTIME_CREDENTIALS_ENABLED', 'DW_AUTH_BACKWARD_COMPATIBLE'];
$checked = [];
foreach ($keys as $key) {
    $process = proc_open(['/target/debug/durable-workflow-server'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['DW_RUST_EXPERIMENTAL' => '1', 'DW_AUTH_TOKEN' => 'parity-role-legacy', $key => chr(255)]);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to launch native configuration probe.');
    }
    $output = stream_get_contents($pipes[1], 4096).stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status === 0 || ! str_contains($output, 'unsupported development authentication configuration')
        || str_contains($output, 'DB_DATABASE')) {
        throw new RuntimeException('Unparseable native auth configuration reached storage selection: '.$key);
    }
    $checked[] = $key;
}
echo json_encode(['outcome' => 'pass', 'raw_environment_refusals' => $checked,
    'storage_opened' => false], JSON_THROW_ON_ERROR)."\n";
