#!/usr/bin/env php
<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\PollResponse;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\WorkflowCommand;
use DurableWorkflow\Worker\WorkflowContext;
use DurableWorkflow\WorkflowHandle;

const CAPACITY_WORKFLOW_TYPES = [
    'simple-start-complete' => 'capacity.v1.simple',
    'one-activity' => 'capacity.v1.one_activity',
    'multiple-activities' => 'capacity.v1.multiple_activities',
    'timer' => 'capacity.v1.timer',
    'signal' => 'capacity.v1.signal',
    'child-workflow-fanout' => 'capacity.v1.child_parent',
    'replay-heavy-history' => 'capacity.v1.replay_heavy',
    'query-inspection' => 'capacity.v1.queryable_counter',
    'mixed' => 'capacity.v1.mixed_selector',
];

function capacityAdapterDescriptor(): array
{
    $path = __DIR__.'/adapter.json';
    $descriptor = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($descriptor)) {
        throw new RuntimeException("{$path} must contain an object.");
    }

    return $descriptor;
}

function capacityAutoload(): void
{
    foreach ([__DIR__.'/vendor/autoload.php', dirname(__DIR__, 5).'/vendor/autoload.php'] as $candidate) {
        if (is_file($candidate)) {
            require $candidate;

            return;
        }
    }

    throw new RuntimeException('Install the exact Composer artifacts before running the PHP adapter.');
}

function capacityEnvironment(string $name, ?string $fallback = null): ?string
{
    $value = getenv($name);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    return $fallback;
}

function capacityClient(bool $worker): Client
{
    $runtimeUrl = capacityEnvironment('DURABLE_WORKFLOW_RUNTIME_URL');
    if ($runtimeUrl === null) {
        throw new RuntimeException('Set DURABLE_WORKFLOW_RUNTIME_URL.');
    }
    $sharedToken = capacityEnvironment('DURABLE_WORKFLOW_TOKEN');

    return new Client(
        $runtimeUrl,
        namespace: capacityEnvironment('DURABLE_WORKFLOW_NAMESPACE', 'default') ?? 'default',
        token: $sharedToken,
        controlToken: $sharedToken === null && ! $worker
            ? capacityEnvironment('DURABLE_WORKFLOW_CLIENT_TOKEN')
            : null,
        workerToken: $sharedToken === null && $worker
            ? capacityEnvironment('DURABLE_WORKFLOW_WORKER_TOKEN')
            : null,
    );
}

/** @return array<string, mixed> */
function capacityRequest(mixed $value): array
{
    return is_array($value) ? $value : [];
}

function capacityBlob(array $request): string
{
    return is_string($request['blob'] ?? null) ? $request['blob'] : '';
}

function capacityResultBlob(array $request): string
{
    return is_string($request['result_blob'] ?? null)
        ? $request['result_blob']
        : capacityBlob($request);
}

function capacityHashWorkflow(WorkflowContext $context, array $request): Generator
{
    $digest = capacityBlob($request);
    for ($index = 0; $index < 5; $index++) {
        $digest = yield $context->activity('capacity.v1.hash', [$digest]);
    }

    return $digest;
}

function capacitySignalWorkflow(WorkflowContext $context): Generator
{
    while (true) {
        $signals = $context->signals('capacity.v1.append');
        if (count($signals) >= 4) {
            break;
        }
        yield new WorkflowCommand(
            'open_signal_wait',
            'signal_wait',
            ['signal_name' => 'capacity.v1.append'],
        );
    }

    $sequences = array_map(
        static fn (array $arguments): int => (int) ($arguments[0] ?? -1),
        array_slice($signals, 0, 4),
    );
    if ($sequences !== [0, 1, 2, 3]) {
        throw new RuntimeException('capacity.v1.append signals must retain sequence 0 through 3.');
    }

    return count($signals);
}

function capacityReplayWorkflow(WorkflowContext $context): Generator
{
    for ($index = 0; $index < 500; $index++) {
        yield $context->sideEffect(static fn (): int => $index);
    }

    return 500;
}

function capacityQueryWorkflow(WorkflowContext $context): Generator
{
    while ($context->signals('capacity.v1.finish') === []) {
        yield new WorkflowCommand(
            'open_signal_wait',
            'signal_wait',
            ['signal_name' => 'capacity.v1.finish'],
        );
    }

    return 0;
}

