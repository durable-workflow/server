<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

[$script, $root, $phase, $workflowId, $runId, $fixturePath] = $argv;
require $root.'/vendor/autoload.php';
require __DIR__.'/observation-values.php';
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$spec = $fixture['continuation'];
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('server.mode') !== 'embedded' || ! in_array($phase, $spec['phase_order'], true)) {
    throw new InvalidArgumentException('Unsupported embedded continuation phase');
}

final class ContinueEmbeddedState
{
    public static array $spec;

    public static string $activityQueue;

    public static int $activityInvocations = 0;
}

ContinueEmbeddedState::$activityQueue = $workflowId.'-activity';
ContinueEmbeddedState::$spec = $spec;

#[\Workflow\V2\Attributes\Type('parity.v1.continue_echo')]
final class ContinueEmbeddedActivity extends \Workflow\V2\Activity
{
    public function handle(mixed $value): mixed
    {
        ContinueEmbeddedState::$activityInvocations++;

        return $value;
    }
}

#[\Workflow\V2\Attributes\Type('parity.v1.continue_initial_memo')]
final class ContinueEmbeddedWorkflow extends \Workflow\V2\Workflow
{
    public function handle(array $input): mixed
    {
        if ($input['generation'] === 0) {
            $value = \Workflow\V2\activity(ContinueEmbeddedActivity::class,
                new ActivityOptions(queue: ContinueEmbeddedState::$activityQueue,
                    maxAttempts: 1, backoff: [], startToCloseTimeout: 120, scheduleToCloseTimeout: 600), $input['value']);
            if (isset(ContinueEmbeddedState::$spec['memo_before_continuation'])) {
                self::upsertMemo(ContinueEmbeddedState::$spec['memo_before_continuation']);
            }
            return self::continueAsNew(['generation' => 1, 'value' => $value]);
        }
        if ($input['generation'] !== 1) {
            throw new RuntimeException('Unexpected embedded continuation generation');
        }

        return $input['value'];
    }
}

config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
    // Core 2.15.12 (Server 2.11.8) resolves a run through its instance's
    // namespace, and reserves a caller-named instance without model events.
    'workflows.v2.namespace' => 'default',
    'workflows.v2.types.workflows' => [$fixture['workflow_type'] => ContinueEmbeddedWorkflow::class],
    'workflows.v2.types.activities' => ['parity.v1.continue_echo' => ContinueEmbeddedActivity::class]]);
foreach ([WorkflowInstance::class, WorkflowRun::class, \Workflow\V2\Models\WorkflowTask::class] as $model) {
    $model::creating(static function ($row): void { $row->namespace = 'default'; });
}
$snapshot = static function (string $selected) use ($workflowId): array {
    $run = WorkflowRun::query()->findOrFail($selected);
    $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
        'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
        'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload])->all();
    $events = decodeHistory($events, static fn ($envelope) => Serializer::unserializeWithCodec(
        'avro', is_array($envelope) ? $envelope['blob'] : $envelope));
    foreach ($events as &$event) {
        if ($event['event_type'] === 'MemoUpserted') {
            foreach (['entries', 'merged'] as $field) {
                $event['typed_decoded'][$field] = typedValue(Serializer::unserializeWithCodec('avro', $event['payload'][$field]['blob']));
            }
        }
    }
    unset($event);
    $memo = $run->typedMemos();
    ksort($memo);

    return ['workflow_id' => $workflowId, 'run_id' => $selected, 'workflow_type' => $run->workflow_type,
        'namespace' => $run->namespace, 'status' => $run->status->value, 'task_queue' => $run->queue,
        'payload_codec' => $run->payload_codec, 'execution' => $run->toArray(),
        'typed_input' => typedValue($run->workflowArguments()), 'typed_output' => typedValue($run->workflowOutput()),
        'typed_memo' => typedValue($memo), 'events' => $events];
};
$runList = static fn (): array => WorkflowRun::query()->where('workflow_instance_id', $workflowId)
    ->orderBy('run_number')->get()->map(static fn ($run): array => ['run_id' => $run->id, 'run_number' => $run->run_number])->all();
