<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use DurableWorkflow\Exception\WorkflowCancelled;
use DurableWorkflow\Transport\Psr18Transport;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;
use Workflow\V2\StartOptions;
use Workflow\WorkflowOptions;

function cooperativeObservedTransport(array &$traffic): Psr18Transport
{
    $stack = HandlerStack::create();
    $stack->push(static function (callable $handler) use (&$traffic): callable {
        return static function (RequestInterface $request, array $options) use ($handler, &$traffic) {
            return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$traffic): ResponseInterface {
                if (in_array($request->getMethod(), ['POST', 'PUT'], true)) {
                    $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    $path = $request->getUri()->getPath();
                    if (! str_ends_with($path, '/poll') || is_array($body['task'] ?? null)) {
                        $traffic[] = ['method' => $request->getMethod(), 'path' => $path, 'status' => $response->getStatusCode(),
                            'request' => json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
                            'response' => $body];
                    }
                }

                return $response; // Retain actual I/O without changing packets or recording headers.
            });
        };
    });

    return new Psr18Transport(client: new GuzzleClient(['http_errors' => false, 'handler' => $stack]));
}

function httpCooperativeObservation(array $fixture, string $workflowId, string $namespace, string $queue, string $url): array
{
    $traffic = [];
    $client = new Client($url, namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: cooperativeObservedTransport($traffic), workerProtocolVersion: '1.20');
    $phase = $fixture['cooperative_cancellation']['phase'];
    $reason = $fixture['cooperative_cancellation']['reason'];
    $budget = $fixture['cooperative_cancellation']['cleanup_timeout_seconds'];
    $worker = $handle = null;
    $before = $accepted = $afterRequest = $pendingDuplicate = $historyBeforePendingDuplicate = $historyAfterPendingDuplicate = null;
    $deadline = microtime(true) + 30;
    $request = static function () use ($client, $workflowId, $reason, $budget, &$handle, &$before, &$accepted,
        &$afterRequest, &$pendingDuplicate, &$historyBeforePendingDuplicate, &$historyAfterPendingDuplicate): void {
        $before = httpHistory($client, $workflowId, $handle->selectedRunId);
        $accepted = $client->requestWorkflowCancellation($workflowId, $reason, $budget, $handle->selectedRunId);
        $afterRequest = $handle->describeSelectedRun()->raw;
        $historyBeforePendingDuplicate = httpHistory($client, $workflowId, $handle->selectedRunId);
        $pendingDuplicate = $client->requestWorkflowCancellation($workflowId, 'replacement reason', 300, $handle->selectedRunId);
        $historyAfterPendingDuplicate = httpHistory($client, $workflowId, $handle->selectedRunId);
    };
    $worker = (new Worker($client, $queue, enableCooperativeCancellation: true,
        clock: static function () use (&$worker, &$handle, &$accepted, $client, $workflowId, $phase, $request, $deadline): float {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Cooperative worker exceeded its 30-second budget.');
            }
            if ($handle !== null && $accepted === null && $phase === 'pending_timer') {
                $events = httpHistory($client, $workflowId, $handle->selectedRunId);
                if (array_filter($events, static fn (array $event): bool => $event['event_type'] === 'TimerScheduled') !== []) {
                    $request();
                }
            }
            if ($handle !== null && $handle->describeSelectedRun()->isTerminal) {
                $worker->requestShutdown();
            }

            return microtime(true);
        },
        diagnosticListener: static function (string $event) use (&$handle, $client, $fixture, $workflowId, $queue, $phase, $request): void {
            if ($event === 'worker.registered') {
                $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $queue, [$fixture['input']]);
                if ($phase === 'before_claim') {
                    $request();
                }
            }
        }))
        ->registerWorkflow('parity.v1.cooperative_cleanup', static function (WorkflowContext $context, array $value): array {
            try {
                $context->sleep(60);
            } catch (WorkflowCancelled $error) {
                $snapshot = $error->context?->toArray() ?? throw new RuntimeException('Canonical root context is missing.');

                return $context->cancellationShield(static function () use ($context, $value, $snapshot): array {
                    $context->sleep($value['cleanup_delay_seconds']);

                    return $context->activity('parity.v1.cooperative_cleanup_activity', [$value, $snapshot]);
                });
            }

            throw new RuntimeException('The original timer unexpectedly completed without cancellation.');
        })
        ->registerActivity('parity.v1.cooperative_cleanup_activity', static function (ActivityContext $context, array $value, array $snapshot): array {
            $context->heartbeat(['request_id' => $snapshot['request_id']]);

            return ['value' => $value, 'cancellation' => $snapshot];
        });
    $worker->run(0);
    $execution = $handle->describeSelectedRun();
    $events = httpHistory($client, $workflowId, $handle->selectedRunId);
    $postTerminalDuplicate = $client->requestWorkflowCancellation($workflowId, 'post-terminal replacement', 3600, $handle->selectedRunId);
    $outcome = null;
    try {
        $handle->resultOfSelectedRun(1);
    } catch (WorkflowCancelled $error) {
        $outcome = ['type' => $error::class, 'message' => $error->getMessage()];
    }
    $fresh = new Client($url, namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null, workerProtocolVersion: '1.20');

    return ['execution' => $execution->raw, 'workflow_id' => $execution->workflowId, 'run_id' => $execution->runId,
        'workflow_type' => $execution->workflowType, 'namespace' => $execution->namespace, 'task_queue' => $execution->taskQueue,
        'status' => $execution->status, 'payload_codec' => $execution->raw['payload_codec'] ?? null,
        'input' => $execution->input, 'output' => $execution->output,
        'events' => decodeHistory($events, $client->payloadCodec()->decodeEnvelope(...)),
        'cooperative' => ['before' => $before, 'accepted' => $accepted, 'after_request' => $afterRequest, 'pending_duplicate' => $pendingDuplicate,
            'history_before_pending_duplicate' => $historyBeforePendingDuplicate, 'history_after_pending_duplicate' => $historyAfterPendingDuplicate,
            'post_terminal_duplicate' => $postTerminalDuplicate, 'history_before_terminal_duplicate' => $events,
            'history_after_terminal_duplicate' => httpHistory($client, $workflowId, $handle->selectedRunId),
            'outcome' => $outcome, 'traffic' => $traffic,
            'fresh_execution' => $fresh->describeWorkflow($workflowId, $handle->selectedRunId)->raw,
            'fresh_history' => httpHistory($fresh, $workflowId, $handle->selectedRunId)]];
}

