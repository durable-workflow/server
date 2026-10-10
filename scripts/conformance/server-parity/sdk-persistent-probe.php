<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\TransportException;
use DurableWorkflow\Transport\BoundedTransport;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

require_once __DIR__.'/sdk-pressure-probe.php';

final class SdkPersistentPressureTransport implements BoundedTransport
{
    private Psr18Transport $pollTransport;
    public array $pollRequests = [];

    public function __construct(public readonly SdkDatabasePressureTransport $shutdown, private readonly bool $originalFailure)
    {
        $this->pollTransport = new Psr18Transport();
    }

    public function supportsBoundedRequests(): bool { return $this->shutdown->supportsBoundedRequests(); }
    public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
    {
        return $this->perform($method, $uri, $headers, $body, null);
    }
    public function sendBounded(string $method, string $uri, array $headers, ?array $body, int $timeoutSeconds): ?array
    {
        return $this->perform($method, $uri, $headers, $body, $timeoutSeconds);
    }
    private function perform(string $method, string $uri, array $headers, ?array $body, ?int $timeout): ?array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($this->originalFailure && $path === '/api/worker/workflow-tasks/poll' && $this->pollRequests === []) {
            // A declared request fault: use an invalid fixture credential for
            // one real HTTP poll. Registration and shutdown keep the original.
            $headers['Authorization'] = 'Bearer parity-intentionally-invalid-fixture-credential';
            $started = hrtime(true) / 1e9;
            try {
                $timeout === null ? $this->pollTransport->send($method, $uri, $headers, $body)
                    : $this->pollTransport->sendBounded($method, $uri, $headers, $body, $timeout);
            } catch (TransportException $error) {
                $this->pollRequests[] = ['method' => $method, 'path' => $path, 'status' => $error->status,
                    'namespace' => $headers['X-Namespace'] ?? null,
                    'credential_sha256' => hash('sha256', $headers['Authorization']),
                    'timeout_seconds' => $timeout, 'elapsed_seconds' => hrtime(true) / 1e9 - $started];
                throw $error;
            }
            throw new RuntimeException('The declared invalid poll credential did not receive a rejection.');
        }
        return $timeout === null ? $this->shutdown->send($method, $uri, $headers, $body)
            : $this->shutdown->sendBounded($method, $uri, $headers, $body, $timeout);
    }
    public function requests(): array
    {
        return [...array_slice($this->shutdown->requests, 0, 1), ...$this->pollRequests, ...array_slice($this->shutdown->requests, 1)];
    }
}

function persistentSdkError(Throwable $error, Throwable $returned): array
{
    return ['class' => get_class($error),
        'status' => $error instanceof ServerException || $error instanceof TransportException ? $error->status : null,
        'reason' => $error instanceof ServerException ? $error->reason : null,
        'same_as_returned_error' => $error === $returned];
}

function finishSdkPersistentPressure(Client $client, array $fixture, string $workflowId, string $queue, string $url): array
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
    $shutdown = new SdkDatabasePressureTransport($workerId,
        registered: static function (array $reply) use (&$state, &$worker, $client, $workerId, $reload, $definition, $queue): void {
            $state['original_registration'] = $reply;
            $state['heartbeat'] = $client->heartbeatWorker($workerId);
            $state['original_task'] = $client->pollWorkflowTask($workerId, $queue, 0);
            $state['before_unknown'] = $reload();
            $unknown = str_repeat($reply['registration_token'] === str_repeat('0', 32) ? '1' : '0', 32);
            $state['unknown_token'] = $unknown;
            $state['unknown'] = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $unknown));
            $state['after_unknown'] = $reload();
            $state['before_pressure'] = $reload();
            if (!$definition['original_worker_failure']) { $worker->requestShutdown(); }
        },
        pressureFailed: static function (TransportException $error) use (&$state, $reload): void {
            // This first actual 503 has completed. Inspect while the independent
            // writer remains held, then retain the lock through SDK exhaustion.
            $state['after_pressure'] = $reload();
        },
    );
    $transport = new SdkPersistentPressureTransport($shutdown, $definition['original_worker_failure']);
    $sdk = new Client($url, namespace: $client->namespace, token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: $transport, workerProtocolVersion: $client->workerProtocolVersion);
    $diagnostics = [];
    $worker = (new Worker($sdk, $queue, workerId: $workerId,
        diagnosticListener: static function (string $event, array $context) use (&$diagnostics): void {
            $diagnostics[] = ['event' => $event, 'operation' => $context['operation'] ?? null,
                'attempt' => $context['attempt'] ?? null, 'exception' => $context['exception'] ?? null];
        }))->registerWorkflow($definition['peer_workflow_type'], static fn (WorkflowContext $context, array $value): array => $value);
    $returned = null;
    $elapsed = null;
    try {
        $worker->run(0);
    } catch (Throwable $error) {
        $returned = $error;
        $elapsed = hrtime(true) / 1e9 - $shutdown->shutdownStarted;
    } finally {
        // A timed-out request can remain in flight. Release only after the SDK
        // returns; reconcile the original token instead of assuming rollback.
        $shutdown->releaseLock();
    }
    if (!$returned instanceof Throwable) { throw new RuntimeException('Persistent shutdown failure was hidden by the SDK.'); }
    $diagnostics = array_map(static function (array $entry) use ($returned): array {
        $error = $entry['exception'];
        unset($entry['exception']);
        if ($error instanceof Throwable) { $entry['error'] = persistentSdkError($error, $returned); }
        return $entry;
    }, $diagnostics);
    $manual = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $state['original_registration']['registration_token']));
    $state['original_receipt'] = $manual['response'];
    $state['after_original'] = $reload();
    $beforeReplay = $reload();
    $manualReplay = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $state['original_registration']['registration_token']));
    $afterReplay = $reload();
    $state['sdk_persistent_pressure'] = ['kind' => 'real_independent_database_write_lock_held_until_sdk_returns',
        'backend' => getenv('DB_CONNECTION'), 'bounded_transport' => $transport->supportsBoundedRequests(),
        'shutdown_elapsed_seconds' => $elapsed, 'lock_release' => 'after_sdk_return_before_manual_reconciliation',
        'original_worker_failure' => $definition['original_worker_failure'],
        'request_fault' => $definition['original_worker_failure'] ? 'one_actual_sdk_workflow_poll_with_invalid_fixture_credential' : null,
        'reference_php_session_before' => json_decode(file_get_contents(getenv('DW_PARITY_PRESSURE_SESSION_BEFORE')), true, 512, JSON_THROW_ON_ERROR),
        'reference_php_session_after' => json_decode(file_get_contents(getenv('DW_PARITY_PRESSURE_SESSION_AFTER')), true, 512, JSON_THROW_ON_ERROR),
        'requests' => $transport->requests(), 'failures' => $shutdown->failures,
        'sdk_receipt' => $shutdown->receipt, 'returned_error' => persistentSdkError($returned, $returned),
        'diagnostics' => $diagnostics, 'manual_reconciliation' => $manual, 'manual_replay' => $manualReplay,
        'before_manual_replay' => $beforeReplay, 'after_manual_replay' => $afterReplay];
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
