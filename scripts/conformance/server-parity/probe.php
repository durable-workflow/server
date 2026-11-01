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
use ServerParity\SdkTimerWorkflow;
use ServerParity\SignalsWorkflow;
use ServerParity\SdkQueriesWorkflow;
use ServerParity\SdkSignalsWorkflow;
use ServerParity\QueriesWorkflow;
use ServerParity\UpdatesWorkflow;
use ServerParity\SdkUpdatesWorkflow;
use ServerParity\TwoChildrenWorkflow;
use ServerParity\OneChildWorkflow;
use ServerParity\ChildEchoWorkflow;
use ServerParity\ChildActivityWorkflow;
use ServerParity\CancelledChildParentWorkflow;
use ServerParity\CancelledChildWorkflow;
use ServerParity\ActivityRetryWorkflow;
use ServerParity\RetryActivity;
use ServerParity\UnmatchedFilterWorkflow;
use ServerParity\ExhaustedFailureWorkflow;
use ServerParity\FilteredFailureWorkflow;
use ServerParity\TerminalActivity;
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
$options = getopt('', ['mode:', 'fixture:', 'workflow-id:', 'application-root:', 'url:', 'run-id:', 'patch-phase:', 'patch-deadline:', 'range-phase:', 'range-deadline:']);
$mode = $options['mode'] ?? '';
$fixture = json_decode(file_get_contents($options['fixture']), true, flags: JSON_THROW_ON_ERROR);
$workflowId = $options['workflow-id'];
$namespace = 'default';
$queue = $fixture['sdk_update_direction']['task_queue'] ?? $fixture['sdk_query_direction']['task_queue'] ?? $fixture['sdk_signal_direction']['task_queue'] ?? $fixture['sdk_timer_author']['task_queue'] ?? $fixture['sdk_child_direction']['parent_queue'] ?? $fixture['sdk_direction']['workflow_queue'] ?? 'server-parity-v1';
$sdkAutoload = getenv('DW_PARITY_SDK_AUTOLOAD') ?: __DIR__.'/vendor/autoload.php';
if (! is_file($sdkAutoload)) {
    fwrite(STDERR, "Install the locked parity SDK adapter first.\n");
    exit(2);
}
require $sdkAutoload;

require __DIR__.'/observation-values.php';
require __DIR__.'/probe-failure.php';
require_once __DIR__.'/lock-pressure-middleware.php';

function observedChildTransport(array &$polls, array &$completions, array &$activityPolls, array &$activityOutcomes, ?Closure $afterCompletion = null, ?array &$scheduleReceipts = null): Psr18Transport
{
    // Observe real published transport I/O. No response or request is changed,
    // and authentication headers are never retained.
    $stack = HandlerStack::create();
    $stack->push(static function (callable $handler) use (&$polls, &$completions, &$activityPolls, &$activityOutcomes, $afterCompletion, &$scheduleReceipts): callable {
        return static function (RequestInterface $request, array $options) use ($handler, &$polls, &$completions, &$activityPolls, &$activityOutcomes, $afterCompletion, &$scheduleReceipts) {
            return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$polls, &$completions, &$activityPolls, &$activityOutcomes, $afterCompletion, &$scheduleReceipts): ResponseInterface {
                $path = $request->getUri()->getPath();
                if ($scheduleReceipts !== null && str_starts_with($path, '/api/schedules') && $request->getMethod() !== 'GET') {
                    $body = (string) $request->getBody();
                    $scheduleReceipts[] = ['method' => $request->getMethod(), 'path' => $path,
                        'request' => $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR),
                        'status' => $response->getStatusCode(), 'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
                } elseif ($path === '/api/worker/workflow-tasks/poll') {
                    $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($body['task'] ?? null)) {
                        $polls[] = $body['task'];
                    }
                } elseif (preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $path)) {
                    $completions[] = ['path' => $path,
                        'request' => json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
                        'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
                    if ($response->getStatusCode() === 200 && $afterCompletion !== null) {
                        $afterCompletion();
                    }
                } elseif ($path === '/api/worker/activity-tasks/poll') {
                    $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($body['task'] ?? null)) {
                        $activityPolls[] = $body['task'];
                    }
                } elseif (preg_match('#^/api/worker/activity-tasks/[^/]+/(?:complete|fail)$#', $path)) {
                    $activityOutcomes[] = ['path' => $path, 'status' => $response->getStatusCode(),
                        'request' => json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
                        'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
                }

                return $response;
            });
        };
    });
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');

    return new Psr18Transport(client: new GuzzleClient(['http_errors' => false, 'handler' => $stack]));
}

