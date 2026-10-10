<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as AdmissionTransport;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;
use Workflow\WorkflowOptions;

function admissionRequest(array $definition, array $case, string $url, string $peerId, string $peerRun, string $queue): array
{
    $worker = $case['plane'] === 'worker';
    $read = $case['plane'] === 'read';
    $path = $worker ? '/api/worker/workflow-tasks/poll'
        : '/api/workflows/'.rawurlencode($peerId).'/runs/'.rawurlencode($peerRun).($read ? '' : '/cancel');
    $method = $read ? 'GET' : 'POST';
    $query = $case['query_namespace'] === null ? '' : '?'.http_build_query(['namespace' => $case['query_namespace']]);
    $body = $read ? null : ($worker ? ['worker_id' => $peerId.'-refused-worker', 'task_queue' => $queue, 'timeout_ms' => 0]
        : ['reason' => $definition['refused_reason']]);
    $headers = ['Accept' => 'application/json'];
    if ($case['auth'] !== 'missing') {
        $headers['Authorization'] = 'Bearer '.($case['auth'] === 'valid' ? getenv('DW_PARITY_TOKEN') : 'deliberately-invalid-parity-token');
    }
    if ($case['version'] !== null) {
        $headers[$worker ? 'X-Durable-Workflow-Protocol-Version' : 'X-Durable-Workflow-Control-Plane-Version'] = $case['version'];
    }
    if ($case['header_namespace'] !== null) {
        $headers['X-Namespace'] = $case['header_namespace'];
    }
    // These are actual bounded HTTP requests. Authentication values are never
    // included in receipts or request headers retained as evidence.
    $response = (new AdmissionTransport(['http_errors' => false, 'connect_timeout' => 1, 'timeout' => 1]))
        ->request($method, rtrim($url, '/').$path.$query, ['headers' => $headers, ...($body === null ? [] : ['json' => $body])]);
    $raw = (string) $response->getBody();
    if (strlen($raw) > 1024 * 1024) {
        throw new RuntimeException('Admission response exceeds its observation budget.');
    }

    return ['id' => $case['id'], 'method' => $method, 'path' => $path.$query,
        'request' => $body, 'auth' => $case['auth'], 'requested_version' => $case['version'],
        'header_namespace' => $case['header_namespace'], 'query_namespace' => $case['query_namespace'],
        'status' => $response->getStatusCode(), 'response' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR),
        'headers' => ['control' => $response->getHeaderLine('X-Durable-Workflow-Control-Plane-Version'),
            'worker' => $response->getHeaderLine('X-Durable-Workflow-Protocol-Version')]];
}

function finishHttpAdmission(Client $client, array $fixture, string $workflowId, string $runId, string $queue, string $url, string $namespace): array
{
    $definition = $fixture['admission'];
    $peerId = $workflowId.$definition['peer_suffix'];
    $peer = $client->startWorkflow($definition['peer_workflow_type'], $peerId, $queue, [$fixture['input']]);
    $state = ['peer_workflow_id' => $peerId, 'peer_run_id' => $peer->selectedRunId,
        'peer_before' => $peer->describeSelectedRun()->raw,
        'history_before' => httpHistory($client, $peerId, $peer->selectedRunId), 'requests' => []];
    try {
        $state['cluster'] = $client->clusterInfo()->raw;
        foreach ($definition['requests'] as $case) {
            $state['requests'][] = admissionRequest($definition, $case, $url, $peerId, $peer->selectedRunId, $queue);
        }
        $state['peer_after_refusals'] = $peer->describeSelectedRun()->raw;
        $state['history_after_refusals'] = httpHistory($client, $peerId, $peer->selectedRunId);
    } catch (Throwable $error) {
        parityHttpFailureEvidence($error, 'admission', $workflowId, $runId, $url, $namespace, [], ['admission' => $state]);
        throw $error;
    } finally {
        $peer->cancelSelectedRun($definition['cleanup_reason']);
    }
    $state['peer_after_cleanup'] = $peer->describeSelectedRun()->raw;
    $state['history_after_cleanup'] = httpHistory($client, $peerId, $peer->selectedRunId);

    return $state;
}

function finishEmbeddedAdmission(array $fixture, string $workflowId, string $queue): array
{
    $definition = $fixture['admission'];
    $peerId = $workflowId.$definition['peer_suffix'];
    $peer = WorkflowStub::make(\ServerParity\OneActivityWorkflow::class, $peerId);
    $peer->start($fixture['input'], new WorkflowOptions(connection: 'database', queue: $queue),
        new StartOptions(executionTimeoutSeconds: 3600, runTimeoutSeconds: 600));
    $run = WorkflowRun::query()->findOrFail($peer->runId());
    $history = static fn (): array => $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
        'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
        'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload,
    ])->all();
    $state = ['peer_workflow_id' => $peerId, 'peer_run_id' => $run->id,
        'peer_before' => $run->toArray(), 'history_before' => $history(), 'requests' => [],
        'peer_after_refusals' => $run->fresh()->toArray(), 'history_after_refusals' => $history()];
    // HTTP authentication is inapplicable to the embedded engine. Its original
    // peer is nevertheless admitted and cancelled through installed APIs.
    $result = $peer->attemptCancel($definition['cleanup_reason']);
    $state['cleanup'] = ['accepted' => $result->accepted(), 'command_id' => $result->commandId(),
        'command_sequence' => $result->commandSequence(), 'workflow_id' => $result->workflowId(),
        'run_id' => $result->resolvedRunId(), 'status' => $result->status(), 'outcome' => $result->outcome(), 'reason' => $result->reason()];
    $state['peer_after_cleanup'] = $run->fresh()->toArray();
    $state['history_after_cleanup'] = $history();

    return $state;
}