function capacityMixedWorkflow(WorkflowContext $context, array $request): Generator
{
    $shape = is_string($request['shape'] ?? null) ? $request['shape'] : '';

    return match ($shape) {
        'simple-start-complete' => capacityResultBlob($request),
        'one-activity' => yield $context->activity('capacity.v1.echo', [capacityBlob($request)]),
        'multiple-activities' => yield from capacityHashWorkflow($context, $request),
        'timer' => yield $context->sleep(1),
        'signal' => yield from capacitySignalWorkflow($context),
        'child-workflow-fanout' => yield $context->childWorkflow('capacity.v1.child_parent', [$request], [
            'queue' => is_string($request['task_queue'] ?? null) ? $request['task_queue'] : null,
        ]),
        'replay-heavy-history' => yield from capacityReplayWorkflow($context),
        'query-inspection' => yield from capacityQueryWorkflow($context),
        default => throw new RuntimeException("Unsupported mixed workload shape: {$shape}"),
    };
}

function capacityConfiguredWorker(): Worker
{
    $taskQueue = capacityEnvironment('DURABLE_WORKFLOW_TASK_QUEUE');
    if ($taskQueue === null) {
        throw new RuntimeException('Set DURABLE_WORKFLOW_TASK_QUEUE.');
    }

    $worker = Worker::create(capacityClient(true), $taskQueue)
        ->registerWorkflow(
            'capacity.v1.simple',
            static fn (WorkflowContext $context, array $request): string => capacityResultBlob($request),
        )
        ->registerWorkflow(
            'capacity.v1.one_activity',
            static function (WorkflowContext $context, array $request): Generator {
                return yield $context->activity('capacity.v1.echo', [capacityBlob($request)]);
            },
        )
        ->registerWorkflow('capacity.v1.multiple_activities', capacityHashWorkflow(...))
        ->registerWorkflow(
            'capacity.v1.timer',
            static function (WorkflowContext $context): Generator {
                yield $context->sleep(1);

                return 'capacity.timer';
            },
        )
        ->registerWorkflow('capacity.v1.signal', capacitySignalWorkflow(...))
        ->declareSignal(
            'capacity.v1.signal',
            'capacity.v1.append',
            static fn (int $sequence, string $payload): int => $sequence,
        );

    $worker
        ->registerWorkflow('capacity.v1.replay_heavy', capacityReplayWorkflow(...))
        ->registerWorkflow('capacity.v1.queryable_counter', capacityQueryWorkflow(...))
        ->declareSignal('capacity.v1.queryable_counter', 'capacity.v1.finish')
        ->registerQuery(
            'capacity.v1.queryable_counter',
            'capacity.v1.inspect_counter',
            static fn (QueryContext $context): int => count($context->events('CounterIncremented')),
        )
        ->registerWorkflow('capacity.v1.mixed_selector', capacityMixedWorkflow(...))
        ->declareSignal(
            'capacity.v1.mixed_selector',
            'capacity.v1.append',
            static fn (int $sequence, string $payload): int => $sequence,
        )
        ->declareSignal('capacity.v1.mixed_selector', 'capacity.v1.finish')
        ->registerQuery(
            'capacity.v1.mixed_selector',
            'capacity.v1.inspect_counter',
            static fn (QueryContext $context): int => count($context->events('CounterIncremented')),
        )
        ->registerActivity(
            'capacity.v1.echo',
            static fn (ActivityContext $context, string $payload): string => $payload,
        )
        ->registerActivity(
            'capacity.v1.hash',
            static fn (ActivityContext $context, string $payload): string => hash('sha256', $payload),
        );

    return $worker;
}

/** @return list<array<string, mixed>> */
function capacityTaskHistory(Client $client, array $task, string $leaseOwner, int $attempt): array
{
    $raw = $task['history_events'] ?? $task['history'] ?? [];
    $history = [];
    if (is_array($raw)) {
        foreach ($raw as $event) {
            if (is_array($event)) {
                $history[] = $event;
            }
        }
    }
    $next = is_string($task['next_history_page_token'] ?? null)
        ? $task['next_history_page_token']
        : '';
    while ($next !== '') {
        $page = $client->workflowTaskHistory(
            (string) $task['task_id'],
            $leaseOwner,
            $attempt,
            $next,
        );
        foreach (($page['history_events'] ?? []) as $event) {
            if (is_array($event)) {
                $history[] = $event;
            }
        }
        $newNext = is_string($page['next_history_page_token'] ?? null)
            ? $page['next_history_page_token']
            : '';
        if ($newNext === $next) {
            throw new RuntimeException('Workflow history pagination repeated a page token.');
        }
        $next = $newNext;
    }

    return $history;
}

/** @param list<array<string, mixed>> $history */
function capacityChildCompletionCount(array $history): int
{
    return count(array_filter(
        $history,
        static fn (array $event): bool => ($event['event_type'] ?? $event['type'] ?? null) === 'ChildRunCompleted',
    ));
}

