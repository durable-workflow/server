<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;

// A replaced worker process still holds an earlier claim on a workflow task
// that a new claim of the same worker_id has taken over. Its late requests
// are refused with the published reasons and fields a worker uses to tell
// that its claim was lost, and record nothing.
function finishHttpLateWorkflowCompletion(Client $client, array $fixture, string $workflowId): array
{
    $definition = $fixture['late_workflow_completion'];
    $receipt = static function (callable $request): array {
        try {
            return ['status' => 200, 'response' => $request()];
        } catch (ServerException $error) {
            return ['status' => $error->status, 'response' => $error->details];
        }
    };
    $worker = $workflowId.'-late-worker';
    $queue = $workflowId.'-late';
    $peerId = $workflowId.'-late-peer';
    // Registering the same worker_id again releases its workflow leases.
    $register = static fn (): array => $client->registerWorker($worker, $queue, [$definition['workflow_type']],
        [$definition['activity_type']]);
    $claim = static function () use ($client, $worker, $queue): array {
        $task = $client->pollWorkflowTask($worker, $queue, 5);
        if (! is_array($task)) {
            throw new RuntimeException('Expected a workflow task claim.');
        }

        return $task;
    };
    $schedule = [[
        'type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
        'arguments' => $client->payloadCodec()->envelope([$fixture['input']]),
        'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []],
    ]];
    $complete = [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($fixture['input'])]];
    $late = static fn (array $task, array $commands): array => $receipt(static fn (): array => $client->completeWorkflowTask(
        $task['task_id'], $worker, $task['workflow_task_attempt'], $commands));
    $state = ['applicable' => true, 'worker_id' => $worker, 'task_queue' => $queue, 'peer_workflow_id' => $peerId];
    $register();
    $handle = $client->startWorkflow($definition['workflow_type'], $peerId, $queue, [$fixture['input']]);
    $state['peer_run_id'] = $handle->selectedRunId;

    $replaced = $claim();
    $register();
    $current = $claim();
    $state['first_claims'] = [$replaced, $current];
    $state['while_leased'] = $late($replaced, $schedule);
    $state['heartbeat_while_leased'] = $receipt(static fn (): array => $client->heartbeatWorkflowTask(
        $replaced['task_id'], $worker, $replaced['workflow_task_attempt']));
    $state['scheduled'] = $client->completeWorkflowTask($current['task_id'], $worker, $current['workflow_task_attempt'], $schedule);
    $state['after_completion'] = $late($replaced, $schedule);
    $state['heartbeat_after_completion'] = $receipt(static fn (): array => $client->heartbeatWorkflowTask(
        $replaced['task_id'], $worker, $replaced['workflow_task_attempt']));
    $state['failure_after_completion'] = $receipt(static fn (): array => $client->failWorkflowTask(
        $replaced['task_id'], $worker, $replaced['workflow_task_attempt'], 'late failure'));
    $state['history_while_open'] = httpHistory($client, $peerId, $handle->selectedRunId);

    $activity = $client->pollActivityTask($worker, $queue, 5);
    $client->completeActivityTask($activity['task_id'], $activity['activity_attempt_id'], $worker, $fixture['input']);
    $replaced = $claim();
    $register();
    $current = $claim();
    $state['last_claims'] = [$replaced, $current];
    $state['closed'] = $client->completeWorkflowTask($current['task_id'], $worker, $current['workflow_task_attempt'], $complete);
    $state['after_close'] = $late($replaced, $complete);
    $state['history'] = httpHistory($client, $peerId, $handle->selectedRunId);
    $client->deregisterWorker($worker);

    return $state;
}
