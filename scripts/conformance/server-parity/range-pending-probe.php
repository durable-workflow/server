<?php

declare(strict_types=1);

function rangePendingHttpCheckpoint(\DurableWorkflow\Client $client, array $options, string $runId): array
{
    $execution = $client->describeWorkflow($options['workflow-id'], $runId);
    $debug = (new \GuzzleHttp\Client(['http_errors' => false]))->get($options['url'].'/api/workflows/'
        .rawurlencode($options['workflow-id']).'/runs/'.rawurlencode($runId).'/debug', [
            'headers' => ['Authorization' => 'Bearer '.(getenv('DW_PARITY_TOKEN') ?: ''),
                'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'],
        ]);

    return ['workflow_id' => $options['workflow-id'], 'run_id' => $runId,
        'workflow_type' => $execution->workflowType, 'namespace' => $execution->namespace, 'task_queue' => $execution->taskQueue,
        'status' => $execution->status, 'payload_codec' => $execution->raw['payload_codec'],
        'input' => $execution->input, 'output' => $execution->output, 'execution' => $execution->raw,
        'events' => rangeDecodeHistory(httpHistory($client, $options['workflow-id'], $runId), $client->payloadCodec()->decodeEnvelope(...)),
        'debug_status' => $debug->getStatusCode(), 'debug' => json_decode((string) $debug->getBody(), true, flags: JSON_THROW_ON_ERROR)];
}

function rangePendingHttpPhase(array $fixture, array $options): array
{
    $phase = $options['range-phase'];
    $worker = null;
    $stage = $phase;
    $requests = $diagnostics = $checkpoints = [];
    $stack = \GuzzleHttp\HandlerStack::create();
    $stack->push(static function (callable $next) use ($phase, &$stage, &$worker, &$requests): callable {
        return static function (\Psr\Http\Message\RequestInterface $request, array $transportOptions) use ($next, $phase, &$stage, &$worker, &$requests) {
            return $next($request, $transportOptions)->then(static function (\Psr\Http\Message\ResponseInterface $response) use ($request, $phase, &$stage, &$worker, &$requests) {
                $path = $request->getUri()->getPath();
                $body = (string) $request->getBody();
                $requestBody = $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                $responseBody = (string) $response->getBody();
                $requests[] = ['stage' => $stage, 'method' => $request->getMethod(), 'path' => $path, 'request' => $requestBody,
                    'status' => $response->getStatusCode(),
                    'response' => $responseBody === '' ? null : json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR)];
                $workflow = str_starts_with($path, '/api/worker/workflow-tasks/');
                $activity = str_starts_with($path, '/api/worker/activity-tasks/');
                $commands = array_column($requestBody['commands'] ?? [], 'type');
                if ($response->getStatusCode() === 200 && $request->getMethod() === 'POST'
                    && ($phase === 'original' && $workflow && str_ends_with($path, '/complete') && in_array('schedule_activity', $commands, true)
                        || $phase === 'incompatible' && $workflow && str_ends_with($path, '/fail')
                        || $stage === 'repaired-wait' && $workflow && str_ends_with($path, '/fail')
                        || $stage === 'activity' && $activity && str_ends_with($path, '/complete')
                        || $stage === 'final' && $workflow && str_ends_with($path, '/complete') && in_array('complete_workflow', $commands, true))) {
                    $worker?->requestShutdown();
                }

                return $response;
            });
        };
    });
    $client = new \DurableWorkflow\Client($options['url'], namespace: 'default',
        token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: new \DurableWorkflow\Transport\Psr18Transport(client: new \GuzzleHttp\Client(['http_errors' => false, 'handler' => $stack])));
    $runId = $phase === 'original'
        ? $client->startWorkflow($fixture['workflow_type'], $options['workflow-id'], 'server-parity-v1', [$fixture['input']])->selectedRunId
        : $options['run-id'];
    $repair = $phase === 'corrected-repair' ? $client->repairWorkflow($options['workflow-id'], $runId) : null;
    if ($repair !== null) {
        $checkpoints['after_repair'] = rangePendingHttpCheckpoint($client, $options, $runId);
    }
    $definition = __DIR__.'/range-pending-http-'.($phase === 'incompatible' ? 'incompatible' : 'original').'.php';
    $handler = require $definition;
    $error = null;
    foreach ($phase === 'corrected-repair' ? ['repaired-wait', 'activity', 'final'] : [$phase] as $stage) {
        $worker = new \DurableWorkflow\Worker($client,
            $stage === 'activity' ? $fixture['range_repair']['activity_queue'] : 'server-parity-v1',
            workerId: $options['workflow-id'].':'.$phase.':'.$stage, stickyCacheTtlSeconds: 1,
            clock: static function () use ($options): float {
                if (microtime(true) >= (float) $options['range-deadline']) {
                    throw new RuntimeException('Pending range author exceeded its original 35-second budget.');
                }

                return microtime(true);
            }, diagnosticListener: static function (string $event) use (&$diagnostics): void { $diagnostics[] = $event; });
        if ($stage === 'activity') {
            $worker->registerActivity('parity.v1.echo_activity', static function (\DurableWorkflow\Worker\ActivityContext $context, array $value): array {
                RangeRepairState::$activityInvocations++;

                return $value;
            });
        } else {
            $worker->registerWorkflow($fixture['workflow_type'], $handler);
        }
        try {
            $worker->run(1);
        } catch (Throwable $failure) {
            $error = ['class' => $failure::class, 'message' => $failure->getMessage()];
            break;
        }
        if ($stage === 'repaired-wait') {
            $checkpoints['after_waiting'] = rangePendingHttpCheckpoint($client, $options, $runId);
        }
    }
    $loaded = [];
    foreach ([\DurableWorkflow\Worker::class, \DurableWorkflow\Worker\WorkflowContext::class] as $class) {
        $loaded[$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
    }

    return rangePendingHttpCheckpoint($client, $options, $runId) + [
        'phase' => $phase, 'pid' => getmypid(), 'versions' => RangeRepairState::$versions,
        'selections' => RangeRepairState::$selections, 'activity_invocations' => RangeRepairState::$activityInvocations,
        'definition_sha256' => hash_file('sha256', $definition), 'requests' => $requests, 'diagnostics' => $diagnostics,
        'worker_error' => $error, 'repair' => $repair, 'checkpoints' => $checkpoints, 'sdk_loaded_sources' => $loaded];
}

