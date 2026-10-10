<?php

declare(strict_types=1);

use DurableWorkflow\Client;

function finishHttpTaskRetry(Client $admin, array $fixture, string $workflowId, string $queue, string $url): array
{
    $definition = $fixture['workflow_task_retry'];
    $requests = [];
    $client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: namespaceTransport($requests));
    $workerId = $workflowId.'-retry-worker';
    $peerId = $workflowId.$definition['peer_suffix'];
    $registration = $client->registerWorker($workerId, $queue, [$definition['peer_workflow_type']], []);
    $handle = $client->startWorkflow($definition['peer_workflow_type'], $peerId, $queue, [$fixture['input']]);
    $reload = static fn (): array => namespaceHttpRun($admin, $peerId, $handle->selectedRunId);
    $poll = static function () use ($client, $workerId, $queue): array {
        $deadline = microtime(true) + 10;
        do {
            $task = $client->pollWorkflowTask($workerId, $queue, 1);
            if ($task !== null) { return $task; }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Original workflow retry task did not arrive.');
    };
    $task = $poll();
    $state = ['applicable' => true, 'worker_id' => $workerId, 'registration' => $registration,
        'peer_workflow_id' => $peerId, 'peer_run_id' => $handle->selectedRunId, 'before' => $reload(), 'steps' => []];
    foreach ($definition['failures'] as $failure) {
        $report = static fn (int $attempt): array => $client->failWorkflowTask($task['task_id'], $workerId,
            $attempt, $failure['message'], $failure['type']);
        $step = ['task' => $task, 'before' => $reload()];
        $step['stale'] = namespaceReceipt('stale_attempt', static fn (): array => $report($task['workflow_task_attempt'] + 1));
        $step['after_stale'] = $reload();
        $step['accepted'] = $report($task['workflow_task_attempt']);
        $step['after_accepted'] = $reload();
        $step['duplicate'] = namespaceReceipt('duplicate', static fn (): array => $report($task['workflow_task_attempt']));
        $step['late_completion'] = namespaceReceipt('late_completion', static fn (): array =>
            $client->completeWorkflowTask($task['task_id'], $workerId, $task['workflow_task_attempt'],
                [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($fixture['input'])]]));
        $step['after_refusals'] = $reload();
        $task = $poll();
        $step['next_task'] = $task;
        $state['steps'][] = $step;
    }
    $state['last_task'] = $task;
    $state['completion'] = $client->completeWorkflowTask($task['task_id'], $workerId, $task['workflow_task_attempt'],
        [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($fixture['input'])]]);
    $state['final'] = $reload();
    $state['idle_poll'] = $client->pollWorkflowTask($workerId, $queue, 0);
    $state['late_failure'] = namespaceReceipt('late_failure', static fn (): array =>
        $client->failWorkflowTask($task['task_id'], $workerId, $task['workflow_task_attempt'],
            $definition['failures'][0]['message'], $definition['failures'][0]['type']));
    $state['after_terminal_refusal'] = $reload();
    $state['requests'] = $requests;
    return $state;
}