$work = static function (string $queue): string {
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue,
        '--once' => true, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--max-time' => 5]);

    return Artisan::output();
};
$deadline = microtime(true) + $spec['phase_budget_seconds'];
if ($phase === 'original') {
    $stub = WorkflowStub::make(ContinueEmbeddedWorkflow::class, $workflowId, 'default');
    $stub->start(['generation' => 0, 'value' => $fixture['input']],
        new WorkflowOptions(connection: 'database', queue: $workflowId.'-workflow'),
        new StartOptions(memo: $spec['initial_memo'], executionTimeoutSeconds: $spec['execution_timeout_seconds'],
            runTimeoutSeconds: $spec['run_timeout_seconds']));
    $runId = $stub->runId();
    $initial = $snapshot($runId);
} else {
    WorkflowStub::loadSelection($workflowId, $runId, 'default');
}
$workerOutput = [];
do {
    $workerOutput[] = $work($workflowId.($phase === 'activity' ? '-activity' : '-workflow'));
    $result = $snapshot($runId);
    $done = match ($phase) {
        'original' => in_array('ActivityScheduled', array_column($result['events'], 'event_type'), true),
        'activity' => in_array('ActivityCompleted', array_column($result['events'], 'event_type'), true),
        'continuation' => in_array('WorkflowContinuedAsNew', array_column($result['events'], 'event_type'), true),
        'successor' => $result['status'] === 'completed',
    };
    if (! $done && microtime(true) >= $deadline) {
        throw new RuntimeException('Original embedded continuation phase deadline expired');
    }
} while (! $done);
if ($phase === 'continuation') {
    $next = WorkflowInstance::query()->findOrFail($workflowId)->current_run_id;
    $inherited = $snapshot($next);
    $runsBefore = $runList();
    $taskId = $result['events'][count($result['events']) - 1]['payload']['task']['id'];
    $before = [$snapshot($runId), $snapshot($next)];
    \Workflow\V2\Jobs\RunWorkflowTask::dispatch($taskId)->onConnection('database')->onQueue($workflowId.'-duplicate');
    $duplicateOutput = $work($workflowId.'-duplicate');
    $duplicate = ['task_id' => $taskId, 'delivery' => 'real_database_queue', 'worker_output' => $duplicateOutput,
        'before' => $before, 'after' => [$snapshot($runId), $snapshot($next)],
        'current_after' => ['run_id' => WorkflowInstance::query()->findOrFail($workflowId)->current_run_id]];
    $runsAfter = $runList();
}
$loaded = [];
foreach (['VersionDecisions', 'WorkflowExecutor', 'WorkflowFiberRunner', 'QueryStateReplayer'] as $name) {
    $loaded['src/V2/Support/'.$name.'.php'] = hash_file('sha256', (new ReflectionClass('Workflow\\V2\\Support\\'.$name))->getFileName());
}
$actor = ['phase' => $phase, 'pid' => getmypid(), 'workflow_id' => $workflowId, 'run_id' => $runId,
    'activity_invocations' => ContinueEmbeddedState::$activityInvocations, 'worker_finished' => true,
    'workflow_package' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/workflow'), 'v'),
    'workflow_source' => InstalledVersions::getReference('durable-workflow/workflow'),
    'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'), 'workflow_loaded_sources' => $loaded,
    'definition_sha256' => hash_file('sha256', __FILE__), 'worker_output' => $workerOutput];
$currentRun = WorkflowInstance::query()->findOrFail($workflowId)->current_run_id;
$current = $snapshot($currentRun);
$startedEvents = array_values(array_filter($result['events'], static fn ($event): bool => $event['event_type'] === 'WorkflowStarted'));
$predecessor = $phase === 'successor'
    ? $snapshot($startedEvents[0]['payload']['continued_from_run_id']) : null;
echo json_encode($result + ['actor' => $actor, 'initial' => $initial ?? null,
    'inherited' => $inherited ?? null, 'duplicate' => $duplicate ?? null,
    'runs_before_duplicate' => $runsBefore ?? null, 'runs_after_duplicate' => $runsAfter ?? null,
    'runs_final' => $runList(), 'predecessor_final' => $predecessor,
    'current_final' => ['run_id' => $currentRun, 'status' => $current['status']]],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
