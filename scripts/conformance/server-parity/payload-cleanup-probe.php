<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as PayloadCleanupHttp;

// The external payload cleanup status and pass of a fresh namespace over raw
// HTTP: the status before and after passes, each pass's report, and the
// pass's limit validation. Responses are recorded raw.
function finishHttpPayloadCleanup(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['payload_cleanup'];
    $namespace = strtolower($workflowId.'-cleanup');
    $admin->createNamespace($namespace, 'payload cleanup λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new PayloadCleanupHttp(['base_uri' => rtrim($url, '/').'/api/system/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $raw = static function (string $method, string $path, mixed $body = null) use ($http, $token, $namespace): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $state = ['applicable' => true, 'namespace' => $namespace, 'requests' => []];
    foreach ($definition['requests'] as [$label, $method, $path, $body]) {
        $state['requests'][$label] = $raw($method, $path, $body === [] ? (object) [] : $body);
    }

    return $state;
}
