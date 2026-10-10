<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as NamespaceHttp;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Artisan;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;
use Workflow\Serializers\Serializer;

function namespaceReceipt(string $id, callable $request): array
{
    try {
        return ['id' => $id, 'status' => 200, 'response' => $request()];
    } catch (ServerException $error) {
        return ['id' => $id, 'status' => $error->status, 'response' => $error->details];
    }
}

function namespaceHttpRun(Client $client, string $workflowId, string $runId): array
{
    $run = $client->describeWorkflow($workflowId, $runId);

    return ['execution' => $run->raw, 'workflow_id' => $run->workflowId, 'run_id' => $run->runId,
        'workflow_type' => $run->workflowType, 'namespace' => $run->namespace, 'task_queue' => $run->taskQueue,
        'status' => $run->status, 'payload_codec' => $run->raw['payload_codec'] ?? null,
        'input' => $run->input, 'typed_input' => typedValue($run->input),
        'output' => $run->output, 'typed_output' => typedValue($run->output),
        'events' => decodeHistory(httpHistory($client, $workflowId, $runId), $client->payloadCodec()->decodeEnvelope(...))];
}

function namespaceTransport(array &$receipts, ?Closure $beforeCompletion = null): Psr18Transport
{
    $stack = HandlerStack::create();
    $stack->push(static function (callable $handler) use (&$receipts, $beforeCompletion): callable {
        return static function (RequestInterface $request, array $options) use ($handler, &$receipts, $beforeCompletion) {
            $path = $request->getUri()->getPath();
            $body = (string) $request->getBody();
            $input = $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            if ($beforeCompletion !== null && preg_match('#^/api/worker/(workflow|activity)-tasks/([^/]+)/complete$#', $path, $match)) {
                $beforeCompletion($match[1], $match[2], $input);
            }

            return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, $path, $input, &$receipts): ResponseInterface {
                if (str_starts_with($path, '/api/worker/')) {
                    $receipts[] = ['method' => $request->getMethod(), 'path' => $path,
                        'namespace' => $request->getHeaderLine('X-Namespace'), 'request' => $input,
                        'status' => $response->getStatusCode(),
                        'response' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
                }

                return $response;
            });
        };
    });

    return new Psr18Transport(client: new NamespaceHttp(['http_errors' => false, 'handler' => $stack,
        'connect_timeout' => 1, 'timeout' => 5]));
}

function finishHttpNamespaces(Client $admin, array $fixture, string $workflowId, string $queue, string $url): array
{
    $definition = $fixture['namespace_isolation'];
    $names = array_map(static fn (string $suffix): string => strtolower($workflowId.$suffix), $definition['namespace_suffixes']);
    $ids = array_map(static fn (string $suffix): string => $workflowId.$suffix, $definition['peer_suffixes']);
    $state = ['namespaces' => $names, 'worker_id' => $workflowId.'-shared-worker', 'administration' => [],
        'requests' => [], 'worker_receipts' => [[], []], 'worker_refusals' => [], 'runs' => []];
    foreach ($names as $name) {
        $created = $admin->createNamespace(strtoupper($name), $definition['description'], $definition['retention_days']);
        $state['administration'][] = ['created' => $created->raw, 'described' => $admin->describeNamespace(strtoupper($name))->raw];
    }
    $state['listed'] = array_values(array_map(static fn ($item): array => $item->raw,
        array_filter($admin->listNamespaces(), static fn ($item): bool => in_array($item->name, $names, true))));
    $clients = [];
    $handles = [];
    $beforeCompletion = static function (string $kind, string $task, array $body) use (&$state, &$clients, &$handles, $ids): void {
        $before = namespaceHttpRun($clients[0], $ids[0], $handles[0]->selectedRunId);
        $request = static function () use ($kind, $task, $body, &$clients): array {
            return $kind === 'workflow'
                ? $clients[1]->completeWorkflowTask($task, $body['lease_owner'], $body['workflow_task_attempt'], $body['commands'])
                : $clients[1]->completeActivityTask($task, $body['activity_attempt_id'], $body['lease_owner'], $clients[1]->payloadCodec()->decodeEnvelope($body['result']));
        };
        $state['worker_refusals'][] = ['kind' => $kind, 'task_id' => $task, 'request' => $body,
            'receipt' => namespaceReceipt('foreign_'.$kind.'_completion', $request),
            'before' => $before, 'after' => namespaceHttpRun($clients[0], $ids[0], $handles[0]->selectedRunId)];
    };
    foreach ($names as $index => $name) {
        $clients[] = new Client($url, namespace: $name, token: getenv('DW_PARITY_TOKEN') ?: null,
            transport: namespaceTransport($state['worker_receipts'][$index], $index === 0 ? $beforeCompletion : null));
        $state['registrations'][] = $clients[$index]->registerWorker($state['worker_id'], $queue,
            [$definition['peer_workflow_type']], ['parity.v1.echo_activity']);
        $handles[] = $clients[$index]->startWorkflow($definition['peer_workflow_type'], $ids[$index], $queue, [$fixture['input']]);
    }
    $state['before'] = array_map(static fn (int $index): array => namespaceHttpRun($clients[$index], $ids[$index], $handles[$index]->selectedRunId), [0, 1]);
    $foreign = $clients[1];
    $firstRun = $handles[0]->selectedRunId;
    $state['requests'] = [
        namespaceReceipt('foreign_current_read', static fn (): array => $foreign->describeWorkflow($ids[0])->raw),
        namespaceReceipt('foreign_run_read', static fn (): array => $foreign->describeWorkflow($ids[0], $firstRun)->raw),
        namespaceReceipt('foreign_history', static fn (): array => $foreign->workflowHistory($ids[0], $firstRun)),
        namespaceReceipt('foreign_cancel', static fn (): array => $foreign->cancelWorkflow($ids[0], $definition['refused_reason'], $firstRun)),
        namespaceReceipt('reserved_workflow_id', static fn (): array => $foreign->startWorkflow($definition['peer_workflow_type'], $ids[0], $queue, [$fixture['input']])->describeSelectedRun()->raw),
    ];
    $state['after_refusals'] = array_map(static fn (int $index): array => namespaceHttpRun($clients[$index], $ids[$index], $handles[$index]->selectedRunId), [0, 1]);
    foreach ([0, 1] as $index) {
        $deadline = microtime(true) + 10;
        $worker = null;
        $worker = (new Worker($clients[$index], $queue, workerId: $state['worker_id'],
            clock: static function () use (&$worker, $handles, $index, $deadline): float {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Named namespace worker exceeded its completion budget.');
                }
                if ($handles[$index]->describeSelectedRun()->status === 'completed') {
                    $worker->requestShutdown();
                }

                return microtime(true);
            }))
            ->registerWorkflow($definition['peer_workflow_type'], static fn (WorkflowContext $context, array $value): array => $context->activity('parity.v1.echo_activity', [$value]))
            ->registerActivity('parity.v1.echo_activity', static fn (ActivityContext $context, array $value): array => $value);
        $worker->run(0);
        $state['runs'][] = namespaceHttpRun($clients[$index], $ids[$index], $handles[$index]->selectedRunId);
        if ($index === 0) {
            $state['second_after_first'] = namespaceHttpRun($clients[1], $ids[1], $handles[1]->selectedRunId);
            $state['second_worker_heartbeat'] = $clients[1]->heartbeatWorker($state['worker_id']);
        }
    }

    return $state;
}