function rangePendingEmbeddedCheckpoint(string $runId): array
{
    $run = \Workflow\V2\Models\WorkflowRun::query()->findOrFail($runId);
    $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
        'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
        'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload])->all();

    return ['workflow_id' => $run->workflow_instance_id, 'run_id' => $run->id,
        'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace, 'task_queue' => $run->queue,
        'status' => $run->status->value, 'payload_codec' => $run->payload_codec,
        'input' => $run->workflowArguments(), 'output' => $run->workflowOutput(), 'execution' => $run->toArray(),
        'events' => rangeDecodeHistory($events, static fn ($value) => \Workflow\Serializers\Serializer::unserializeWithCodec('avro', is_array($value) ? $value['blob'] : $value)),
        'tasks' => $run->tasks()->orderBy('created_at')->orderBy('id')->get()->map->toArray()->all(),
        'failures' => $run->failures()->get()->map->toArray()->all()];
}

function rangePendingEmbeddedPhase(array $fixture, array $options): array
{
    $root = $options['application-root'];
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('server.mode') !== 'embedded') {
        throw new RuntimeException('Pending range fixture requires the embedded application mode.');
    }
    #[\Workflow\V2\Attributes\Type('parity.v1.echo_activity')]
    class RangeRepairActivity extends \Workflow\V2\Activity
    {
        public function handle(array $value): array
        {
            RangeRepairState::$activityInvocations++;

            return $value;
        }
    }
    $phase = $options['range-phase'];
    $definition = __DIR__.'/range-pending-embedded-'.($phase === 'incompatible' ? 'incompatible' : 'original').'.php';
    require $definition;
    config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
        // Core 2.15.12 (Server 2.11.8) resolves a run through its instance's
        // namespace, and reserves a caller-named instance without model events.
        'workflows.v2.namespace' => 'default',
        'workflows.v2.types.workflows' => [$fixture['workflow_type'] => RangeRepairWorkflow::class],
        'workflows.v2.types.activities' => ['parity.v1.echo_activity' => RangeRepairActivity::class]]);
    foreach ([\Workflow\V2\Models\WorkflowInstance::class, \Workflow\V2\Models\WorkflowRun::class,
        \Workflow\V2\Models\WorkflowTask::class] as $model) {
        $model::creating(static function ($row): void { $row->namespace = 'default'; });
    }
    if ($phase === 'original') {
        $stub = \Workflow\V2\WorkflowStub::make(RangeRepairWorkflow::class, $options['workflow-id'], 'default');
        $stub->start($fixture['input'], new \Workflow\WorkflowOptions(connection: 'database', queue: 'server-parity-v1'),
            new \Workflow\V2\StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
    } else {
        $stub = \Workflow\V2\WorkflowStub::loadSelection($options['workflow-id'], $options['run-id'], 'default');
    }
    $repair = $phase === 'corrected-repair' ? $stub->attemptRepair() : null;
    $checkpoints = $workerOutput = [];
    $work = static function (string $queue) use (&$workerOutput): void {
        \Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue,
            '--once' => true, '--stop-when-empty' => true, '--max-time' => 5, '--sleep' => 0, '--tries' => 1]);
        $workerOutput[] = ['queue' => $queue, 'output' => \Illuminate\Support\Facades\Artisan::output()];
    };
    if ($repair !== null) {
        $checkpoints['after_repair'] = rangePendingEmbeddedCheckpoint($stub->runId());
        $work('server-parity-v1');
        $checkpoints['after_waiting'] = rangePendingEmbeddedCheckpoint($stub->runId());
        $work($fixture['range_repair']['activity_queue']);
    }
    do {
        $work('server-parity-v1');
        $observation = rangePendingEmbeddedCheckpoint($stub->runId());
        $done = $phase === 'original' ? in_array('TimerScheduled', array_column($observation['events'], 'event_type'), true)
            : in_array($observation['status'], ['completed', 'failed', 'cancelled', 'terminated'], true)
                || array_filter($observation['tasks'], static fn ($task): bool => ($task['payload']['replay_blocked'] ?? false) === true);
        if (! $done && microtime(true) >= (float) $options['range-deadline']) {
            throw new RuntimeException('Embedded pending range author exceeded its original 35-second budget.');
        }
        if (! $done) {
            usleep(100000);
        }
    } while (! $done);
    $loaded = [];
    foreach (['VersionResolver', 'WorkflowExecutor', 'TaskRepair'] as $name) {
        $loaded['src/V2/Support/'.$name.'.php'] = hash_file('sha256', (new ReflectionClass('Workflow\\V2\\Support\\'.$name))->getFileName());
    }
    $provenance = $root.'/vendor/durable-workflow/workflow/.package-provenance';

    return $observation + ['phase' => $phase, 'pid' => getmypid(),
        'versions' => RangeRepairState::$versions, 'selections' => RangeRepairState::$selections,
        'activity_invocations' => RangeRepairState::$activityInvocations,
        'definition_sha256' => hash_file('sha256', $definition), 'worker_output' => $workerOutput,
        'checkpoints' => $checkpoints,
        'repair' => $repair === null ? null : ['accepted' => $repair->accepted(), 'outcome' => $repair->outcome(),
            'run_id' => $repair->runId(), 'resolved_run_id' => $repair->resolvedRunId(), 'command_id' => $repair->commandId()],
        'workflow_package' => \Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
        'workflow_source' => \Composer\InstalledVersions::getReference('durable-workflow/workflow'),
        'workflow_provenance' => is_file($provenance) ? file($provenance, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : null,
        'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'), 'workflow_loaded_sources' => $loaded];
}
