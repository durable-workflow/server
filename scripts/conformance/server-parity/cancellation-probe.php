<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\WorkflowCancelled;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;
use Workflow\V2\StartOptions;
use Workflow\WorkflowOptions;

// Application callbacks use only the published APIs. These observations never
// mutate engine rows or synthesize a claim, cancellation or terminal outcome.
final class CancellationProbeState
{
    public static string $reason;
    public static ?array $receipt = null;
    public static array $before = [];
    public static array $claims = [];
}

function cancellationReceipt(callable $submit): array
{
    try {
        return ['status' => 200, 'response' => $submit()];
    } catch (ServerException $error) {
        return ['status' => $error->status, 'response' => $error->details];
    }
}

function httpCancellationObservation(array $fixture, string $workflowId, string $namespace, string $queue, string $url): array
{
    $polls = $completions = $activityPolls = $activityOutcomes = [];
    $client = new Client($url, namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: observedChildTransport($polls, $completions, $activityPolls, $activityOutcomes));
    $phase = $fixture['immediate_cancellation']['phase'];
    $reason = $fixture['immediate_cancellation']['reason'];
    $handle = $worker = null;
    $before = [];
    $receipt = null;
    $deadline = microtime(true) + 30;
    $cancel = static function () use ($client, $workflowId, $reason, &$handle, &$before, &$receipt): void {
        $before = httpHistory($client, $workflowId, $handle->selectedRunId);
        $receipt = cancellationReceipt(static fn (): array => $client->cancelWorkflow($workflowId, $reason, $handle->selectedRunId));
    };
    $worker = (new Worker($client, $queue,
        clock: static function () use (&$worker, &$handle, &$receipt, $client, $workflowId, $phase, $cancel, $deadline): float {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Cancellation worker exceeded its 30-second budget.');
            }
            if ($handle !== null && $receipt === null && $phase === 'pending_timer') {
                $events = httpHistory($client, $workflowId, $handle->selectedRunId);
                if (array_filter($events, static fn (array $event): bool => $event['event_type'] === 'TimerScheduled') !== []) {
                    $cancel();
                }
            }
            if ($handle !== null && $handle->describeSelectedRun()->isTerminal) {
                $worker->requestShutdown();
            }

            return microtime(true);
        },
        diagnosticListener: static function (string $event) use (&$handle, $client, $fixture, $workflowId, $queue, $phase, $cancel): void {
            if ($event === 'worker.registered') {
                $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $queue, [$fixture['input']]);
                if ($phase === 'before_claim') {
                    $cancel();
                }
            }
        }))
        ->registerWorkflow('parity.v1.cancel_before_claim', static fn (WorkflowContext $context, array $value): array => $value)
        ->registerWorkflow('parity.v1.cancel_pending_timer', static function (WorkflowContext $context, array $value) use ($fixture): array {
            $context->sleep($fixture['immediate_cancellation']['delay_seconds'] ?? 60);

            return $value;
        })
        ->registerWorkflow('parity.v1.cancel_leased_activity', static fn (WorkflowContext $context, array $value): array => $context->activity('parity.v1.cancel_activity', [$value]))
        ->registerActivity('parity.v1.cancel_activity', static function (ActivityContext $context, array $value) use ($cancel): array {
            $cancel();

            return $value; // The normal SDK worker must discard its stale result.
        });
    $worker->run(0);
    $workerActivityOutcomes = $activityOutcomes;
    $execution = $handle->describeSelectedRun();
    $events = httpHistory($client, $workflowId, $handle->selectedRunId);
    $duplicate = cancellationReceipt(static fn (): array => $client->cancelWorkflow($workflowId, 'replacement reason', $handle->selectedRunId));
    $afterDuplicate = httpHistory($client, $workflowId, $handle->selectedRunId);
    $outcome = null;
    try {
        $handle->resultOfSelectedRun(1);
    } catch (WorkflowCancelled $error) {
        $outcome = ['type' => $error::class, 'message' => $error->getMessage()];
    }
    $late = null;
    if ($activityPolls !== []) {
        $task = $activityPolls[0];
        $late = cancellationReceipt(static fn (): array => $client->completeActivityTask($task['task_id'],
            $task['activity_attempt_id'], $task['lease_owner'], $fixture['input']));
    }
    // A separate published Client reloads the same durable run and history.
    $fresh = new Client($url, namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null);
    $inspectionWorker = $workflowId.':inspection';
    $fresh->registerWorker($inspectionWorker, $queue, [$fixture['workflow_type']], ['parity.v1.cancel_activity']);
    $remaining = ['workflow' => $fresh->pollWorkflowTask($inspectionWorker, $queue, 0),
        'activity' => $fresh->pollActivityTask($inspectionWorker, $queue, 0)];
    $fresh->deregisterWorker($inspectionWorker);

    return ['execution' => $execution->raw, 'workflow_id' => $execution->workflowId, 'run_id' => $execution->runId,
        'workflow_type' => $execution->workflowType, 'namespace' => $execution->namespace, 'task_queue' => $execution->taskQueue,
        'status' => $execution->status, 'payload_codec' => $execution->raw['payload_codec'] ?? null,
        'input' => $execution->input, 'output' => $execution->output,
        'events' => decodeHistory($events, $client->payloadCodec()->decodeEnvelope(...)),
        'workflow_polls' => $polls, 'workflow_completions' => $completions,
        'activity_polls' => $activityPolls, 'activity_outcomes' => $workerActivityOutcomes,
        'cancellation' => ['before' => $before, 'accepted' => $receipt, 'duplicate' => $duplicate,
            'history_before_duplicate' => $events, 'history_after_duplicate' => $afterDuplicate,
            'late_completion' => $late, 'outcome' => $outcome, 'remaining_tasks' => $remaining,
            'fresh_execution' => $fresh->describeWorkflow($workflowId, $handle->selectedRunId)->raw,
            'fresh_history' => httpHistory($fresh, $workflowId, $handle->selectedRunId)]];
}