function namespaceEmbeddedRun(string $runId): array
{
    $run = WorkflowRun::query()->findOrFail($runId);
    $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($row): array => [
        'sequence' => $row->sequence, 'event_type' => $row->event_type->value,
        'timestamp' => $row->recorded_at->toISOString(), 'payload' => $row->payload,
    ])->all();

    return ['execution' => $run->toArray(), 'workflow_id' => $run->workflow_instance_id, 'run_id' => $run->id,
        'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace, 'task_queue' => $run->queue,
        'status' => $run->status->value, 'payload_codec' => $run->payload_codec,
        'input' => $run->workflowArguments(), 'typed_input' => typedValue($run->workflowArguments()),
        'output' => $run->workflowOutput(), 'typed_output' => typedValue($run->workflowOutput()),
        'events' => decodeHistory($events, static fn ($value) => Serializer::unserializeWithCodec('avro', is_array($value) ? $value['blob'] : $value))];
}

function finishEmbeddedNamespaces(array $fixture, string $workflowId, string $queue): array
{
    $definition = $fixture['namespace_isolation'];
    $names = array_map(static fn (string $suffix): string => strtolower($workflowId.$suffix), $definition['namespace_suffixes']);
    $ids = array_map(static fn (string $suffix): string => $workflowId.$suffix, $definition['peer_suffixes']);
    // The host supplies namespace binding; descendants inherit their actual
    // original parent. These listeners never write lifecycle/history outcomes.
    WorkflowRun::creating(static function ($row): void {
        $row->namespace = WorkflowInstance::query()->findOrFail($row->workflow_instance_id)->namespace;
    });
    WorkflowTask::creating(static function ($row): void {
        $row->namespace = WorkflowRun::query()->findOrFail($row->workflow_run_id)->namespace;
    });
    $state = ['namespaces' => $names, 'administration' => [], 'requests' => [], 'worker_receipts' => [],
        'worker_refusals' => [], 'runs' => [], 'foreign_selections' => []];
    $handles = [];
    foreach ([0, 1] as $index) {
        $handles[] = WorkflowStub::make(\ServerParity\OneActivityWorkflow::class, $ids[$index], $names[$index]);
        $handles[$index]->start($fixture['input'], new WorkflowOptions(connection: 'database', queue: $queue),
            new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
    }
    $state['before'] = array_map(static fn ($handle): array => namespaceEmbeddedRun($handle->runId()), $handles);
    foreach ([0, 1] as $index) {
        try {
            WorkflowStub::loadSelection($ids[$index], $handles[$index]->runId(), $names[1 - $index]);
            $state['foreign_selections'][] = ['refused' => false];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
            $state['foreign_selections'][] = ['refused' => true, 'exception' => $error::class];
        }
    }
    $state['after_refusals'] = array_map(static fn ($handle): array => namespaceEmbeddedRun($handle->runId()), $handles);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true,
        '--max-time' => 20, '--sleep' => 0, '--tries' => 1]);
    $state['runs'] = array_map(static fn ($handle): array => namespaceEmbeddedRun($handle->runId()), $handles);

    return $state;
}
