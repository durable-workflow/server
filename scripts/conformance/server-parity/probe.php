<?php

declare(strict_types=1);
use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use ServerParity\EchoActivity;
use ServerParity\EchoWorkflow;
use ServerParity\OneActivityWorkflow;
use ServerParity\TimerWorkflow;
use ServerParity\SignalsWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

// Both adapters return observations; contract assertions live in the shared
// runner. This probe never bootstraps or migrates a target database.
$options = getopt('', ['mode:', 'fixture:', 'workflow-id:', 'application-root:', 'url:']);
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

function httpObservation(array $fixture, string $workflowId, string $namespace, string $queue, string $url): array
{
    $client = new Client($url, namespace: $namespace, token: getenv('DW_PARITY_TOKEN') ?: null);
    $handle = null;
    $deliveries = [];
    $deadline = microtime(true) + 30;
    $worker = null;
    $worker = (new Worker($client, $queue, clock: static function () use (&$worker, &$handle, &$deliveries, $client, $fixture, $workflowId, $deadline): float {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Parity worker exceeded its 30-second completion budget.');
        }
        if ($handle !== null && isset($fixture['signal_count']) && count($deliveries) < $fixture['signal_count']) {
            $page = $client->workflowHistory($workflowId, $handle->selectedRunId, 100);
            $opened = array_filter($page['events'], static fn (array $event): bool => $event['event_type'] === 'ConditionWaitOpened');
            if (count($opened) > count($deliveries)) {
                $before = $handle->describeSelectedRun()->raw;
                $response = $client->signalWorkflow($workflowId, 'payload', [$fixture['signal_value']], $handle->selectedRunId);
                $deliveries[] = ['before' => $before, 'response' => $response];
            }
        }
        if ($worker !== null && $handle !== null && $handle->describeSelectedRun()->isTerminal) {
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
        ->registerWorkflow('parity.v1.one_activity', static fn (WorkflowContext $context, array $value): array => $context->activity('parity.v1.echo_activity', [$value]))
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
        ->registerActivity('parity.v1.echo_activity', static fn (ActivityContext $context, array $value): array => $value);

    // run() performs real registration and task execution. The clock observes
    // real time and selected-run completion, without replacing runtime I/O.
    $worker->run(0);
    $execution = $handle->describeSelectedRun();
    $events = [];
    $tokens = [];
    $next = null;
    do {
        // A small page proves history pagination as well as event order.
        $page = $client->workflowHistory($workflowId, $handle->selectedRunId, 2, $next);
        array_push($events, ...$page['events']);
        $next = $page['next_page_token'] ?? null;
        if ($next !== null && $next !== '') {
            if (isset($tokens[$next])) {
                throw new RuntimeException('History pagination repeated a token.');
            }
            $tokens[$next] = true;
        }
    } while ($next !== null && $next !== '');

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
        ],
        'workflows.v2.types.activities' => ['parity.v1.echo_activity' => EchoActivity::class],
    ]);
    // Namespace belongs to the host Laravel application in embedded mode.
    foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class] as $model) {
        $model::creating(static function ($row) use ($namespace): void {
            $row->namespace = $namespace;
        });
    }
    $class = isset($fixture['signal_count']) ? SignalsWorkflow::class : (isset($fixture['timer_delays']) ? TimerWorkflow::class
        : ($fixture['activity'] ? OneActivityWorkflow::class : EchoWorkflow::class));
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
    $deadline = microtime(true) + 30;
    do {
        Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
            '--max-time' => 30, '--sleep' => 0, '--tries' => 1,
        ]);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        if (isset($fixture['signal_count']) && count($deliveries) < $fixture['signal_count']) {
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
    ];
}

try {
    $observation = match ($mode) {
        'http' => httpObservation($fixture, $workflowId, $namespace, $queue, $options['url']),
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
