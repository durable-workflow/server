<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ParallelCancellationHttp;
use GuzzleHttp\HandlerStack;

// A run's cooperative cancellation delivered at a parallel group with
// activity members and at a selection handle, over raw worker HTTP on
// protocol 1.20 in a fresh namespace (published Workflow
// CooperativeCancellationDelivery::parallelEligibility and
// WorkflowExecutor::deliverPortableCancellation). Each case completes the
// run's first task with a group of members, claims the first activity,
// requests the run's cancellation, delivers it at the case's boundary and
// completes the run. It records the answers, the run's member and
// cancellation events, and what the claimed activity's heartbeat is told.
function finishHttpParallelCancellation(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['parallel_cancellation'];
    $namespace = strtolower($workflowId.'-pc');
    $admin->createNamespace($namespace, 'parallel cancellation λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ParallelCancellationHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
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
        'reason' => $answer['response']['reason'] ?? null, 'run_status' => $answer['response']['run_status'] ?? null];
    $delivery = static fn (array $answer): array => ['status' => $answer['status'],
        ...array_intersect_key($answer['response'], array_flip(['delivered', 'reason', 'sequence', 'call_kind',
            'sequence_span', 'operation_sequence', 'operation_sequence_span']))];
    $manifest = array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
        ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http']);
    $empty = ['codec' => 'avro', 'blob' => $definition['empty_blob']];
    $state = ['applicable' => true];
    foreach ($definition['cases'] as $case => $plan) {
        $id = $workflowId.'-pc-'.$case;
        $queue = $id.'-queue';
        $worker = $id.'-worker';
        $registered = $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [$definition['activity_type']], 'capabilities' => ['cooperative_cancellation'],
            'capability_manifest' => $manifest]);
        $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty]);
        if ($registered['status'] !== 201 || $started['status'] !== 201) {
            throw new RuntimeException('Parallel cancellation setup: '.json_encode([$registered, $started]));
        }
        $poll = static function () use ($raw, $worker, $queue, $case): array {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $task = $raw('POST', 'worker/workflow-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
                    'timeout_seconds' => 1])['response']['task'] ?? null;
                if (is_array($task)) {
                    return $task;
                }
            }
            throw new RuntimeException("Parallel cancellation {$case}: no workflow task");
        };
        $complete = static fn (array $task, array $commands): array => $raw('POST',
            'worker/workflow-tasks/'.$task['task_id'].'/complete', ['lease_owner' => $task['lease_owner'],
                'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
        $commands = array_map(static function (array $member) use ($definition, $empty): array {
            $command = $member['kind'] === 'activity'
                ? ['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'], 'arguments' => $empty]
                : ['type' => 'start_timer', 'delay_seconds' => 3600];

            return [...$command, ...$member['group'], 'parallel_group_path' => [$member['group']]];
        }, $plan['members']);
        $observed = ['schedule' => $answer($complete($poll(), $commands))];
        $activity = null;
        for ($attempt = 0; $attempt < 10 && $activity === null; $attempt++) {
            $activity = $raw('POST', 'worker/activity-tasks/poll', ['worker_id' => $worker, 'task_queue' => $queue,
                'timeout_seconds' => 1])['response']['task'] ?? null;
        }
        if (! is_array($activity)) {
            throw new RuntimeException("Parallel cancellation {$case}: no activity task");
        }
        $requested = $raw('POST', 'workflows/'.rawurlencode($id).'/request-cancellation',
            ['reason' => $definition['reason'], 'cleanup_timeout_seconds' => 30]);
        $observed['request'] = ['status' => $requested['status'], 'reason' => $requested['response']['reason'] ?? null];
        $root = $poll();
        $deliver = static fn (array $boundary): array => $raw('POST', 'worker/workflow-tasks/'.$root['task_id'].'/deliver-cancellation', [
            'lease_owner' => $root['lease_owner'], 'workflow_task_attempt' => $root['workflow_task_attempt'],
            'request_id' => $requested['response']['cancellation_request']['request_id'] ?? null, ...$boundary]);
        if (isset($plan['refused_boundary'])) {
            $observed['refused_delivery'] = $delivery($deliver($plan['refused_boundary']));
        }
        $observed['delivery'] = $delivery($deliver($plan['boundary']));
        $observed['completion'] = $answer($complete($root, [['type' => 'complete_workflow', 'result' => $empty]]));
        $heartbeat = $raw('POST', 'worker/activity-tasks/'.$activity['task_id'].'/heartbeat', [
            'activity_attempt_id' => $activity['activity_attempt_id'], 'lease_owner' => $activity['lease_owner']]);
        $observed['activity_heartbeat'] = ['status' => $heartbeat['status'],
            'cancel_requested' => $heartbeat['response']['cancel_requested'] ?? null,
            'reason' => $heartbeat['response']['reason'] ?? null];
        $events = $raw('GET', 'workflows/'.rawurlencode($id).'/runs/'.$started['response']['run_id'].'/history')['response']['events'] ?? [];
        $observed['events'] = [];
        foreach ($events as $event) {
            if (in_array($event['event_type'], ['StartAccepted', 'WorkflowStarted'], true)) {
                continue;
            }
            $projected = ['event_type' => $event['event_type']];
            foreach ($definition['event_fields'] as $field) {
                if (array_key_exists($field, $event['payload'])) {
                    $projected[$field] = $event['payload'][$field];
                }
            }
            $observed['events'][] = $projected;
        }
        $state[$case] = $observed;
    }

    return $state;
}
