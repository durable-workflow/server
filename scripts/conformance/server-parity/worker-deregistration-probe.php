<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\WorkflowContext;

function fenceReceipt(callable $request): array
{
    try {
        return ['status' => 200, 'response' => $request()];
    } catch (ServerException $error) {
        return ['status' => $error->status, 'response' => $error->details];
    }
}

function finishHttpWorkerDeregistration(Client $client, array $fixture, string $workflowId, string $queue): array
{
    $definition = $fixture['worker_deregistration'];
    $peerId = $workflowId.$definition['peer_suffix'];
    $workerId = $workflowId.'-fenced-worker';
    $peer = $client->startWorkflow($definition['peer_workflow_type'], $peerId, $queue, [$fixture['input']]);
    $registration = static fn (): array => $client->registerWorker($workerId, $queue, [$definition['peer_workflow_type']], []);
    $reload = static fn (): array => namespaceHttpRun($client, $peerId, $peer->selectedRunId);
    $state = ['applicable' => true, 'worker_id' => $workerId, 'peer_workflow_id' => $peerId,
        'peer_run_id' => $peer->selectedRunId, 'capability' => $client->clusterInfo()->raw['worker_protocol']['server_capabilities']['worker_deregistration_fencing'] ?? null];
    $state['original_registration'] = $registration();
    $originalToken = $state['original_registration']['registration_token'];
    $state['heartbeat'] = $client->heartbeatWorker($workerId);
    $state['original_task'] = $client->pollWorkflowTask($workerId, $queue, 0);
    $task = $state['original_task'];
    $state['before_unknown'] = $reload();
    $unknown = str_repeat('0', 32);
    if ($unknown === $originalToken) { $unknown = str_repeat('1', 32); }
    $state['unknown_token'] = $unknown;
    $state['unknown'] = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $unknown));
    $state['after_unknown'] = $reload();
    $state['original_receipt'] = $client->deregisterWorkerRegistration($workerId, $originalToken);
    $state['after_original'] = $reload();
    $commands = [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->encodeEnvelope($fixture['input'])]];
    $stale = static fn (): array => $client->completeWorkflowTask($task['task_id'], $workerId, $task['workflow_task_attempt'], $commands);
    $state['stale_original'] = fenceReceipt($stale);
    $state['after_stale_original'] = $reload();
    $state['replacement_registration'] = $registration();
    $replacementToken = $state['replacement_registration']['registration_token'];
    $state['latest_registration'] = $registration();
    $latestToken = $state['latest_registration']['registration_token'];
    $state['before_superseded'] = $reload();
    $state['superseded'] = fenceReceipt(static fn (): array => $client->deregisterWorkerRegistration($workerId, $replacementToken));
    $state['after_superseded'] = $reload();
    $state['latest_task'] = $client->pollWorkflowTask($workerId, $queue, 0);
    $state['before_replay'] = $reload();
    // Actual receipt replay after replacement. Transport faults have separate fixtures.
    $state['replayed_receipt'] = $client->deregisterWorkerRegistration($workerId, $originalToken);
    $state['after_replay'] = $reload();
    $state['replacement_heartbeat'] = $client->heartbeatWorker($workerId);
    $state['stale_after_replay'] = fenceReceipt($stale);
    $state['after_stale_replay'] = $reload();
    $state['latest_heartbeat'] = $client->heartbeatWorker($workerId);
    $state['latest_receipt'] = $client->deregisterWorkerRegistration($workerId, $latestToken);
    $state['after_latest'] = $reload();
    $worker = null;
    $deadline = microtime(true) + 15;
    $worker = (new Worker($client, $queue, workerId: $workflowId.'-recovery-worker',
        clock: static function () use (&$worker, $peer, $deadline): float {
            if (microtime(true) > $deadline) { throw new RuntimeException('Original fenced workflow did not recover.'); }
            if ($peer->describeSelectedRun()->status === 'completed') { $worker->requestShutdown(); }
            return microtime(true);
        }))->registerWorkflow($definition['peer_workflow_type'], static fn (WorkflowContext $context, array $value): array => $value);
    $worker->run(0);
    $state['final'] = $reload();
    $idleId = $workflowId.'-idle-worker';
    $state['idle_registration'] = $client->registerWorker($idleId, $queue, [$definition['peer_workflow_type']], []);
    $state['idle_poll'] = $client->pollWorkflowTask($idleId, $queue, 0);
    $state['idle_receipt'] = $client->deregisterWorkerRegistration($idleId, $state['idle_registration']['registration_token']);
    return $state;
}
