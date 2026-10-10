<?php

declare(strict_types=1);

use DurableWorkflow\Client;

function finishHttpWaitingHistory(Client $admin, array $fixture, string $workflowId, string $queue, string $url): array
{
    $definition = $fixture['waiting_for_history'];
    $requests = [];
    $client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: namespaceTransport($requests));
    $workerId = $workflowId.'-waiting-worker';
    $peerId = $workflowId.$definition['peer_suffix'];
    $registration = $client->registerWorker($workerId, $queue, [$definition['peer_workflow_type']], [$definition['activity_type']]);
    $handle = $client->startWorkflow($definition['peer_workflow_type'], $peerId, $queue, [$fixture['input']]);
    $reload = static fn (): array => namespaceHttpRun($admin, $peerId, $handle->selectedRunId);
    $poll = static function () use ($client, $workerId, $queue): array {
        $deadline = microtime(true) + 10;
        do {
            $task = $client->pollWorkflowTask($workerId, $queue, 1);
            if ($task !== null) { return $task; }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Original pending-history workflow task did not arrive.');
    };
    $first = $poll();
    $scheduled = $client->completeWorkflowTask($first['task_id'], $workerId, $first['workflow_task_attempt'], [
        ['type' => 'start_timer', 'delay_seconds' => 0],
        ['type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
            'arguments' => $client->payloadCodec()->envelope([$fixture['input']])],
    ]);
    $replay = $poll();
    $failure = $definition['failure'];
    $acknowledge = static fn (int $attempt): array => $client->failWorkflowTask($replay['task_id'], $workerId,
        $attempt, $failure['message'], $failure['type']);
    $state = ['applicable' => true, 'worker_id' => $workerId, 'registration' => $registration,
        'peer_workflow_id' => $peerId, 'peer_run_id' => $handle->selectedRunId,
        'first_task' => $first, 'scheduled' => $scheduled, 'replay_task' => $replay,
        'before' => $reload()];
    $state['stale_attempt'] = namespaceReceipt('stale_attempt', static fn (): array => $acknowledge($replay['workflow_task_attempt'] + 1));
    $state['after_stale'] = $reload();
    $state['acknowledgement'] = $acknowledge($replay['workflow_task_attempt']);
    $state['after_acknowledgement'] = $reload();
    $state['idle_poll'] = $client->pollWorkflowTask($workerId, $queue, 0);
    $state['duplicate'] = namespaceReceipt('duplicate', static fn (): array => $acknowledge($replay['workflow_task_attempt']));
    $state['late_completion'] = namespaceReceipt('late_completion', static fn (): array =>
        $client->completeWorkflowTask($replay['task_id'], $workerId, $replay['workflow_task_attempt'],
            [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($fixture['input'])]]));
    $state['after_refusals'] = $reload();
    $activity = $client->pollActivityTask($workerId, $queue, 0);
    if ($activity === null) { throw new RuntimeException('Original pending activity disappeared after waiting acknowledgement.'); }
    $state['activity_task'] = $activity;
    $state['activity_receipt'] = $client->completeActivityTask($activity['task_id'], $activity['activity_attempt_id'], $workerId, $fixture['input']);
    $last = $poll();
    $state['last_task'] = $last;
    $state['completion'] = $client->completeWorkflowTask($last['task_id'], $workerId, $last['workflow_task_attempt'],
        [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($fixture['input'])]]);
    $state['final'] = $reload();
    $state['requests'] = $requests;
    return $state;
}
