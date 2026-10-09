<?php

declare(strict_types=1);
use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Exception\ServerException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use ServerParity\EchoActivity;
use ServerParity\EchoWorkflow;
use ServerParity\OneActivityWorkflow;
use ServerParity\TimerWorkflow;
use ServerParity\SignalsWorkflow;
use ServerParity\QueriesWorkflow;
use ServerParity\UpdatesWorkflow;
use ServerParity\TwoChildrenWorkflow;
use ServerParity\ChildActivityWorkflow;
use ServerParity\ActivityRetryWorkflow;
use ServerParity\RetryActivity;
use Workflow\V2\CommandContext;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

// Both adapters return observations; contract assertions live in the shared
// runner. This probe never bootstraps or migrates a target database.
$options = getopt('', ['mode:', 'fixture:', 'workflow-id:', 'application-root:', 'url:', 'run-id:']);
$mode = $options['mode'] ?? '';
$fixture = json_decode(file_get_contents($options['fixture']), true, flags: JSON_THROW_ON_ERROR);
$workflowId = $options['workflow-id'];
$namespace = 'default';
$queue = 'server-parity-v1';
$sdkAutoload = getenv('DW_PARITY_SDK_AUTOLOAD') ?: __DIR__.'/vendor/autoload.php';
if (! is_file($sdkAutoload)) {
    fwrite(STDERR, "Install the locked parity SDK adapter first.\n");
    exit(2);
}
require $sdkAutoload;

// Describe already decoded values; the official Avro codec owns wire parsing.
// Decimal strings retain the complete int64 value across the JSON recorder.
function typedValue(mixed $value): array
{
    if (is_array($value)) {
        return ['type' => array_is_list($value) ? 'list' : 'map', 'value' => array_map(typedValue(...), $value)];
    }

    return match (get_debug_type($value)) {
        'int' => ['type' => 'int64', 'value' => (string) $value],
        'float' => ['type' => 'double', 'value' => $value],
        'bool' => ['type' => 'boolean', 'value' => $value],
        'string' => ['type' => 'string', 'value' => $value],
        'null' => ['type' => 'null', 'value' => null],
        default => throw new RuntimeException('No parity type recorder for '.get_debug_type($value)),
    };
}

function decodeHistory(array $events, callable $decode): array
{
    return array_map(static function (array $event) use ($decode): array {
        $payload = $event['payload'];
        $decoded = [];
        foreach (['output', 'result', 'arguments', 'value'] as $key) {
            if (array_key_exists($key, $payload)) {
                $decoded[$key] = $decode($payload[$key]);
            }
        }
        if (isset($payload['activity']['arguments'])) {
            $decoded['activity_arguments'] = $decode($payload['activity']['arguments']);
        }
        $event['decoded'] = $decoded;
        $event['typed_decoded'] = array_map(typedValue(...), $decoded);

        return $event;
    }, $events);
}

function httpHistory(Client $client, string $workflowId, string $runId): array
{
    $events = [];
    $tokens = [];
    $next = null;
    do {
        $page = $client->workflowHistory($workflowId, $runId, 2, $next);
        array_push($events, ...$page['events']);
        $next = $page['next_page_token'] ?? null;
        if ($next !== null && $next !== '') {
            if (isset($tokens[$next])) {
                throw new RuntimeException('History pagination repeated a token.');
            }
            $tokens[$next] = true;
        }
    } while ($next !== null && $next !== '');

    return $events;
}

