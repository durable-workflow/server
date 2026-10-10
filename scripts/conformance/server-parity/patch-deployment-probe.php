<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

final class PatchDeploymentState
{
    public static string $changeId;
    public static array $expectedDecisions;
    public static array $decisions = [];
}

/** Original and replacement use different processes and author definitions. */
function patchDeploymentObservation(array $fixture, array $options): array
{
    $deadline = microtime(true) + 30;
    $phases = [];
    foreach (['original', 'replacement'] as $phase) {
        $arguments = [PHP_BINARY, __DIR__.'/probe.php', '--mode', $options['mode'],
            '--fixture', $options['fixture'], '--workflow-id', $options['workflow-id'],
            '--patch-phase', $phase, '--patch-deadline', (string) $deadline];
        foreach (['url', 'application-root'] as $key) {
            if (isset($options[$key])) {
                array_push($arguments, '--'.$key, $options[$key]);
            }
        }
        if ($phase === 'replacement') {
            array_push($arguments, '--run-id', $phases[0]['run_id']);
        }
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the cold patch worker.');
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
                @file_put_contents($directory.'/failure-'.$name.'-patch.json', json_encode([
                    'failed_phase' => $phase, 'completed_phases' => $phases,
                    'error' => trim($stderr),
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            }
            throw new RuntimeException('Patch '.$phase.' worker failed: '.trim($stderr));
        }
        $phases[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }
    $observation = $phases[1];
    $observation['patch_deployment'] = ['checkpoint' => $fixture['patch_deployment']['checkpoint'],
        'change_id' => $fixture['patch_deployment']['change_id'], 'original' => $phases[0], 'replacement' => $phases[1]];
    $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (is_string($directory) && is_dir($directory)) {
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
        @file_put_contents($directory.'/patch-'.$name.'.json', json_encode($observation,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
    }

    return $observation;
}

function patchHttpPhase(array $fixture, array $options): array
{
    if ($options['patch-phase'] === 'replacement' && isset($fixture['patch_deployment']['consumer'])) {
        return patchPublishedSdkPhase($fixture, $options);
    }
    $phase = $options['patch-phase'];
    $workflowId = $options['workflow-id'];
    $queue = 'server-parity-v1';
    $worker = $handle = null;
    $requests = $diagnostics = [];
    $stack = HandlerStack::create();
    $stack->push(static function (callable $handler) use (&$worker, &$requests, $phase, $fixture): callable {
        return static function (RequestInterface $request, array $transportOptions) use ($handler, &$worker, &$requests, $phase, $fixture) {
            return $handler($request, $transportOptions)->then(static function (ResponseInterface $response) use ($request, &$worker, &$requests, $phase, $fixture): ResponseInterface {
                $path = $request->getUri()->getPath();
                $body = (string) $request->getBody();
                $requestBody = $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                $responseBody = (string) $response->getBody();
                $requests[] = ['method' => $request->getMethod(), 'path' => $path, 'request' => $requestBody,
                    'status' => $response->getStatusCode(), 'response' => $responseBody === '' ? null : json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR)];
                if ($response->getStatusCode() !== 200) {
                    return $response;
                }
                $workflowCompletion = preg_match('#^/api/worker/workflow-tasks/[^/]+/complete$#', $path) === 1;
                $activityCompletion = preg_match('#^/api/worker/activity-tasks/[^/]+/complete$#', $path) === 1;
                $commands = array_column($requestBody['commands'] ?? [], 'type');
                if ($phase === 'original' && (
                    $fixture['patch_deployment']['checkpoint'] === 'activity_pending' && $workflowCompletion && in_array('schedule_activity', $commands, true)
                    || $fixture['patch_deployment']['checkpoint'] === 'activity_completed' && $activityCompletion
                ) || $phase === 'replacement' && $workflowCompletion && in_array('complete_workflow', $commands, true)) {
                    $worker->requestShutdown();
                }

                return $response;
            });
        };
    });
    $client = new Client($options['url'], namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: new Psr18Transport(client: new GuzzleClient(['http_errors' => false, 'handler' => $stack])));
    if ($phase === 'replacement') {
        $handle = $client->workflowHandle($workflowId, $options['run-id']);
    } elseif (($fixture['patch_deployment']['start_before_worker_registration'] ?? false) === true) {
        // This explicit legacy history starts before a worker has advertised
        // a definition fingerprint. Do not override recorded worker identity.
        $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $queue, [$fixture['input']]);
    }
    $worker = new Worker($client, $queue, workerId: $workflowId.':'.$phase,
        stickyCacheTtlSeconds: $fixture['patch_deployment']['original_sticky_ttl_seconds'] ?? 300,
        clock: static function () use ($options): float {
            if (microtime(true) > (float) $options['patch-deadline']) {
                throw new RuntimeException('Patch deployment exceeded its original 30-second budget.');
            }

            return microtime(true);
        },
        diagnosticListener: static function (string $event) use (&$handle, $client, $workflowId, $queue, $fixture, $phase, &$diagnostics): void {
            $diagnostics[] = $event;
            if ($event === 'worker.registered' && $phase === 'original' && $handle === null) {
                $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $queue, [$fixture['input']]);
            }
        });
    $worker->registerWorkflow($fixture['workflow_type'], static function (WorkflowContext $context, array $value) use ($phase, $fixture): array {
        if ($phase === 'replacement' || ($fixture['patch_deployment']['original_patch'] ?? false)) {
            $decisions = [];
            foreach (PatchDeploymentState::$expectedDecisions as $_) {
                $decisions[] = $context->patched(PatchDeploymentState::$changeId);
            }
            PatchDeploymentState::$decisions[] = $decisions;
            if ($decisions !== PatchDeploymentState::$expectedDecisions) {
                throw new LogicException('Patch decisions differ from the declared deployment contract.');
            }
        }

        return $context->activity('parity.v1.echo_activity', [$value]);
    });
    if ($phase === 'replacement' || $fixture['patch_deployment']['checkpoint'] === 'activity_completed') {
        $worker->registerActivity('parity.v1.echo_activity', static fn (ActivityContext $context, array $value): array => $value);
    }
    $worker->run(0);
    $execution = $client->describeWorkflow($workflowId, $handle->selectedRunId);
    $events = decodeHistory(httpHistory($client, $workflowId, $handle->selectedRunId), $client->payloadCodec()->decodeEnvelope(...));

    return ['phase' => $phase, 'pid' => getmypid(), 'decisions' => PatchDeploymentState::$decisions,
        'requests' => $requests, 'diagnostics' => $diagnostics, 'execution' => $execution->raw,
        'workflow_id' => $workflowId, 'run_id' => $execution->runId, 'workflow_type' => $execution->workflowType,
        'namespace' => $execution->namespace, 'task_queue' => $execution->taskQueue, 'status' => $execution->status,
        'payload_codec' => $execution->raw['payload_codec'], 'input' => $execution->input, 'output' => $execution->output,
        'events' => $events];
}

/** Observe an unmodified published worker through a byte-preserving local proxy. */
function patchPublishedSdkPhase(array $fixture, array $options): array
{
    $consumer = $fixture['patch_deployment']['consumer'];
    $directory = __DIR__.'/published-sdk-patch';
    $journal = tempnam(sys_get_temp_dir(), 'parity-sdk-');
    $proxy = proc_open(['node', $directory.'/observe-http.mjs', $options['url'], $journal],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $proxyPipes);
    if (! is_resource($proxy)) {
        unlink($journal);
        throw new RuntimeException('Could not start the SDK HTTP observer.');
    }
    fclose($proxyPipes[0]);
    try {
        $url = trim(fgets($proxyPipes[1]));
        if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) {
            throw new RuntimeException('SDK observer did not bind its local port.');
        }
        $arguments = match ($consumer['language']) {
            'rust' => [getenv('DW_PARITY_RUST_PATCH_WORKER') ?: '/target/debug/server-parity-published-rust-patch'],
            'python' => ['python3', $directory.'/python.py'],
            default => throw new RuntimeException('Unsupported published patch consumer.'),
        };
        array_push($arguments, $url, $options['workflow-id'], $options['run-id'], $fixture['patch_deployment']['change_id']);
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the published SDK worker.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $evidence = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
        if (is_string($evidence) && is_dir($evidence)) {
            $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
            file_put_contents($evidence.'/consumer-'.$name.'-raw.json', json_encode([
                'consumer' => $consumer, 'stdout' => $stdout, 'stderr' => $stderr,
                'exit_status' => $status, 'transport_jsonl' => file_get_contents($journal),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }
        $requests = array_map(static function ($line): array {
            $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            foreach (['request', 'response'] as $key) {
                $body = $request[$key.'_body'];
                $request[$key] = $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            }
            unset($request['request_body'], $request['response_body'], $request['response_bytes_base64']);
            if ($request['method'] === 'GET' || str_ends_with($request['path'], '/poll')) {
                // Full read/poll bytes remain in the raw sidecar. Avoid copying
                // every repeated capability manifest into all three phases.
                $request['response'] = array_intersect_key($request['response'] ?? [],
                    array_flip(['task', 'poll_status', 'workflow_id', 'run_id', 'status', 'reason', 'message']));
                $request['response_projection'] = 'read_or_poll_summary';
            }

            return $request;
        },
            file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if ($status !== 0) {
            $evidence = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
            if (is_string($evidence) && is_dir($evidence)) {
                $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $options['workflow-id']);
                file_put_contents($evidence.'/failure-'.$name.'-consumer.json', json_encode([
                    'consumer' => $consumer, 'requests' => $requests, 'stdout' => $stdout,
                    'stderr' => $stderr, 'exit_status' => $status,
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            }
            throw new RuntimeException('Published SDK worker failed: '.trim($stderr));
        }
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        if ($result['language'] !== $consumer['language'] || $result['sdk_version'] !== $consumer['version']) {
            throw new RuntimeException('Actual published worker differs from the selected consumer.');
        }
        $client = new Client($options['url'], namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
        $execution = $client->describeWorkflow($options['workflow-id'], $options['run-id']);
        $events = decodeHistory(httpHistory($client, $options['workflow-id'], $options['run-id']), $client->payloadCodec()->decodeEnvelope(...));

        return ['phase' => 'replacement', 'pid' => $result['pid'], 'decisions' => $result['decisions'],
            'consumer' => $consumer, 'worker_finished' => $result['worker_finished'], 'requests' => $requests,
            'execution' => $execution->raw, 'workflow_id' => $options['workflow-id'], 'run_id' => $execution->runId,
            'workflow_type' => $execution->workflowType, 'namespace' => $execution->namespace,
            'task_queue' => $execution->taskQueue, 'status' => $execution->status,
            'payload_codec' => $execution->raw['payload_codec'], 'input' => $execution->input,
            'output' => $execution->output, 'events' => $events];
    } finally {
        proc_terminate($proxy);
        fclose($proxyPipes[1]);
        fclose($proxyPipes[2]);
        proc_close($proxy);
        unlink($journal);
    }
}

function patchEmbeddedPhase(array $fixture, array $options): array
{
    $root = $options['application-root'];
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    require __DIR__.'/embedded-types.php';
    $definition = ($fixture['patch_deployment']['original_patch'] ?? false) ? 'replacement' : $options['patch-phase'];
    require __DIR__.'/patch-deployment-'.$definition.'.php';
    config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
        'workflows.v2.types.workflows' => [$fixture['workflow_type'] => \ServerParityPatch\DeploymentWorkflow::class],
        'workflows.v2.types.activities' => ['parity.v1.echo_activity' => \ServerParity\EchoActivity::class]]);
    foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowTask::class] as $model) {
        $model::creating(static function ($row): void { $row->namespace = 'default'; });
    }
    $phase = $options['patch-phase'];
    if ($phase === 'original') {
        $stub = WorkflowStub::make(\ServerParityPatch\DeploymentWorkflow::class, $options['workflow-id'], 'default');
        $stub->start($fixture['input'], new WorkflowOptions(connection: 'database', queue: 'server-parity-v1'),
            new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
    } else {
        $stub = WorkflowStub::loadSelection($options['workflow-id'], $options['run-id'], 'default');
    }
    do {
        if (microtime(true) > (float) $options['patch-deadline']) {
            throw new RuntimeException('Embedded patch deployment exceeded its original 30-second budget.');
        }
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'server-parity-v1',
            '--once' => true, '--stop-when-empty' => true, '--max-time' => 5, '--sleep' => 0, '--tries' => 1]);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
            'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
            'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload,
        ])->all();
        $kinds = array_column($events, 'event_type');
        $done = $phase === 'replacement' ? $run->status->value === 'completed'
            : in_array($fixture['patch_deployment']['checkpoint'] === 'activity_pending' ? 'ActivityScheduled' : 'ActivityCompleted', $kinds, true);
        if (! $done) {
            usleep(20000);
        }
    } while (! $done);

    return ['phase' => $phase, 'pid' => getmypid(), 'decisions' => PatchDeploymentState::$decisions,
        ...(isset($fixture['patch_deployment']['consumer']) && $phase === 'replacement'
            ? ['consumer' => ['applicable' => false, 'reason' => 'embedded_executes_php_author_definitions']] : []),
        'instance' => WorkflowInstance::query()->findOrFail($run->workflow_instance_id)->toArray(),
        'worker_output' => Artisan::output(), 'execution' => $run->toArray(), 'workflow_id' => $run->workflow_instance_id,
        'run_id' => $run->id, 'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace,
        'task_queue' => $run->queue, 'status' => $run->status->value, 'payload_codec' => $run->payload_codec,
        'input' => $run->workflowArguments(), 'output' => $run->workflowOutput(),
        'events' => decodeHistory($events, static fn ($value) => Serializer::unserializeWithCodec('avro', is_array($value) ? $value['blob'] : $value))];
}
