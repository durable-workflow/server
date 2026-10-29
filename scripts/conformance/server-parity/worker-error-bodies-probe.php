<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as WorkerErrorBodiesHttp;

// Worker-plane error bodies over raw worker HTTP on protocol 1.20 in a
// fresh namespace (published Server bootstrap/app.php exception rendering,
// WorkerProtocol::json, pollWorkflowTasks, ActivityTaskController). Each
// request is sent once; the probe records the status and body, with
// server_capabilities reduced to whether it is present.
function finishHttpWorkerErrorBodies(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['worker_error_bodies'];
    $namespace = strtolower($workflowId.'-web');
    $admin->createNamespace($namespace, 'worker error bodies λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new WorkerErrorBodiesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $state = ['applicable' => true];
    foreach ($definition['requests'] as $case => [$method, $path, $body]) {
        $response = $http->request($method, $path, ['headers' => $headers,
            ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $hasCapabilities = is_array($decoded['server_capabilities'] ?? null);
        unset($decoded['server_capabilities']);
        ksort($decoded);
        $state[$case] = ['status' => $response->getStatusCode(), 'server_capabilities' => $hasCapabilities, 'body' => $decoded];
    }

    return $state;
}
