<?php

declare(strict_types=1);

$baseUrl = rtrim((string) getenv('DW_PROBE_URL'), '/');
$token = (string) getenv('DW_PROBE_TOKEN');
$workerToken = (string) getenv('DW_PROBE_WORKER_TOKEN');

if ($baseUrl === '' || $token === '' || $workerToken === '') {
    throw new InvalidArgumentException('Set DW_PROBE_URL, DW_PROBE_TOKEN, and DW_PROBE_WORKER_TOKEN.');
}

/** @return array{status: int, body: array<string, mixed>} */
function request(string $baseUrl, string $token, string $method, string $path, ?string $namespace = null, ?array $body = null): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer '.$token,
        'X-Durable-Workflow-Control-Plane-Version: 2',
    ];

    if ($namespace !== null) {
        $headers[] = 'X-Namespace: '.$namespace;
    }

    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 10,
    ];

    if ($body !== null) {
        $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    }

    $context = stream_context_create(['http' => $options]);
    $response = @file_get_contents($baseUrl.$path, false, $context);
    $responseHeaders = $http_response_header ?? [];

    if ($response === false || ! preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0] ?? '', $match)) {
        throw new RuntimeException('HTTP request failed: '.$method.' '.$path);
    }

    $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('Expected a JSON object: '.$method.' '.$path);
    }

    return ['status' => (int) $match[1], 'body' => $decoded];
}

function expectStatus(array $response, int $status, string $case): void
{
    if ($response['status'] !== $status) {
        throw new RuntimeException($case.': expected HTTP '.$status.', received '.$response['status']);
    }
}

function expectNamespace(array $response, string $namespace, string $case): void
{
    expectStatus($response, 200, $case);

    if (($response['body']['namespace'] ?? null) !== $namespace) {
        throw new RuntimeException($case.': response escaped the requested namespace');
    }
}

$suffix = bin2hex(random_bytes(5));
$alpha = 'probe-alpha-'.$suffix;
$beta = 'probe-beta-'.$suffix;
$alphaId = 'probe-alpha-workflow-'.$suffix;
$betaId = 'probe-beta-workflow-'.$suffix;

foreach ([$alpha, $beta] as $namespace) {
    expectStatus(request($baseUrl, $token, 'POST', '/api/namespaces', body: ['name' => $namespace]), 201, 'create '.$namespace);
}

foreach ([[$alpha, $alphaId], [$beta, $betaId]] as [$namespace, $workflowId]) {
    $started = request($baseUrl, $token, 'POST', '/api/workflows', $namespace, [
        'workflow_id' => $workflowId,
        'workflow_type' => 'probe.request-isolation',
        'task_queue' => 'probe-isolation',
        'input' => [],
    ]);
    expectStatus($started, 201, 'start '.$namespace);

    if (($started['body']['namespace'] ?? null) !== $namespace) {
        throw new RuntimeException('Workflow start escaped the requested namespace');
    }
}

for ($round = 0; $round < 30; $round++) {
    expectNamespace(request($baseUrl, $token, 'GET', '/api/workflows/'.$alphaId, $alpha), $alpha, 'alpha workflow');
    expectNamespace(request($baseUrl, $token, 'GET', '/api/workflows/'.$betaId, $beta), $beta, 'beta workflow');
    expectStatus(request($baseUrl, $token, 'GET', '/api/workflows/'.$alphaId, $beta), 404, 'beta cannot see alpha workflow');
    expectStatus(request($baseUrl, 'invalid-probe-token', 'GET', '/api/workflows/'.$alphaId, $alpha), 401, 'invalid token after success');
    expectStatus(request($baseUrl, $workerToken, 'GET', '/api/workflows/'.$alphaId, $alpha), 403, 'worker role after admin success');
    expectStatus(request($baseUrl, $token, 'GET', '/api/workflows/'.$betaId, $alpha), 404, 'alpha cannot see beta workflow');
    expectNamespace(request($baseUrl, $token, 'GET', '/api/workflows/'.$alphaId, $alpha), $alpha, 'valid token after rejection');
}

echo json_encode([
    'probe' => 'persistent-request-isolation',
    'namespaces' => 2,
    'created_workflows' => 2,
    'rounds' => 30,
    'checked_requests' => 210,
    'result' => 'pass',
], JSON_THROW_ON_ERROR).PHP_EOL;
