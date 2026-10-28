<?php

declare(strict_types=1);

// A POST held in a child process while the probe goes on, such as a long
// poll or a client query that waits for its answer. The child retries only
// the documented lock-pressure refusal, which committed nothing
// (lockPressureOperationRetryDelay), and reports retries on stderr.
//
// `$settleSeconds` lets the request reach the server before the probe goes
// on. A probe that can observe the request's arrival passes 0 and checks
// that instead.
function startHeldRequest(string $url, array $headers, string $path, array $body, float $settleSeconds): array
{
    $child = <<<'PHP'
[, $url, $path, $headers, $body, $retryRule] = $argv;
require $retryRule;
$lines = '';
foreach (json_decode($headers, true) as $name => $value) {
    $lines .= "{$name}: {$value}\r\n";
}
$context = stream_context_create(['http' => ['method' => 'POST', 'header' => $lines."Content-Type: application/json\r\n",
    'content' => $body, 'ignore_errors' => true, 'timeout' => 60]]);
$retries = [];
$waited = 0.0;
while (true) {
    $response = file_get_contents(rtrim($url, '/').$path, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $status);
    $status = (int) ($status[1] ?? 0);
    $decoded = json_decode((string) $response, true);
    $delay = lockPressureOperationRetryDelay('POST', $path, $status, $decoded, count($retries), $waited);
    if ($delay === null) {
        break;
    }
    $retries[] = $delay;
    usleep((int) round($delay * 1_000_000));
    $waited += $delay;
}
if ($retries !== []) {
    fwrite(STDERR, json_encode(['lock_pressure_poll_retries' => ['path' => $path, 'delays' => $retries]]).PHP_EOL);
}
echo json_encode(['status' => $status, 'response' => $decoded]);
PHP;
    $process = proc_open([PHP_BINARY, '-r', $child, '--', $url, $path, json_encode($headers, JSON_THROW_ON_ERROR),
        json_encode($body, JSON_THROW_ON_ERROR), __DIR__.'/lock-pressure-retry.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('The held request could not start.');
    }
    if ($settleSeconds > 0) {
        usleep((int) round($settleSeconds * 1_000_000));
    }

    return [$process, $pipes];
}

// The held request's status and decoded response, without the server's
// capability and control-plane descriptions.
function collectHeldRequest(array $pending): array
{
    [$process, $pipes] = $pending;
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    proc_close($process);
    $result = json_decode((string) $output, true);
    if (! is_array($result)) {
        throw new RuntimeException('The held request failed: '.trim((string) $errors));
    }
    if (trim((string) $errors) !== '') {
        fwrite(STDERR, (string) $errors);
    }
    unset($result['response']['server_capabilities'], $result['response']['control_plane']);

    return $result;
}
