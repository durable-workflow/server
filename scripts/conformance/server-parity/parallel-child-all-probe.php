<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ChildWorkflowFailed;
use DurableWorkflow\Worker\Replayer;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as ChildAllHttp;
use GuzzleHttp\HandlerStack;

// Actual published SDK replay and worker HTTP, in isolated queues. Observations
// retain canonical identities/values, never registration tokens or task claims.
function finishHttpParallelChildAll(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['parallel_child_all'];
    $namespace = strtolower($workflowId.'-child-all');
    $admin->createNamespace($namespace, 'flat child all barriers', 30);
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new ChildAllHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);
    $raw = static function (string $method, string $path, ?array $body = null, bool $allowConflict = false) use ($http, $namespace): array {
        $options = ['headers' => array_filter([
            'Authorization' => getenv('DW_PARITY_TOKEN') ? 'Bearer '.getenv('DW_PARITY_TOKEN') : null,
            'Accept' => 'application/json', 'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2', 'X-Durable-Workflow-Protocol-Version' => '1.20',
        ])];
        if ($body !== null) {
            $options['json'] = $body === [] ? (object) [] : $body;
        }
        $response = $http->request($method, $path, $options);
        $value = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() >= 400 && ! ($allowConflict && $response->getStatusCode() === 409)) {
            throw new RuntimeException('Child all '.$method.' '.$path.' HTTP '.$response->getStatusCode()
                .': '.($value['reason'] ?? $value['message'] ?? 'unknown'));
        }

        return $value + ['observed_http_status' => $response->getStatusCode()];
    };
    $codec = $admin->payloadCodec();
    $frame = static fn (mixed $value): array => ['codec' => 'avro', 'blob' => $codec->encode($value)];
    $history = static function (string $id, string $run) use ($raw, $codec): array {
        $events = $raw('GET', '/api/workflows/'.rawurlencode($id).'/runs/'.$run.'/history')['events'] ?? [];
        $events = decodeHistory($events, $codec->decodeEnvelope(...));

        return array_map(static function (array $event): array {
            $event = array_intersect_key($event, array_flip(['event_type', 'sequence', 'payload', 'typed_decoded']));
            // PHP canonical events include a task snapshot with lease authority.
            // The child contract needs call/link/run identities, never that claim.
            unset($event['payload']['task']);

            return $event;
        }, $events);
    };
    $complete = static fn (array $task, array $commands, bool $duplicate = false): array => $raw('POST',
        '/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'], 'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => $commands,
        ], $duplicate);
    $duplicateOutcome = static fn (array $response): array => ['status' => $response['observed_http_status'],
        'recorded' => $response['recorded'] ?? null, 'reason' => $response['reason'] ?? null,
        'message' => $response['message'] ?? null];
    $cases = [];
    foreach ($definition['cases'] as $case => $size) {
        $id = $workflowId.'-'.$case;
        $parentQueue = $id.'-parents';
        $childQueue = $id.'-children';
        $parentWorker = $id.'-parent-worker';
        $childWorker = $id.'-child-worker';
        foreach ([[$parentWorker, $parentQueue, $definition['parent_type']], [$childWorker, $childQueue, $definition['child_type']]] as [$worker, $queue, $type]) {
            $raw('POST', '/api/worker/register', ['worker_id' => $worker, 'task_queue' => $queue,
                'runtime' => 'php', 'supported_workflow_types' => [$type], 'supported_activity_types' => [],
                'capability_manifest' => array_fill_keys(['local_activities', 'worker_sessions', 'sticky_execution'],
                    ['supported' => false, 'minimum_protocol_version' => '1.18']),
            ]);
        }
        $poll = static fn (bool $parent): ?array => $raw('POST', '/api/worker/workflow-tasks/poll', [
            'worker_id' => $parent ? $parentWorker : $childWorker,
            'task_queue' => $parent ? $parentQueue : $childQueue, 'timeout_seconds' => 0,
        ])['task'] ?? null;
        $run = $raw('POST', '/api/workflows', ['workflow_id' => $id, 'workflow_type' => $definition['parent_type'],
            'task_queue' => $parentQueue, 'input' => $frame([$fixture['input']])]);
        $initial = $poll(true) ?? throw new RuntimeException('Child all missing initial parent task.');
        $workflow = static function (WorkflowContext $context, array $value) use ($size, $definition, $childQueue): array {
            try {
                return ['results' => $context->all(array_map(static fn (int $index) => $context->deferChildWorkflow(
                    $definition['child_type'], [['index' => $index, 'value' => $value]], ['queue' => $childQueue],
                ), range(0, $size - 1)))];
            } catch (ChildWorkflowFailed $error) {
                return ['failure_class' => $error::class, 'failure_type' => $error->failureType,
                    'workflow_type' => $error->workflowType, 'message' => $error->getMessage()];
            }
        };
        $replay = static fn (array $task, callable $handler): array => (new Replayer($codec))
            ->replay($handler, $task['history_events'], $codec->decodeEnvelope($task['arguments']), $task['task_queue'])
            ->commands;
        $commands = $replay($initial, $workflow);
        $admitted = $complete($initial, $commands);
        $prefix = $history($id, $run['run_id']);
        $duplicate = $complete($initial, $commands, true);
        if ($history($id, $run['run_id']) !== $prefix) {
            throw new RuntimeException('Child all duplicate parent completion changed history.');
        }
        $scheduled = array_values(array_filter($prefix, static fn (array $event): bool => $event['event_type'] === 'ChildWorkflowScheduled'));
        if (count($scheduled) !== $size) {
            throw new RuntimeException('Child all did not atomically start every child.');
        }
        $tasks = [];
        for ($index = 0; $index < $size; $index++) {
            $task = $poll(false) ?? throw new RuntimeException('Child all missing child task.');
            $position = array_search($task['run_id'], array_column(array_column($scheduled, 'payload'), 'child_workflow_run_id'), true);
            if ($position === false || isset($tasks[$position])) {
                throw new RuntimeException('Child all claimed an unknown or duplicate child.');
            }
            $tasks[$position] = $task;
        }
        ksort($tasks);
        $state = ['size' => $size, 'workflow_id' => $id, 'run_id' => $run['run_id'],
            'initial_recorded' => $admitted['recorded'], 'duplicate_start_outcome' => $duplicateOutcome($duplicate),
            'initial_no_parent_task' => $poll(true) === null,
            'initial_commands' => array_map(static function (array $command) use ($codec): array {
                $value = $command;
                $value['typed_arguments'] = typedValue($codec->decodeEnvelope($command['arguments']));
                unset($value['arguments']);

                return $value;
            }, $commands), 'prefix' => $prefix, 'children' => [], 'empty_before_last' => []];
        $success = in_array($case, ['single', 'out_of_order'], true);
        $order = $success && $size === 3 ? [2, 0, 1] : range(0, $size - 1);
        $state['completion_order'] = $order;
        foreach ($order as $offset => $index) {
            $task = $tasks[$index];
            $childWorkflow = static fn (WorkflowContext $context, array $value): array => ['echo' => $value];
            $childCommands = $replay($task, $childWorkflow);
            if (! $success && $offset === 0) {
                if ($case === 'failed') {
                    $childCommands = [['type' => 'fail_workflow', 'message' => 'child barrier failure λ',
                        'exception_type' => 'ChildAllProbeFailure', 'exception_class' => 'ChildAllProbeFailure']];
                    $complete($task, $childCommands);
                } else {
                    $raw('POST', '/api/workflows/'.$task['workflow_id'].'/'.($case === 'cancelled' ? 'cancel' : 'terminate'),
                        ['reason' => 'child barrier closed λ']);
                }
            } else {
                $complete($task, $childCommands);
                $state['duplicate_child_outcomes'][] = $duplicateOutcome($complete($task, $childCommands, true));
            }
            $state['children'][$index] = ['workflow_id' => $task['workflow_id'], 'run_id' => $task['run_id'],
                'typed_arguments' => typedValue($codec->decodeEnvelope($task['arguments'])),
                'events' => $history($task['workflow_id'], $task['run_id'])];
            if ($success && $offset < $size - 1) {
                $state['empty_before_last'][] = $poll(true) === null;
            }
            if (($success && $offset === $size - 1) || (! $success && $offset === 0)) {
                $resumed = $poll(true) ?? throw new RuntimeException('Child all missing eligible parent resume.');
                $parentCommands = $replay($resumed, $workflow);
                if (count($parentCommands) !== 1 || $parentCommands[0]['type'] !== 'complete_workflow') {
                    throw new RuntimeException('Child all replay did not complete with committed results or typed failure.');
                }
                $state['typed_result'] = typedValue($codec->decodeEnvelope($parentCommands[0]['result']));
                $complete($resumed, $parentCommands);
                $state['duplicate_finish_outcome'] = $duplicateOutcome($complete($resumed, $parentCommands, true));
                $state['at_parent_close'] = $history($id, $run['run_id']);
            }
        }
        ksort($state['children']);
        $state['children'] = array_values($state['children']);
        $state['events'] = $history($id, $run['run_id']);
        $state['no_extra_parent_task'] = $poll(true) === null;
        $cases[$case] = $state;
    }

    return ['applicable' => true, 'cases' => $cases];
}
