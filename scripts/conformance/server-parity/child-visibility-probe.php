<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ChildVisibilityHttp;
use GuzzleHttp\HandlerStack;

// A child run's memos and search attributes, over raw worker HTTP on
// protocol 1.20 in a fresh namespace with one registered keyword attribute
// (published Workflow inheritTypedVisibilityMetadata). The parent starts
// with a memo and a search attribute, then starts one child under a retry
// policy. The probe records the child's description, fails its first run,
// records the retried run's description, the child's run list and a
// visibility query for the attribute, then completes the child and the
// parent.
function finishHttpChildVisibility(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['child_visibility'];
    $namespace = strtolower($workflowId.'-cv');
    $admin->createNamespace($namespace, 'child visibility λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ChildVisibilityHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $headers = array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json', 'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
    ]);
    $raw = static function (string $method, string $path, ?array $body = null) use ($http, $headers): array {
        $response = $http->request($method, $path, ['headers' => $headers,
            ...($body === null ? [] : ['json' => $body === [] ? (object) [] : $body])]);

        return ['status' => $response->getStatusCode(),
            'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    };
    $ok = static function (array $answer, int $status, string $step): array {
        if ($answer['status'] !== $status) {
            throw new RuntimeException("Child visibility {$step}: ".json_encode($answer));
        }

        return $answer['response'];
    };
    foreach ($definition['definitions'] as [$name, $type]) {
        $ok($raw('POST', 'search-attributes', ['name' => $name, 'type' => $type]), 201, 'definition');
    }
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $id = $workflowId.'-cv-parent';
    $queue = $id.'-queue';
    $worker = $id.'-worker';
    $ok($raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
        'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type'], $definition['child_type']],
        'supported_activity_types' => [],
        'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
            ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]), 201, 'registration');
    $started = $ok($raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
        'task_queue' => $queue, 'input' => $empty, 'memo' => $definition['memo'],
        'search_attributes' => $definition['search_attributes']]), 201, 'start');
    $poll = static function (string $type) use ($raw, $worker, $queue): array {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
                'timeout_seconds' => 1])['response']['task'] ?? null;
            if (is_array($task)) {
                if (($task['workflow_type'] ?? null) !== $type) {
                    throw new RuntimeException("Child visibility: expected a {$type} task");
                }

                return $task;
            }
        }
        throw new RuntimeException("Child visibility: no {$type} task");
    };
    $complete = static fn (array $task, array $commands): array => $raw('POST',
        'worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
    $ok($complete($poll($definition['workflow_type']), [['type' => 'start_child_workflow',
        'workflow_type' => $definition['child_type'], 'arguments' => $empty,
        'retry_policy' => ['max_attempts' => 2]]]), 200, 'child start');
    $events = $ok($raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$started['run_id'].'/history'), 200, 'history')['events'] ?? [];
    $child = null;
    foreach ($events as $event) {
        if ($event['event_type'] === 'ChildWorkflowScheduled') {
            $child = $event['payload']['child_workflow_instance_id'] ?? null;
        }
    }
    if (! is_string($child)) {
        throw new RuntimeException('Child visibility: no child instance');
    }
    $described = static function () use ($raw, $ok, $child): array {
        $run = $ok($raw('GET', 'workflows/'.rawurlencode($child)), 200, 'child description');

        return ['run_number' => $run['run_number'] ?? null, 'memo' => $run['memo'] ?? 'absent',
            'search_attributes' => $run['search_attributes'] ?? 'absent'];
    };
    $state = ['applicable' => true, 'child' => $described()];
    $ok($complete($poll($definition['child_type']), [['type' => 'fail_workflow', 'message' => 'first attempt',
        'exception_type' => 'Boom']]), 200, 'child failure');
    $state['retry'] = $described();
    $runs = $ok($raw('GET', 'workflows/'.rawurlencode($child).'/runs'), 200, 'child runs')['runs'] ?? [];
    usort($runs, static fn (array $a, array $b): int => ($a['run_number'] ?? 0) <=> ($b['run_number'] ?? 0));
    $state['runs'] = array_map(static fn (array $run): array => ['run_number' => $run['run_number'] ?? null,
        'memo' => $run['memo'] ?? 'absent'], $runs);
    $listed = $ok($raw('GET', 'workflows?query='.rawurlencode($definition['query'])), 200, 'query')['workflows'] ?? [];
    $names = [$id => 'parent', $child => 'child'];
    $rows = array_map(static fn (array $row): array => ['workflow' => $names[$row['workflow_id'] ?? ''] ?? 'other',
        'status' => $row['status'] ?? null, 'search_attributes' => $row['search_attributes'] ?? 'absent'], $listed);
    usort($rows, static fn (array $a, array $b): int => [$a['workflow'], $a['status']] <=> [$b['workflow'], $b['status']]);
    $state['listed'] = $rows;
    // End the child, then the parent, so nothing stays for later fixtures.
    $ok($complete($poll($definition['child_type']), [['type' => 'complete_workflow', 'result' => $empty]]), 200, 'child completion');
    $ok($complete($poll($definition['workflow_type']), [['type' => 'complete_workflow', 'result' => $empty]]), 200, 'parent completion');

    return $state;
}
