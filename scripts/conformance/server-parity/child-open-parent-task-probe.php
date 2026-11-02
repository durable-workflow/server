<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as ChildOpenParentTaskHttp;
use GuzzleHttp\HandlerStack;

// A child's resolution while its parent has a ready workflow task, over raw
// worker HTTP on protocol 1.20 in a fresh namespace (published Workflow
// WorkflowExecutor::dispatchParentResumeTasks). The parent's first task
// starts a child on its own queue and schedules an activity; the activity's
// result leaves the parent a ready resume task; then the child completes.
// Records the parent's description, every workflow task a worker can then
// claim for the parent, and the run's close from the first of them.
//
// The leased case claims that resume task before the child completes, so the
// lease's history lacks the child's result, then completes the lease with a
// long timer (workflow#962: Core 2.15.10 gives the parent a follow-up task).
function finishHttpChildOpenParentTask(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['child_open_parent_task'];
    $namespace = strtolower($workflowId.'-copt');
    $admin->createNamespace($namespace, 'child open parent task λ', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ChildOpenParentTaskHttp(['base_uri' => rtrim($url, '/').'/api/', 'http_errors' => false,
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
    $manifest = array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
        ['supported' => false, 'minimum_protocol_version' => '1.18', 'reason' => 'raw_http']);
    $id = $workflowId.'-copt';
    $queue = $id.'-queue';
    $childQueue = $id.'-child-queue';
    $worker = $id.'-worker';
    $childWorker = $id.'-child-worker';
    $registered = [
        $raw('POST', 'worker/register', ['worker_id' => $worker, 'task_queue' => $queue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['workflow_type']],
            'supported_activity_types' => [$definition['activity_type']], 'capability_manifest' => $manifest]),
        $raw('POST', 'worker/register', ['worker_id' => $childWorker, 'task_queue' => $childQueue, 'runtime' => 'php',
            'sdk_version' => 'raw-http', 'supported_workflow_types' => [$definition['child_type']],
            'supported_activity_types' => [], 'capability_manifest' => $manifest]),
    ];
    $started = $raw('POST', 'workflows', ['workflow_id' => $id, 'workflow_type' => $definition['workflow_type'],
        'task_queue' => $queue, 'input' => $empty]);
    if ($registered[0]['status'] !== 201 || $registered[1]['status'] !== 201 || $started['status'] !== 201) {
        throw new RuntimeException('Child open parent task setup: '.json_encode([$registered, $started]));
    }
    $poll = static function (string $kind, string $workerId, string $taskQueue, int $timeout) use ($raw): ?array {
        $task = $raw('POST', 'worker/'.$kind.'-tasks/poll', ['worker_id' => $workerId, 'task_queue' => $taskQueue,
            'timeout_seconds' => $timeout])['response']['task'] ?? null;

        return is_array($task) ? $task : null;
    };
    $complete = static fn (array $task, array $commands): array => $raw('POST', 'worker/workflow-tasks/'.$task['task_id'].'/complete',
        ['lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'], 'commands' => $commands]);
    $answer = static fn (array $result): array => ['status' => $result['status'],
        'reason' => $result['response']['reason'] ?? null, 'run_status' => $result['response']['run_status'] ?? null];
    $claimable = static function (string $runId) use ($poll, $worker, $queue): array {
        // Every workflow task a worker can now claim for the parent.
        $claimed = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $task = $poll('workflow', $worker, $queue, $attempt === 0 ? 5 : 1);
            if ($task === null) {
                break;
            }
            if (isset($task['workflow_id']) && $task['workflow_id'] !== $runId) {
                throw new RuntimeException('Child open parent task: a task for another run: '.json_encode($task['workflow_id'] ?? null));
            }
            $claimed[] = $task;
        }

        return $claimed;
    };
    $shape = static fn (array $claimed): array => array_map(static fn (array $task): array => [
        'workflow_update_id' => $task['workflow_update_id'] ?? null,
        'replays_child_result' => in_array('ChildRunCompleted', array_map(
            static fn (array $event): string => (string) ($event['event_type'] ?? ''), $task['history_events'] ?? []), true),
    ], $claimed);
    $case = static function (string $runId, bool $leased, bool $start) use ($raw, $poll, $complete, $answer, $claimable, $shape, $definition,
        $empty, $queue, $childQueue, $worker, $childWorker): array {
        $described = static fn (): array => array_intersect_key(
            $raw('GET', 'workflows/'.rawurlencode($runId))['response'], array_flip($definition['described_fields']));
        if ($start && $raw('POST', 'workflows', ['workflow_id' => $runId, 'workflow_type' => $definition['workflow_type'],
            'task_queue' => $queue, 'input' => $empty])['status'] !== 201) {
            throw new RuntimeException('Child open parent task: start '.$runId);
        }
        $first = $poll('workflow', $worker, $queue, 5) ?? throw new RuntimeException('Child open parent task: no first task');
        $opened = $complete($first, [
            ['type' => 'start_child_workflow', 'workflow_type' => $definition['child_type'], 'arguments' => $empty, 'queue' => $childQueue],
            ['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'], 'arguments' => $empty],
        ]);
        $activity = $poll('activity', $worker, $queue, 5) ?? throw new RuntimeException('Child open parent task: no activity');
        $activityDone = $raw('POST', 'worker/activity-tasks/'.$activity['task_id'].'/complete', ['lease_owner' => $activity['lease_owner'],
            'activity_attempt_id' => $activity['activity_attempt_id'], 'result' => $empty]);
        $lease = $leased ? ($poll('workflow', $worker, $queue, 5) ?? throw new RuntimeException('Child open parent task: no resume task')) : null;
        $child = $poll('workflow', $childWorker, $childQueue, 5) ?? throw new RuntimeException('Child open parent task: no child task');
        $childDone = $complete($child, [['type' => 'complete_workflow', 'result' => $empty]]);
        $afterChild = $described();
        // The lease's worker has not seen the child's result; it waits on a
        // long timer instead, as a workflow body awaiting a timer would.
        $leaseDone = $lease === null ? null : $answer($complete($lease, [['type' => 'start_timer', 'delay_seconds' => 3600]]));
        $afterLease = $lease === null ? null : $described();
        $claimed = $claimable($runId);
        $closed = $claimed === [] ? null : $answer($complete($claimed[0], [['type' => 'complete_workflow', 'result' => $empty]]));
        $final = $described();
        if ($final['status'] !== 'completed') {
            $raw('POST', 'workflows/'.rawurlencode($runId).'/terminate', ['reason' => 'child open parent task recorded']);
        }

        return [
            'opened' => $answer($opened),
            'activity_completed' => $activityDone['status'],
            ...($lease === null ? [] : ['lease_replays_child_result' => $shape([$lease])[0]['replays_child_result']]),
            'child_completed' => $answer($childDone),
            'described_after_child' => $afterChild,
            ...($lease === null ? [] : ['lease_completed' => $leaseDone, 'described_after_lease' => $afterLease]),
            'claimable_parent_tasks' => $shape($claimed),
            'closed' => $closed,
            'described_after_close' => $final,
        ];
    };

    return [
        'applicable' => true,
        'ready' => $case($id, false, false),
        ...(isset($definition['expected']['leased']) ? ['leased' => $case($id.'-leased', true, true)] : []),
    ];
}
