<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as RepairPassHttp;
use GuzzleHttp\HandlerStack;

// Task repair passes over raw HTTP. The pass is server-wide, so every request
// that would run one names run ids and a task queue that do not exist.
// The SQLite reference's retryable lock-pressure refusals are retried.
// Responses are recorded raw.
function finishHttpRepairPass(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $namespace = strtolower($workflowId.'-repair');
    $admin->createNamespace($namespace, 'repair pass λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new RepairPassHttp(['base_uri' => rtrim($url, '/').'/api/system/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $own = static fn (mixed $value): mixed => json_decode(
        str_replace('@own', $namespace, json_encode($value, JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
    $state = ['applicable' => true, 'namespace' => $namespace, 'responses' => []];
    foreach ($fixture['repair_pass']['requests'] as [$label, $body]) {
        $response = $http->request('POST', 'repair/pass', ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), 'json' => $own($body)]);
        $state['responses'][$label] = ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    return $state;
}
