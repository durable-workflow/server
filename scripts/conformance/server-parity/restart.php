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

function sameRestartValue(mixed $expected, mixed $actual): bool
{
    if (is_array($expected)) {
        if (! is_array($actual) || array_is_list($expected) !== array_is_list($actual)
            || count($expected) !== count($actual)) {
            return false;
        }
        // Avro map order is not semantic. List positions, keys, scalar types
        // and exact large integers must still match without PHP coercion.
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || ! sameRestartValue($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }

    return is_float($expected)
        ? is_float($actual) && pack('d', $expected) === pack('d', $actual)
        : $expected === $actual;
}

/** Compare the control API's declared fields without losing raw worker events. */
function restartControlHistory(array $events): array
{
    $ids = array_column($events, 'id');
    assertRestart(count($ids) === count($events) && count(array_unique($ids)) === count($events)
        && ! in_array('', $ids, true), 'Recovered worker history lost original event identities.');

    return array_map(static fn (array $event): array => ['sequence' => $event['sequence'],
        'event_type' => $event['event_type'], 'timestamp' => $event['recorded_at'], 'payload' => $event['payload']], $events);
}

function restartSignalWorkflow(WorkflowContext $context, array $input): array
{
    $context->waitCondition(fn (): bool => count($context->signals('payload')) > 0, 'restart.payload');

    return $context->signals('payload')[0][0];
}

function restartParentWorkflow(WorkflowContext $context, array $input): mixed
{
    return $context->childWorkflow('restart.child', [$input]);
}

function restartChildWorkflow(WorkflowContext $context, array $input): mixed
{
    return $context->activity('restart.child.activity', [$input]);
}

function restartRetryWorkflow(WorkflowContext $context, array $input): mixed
{
    return $context->activity('restart.retry.activity', [$input], ['retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [10]]]);
}

function restartTerminalWorkflow(WorkflowContext $context, array $input): array
{
    try {
        $context->activity('restart.terminal.activity', [$input], ['retry_policy' => ['max_attempts' => 3, 'backoff_seconds' => [10]]]);
    } catch (\DurableWorkflow\Exception\ActivityFailed $failure) {
        return ['echo' => $input, 'caught' => ['type' => $failure->failureType, 'message' => $failure->getMessage(), 'non_retryable' => $failure->nonRetryable]];
    }

    throw new LogicException('The terminal recovery activity unexpectedly succeeded.');
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
    // Type routing keeps this dedicated worker from claiming the pending signal
    // resume. The SDK replayer authors the actual parent child-start command.
    $client->registerWorker('restart-child-old', 'restart-v1', ['restart.parent', 'restart.child'], []);
    $parentHandle = $client->startWorkflow('restart.parent', 'rust-restart-parent', 'restart-v1', [$value]);
    $parentTask = $client->pollWorkflowTask('restart-child-old', 'restart-v1', 0);
    assertRestart(is_array($parentTask) && $parentTask['run_id'] === $parentHandle->selectedRunId, 'Prepare did not obtain the original parent workflow.');
    $parentReplay = (new Replayer($client->payloadCodec()))->replay(restartParentWorkflow(...),
        $parentTask['history_events'], $client->payloadCodec()->decodeEnvelope($parentTask['arguments']), 'restart-v1', $parentTask);
    assertRestart(count($parentReplay->commands) === 1 && $parentReplay->commands[0]['type'] === 'start_child_workflow', 'SDK did not author the original child start.');
    $parentCompletion = $client->completeWorkflowTask($parentTask['task_id'], $parentTask['lease_owner'], $parentTask['workflow_task_attempt'], $parentReplay->commands);
    assertRestart($parentCompletion['recorded'] === true && $parentCompletion['run_id'] === $parentHandle->selectedRunId, 'Original child creation was not acknowledged durably.');
    $childTask = $client->pollWorkflowTask('restart-child-old', 'restart-v1', 0);
    assertRestart(is_array($childTask) && $childTask['workflow_type'] === 'restart.child' && $childTask['workflow_task_attempt'] === 1, 'Prepare did not lease the original child workflow.');
    $parentHistory = $client->workflowHistory($parentHandle->workflowId, $parentHandle->selectedRunId)['events'];
    assertRestart(array_column($parentHistory, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'ChildWorkflowScheduled', 'ChildRunStarted'], 'Parent was not waiting on its original child at the kill checkpoint.');
    assertRestart($parentHistory[2]['payload']['child_workflow_run_id'] === $childTask['run_id']
        && count($childTask['history_events']) === 1 && $childTask['history_events'][0]['event_type'] === 'WorkflowStarted', 'Child lease is not bound to the acknowledged original parent call.');
    // A separate routed queue keeps this failure checkpoint independent of the
    // already pending signal/child work. The real SDK authors the retry policy.
    $client->registerWorker('restart-retry-old', 'restart-retry-v1', ['restart.retry'], ['restart.retry.activity']);
    $retryHandle = $client->startWorkflow('restart.retry', 'rust-restart-retry', 'restart-retry-v1', [$value]);
    $retryTask = $client->pollWorkflowTask('restart-retry-old', 'restart-retry-v1', 0);
    assertRestart(is_array($retryTask) && $retryTask['run_id'] === $retryHandle->selectedRunId, 'Prepare did not lease the original retry workflow.');
    $retryReplay = (new Replayer($client->payloadCodec()))->replay(restartRetryWorkflow(...),
        $retryTask['history_events'], $client->payloadCodec()->decodeEnvelope($retryTask['arguments']), 'restart-retry-v1', $retryTask);
    $client->completeWorkflowTask($retryTask['task_id'], $retryTask['lease_owner'], $retryTask['workflow_task_attempt'], $retryReplay->commands);
    $failedActivity = $client->pollActivityTask('restart-retry-old', 'restart-retry-v1', 0);
    assertRestart(is_array($failedActivity) && $failedActivity['attempt_number'] === 1, 'Prepare did not lease the first retry activity attempt.');
    $failureReceipt = $client->failActivityTask($failedActivity['task_id'], $failedActivity['activity_attempt_id'],
        $failedActivity['lease_owner'], 'restart retry λ', 'RuntimeException');
    assertRestart($failureReceipt['recorded'] === true, 'Application failure/retry was not acknowledged durably.');
    $retryHistory = $client->workflowHistory($retryHandle->workflowId, $retryHandle->selectedRunId)['events'];
    assertRestart(array_column($retryHistory, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityRetryScheduled'], 'Reported retry was not pending at the kill checkpoint.');
    assertRestart($failureReceipt['next_task_id'] === $retryHistory[4]['payload']['retry_task_id']
        && new DateTimeImmutable($retryHistory[4]['payload']['retry_available_at']) > new DateTimeImmutable(), 'Retry identity/deadline was not retained before the kill.');
    assertRestart($client->pollActivityTask('restart-retry-old', 'restart-retry-v1', 0) === null, 'Retry was claimable before its recorded backoff.');
    $client->registerWorker('restart-terminal-old', 'restart-terminal-v1', ['restart.terminal'], ['restart.terminal.activity']);
    $terminalHandle = $client->startWorkflow('restart.terminal', 'rust-restart-terminal', 'restart-terminal-v1', [$value]);
    $terminalTask = $client->pollWorkflowTask('restart-terminal-old', 'restart-terminal-v1', 0);
    assertRestart(is_array($terminalTask) && $terminalTask['run_id'] === $terminalHandle->selectedRunId, 'Prepare did not lease the terminal workflow.');
    $terminalReplay = (new Replayer($client->payloadCodec()))->replay(restartTerminalWorkflow(...),
        $terminalTask['history_events'], $client->payloadCodec()->decodeEnvelope($terminalTask['arguments']), 'restart-terminal-v1', $terminalTask);
    $client->completeWorkflowTask($terminalTask['task_id'], $terminalTask['lease_owner'], $terminalTask['workflow_task_attempt'], $terminalReplay->commands);
    $terminalActivity = $client->pollActivityTask('restart-terminal-old', 'restart-terminal-v1', 0);
    assertRestart(is_array($terminalActivity) && $terminalActivity['attempt_number'] === 1, 'Prepare did not lease the terminal activity.');
    // Actual published client reports non-retryable=true with budget remaining.
    $terminalFailure = $client->failActivityTask($terminalActivity['task_id'], $terminalActivity['activity_attempt_id'],
        $terminalActivity['lease_owner'], 'restart terminal λ', 'RuntimeException', nonRetryable: true);
    $terminalHistory = $client->workflowHistory($terminalHandle->workflowId, $terminalHandle->selectedRunId)['events'];
    assertRestart($terminalFailure['recorded'] === true && array_column($terminalHistory, 'event_type')
        === ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityFailed'], 'Terminal failure was not acknowledged before the kill.');
    assertRestart($terminalHistory[4]['payload']['non_retryable'] === true
        && $terminalHistory[4]['payload']['activity']['status'] === 'failed'
        && $terminalHistory[4]['payload']['activity']['retry_policy']['max_attempts'] === 3, 'Explicit non-retryable report did not close the original activity with unused budget.');
    assertRestart($client->pollActivityTask('restart-terminal-old', 'restart-terminal-v1', 0) === null, 'Terminal failure unexpectedly scheduled another attempt.');
    $client->registerWorker('restart-cancel-old', 'restart-cancel-v1', ['restart.cancel'], ['restart.cancel.activity']);
    $cancelled = [];
    foreach (['leased_activity', 'pending_timer'] as $cancelPhase) {
        $cancelHandle = $client->startWorkflow('restart.cancel', 'rust-restart-cancel-'.$cancelPhase, 'restart-cancel-v1', [$value]);
        $cancelTask = $client->pollWorkflowTask('restart-cancel-old', 'restart-cancel-v1', 0);
        assertRestart(is_array($cancelTask) && $cancelTask['run_id'] === $cancelHandle->selectedRunId, 'Prepare did not claim original cancellation workflow.');
        $commands = $cancelPhase === 'pending_timer' ? [['type' => 'start_timer', 'delay_seconds' => 10]]
            : [['type' => 'schedule_activity', 'activity_type' => 'restart.cancel.activity', 'arguments' => $client->payloadCodec()->envelope([$value])]];
        $client->completeWorkflowTask($cancelTask['task_id'], $cancelTask['lease_owner'], $cancelTask['workflow_task_attempt'], $commands);
        $cancelActivity = $cancelPhase === 'leased_activity' ? $client->pollActivityTask('restart-cancel-old', 'restart-cancel-v1', 0) : null;
        assertRestart($cancelPhase !== 'leased_activity' || (is_array($cancelActivity) && $cancelActivity['attempt_number'] === 1), 'Prepare did not lease original cancelled attempt.');
        $cancelBefore = $client->workflowHistory($cancelHandle->workflowId, $cancelHandle->selectedRunId)['events'];
        $cancelAck = $client->cancelWorkflow($cancelHandle->workflowId, 'restart cancellation λ', $cancelHandle->selectedRunId);
        $cancelHistory = $client->workflowHistory($cancelHandle->workflowId, $cancelHandle->selectedRunId)['events'];
        $expected = $cancelPhase === 'leased_activity'
            ? ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'CancelRequested', 'ActivityCancelled', 'WorkflowCancelled']
            : ['StartAccepted', 'WorkflowStarted', 'TimerScheduled', 'CancelRequested', 'TimerCancelled', 'WorkflowCancelled'];
        assertRestart(array_column($cancelHistory, 'event_type') === $expected
            && sameRestartValue($cancelBefore, array_slice($cancelHistory, 0, count($cancelBefore)))
            && $cancelAck['command_id'] === $cancelHistory[array_key_last($cancelHistory)]['payload']['workflow_command_id'], 'Cancellation was not acknowledged with original history before the kill.');
        $cancelled[$cancelPhase] = ['workflow_id' => $cancelHandle->workflowId, 'run_id' => $cancelHandle->selectedRunId,
            'activity' => $cancelActivity, 'accepted' => $cancelAck, 'history' => $cancelHistory];
    }
    $receipt = ['workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId, 'activity' => $activity,
        'cancelled' => $cancelled,
        'retry' => ['workflow_id' => $retryHandle->workflowId, 'run_id' => $retryHandle->selectedRunId,
            'first_claim' => $failedActivity, 'failure_receipt' => $failureReceipt, 'history' => $retryHistory],
        'terminal' => ['workflow_id' => $terminalHandle->workflowId, 'run_id' => $terminalHandle->selectedRunId,
            'activity' => $terminalActivity, 'failure_receipt' => $terminalFailure, 'history' => $terminalHistory],
        'timer' => ['workflow_id' => $timerHandle->workflowId, 'run_id' => $timerHandle->selectedRunId, 'scheduled' => $timerHistory[2]['payload']],
        'signal' => ['workflow_id' => $signalHandle->workflowId, 'run_id' => $signalHandle->selectedRunId,
            'opened' => $signalHistory[2]['payload'], 'received' => $signalHistory[3]['payload'], 'response' => $signalCommand],
        'child' => ['workflow_id' => $parentHandle->workflowId, 'run_id' => $parentHandle->selectedRunId,
            'parent_task' => $parentTask, 'parent_commands' => $parentReplay->commands, 'parent_completion' => $parentCompletion,
            'scheduled' => $parentHistory[2]['payload'], 'started' => $parentHistory[3]['payload'], 'task' => $childTask]];
    file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR).PHP_EOL);
    echo json_encode(['phase' => $phase, 'outcome' => 'prepared', 'run_id' => $handle->selectedRunId,
        'lease_expires_at' => $activity['lease_expires_at'], 'pending_timer' => $receipt['timer'],
        'cancelled' => $cancelled,
        'pending_retry' => ['run_id' => $retryHandle->selectedRunId, 'task_id' => $failureReceipt['next_task_id'],
            'available_at' => $retryHistory[4]['payload']['retry_available_at']],
        'pending_terminal_resume' => ['run_id' => $terminalHandle->selectedRunId, 'task_id' => $terminalFailure['next_task_id'],
            'failure_id' => $terminalHistory[4]['payload']['failure_id']],
        'pending_child' => ['parent_run_id' => $parentHandle->selectedRunId, 'child_run_id' => $childTask['run_id'],
            'task_id' => $childTask['task_id'], 'lease_expires_at' => $childTask['lease_expires_at']]], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

assertRestart($phase === 'finish', 'Unknown restart phase.');
$receipt = json_decode(file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
$old = $receipt['activity'];
// Let the real persisted lease expire. Do not edit clocks/rows or revoke the
// old worker to imitate process-loss recovery.
$waitUntil = max(strtotime($old['lease_expires_at']), strtotime($receipt['child']['task']['lease_expires_at']),
    strtotime($receipt['cancelled']['pending_timer']['history'][2]['payload']['fire_at'])) + 1;
assertRestart($waitUntil - time() < 40, 'Unexpected unbounded lease wait.');
while (time() < $waitUntil) {
    usleep(100_000);
}
$client->registerWorker('restart-cancel-new', 'restart-cancel-v1', ['restart.cancel'], ['restart.cancel.activity']);
$cancelRecovery = [];
foreach ($receipt['cancelled'] as $cancelPhase => $cancelled) {
    $description = $client->describeWorkflow($cancelled['workflow_id'], $cancelled['run_id']);
    assertRestart($description->status === 'cancelled' && $description->output === null
        && sameRestartValue($description->input, [$value])
        && sameRestartValue($cancelled['history'], $client->workflowHistory($cancelled['workflow_id'], $cancelled['run_id'])['events']), 'Process recovery changed original cancelled run/history/input.');
    $cancelOutcome = null;
    try {
        $client->workflowHandle($cancelled['workflow_id'], $cancelled['run_id'])->resultOfSelectedRun(1);
    } catch (\DurableWorkflow\Exception\WorkflowCancelled $error) {
        $cancelOutcome = ['type' => $error::class, 'message' => $error->getMessage()];
    }
    assertRestart($cancelOutcome === ['type' => \DurableWorkflow\Exception\WorkflowCancelled::class, 'message' => 'restart cancellation λ'], 'Recovered cancellation lost its original typed SDK outcome.');
    $repeatCancel = null;
    try {
        $client->cancelWorkflow($cancelled['workflow_id'], 'replacement cancellation', $cancelled['run_id']);
    } catch (ServerException $error) {
        assertRestart($error->status === 409 && ($error->details['rejection_reason'] ?? null) === 'run_not_active', 'Recovered cancellation repeat was not refused.');
        $repeatCancel = $error->details;
    }
    assertRestart(is_array($repeatCancel) && $repeatCancel['command_id'] !== $cancelled['accepted']['command_id'], 'Repeat replaced original cancellation command.');
    $lateReceipts = [];
    if (is_array($cancelled['activity'])) {
        $claim = $cancelled['activity'];
        foreach (['complete', 'fail'] as $operation) {
            $refused = false;
            try {
                $operation === 'complete'
                    ? $client->completeActivityTask($claim['task_id'], $claim['activity_attempt_id'], $claim['lease_owner'], $value)
                    : $client->failActivityTask($claim['task_id'], $claim['activity_attempt_id'], $claim['lease_owner'], 'late failure', 'RuntimeException');
            } catch (ServerException $error) {
                $details = $error->details;
                $refused = $error->status === 409 && $details['recorded'] === false && $details['reason'] === 'run_cancelled'
                    && $details['outcome'] === 'ignored' && $details['task_id'] === $claim['task_id']
                    && $details['activity_attempt_id'] === $claim['activity_attempt_id'] && $details['lease_owner'] === $claim['lease_owner']
                    && $details['task_status'] === 'cancelled' && $details['activity_status'] === 'cancelled' && $details['attempt_status'] === 'cancelled';
                $lateReceipts[$operation] = $details;
            }
            assertRestart($refused, 'Pre-kill cancelled activity committed a late outcome.');
        }
    } else {
        assertRestart(new DateTimeImmutable($cancelled['history'][2]['payload']['fire_at']) < new DateTimeImmutable(), 'Cancelled timer recovery must run after the original timer deadline.');
    }
    assertRestart(sameRestartValue($cancelled['history'], $client->workflowHistory($cancelled['workflow_id'], $cancelled['run_id'])['events']), 'Stale/repeated cancellation changed original durable history.');
    $cancelRecovery[$cancelPhase] = ['execution' => $description->raw, 'outcome' => $cancelOutcome,
        'history' => $cancelled['history'], 'repeat' => $repeatCancel, 'late_receipts' => $lateReceipts];
}
assertRestart($client->pollWorkflowTask('restart-cancel-new', 'restart-cancel-v1', 0) === null
    && $client->pollActivityTask('restart-cancel-new', 'restart-cancel-v1', 0) === null, 'Cancelled work reactivated after process replacement.');
$retry = $receipt['retry'];
$firstRetryClaim = $retry['first_claim'];
assertRestart(sameRestartValue($retry['history'], $client->workflowHistory($retry['workflow_id'], $retry['run_id'])['events']), 'Acknowledged retry history changed across process loss.');
$failureDuplicate = $client->failActivityTask($firstRetryClaim['task_id'], $firstRetryClaim['activity_attempt_id'],
    $firstRetryClaim['lease_owner'], 'restart retry λ', 'RuntimeException');
assertRestart($failureDuplicate['recorded'] === false, 'Acknowledged pre-kill failure created another retry.');
$refuseOldRetryResult = function () use ($client, $firstRetryClaim, $value): void {
    $refused = false;
    try {
        $client->completeActivityTask($firstRetryClaim['task_id'], $firstRetryClaim['activity_attempt_id'], $firstRetryClaim['lease_owner'], $value);
    } catch (ServerException $exception) {
        $refused = $exception->status === 409;
    }
    assertRestart($refused, 'The failed pre-kill activity attempt committed a late result.');
};
$refuseOldRetryResult();
$terminal = $receipt['terminal'];
$terminalActivity = $terminal['activity'];
assertRestart(sameRestartValue($terminal['history'], $client->workflowHistory($terminal['workflow_id'], $terminal['run_id'])['events']), 'Original acknowledged terminal failure changed after the kill.');
$terminalFailureDuplicate = $client->failActivityTask($terminalActivity['task_id'], $terminalActivity['activity_attempt_id'],
    $terminalActivity['lease_owner'], 'restart terminal λ', 'RuntimeException', nonRetryable: true);
assertRestart($terminalFailureDuplicate['recorded'] === false, 'Pre-kill terminal failure created another outcome or parent resume.');
$refuseOldTerminalResult = function () use ($client, $terminalActivity, $value): void {
    $refused = false;
    try {
        $client->completeActivityTask($terminalActivity['task_id'], $terminalActivity['activity_attempt_id'], $terminalActivity['lease_owner'], $value);
    } catch (ServerException $failure) {
        $refused = $failure->status === 409;
    }
    assertRestart($refused, 'The terminal pre-kill attempt committed a late success.');
};
$refuseOldTerminalResult();
$client->registerWorker('restart-terminal-new', 'restart-terminal-v1', ['restart.terminal'], []);
$terminalClaim = $client->pollWorkflowTask('restart-terminal-new', 'restart-terminal-v1', 0);
assertRestart(is_array($terminalClaim) && $terminalClaim['task_id'] === $terminal['failure_receipt']['next_task_id']
    && $terminalClaim['run_id'] === $terminal['run_id']
    && sameRestartValue(restartControlHistory($terminalClaim['history_events']), $terminal['history'])
    && $terminalClaim['history_events'][4]['workflow_task_id'] === $terminalActivity['task_id'], 'Recovered terminal resumption lost original task/run/history.');
$terminalReplay = (new Replayer($client->payloadCodec()))->replay(restartTerminalWorkflow(...),
    $terminalClaim['history_events'], $client->payloadCodec()->decodeEnvelope($terminalClaim['arguments']), 'restart-terminal-v1', $terminalClaim);
$terminalResult = ['echo' => $value, 'caught' => ['type' => 'RuntimeException', 'message' => 'restart terminal λ', 'non_retryable' => true]];
assertRestart(count($terminalReplay->commands) === 1 && $terminalReplay->commands[0]['type'] === 'complete_workflow'
    && sameRestartValue($terminalResult, $client->payloadCodec()->decodeEnvelope($terminalReplay->commands[0]['result'])), 'Published replay did not catch the original non-retryable failure.');
$terminalCompletion = $client->completeWorkflowTask($terminalClaim['task_id'], $terminalClaim['lease_owner'], $terminalClaim['workflow_task_attempt'], $terminalReplay->commands);
$terminalHistory = $client->workflowHistory($terminal['workflow_id'], $terminal['run_id'])['events'];
assertRestart($terminalCompletion['recorded'] === true && array_column($terminalHistory, 'event_type')
    === ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityFailed', 'WorkflowCompleted']
    && sameRestartValue(array_slice($terminalHistory, 0, 5), $terminal['history']), 'Terminal recovery changed the acknowledged failure prefix or completed twice.');
assertRestart(sameRestartValue($terminalResult, $client->describeWorkflow($terminal['workflow_id'], $terminal['run_id'])->output), 'Recovered terminal caught result changed its original typed input.');
$terminalCompletionDuplicate = $client->completeWorkflowTask($terminalClaim['task_id'], $terminalClaim['lease_owner'], $terminalClaim['workflow_task_attempt'], $terminalReplay->commands);
assertRestart($terminalCompletionDuplicate['recorded'] === false
    && sameRestartValue($terminalHistory, $client->workflowHistory($terminal['workflow_id'], $terminal['run_id'])['events']), 'Recovered terminal completion created another outcome.');
$refuseOldTerminalResult();
assertRestart($client->pollWorkflowTask('restart-terminal-new', 'restart-terminal-v1', 0) === null, 'Terminal recovery left another parent resumption.');
$retryWorker = null;
$retryClaim = null;
$retryDeadline = microtime(true) + 15;
$retryWorker = (new Worker($client, 'restart-retry-v1', workerId: 'restart-retry-new', clock: function () use ($client, $retry, $retryDeadline, &$retryWorker): float {
    assertRestart(microtime(true) < $retryDeadline, 'Recovered retry exceeded its completion budget.');
    if ($client->describeWorkflow($retry['workflow_id'], $retry['run_id'])->status === 'completed') {
        $retryWorker?->requestShutdown();
    }

    return microtime(true);
}))->registerWorkflow('restart.retry', restartRetryWorkflow(...))
    ->registerActivity('restart.retry.activity', function (ActivityContext $context, array $input) use (&$retryClaim, $value): array {
        assertRestart($context->attemptNumber === 2 && sameRestartValue($input, $value), 'Recovered retry lost its original typed input or attempt count.');
        $retryClaim = ['task_id' => $context->taskId, 'activity_attempt_id' => $context->activityAttemptId,
            'lease_owner' => $context->leaseOwner, 'attempt_number' => $context->attemptNumber];

        return ['echo' => $input, 'attempt' => $context->attemptNumber];
    });
$retryWorker->run(0);
$retryExecution = $client->describeWorkflow($retry['workflow_id'], $retry['run_id']);
$retryResult = ['echo' => $value, 'attempt' => 2];
assertRestart($retryExecution->status === 'completed' && sameRestartValue($retryExecution->output, $retryResult), 'Recovered retry result differs.');
$retryHistory = $client->workflowHistory($retry['workflow_id'], $retry['run_id'])['events'];
assertRestart(array_column($retryHistory, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityRetryScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'], 'Recovered retry history differs or resolved twice.');
assertRestart(array_column($retryHistory, 'sequence') === range(1, 8)
    && sameRestartValue(array_slice($retryHistory, 0, 5), $retry['history']), 'Original acknowledged failure/retry receipt changed after recovery.');
assertRestart(is_array($retryClaim) && $retryClaim['task_id'] === $retry['history'][4]['payload']['retry_task_id']
    && $retryClaim['activity_attempt_id'] !== $firstRetryClaim['activity_attempt_id']
    && $retryClaim['lease_owner'] !== $firstRetryClaim['lease_owner'], 'Recovered worker did not execute the original retry task with a new attempt/owner.');
foreach ([5, 6] as $index) {
    assertRestart($retryHistory[$index]['payload']['activity_execution_id'] === $firstRetryClaim['activity_execution_id']
        && $retryHistory[$index]['payload']['activity_attempt_id'] === $retryClaim['activity_attempt_id']
        && $retryHistory[$index]['payload']['attempt_number'] === 2, 'Recovered retry result has a different activity/attempt relationship.');
}
assertRestart((new DateTimeImmutable($retryHistory[5]['payload']['task']['available_at']))->format('U.u')
    === (new DateTimeImmutable($retry['history'][4]['payload']['retry_available_at']))->format('U.u'), 'Recovered retry recomputed its original backoff deadline.');
assertRestart(new DateTimeImmutable($retryHistory[5]['payload']['task']['leased_at']) >= new DateTimeImmutable($retry['history'][4]['payload']['retry_available_at']), 'Recovered retry claimed before its original deadline.');
assertRestart($retryHistory[5]['payload']['activity']['arguments'] === $retry['history'][2]['payload']['activity']['arguments'], 'Recovered retry changed original argument bytes.');
$retryDuplicate = $client->completeActivityTask($retryClaim['task_id'], $retryClaim['activity_attempt_id'], $retryClaim['lease_owner'], $retryResult);
assertRestart($retryDuplicate['recorded'] === false
    && sameRestartValue($retryHistory, $client->workflowHistory($retry['workflow_id'], $retry['run_id'])['events']), 'Recovered retry completion created another outcome.');
$refuseOldRetryResult();
$refused = false;
try {
    $client->completeActivityTask($old['task_id'], $old['activity_attempt_id'], $old['lease_owner'], $value);
} catch (ServerException $exception) {
    $refused = $exception->status === 409;
}
assertRestart($refused, 'Expired pre-kill activity claim was accepted.');
$child = $receipt['child'];
$oldChild = $child['task'];
$staleChildCommands = [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($value)]];
$refuseOldChild = function () use ($client, $oldChild, $staleChildCommands): void {
    $refused = false;
    try {
        $client->completeWorkflowTask($oldChild['task_id'], $oldChild['lease_owner'], $oldChild['workflow_task_attempt'], $staleChildCommands);
    } catch (ServerException $exception) {
        $refused = $exception->status === 409;
    }
    assertRestart($refused, 'Expired pre-kill child workflow claim was accepted.');
};
$refuseOldChild();
$client->registerWorker('restart-child-new', 'restart-v1', ['restart.child'], []);
$recoveredChild = $client->pollWorkflowTask('restart-child-new', 'restart-v1', 0);
assertRestart(is_array($recoveredChild) && $recoveredChild['task_id'] === $oldChild['task_id']
    && $recoveredChild['run_id'] === $oldChild['run_id'] && $recoveredChild['workflow_task_attempt'] === 2
    && $recoveredChild['lease_owner'] !== $oldChild['lease_owner'], 'Replacement did not recover the original child lease as attempt two.');
assertRestart(sameRestartValue($oldChild['history_events'], $recoveredChild['history_events'])
    && sameRestartValue($oldChild['arguments'], $recoveredChild['arguments']), 'Original child input/history changed across process loss.');
$refuseOldChild();
$childReplay = (new Replayer($client->payloadCodec()))->replay(restartChildWorkflow(...),
    $recoveredChild['history_events'], $client->payloadCodec()->decodeEnvelope($recoveredChild['arguments']), 'restart-v1', $recoveredChild);
assertRestart(count($childReplay->commands) === 1 && $childReplay->commands[0]['type'] === 'schedule_activity', 'Recovered SDK child did not author its real nested activity.');
$childCompletion = $client->completeWorkflowTask($recoveredChild['task_id'], $recoveredChild['lease_owner'], $recoveredChild['workflow_task_attempt'], $childReplay->commands);
$childDuplicate = $client->completeWorkflowTask($recoveredChild['task_id'], $recoveredChild['lease_owner'], $recoveredChild['workflow_task_attempt'], $childReplay->commands);
assertRestart($childCompletion['recorded'] === true && $childDuplicate['recorded'] === false, 'Recovered child turn did not retain its immutable receipt.');
$worker = null;
$deadline = microtime(true) + 25;
$clock = function () use ($client, $receipt, $deadline, &$worker): float {
    $now = microtime(true);
    if ($now > $deadline) {
        throw new RuntimeException('Restart did not complete within its execution budget.');
    }
    if ($client->describeWorkflow($receipt['workflow_id'], $receipt['run_id'])->status === 'completed'
        && $client->describeWorkflow($receipt['timer']['workflow_id'], $receipt['timer']['run_id'])->status === 'completed'
        && $client->describeWorkflow($receipt['signal']['workflow_id'], $receipt['signal']['run_id'])->status === 'completed'
        && $client->describeWorkflow($receipt['child']['workflow_id'], $receipt['child']['run_id'])->status === 'completed'
        && $client->describeWorkflow($receipt['child']['task']['workflow_id'], $receipt['child']['task']['run_id'])->status === 'completed') {
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
$worker->registerWorkflow('restart.parent', restartParentWorkflow(...));
$worker->registerWorkflow('restart.child', restartChildWorkflow(...));
$worker->registerActivity('restart.activity', function (ActivityContext $context, array $input): array {
    assertRestart($context->attemptNumber === 2, 'Recovered worker did not execute attempt two.');

    return $input;
});
$worker->registerActivity('restart.child.activity', function (ActivityContext $context, array $input): array {
    assertRestart($context->attemptNumber === 1, 'Child nested activity was duplicated by recovery.');

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
assertRestart($signalExecution->status === 'completed' && sameRestartValue($value, $signalExecution->output), 'Pending signal did not recover its original typed outcome.');
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
assertRestart(sameRestartValue([$value], $client->payloadCodec()->decodeEnvelope($signalHistory[3]['payload']['arguments']))
    && sameRestartValue($value, $client->payloadCodec()->decodeEnvelope($signalHistory[5]['payload']['value'])), 'Accepted/applied signal payload changed across process loss.');
$parentExecution = $client->describeWorkflow($child['workflow_id'], $child['run_id']);
$childExecution = $client->describeWorkflow($oldChild['workflow_id'], $oldChild['run_id']);
assertRestart($parentExecution->status === 'completed' && sameRestartValue($value, $parentExecution->output)
    && $childExecution->status === 'completed' && sameRestartValue($value, $childExecution->output), 'Recovered parent/child durable results differ.');
$parentHistory = $client->workflowHistory($child['workflow_id'], $child['run_id'])['events'];
$childHistory = $client->workflowHistory($oldChild['workflow_id'], $oldChild['run_id'])['events'];
assertRestart(array_column($parentHistory, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'ChildWorkflowScheduled', 'ChildRunStarted', 'ChildRunCompleted', 'WorkflowCompleted'], 'Recovered parent history differs or resolved twice.');
assertRestart(array_column($childHistory, 'event_type') === ['WorkflowStarted', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'], 'Recovered child history differs or scheduled its activity twice.');
assertRestart(array_column($parentHistory, 'sequence') === range(1, 6)
    && array_column($childHistory, 'sequence') === range(1, 5), 'Recovered child-family history is not contiguous.');
foreach ([2, 3, 4] as $index) {
    foreach (['sequence', 'workflow_link_id', 'child_call_id', 'child_workflow_instance_id', 'child_workflow_run_id', 'child_workflow_type'] as $key) {
        assertRestart($parentHistory[$index]['payload'][$key] === $child['scheduled'][$key], 'An acknowledged original child identity changed after the kill.');
    }
}
assertRestart($childHistory[0]['payload']['parent_workflow_instance_id'] === $child['workflow_id']
    && $childHistory[0]['payload']['parent_workflow_run_id'] === $child['run_id']
    && $childHistory[0]['payload']['workflow_link_id'] === $child['scheduled']['workflow_link_id'], 'Recovered child refers to another parent/call.');
assertRestart(sameRestartValue($value, $client->payloadCodec()->decodeEnvelope($parentHistory[4]['payload']['output']))
    && $parentHistory[4]['payload']['result'] === $parentHistory[4]['payload']['output'], 'Parent did not receive the exact committed child result.');
$parentDuplicate = $client->completeWorkflowTask($child['parent_task']['task_id'], $child['parent_task']['lease_owner'],
    $child['parent_task']['workflow_task_attempt'], $child['parent_commands']);
assertRestart($parentDuplicate['recorded'] === false
    && sameRestartValue($parentHistory, $client->workflowHistory($child['workflow_id'], $child['run_id'])['events']), 'Retrying acknowledged child creation changed the completed parent history.');
$client->deregisterWorkerRegistration('restart-old');
$client->deregisterWorkerRegistration('restart-child-old');
$client->deregisterWorkerRegistration('restart-child-new');
$client->deregisterWorkerRegistration('restart-retry-old');
$client->deregisterWorkerRegistration('restart-terminal-old');
$client->deregisterWorkerRegistration('restart-terminal-new');
$client->deregisterWorkerRegistration('restart-cancel-old');
$client->deregisterWorkerRegistration('restart-cancel-new');
echo json_encode(['phase' => $phase, 'outcome' => 'pass', 'run_id' => $execution->runId,
    'cancellation_recovered' => true, 'cancellation' => $cancelRecovery,
    'terminal_recovered' => true, 'terminal_history' => $terminalHistory, 'terminal_claim' => $terminalClaim,
    'terminal_completion' => $terminalCompletion, 'terminal_failure_duplicate' => $terminalFailureDuplicate,
    'terminal_completion_duplicate' => $terminalCompletionDuplicate,
    'retry_recovered' => true, 'retry_history' => $retryHistory, 'retry_claim' => $retryClaim,
    'retry_failure_duplicate' => $failureDuplicate, 'retry_completion_duplicate' => $retryDuplicate,
    'stale_claim_refused' => true, 'history' => $history, 'timer_recovered' => true, 'timer_history' => $timerHistory,
    'signal_recovered' => true, 'signal_history' => $signalHistory, 'child_recovered' => true,
    'stale_child_claim_refused' => true, 'child_claim' => $recoveredChild, 'child_completion' => $childCompletion,
    'child_duplicate' => $childDuplicate, 'parent_duplicate' => $parentDuplicate,
    'parent_history' => $parentHistory, 'child_history' => $childHistory], JSON_THROW_ON_ERROR).PHP_EOL;
