<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\TransportException;
use DurableWorkflow\Transport\BoundedTransport;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as PressureHttp;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;

final class SdkDatabasePressureTransport implements BoundedTransport
{
    private Psr18Transport $inner;
    private ?PDO $lock = null;
    private int $wireStatus = 0;
    private string $retryAfter = '';
    public array $requests = [];
    public array $failures = [];
    public ?array $receipt = null;
    public ?float $shutdownStarted = null;

    public function __construct(private readonly string $workerId, private readonly Closure $registered, private readonly Closure $pressureFailed)
    {
        $stack = HandlerStack::create();
        $stack->push(function (callable $handler): Closure {
            return function ($request, array $options) use ($handler) {
                return $handler($request, $options)->then(function (ResponseInterface $response): ResponseInterface {
                    $this->wireStatus = $response->getStatusCode();
                    $this->retryAfter = $response->getHeaderLine('Retry-After');
                    return $response;
                });
            };
        });
        $this->inner = new Psr18Transport(client: new PressureHttp(['http_errors' => false, 'handler' => $stack]));
    }
    public function supportsBoundedRequests(): bool { return $this->inner->supportsBoundedRequests(); }
    public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
    {
        return $this->perform($method, $uri, $headers, $body, null);
    }
    public function sendBounded(string $method, string $uri, array $headers, ?array $body, int $timeoutSeconds): ?array
    {
        return $this->perform($method, $uri, $headers, $body, $timeoutSeconds);
    }
    private function holdLock(): void
    {
        $driver = getenv('DB_CONNECTION');
        $database = getenv('DW_PARITY_PRESSURE_DATABASE');
        if (!$database) { throw new RuntimeException('Pressure database was not explicitly selected.'); }
        $dsn = $driver === 'sqlite' ? 'sqlite:'.$database
            : $driver.':host='.getenv('DB_HOST').';dbname='.$database;
        $this->lock = new PDO($dsn, getenv('DB_USERNAME') ?: null, getenv('DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($driver === 'sqlite') {
            $this->lock->exec('BEGIN IMMEDIATE');
        } elseif (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->lock->beginTransaction();
            $statement = $this->lock->prepare('SELECT worker_id FROM workflow_worker_registrations WHERE namespace=? AND worker_id=? FOR UPDATE');
            $statement->execute(['default', $this->workerId]);
            if ($statement->fetchColumn() !== $this->workerId) { throw new RuntimeException('Original worker row was not locked.'); }
        } else { throw new RuntimeException('Unsupported pressure backend.'); }
    }
    public function releaseLock(): void
    {
        if ($this->lock !== null) { $this->lock->exec('ROLLBACK'); $this->lock = null; }
    }
    private function perform(string $method, string $uri, array $headers, ?array $body, ?int $timeout): ?array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $shutdown = str_ends_with($path, '/deregister');
        if ($shutdown && $this->shutdownStarted === null) {
            $this->shutdownStarted = hrtime(true) / 1e9;
            $this->holdLock();
        }
        $this->wireStatus = 0;
        $this->retryAfter = '';
        $started = hrtime(true) / 1e9;
        $metadata = ['method' => $method, 'path' => $path, 'namespace' => $headers['X-Namespace'] ?? null,
            'credential_sha256' => hash('sha256', $headers['Authorization'] ?? ''),
            'timeout_seconds' => $timeout, 'registration_token' => $body['registration_token'] ?? null];
        try {
            $reply = $timeout === null ? $this->inner->send($method, $uri, $headers, $body)
                : $this->inner->sendBounded($method, $uri, $headers, $body, $timeout);
        } catch (TransportException $error) {
            $this->requests[] = [...$metadata, 'status' => $this->wireStatus, 'retry_after' => $this->retryAfter,
                'elapsed_seconds' => hrtime(true) / 1e9 - $started];
            $this->failures[] = ['status' => $error->status, 'response' => $error->response];
            if ($shutdown && count($this->failures) === 1) { ($this->pressureFailed)($error); }
            throw $error;
        }
        $this->requests[] = [...$metadata, 'status' => $this->wireStatus, 'retry_after' => $this->retryAfter,
            'elapsed_seconds' => hrtime(true) / 1e9 - $started];
        if ($path === '/api/worker/register') { ($this->registered)($reply); }
        if ($shutdown) { $this->receipt = $reply; }
        return $reply;
    }
}