function embeddedCooperativeReceipt($command): array
{
    return ['command' => \Workflow\V2\Support\CommandResponse::payload($command),
        'context' => $command->cancellationContext()?->toArray()];
}

function embeddedCooperativeObservation(array $fixture, string $workflowId, string $namespace, string $queue, string $root): array
{
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (config('server.mode') === 'service') {
        throw new RuntimeException('Embedded parity requires DW_MODE=embedded before application bootstrap.');
    }
    require __DIR__.'/embedded-types.php';
    config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
        'workflows.v2.types.workflows' => ['parity.v1.cooperative_cleanup' => \ServerParity\CooperativeCleanupWorkflow::class],
        'workflows.v2.types.activities' => ['parity.v1.cooperative_cleanup_activity' => \ServerParity\CooperativeCleanupActivity::class]]);
    foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class] as $model) {
        $model::creating(static function ($row) use ($namespace): void { $row->namespace = $namespace; });
    }
    $stub = WorkflowStub::make(\ServerParity\CooperativeCleanupWorkflow::class, $workflowId);
    $stub->start($fixture['input'], new WorkflowOptions(connection: 'database', queue: $queue),
        new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
    $runId = $stub->runId();
    if ($fixture['cooperative_cancellation']['phase'] === 'pending_timer') {
        $initialDeadline = microtime(true) + 10;
        do {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--sleep' => 0, '--tries' => 1]);
            $initialHistory = embeddedCancellationHistory($runId);
            if (array_filter($initialHistory, static fn (array $event): bool => $event['event_type'] === 'TimerScheduled') !== []) {
                break;
            }
            if (microtime(true) >= $initialDeadline) {
                throw new RuntimeException('The original embedded timer was not durably scheduled before cancellation.');
            }
        } while (true);
    }
    $before = embeddedCancellationHistory($runId);
    $selected = WorkflowStub::loadRun($runId, $namespace);
    $accepted = embeddedCooperativeReceipt($selected->attemptRequestCancellation(
        $fixture['cooperative_cancellation']['reason'], $fixture['cooperative_cancellation']['cleanup_timeout_seconds']));
    $afterRequest = WorkflowRun::query()->findOrFail($runId)->toArray();
    $historyBeforePendingDuplicate = embeddedCancellationHistory($runId);
    $pendingDuplicate = embeddedCooperativeReceipt($selected->attemptRequestCancellation('replacement reason', 300));
    $historyAfterPendingDuplicate = embeddedCancellationHistory($runId);
    $watchdog = [];
    $deadline = microtime(true) + 30;
    do {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
            '--max-time' => 2, '--sleep' => 0, '--tries' => 1]);
        // Execute the installed watchdog against actual wall-clock deadlines.
        // Never change time or construct a terminal event/row in this adapter.
        $watchdog[] = \Workflow\V2\TaskWatchdog::runPass(respectThrottle: false, runIds: [$runId]);
        $run = WorkflowRun::query()->findOrFail($runId);
        if ($run->status->isTerminal()) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Embedded cooperative cleanup exceeded its 30-second budget.');
        }
        usleep(250000);
    } while (true);
    $events = embeddedCancellationHistory($runId);
    $postTerminalDuplicate = embeddedCooperativeReceipt($selected->attemptRequestCancellation('post-terminal replacement', 3600));
    $fresh = WorkflowStub::loadRun($runId, $namespace);

    return ['execution' => $run->toArray(), 'worker_output' => Artisan::output(), 'workflow_id' => $run->workflow_instance_id,
        'run_id' => $run->id, 'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace, 'task_queue' => $run->queue,
        'status' => $run->status->value, 'payload_codec' => $run->payload_codec, 'input' => $run->workflowArguments(),
        'output' => $run->workflowOutput(), 'events' => decodeHistory($events,
            static fn ($value) => Serializer::unserializeWithCodec('avro', is_array($value) ? $value['blob'] : $value)),
        'cooperative' => ['before' => $before, 'accepted' => $accepted, 'after_request' => $afterRequest, 'pending_duplicate' => $pendingDuplicate,
            'history_before_pending_duplicate' => $historyBeforePendingDuplicate, 'history_after_pending_duplicate' => $historyAfterPendingDuplicate,
            'post_terminal_duplicate' => $postTerminalDuplicate, 'history_before_terminal_duplicate' => $events,
            'history_after_terminal_duplicate' => embeddedCancellationHistory($runId), 'watchdog' => $watchdog,
            'fresh_history' => embeddedCancellationHistory($runId),
            'delivery_history_event_id' => $run->historyEvents()->where('event_type', 'CooperativeCancellationDelivered')->value('id'),
            'fresh_execution' => ['id' => $fresh->runId(), 'status' => $fresh->status(), 'cancelled' => $fresh->cancelled(), 'output' => $fresh->output()],
            'failures' => $run->failures()->get()->map->toArray()->all(),
            'attempts' => $run->activityAttempts()->get()->map->toArray()->all(),
            'tasks' => $run->tasks()->get()->map->toArray()->all()]];
}
