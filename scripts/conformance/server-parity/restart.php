<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use DurableWorkflow\Worker\Replayer;

require __DIR__.'/vendor/autoload.php';

function assertRestart(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function restartSignalWorkflow(WorkflowContext $context, array $input): array
{
    $context->waitCondition(fn (): bool => count($context->signals('payload')) > 0, 'restart.payload');

    return $context->signals('payload')[0][0];
}

assertRestart($argc === 4, 'Usage: restart.php prepare|finish URL RECEIPT');
[$script, $phase, $url, $receiptPath] = $argv;
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
$value = ['message' => 'restart λ', 'count' => 9007199254740993];

if ($phase === 'prepare') {
    $signalDefinition = (new Worker($client, 'restart-v1'))
        ->registerWorkflow('restart.signals', restartSignalWorkflow(...))
        ->declareSignal('restart.signals', 'payload', static fn (array $value) => null);
    $client->registerWorker('restart-old', 'restart-v1', ['restart.workflow', 'restart.timer', 'restart.signals'], ['restart.activity'],
        workflowCommandContracts: $signalDefinition->contracts()['workflow_commands']);
    $handle = $client->startWorkflow('restart.workflow', 'rust-restart-activity', 'restart-v1', [$value]);
    $task = $client->pollWorkflowTask('restart-old', 'restart-v1', 0);
    assertRestart(is_array($task), 'Prepare did not obtain a real workflow task.');
    $client->completeWorkflowTask($task['task_id'], $task['lease_owner'], $task['workflow_task_attempt'], [[
        'type' => 'schedule_activity', 'activity_type' => 'restart.activity',
        'arguments' => $client->payloadCodec()->envelope([$value]), 'queue' => 'restart-v1',
    ]]);
    $activity = $client->pollActivityTask('restart-old', 'restart-v1', 0);
    assertRestart(is_array($activity) && $activity['attempt_number'] === 1, 'Prepare did not lease attempt one.');
    $timerHandle = $client->startWorkflow('restart.timer', 'rust-restart-timer', 'restart-v1', [$value]);
    $timerTask = $client->pollWorkflowTask('restart-old', 'restart-v1', 0);
    assertRestart(is_array($timerTask) && $timerTask['run_id'] === $timerHandle->selectedRunId, 'Prepare did not obtain the timer workflow.');
    $client->completeWorkflowTask($timerTask['task_id'], $timerTask['lease_owner'], $timerTask['workflow_task_attempt'], [['type' => 'start_timer', 'delay_seconds' => 10]]);
    $timerHistory = $client->workflowHistory($timerHandle->workflowId, $timerHandle->selectedRunId)['events'];
    assertRestart(array_column($timerHistory, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'TimerScheduled'], 'Timer was not pending at the kill checkpoint.');
    $signalHandle = $client->startWorkflow('restart.signals', 'rust-restart-signals', 'restart-v1', [$value]);
    $signalTask = $client->pollWorkflowTask('restart-old', 'restart-v1', 0);
    assertRestart(is_array($signalTask) && $signalTask['run_id'] === $signalHandle->selectedRunId, 'Prepare did not lease the signal workflow.');
    // Author the wait with the actual published replayer and this leased task's
    // real history/input. Its fingerprint is the same source the new worker
    // will replay after the process kill; no fixture fingerprint is invented.
    $replayed = (new Replayer($client->payloadCodec()))->replay(restartSignalWorkflow(...),
        $signalTask['history_events'], $client->payloadCodec()->decodeEnvelope($signalTask['arguments']), 'restart-v1', $signalTask);
    assertRestart(count($replayed->commands) === 1 && $replayed->commands[0]['type'] === 'open_condition_wait', 'Published replayer did not author a pending signal condition.');
    $client->completeWorkflowTask($signalTask['task_id'], $signalTask['lease_owner'], $signalTask['workflow_task_attempt'], $replayed->commands);
    $signalCommand = $client->signalWorkflow($signalHandle->workflowId, 'payload', [$value], $signalHandle->selectedRunId);
    $signalHistory = $client->workflowHistory($signalHandle->workflowId, $signalHandle->selectedRunId)['events'];
    assertRestart(array_column($signalHistory, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'ConditionWaitOpened', 'SignalReceived'], 'Signal was not durably pending at the kill checkpoint.');
    $receipt = ['workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId, 'activity' => $activity,
        'timer' => ['workflow_id' => $timerHandle->workflowId, 'run_id' => $timerHandle->selectedRunId, 'scheduled' => $timerHistory[2]['payload']],
        'signal' => ['workflow_id' => $signalHandle->workflowId, 'run_id' => $signalHandle->selectedRunId,
            'opened' => $signalHistory[2]['payload'], 'received' => $signalHistory[3]['payload'], 'response' => $signalCommand]];
    file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR).PHP_EOL);
    echo json_encode(['phase' => $phase, 'outcome' => 'prepared', 'run_id' => $handle->selectedRunId,
        'lease_expires_at' => $activity['lease_expires_at'], 'pending_timer' => $receipt['timer']], JSON_THROW_ON_ERROR).PHP_EOL;
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
    if ($client->describeWorkflow($receipt['workflow_id'], $receipt['run_id'])->status === 'completed'
        && $client->describeWorkflow($receipt['timer']['workflow_id'], $receipt['timer']['run_id'])->status === 'completed'
        && $client->describeWorkflow($receipt['signal']['workflow_id'], $receipt['signal']['run_id'])->status === 'completed') {
        $worker?->requestShutdown();
    }

    return $now;
};
$worker = new Worker($client, 'restart-v1', workerId: 'restart-new', clock: $clock);
$worker->registerWorkflow('restart.workflow', fn (WorkflowContext $context, array $input): mixed =>
    $context->activity('restart.activity', [$input]));