function finishSdkDatabasePressure(Client $client, array $fixture, string $workflowId, string $queue, string $url): array
{
    $definition = $fixture['worker_deregistration'];
    $peerId = $workflowId.$definition['peer_suffix'];
    $workerId = $workflowId.'-fenced-worker';
    $peer = $client->startWorkflow($definition['peer_workflow_type'], $peerId, $queue, [$fixture['input']]);
    $reload = static fn (): array => namespaceHttpRun($client, $peerId, $peer->selectedRunId);
    $registration = static fn (): array => $client->registerWorker($workerId, $queue, [$definition['peer_workflow_type']], []);
    $commands = [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($fixture['input'])]];
    $state = ['applicable' => true, 'worker_id' => $workerId, 'peer_workflow_id' => $peerId, 'peer_run_id' => $peer->selectedRunId,
        'capability' => $client->clusterInfo()->raw['worker_protocol']['server_capabilities']['worker_deregistration_fencing'] ?? null];
    $worker = null;
    $transport = null;
    $authority = [];
    $transport = new SdkDatabasePressureTransport($workerId,
        registered: static function (array $reply) use (&$state, &$authority, &$worker, $client, $fixture, $workflowId, $workerId, $queue, $reload): void {
            $state['original_registration'] = $reply;
            $state['heartbeat'] = $client->heartbeatWorker($workerId);
            $state['original_task'] = $client->pollWorkflowTask($workerId, $queue, 0);
            $authorityId = $workflowId.'-authority';
            $run = $client->startWorkflow($fixture['worker_deregistration']['peer_workflow_type'], $authorityId, $queue, [$fixture['input']]);
            $authority = ['workflow_id' => $authorityId, 'run_id' => $run->selectedRunId,
                'task' => $client->pollWorkflowTask($workerId, $queue, 0)];
            $state['before_unknown'] = $reload();
            $unknown = str_repeat($reply['registration_token'] === str_repeat('0', 32) ? '1' : '0', 32);
            $state['unknown_token'] = $unknown;
            $state['unknown'] = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $unknown));
            $state['after_unknown'] = $reload();
            $state['before_pressure'] = $reload();
            $authority['before'] = namespaceHttpRun($client, $authorityId, $run->selectedRunId);
            $worker->requestShutdown();
        },
        pressureFailed: static function (TransportException $error) use (&$state, &$authority, &$transport, $client, $workerId, $reload, $commands): void {
            // Keep the independent writer held while inspecting actual full reads.
            $state['after_pressure'] = $reload();
            $authority['after_pressure'] = namespaceHttpRun($client, $authority['workflow_id'], $authority['run_id']);
            $transport->releaseLock();
            $task = $authority['task'];
            $authority['completion'] = fenceReceipt(static fn (): array => $client->completeWorkflowTask($task['task_id'], $workerId, $task['workflow_task_attempt'], $commands));
            $authority['final'] = namespaceHttpRun($client, $authority['workflow_id'], $authority['run_id']);
        },
    );
    $sdk = new Client($url, namespace: $client->namespace, token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: $transport, workerProtocolVersion: $client->workerProtocolVersion);
    $diagnostics = [];
    $worker = (new Worker($sdk, $queue, workerId: $workerId,
        diagnosticListener: static function (string $event, array $context) use (&$diagnostics): void {
            $diagnostics[] = ['event' => $event, 'operation' => $context['operation'] ?? null, 'attempt' => $context['attempt'] ?? null];
        }))->registerWorkflow($definition['peer_workflow_type'], static fn (WorkflowContext $context, array $value): array => $value);
    try { $worker->run(0); } finally { $transport->releaseLock(); }
    $elapsed = hrtime(true) / 1e9 - $transport->shutdownStarted;
    $state['sdk_database_pressure'] = ['kind' => 'real_independent_database_write_lock', 'backend' => getenv('DB_CONNECTION'),
        'bounded_transport' => $transport->supportsBoundedRequests(), 'shutdown_elapsed_seconds' => $elapsed,
        'reference_php_session_before' => json_decode(file_get_contents(getenv('DW_PARITY_PRESSURE_SESSION_BEFORE')), true, 512, JSON_THROW_ON_ERROR),
        'reference_php_session_after' => json_decode(file_get_contents(getenv('DW_PARITY_PRESSURE_SESSION_AFTER')), true, 512, JSON_THROW_ON_ERROR),
        'requests' => $transport->requests, 'failures' => $transport->failures, 'diagnostics' => $diagnostics, 'authority' => $authority];
    $state['original_receipt'] = $transport->receipt;
    $state['after_original'] = $reload();
    $task = $state['original_task'];
    $stale = static fn (): array => $client->completeWorkflowTask($task['task_id'], $workerId, $task['workflow_task_attempt'], $commands);
    $state['stale_original'] = fenceReceipt($stale);
    $state['after_stale_original'] = $reload();
    $state['replacement_registration'] = $registration();
    $state['latest_registration'] = $registration();
    $state['before_superseded'] = $reload();
    $state['superseded'] = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $state['replacement_registration']['registration_token']));
    $state['after_superseded'] = $reload();
    $state['latest_task'] = $client->pollWorkflowTask($workerId, $queue, 0);
    $state['before_replay'] = $reload();
    $state['replayed_receipt'] = $client->deregisterWorkerRegistration($workerId, $state['original_registration']['registration_token']);
    $state['after_replay'] = $reload();
    $state['replacement_heartbeat'] = $client->heartbeatWorker($workerId);
    $state['stale_after_replay'] = fenceReceipt($stale);
    $state['after_stale_replay'] = $reload();
    $state['latest_heartbeat'] = $client->heartbeatWorker($workerId);
    $state['latest_receipt'] = $client->deregisterWorkerRegistration($workerId, $state['latest_registration']['registration_token']);
    $state['after_latest'] = $reload();
    return finishWorkerDeregistrationRecovery($client, $fixture, $workflowId, $queue, $peerId, $peer->selectedRunId, $state);
}