/** @return list<array<string, mixed>> */
function capacityFanoutCommands(Client $client, string $taskQueue): array
{
    $commands = [];
    for ($index = 0; $index < 10; $index++) {
        $commands[] = WorkflowCommand::childWorkflow(
            'capacity.v1.child_leaf',
            [$index],
            ['queue' => $taskQueue],
        )->toWire($client->payloadCodec(), $taskQueue);
    }

    return $commands;
}

/** @return list<mixed> */
function capacityTaskArguments(Client $client, array $task): array
{
    $raw = $task['arguments'] ?? $task['input'] ?? null;
    if ($raw === null) {
        return [];
    }
    $decoded = is_array($raw) || is_string($raw)
        ? $client->payloadCodec()->decodeEnvelope($raw)
        : $raw;

    return is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
}

function capacityFanoutWorker(): void
{
    $taskQueue = capacityEnvironment('DURABLE_WORKFLOW_TASK_QUEUE');
    if ($taskQueue === null) {
        throw new RuntimeException('Set DURABLE_WORKFLOW_TASK_QUEUE.');
    }
    $client = capacityClient(true);
    $workerId = 'php-capacity-fanout-'.bin2hex(random_bytes(8));
    $running = true;
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use (&$running): void {
            $running = false;
        });
        pcntl_signal(SIGINT, static function () use (&$running): void {
            $running = false;
        });
    }
    $client->registerWorker(
        $workerId,
        $taskQueue,
        ['capacity.v1.child_parent', 'capacity.v1.child_leaf'],
        [],
        ['durable_history_replay', 'graceful_shutdown'],
        maxConcurrentWorkflowTasks: 1,
        maxConcurrentActivityTasks: 0,
    );
    $lastHeartbeat = microtime(true);
    try {
        while ($running) {
            if (microtime(true) - $lastHeartbeat >= 15) {
                $client->heartbeatWorker($workerId, ['workflow' => 1, 'activity' => 0]);
                $lastHeartbeat = microtime(true);
            }
            $response = $client->pollWorkflowTaskResponse($workerId, $taskQueue, 1);
            if (PollResponse::isTerminal($response)) {
                break;
            }
            $task = is_array($response['task'] ?? null) ? $response['task'] : null;
            if ($task === null) {
                continue;
            }
            $taskId = (string) ($task['task_id'] ?? '');
            $attempt = (int) ($task['workflow_task_attempt'] ?? 1);
            $leaseOwner = (string) ($task['lease_owner'] ?? $workerId);
            try {
                $history = capacityTaskHistory($client, $task, $leaseOwner, $attempt);
                $workflowType = (string) ($task['workflow_type'] ?? '');
                if ($workflowType === 'capacity.v1.child_leaf') {
                    $arguments = capacityTaskArguments($client, $task);
                    $commands = [[
                        'type' => 'complete_workflow',
                        'result' => $client->payloadCodec()->envelope((int) ($arguments[0] ?? 0)),
                    ]];
                } elseif ($workflowType === 'capacity.v1.child_parent') {
                    $scheduled = count(array_filter(
                        $history,
                        static fn (array $event): bool => ($event['event_type'] ?? $event['type'] ?? null) === 'ChildWorkflowScheduled',
                    ));
                    if ($scheduled === 0) {
                        $commands = capacityFanoutCommands($client, $taskQueue);
                    } elseif (capacityChildCompletionCount($history) >= 10) {
                        $commands = [[
                            'type' => 'complete_workflow',
                            'result' => $client->payloadCodec()->envelope(45),
                        ]];
                    } else {
                        // Acknowledge this task without blocking its one declared
                        // slot; each remaining child completion schedules another.
                        $commands = [];
                    }
                } else {
                    throw new RuntimeException("Unsupported fanout workflow type: {$workflowType}");
                }
                $client->completeWorkflowTask($taskId, $leaseOwner, $attempt, $commands);
            } catch (Throwable $error) {
                $client->failWorkflowTask(
                    $taskId,
                    $leaseOwner,
                    $attempt,
                    'Capacity fanout worker failed: '.$error->getMessage(),
                    $error::class,
                );
            }
        }
    } finally {
        $client->deregisterWorkerRegistration($workerId);
    }
}