function observedChildTransport(array &$polls, array &$completions, array &$activityPolls, array &$activityOutcomes): Psr18Transport
{
    // Observe real published transport I/O. No response or request is changed,
    // and authentication headers are never retained.
    $stack = HandlerStack::create();
    $stack->push(static function (callable $handler) use (&$polls, &$completions, &$activityPolls, &$activityOutcomes): callable {
        return static function (RequestInterface $request, array $options) use ($handler, &$polls, &$completions, &$activityPolls, &$activityOutcomes) {
            return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$polls, &$completions, &$activityPolls, &$activityOutcomes): ResponseInterface {
                $path = $request->getUri()->getPath();
                if ($path === '/api/worker/workflow-tasks/poll') {
                    $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($body['task'] ?? null)) {
                        $polls[] = $body['task'];
                    }
                } elseif (preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $path)) {
                    $completions[] = ['path' => $path,
                        'request' => json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
                        'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
                } elseif ($path === '/api/worker/activity-tasks/poll') {
                    $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($body['task'] ?? null)) {
                        $activityPolls[] = $body['task'];
                    }
                } elseif (preg_match('#^/api/worker/activity-tasks/[^/]+/(?:complete|fail)$#', $path)) {
                    $activityOutcomes[] = ['path' => $path,
                        'request' => json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
                        'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
                }

                return $response;
            });
        };
    });

    return new Psr18Transport(client: new GuzzleClient(['http_errors' => false, 'handler' => $stack]));
}

