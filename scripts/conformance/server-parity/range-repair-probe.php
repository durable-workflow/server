<?php

declare(strict_types=1);

final class RangeRepairState
{
    public static string $changeId;

    public static array $versions = [];

    public static array $selections = [];

    public static int $activityInvocations = 0;
}

function rangeDecodeHistory(array $events, callable $decode): array
{
    $events = decodeHistory($events, $decode);
    foreach ($events as &$event) {
        if ($event['event_type'] === 'RepairRequested') {
            $event['decoded']['repair_command'] = $decode($event['payload']['command']['payload']);
            $event['typed_decoded']['repair_command'] = typedValue($event['decoded']['repair_command']);
        }
    }

    return $events;
}

function rangeRepairObservation(array $fixture, array $options): array
{
    $phases = [];
    $deadline = microtime(true) + 35;
    foreach (['original', 'incompatible', 'corrected-repair'] as $phase) {
        $arguments = [PHP_BINARY, __DIR__.'/probe.php', '--mode', $options['mode'],
            '--fixture', $options['fixture'], '--workflow-id', $options['workflow-id'],
            '--range-phase', $phase, '--range-deadline', (string) $deadline];
        foreach (['url', 'application-root'] as $key) {
            if (isset($options[$key])) {
                array_push($arguments, '--'.$key, $options[$key]);
            }
        }
        if ($phase !== 'original') {
            array_push($arguments, '--run-id', $phases[0]['run_id']);
        }
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the cold range author.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
            if (is_string($directory) && is_dir($directory)) {
                $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
                file_put_contents($directory.'/failure-'.$name.'-range.json', json_encode([
                    'failed_phase' => $phase, 'completed_phases' => $phases,
                    'error' => trim($stderr),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            }
            throw new RuntimeException('Range '.$phase.' author failed: '.trim($stderr));
        }
        $phases[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }
    $observation = $phases[2];
    $observation['range_repair'] = ['checkpoint' => $fixture['range_repair']['checkpoint'],
        'change_id' => $fixture['range_repair']['change_id'],
        'original' => $phases[0], 'incompatible' => $phases[1], 'corrected' => $phases[2]];

    return $observation;
}

function rangeHttpPhase(array $fixture, array $options): array
{
    if ($fixture['range_repair']['pending_activity'] ?? false) {
        require __DIR__.'/range-pending-probe.php';

        return rangePendingHttpPhase($fixture, $options);
    }
    $phase = $options['range-phase'];
    $worker = null;
    $requests = $diagnostics = [];
    $stack = \GuzzleHttp\HandlerStack::create();
    $stack->push(static function (callable $next) use ($phase, &$worker, &$requests): callable {
        return static function (\Psr\Http\Message\RequestInterface $request, array $transportOptions) use ($next, $phase, &$worker, &$requests) {
            return $next($request, $transportOptions)->then(static function (\Psr\Http\Message\ResponseInterface $response) use ($request, $phase, &$worker, &$requests) {
                $path = $request->getUri()->getPath();
                $body = (string) $request->getBody();
                $requestBody = $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                $responseBody = (string) $response->getBody();
                $requests[] = ['method' => $request->getMethod(), 'path' => $path, 'request' => $requestBody,
                    'status' => $response->getStatusCode(),
                    'response' => $responseBody === '' ? null : json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR)];
                $commands = array_column($requestBody['commands'] ?? [], 'type');
                if ($response->getStatusCode() === 200 && $request->getMethod() === 'POST'
                    && str_starts_with($path, '/api/worker/workflow-tasks/')
                    && ($phase === 'original' && str_ends_with($path, '/complete') && in_array('schedule_activity', $commands, true)
                        || $phase === 'incompatible' && str_ends_with($path, '/fail')
                        || $phase === 'corrected-repair' && str_ends_with($path, '/complete') && in_array('complete_workflow', $commands, true))) {
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
    $worker = new \DurableWorkflow\Worker($client, 'server-parity-v1', workerId: $options['workflow-id'].':'.$phase,
        stickyCacheTtlSeconds: 1, clock: static function () use ($options): float {
            if (microtime(true) >= (float) $options['range-deadline']) {
                throw new RuntimeException('Range author exceeded its original 35-second budget.');
            }

            return microtime(true);
        }, diagnosticListener: static function (string $event) use (&$diagnostics): void { $diagnostics[] = $event; });
    $definition = __DIR__.'/range-repair-http-'.($phase === 'incompatible' ? 'incompatible' : 'original').'.php';
    $worker->registerWorkflow($fixture['workflow_type'], require $definition);
    if ($phase !== 'original') {
        $worker->registerActivity('parity.v1.echo_activity', static function (\DurableWorkflow\Worker\ActivityContext $context, array $value): array {
            RangeRepairState::$activityInvocations++;

            return $value;
        });
    }
    $error = null;
    try {
        $worker->run(0);
    } catch (Throwable $failure) {
        $error = ['class' => $failure::class, 'message' => $failure->getMessage()];
    }
    $execution = $client->describeWorkflow($options['workflow-id'], $runId);
    $events = rangeDecodeHistory(httpHistory($client, $options['workflow-id'], $runId), $client->payloadCodec()->decodeEnvelope(...));
    $debug = (new \GuzzleHttp\Client(['http_errors' => false]))->get($options['url'].'/api/workflows/'
        .rawurlencode($options['workflow-id']).'/runs/'.rawurlencode($runId).'/debug', [
            'headers' => ['Authorization' => 'Bearer '.(getenv('DW_PARITY_TOKEN') ?: ''),
                'X-Namespace' => 'default', 'X-Durable-Workflow-Control-Plane-Version' => '2'],
        ]);
    $loaded = [];
    foreach ([\DurableWorkflow\Worker::class, \DurableWorkflow\Worker\WorkflowContext::class] as $class) {
        $loaded[$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
    }

    return ['phase' => $phase, 'pid' => getmypid(), 'workflow_id' => $options['workflow-id'], 'run_id' => $runId,
        'workflow_type' => $execution->workflowType, 'namespace' => $execution->namespace, 'task_queue' => $execution->taskQueue,
        'status' => $execution->status, 'payload_codec' => $execution->raw['payload_codec'],
        'input' => $execution->input, 'output' => $execution->output, 'execution' => $execution->raw, 'events' => $events,
        'versions' => RangeRepairState::$versions, 'activity_invocations' => RangeRepairState::$activityInvocations,
        'definition_sha256' => hash_file('sha256', $definition), 'requests' => $requests, 'diagnostics' => $diagnostics,
        'worker_error' => $error, 'repair' => $repair, 'sdk_loaded_sources' => $loaded,
        'debug_status' => $debug->getStatusCode(), 'debug' => json_decode((string) $debug->getBody(), true, flags: JSON_THROW_ON_ERROR)];
}

function rangeEmbeddedPhase(array $fixture, array $options): array
{
    if ($fixture['range_repair']['pending_activity'] ?? false) {
        require __DIR__.'/range-pending-probe.php';

        return rangePendingEmbeddedPhase($fixture, $options);
    }
    $root = $options['application-root'];
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('server.mode') !== 'embedded') {
        throw new RuntimeException('Range fixture requires the embedded application mode.');
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
    $definition = __DIR__.'/range-repair-embedded-'.($phase === 'incompatible' ? 'incompatible' : 'original').'.php';
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
    $workerOutput = [];
    do {
        \Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'server-parity-v1',
            '--once' => true, '--stop-when-empty' => true, '--max-time' => 5, '--sleep' => 0, '--tries' => 1]);
        $workerOutput[] = \Illuminate\Support\Facades\Artisan::output();
        $run = \Workflow\V2\Models\WorkflowRun::query()->findOrFail($stub->runId());
        $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
            'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
            'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload])->all();
        $tasks = $run->tasks()->orderBy('created_at')->orderBy('id')->get()->map->toArray()->all();
        $done = $phase === 'original' ? in_array('ActivityScheduled', array_column($events, 'event_type'), true)
            : $run->status->isTerminal() || array_filter($tasks, static fn ($task): bool => ($task['payload']['replay_blocked'] ?? false) === true);
        if (! $done && microtime(true) >= (float) $options['range-deadline']) {
            throw new RuntimeException('Embedded range author exceeded its original 35-second budget.');
        }
    } while (! $done);
    $loaded = [];
    foreach (['VersionResolver', 'WorkflowExecutor', 'TaskRepair'] as $name) {
        $loaded['src/V2/Support/'.$name.'.php'] = hash_file('sha256', (new ReflectionClass('Workflow\\V2\\Support\\'.$name))->getFileName());
    }
    $provenance = $root.'/vendor/durable-workflow/workflow/.package-provenance';
    $output = $run->workflowOutput();

    return ['phase' => $phase, 'pid' => getmypid(), 'workflow_id' => $run->workflow_instance_id, 'run_id' => $run->id,
        'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace, 'task_queue' => $run->queue,
        'status' => $run->status->value, 'payload_codec' => $run->payload_codec,
        'input' => $run->workflowArguments(), 'output' => $output, 'execution' => $run->toArray(),
        'events' => rangeDecodeHistory($events, static fn ($value) => \Workflow\Serializers\Serializer::unserializeWithCodec('avro', is_array($value) ? $value['blob'] : $value)),
        'versions' => RangeRepairState::$versions, 'activity_invocations' => RangeRepairState::$activityInvocations,
        'definition_sha256' => hash_file('sha256', $definition), 'tasks' => $tasks,
        'failures' => $run->failures()->get()->map->toArray()->all(), 'worker_output' => $workerOutput,
        'repair' => $repair === null ? null : ['accepted' => $repair->accepted(), 'outcome' => $repair->outcome(),
            'run_id' => $repair->runId(), 'resolved_run_id' => $repair->resolvedRunId(), 'command_id' => $repair->commandId()],
        'workflow_package' => \Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
        'workflow_source' => \Composer\InstalledVersions::getReference('durable-workflow/workflow'),
        'workflow_provenance' => is_file($provenance) ? file($provenance, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : null,
        'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'), 'workflow_loaded_sources' => $loaded];
}
