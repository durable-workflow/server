<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use GuzzleHttp\Client as NamespaceDeleteHttp;

// Deleting a namespace that holds a search attribute definition and a started,
// unclaimed run, through the published SDK; then the namespace's reads, a
// second delete, the fixture's own run in its namespace, and the deleted
// run's workflow ID started again elsewhere.
function finishHttpNamespaceDelete(Client $admin, array $fixture, string $workflowId, string $queue, string $url): array
{
    $definition = $fixture['namespace_delete'];
    $namespace = strtolower($workflowId.'-delete');
    $doomed = $workflowId.'-doomed';
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new NamespaceDeleteHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $raw = static function (string $method, string $path, string $scope, ?array $body = null) use ($http, $token): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $scope,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $state = ['applicable' => true, 'namespace' => $namespace];
    $state['created'] = $admin->createNamespace($namespace, $definition['description'], 30)->raw;
    $state['search_attribute'] = $raw('POST', 'search-attributes', $namespace, $definition['search_attribute'])['status'];
    $scoped = parityClient($url, namespace: $namespace, token: $token);
    $state['started'] = $scoped->startWorkflow($fixture['workflow_type'], $doomed, $queue.'-delete', [$fixture['input']])
        ->describeSelectedRun()->raw['status'] ?? null;
    // The delete itself over HTTP, so its status is recorded too.
    $state['deleted'] = $raw('DELETE', 'namespaces/'.$namespace, 'default');
    $state['described_after'] = $raw('GET', 'namespaces/'.$namespace, 'default');
    $state['deleted_again'] = $raw('DELETE', 'namespaces/'.$namespace, 'default');
    // The published SDK's typed refusal for a namespace that is gone.
    try {
        $admin->deleteNamespace($namespace);
        $state['sdk_delete_again'] = ['accepted' => true];
    } catch (ServerException $error) {
        $state['sdk_delete_again'] = ['class' => get_class($error), 'status' => $error->status, 'reason' => $error->reason];
    }
    $state['own_run'] = $admin->describeWorkflow($workflowId)->raw['workflow_id'] ?? null;
    $state['restarted'] = $admin->startWorkflow($fixture['workflow_type'], $doomed, $queue.'-delete', [$fixture['input']])
        ->describeSelectedRun()->raw['workflow_id'] ?? null;

    return $state;
}
