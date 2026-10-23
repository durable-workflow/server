<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as StorageTestHttp;

// The external payload storage diagnostic of a fresh namespace: before any
// policy, with a disabled policy, for another driver, for invalid requests,
// and a passing round trip through the local driver.
function finishHttpStorageTest(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['storage_test'];
    $namespace = strtolower($workflowId.'-storage');
    $admin->createNamespace($namespace, 'storage test λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new StorageTestHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $raw = static function (string $method, string $path, array|object|null $body = null) use ($http, $token, $namespace): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    // Each target writes under its own root: the servers can run as different
    // users, and an object one target wrote must not satisfy another.
    $host = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) parse_url($url, PHP_URL_HOST)));
    $root = 'file:///state/storage-test-'.$host.'/'.$namespace;
    $policy = static fn (bool $enabled): array => ['driver' => 'local', 'enabled' => $enabled,
        'config' => ['uri' => $root]];
    $sizes = $definition['sizes'];
    $state = ['applicable' => true, 'namespace' => $namespace, 'storage_root' => $root];
    $state['not_configured'] = $raw('POST', 'storage/test', $sizes);
    $state['disabled_policy'] = $raw('PUT', 'namespaces/'.$namespace.'/external-storage', $policy(false))['status'];
    $state['disabled'] = $raw('POST', 'storage/test', $sizes);
    $state['enabled_policy'] = $raw('PUT', 'namespaces/'.$namespace.'/external-storage', $policy(true))['status'];
    $state['other_driver'] = $raw('POST', 'storage/test', ['driver' => 's3', ...$sizes]);
    foreach ($definition['invalid'] as $body) {
        $state['invalid'][] = $raw('POST', 'storage/test', $body === [] ? (object) [] : $body);
    }
    // The published SQLite reference can refuse a round trip with a retryable
    // 503 while its maintenance loop holds the file; a later attempt succeeds.
    $roundTrip = static function () use ($raw, $sizes): array {
        for ($attempt = 1; ; $attempt++) {
            $recorded = $raw('POST', 'storage/test', $sizes);
            if ($attempt === 10 || $recorded['status'] !== 503 || ($recorded['response']['retryable'] ?? null) !== true) {
                return $recorded;
            }
            usleep(500_000);
        }
    };
    $state['passed'] = $roundTrip();
    // A repeated diagnostic writes and removes the same content-addressed objects.
    $state['repeated'] = $roundTrip();

    return $state;
}