function capacityWorker(): void
{
    $cell = capacityEnvironment('DURABLE_WORKFLOW_CAPACITY_CELL', '') ?? '';
    $processIndex = (int) (capacityEnvironment('DURABLE_WORKFLOW_WORKER_PROCESS_INDEX', '0') ?? '0');
    $workerConcurrency = (int) (capacityEnvironment('DURABLE_WORKFLOW_WORKER_CONCURRENCY', '1') ?? '1');
    $mixedFanoutProcesses = max(1, intdiv($workerConcurrency, 2));
    if ($cell === 'child-workflow-fanout' || ($cell === 'mixed' && $processIndex < $mixedFanoutProcesses)) {
        capacityFanoutWorker();

        return;
    }

    capacityConfiguredWorker()->run();
}

/**
 * @param  array<string, mixed>  $command
 * @param  array<string, WorkflowHandle>  $handles
 */
function capacityClientCommand(Client $client, array $command, array &$handles): array
{
    $operation = is_string($command['operation'] ?? null) ? $command['operation'] : '';
    $workflowId = is_string($command['workflow_id'] ?? null) ? $command['workflow_id'] : '';
    if ($workflowId === '') {
        throw new InvalidArgumentException('Every client operation requires workflow_id.');
    }
    $started = hrtime(true);
    $result = null;
    $runId = null;

    if ($operation === 'start') {
        $cellId = is_string($command['cell_id'] ?? null) ? $command['cell_id'] : '';
        $workflowType = CAPACITY_WORKFLOW_TYPES[$cellId] ?? null;
        $taskQueue = is_string($command['task_queue'] ?? null) ? $command['task_queue'] : '';
        if ($workflowType === null || $taskQueue === '') {
            throw new InvalidArgumentException('start requires a declared cell_id and task_queue.');
        }
        $payload = capacityRequest($command['payload'] ?? null);
        $payload['task_queue'] = $taskQueue;
        $handle = $client->startWorkflow($workflowType, $workflowId, $taskQueue, [$payload]);
        $handles[$workflowId] = $handle;
        $runId = $handle->selectedRunId;
    } elseif ($operation === 'signal') {
        $name = is_string($command['name'] ?? null) ? $command['name'] : '';
        $arguments = is_array($command['arguments'] ?? null) ? array_values($command['arguments']) : [];
        $client->workflowHandle($workflowId)->signal($name, $arguments);
    } elseif ($operation === 'query') {
        $name = is_string($command['name'] ?? null) ? $command['name'] : '';
        $arguments = is_array($command['arguments'] ?? null) ? array_values($command['arguments']) : [];
        $result = $client->workflowHandle($workflowId)->query($name, $arguments);
    } elseif ($operation === 'result') {
        $timeout = is_numeric($command['timeout_seconds'] ?? null) ? (int) $command['timeout_seconds'] : 300;
        $result = ($handles[$workflowId] ?? $client->workflowHandle($workflowId))->result($timeout, 1);
    } else {
        throw new InvalidArgumentException("Unsupported client operation: {$operation}");
    }

    return [
        'ok' => true,
        'operation' => $operation,
        'workflow_id' => $workflowId,
        'run_id' => $runId,
        'elapsed_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
        'result' => $result,
    ];
}

function capacityClientLoop(): void
{
    $client = capacityClient(false);
    $handles = [];
    while (($line = fgets(STDIN)) !== false) {
        try {
            $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($command)) {
                throw new InvalidArgumentException('Each client command must be a JSON object.');
            }
            $response = capacityClientCommand($client, $command, $handles);
        } catch (Throwable $error) {
            $response = [
                'ok' => false,
                'error_type' => $error::class,
                'error' => $error->getMessage(),
            ];
        }
        echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
        flush();
    }
}

$mode = $argv[1] ?? '';
if ($mode === 'describe') {
    echo json_encode(capacityAdapterDescriptor(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

capacityAutoload();
if ($mode === 'worker') {
    capacityWorker();
} elseif ($mode === 'client') {
    capacityClientLoop();
} elseif ($mode === 'check') {
    capacityConfiguredWorker()->contracts();
    $fanout = capacityFanoutCommands(capacityClient(false), 'capacity-check');
    $fanoutArguments = array_map(
        static fn (array $command): string => json_encode($command['arguments'] ?? null, JSON_THROW_ON_ERROR),
        $fanout,
    );
    if (count($fanout) !== 10
        || count(array_unique($fanoutArguments)) !== 10
        || array_filter($fanout, static fn (array $command): bool => ($command['type'] ?? null) !== 'start_child_workflow') !== []
    ) {
        throw new RuntimeException('Fanout must emit ten distinct child starts in one workflow task.');
    }
    echo 'capacity PHP adapter definitions are valid'.PHP_EOL;
} else {
    fwrite(STDERR, "usage: capacity_adapter.php describe|check|worker|client\n");
    exit(2);
}
