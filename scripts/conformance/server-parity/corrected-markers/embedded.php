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
require dirname(__DIR__).'/observation-values.php';
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('server.mode') !== 'embedded') {
    throw new RuntimeException('This actor requires a separately installed embedded application');
}
if (! in_array($phase, ['original', 'activity', 'replacement'], true)) {
    throw new InvalidArgumentException('Unknown embedded marker phase');
}

final class CorrectedEmbeddedState
{
    public static array $spec;

    public static string $phase;

    public static string $activityQueue;

    public static array $decisions = [];

    public static array $clocks = [];

    public static int $activityInvocations = 0;
}

CorrectedEmbeddedState::$spec = $fixture['corrected_markers'];
CorrectedEmbeddedState::$phase = $phase;
CorrectedEmbeddedState::$activityQueue = $workflowId.'-activity';

#[\Workflow\V2\Attributes\Type('parity.v1.echo_activity')]
final class CorrectedEmbeddedActivity extends \Workflow\V2\Activity
{
    public function handle(mixed $value): mixed
    {
        CorrectedEmbeddedState::$activityInvocations++;

        return $value;
    }
}

#[\Workflow\V2\Attributes\Type('parity.v1.corrected_markers')]
final class CorrectedEmbeddedWorkflow extends \Workflow\V2\Workflow
{
    public function handle(mixed $value): array
    {
        $spec = CorrectedEmbeddedState::$spec;
        $original = CorrectedEmbeddedState::$phase === 'original';
        $clocks = [];
        $clock = static function () use (&$clocks): void {
            $clocks[] = self::now()->toISOString();
        };
        if ($spec['case'] === 'genuine_one' && $original) {
            $first = self::getVersion('corrected-one', 0, 1);
            $clock();
            $second = self::getVersion('corrected-one', 1, 1);
            $clock();
            $projection = [$first, $second];
        } elseif (in_array($spec['case'], ['numeric', 'full_retry'], true)) {
            [$firstBounds, $secondBounds] = $original ? [[-3, 7], [-3, 7]] : [[-10, 10], [5, 20]];
            $first = self::getVersion('corrected-numeric', ...$firstBounds);
            $clock();
            $second = self::getVersion('corrected-numeric', ...$secondBounds);
            $clock();
            $projection = [$first, $second];
        } elseif ($spec['case'] === 'zero') {
            $first = self::getVersion('corrected-zero', -7, 0);
            $clock();
            $patch = self::patched('corrected-zero');
            $clock();
            $deprecated = self::deprecatePatch('corrected-zero');
            $clock();
            $wider = self::getVersion('corrected-zero', 0, 10);
            $clock();
            $repeat = self::patched('corrected-zero');
            $clock();
            $projection = [$first, $patch, $deprecated, $wider, $repeat];
        } elseif (in_array($spec['case'], ['deprecated', 'genuine_one'], true)) {
            $deprecated = self::deprecatePatch('corrected-one');
            $clock();
            $patch = self::patched('corrected-one');
            $clock();
            $version = self::getVersion('corrected-one', 0, 10);
            $clock();
            $repeated = self::deprecatePatch('corrected-one');
            $clock();
            $projection = [$deprecated, $patch, $version, $repeated];
        } else {
            throw new InvalidArgumentException('Embedded marker case is not implemented');
        }
        CorrectedEmbeddedState::$decisions[] = $projection;
        CorrectedEmbeddedState::$clocks[] = $clocks;
        $options = new ActivityOptions(queue: CorrectedEmbeddedState::$activityQueue,
            maxAttempts: 1, backoff: $spec['retry_policy'] === 'php_full' ? [1, 2, 5, 10, 15, 30, 60, 120] : [],
            startToCloseTimeout: 120, scheduleToCloseTimeout: 600);

        return ['projection' => $projection, 'typed' => \Workflow\V2\activity(CorrectedEmbeddedActivity::class, $options, $value)];
    }
}

config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
    'workflows.v2.types.workflows' => ['parity.v1.corrected_markers' => CorrectedEmbeddedWorkflow::class],
    'workflows.v2.types.activities' => ['parity.v1.echo_activity' => CorrectedEmbeddedActivity::class]]);
foreach ([\Workflow\V2\Models\WorkflowInstance::class, WorkflowRun::class, \Workflow\V2\Models\WorkflowTask::class] as $model) {
    $model::creating(static function ($row): void { $row->namespace = 'default'; });
}
$deadline = microtime(true) + 30;
if ($phase === 'original') {
    $stub = WorkflowStub::make(CorrectedEmbeddedWorkflow::class, $workflowId, 'default');
    $stub->start($fixture['input'], new WorkflowOptions(connection: 'database', queue: $workflowId.'-workflow'),
        new StartOptions(executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200));
    $runId = $stub->runId();
} else {
    $stub = WorkflowStub::loadSelection($workflowId, $runId, 'default');
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
        throw new RuntimeException('Original 30-second embedded phase observation expired');
    }
} while (! $done);
$events = decodeHistory($events, static fn ($envelope) => \Workflow\Serializers\Serializer::unserializeWithCodec(
    'avro', is_array($envelope) ? $envelope['blob'] : $envelope));
$loaded = [];
$version = InstalledVersions::getPrettyVersion('durable-workflow/workflow');
foreach ([$version === '2.5.6' ? 'VersionResolver' : 'VersionDecisions', 'WorkflowExecutor', 'WorkflowFiberRunner', 'QueryStateReplayer'] as $name) {
    $loaded['src/V2/Support/'.$name.'.php'] = hash_file('sha256', (new ReflectionClass('Workflow\\V2\\Support\\'.$name))->getFileName());
}
echo json_encode(['phase' => $phase, 'pid' => getmypid(), 'workflow_id' => $workflowId, 'run_id' => $runId,
    'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace, 'task_queue' => $run->queue,
    'status' => $run->status->value, 'payload_codec' => $run->payload_codec, 'execution' => $run->toArray(),
    'input' => $run->workflowArguments(), 'output' => $run->workflowOutput(),
    'typed_input' => typedValue($run->workflowArguments()), 'typed_output' => typedValue($run->workflowOutput()),
    'events' => $events, 'decisions' => CorrectedEmbeddedState::$decisions,
    'clocks' => CorrectedEmbeddedState::$clocks, 'activity_invocations' => CorrectedEmbeddedState::$activityInvocations,
    'tasks' => $run->tasks()->orderBy('created_at')->orderBy('id')->get()->map->toArray()->all(),
    'worker_output' => $workerOutput, 'definition_sha256' => hash_file('sha256', __FILE__),
    'workflow_package' => $version,
    'workflow_source' => InstalledVersions::getReference('durable-workflow/workflow'),
    'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
    'workflow_loaded_sources' => $loaded,
    'actor' => ['pid' => getmypid(), 'workflow_id' => $workflowId, 'run_id' => $runId,
        'mode' => $phase === 'activity' ? 'activity' : 'workflow', 'case' => $fixture['corrected_markers']['case'],
        'range_profile' => $phase === 'replacement' ? 'wider' : 'original',
        'retry_policy' => $fixture['corrected_markers']['retry_policy'],
        'decisions' => CorrectedEmbeddedState::$decisions, 'worker_finished' => true]],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
