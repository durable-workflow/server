<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as RetriedChildCancellationHttp;
use GuzzleHttp\HandlerStack;

// A parent's cooperative cancellation delivered to a child it retried
// (Server #873), over raw worker HTTP on protocol 1.20 in a fresh namespace.
// A cooperative worker runs the parent; another worker runs the child. Each
// case starts the child under a retry policy and the case's cancellation
// policy, fails its first run so it is retried, requests the parent's
// cancellation, delivers it at the child boundary and completes the parent.
// It records the answers, the parent's child and cancellation events, and the
// child's runs and the retry run's events. IDs are labels.
function finishHttpRetriedChildCancellation(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['retried_child_cancellation'];
    $namespace = strtolower($workflowId.'-rcc');
    $admin->createNamespace($namespace, 'retried child cancellation λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new RetriedChildCancellationHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
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
    $answer = static fn (array $answer): array => ['status' => $answer['status'],
        'reason' => $answer['response']['reason'] ?? null, 'run_status' => $answer['response']['run_status'] ?? null,
        'outcome' => $answer['response']['outcome'] ?? null];
    $manifest = array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
        ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http']);
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $policy) {
        $labels = [];
        $label = static function (mixed $id) use (&$labels): mixed {
            if (! is_string($id) || $id === '') {
                return $id;
            }
            $labels[strtolower($id)] ??= '{id:'.(count($labels) + 1).'}';

            return $labels[strtolower($id)];
        };
        $id = $workflowId.'-rcc-'.$case;
        $queue = $id.'-queue';
        $parentWorker = $id.'-parent-worker';
        $childWorker = $id.'-child-worker';
        foreach ([[$parentWorker, $definition['workflow_type'], ['cooperative_cancellation']], [$childWorker, $definition['child_type'], []]] as [$worker, $type, $capabilities]) {
            $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
                'sdk_version' => 'raw-http', 'supported_workflow_types' => [$type], 'supported_activity_types' => [],
                'capabilities' => $capabilities, 'capability_manifest' => $manifest]);
            if ($registered['status'] !== 201) {
                throw new RuntimeException('Retried child cancellation registration: '.json_encode($registered));
            }
        }
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]);
        if ($started['status'] !== 201) {
            throw new RuntimeException('Retried child cancellation start: '.json_encode($started));
        }
        $parentRun = $started['response']['run_id'];
        $poll = static function (string $worker, string $type) use ($raw, $queue, $case): array {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
                    'timeout_seconds' => 1])['response']['task'] ?? null;
                if (is_array($task)) {
                    if (($task['workflow_type'] ?? null) !== $type) {
                        throw new RuntimeException("Retried child cancellation {$case}: expected a {$type} task");
                    }

                    return $task;
                }
            }
            throw new RuntimeException("Retried child cancellation {$case}: no {$type} task");
        };
        $complete = static fn (array $task, array $commands): array => $raw('POST',
            'worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
                'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
        $observed = [];
        $observed['child_start'] = $answer($complete($poll($parentWorker, $definition['workflow_type']), [[
            'type' => 'start_child_workflow', 'workflow_type' => $definition['child_type'], 'arguments' => $empty,
            'retry_policy' => ['max_attempts' => 2], 'cancellation_policy' => $policy]]));
        $observed['child_failure'] = $answer($complete($poll($childWorker, $definition['child_type']), [[
            'type' => 'fail_workflow', 'message' => 'first attempt', 'exception_type' => 'Boom']]));
        $requested = $raw('POST', 'workflows/'.rawurlencode($id).'/request-cancellation',
            ['reason' => $definition['reason'], 'cleanup_timeout_seconds' => 30]);
        $observed['request'] = ['status' => $requested['status'], 'reason' => $requested['response']['reason'] ?? null,
            'duplicate' => $requested['response']['duplicate'] ?? null];
        if ($requested['status'] === 202) {
            $root = $poll($parentWorker, $definition['workflow_type']);
            $delivered = $raw('POST', 'worker/workflow-tasks/'.$root['task_id'].'/deliver-cancellation', [
                'lease_owner' => $root['lease_owner'], 'workflow_task_attempt' => $root['workflow_task_attempt'],
                'request_id' => $requested['response']['cancellation_request']['request_id'] ?? null,
                'sequence' => 1, 'call_kind' => 'child']);
            $observed['delivery'] = $answer($delivered);
            $observed['parent_completion'] = $answer($complete($root, [['type' => 'complete_workflow', 'result' => $empty]]));
        }
        $events = $raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$parentRun.'/history')['response']['events'] ?? [];
        $observed['parent_events'] = [];
        $child = null;
        foreach ($events as $event) {
            $type = $event['event_type'];
            if (! str_starts_with($type, 'Child') && ! str_contains($type, 'Cancel')) {
                continue;
            }
            $child ??= $event['payload']['child_workflow_instance_id'] ?? null;
            $projected = ['event_type' => $type];
            foreach ($definition['event_fields'] as $field) {
                if (array_key_exists($field, $event['payload'])) {
                    $value = $event['payload'][$field];
                    $projected[$field] = str_ends_with($field, '_id') ? $label($value) : $value;
                }
            }
            $observed['parent_events'][] = $projected;
        }
        if (! is_string($child)) {
            throw new RuntimeException("Retried child cancellation {$case}: no child instance");
        }
        $runs = $raw('GET', 'workflows/'.rawurlencode($child).'/runs')['response']['runs'] ?? [];
        usort($runs, static fn (array $a, array $b): int => ($a['run_number'] ?? 0) <=> ($b['run_number'] ?? 0));
        $observed['child_runs'] = array_map(static fn (array $run): array => ['run_id' => $label($run['run_id'] ?? null),
            'run_number' => $run['run_number'] ?? null, 'status' => $run['status'] ?? null], $runs);
        $retry = end($runs);
        $history = $raw('GET', 'workflows/'.rawurlencode($child).'/runs/'.($retry['run_id'] ?? '').'/history')['response']['events'] ?? [];
        $observed['retry_events'] = array_map(static fn (array $event): string => $event['event_type'], $history);
        // End the retried child so nothing stays for later fixtures.
        $terminated = $raw('POST', 'workflows/'.rawurlencode($child).'/terminate', ['reason' => 'retried child cancellation recorded']);
        if (! in_array($terminated['status'], [200, 202], true)) {
            throw new RuntimeException("Retried child cancellation {$case} cleanup: ".json_encode($terminated));
        }
        if ($requested['status'] !== 202) {
            $raw('POST', 'workflows/'.rawurlencode($id).'/terminate', ['reason' => 'retried child cancellation recorded']);
        }
        $state[$case] = $observed;
    }

    return $state;
}
