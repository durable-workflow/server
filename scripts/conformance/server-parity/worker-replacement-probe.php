<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;

// A worker process that dies holding an activity lease is replaced by a new
// process registering the same worker_id. Its lease is released at once,
// rather than after the five-minute activity lease.
function finishHttpWorkerReplacement(Client $client, array $fixture, string $workflowId): array
{
    $definition = $fixture['worker_replacement'];
    $receipt = static function (callable $request): array {
        try {
            return ['status' => 200, 'response' => $request()];
        } catch (ServerException $error) {
            return ['status' => $error->status, 'response' => $error->details];
        }
    };
    $worker = $workflowId.'-replaced-worker';
    $queue = $workflowId.'-replacement';
    $peerId = $workflowId.'-replacement-peer';
    $register = static fn (): array => $client->registerWorker($worker, $queue, [$definition['workflow_type']],
        [$definition['activity_type']]);
    $envelope = static fn (): array => $client->payloadCodec()->envelope($fixture['input']);
    $state = ['applicable' => true, 'worker_id' => $worker, 'task_queue' => $queue, 'peer_workflow_id' => $peerId];
    $state['registration'] = $register();
    $handle = $client->startWorkflow($definition['workflow_type'], $peerId, $queue, [$fixture['input']]);
    $state['peer_run_id'] = $handle->selectedRunId;
    $task = $client->pollWorkflowTask($worker, $queue, 5);
    $state['scheduled'] = $client->completeWorkflowTask($task['task_id'], $worker, $task['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => $definition['activity_type'],
        'arguments' => $client->payloadCodec()->envelope([$fixture['input']]),
        'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => []],
    ]]);
    $state['original_claim'] = $client->pollActivityTask($worker, $queue, 5);
    $state['replacement_registration'] = $register();
    $state['history_after_replacement'] = httpHistory($client, $peerId, $handle->selectedRunId);
    $state['replacement_claim'] = $client->pollActivityTask($worker, $queue, 5);
    $original = $state['original_claim'];
    $state['stale_completion'] = $receipt(static fn (): array => $client->completeActivityTask($original['task_id'],
        $original['activity_attempt_id'], $worker, $fixture['input']));
    $replacement = $state['replacement_claim'];
    $state['completion'] = $receipt(static fn (): array => $client->completeActivityTask($replacement['task_id'],
        $replacement['activity_attempt_id'], $worker, $fixture['input']));
    $task = $client->pollWorkflowTask($worker, $queue, 5);
    $state['closed'] = $client->completeWorkflowTask($task['task_id'], $worker, $task['workflow_task_attempt'],
        [['type' => 'complete_workflow', 'result' => $envelope()]]);
    $state['history'] = httpHistory($client, $peerId, $handle->selectedRunId);
    $client->deregisterWorker($worker);

    return $state;
}