// A query request waits for a worker response. Execute the unchanged published
// client in an independent PHP process so this probe's worker keeps polling.
if ($mode === 'query') {
    try {
        $client = parityClient($options['url'], namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null);
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
    $scheduleReceipts = [];
    $scheduleState = null;
    $client = $handle = null;
    $childCancellation = null;
    // Observe the committed response before this single real SDK worker can
    // poll its next task. A wall-clock callback cannot guarantee before-claim
    // cancellation when a server completes successive requests quickly.
    $afterCompletion = isset($fixture['child_cancellation']) ? static function () use (&$client, &$handle, &$childCancellation, $workflowId, $fixture): void {
        driveHttpChildCancellation($client, $workflowId, $handle->selectedRunId, $fixture, $childCancellation);
    } : null;
    $transport = isset($fixture['child_count']) || isset($fixture['child_cancellation']) || isset($fixture['retry_policy']) || isset($fixture['schedule'])
        ? observedChildTransport($workflowPolls, $workflowCompletions, $activityPolls, $activityOutcomes, $afterCompletion, $scheduleReceipts) : null;
    $client = parityClient($url, namespace: $namespace, transport: $transport, token: getenv('DW_PARITY_TOKEN') ?: null);
    $workerClient = ($fixture['auth_profile'] ?? null) === 'role_tokens'
        ? parityClient($url, namespace: $namespace, token: parityAdmissionToken('worker')) : $client;
    $handle = null;
    $deliveries = [];
    $queries = [];
    $queryTasks = [];
    $updates = [];
    $updateTasks = [];
    $pendingUpdate = null;
    $pendingQuery = null;
    $caughtFailure = null;
    $diagnostics = [];
    $failureCaptured = false;
    $caughtChildCancellation = null;
    $deadline = microtime(true) + 30;
    $worker = null;
    $worker = (new Worker($workerClient, $queue, clock: static function () use (&$worker, &$handle, &$deliveries, &$queries, &$queryTasks, &$pendingQuery, &$updates, &$updateTasks, &$pendingUpdate, &$diagnostics, &$failureCaptured, &$childCancellation, $namespace, $client, $fixture, $fixturePath, &$workflowId, $url, $deadline): float {
        if (microtime(true) > $deadline) {
            $error = new RuntimeException('Parity worker exceeded its 30-second completion budget.');
            parityHttpFailureEvidence($error, 'before-worker-shutdown', $workflowId, $handle?->selectedRunId, $url, $namespace, $diagnostics);
            $failureCaptured = true;
            throw $error;
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
    }, diagnosticListener: static function (string $event, array $details) use (&$handle, &$diagnostics, &$scheduleState, $client, $fixture, &$workflowId, $queue): void {
        $diagnostics[] = parityWorkerDiagnostic($event, $details);
        if (count($diagnostics) > 200) {
            array_shift($diagnostics);
        }
        if ($event === 'worker.registered') {
            if (isset($fixture['schedule'])) {
                $scheduleState = [];
                $trigger = prepareHttpSchedule($client, $fixture, $workflowId, $queue, $scheduleState);
                $workflowId = $trigger['workflow_id'];
                $handle = $client->workflowHandle($workflowId, $trigger['run_id']);

                return;
            }
            $arguments = [$fixture['input']];
            if (isset($fixture['signal_count'])) {
                $arguments[] = $fixture['signal_count'];
            }
            $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $queue, $arguments);
        }
    }))
        ->registerWorkflow('parity.v1.echo', static fn (WorkflowContext $context, array $value): array => $value)
        ->registerWorkflow('parity.v1.cancelled_child_parent', static function (WorkflowContext $context, array $value) use (&$caughtChildCancellation): array {
            try {
                $context->childWorkflow('parity.v1.cancelled_child', [$value], ['queue' => 'server-parity-v1']);
            } catch (\DurableWorkflow\Exception\ChildWorkflowFailed $error) {
                if ($error->failureType !== 'ChildRunCancelled') {
                    throw $error;
                }
                $caughtChildCancellation = ['class' => $error::class, 'message' => $error->getMessage(),
                    'failure_type' => $error->failureType, 'workflow_type' => $error->workflowType, 'payload' => $error->failure];

                return ['value' => $value, 'child_cancelled' => true];
            }

            throw new LogicException('The original child unexpectedly completed.');
        })
        ->registerWorkflow('parity.v1.cancelled_child', static function (WorkflowContext $context, array $value): array {
            $context->sleep(60);

            return $value;
        })
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
        ->registerWorkflow('parity.v1.activity_retry_unmatched_filter', static fn (WorkflowContext $context, array $value): array => $context->activity('parity.v1.retry_activity', [$value], ['retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [1], 'non_retryable_error_types' => ['InvalidArgumentException']]]))
        ->registerWorkflow('parity.v1.activity_failure_exhausted', static function (WorkflowContext $context, array $value) use (&$caughtFailure): array {
            try {
                $context->activity('parity.v1.terminal_activity', [$value], ['retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [1], 'non_retryable_error_types' => []]]);
            } catch (\DurableWorkflow\Exception\ActivityFailed $failure) {
                $caughtFailure = ['type' => $failure->failureType, 'message' => $failure->getMessage(),
                    'non_retryable' => $failure->nonRetryable, 'history_event_type' => $failure->historyEventType, 'payload' => $failure->failure];

                return ['echo' => $value, 'caught' => ['type' => $failure->failureType, 'message' => $failure->getMessage()]];
            }

            throw new LogicException('The terminal fixture activity unexpectedly succeeded.');
        })
        ->registerWorkflow('parity.v1.activity_failure_filtered', static function (WorkflowContext $context, array $value) use (&$caughtFailure): array {
            try {
                $context->activity('parity.v1.terminal_activity', [$value], ['retry_policy' => ['max_attempts' => 3, 'backoff_seconds' => [1], 'non_retryable_error_types' => ['RuntimeException']]]);
            } catch (\DurableWorkflow\Exception\ActivityFailed $failure) {
                $caughtFailure = ['type' => $failure->failureType, 'message' => $failure->getMessage(),
                    'non_retryable' => $failure->nonRetryable, 'history_event_type' => $failure->historyEventType, 'payload' => $failure->failure];

                return ['echo' => $value, 'caught' => ['type' => $failure->failureType, 'message' => $failure->getMessage()]];
            }

            throw new LogicException('The terminal fixture activity unexpectedly succeeded.');
        })
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
        ->registerActivity('parity.v1.terminal_activity', static function (ActivityContext $context, array $value): array {
            throw new RuntimeException('parity terminal λ');
        })
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
    } catch (Throwable $error) {
        if (! $failureCaptured) {
            parityHttpFailureEvidence($error, 'after-worker-shutdown', $workflowId, $handle?->selectedRunId, $url, $namespace, $diagnostics,
                ['workflow_polls' => $workflowPolls, 'workflow_completions' => $workflowCompletions,
                    'activity_polls' => $activityPolls, 'activity_outcomes' => $activityOutcomes,
                    'schedule' => $scheduleState, 'schedule_receipts' => $scheduleReceipts]);
        }
        throw $error;
    } finally {
        if ($pendingQuery !== null) {
            proc_terminate($pendingQuery['process'], 9);
            proc_close($pendingQuery['process']);
            unlink($pendingQuery['stdout']);
            unlink($pendingQuery['stderr']);
        }
    }
    $execution = $handle->describeSelectedRun();
    $visibility = isset($fixture['visibility']) ? finishHttpVisibility($client, $fixture, $workflowId,
        $handle->selectedRunId, $queue, $url, $namespace) : null;
    $admission = isset($fixture['admission']) ? finishHttpAdmission($client, $fixture, $workflowId,
        $handle->selectedRunId, $queue, $url, $namespace) : null;
    $namespaces = isset($fixture['namespace_isolation']) ? finishHttpNamespaces($client, $fixture, $workflowId, $queue, $url) : null;
    $workerDeregistration = isset($fixture['worker_deregistration']) ? finishHttpWorkerDeregistration($client, $fixture, $workflowId, $queue, $url) : null;
    $lateWorkflowCompletion = isset($fixture['late_workflow_completion']) ? finishHttpLateWorkflowCompletion($client, $fixture, $workflowId) : null;
    $searchAttributes = isset($fixture['search_attributes']) ? finishHttpSearchAttributes($client, $fixture, $workflowId, $url) : null;
    $storageTest = isset($fixture['storage_test']) ? finishHttpStorageTest($client, $fixture, $workflowId, $url) : null;
    $activityLeaseRenewal = isset($fixture['activity_lease_renewal']) ? finishHttpActivityLeaseRenewal($client, $fixture, $workflowId, $url) : null;
    $concurrentWaits = isset($fixture['concurrent_waits']) ? finishHttpConcurrentWaits($client, $fixture, $workflowId, $url) : null;
    $historyExportWaits = isset($fixture['history_export_waits']) ? finishHttpHistoryExportWaits($client, $fixture, $workflowId, $url) : null;
    $continueTransfers = isset($fixture['continue_transfers']) ? finishHttpContinueTransfers($client, $fixture, $workflowId, $url) : null;
    $largeCommandBatches = isset($fixture['large_command_batches']) ? finishHttpLargeCommandBatches($client, $fixture, $workflowId, $url) : null;
    $manyUpdates = isset($fixture['many_updates']) ? finishHttpManyUpdates($client, $fixture, $workflowId, $url) : null;
    $workerErrorBodies = isset($fixture['worker_error_bodies']) ? finishHttpWorkerErrorBodies($client, $fixture, $workflowId, $url) : null;
    $childDescriptions = isset($fixture['child_descriptions']) ? finishHttpChildDescriptions($client, $fixture, $workflowId, $url) : null;
    $childOpenParentTask = isset($fixture['child_open_parent_task']) ? finishHttpChildOpenParentTask($client, $fixture, $workflowId, $url) : null;
    $childRetries = isset($fixture['child_retries']) ? finishHttpChildRetries($client, $fixture, $workflowId, $url) : null;
    $activityLateOutcomes = isset($fixture['activity_late_outcomes']) ? finishHttpActivityLateOutcomes($client, $fixture, $workflowId, $url) : null;
    $childVisibility = isset($fixture['child_visibility']) ? finishHttpChildVisibility($client, $fixture, $workflowId, $url) : null;
    $clusterCapabilities = isset($fixture['cluster_capabilities']) ? finishHttpClusterCapabilities($client, $fixture, $workflowId, $url) : null;
    $deepJsonBodies = isset($fixture['deep_json_bodies']) ? finishHttpDeepJsonBodies($client, $fixture, $workflowId, $url) : null;
    $visibilityCollation = isset($fixture['visibility_collation']) ? finishHttpVisibilityCollation($client, $fixture, $workflowId, $url) : null;
    $searchAttributeDatetimes = isset($fixture['search_attribute_datetimes']) ? finishHttpSearchAttributeDatetimes($client, $fixture, $workflowId, $url) : null;
    $retriedChildCancellation = isset($fixture['retried_child_cancellation']) ? finishHttpRetriedChildCancellation($client, $fixture, $workflowId, $url) : null;
    $parallelCancellation = isset($fixture['parallel_cancellation']) ? finishHttpParallelCancellation($client, $fixture, $workflowId, $url) : null;
    $activityResultRepeats = isset($fixture['activity_result_repeats']) ? finishHttpActivityResultRepeats($client, $fixture, $workflowId, $url) : null;
    $startSearchAttributes = isset($fixture['start_search_attributes']) ? finishHttpStartSearchAttributes($client, $fixture, $workflowId, $url) : null;
    $queryArgumentRouting = isset($fixture['query_argument_routing']) ? finishHttpQueryArgumentRouting($client, $fixture, $workflowId, $url) : null;
    $queryContractArguments = isset($fixture['query_contract_arguments']) ? finishHttpQueryContractArguments($client, $fixture, $workflowId, $url) : null;
    $parentCloseCancellation = isset($fixture['parent_close_cancellation']) ? finishHttpParentCloseCancellation($client, $fixture, $workflowId, $url) : null;
    $bridgeAdapters = isset($fixture['bridge_adapters']) ? finishHttpBridgeAdapters($client, $fixture, $workflowId, $url) : null;
    $nexusOperations = isset($fixture['nexus_operations']) ? finishHttpNexusOperations($client, $fixture, $workflowId, $url) : null;
    $recordLocalActivity = isset($fixture['record_local_activity']) ? finishHttpRecordLocalActivity($client, $fixture, $workflowId, $url) : null;
    $workflowPollRequest = isset($fixture['workflow_poll_request']) ? finishHttpWorkflowPollRequest($client, $fixture, $workflowId, $url) : null;
    $workerPollFence = isset($fixture['worker_poll_fence']) ? finishHttpWorkerPollFence($client, $fixture, $workflowId, $url) : null;
    $workerSessions = isset($fixture['worker_sessions']) ? finishHttpWorkerSessions($client, $fixture, $workflowId, $url) : null;
    $workerSessionActivities = isset($fixture['worker_session_activities']) ? finishHttpWorkerSessionActivities($client, $fixture, $workflowId, $url) : null;
    $activityCancellationAcknowledgement = isset($fixture['activity_cancellation_acknowledgement'])
        ? finishHttpActivityCancellationAcknowledgement($client, $fixture, $workflowId, $url) : null;
    $cancellationScopes = isset($fixture['cancellation_scopes']) ? finishHttpCancellationScopes($client, $fixture, $workflowId, $url) : null;
    $serviceCatalog = isset($fixture['service_catalog']) ? finishHttpServiceCatalog($client, $fixture, $workflowId, $url) : null;
    $serviceEndpoints = isset($fixture['service_endpoints']) ? finishHttpServiceEndpoints($client, $fixture, $workflowId, $url) : null;
    $serviceCalls = isset($fixture['service_calls']) ? finishHttpServiceCalls($client, $fixture, $workflowId, $url) : null;
    $workflowImport = isset($fixture['workflow_import']) ? finishHttpWorkflowImport($client, $fixture, $workflowId, $url) : null;
    $waterlineImport = isset($fixture['waterline_import']) ? finishHttpWaterlineImport($client, $fixture, $workflowId, $url) : null;
    $systemMetrics = isset($fixture['system_metrics']) ? finishHttpSystemMetrics($client, $fixture, $workflowId, $url) : null;
    $operatorDashboardTypes = isset($fixture['operator_dashboard_types']) ? finishHttpOperatorDashboardTypes($client, $fixture, $workflowId, $url) : null;
    $prometheusMetrics = isset($fixture['prometheus_metrics']) ? finishHttpPrometheusMetrics($client, $fixture, $workflowId, $url) : null;
    $payloadCleanup = isset($fixture['payload_cleanup']) ? finishHttpPayloadCleanup($client, $fixture, $workflowId, $url) : null;
    $retentionPayloads = isset($fixture['retention_payloads']) ? finishHttpRetentionPayloads($client, $fixture, $workflowId, $url) : null;
    $clusterInfo = isset($fixture['cluster_info']) ? finishHttpClusterInfo($client, $fixture, $workflowId, $url) : null;
    $repairPass = isset($fixture['repair_pass']) ? finishHttpRepairPass($client, $fixture, $workflowId, $url) : null;
    $namespaceDelete = isset($fixture['namespace_delete']) ? finishHttpNamespaceDelete($client, $fixture, $workflowId, $queue, $url) : null;
    $namespaceDeletePayloads = isset($fixture['namespace_delete_payloads']) ? finishHttpNamespaceDeletePayloads($client, $fixture, $workflowId, $url) : null;
    $updateValidation = isset($fixture['update_validation']) ? finishHttpUpdateValidation($client, $fixture, $workflowId, $url) : null;
    $messageStreams = isset($fixture['message_streams']) ? finishHttpMessageStreams($client, $fixture, $workflowId, $url) : null;
    $workflowStreamWrites = isset($fixture['workflow_stream_writes']) ? finishHttpWorkflowStreamWrites($client, $fixture, $workflowId, $url) : null;
    $schedulePolicies = isset($fixture['schedule_policies']) ? finishHttpSchedulePolicies($client, $fixture, $workflowId, $url) : null;
    $scheduleCatalog = isset($fixture['schedule_catalog'])
        ? finishHttpSchedulePolicies($client, $fixture, $workflowId, $url, 'schedule_catalog')
        : null;
    $signalsBeforeWorker = isset($fixture['signals_before_worker'])
        ? finishHttpSignalsBeforeWorker($client, $fixture, $workflowId, $url)
        : null;
    $workerListing = isset($fixture['worker_listing']) ? finishHttpWorkerListing($client, $fixture, $workflowId) : null;
    $workerReplacement = isset($fixture['worker_replacement']) ? finishHttpWorkerReplacement($client, $fixture, $workflowId) : null;
    $operatorMetrics = isset($fixture['operator_metrics']) ? finishHttpOperatorMetrics($client, $fixture, $workflowId, $url) : null;
    $waitingHistory = isset($fixture['waiting_for_history']) ? finishHttpWaitingHistory($client, $fixture, $workflowId, $queue, $url) : null;
    $taskRetry = isset($fixture['workflow_task_retry']) ? finishHttpTaskRetry($client, $fixture, $workflowId, $queue, $url) : null;
    if ($scheduleState !== null) {
        $scheduleState = finishHttpSchedule($client, $fixture, $scheduleState, $workflowId, $handle->selectedRunId);
        $scheduleState['control_receipts'] = $scheduleReceipts;
    }
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
    if (isset($fixture['child_cancellation'])) {
        $childCancellation = finishHttpChildCancellation($client, $workflowId, $handle->selectedRunId, $childCancellation, $url, $namespace);
        $childCancellation['caught'] = $caughtChildCancellation;
    }
    $activityDuplicates = null;
    if (isset($fixture['retry_policy']) && ! isset($fixture['terminal_activity_failure'])) {
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
    if (isset($fixture['terminal_activity_failure'])) {
        $originalOutcomes = $activityOutcomes;
        $before = httpHistory($client, $workflowId, $handle->selectedRunId);
        $receipts = [];
        foreach ($activityPolls as $task) {
            try {
                $response = $client->failActivityTask($task['task_id'], $task['activity_attempt_id'], $task['lease_owner'],
                    $fixture['failure']['message'], $fixture['failure']['type']);
                $receipts[] = ['status' => 200, 'response' => $response];
            } catch (ServerException $failure) {
                $receipts[] = ['status' => $failure->status, 'response' => $failure->details];
            }
        }
        $activityDuplicates = ['failures' => $receipts, 'history_before' => $before,
            'history_after' => httpHistory($client, $workflowId, $handle->selectedRunId)];
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
        'child_cancellation' => $childCancellation,
        'schedule' => $scheduleState,
        'workflow_id' => $execution->workflowId,
        'visibility' => $visibility,
        'admission' => $admission,
        'namespace_isolation' => $namespaces,
        'worker_deregistration' => $workerDeregistration,
        'late_workflow_completion' => $lateWorkflowCompletion,
        'search_attributes' => $searchAttributes,
        'storage_test' => $storageTest,
        'activity_lease_renewal' => $activityLeaseRenewal,
        'concurrent_waits' => $concurrentWaits,
        'history_export_waits' => $historyExportWaits,
        'continue_transfers' => $continueTransfers,
        'large_command_batches' => $largeCommandBatches,
        'many_updates' => $manyUpdates,
        'worker_error_bodies' => $workerErrorBodies,
        'child_descriptions' => $childDescriptions,
        'child_open_parent_task' => $childOpenParentTask,
        'child_retries' => $childRetries,
        'activity_late_outcomes' => $activityLateOutcomes,
        'child_visibility' => $childVisibility,
        'cluster_capabilities' => $clusterCapabilities,
        'deep_json_bodies' => $deepJsonBodies,
        'visibility_collation' => $visibilityCollation,
        'search_attribute_datetimes' => $searchAttributeDatetimes,
        'retried_child_cancellation' => $retriedChildCancellation,
        'parallel_cancellation' => $parallelCancellation,
        'activity_result_repeats' => $activityResultRepeats,
        'start_search_attributes' => $startSearchAttributes,
        'query_argument_routing' => $queryArgumentRouting,
        'query_contract_arguments' => $queryContractArguments,
        'parent_close_cancellation' => $parentCloseCancellation,
        'bridge_adapters' => $bridgeAdapters,
        'nexus_operations' => $nexusOperations,
        'record_local_activity' => $recordLocalActivity,
        'workflow_poll_request' => $workflowPollRequest,
        'worker_poll_fence' => $workerPollFence,
        'worker_sessions' => $workerSessions,
        'worker_session_activities' => $workerSessionActivities,
        'activity_cancellation_acknowledgement' => $activityCancellationAcknowledgement,
        'cancellation_scopes' => $cancellationScopes,
        'service_catalog' => $serviceCatalog,
        'service_endpoints' => $serviceEndpoints,
        'service_calls' => $serviceCalls,
        'workflow_import' => $workflowImport,
        'waterline_import' => $waterlineImport,
        'system_metrics' => $systemMetrics,
        'operator_dashboard_types' => $operatorDashboardTypes,
        'prometheus_metrics' => $prometheusMetrics,
        'payload_cleanup' => $payloadCleanup,
        'retention_payloads' => $retentionPayloads,
        'cluster_info' => $clusterInfo,
        'repair_pass' => $repairPass,
        'namespace_delete' => $namespaceDelete,
        'namespace_delete_payloads' => $namespaceDeletePayloads,
        'update_validation' => $updateValidation,
        'message_streams' => $messageStreams,
        'workflow_stream_writes' => $workflowStreamWrites,
        'schedule_policies' => $schedulePolicies,
        'schedule_catalog' => $scheduleCatalog,
        'signals_before_worker' => $signalsBeforeWorker,
        'worker_listing' => $workerListing,
        'worker_replacement' => $workerReplacement,
        'operator_metrics' => $operatorMetrics,
        'waiting_for_history' => $waitingHistory,
        'workflow_task_retry' => $taskRetry,
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
        'caught_activity_failure' => $caughtFailure,
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
            'parity.v1.sdk_timer' => SdkTimerWorkflow::class,
            'parity.v1.signals' => SignalsWorkflow::class,
            'parity.v1.sdk_signals' => SdkSignalsWorkflow::class,
            'parity.v1.sdk_queries' => SdkQueriesWorkflow::class,
            'parity.v1.queries' => QueriesWorkflow::class,
            'parity.v1.updates' => UpdatesWorkflow::class,
            'parity.v1.sdk_updates' => SdkUpdatesWorkflow::class,
            'parity.v1.two_children' => TwoChildrenWorkflow::class,
            'parity.v1.one_child' => OneChildWorkflow::class,
            'parity.v1.echo_child' => ChildEchoWorkflow::class,
            'parity.v1.child_activity' => ChildActivityWorkflow::class,
            'parity.v1.cancelled_child_parent' => CancelledChildParentWorkflow::class,
            'parity.v1.cancelled_child' => CancelledChildWorkflow::class,
            'parity.v1.activity_retry' => ActivityRetryWorkflow::class,
            'parity.v1.activity_retry_unmatched_filter' => UnmatchedFilterWorkflow::class,
            'parity.v1.activity_failure_exhausted' => ExhaustedFailureWorkflow::class,
            'parity.v1.activity_failure_filtered' => FilteredFailureWorkflow::class,
        ],
        'workflows.v2.types.activities' => ['parity.v1.echo_activity' => EchoActivity::class, 'parity.v1.retry_activity' => RetryActivity::class, 'parity.v1.terminal_activity' => TerminalActivity::class],
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
        $class = isset($fixture['sdk_child_direction']) ? OneChildWorkflow::class : TwoChildrenWorkflow::class;
    }
    if (isset($fixture['sdk_timer_author'])) {
        $class = SdkTimerWorkflow::class;
    }
    if (isset($fixture['sdk_signal_direction'])) {
        $class = SdkSignalsWorkflow::class;
    }
    if (isset($fixture['sdk_query_direction'])) {
        $class = SdkQueriesWorkflow::class;
    }
    if (isset($fixture['sdk_update_direction'])) {
        $class = SdkUpdatesWorkflow::class;
    }
    if (isset($fixture['retry_policy'])) {
        $class = ActivityRetryWorkflow::class;
    }
    $class = match ($fixture['workflow_type']) {
        'parity.v1.activity_retry_unmatched_filter' => UnmatchedFilterWorkflow::class,
        'parity.v1.activity_failure_exhausted' => ExhaustedFailureWorkflow::class,
        'parity.v1.activity_failure_filtered' => FilteredFailureWorkflow::class,
        'parity.v1.cancelled_child_parent' => CancelledChildParentWorkflow::class,
        default => $class,
    };
    $scheduleState = null;
    if (isset($fixture['schedule'])) {
        $scheduleState = [];
        $trigger = prepareEmbeddedSchedule($fixture, $workflowId, $queue, $class, $scheduleState);
        $workflowId = $trigger['workflow_id'];
        $stub = WorkflowStub::loadSelection($workflowId, $trigger['run_id'], $namespace);
    } else {
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
    }
    $deliveries = [];
    $queries = [];
    $updates = [];
    $pendingUpdate = null;
    $deadline = microtime(true) + 30;
    $childCancellation = null;
    do {
        Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => isset($fixture['sdk_child_direction']) ? $queue.','.$fixture['sdk_child_direction']['child_queue'] : $queue, '--stop-when-empty' => true,
            '--max-time' => 30, '--sleep' => 0, '--tries' => 1,
            ...isset($fixture['child_cancellation']) ? ['--once' => true] : [],
        ]);
        if (isset($fixture['child_cancellation'])) {
            driveEmbeddedChildCancellation($stub->runId(), $fixture, $childCancellation);
        }
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $history = static fn (): array => $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
            'sequence' => $event->sequence, 'event_type' => $event->event_type->value, 'payload' => $event->payload,
        ])->all();
        if (isset($fixture['update_values'])) {
            if ($pendingUpdate !== null) {
                $completed = $stub->inspectUpdate($pendingUpdate['accepted']['update_id']);
                $expectsFailure = isset($fixture['sdk_update_failure']) && count($updates) === 0;
                if ($expectsFailure ? ! $completed->failed() : ! $completed->completed()) {
                    throw new RuntimeException('Embedded update did not complete through its real queue job.');
                }
                $historyBefore = $history();
                $duplicate = $stub->withCommandContext(CommandContext::phpApi()->with(['request' => ['request_id' => $pendingUpdate['request_id']]]))
                    ->submitUpdateWithArguments($fixture['update_name'], [$fixture['duplicate_update_value']]);
                $inspected = $stub->inspectUpdate($duplicate->updateId());
                if ($expectsFailure ? ! $inspected->failed() : ! $inspected->completed()) {
                    throw new RuntimeException('Embedded completed duplicate changed the original update outcome.');
                }
                $result = $inspected->result();
                $outcome = $expectsFailure ? ['failed_result' => [
                    'failure_id' => $completed->failureId(), 'message' => $completed->failureMessage(),
                    'update_status' => $completed->updateStatus(), 'outcome' => $completed->outcome()],
                    'duplicate_failed_result' => ['failure_id' => $inspected->failureId(),
                        'message' => $inspected->failureMessage(), 'update_status' => $inspected->updateStatus(),
                        'outcome' => $inspected->outcome()]] : ['result' => $result, 'typed_result' => typedValue($result)];
                $updates[] = [...$pendingUpdate, ...$outcome,
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
        $expected = ($fixture['queries'] ?? $fixture['query_checkpoints'] ?? [])[count($queries)] ?? null;
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
    if ($scheduleState !== null) {
        $scheduleState = finishEmbeddedSchedule($fixture, $scheduleState, $workflowId, $stub->runId());
    }
    $visibility = isset($fixture['visibility']) ? finishEmbeddedVisibility($fixture, $workflowId, $queue, $namespace) : null;
    $admission = isset($fixture['admission']) ? finishEmbeddedAdmission($fixture, $workflowId, $queue) : null;
    $namespaces = isset($fixture['namespace_isolation']) ? finishEmbeddedNamespaces($fixture, $workflowId, $queue) : null;
    if (isset($fixture['child_cancellation'])) {
        $childCancellation = finishEmbeddedChildCancellation($stub->runId(), $childCancellation);
        $childCancellation['caught'] = ChildCancellationProbeState::$caught;
    }
    $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
        'sequence' => $event->sequence,
        'event_type' => $event->event_type->value,
        'timestamp' => $event->recorded_at->toISOString(),
        'payload' => $event->payload,
    ])->all();
    $activityDuplicates = null;
    if (isset($fixture['retry_policy']) && ! isset($fixture['terminal_activity_failure'])) {
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
    if (isset($fixture['terminal_activity_failure'])) {
        $before = $history();
        $taskIds = [];
        foreach ($events as $event) {
            if ($event['event_type'] !== 'ActivityStarted') {
                continue;
            }
            $taskId = $event['payload']['task']['id'];
            $taskIds[] = $taskId;
            \Workflow\V2\Jobs\RunActivityTask::dispatch($taskId)->onConnection('database')->onQueue($queue);
        }
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true, '--max-time' => 30, '--sleep' => 0, '--tries' => 1]);
        $activityDuplicates = ['redelivered_task_ids' => $taskIds, 'history_before' => $before, 'history_after' => $history()];
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
        'visibility' => $visibility,
        'admission' => $admission,
        'namespace_isolation' => $namespaces,
        'child_cancellation' => $childCancellation,
        'worker_output' => Artisan::output(),
        'workflow_id' => $run->workflow_instance_id,
        'run_id' => $run->id,
        'workflow_type' => $run->workflow_type,
        'namespace' => $run->namespace,
        'task_queue' => $run->queue,
        'status' => $run->status->value,
        'payload_codec' => $run->payload_codec,
        'input' => $run->workflowArguments(),
        'schedule' => $scheduleState,
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
    require __DIR__.'/schedule-probe.php';
    require __DIR__.'/visibility-probe.php';
    require __DIR__.'/admission-probe.php';
    require __DIR__.'/namespace-probe.php';
    require __DIR__.'/search-attribute-probe.php';
    require __DIR__.'/storage-test-probe.php';
    require __DIR__.'/activity-lease-renewal-probe.php';
    require __DIR__.'/concurrent-waits-probe.php';
    require __DIR__.'/history-export-waits-probe.php';
    require __DIR__.'/continue-transfers-probe.php';
    require __DIR__.'/large-command-batches-probe.php';
    require __DIR__.'/many-updates-probe.php';
    require __DIR__.'/worker-error-bodies-probe.php';
    require __DIR__.'/child-descriptions-probe.php';
    require __DIR__.'/child-open-parent-task-probe.php';
    require __DIR__.'/child-retries-probe.php';
    require __DIR__.'/activity-late-outcomes-probe.php';
    require __DIR__.'/child-visibility-probe.php';
    require __DIR__.'/cluster-capabilities-probe.php';
    require __DIR__.'/deep-json-bodies-probe.php';
    require __DIR__.'/visibility-collation-probe.php';
    require __DIR__.'/search-attribute-datetimes-probe.php';
    require __DIR__.'/retried-child-cancellation-probe.php';
    require __DIR__.'/parallel-cancellation-probe.php';
    require __DIR__.'/activity-result-repeats-probe.php';
    require __DIR__.'/start-search-attributes-probe.php';
    require __DIR__.'/query-arguments-probe.php';
    require __DIR__.'/query-contract-arguments-probe.php';
    require __DIR__.'/parent-close-cancellation-probe.php';
    require __DIR__.'/bridge-adapters-probe.php';
    require __DIR__.'/nexus-operations-probe.php';
    require __DIR__.'/record-local-activity-probe.php';
    require __DIR__.'/workflow-poll-request-probe.php';
    require __DIR__.'/worker-poll-fence-probe.php';
    require __DIR__.'/worker-sessions-probe.php';
    require __DIR__.'/worker-session-activities-probe.php';
    require __DIR__.'/activity-cancellation-ack-probe.php';
    require __DIR__.'/cancellation-scopes-probe.php';
    require __DIR__.'/service-catalog-probe.php';
    require __DIR__.'/service-endpoints-probe.php';
    require __DIR__.'/service-calls-probe.php';
    require __DIR__.'/workflow-import-probe.php';
    require __DIR__.'/waterline-import-probe.php';
    require __DIR__.'/system-metrics-probe.php';
    require __DIR__.'/operator-dashboard-types-probe.php';
    require __DIR__.'/prometheus-metrics-probe.php';
    require __DIR__.'/payload-cleanup-probe.php';
    require __DIR__.'/retention-payloads-probe.php';
    require __DIR__.'/cluster-info-probe.php';
    require __DIR__.'/repair-pass-probe.php';
    require __DIR__.'/namespace-delete-probe.php';
    require __DIR__.'/namespace-delete-payloads-probe.php';
    require __DIR__.'/update-validation-probe.php';
    require __DIR__.'/message-streams-probe.php';
    require __DIR__.'/workflow-stream-writes-probe.php';
    require __DIR__.'/schedule-policies-probe.php';
    require __DIR__.'/signals-before-worker-probe.php';
    require __DIR__.'/worker-deregistration-probe.php';
    require __DIR__.'/late-workflow-completion-probe.php';
    require __DIR__.'/worker-listing-probe.php';
    require __DIR__.'/worker-replacement-probe.php';
    require __DIR__.'/operator-metrics-probe.php';
    require __DIR__.'/waiting-history-probe.php';
    require __DIR__.'/task-retry-probe.php';
    require __DIR__.'/child-cancellation-probe.php';
    require __DIR__.'/cancellation-probe.php';
    require __DIR__.'/cooperative-probe.php';
    if (isset($fixture['selected_run_signal_pair'])) {
        require __DIR__.'/sdk-direction-probe.php';
        require __DIR__.'/sdk-namespace-signal-probe.php';
        require __DIR__.'/sdk-selected-run-signal-probe.php';
        $observation = $mode === 'http'
            ? selectedRunSignalHttpObservation($fixture, $options)
            : selectedRunSignalEmbeddedObservation($fixture, $workflowId, $options['application-root']);
    } elseif (isset($fixture['namespace_signal_pair'])) {
        require __DIR__.'/sdk-direction-probe.php';
        require __DIR__.'/sdk-namespace-signal-probe.php';
        $observation = $mode === 'http'
            ? namespaceSignalHttpObservation($fixture, $options)
            : namespaceSignalEmbeddedObservation($fixture, $workflowId, $options['application-root']);
    } elseif (isset($fixture['sdk_update_direction'])) {
        require __DIR__.'/sdk-direction-probe.php';
        require __DIR__.'/sdk-query-direction-probe.php';
        require __DIR__.'/sdk-update-direction-probe.php';
        if ($mode === 'http') {
            $observation = sdkUpdateDirectionHttpObservation($fixture, $options);
        } elseif ($mode === 'embedded') {
            $observation = embeddedObservation($fixture, $workflowId, $namespace, $queue, $options['application-root']);
            $observation['sdk_update_direction'] = ['applicable' => false, 'reason' => 'embedded_executes_php_author_definitions'];
        } else {
            throw new InvalidArgumentException('Mode must be http or embedded.');
        }
    } elseif (isset($fixture['sdk_query_direction'])) {
        require __DIR__.'/sdk-direction-probe.php';
        require __DIR__.'/sdk-query-direction-probe.php';
        if ($mode === 'http') {
            $observation = sdkQueryDirectionHttpObservation($fixture, $options);
        } elseif ($mode === 'embedded') {
            $observation = embeddedObservation($fixture, $workflowId, $namespace, $queue, $options['application-root']);
            $observation['sdk_query_direction'] = ['applicable' => false, 'reason' => 'embedded_executes_php_author_definitions'];
        } else {
            throw new InvalidArgumentException('Mode must be http or embedded.');
        }
    } elseif (isset($fixture['sdk_direction']) || isset($fixture['sdk_child_direction']) || isset($fixture['sdk_timer_author']) || isset($fixture['sdk_signal_direction'])) {
        require __DIR__.'/sdk-direction-probe.php';
        if ($mode === 'http') {
            $observation = sdkDirectionHttpObservation($fixture, $options);
        } elseif ($mode === 'embedded') {
            $observation = embeddedObservation($fixture, $workflowId, $namespace, $queue, $options['application-root']);
            $observation[isset($fixture['sdk_signal_direction']) ? 'sdk_signal_direction' : (isset($fixture['sdk_timer_author']) ? 'sdk_timer_author' : (isset($fixture['sdk_child_direction']) ? 'sdk_child_direction' : 'sdk_direction'))]
                = ['applicable' => false, 'reason' => 'embedded_executes_php_author_definitions'];
        } else {
            throw new InvalidArgumentException('Mode must be http or embedded.');
        }
    } elseif (isset($fixture['activity_deadline_receipts'])) {
        require __DIR__.'/activity-deadline-receipts-probe.php';
        if ($mode === 'embedded') {
            require __DIR__.'/activity-deadline-receipts-embedded.php';
            $observation = activityDeadlineReceiptsEmbeddedObservation($fixture, $options);
        } elseif ($mode === 'http') {
            $observation = activityDeadlineReceiptsHttpObservation($fixture, $options);
        } else throw new InvalidArgumentException('Mode must be http or embedded.');
    } elseif (isset($fixture['continuation'])) {
        require __DIR__.'/continue-probe.php';
        $observation = continueObservation($fixture, $options);
    } elseif (isset($fixture['memo_replay'])) {
        require __DIR__.'/memo-probe.php';
        $observation = memoObservation($fixture, $options);
    } elseif (isset($fixture['activity_deadline_authority'])) {
        require __DIR__.'/activity-deadline-probe.php';
        if ($mode === 'embedded') {
            require __DIR__.'/activity-deadline-embedded.php';
            $observation = activityDeadlineEmbeddedObservation($fixture, $options);
        } elseif ($mode === 'http') {
            $observation = activityDeadlineHttpObservation($fixture, $options);
        } else {
            throw new InvalidArgumentException('Mode must be http or embedded.');
        }
    } elseif (isset($fixture['corrected_range'])) {
        require __DIR__.'/corrected-range-probe.php';
        $observation = match ($mode) {
            'http' => correctedRangeHttpObservation($fixture, $options),
            'embedded' => correctedRangeEmbeddedObservation($fixture, $options),
            default => throw new InvalidArgumentException('Mode must be http or embedded.'),
        };
    } elseif (isset($fixture['corrected_markers'])) {
        require __DIR__.'/corrected-marker-probe.php';
        $observation = match ($mode) {
            'http' => correctedMarkerHttpObservation($fixture, $options),
            'embedded' => correctedMarkerEmbeddedObservation($fixture, $options),
            default => throw new InvalidArgumentException('Mode must be http or embedded.'),
        };
    } elseif (isset($fixture['range_repair'])) {
        require __DIR__.'/range-repair-probe.php';
        RangeRepairState::$changeId = $fixture['range_repair']['change_id'];
        $observation = isset($options['range-phase'])
            ? match ($mode) {
                'http' => rangeHttpPhase($fixture, $options),
                'embedded' => rangeEmbeddedPhase($fixture, $options),
                default => throw new InvalidArgumentException('Mode must be http or embedded.'),
            }
            : rangeRepairObservation($fixture, $options);
    } elseif (isset($fixture['patch_deployment'])) {
        require __DIR__.'/patch-deployment-probe.php';
        PatchDeploymentState::$changeId = $fixture['patch_deployment']['change_id'];
        PatchDeploymentState::$expectedDecisions = $fixture['patch_deployment']['expected_decisions'];
        if (($options['patch-phase'] ?? null) === 'original' && isset($fixture['patch_deployment']['legacy_marker_history'])) {
            PatchDeploymentState::$expectedDecisions = array_fill(0, $fixture['patch_deployment']['legacy_marker_history']['original_calls'], true);
        }
        $observation = isset($options['patch-phase'])
            ? match ($mode) {
                'http' => patchHttpPhase($fixture, $options),
                'embedded' => patchEmbeddedPhase($fixture, $options),
                default => throw new InvalidArgumentException('Mode must be http or embedded.'),
            }
            : patchDeploymentObservation($fixture, $options);
    } else {
        $observation = match ($mode) {
        'http' => isset($fixture['cooperative_cancellation'])
            ? httpCooperativeObservation($fixture, $workflowId, $namespace, $queue, $options['url'])
            : (isset($fixture['immediate_cancellation'])
            ? httpCancellationObservation($fixture, $workflowId, $namespace, $queue, $options['url'])
            : httpObservation($fixture, $workflowId, $namespace, $queue, $options['url'], $options['fixture'])),
        'embedded' => isset($fixture['cooperative_cancellation'])
            ? embeddedCooperativeObservation($fixture, $workflowId, $namespace, $queue, $options['application-root'])
            : (isset($fixture['immediate_cancellation'])
            ? embeddedCancellationObservation($fixture, $workflowId, $namespace, $queue, $options['application-root'])
            : embeddedObservation($fixture, $workflowId, $namespace, $queue, $options['application-root'])),
        default => throw new InvalidArgumentException('Mode must be http or embedded.'),
        };
    }
    $observation['typed_input'] = typedValue($observation['input']);
    if ($mode === 'embedded' && isset($fixture['worker_replacement'])) {
        $observation['worker_replacement'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_worker_registration_lifecycle'];
    }
    if ($mode === 'embedded' && isset($fixture['operator_metrics'])) {
        $observation['operator_metrics'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_operator_metrics'];
    }
    if ($mode === 'embedded' && isset($fixture['worker_listing'])) {
        $observation['worker_listing'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_worker_registration_lifecycle'];
    }
    if ($mode === 'embedded' && isset($fixture['worker_deregistration'])) {
        $observation['worker_deregistration'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_worker_registration_lifecycle'];
    }
    if ($mode === 'embedded' && isset($fixture['late_workflow_completion'])) {
        $observation['late_workflow_completion'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_worker_registration_lifecycle'];
    }
    if ($mode === 'embedded' && isset($fixture['search_attributes'])) {
        $observation['search_attributes'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_search_attribute_registry'];
    }
    if ($mode === 'embedded' && isset($fixture['storage_test'])) {
        $observation['storage_test'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_storage_diagnostic'];
    }
    if ($mode === 'embedded' && isset($fixture['concurrent_waits'])) {
        $observation['concurrent_waits'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['history_export_waits'])) {
        $observation['history_export_waits'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['continue_transfers'])) {
        $observation['continue_transfers'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['large_command_batches'])) {
        $observation['large_command_batches'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['many_updates'])) {
        $observation['many_updates'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['worker_error_bodies'])) {
        $observation['worker_error_bodies'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['child_descriptions'])) {
        $observation['child_descriptions'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['child_open_parent_task'])) {
        $observation['child_open_parent_task'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['child_retries'])) {
        $observation['child_retries'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['activity_late_outcomes'])) {
        $observation['activity_late_outcomes'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['child_visibility'])) {
        $observation['child_visibility'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['cluster_capabilities'])) {
        $observation['cluster_capabilities'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_cluster_info'];
    }
    if ($mode === 'embedded' && isset($fixture['deep_json_bodies'])) {
        $observation['deep_json_bodies'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_request_bodies'];
    }
    if ($mode === 'embedded' && isset($fixture['visibility_collation'])) {
        $observation['visibility_collation'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_list_queries'];
    }
    if ($mode === 'embedded' && isset($fixture['search_attribute_datetimes'])) {
        $observation['search_attribute_datetimes'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['retried_child_cancellation'])) {
        $observation['retried_child_cancellation'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['parallel_cancellation'])) {
        $observation['parallel_cancellation'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['activity_result_repeats'])) {
        $observation['activity_result_repeats'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['start_search_attributes'])) {
        $observation['start_search_attributes'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_search_attribute_registry'];
    }
    if ($mode === 'embedded' && isset($fixture['query_argument_routing'])) {
        $observation['query_argument_routing'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['query_contract_arguments'])) {
        $observation['query_contract_arguments'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['parent_close_cancellation'])) {
        $observation['parent_close_cancellation'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['activity_lease_renewal'])) {
        $observation['activity_lease_renewal'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['bridge_adapters'])) {
        $observation['bridge_adapters'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_bridge_adapters'];
    }
    if ($mode === 'embedded' && isset($fixture['nexus_operations'])) {
        $observation['nexus_operations'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_nexus_operations'];
    }
    if ($mode === 'embedded' && isset($fixture['record_local_activity'])) {
        $observation['record_local_activity'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['workflow_poll_request'])) {
        $observation['workflow_poll_request'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['worker_poll_fence'])) {
        $observation['worker_poll_fence'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['worker_sessions'])) {
        $observation['worker_sessions'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['worker_session_activities'])) {
        $observation['worker_session_activities'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['activity_cancellation_acknowledgement'])) {
        $observation['activity_cancellation_acknowledgement'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['cancellation_scopes'])) {
        $observation['cancellation_scopes'] = ['applicable' => false, 'reason' => 'embedded_has_no_worker_protocol'];
    }
    if ($mode === 'embedded' && isset($fixture['service_catalog'])) {
        $observation['service_catalog'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_service_catalog'];
    }
    if ($mode === 'embedded' && isset($fixture['service_endpoints'])) {
        $observation['service_endpoints'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_service_endpoints'];
    }
    if ($mode === 'embedded' && isset($fixture['service_calls'])) {
        $observation['service_calls'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_service_calls'];
    }
    if ($mode === 'embedded' && isset($fixture['workflow_import'])) {
        $observation['workflow_import'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_workflow_import'];
    }
    if ($mode === 'embedded' && isset($fixture['waterline_import'])) {
        $observation['waterline_import'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_waterline_import'];
    }
    if ($mode === 'embedded' && isset($fixture['system_metrics'])) {
        $observation['system_metrics'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_metrics'];
    }
    if ($mode === 'embedded' && isset($fixture['operator_dashboard_types'])) {
        $observation['operator_dashboard_types'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_dashboard'];
    }
    if ($mode === 'embedded' && isset($fixture['prometheus_metrics'])) {
        $observation['prometheus_metrics'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_metrics'];
    }
    if ($mode === 'embedded' && isset($fixture['cluster_info'])) {
        $observation['cluster_info'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_cluster_info'];
    }
    if ($mode === 'embedded' && isset($fixture['payload_cleanup'])) {
        $observation['payload_cleanup'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_system_routes'];
    }
    if ($mode === 'embedded' && isset($fixture['retention_payloads'])) {
        $observation['retention_payloads'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_system_routes'];
    }
    if ($mode === 'embedded' && isset($fixture['repair_pass'])) {
        $observation['repair_pass'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_system_routes'];
    }
    if ($mode === 'embedded' && isset($fixture['namespace_delete'])) {
        $observation['namespace_delete'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_namespaces'];
    }
    if ($mode === 'embedded' && isset($fixture['namespace_delete_payloads'])) {
        $observation['namespace_delete_payloads'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_namespaces'];
    }
    if ($mode === 'embedded' && isset($fixture['update_validation'])) {
        $observation['update_validation'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_update_validation'];
    }
    if ($mode === 'embedded' && isset($fixture['message_streams'])) {
        $observation['message_streams'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_message_streams'];
    }
    if ($mode === 'embedded' && isset($fixture['workflow_stream_writes'])) {
        $observation['workflow_stream_writes'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_workflow_streams'];
    }
    if ($mode === 'embedded' && isset($fixture['schedule_policies'])) {
        $observation['schedule_policies'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_schedules'];
    }
    if ($mode === 'embedded' && isset($fixture['schedule_catalog'])) {
        $observation['schedule_catalog'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_schedules'];
    }
    if ($mode === 'embedded' && isset($fixture['signals_before_worker'])) {
        $observation['signals_before_worker'] = ['applicable' => false, 'reason' => 'embedded_runs_have_a_loadable_contract'];
    }
    $observation['mode'] = $mode;
    $observation['typed_output'] = typedValue($observation['output']);
    if ($mode === 'embedded' && isset($fixture['waiting_for_history'])) {
        $observation['waiting_for_history'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_workflow_task_failure_endpoint'];
    }
    if ($mode === 'embedded' && isset($fixture['workflow_task_retry'])) {
        $observation['workflow_task_retry'] = ['applicable' => false, 'reason' => 'embedded_has_no_http_workflow_task_retry_endpoint'];
    }
    $observation['sdk_php'] = ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v');
    $observation['sdk_php_source'] = InstalledVersions::getReference('durable-workflow/sdk');
    if (isset($fixture['activity_deadline_receipts'])) {
        $observation['sdk_composer_lock_sha256'] = hash_file('sha256', dirname($sdkAutoload, 2).'/composer.lock');
        $observation['sdk_loaded_sources'] = [];
        foreach ([Client::class => 'src/Client.php', Worker::class => 'src/Worker.php', WorkflowContext::class => 'src/Worker/WorkflowContext.php'] as $class => $path) {
            $observation['sdk_loaded_sources'][$path] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
        }
    }
    if (isset($fixture['range_repair'])) {
        $observation['sdk_composer_lock_sha256'] = hash_file('sha256', dirname($sdkAutoload, 2).'/composer.lock');
    }
    if (isset($fixture['corrected_markers']) || isset($fixture['corrected_range'])) {
        $observation['sdk_composer_lock_sha256'] = hash_file('sha256', dirname($sdkAutoload, 2).'/composer.lock');
        $observation['rust_consumer_cargo_lock_sha256'] = hash_file('sha256', getenv('DW_PARITY_CORRECTED_RUST_LOCK') ?: '/sdk-adapters/Cargo.lock');
        $observation['sdk_loaded_sources'] = [];
        foreach ([Client::class => 'src/Client.php', Worker::class => 'src/Worker.php', WorkflowContext::class => 'src/Worker/WorkflowContext.php'] as $class => $path) {
            $observation['sdk_loaded_sources'][$path] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
        }
    }
    $observation['php_version'] = PHP_VERSION;
    if ($mode === 'embedded' && ! isset($observation['workflow_package'])) {
        $observation['workflow_package'] = ltrim(InstalledVersions::getPrettyVersion('durable-workflow/workflow'), 'v');
    }
    if (isset($fixture['sdk_update_direction']) || isset($fixture['sdk_query_direction']) || isset($fixture['sdk_direction']) || isset($fixture['sdk_child_direction']) || isset($fixture['sdk_timer_author']) || isset($fixture['sdk_signal_direction'])) {
        sdkDirectionPackageObservation($observation, $sdkAutoload, $mode === 'embedded' ? $options['application-root'] : null);
    }
    if (isset($fixture['namespace_signal_pair']) || isset($fixture['selected_run_signal_pair'])) {
        $pairKey = isset($fixture['selected_run_signal_pair']) ? 'selected_run_signal_pair' : 'namespace_signal_pair';
        $peer = &$observation[$pairKey]['peer'];
        $peer['mode'] = $mode;
        foreach (['sdk_php', 'sdk_php_source', 'php_version', 'workflow_package'] as $field) {
            if (array_key_exists($field, $observation)) { $peer[$field] = $observation[$field]; }
        }
        sdkDirectionPackageObservation($peer, $sdkAutoload, $mode === 'embedded' ? $options['application-root'] : null);
        unset($peer);
    }
    if ($mode === 'embedded' && isset($fixture['patch_deployment']['embedded_clock_probe']) && ! isset($observation['workflow_source'])) {
        $observation['workflow_source'] = InstalledVersions::getReference('durable-workflow/workflow');
        $observation['embedded_composer_lock_sha256'] = hash_file('sha256', $options['application-root'].'/composer.lock');
        $observation['workflow_loaded_sources'] = [];
        $versionClass = ($options['patch-phase'] ?? null) === 'original'
            && ($fixture['patch_deployment']['legacy_marker_history']['embedded_original'] ?? null) === '2.5.5'
            ? 'VersionResolver' : 'VersionDecisions';
        foreach ([$versionClass, 'WorkflowExecutor', 'WorkflowFiberRunner', 'QueryStateReplayer'] as $name) {
            $class = new ReflectionClass('Workflow\\V2\\Support\\'.$name);
            $observation['workflow_loaded_sources']['src/V2/Support/'.$name.'.php'] = hash_file('sha256', $class->getFileName());
        }
    }
    echo json_encode($observation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    exit(1);
}
