<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as JsonDoublesHttp;
use GuzzleHttp\HandlerStack;

// JSON doubles keep every digit (server#1010). PHP's json_decode and
// json_encode return a double with the digits it was sent with. Over raw HTTP
// in a fresh namespace, one run starts with the fixture's doubles as a memo and
// as registered double search attributes, then heartbeats its activity with
// the same doubles as details. The probe records each value as it is read back
// from the run description, the visibility list, the WorkflowStarted event and
// the run's activity metadata, plus the implementation and the storage. The
// contract compares them with the fixture's input digits.
function finishHttpJsonDoubles(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['json_doubles'];
    $namespace = strtolower($workflowId.'-dbl');
    $admin->createNamespace($namespace, 'json doubles λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new JsonDoublesHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $token, $namespace): array {
        $options = ['headers' => array_filter([
            'Authorization' => $token === null ? null : 'Bearer '.$token,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
        ])];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $response = $http->request($method, $path, $options);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('JSON doubles '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };
    // json_decode reads each fixture text exactly, as the published Server does.
    $doubles = array_map(static fn (string $text): float => json_decode($text, flags: JSON_THROW_ON_ERROR),
        $definition['doubles']);
    $attributes = [];
    foreach ($doubles as $name => $value) {
        $attribute = $definition['search_attribute_prefix'].$name;
        $ok($raw('POST', '/api/search-attributes', ['name' => $attribute, 'type' => 'double']), 201, 'definition '.$attribute);
        $attributes[$attribute] = $value;
    }
    $id = $workflowId.'-dbl';
    $queue = $id.'-queue';
    $worker = $id.'-worker';
    $ok($raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
        'supported_activity_types' => [$definition['activity_type']], 'capabilities' => [],
        'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
            ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]), 201, 'registration');
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $started = $ok($raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
        'task_queue' => $queue, 'input' => $empty, 'memo' => [$definition['memo_name'] => $doubles],
        'search_attributes' => $attributes]), 201, 'start');
    $run = '/api/workflows/'.rawurlencode($id).'/runs/'.$started['run_id'];
    $described = $ok($raw('GET', $run), 200, 'describe');
    $startedEvent = null;
    foreach ($ok($raw('GET', $run.'/history'), 200, 'history')['events'] ?? [] as $event) {
        if (($event['event_type'] ?? null) === 'WorkflowStarted') {
            $startedEvent = $event['payload'] ?? [];
        }
    }
    $poll = ['worker_id' => $worker, 'task_queue' => $queue, 'timeout_seconds' => 0];
    $task = $ok($raw('POST', '/api/worker/workflow-tasks/poll', $poll), 200, 'workflow claim')['task'];
    $ok($raw('POST', '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
        'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
        'commands' => [['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
            'arguments' => $empty, 'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []],
            'start_to_close_timeout' => 300]]]), 200, 'schedule activity');
    $activity = $ok($raw('POST', '/api/worker/activity-tasks/poll', $poll), 200, 'activity claim')['task'];
    $ok($raw('POST', '/api/worker/activity-tasks/'.$activity['task_id'].'/heartbeat', [
        'activity_attempt_id' => $activity['activity_attempt_id'], 'lease_owner' => $activity['lease_owner'],
        'details' => $doubles]), 200, 'heartbeat');
    $listed = $ok($raw('GET', '/api/workflows?query='.rawurlencode('WorkflowId = "'.$id.'"')), 200, 'list')['workflows'] ?? [];
    $activities = $ok($raw('GET', $run.'/activities'), 200, 'activities')['activities'] ?? [];
    // End the run so nothing stays for later fixtures.
    $raw('POST', '/api/workflows/'.rawurlencode($id).'/terminate', ['reason' => 'json doubles recorded']);
    $pick = static fn (mixed $values): mixed => is_array($values)
        ? array_intersect_key($values, $attributes) : 'absent';

    // MySQL's json column type parses doubles itself and can land one ULP
    // off; MariaDB stores JSON as text, as SQLite and PostgreSQL keep it.
    $mysql = (string) getenv('MYSQL_IMAGE');
    $storage = $mysql !== '' && ! str_contains($mysql, 'mariadb') ? 'mysql_json' : 'exact';

    return ['applicable' => true,
        // A failed cluster info read throws, so the implementation is never guessed.
        'implementation' => $admin->clusterInfo()->raw['implementation'] ?? null,
        'storage' => $storage,
        'described_memo' => $described['memo'][$definition['memo_name']] ?? 'absent',
        'described_search_attributes' => $pick($described['search_attributes'] ?? null),
        'listed_search_attributes' => $pick($listed[0]['search_attributes'] ?? null),
        'started_memo' => $startedEvent['memo'][$definition['memo_name']] ?? 'absent',
        'started_search_attributes' => $pick($startedEvent['search_attributes'] ?? null),
        'heartbeat_details' => $activities[0]['last_heartbeat_progress']['details'] ?? 'absent'];
}