// A query request waits for a worker response. Execute the unchanged published
// client in an independent PHP process so this probe's worker keeps polling.
if ($mode === 'query') {
    try {
        $client = new Client($options['url'], namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null);
        $result = $client->queryWorkflow($workflowId, $fixture['query_name'], $fixture['query_arguments'], $options['run-id']);
        echo json_encode(['result' => $result, 'typed_result' => typedValue($result)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
        exit(1);
    }
}

function httpObservation(array $fixture, string $workflowId, string $namespace, string $queue, string $url, string $fixturePath): array
{
    $workflowPolls = [];
    $workflowCompletions = [];
    $activityPolls = [];
    $activityOutcomes = [];
    $transport = isset($fixture['child_count']) || isset($fixture['retry_policy'])
        ? observedChildTransport($workflowPolls, $workflowCompletions, $activityPolls, $activityOutcomes) : null;
    $client = new Client($url, namespace: $namespace, transport: $transport, token: getenv('DW_PARITY_TOKEN') ?: null);
    $handle = null;
    $deliveries = [];
    $queries = [];
    $queryTasks = [];
    $updates = [];
    $updateTasks = [];
    $pendingUpdate = null;
    $pendingQuery = null;
    $deadline = microtime(true) + 30;
    $worker = null;
    $worker = (new Worker($client, $queue, clock: static function () use (&$worker, &$handle, &$deliveries, &$queries, &$queryTasks, &$pendingQuery, &$updates, &$updateTasks, &$pendingUpdate, $client, $fixture, $fixturePath, $workflowId, $url, $deadline): float {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Parity worker exceeded its 30-second completion budget.');
        }
        if ($handle !== null && isset($fixture['update_values'])) {
            $history = $client->workflowHistory($workflowId, $handle->selectedRunId, 100)['events'];
            if ($pendingUpdate !== null) {
                $completed = array_filter($history, static fn (array $event): bool => $event['event_type'] === 'UpdateCompleted'
                    && $event['payload']['update_id'] === $pendingUpdate['accepted']['update_id']);
                if ($completed === []) {
                    return microtime(true);
                }
                // This completed retry is still the published SDK call. It must
                // return the original result despite the different arguments.
                $result = $client->updateWorkflow($workflowId, $fixture['update_name'], [$fixture['duplicate_update_value']],
                    requestId: $pendingUpdate['request_id'], runId: $handle->selectedRunId);
                $updates[] = [...$pendingUpdate, 'result' => $result, 'typed_result' => typedValue($result),
                    'history_before_result' => $history,
                    'history_after_result' => $client->workflowHistory($workflowId, $handle->selectedRunId, 100)['events'],
                    'worker' => $updateTasks[count($updates)] ?? null];
                $pendingUpdate = null;
            }
            $index = count($updates);
            if ($index < count($fixture['update_values']) && $handle->describeSelectedRun()->status === 'waiting') {
                $value = $fixture['update_values'][$index];
                $requestId = $workflowId.':update:'.$index;
                $before = $handle->describeSelectedRun()->raw;
                $accepted = $client->updateWorkflow($workflowId, $fixture['update_name'], [$value],
                    waitFor: 'accepted', requestId: $requestId, runId: $handle->selectedRunId);
                $historyAccepted = $client->workflowHistory($workflowId, $handle->selectedRunId, 100)['events'];
                $duplicate = $client->updateWorkflow($workflowId, $fixture['update_name'], [$fixture['duplicate_update_value']],
                    waitFor: 'accepted', requestId: $requestId, runId: $handle->selectedRunId);
                $pendingUpdate = ['name' => $fixture['update_name'], 'request_id' => $requestId,
                    'arguments' => [$value], 'typed_arguments' => typedValue([$value]), 'before' => $before,
                    'accepted' => $accepted, 'duplicate_accepted' => $duplicate,
                    'history_after_acceptance' => $historyAccepted,
                    'history_after_duplicate' => $client->workflowHistory($workflowId, $handle->selectedRunId, 100)['events']];

                return microtime(true);
            }
        }
        if ($handle !== null && isset($fixture['queries'])) {
            if ($pendingQuery !== null) {
                $process = proc_get_status($pendingQuery['process']);
                if ($process['running']) {
                    return microtime(true);
                }
                $pending = $pendingQuery;
                $pendingQuery = null;
                proc_close($pending['process']);
                $result = file_get_contents($pending['stdout']);
                $error = file_get_contents($pending['stderr']);
                unlink($pending['stdout']);
                unlink($pending['stderr']);
                if ($process['exitcode'] !== 0) {
                    throw new RuntimeException('Independent query client failed: '.$error);
                }
                $queries[] = [...$pending['observation'], ...json_decode($result, true, flags: JSON_THROW_ON_ERROR),
                    'after' => $handle->describeSelectedRun()->raw,
                    'history_after' => $client->workflowHistory($workflowId, $handle->selectedRunId, 100)['events'],
                    'worker' => $queryTasks[count($queries)] ?? null];
            }
            $expected = $fixture['queries'][count($queries)] ?? null;
            if ($expected !== null) {
                $execution = $handle->describeSelectedRun();
                $history = $client->workflowHistory($workflowId, $handle->selectedRunId, 100)['events'];
                $applied = array_filter($history, static fn (array $event): bool => $event['event_type'] === 'SignalApplied');
                if ($execution->status === $expected['status'] && count($applied) === $expected['after_signal_count']) {
                    $stdout = tempnam(sys_get_temp_dir(), 'dw-parity-query-');
                    $stderr = tempnam(sys_get_temp_dir(), 'dw-parity-query-');
                    $process = proc_open([PHP_BINARY, __FILE__, '--mode', 'query', '--fixture', $fixturePath,
                        '--workflow-id', $workflowId, '--url', $url, '--run-id', $handle->selectedRunId],
                        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes);
                    if (! is_resource($process)) {
                        unlink($stdout);
                        unlink($stderr);
                        throw new RuntimeException('Unable to start the real query client.');
                    }
                    $pendingQuery = ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr,
                        'observation' => ['name' => $fixture['query_name'], 'arguments' => $fixture['query_arguments'],
                            'typed_arguments' => typedValue($fixture['query_arguments']), 'before' => $execution->raw,
                            'history_before' => $history]];

                    return microtime(true);
                }
            }
        }
        if ($handle !== null && isset($fixture['signal_count']) && count($deliveries) < $fixture['signal_count']
            && count($updates) === count($fixture['update_values'] ?? [])) {
            $page = $client->workflowHistory($workflowId, $handle->selectedRunId, 100);
            $opened = array_filter($page['events'], static fn (array $event): bool => $event['event_type'] === 'ConditionWaitOpened');
            if (count($opened) > count($deliveries)) {
                $before = $handle->describeSelectedRun()->raw;
                $response = $client->signalWorkflow($workflowId, 'payload', [$fixture['signal_value']], $handle->selectedRunId);
                $deliveries[] = ['before' => $before, 'response' => $response];
            }
        }
        if ($worker !== null && $handle !== null && $handle->describeSelectedRun()->isTerminal
            && count($queries) === count($fixture['queries'] ?? [])) {
            $worker->requestShutdown();
        }

        return microtime(true);
    }, diagnosticListener: static function (string $event) use (&$handle, $client, $fixture, $workflowId, $queue): void {
        if ($event === 'worker.registered') {
            $arguments = [$fixture['input']];
            if (isset($fixture['signal_count'])) {
                $arguments[] = $fixture['signal_count'];
            }
            $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $queue, $arguments);
        }
    }))
        ->registerWorkflow('parity.v1.echo', static fn (WorkflowContext $context, array $value): array => $value)
        ->registerWorkflow('parity.v1.two_children', static function (WorkflowContext $context, array $value): array {
            $results = [];
            foreach ($value['payloads'] as $payload) {
                $results[] = $context->childWorkflow('parity.v1.child_activity', [$payload], ['queue' => 'server-parity-v1']);
            }

            return ['child_results' => $results];
        })
        ->registerWorkflow('parity.v1.child_activity', static function (WorkflowContext $context, array $value): array {
            return ['echo' => $context->activity('parity.v1.echo_activity', [$value]), 'source' => 'child-workflow'];
        })
        ->registerWorkflow('parity.v1.one_activity', static fn (WorkflowContext $context, array $value): array => $context->activity('parity.v1.echo_activity', [$value]))
        ->registerWorkflow('parity.v1.activity_retry', static fn (WorkflowContext $context, array $value): array => $context->activity('parity.v1.retry_activity', [$value], ['retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [1], 'non_retryable_error_types' => []]]))
        ->registerWorkflow('parity.v1.one_timer', static function (WorkflowContext $context, array $value): array {
            foreach ($value['delays'] as $delay) {
                $context->sleep($delay);
            }

            return $value;
        })
        ->registerWorkflow('parity.v1.signals', static function (WorkflowContext $context, array $value, int $target): array {
            for ($index = 0; $index < $target; $index++) {
                $context->waitCondition(fn (): bool => count($context->signals('payload')) > $index, 'payload:'.$index);
            }

            return $context->signals('payload')[$target - 1][0];
        })
        ->declareSignal('parity.v1.signals', 'payload', static fn (array $value) => null)
        ->registerWorkflow('parity.v1.queries', static function (WorkflowContext $context, array $value, int $target): array {
            for ($index = 0; $index < $target; $index++) {
                $context->waitCondition(fn (): bool => count($context->signals('payload')) > $index, 'payload:'.$index);
            }

            return $context->signals('payload')[$target - 1][0];
        })
        ->declareSignal('parity.v1.queries', 'payload', static fn (array $value) => null)
        ->registerQuery('parity.v1.queries', 'state', static function (QueryContext $context, array $request) use ($client, &$queryTasks): array {
            $applied = $context->events('SignalApplied');
            $queryTasks[] = ['task' => $context->task, 'arguments' => [$request], 'typed_arguments' => typedValue([$request])];

            return ['request' => $request, 'delivered' => count($applied),
                'last' => $applied === [] ? null : $client->payloadCodec()->decodeEnvelope($applied[array_key_last($applied)]['payload']['value'])];
        })
        ->registerWorkflow('parity.v1.updates', static function (WorkflowContext $context, array $value, int $target): array {
            $context->waitCondition(fn (): bool => count($context->signals('payload')) === $target, 'payload:0');

            return ['values' => array_map(static fn (array $arguments): array => $arguments[0], $context->updates('set_payload'))];
        })
        ->declareSignal('parity.v1.updates', 'payload', static fn (array $value) => null)
        ->registerUpdate('parity.v1.updates', 'set_payload', static function (QueryContext $context, array $value) use (&$updateTasks): array {
            $updateTasks[] = ['task' => $context->task, 'arguments' => [$value], 'typed_arguments' => typedValue([$value])];

            return ['value' => $value, 'applied' => count($context->events('UpdateApplied')) + 1];
        })
        ->registerActivity('parity.v1.echo_activity', static fn (ActivityContext $context, array $value): array => $value)
        ->registerActivity('parity.v1.retry_activity', static function (ActivityContext $context, array $value): array {
            if ($context->attemptNumber === 1) {
                throw new RuntimeException('parity retry λ');
            }

            return ['echo' => $value, 'attempt' => $context->attemptNumber];
        });

    // run() performs real registration and task execution. The clock observes
    // real time and selected-run completion, without replacing runtime I/O.
    try {
        $worker->run(0);
    } finally {
        if ($pendingQuery !== null) {
            proc_terminate($pendingQuery['process'], 9);
            proc_close($pendingQuery['process']);
            unlink($pendingQuery['stdout']);
            unlink($pendingQuery['stderr']);
        }
    }
    $execution = $handle->describeSelectedRun();
    // Small pages prove pagination for parent and original child histories.
    $events = httpHistory($client, $workflowId, $handle->selectedRunId);
    foreach ($workflowCompletions as &$completion) {
        $completion['decoded_commands'] = [];
        foreach ($completion['request']['commands'] as $command) {
            $decoded = [];
            foreach (['arguments', 'result'] as $field) {
                if (array_key_exists($field, $command)) {
                    $decoded[$field] = $client->payloadCodec()->decodeEnvelope($command[$field]);
                }
            }
            $completion['decoded_commands'][] = ['decoded' => $decoded, 'typed_decoded' => array_map(typedValue(...), $decoded)];
        }
    }
    unset($completion);
    $activityDuplicates = null;
    if (isset($fixture['retry_policy'])) {
        $before = httpHistory($client, $workflowId, $handle->selectedRunId);
        $originalOutcomes = $activityOutcomes;
        $receipt = static function (callable $submit): array {
            try {
                return ['status' => 200, 'response' => $submit()];
            } catch (ServerException $error) {
                return ['status' => $error->status, 'response' => $error->details];
            }
        };
        $first = $activityPolls[0];
        $second = $activityPolls[1];
        $failed = $receipt(static fn (): array => $client->failActivityTask($first['task_id'], $first['activity_attempt_id'],
            $first['lease_owner'], $fixture['failure']['message'], $fixture['failure']['type']));
        $afterFailure = httpHistory($client, $workflowId, $handle->selectedRunId);
        $completed = $receipt(static fn (): array => $client->completeActivityTask($second['task_id'], $second['activity_attempt_id'],
            $second['lease_owner'], $fixture['output']));
        $activityDuplicates = ['failure' => $failed, 'completion' => $completed, 'history_before' => $before,
            'history_after_failure' => $afterFailure, 'history_after_completion' => httpHistory($client, $workflowId, $handle->selectedRunId)];
        $activityOutcomes = $originalOutcomes;
    }
    $children = [];
    foreach ($events as $event) {
        if ($event['event_type'] !== 'ChildRunStarted') {
            continue;
        }
        $child = $client->describeWorkflow($event['payload']['child_workflow_instance_id'], $event['payload']['child_workflow_run_id']);
        $children[] = ['execution' => $child->raw, 'workflow_id' => $child->workflowId, 'run_id' => $child->runId,
            'workflow_type' => $child->workflowType, 'namespace' => $child->namespace, 'task_queue' => $child->taskQueue,
            'status' => $child->status, 'payload_codec' => $child->raw['payload_codec'] ?? null, 'input' => $child->input, 'typed_input' => typedValue($child->input),
            'output' => $child->output, 'typed_output' => typedValue($child->output),
            'events' => decodeHistory(httpHistory($client, $child->workflowId, $child->runId), $client->payloadCodec()->decodeEnvelope(...))];
    }

    return [
        'execution' => $execution->raw,
        'workflow_id' => $execution->workflowId,
        'run_id' => $execution->runId,
        'workflow_type' => $execution->workflowType,
        'namespace' => $execution->namespace,
        'task_queue' => $execution->taskQueue,
        'status' => $execution->status,
        'payload_codec' => $execution->raw['payload_codec'] ?? null,
        'input' => $execution->input,
        'output' => $handle->resultOfSelectedRun(1),
        'events' => decodeHistory($events, $client->payloadCodec()->decodeEnvelope(...)),
        'signal_deliveries' => $deliveries,
        'queries' => $queries,
        'updates' => $updates,
        'children' => $children,
        'workflow_polls' => $workflowPolls,
        'workflow_completions' => $workflowCompletions,
        'activity_polls' => $activityPolls,
        'activity_outcomes' => $activityOutcomes,
        'activity_duplicate_receipts' => $activityDuplicates,
    ];
}

function embeddedObservation(array $fixture, string $workflowId, string $namespace, string $queue, string $root): array
{
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    require __DIR__.'/embedded-types.php';
    config([
        'queue.default' => 'database',
        'workflows.v2.task_dispatch_mode' => 'queue',
        'workflows.v2.types.workflows' => [
            'parity.v1.echo' => EchoWorkflow::class,
            'parity.v1.one_activity' => OneActivityWorkflow::class,
            'parity.v1.one_timer' => TimerWorkflow::class,
            'parity.v1.signals' => SignalsWorkflow::class,
            'parity.v1.queries' => QueriesWorkflow::class,
            'parity.v1.updates' => UpdatesWorkflow::class,
            'parity.v1.two_children' => TwoChildrenWorkflow::class,
            'parity.v1.child_activity' => ChildActivityWorkflow::class,
            'parity.v1.activity_retry' => ActivityRetryWorkflow::class,
        ],
        'workflows.v2.types.activities' => ['parity.v1.echo_activity' => EchoActivity::class, 'parity.v1.retry_activity' => RetryActivity::class],
    ]);
    // Namespace belongs to the host Laravel application in embedded mode.
    foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class] as $model) {
        $model::creating(static function ($row) use ($namespace): void {
            $row->namespace = $namespace;
        });
    }
    $class = isset($fixture['update_values']) ? UpdatesWorkflow::class : (isset($fixture['queries']) ? QueriesWorkflow::class : (isset($fixture['signal_count']) ? SignalsWorkflow::class : (isset($fixture['timer_delays']) ? TimerWorkflow::class
        : ($fixture['activity'] ? OneActivityWorkflow::class : EchoWorkflow::class))));
    if (isset($fixture['child_count'])) {
        $class = TwoChildrenWorkflow::class;
    }
    if (isset($fixture['retry_policy'])) {
        $class = ActivityRetryWorkflow::class;
    }
    $stub = WorkflowStub::make($class, $workflowId);
    $arguments = [$fixture['input']];
    if (isset($fixture['signal_count'])) {
        $arguments[] = $fixture['signal_count'];
    }
    $stub->start(
        ...[...$arguments,
        new WorkflowOptions(connection: 'database', queue: $queue),
        new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600)],
    );
    $deliveries = [];
    $queries = [];
    $updates = [];
    $pendingUpdate = null;
    $deadline = microtime(true) + 30;
    do {
        Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
            '--max-time' => 30, '--sleep' => 0, '--tries' => 1,
        ]);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $history = static fn (): array => $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
            'sequence' => $event->sequence, 'event_type' => $event->event_type->value, 'payload' => $event->payload,
        ])->all();
        if (isset($fixture['update_values'])) {
            if ($pendingUpdate !== null) {
                $completed = $stub->inspectUpdate($pendingUpdate['accepted']['update_id']);
                if (! $completed->completed()) {
                    throw new RuntimeException('Embedded update did not complete through its real queue job.');
                }
                $historyBefore = $history();
                $duplicate = $stub->withCommandContext(CommandContext::phpApi()->with(['request' => ['request_id' => $pendingUpdate['request_id']]]))
                    ->submitUpdateWithArguments($fixture['update_name'], [$fixture['duplicate_update_value']]);
                $result = $stub->inspectUpdate($duplicate->updateId())->result();
                $updates[] = [...$pendingUpdate, 'result' => $result, 'typed_result' => typedValue($result),
                    'history_before_result' => $historyBefore, 'history_after_result' => $history()];
                $pendingUpdate = null;
            }
            $index = count($updates);
            if ($index < count($fixture['update_values']) && $run->status->value === 'waiting') {
                $value = $fixture['update_values'][$index];
                $requestId = $workflowId.':update:'.$index;
                $target = $stub->withCommandContext(CommandContext::phpApi()->with(['request' => ['request_id' => $requestId]]));
                $before = $run->toArray();
                $receipt = static fn ($update): array => ['accepted' => $update->accepted(), 'command_id' => $update->commandId(),
                    'workflow_id' => $update->workflowId(), 'run_id' => $update->runId(), 'update_id' => $update->updateId(),
                    'update_name' => $update->updateName(), 'update_status' => $update->updateStatus(), 'outcome' => $update->outcome()];
                $accepted = $receipt($target->submitUpdateWithArguments($fixture['update_name'], [$value]));
                $historyAccepted = $history();
                $duplicate = $receipt($target->submitUpdateWithArguments($fixture['update_name'], [$fixture['duplicate_update_value']]));
                $pendingUpdate = ['name' => $fixture['update_name'], 'request_id' => $requestId,
                    'arguments' => [$value], 'typed_arguments' => typedValue([$value]), 'before' => $before,
                    'accepted' => $accepted, 'duplicate_accepted' => $duplicate,
                    'history_after_acceptance' => $historyAccepted, 'history_after_duplicate' => $history()];
            }
        }
        $expected = $fixture['queries'][count($queries)] ?? null;
        if ($expected !== null && $run->status->value === $expected['status']
            && $run->historyEvents()->where('event_type', 'SignalApplied')->count() === $expected['after_signal_count']) {
            $history = static fn (): array => $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
                'sequence' => $event->sequence, 'event_type' => $event->event_type->value, 'payload' => $event->payload,
            ])->all();
            $before = $run->toArray();
            $historyBefore = $history();
            $result = $stub->queryWithArguments($fixture['query_name'], $fixture['query_arguments']);
            $queries[] = ['name' => $fixture['query_name'], 'arguments' => $fixture['query_arguments'],
                'typed_arguments' => typedValue($fixture['query_arguments']), 'before' => $before,
                'after' => $run->fresh()->toArray(), 'history_before' => $historyBefore, 'history_after' => $history(),
                'result' => $result, 'typed_result' => typedValue($result)];
        }
        if (isset($fixture['signal_count']) && count($deliveries) < $fixture['signal_count']
            && count($updates) === count($fixture['update_values'] ?? [])) {
            $opened = $run->historyEvents()->where('event_type', 'SignalWaitOpened')->count();
            if ($opened > count($deliveries)) {
                $before = $run->toArray();
                $command = $stub->attemptSignalWithArguments('payload', [$fixture['signal_value']]);
                $response = ['accepted' => $command->accepted(), 'command_id' => $command->commandId(),
                    'workflow_id' => $command->workflowId(), 'run_id' => $command->runId(), 'outcome' => $command->outcome()];
                $deliveries[] = ['before' => $before, 'response' => $response];
            }
        }
        if ($run->status->value === 'completed') {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Embedded parity workflow exceeded its completion budget.');
        }
        usleep(50000);
    } while (true);
    $run = WorkflowRun::query()->findOrFail($stub->runId());
    $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
        'sequence' => $event->sequence,
        'event_type' => $event->event_type->value,
        'timestamp' => $event->recorded_at->toISOString(),
        'payload' => $event->payload,
    ])->all();
    $activityDuplicates = null;
    if (isset($fixture['retry_policy'])) {
        $before = $history();
        $firstTask = $events[3]['payload']['task']['id'];
        $secondTask = $events[5]['payload']['task']['id'];
        \Workflow\V2\Jobs\RunActivityTask::dispatch($firstTask)->onConnection('database')->onQueue($queue);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true, '--max-time' => 30, '--sleep' => 0, '--tries' => 1]);
        $afterFailure = $history();
        \Workflow\V2\Jobs\RunActivityTask::dispatch($secondTask)->onConnection('database')->onQueue($queue);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true, '--max-time' => 30, '--sleep' => 0, '--tries' => 1]);
        $activityDuplicates = ['redelivered_task_ids' => [$firstTask, $secondTask], 'history_before' => $before,
            'history_after_failure' => $afterFailure, 'history_after_completion' => $history()];
    }
    $children = [];
    foreach ($events as $event) {
        if ($event['event_type'] !== 'ChildRunStarted') {
            continue;
        }
        $child = WorkflowRun::query()->findOrFail($event['payload']['child_workflow_run_id']);
        $childEvents = $child->historyEvents()->orderBy('sequence')->get()->map(static fn ($row): array => [
            'sequence' => $row->sequence, 'event_type' => $row->event_type->value,
            'timestamp' => $row->recorded_at->toISOString(), 'payload' => $row->payload,
        ])->all();
        $children[] = ['execution' => $child->toArray(), 'workflow_id' => $child->workflow_instance_id, 'run_id' => $child->id,
            'workflow_type' => $child->workflow_type, 'namespace' => $child->namespace, 'task_queue' => $child->queue,
            'status' => $child->status->value, 'payload_codec' => $child->payload_codec, 'input' => $child->workflowArguments(), 'typed_input' => typedValue($child->workflowArguments()),
            'output' => $child->workflowOutput(), 'typed_output' => typedValue($child->workflowOutput()),
            'events' => decodeHistory($childEvents, static fn ($value) => Serializer::unserializeWithCodec('avro', is_array($value) ? $value['blob'] : $value))];
    }

    return [
        'execution' => $run->toArray(),
        'worker_output' => Artisan::output(),
        'workflow_id' => $run->workflow_instance_id,
        'run_id' => $run->id,
        'workflow_type' => $run->workflow_type,
        'namespace' => $run->namespace,
        'task_queue' => $run->queue,
        'status' => $run->status->value,
        'payload_codec' => $run->payload_codec,
        'input' => $run->workflowArguments(),
        'output' => $run->workflowOutput(),
        'events' => decodeHistory($events, static fn ($value) => Serializer::unserializeWithCodec('avro', is_array($value) ? $value['blob'] : $value)),
        'signal_deliveries' => $deliveries,
        'queries' => $queries,
        'updates' => $updates,
        'children' => $children,
        'activity_duplicate_receipts' => $activityDuplicates,
    ];
}

try {
    $observation = match ($mode) {
        'http' => httpObservation($fixture, $workflowId, $namespace, $queue, $options['url'], $options['fixture']),
        'embedded' => embeddedObservation($fixture, $workflowId, $namespace, $queue, $options['application-root']),
        default => throw new InvalidArgumentException('Mode must be http or embedded.'),
    };
    $observation['typed_input'] = typedValue($observation['input']);
    $observation['mode'] = $mode;
    $observation['typed_output'] = typedValue($observation['output']);
    $observation['sdk_php'] = ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v');
    $observation['php_version'] = PHP_VERSION;
    if ($mode === 'embedded') {
        $observation['workflow_package'] = ltrim(InstalledVersions::getPrettyVersion('durable-workflow/workflow'), 'v');
    }
    echo json_encode($observation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    exit(1);
}
