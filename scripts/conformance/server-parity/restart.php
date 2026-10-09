<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

require __DIR__.'/vendor/autoload.php';

function assertRestart(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

assertRestart($argc === 4, 'Usage: restart.php prepare|finish URL RECEIPT');
[$script, $phase, $url, $receiptPath] = $argv;
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
$value = ['message' => 'restart λ', 'count' => 9007199254740993];

if ($phase === 'prepare') {
    $client->registerWorker('restart-old', 'restart-v1', ['restart.workflow'], ['restart.activity']);
    $handle = $client->startWorkflow('restart.workflow', 'rust-restart-activity', 'restart-v1', [$value]);
    $task = $client->pollWorkflowTask('restart-old', 'restart-v1', 0);
    assertRestart(is_array($task), 'Prepare did not obtain a real workflow task.');
    $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => 'restart.activity',
        'arguments' => $client->payloadCodec()->envelope([$value]), 'queue' => 'restart-v1',
    ]]);
    $activity = $client->pollActivityTask('restart-old', 'restart-v1', 0);
    assertRestart(is_array($activity) && $activity['attempt_number'] === 1, 'Prepare did not lease attempt one.');
    $receipt = ['workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId, 'activity' => $activity];
    file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR).PHP_EOL);
    echo json_encode(['phase' => $phase, 'outcome' => 'prepared', 'run_id' => $handle->selectedRunId,
        'lease_expires_at' => $activity['lease_expires_at']], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

assertRestart($phase === 'finish', 'Unknown restart phase.');
$receipt = json_decode(file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
$old = $receipt['activity'];
// Let the real persisted lease expire. Do not edit clocks/rows or revoke the
// old worker to imitate process-loss recovery.
$waitUntil = strtotime($old['lease_expires_at']) + 1;
assertRestart($waitUntil - time() < 40, 'Unexpected unbounded lease wait.');
while (time() < $waitUntil) {
    usleep(100_000);
}
$refused = false;
try {
    $client->completeActivityTask($old['task_id'], $old['activity_attempt_id'], $old['lease_owner'], $value);
} catch (ServerException $exception) {
    $refused = $exception->status === 409;
}
assertRestart($refused, 'Expired pre-kill activity claim was accepted.');
$worker = null;
$deadline = microtime(true) + 25;
$clock = function () use ($client, $receipt, $deadline, &$worker): float {
    $now = microtime(true);
    if ($now > $deadline) {
        throw new RuntimeException('Restart did not complete within its execution budget.');
    }
    if ($client->describeWorkflow($receipt['workflow_id'], $receipt['run_id'])->status === 'completed') {
        $worker?->requestShutdown();
    }

    return $now;
};
$worker = new Worker($client, 'restart-v1', workerId: 'restart-new', clock: $clock);
$worker->registerWorkflow('restart.workflow', fn (WorkflowContext $context, array $input): mixed =>
    $context->activity('restart.activity', [$input]));
$worker->registerActivity('restart.activity', function (ActivityContext $context, array $input): array {
    assertRestart($context->attemptNumber === 2, 'Recovered worker did not execute attempt two.');

    return $input;
});
$worker->run(0);
$execution = $client->describeWorkflow($receipt['workflow_id'], $receipt['run_id']);
assertRestart($execution->status === 'completed' && $execution->output === $value, 'Recovered durable outcome differs.');
$history = $client->workflowHistory($receipt['workflow_id'], $receipt['run_id'])['events'];
$types = array_column($history, 'event_type');
assertRestart($types === ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted',
    'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'], 'Recovered event inventory differs.');
assertRestart(array_column($history, 'sequence') === range(1, count($history)), 'History is not contiguous.');
assertRestart($history[0]['payload']['workflow_run_id'] === $receipt['run_id'], 'Original start/run identity was lost.');
assertRestart($history[3]['payload']['activity_attempt_id'] === $old['activity_attempt_id'], 'Original attempt history was lost.');
assertRestart($history[4]['payload']['activity_attempt_id'] !== $old['activity_attempt_id'], 'A stale attempt identity was reused.');
$client->deregisterWorkerRegistration('restart-old');
echo json_encode(['phase' => $phase, 'outcome' => 'pass', 'run_id' => $execution->runId,
    'stale_claim_refused' => true, 'history' => $history], JSON_THROW_ON_ERROR).PHP_EOL;
