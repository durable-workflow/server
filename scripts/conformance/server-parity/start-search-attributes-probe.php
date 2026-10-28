<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as StartSearchAttributeHttp;

// Search attribute values on a workflow start (published Server
// WorkflowController::start, SearchAttributeValueValidator::validateForNamespace,
// Workflow DefaultWorkflowControlPlane::seedTypedVisibilityMetadata). One
// fresh namespace has registered definitions, so its schema is enforced;
// another has none, so types are inferred. Each case starts a run over raw
// HTTP and records the start answer, the run description's typed values and
// the start events' payloads.
function finishHttpStartSearchAttributes(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['start_search_attributes'];
    $namespaces = ['registered' => strtolower($workflowId.'-ssa'), 'open' => strtolower($workflowId.'-ssa-open')];
    foreach ($namespaces as $namespace) {
        $admin->createNamespace($namespace, 'start search attributes λ', 30);
    }
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new StartSearchAttributeHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 15]);
    $raw = static function (string $namespace, string $method, string $path, ?array $body = null) use ($http, $token): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    foreach ($definition['definitions'] as [$name, $type]) {
        $created = $raw($namespaces['registered'], 'POST', 'search-attributes', ['name' => $name, 'type' => $type]);
        if ($created['status'] !== 201) {
            throw new RuntimeException('Start search attributes definition: '.json_encode($created));
        }
    }
    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $shape) {
        $namespace = $namespaces[$shape['namespace']];
        // A case may reuse another case's workflow id, which another
        // namespace already reserves.
        $id = $workflowId.'-ssa-'.($shape['reuse_workflow_id'] ?? $case);
        $body = ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'], 'task_queue' => $id.'-queue',
            'input' => ['codec' => 'avro', 'blob' => $definition['empty_blob']]];
        if (array_key_exists('search_attributes', $shape)) {
            $body['search_attributes'] = $shape['search_attributes'] === [] ? (object) [] : $shape['search_attributes'];
        }
        $started = $raw($namespace, 'POST', 'workflows', $body);
        $observed = ['start' => ['status' => $started['status'],
            'reason' => $started['response']['reason'] ?? null, 'outcome' => $started['response']['outcome'] ?? null,
            'message' => $started['response']['message'] ?? null, 'errors' => $started['response']['errors'] ?? null]];
        if ($started['status'] === 201) {
            $runId = $started['response']['run_id'];
            $described = $raw($namespace, 'GET', 'workflows/'.rawurlencode($id), null)['response'];
            $observed['described'] = $described['search_attributes'] ?? 'absent';
            $events = $raw($namespace, 'GET', 'workflows/'.rawurlencode($id).'/runs/'.$runId.'/history', null)['response']['events'] ?? [];
            foreach ($events as $event) {
                if (in_array($event['event_type'] ?? null, ['StartAccepted', 'WorkflowStarted'], true)) {
                    $observed['events'][$event['event_type']] = array_key_exists('search_attributes', $event['payload'] ?? [])
                        ? $event['payload']['search_attributes'] : 'absent';
                }
                if (($event['event_type'] ?? null) === 'SearchAttributesUpserted') {
                    $observed['events']['SearchAttributesUpserted'] = $event['payload'];
                }
            }
            $listed = $raw($namespace, 'GET', 'workflows?query='.rawurlencode($shape['list_query'] ?? 'WorkflowId = "'.$id.'"'), null);
            $observed['listed'] = ['status' => $listed['status'],
                'workflow_ids' => array_map(static fn (array $row): ?string => $row['workflow_id'] ?? null,
                    $listed['response']['workflows'] ?? []),
                'search_attributes' => array_map(static fn (array $row): mixed => $row['search_attributes'] ?? 'absent',
                    $listed['response']['workflows'] ?? [])];
        }
        $state[$case] = $observed;
    }

    return $state;
}
