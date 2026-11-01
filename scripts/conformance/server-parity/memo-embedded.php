<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

[$script, $root, $phase, $workflowId, $runId, $fixturePath] = $argv;
require $root.'/vendor/autoload.php';
require __DIR__.'/observation-values.php';
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('server.mode') !== 'embedded' || ! in_array($phase, $fixture['memo_replay']['phase_order'], true)) {
    throw new InvalidArgumentException('Memo actor requires a supported embedded phase');
}

final class MemoEmbeddedState
{
    public static array $spec;

    public static string $activityQueue;

    public static int $activityInvocations = 0;
}

MemoEmbeddedState::$spec = $fixture['memo_replay'];
MemoEmbeddedState::$activityQueue = $workflowId.'-activity';

#[\Workflow\V2\Attributes\Type('parity.v1.memo_echo')]
final class MemoEmbeddedActivity extends \Workflow\V2\Activity
{
    public function handle(mixed $value): mixed
    {
        MemoEmbeddedState::$activityInvocations++;

        return $value;
    }
}

#[\Workflow\V2\Attributes\Type('parity.v1.memo_activity')]
final class MemoEmbeddedWorkflow extends \Workflow\V2\Workflow
{
    public function handle(mixed $value): mixed
    {
        $spec = MemoEmbeddedState::$spec;
        if (isset($spec['updates'][0])) {
            self::upsertMemo($spec['updates'][0]);
        }
        $result = \Workflow\V2\activity(MemoEmbeddedActivity::class,
            new ActivityOptions(queue: MemoEmbeddedState::$activityQueue, maxAttempts: 1, backoff: [],
                startToCloseTimeout: 120, scheduleToCloseTimeout: 600), $value);
        if (isset($spec['updates'][1])) {
            self::upsertMemo($spec['updates'][1]);
        }

        return $result;
    }
}

config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
    // Core 2.15.12 (Server 2.11.8) resolves a run through its instance's
    // namespace, and reserves a caller-named instance without model events.
    'workflows.v2.namespace' => 'default',
    'workflows.v2.types.workflows' => [$fixture['workflow_type'] => MemoEmbeddedWorkflow::class],
    'workflows.v2.types.activities' => ['parity.v1.memo_echo' => MemoEmbeddedActivity::class]]);
foreach ([\Workflow\V2\Models\WorkflowInstance::class, WorkflowRun::class, \Workflow\V2\Models\WorkflowTask::class] as $model) {
    $model::creating(static function ($row): void { $row->namespace = 'default'; });
}
$deadline = microtime(true) + $fixture['memo_replay']['phase_budget_seconds'];
if ($phase === 'original') {
    $stub = WorkflowStub::make(MemoEmbeddedWorkflow::class, $workflowId, 'default');
    $stub->start($fixture['input'], new WorkflowOptions(connection: 'database', queue: $workflowId.'-workflow'),
        new StartOptions(memo: $fixture['memo_replay']['initial'], executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200));
    $runId = $stub->runId();
    $initial = WorkflowRun::query()->findOrFail($runId)->typedMemos();
    ksort($initial);
} else {
    WorkflowStub::loadSelection($workflowId, $runId, 'default');
}
$workerOutput = [];
do {
    Artisan::call('queue:work', ['connection' => 'database',
        '--queue' => $workflowId.($phase === 'activity' ? '-activity' : '-workflow'),
        '--once' => true, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--max-time' => 5]);
    $workerOutput[] = Artisan::output();
    $run = WorkflowRun::query()->findOrFail($runId);
    $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
        'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
        'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload])->all();
    $done = match ($phase) {
        'original' => in_array('ActivityScheduled', array_column($events, 'event_type'), true),
        'activity' => in_array('ActivityCompleted', array_column($events, 'event_type'), true),
        'replacement' => $run->status->isTerminal(),
    };
    if (! $done && microtime(true) >= $deadline) {
        throw new RuntimeException('Original 30-second embedded memo phase expired');
    }
} while (! $done);
$events = decodeHistory($events, static fn ($envelope) => \Workflow\Serializers\Serializer::unserializeWithCodec(
    'avro', is_array($envelope) ? $envelope['blob'] : $envelope));
foreach ($events as &$event) {
    if (in_array($event['event_type'], ['StartAccepted', 'WorkflowStarted'], true)) {
        $event['typed_decoded']['initial_memo'] = typedValue($event['payload']['memo']);
    }
    if ($event['event_type'] === 'MemoUpserted') {
        foreach (['entries', 'merged'] as $field) {
            $event['typed_decoded'][$field] = typedValue(\Workflow\V2\Support\MemoPayload::decodeEntries($event['payload'][$field]));
        }
    }
}
unset($event);
$memo = $run->typedMemos();
ksort($memo);
$loaded = [];
foreach (['VersionDecisions', 'WorkflowExecutor', 'WorkflowFiberRunner', 'QueryStateReplayer'] as $name) {
    $loaded['src/V2/Support/'.$name.'.php'] = hash_file('sha256', (new ReflectionClass('Workflow\\V2\\Support\\'.$name))->getFileName());
}
echo json_encode(['phase' => $phase, 'pid' => getmypid(), 'workflow_id' => $workflowId, 'run_id' => $runId,
    'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace, 'status' => $run->status->value,
    'task_queue' => $run->queue, 'payload_codec' => $run->payload_codec, 'execution' => $run->toArray(),
    'input' => $run->workflowArguments(), 'output' => $run->workflowOutput(),
    'typed_input' => typedValue($run->workflowArguments()), 'typed_output' => typedValue($run->workflowOutput()),
    'typed_memo' => typedValue($memo), 'typed_initial_memo' => isset($initial) ? typedValue($initial) : null,
    'events' => $events, 'worker_output' => $workerOutput, 'activity_invocations' => MemoEmbeddedState::$activityInvocations,
    'workflow_package' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/workflow'), 'v'),
    'workflow_source' => InstalledVersions::getReference('durable-workflow/workflow'),
    'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'), 'workflow_loaded_sources' => $loaded,
    'definition_sha256' => hash_file('sha256', __FILE__), 'worker_finished' => true],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
