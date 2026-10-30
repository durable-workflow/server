<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ChildRetriesHttp;
use GuzzleHttp\HandlerStack;

// A child's retry and timeout options, over raw worker HTTP on protocol 1.20
// in a fresh namespace (published Workflow ChildWorkflowRetryPolicy and the
// bridge's startChildRetryIfAvailable). Each case starts a parent, completes
// its first workflow task with one start_child_workflow command carrying the
// case's options, then plays the case's child attempts: fail, complete, or
// complete after the run deadline. It records the answers, the parent's
// child events, the child instance's runs and their started events, the
// parent's description while a retry is pending, and whether the parent is
// resumed. IDs are labels in order of first appearance; deadlines are
// recorded by presence and by how a retry's deadlines relate to the first
// run's.
function finishHttpChildRetries(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['child_retries'];
    $namespace = strtolower($workflowId.'-cr');
    $admin->createNamespace($namespace, 'child retries λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ChildRetriesHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
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
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $answer = static fn (array $answer): array => ['status' => $answer['status'],
        'reason' => $answer['response']['reason'] ?? null, 'run_status' => $answer['response']['run_status'] ?? null,
        'outcome' => $answer['response']['outcome'] ?? null, 'recorded' => $answer['response']['recorded'] ?? null];
    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $plan) {
        $labels = [];
        $label = static function (mixed $id) use (&$labels): mixed {
            if (! is_string($id) || $id === '') {
                return $id;
            }
            $key = strtolower($id);
            $labels[$key] ??= '{id:'.(count($labels) + 1).'}';

            return $labels[$key];
        };
        $id = $workflowId.'-cr-'.$case;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type'], $definition['child_type']],
            'supported_activity_types' => [],
            'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http'])]);
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]);
        if ($registered['status'] !== 201 || $started['status'] !== 201) {
            throw new RuntimeException('Child retries setup: '.json_encode([$registered, $started]));
        }
        $parentRun = $started['response']['run_id'];
        // A ready task is handed to a poll that starts after it is available,
        // so each poll is short and repeated.
        $poll = static function (string $type) use ($raw, $worker, $queue, $case): array {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
                    'timeout_seconds' => 1])['response']['task'] ?? null;
                if (is_array($task)) {
                    if (($task['workflow_type'] ?? null) !== $type) {
                        throw new RuntimeException("Child retries {$case}: expected a {$type} task, got ".json_encode($task['workflow_type'] ?? null));
                    }

                    return $task;
                }
            }
            throw new RuntimeException("Child retries {$case}: no {$type} task");
        };
        $complete = static fn (array $task, array $commands): array => $raw('POST',
            'worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
                'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
        $parentEvents = static function () use ($raw, $id, $parentRun): array {
            $events = $raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$parentRun.'/history')['response']['events'] ?? [];

            return array_values(array_filter($events, static fn (array $event): bool => str_starts_with($event['event_type'], 'Child')));
        };
        $described = static fn (): array => array_intersect_key($raw('GET', 'workflows/'.rawurlencode($id))['response'],
            array_flip($definition['described_fields']));

        $observed = [];
        $parentTask = $poll($definition['workflow_type']);
        $observed['start'] = $answer($complete($parentTask, [['type' => 'start_child_workflow',
            'workflow_type' => $definition['child_type'], 'arguments' => $empty, ...$plan['options']]]));
        $observed['attempts'] = [];
        foreach ($plan['attempts'] as $index => $step) {
            if ($index > 0 && isset($plan['retry_wait_seconds'])) {
                usleep((int) ($plan['retry_wait_seconds'] * 1_000_000));
            }
            $task = $poll($definition['child_type']);
            $attempt = ['run_id' => $label($task['run_id'] ?? null)];
            if (isset($step['fail'])) {
                $attempt['answer'] = $answer($complete($task, [['type' => 'fail_workflow', ...$step['fail']]]));
            } else {
                if (isset($step['late_seconds'])) {
                    usleep((int) ($step['late_seconds'] * 1_000_000));
                }
                $attempt['answer'] = $answer($complete($task, [['type' => 'complete_workflow', 'result' => $empty]]));
            }
            $observed['attempts'][] = $attempt;
            if ($index === 0 && count($plan['attempts']) > 1) {
                $observed['described_during_retry'] = $described();
            }
        }

        $events = $parentEvents();
        $observed['parent_events'] = array_map(static function (array $event) use ($definition, $label): array {
            $projected = ['event_type' => $event['event_type']];
            foreach ($definition['event_fields'] as $field) {
                if (array_key_exists($field, $event['payload'])) {
                    $projected[$field] = childRetriesValue($field, $event['payload'][$field], $label);
                }
            }

            return $projected;
        }, $events);
        // How each later run's deadlines relate to the first run's: the
        // execution deadline spans every attempt, and each run's deadline
        // starts with it.
        $runs = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'ChildRunStarted'));
        $observed['retry_deadlines'] = array_map(static fn (array $event): array => [
            'execution_deadline_same' => ($event['payload']['execution_deadline_at'] ?? null) === ($runs[0]['payload']['execution_deadline_at'] ?? null),
            'run_deadline_not_earlier' => ($event['payload']['run_deadline_at'] ?? '') >= ($runs[0]['payload']['run_deadline_at'] ?? ''),
        ], array_slice($runs, 1));

        $child = $events[0]['payload']['child_workflow_instance_id'] ?? null;
        if (! is_string($child)) {
            throw new RuntimeException("Child retries {$case}: no child instance");
        }
        $listed = $raw('GET', 'workflows/'.rawurlencode($child).'/runs')['response']['runs'] ?? [];
        usort($listed, static fn (array $a, array $b): int => ($a['run_number'] ?? 0) <=> ($b['run_number'] ?? 0));
        $observed['child_runs'] = array_map(static fn (array $run): array => ['run_id' => $label($run['run_id'] ?? null),
            'run_number' => $run['run_number'] ?? null, 'status' => $run['status'] ?? null,
            'is_current_run' => $run['is_current_run'] ?? null], $listed);
        $observed['child_started'] = array_map(static function (array $run) use ($raw, $child, $definition, $label): array {
            $history = $raw('GET', 'workflows/'.rawurlencode($child).'/runs/'.$run['run_id'].'/history')['response']['events'] ?? [];
            $started = array_values(array_filter($history, static fn (array $event): bool => $event['event_type'] === 'WorkflowStarted'))[0]['payload'] ?? [];
            $projected = [];
            foreach ($definition['started_fields'] as $field) {
                if (array_key_exists($field, $started)) {
                    $projected[$field] = childRetriesValue($field, $started[$field], $label);
                }
            }

            return $projected;
        }, $listed);

        // The parent is resumed once the call resolves; its task ends the
        // parent, so nothing is left for later fixtures' maintenance passes.
        $resumed = $poll($definition['workflow_type']);
        $observed['parent_resumed'] = $answer($complete($resumed, [['type' => 'complete_workflow', 'result' => $empty]]));
        $state[$case] = $observed;
    }

    return $state;
}

/** IDs as labels, deadlines by presence, and a timeout message without its time. */
function childRetriesValue(string $field, mixed $value, Closure $label): mixed
{
    if (in_array($field, ['execution_deadline_at', 'run_deadline_at'], true)) {
        return is_string($value) ? '{deadline}' : $value;
    }
    if ($field === 'message' && is_string($value)) {
        return preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)/', '{time}', $value);
    }
    if (str_ends_with($field, '_id') || in_array($field, ['workflow_link_id', 'child_call_id'], true)) {
        return $label($value);
    }

    return $value;
}