$worker->registerWorkflow('restart.timer', function (WorkflowContext $context, array $input): array {
    $context->sleep(10);

    return $input;
});
$worker->registerWorkflow('restart.signals', restartSignalWorkflow(...))
    ->declareSignal('restart.signals', 'payload', static fn (array $value) => null);
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
$timer = $receipt['timer'];
$timerExecution = $client->describeWorkflow($timer['workflow_id'], $timer['run_id']);
assertRestart($timerExecution->status === 'completed' && $timerExecution->output === $value, 'Timer did not recover its original durable outcome.');
$timerHistory = $client->workflowHistory($timer['workflow_id'], $timer['run_id'])['events'];
assertRestart(array_column($timerHistory, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'TimerScheduled', 'TimerFired', 'WorkflowCompleted'], 'Recovered timer history differs.');
assertRestart(array_column($timerHistory, 'sequence') === range(1, 5), 'Recovered timer history is not contiguous.');
assertRestart($timerHistory[0]['payload']['workflow_run_id'] === $timer['run_id'], 'Original timer run identity was lost.');
foreach ([2, 3] as $index) {
    foreach (['timer_id', 'sequence', 'delay_seconds', 'fire_at'] as $key) {
        assertRestart($timerHistory[$index]['payload'][$key] === $timer['scheduled'][$key], 'A timer identity, sequence or deadline changed after the kill.');
    }
}
assertRestart(new DateTimeImmutable($timerHistory[3]['payload']['fired_at']) >= new DateTimeImmutable($timer['scheduled']['fire_at']), 'Recovered timer fired early.');
$signal = $receipt['signal'];
$signalExecution = $client->describeWorkflow($signal['workflow_id'], $signal['run_id']);
assertRestart($signalExecution->status === 'completed' && $signalExecution->output === $value, 'Pending signal did not recover its original typed outcome.');
$signalHistory = $client->workflowHistory($signal['workflow_id'], $signal['run_id'])['events'];
assertRestart(array_column($signalHistory, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'ConditionWaitOpened', 'SignalReceived', 'MessageCursorAdvanced', 'SignalApplied', 'ConditionWaitSatisfied', 'WorkflowCompleted'], 'Recovered signal history differs.');
assertRestart(array_column($signalHistory, 'sequence') === range(1, 8), 'Recovered signal history is not contiguous.');
assertRestart($signalHistory[0]['payload']['workflow_run_id'] === $signal['run_id'], 'Original signal run was lost.');
foreach (['workflow_command_id', 'signal_id', 'signal_name', 'signal_wait_id'] as $key) {
    assertRestart($signalHistory[3]['payload'][$key] === $signal['received'][$key]
        && $signalHistory[5]['payload'][$key] === $signal['received'][$key], 'An accepted signal identity changed across process loss.');
}
assertRestart($signal['response']['command_id'] === $signalHistory[5]['payload']['workflow_command_id'], 'The acknowledged signal command was not applied.');
foreach (['condition_wait_id', 'condition_key', 'condition_definition_fingerprint', 'sequence'] as $key) {
    assertRestart($signalHistory[2]['payload'][$key] === $signal['opened'][$key]
        && $signalHistory[6]['payload'][$key] === $signal['opened'][$key], 'The original condition wait changed across process loss.');
}
assertRestart($signalHistory[4]['payload']['previous_position'] === 0 && $signalHistory[4]['payload']['new_position'] === 1, 'Recovered signal cursor did not advance exactly once.');
assertRestart($signalHistory[6]['payload']['workflow_signal_id'] === $signal['received']['signal_id'], 'Recovered condition references a different signal.');
assertRestart($client->payloadCodec()->decodeEnvelope($signalHistory[3]['payload']['arguments']) === [$value]
    && $client->payloadCodec()->decodeEnvelope($signalHistory[5]['payload']['value']) === $value, 'Accepted/applied signal payload changed across process loss.');
$client->deregisterWorkerRegistration('restart-old');
echo json_encode(['phase' => $phase, 'outcome' => 'pass', 'run_id' => $execution->runId,
    'stale_claim_refused' => true, 'history' => $history, 'timer_recovered' => true, 'timer_history' => $timerHistory,
    'signal_recovered' => true, 'signal_history' => $signalHistory], JSON_THROW_ON_ERROR).PHP_EOL;
