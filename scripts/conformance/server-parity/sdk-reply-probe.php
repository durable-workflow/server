<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\TransportException;
use DurableWorkflow\Transport\BoundedTransport;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as ReplyHttp;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;

final class SdkReplyLossTransport implements BoundedTransport
{
    private Psr18Transport $inner;
    private int $wireStatus = 0;
    public array $requests = [];
    public int $injectedLosses = 0;
    public ?array $replayedReceipt = null;
    public ?float $shutdownStarted = null;

    public function __construct(private readonly Closure $registered, private readonly Closure $committed)
    {
        $stack = HandlerStack::create();
        $stack->push(function (callable $handler): Closure {
            return function ($request, array $options) use ($handler) {
                return $handler($request, $options)->then(function (ResponseInterface $response): ResponseInterface {
                    $this->wireStatus = $response->getStatusCode();
                    return $response;
                });
            };
        });
        $this->inner = new Psr18Transport(client: new ReplyHttp(['http_errors' => false, 'handler' => $stack]));
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
    private function perform(string $method, string $uri, array $headers, ?array $body, ?int $timeout): ?array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $shutdown = str_ends_with($path, '/deregister');
        if ($shutdown && $this->shutdownStarted === null) { $this->shutdownStarted = hrtime(true) / 1e9; }
        $reply = $timeout === null ? $this->inner->send($method, $uri, $headers, $body)
            : $this->inner->sendBounded($method, $uri, $headers, $body, $timeout);
        $this->requests[] = ['method' => $method, 'path' => $path, 'namespace' => $headers['X-Namespace'] ?? null,
            'credential_sha256' => hash('sha256', $headers['Authorization'] ?? ''),
            'timeout_seconds' => $timeout, 'status' => $this->wireStatus,
            'registration_token' => $body['registration_token'] ?? null];
        if ($path === '/api/worker/register') { ($this->registered)($reply); }
        if ($shutdown && $this->injectedLosses === 0) {
            ($this->committed)($reply);
            ++$this->injectedLosses;
            // The real response has arrived. This is a deliberate client-side
            // loss after commit, not a TCP disconnect or server failure.
            throw new TransportException('Client-injected reply loss after real shutdown commit.', transientConnectionFailure: true);
        }
        if ($shutdown) { $this->replayedReceipt = $reply; }
        return $reply;
    }
}

function finishSdkReplyReconciliation(Client $client, array $fixture, string $workflowId, string $queue, string $url): array
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
    $transport = new SdkReplyLossTransport(
        registered: static function (array $reply) use (&$state, &$worker, $client, $workerId, $queue, $reload): void {
            $state['original_registration'] = $reply;
            $state['heartbeat'] = $client->heartbeatWorker($workerId);
            // Controlled setup leases real original work under this SDK
            // incarnation, then stops its loop before any SDK task polling.
            $state['original_task'] = $client->pollWorkflowTask($workerId, $queue, 0);
            $state['before_unknown'] = $reload();
            $unknown = str_repeat($reply['registration_token'] === str_repeat('0', 32) ? '1' : '0', 32);
            $state['unknown_token'] = $unknown;
            $state['unknown'] = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $unknown));
            $state['after_unknown'] = $reload();
            $worker->requestShutdown();
        },
        committed: static function (array $receipt) use (&$state, $client, $workerId, $queue, $registration, $reload, $commands): void {
            $state['original_receipt'] = $receipt;
            $state['after_original'] = $reload();
            $task = $state['original_task'];
            $state['stale_original'] = fenceReceipt(static fn (): array => $client->completeWorkflowTask($task['task_id'], $workerId, $task['workflow_task_attempt'], $commands));
            $state['after_stale_original'] = $reload();
            $state['replacement_registration'] = $registration();
            $state['latest_registration'] = $registration();
            $state['before_superseded'] = $reload();
            $token = $state['replacement_registration']['registration_token'];
            $state['superseded'] = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $token));
            $state['after_superseded'] = $reload();
            $state['latest_task'] = $client->pollWorkflowTask($workerId, $queue, 0);
            $state['before_replay'] = $reload();
        },
    );
    $sdk = new Client($url, namespace: $client->namespace, token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: $transport, workerProtocolVersion: $client->workerProtocolVersion);
    $diagnostics = [];
    $worker = (new Worker($sdk, $queue, workerId: $workerId,
        diagnosticListener: static function (string $event, array $context) use (&$diagnostics): void {
            $diagnostics[] = ['event' => $event, 'operation' => $context['operation'] ?? null, 'attempt' => $context['attempt'] ?? null];
        }))->registerWorkflow($definition['peer_workflow_type'], static fn (WorkflowContext $context, array $value): array => $value);
    $worker->run(0);
    $shutdownElapsed = hrtime(true) / 1e9 - $transport->shutdownStarted;
    $state['replayed_receipt'] = $transport->replayedReceipt;
    $state['after_replay'] = $reload();
    $state['sdk_reply_loss'] = ['kind' => 'client_injected_after_real_http_commit',
        'bounded_transport' => $transport->supportsBoundedRequests(), 'injected_loss_count' => $transport->injectedLosses,
        'shutdown_elapsed_seconds' => $shutdownElapsed,
        'requests' => $transport->requests, 'diagnostics' => $diagnostics];
    $state['replacement_heartbeat'] = $client->heartbeatWorker($workerId);
    $task = $state['original_task'];
    $state['stale_after_replay'] = fenceReceipt(static fn (): array => $client->completeWorkflowTask($task['task_id'], $workerId, $task['workflow_task_attempt'], $commands));
    $state['after_stale_replay'] = $reload();
    $state['latest_heartbeat'] = $client->heartbeatWorker($workerId);
    $state['latest_receipt'] = $client->deregisterWorkerRegistration($workerId, $state['latest_registration']['registration_token']);
    $state['after_latest'] = $reload();
    return finishWorkerDeregistrationRecovery($client, $fixture, $workflowId, $queue, $peerId, $peer->selectedRunId, $state);
}
