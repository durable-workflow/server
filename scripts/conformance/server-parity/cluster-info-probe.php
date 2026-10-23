<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ClusterInfoHttp;
use GuzzleHttp\HandlerStack;

// Runtime external payload discovery in /api/cluster/info over raw HTTP: the
// request limits, each namespace's storage policy and the transport manifest
// that SDKs read before uploading a payload. Fresh namespaces cover an
// available, an unconfigured and a disabled policy, plus a namespace that does
// not exist. Responses are recorded raw.
function finishHttpClusterInfo(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['cluster_info'];
    $base = strtolower($workflowId);
    $names = array_map(static fn (string $suffix): string => $base.'-'.$suffix, $definition['namespaces']);
    foreach ($definition['created'] as $case) {
        $admin->createNamespace($names[$case], 'cluster info λ', 30);
    }
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ClusterInfoHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, string $namespace, mixed $body = null) use ($http, $token): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $state = ['applicable' => true, 'namespaces' => $names, 'policies' => [], 'discovery' => []];
    foreach ($definition['policies'] as $case => $policy) {
        $state['policies'][$case] = $raw('PUT', 'namespaces/'.rawurlencode($names[$case]).'/external-storage', $names[$case], $policy);
    }
    foreach ($names as $case => $namespace) {
        $info = $raw('GET', 'cluster/info', $namespace);
        $body = $info['response'];
        $state['discovery'][$case] = ['status' => $info['status'],
            'implementation' => $body['implementation'] ?? null,
            'namespace' => $body['namespace'] ?? null, 'limits' => $body['limits'] ?? null,
            'default_namespace' => $body['default_namespace'] ?? null,
            'server_id_is_text' => is_string($body['server_id'] ?? null) && $body['server_id'] !== '',
            'transport_capability' => $body['worker_protocol']['server_capabilities']['runtime_external_payload_transport'] ?? null];
    }

    return $state;
}
