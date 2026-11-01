<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as VisibilityCollationHttp;

// Text comparisons in structured list queries (published Server
// WorkflowController::index with Workflow's VisibilityFilter), over raw HTTP
// in a fresh namespace with a keyword and a text attribute. Five runs differ
// only by case, accents and trailing spaces in their workflow type and
// attribute values. Each query's matching runs are recorded as labels.
// PHP compares text with the database's collation: exactly on SQLite and
// PostgreSQL, and under utf8mb4_unicode_ci in the published MySQL schema.
function finishHttpVisibilityCollation(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['visibility_collation'];
    $namespace = strtolower($workflowId.'-vc');
    $admin->createNamespace($namespace, 'visibility collation λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $http = new VisibilityCollationHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $token, $namespace): array {
        $response = $http->request($method, $path, ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]), ...($body === null ? [] : ['json' => $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    foreach ($definition['definitions'] as [$name, $type]) {
        $created = $raw('POST', 'search-attributes', ['name' => $name, 'type' => $type]);
        if ($created['status'] !== 201) {
            throw new RuntimeException('Visibility collation definition: '.json_encode($created));
        }
    }
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $labels = [];
    foreach ($definition['runs'] as $label => $run) {
        $id = $workflowId.'-vc-'.$label;
        $labels[$id] = $label;
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $run['workflow_type'],
            'task_queue' => $workflowId.'-vc-queue', 'input' => $empty, 'search_attributes' => $run['search_attributes']]);
        if ($started['status'] !== 201) {
            throw new RuntimeException('Visibility collation start: '.json_encode($started));
        }
    }
    // The MySQL-family jobs name their server image; the other jobs compare
    // text exactly.
    $state = ['applicable' => true, 'collation' => getenv('MYSQL_IMAGE') ? 'mysql' : 'exact'];
    foreach ($definition['queries'] as $name => $query) {
        $query = str_replace('{id}', strtoupper($workflowId), $query);
        $listed = $raw('GET', 'workflows?page_size=50&query='.rawurlencode($query));
        if ($listed['status'] !== 200) {
            $state[$name] = ['status' => $listed['status'], 'reason' => $listed['response']['reason'] ?? null];

            continue;
        }
        $matched = array_map(static fn (array $row): string => $labels[$row['workflow_id'] ?? ''] ?? 'other',
            $listed['response']['workflows'] ?? []);
        sort($matched);
        $state[$name] = ['status' => 200, 'runs' => $matched];
    }
    foreach (array_keys($labels) as $id) {
        $raw('POST', 'workflows/'.rawurlencode($id).'/terminate', ['reason' => 'visibility collation recorded']);
    }

    return $state;
}