function embeddedCancellationHistory(string $runId): array
{
    return WorkflowRun::query()->findOrFail($runId)->historyEvents()->orderBy('sequence')->get()
        ->map(static fn ($event): array => ['sequence' => $event->sequence, 'event_type' => $event->event_type->value,
            'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload])->all();
}

function embeddedCancellationReceipt($command): array
{
    return ['status' => $command->accepted() ? 200 : 409,
        'response' => \Workflow\V2\Support\CommandResponse::payload($command)];
}

function cancelEmbeddedRun(string $runId): void
{
    CancellationProbeState::$before = embeddedCancellationHistory($runId);
    CancellationProbeState::$receipt = embeddedCancellationReceipt(
        WorkflowStub::loadRun($runId, 'default')->attemptCancel(CancellationProbeState::$reason));
}

function embeddedCancellationObservation(array $fixture, string $workflowId, string $namespace, string $queue, string $root): array
{
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    require __DIR__.'/embedded-types.php';
    config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
        'workflows.v2.types.workflows' => [
            'parity.v1.cancel_before_claim' => \ServerParity\CancelBeforeClaimWorkflow::class,
            'parity.v1.cancel_pending_timer' => \ServerParity\CancelPendingTimerWorkflow::class,
            'parity.v1.cancel_leased_activity' => \ServerParity\CancelLeasedActivityWorkflow::class,
        ], 'workflows.v2.types.activities' => ['parity.v1.cancel_activity' => \ServerParity\CancelActivity::class]]);
    foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class] as $model) {
        $model::creating(static function ($row) use ($namespace): void { $row->namespace = $namespace; });
    }
    // Registered type keys contain dots, so look up the exact map key.
    $class = config('workflows.v2.types.workflows')[$fixture['workflow_type']];
    $stub = WorkflowStub::make($class, $workflowId);
    CancellationProbeState::$reason = $fixture['immediate_cancellation']['reason'];
    $stub->start($fixture['input'], new WorkflowOptions(connection: 'database', queue: $queue),
        new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
    $phase = $fixture['immediate_cancellation']['phase'];
    if ($phase === 'before_claim') {
        cancelEmbeddedRun($stub->runId());
    } elseif ($phase === 'pending_timer') {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--sleep' => 0, '--tries' => 1]);
        cancelEmbeddedRun($stub->runId());
    }
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
        '--max-time' => 10, '--sleep' => 0, '--tries' => 1]);
    $run = WorkflowRun::query()->findOrFail($stub->runId());
    $events = embeddedCancellationHistory($run->id);
    $duplicate = embeddedCancellationReceipt(WorkflowStub::loadRun($run->id, $namespace)->attemptCancel('replacement reason'));
    $afterDuplicate = embeddedCancellationHistory($run->id);
    // Redeliver original closed activity work through its actual queue job.
    foreach (CancellationProbeState::$claims as $taskId) {
        \Workflow\V2\Jobs\RunActivityTask::dispatch($taskId)->onConnection('database')->onQueue($queue);
    }
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
        '--max-time' => 10, '--sleep' => 0, '--tries' => 1]);
    $fresh = WorkflowStub::loadRun($run->id, $namespace);

    return ['execution' => $run->toArray(), 'worker_output' => Artisan::output(), 'workflow_id' => $run->workflow_instance_id,
        'run_id' => $run->id, 'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace, 'task_queue' => $run->queue,
        'status' => $run->status->value, 'payload_codec' => $run->payload_codec, 'input' => $run->workflowArguments(),
        'output' => $fresh->output(), 'events' => decodeHistory($events,
            static fn ($value) => Serializer::unserializeWithCodec('avro', is_array($value) ? $value['blob'] : $value)),
        'cancellation' => ['before' => CancellationProbeState::$before, 'accepted' => CancellationProbeState::$receipt,
            'duplicate' => $duplicate, 'history_before_duplicate' => $events, 'history_after_duplicate' => $afterDuplicate,
            'redelivered_task_ids' => CancellationProbeState::$claims, 'fresh_history' => embeddedCancellationHistory($run->id),
            'fresh_execution' => ['id' => $fresh->runId(), 'status' => $fresh->status(), 'cancelled' => $fresh->cancelled(), 'output' => $fresh->output()],
            'failures' => $run->failures()->get()->map->toArray()->all(),
            'attempts' => $run->activityAttempts()->get()->map->toArray()->all(),
            'tasks' => $run->tasks()->get()->map->toArray()->all()]];
}
