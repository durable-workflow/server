<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\WorkflowCancelled;
use DurableWorkflow\Worker\CancellationDelivery;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/observation-values.php';
require __DIR__.'/cooperative-probe.php';

function assertCooperativeRecovery(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function sameCooperativeValue(mixed $expected, mixed $actual): bool
{
    if (! is_array($expected)) {
        return $expected === $actual;
    }
    if (! is_array($actual) || array_is_list($expected) !== array_is_list($actual) || count($expected) !== count($actual)) {
        return false;
    }
    foreach ($expected as $key => $value) {
        if (! array_key_exists($key, $actual) || ! sameCooperativeValue($value, $actual[$key])) {
            return false;
        }
    }

    return true;
}

function cooperativeEvent(array $events, string $type): array
{
    $matches = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === $type));
    assertCooperativeRecovery(count($matches) === 1, 'Expected one original '.$type.' event.');

    return $matches[0];
}

[$script, $mode, $url, $receiptPath] = $argv;
assertCooperativeRecovery(in_array($mode, ['prepare', 'finish'], true), 'Mode must be prepare or finish.');
$receipts = [];
if ($mode === 'prepare') {
    foreach (['completed', 'deadline_expired'] as $outcome) {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../../tests/Fixtures/ServerParity/cooperative-pending-timer-cleanup.json'), true, flags: JSON_THROW_ON_ERROR);
        // Real longer timers give the external Docker kill a pending cleanup
        // checkpoint. These are recovery variants, not added corpus totals.
        $fixture['input']['cleanup_delay_seconds'] = $outcome === 'completed' ? 10 : 60;
        $fixture['cooperative_cancellation']['cleanup_timeout_seconds'] = $outcome === 'completed' ? 60 : 5;
        $workflowId = 'root-restart-'.$outcome;
        $queue = 'root-restart-'.$outcome;
        $traffic = [];
        $client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
            transport: cooperativeObservedTransport($traffic), workerProtocolVersion: '1.20');
        $worker = $handle = $accepted = null;
        $guard = microtime(true) + 20;
        $worker = cooperativeCleanupWorker($client, $queue,
            static function () use (&$worker, &$handle, &$accepted, $client, $workflowId, $fixture, $guard): float {
                assertCooperativeRecovery(microtime(true) < $guard, 'Prepare exceeded its real-time bound.');
                if ($handle !== null) {
                    $events = httpHistory($client, $workflowId, $handle->selectedRunId);
                    $timers = array_values(array_filter($events, static fn (array $event): bool => $event['event_type'] === 'TimerScheduled'));
                    if ($accepted === null && $timers !== []) {
                        $accepted = $client->requestWorkflowCancellation($workflowId,
                            $fixture['cooperative_cancellation']['reason'], $fixture['cooperative_cancellation']['cleanup_timeout_seconds'], $handle->selectedRunId);
                    }
                    if (count($timers) === 2) {
                        $worker->requestShutdown();
                    }
                }

                return microtime(true);
            },
            static function (string $event) use (&$handle, $client, $fixture, $workflowId, $queue): void {
                if ($event === 'worker.registered') {
                    $handle = $client->startWorkflow($fixture['workflow_type'], $workflowId, $queue, [$fixture['input']]);
                }
            });
        $worker->run(0);
        $events = httpHistory($client, $workflowId, $handle->selectedRunId);
        assertCooperativeRecovery(array_column($events, 'event_type') === ['StartAccepted', 'WorkflowStarted', 'TimerScheduled',
            'CooperativeCancellationRequested', 'TimerCancelled', 'CooperativeCancellationDelivered', 'TimerScheduled'], 'Kill checkpoint is not pending root cleanup.');
        assertCooperativeRecovery(! $handle->describeSelectedRun()->isTerminal, 'Cleanup closed before the kill checkpoint.');
        $requested = cooperativeEvent($events, 'CooperativeCancellationRequested');
        $command = $client->payloadCodec()->decodeEnvelope($requested['payload']['command']['payload']);
        assertCooperativeRecovery(sameCooperativeValue($command['cancellation'], $requested['payload']['cancellation']), 'Official codec changed the original root context.');
        $deliveryId = $deliveryTask = null;
        foreach ($traffic as $packet) {
            if (str_ends_with($packet['path'], '/deliver-cancellation') && ($packet['response']['delivered'] ?? null) === true) {
                $deliveryTask = ['task_id' => $packet['response']['task_id'], 'request' => $packet['request']];
            }
            foreach ($packet['response']['history_events'] ?? $packet['response']['task']['history_events'] ?? [] as $event) {
                if ($event['event_type'] === 'CooperativeCancellationDelivered') {
                    $deliveryId = $event['id'];
                }
            }
        }
        assertCooperativeRecovery(is_string($deliveryId) && $deliveryId !== '' && is_array($deliveryTask), 'Actual worker delivery proof is missing.');
        $receipts[$outcome] = ['workflow_id' => $workflowId, 'run_id' => $handle->selectedRunId, 'queue' => $queue,
            'fixture' => $fixture, 'accepted' => $accepted, 'history' => $events, 'context' => $command['cancellation'],
            'delivery_id' => $deliveryId, 'delivery_task' => $deliveryTask, 'traffic' => $traffic];
    }
    file_put_contents($receiptPath, json_encode($receipts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    echo json_encode(['outcome' => 'prepared', 'checkpoint' => 'pending shielded cleanup timers', 'cases' => array_keys($receipts)], JSON_THROW_ON_ERROR)."\n";
    exit;
}

$receipts = json_decode(file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR);
$results = [];
// Observe the original short deadline first; the completed case has its own
// original 60-second budget and a real 10-second cleanup timer.
foreach (['deadline_expired', 'completed'] as $outcome) {
    $receipt = $receipts[$outcome];
    $traffic = [];
    $client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
        transport: cooperativeObservedTransport($traffic), workerProtocolVersion: '1.20');
    $handle = $client->workflowHandle($receipt['workflow_id'], $receipt['run_id']);
    $before = httpHistory($client, $receipt['workflow_id'], $receipt['run_id']);
    assertCooperativeRecovery(sameCooperativeValue($receipt['history'], array_slice($before, 0, count($receipt['history']))), 'Recovery changed acknowledged history.');
    $duplicate = $client->requestWorkflowCancellation($receipt['workflow_id'], 'replacement after process kill', 3600, $receipt['run_id']);
    foreach (['request_id', 'requested_at', 'cleanup_deadline_at'] as $field) {
        assertCooperativeRecovery($duplicate['cancellation_request'][$field] === $receipt['accepted']['cancellation_request'][$field], 'Recovery restarted the original request/budget.');
    }
    $worker = null;
    $guard = microtime(true) + 70;
    $worker = cooperativeCleanupWorker($client, $receipt['queue'],
        static function () use (&$worker, $handle, $guard): float {
            assertCooperativeRecovery(microtime(true) < $guard, 'Recovery exceeded its real-time bound.');
            if ($handle->describeSelectedRun()->isTerminal) {
                $worker->requestShutdown();
            }

            return microtime(true);
        }, static function (string $event): void {});
    $worker->run(0);
    $execution = $handle->describeSelectedRun();
    $events = httpHistory($client, $receipt['workflow_id'], $receipt['run_id']);
    assertCooperativeRecovery(sameCooperativeValue($receipt['history'], array_slice($events, 0, count($receipt['history']))), 'Terminal recovery changed the original prefix.');
    $requested = cooperativeEvent($events, 'CooperativeCancellationRequested');
    $delivered = cooperativeEvent($events, 'CooperativeCancellationDelivered');
    $terminal = cooperativeEvent($events, 'WorkflowCancelled');
    $cleanup = $terminal['payload']['cancellation_cleanup'];
    assertCooperativeRecovery($execution->status === 'cancelled' && $execution->output === null, 'Cleanup recovery did not end cancelled.');
    assertCooperativeRecovery($cleanup['outcome'] === $outcome
        && $cleanup['request_id'] === $receipt['context']['request_id']
        && $cleanup['cleanup_deadline_at'] === $receipt['context']['cleanup_deadline_at']
        && $cleanup['delivery_history_event_id'] === $receipt['delivery_id']
        && $cleanup['delivery_sequence'] === $delivered['payload']['sequence'], 'Recovery lost original cleanup identity/budget/outcome.');
    assertCooperativeRecovery(sameCooperativeValue($receipt['context'], $requested['payload']['cancellation'])
        && sameCooperativeValue($receipt['context'], $delivered['payload']['cancellation']), 'Recovery changed canonical context.');
    $finished = new DateTimeImmutable($cleanup['finished_at']);
    $deadline = new DateTimeImmutable($receipt['context']['cleanup_deadline_at']);
    assertCooperativeRecovery(($finished >= $deadline) === ($outcome === 'deadline_expired'), 'Terminal outcome ignored the original wall-clock deadline.');
    $decoded = decodeHistory($events, $client->payloadCodec()->decodeEnvelope(...));
    if ($outcome === 'completed') {
        $scheduled = cooperativeEvent($decoded, 'ActivityScheduled');
        $completed = cooperativeEvent($decoded, 'ActivityCompleted');
        $heartbeat = cooperativeEvent($events, 'ActivityHeartbeatRecorded');
        assertCooperativeRecovery(sameCooperativeValue($scheduled['decoded']['activity_arguments'], [$receipt['fixture']['input'], $receipt['context']])
            && sameCooperativeValue($completed['decoded']['result'], ['value' => $receipt['fixture']['input'], 'cancellation' => $receipt['context']]), 'Recovered cleanup lost typed int64 input/context/result.');
        assertCooperativeRecovery(($heartbeat['payload']['progress']['details']['request_id'] ?? null) === $receipt['context']['request_id'], 'Recovered activity heartbeat lost root identity.');
    } else {
        assertCooperativeRecovery(! in_array('ActivityScheduled', array_column($events, 'event_type'), true), 'Expired cleanup scheduled an activity.');
    }
    assertCooperativeRecovery(! in_array('WorkflowCompleted', array_column($events, 'event_type'), true), 'Cleanup swallowed root cancellation.');
    $old = $receipt['delivery_task'];
    $late = [];
    foreach (['heartbeat', 'delivery', 'completion'] as $operation) {
        try {
            match ($operation) {
                'heartbeat' => $client->heartbeatWorkflowTask($old['task_id'], $old['request']['lease_owner'], $old['request']['workflow_task_attempt']),
                'delivery' => $client->deliverWorkflowCancellation($old['task_id'], $old['request']['lease_owner'], $old['request']['workflow_task_attempt'],
                    CancellationDelivery::fromPayload(['workflow_command_id' => $receipt['context']['request_id'], 'sequence' => 1, 'call_kind' => 'timer'])),
                'completion' => $client->completeWorkflowTask($old['task_id'], $old['request']['lease_owner'], $old['request']['workflow_task_attempt'],
                    [['type' => 'complete_workflow', 'result' => $client->payloadCodec()->envelope($receipt['fixture']['input'])]]),
            };
            throw new RuntimeException('Recovered stale '.$operation.' was accepted.');
        } catch (ServerException $error) {
            assertCooperativeRecovery($error->status === 409, 'Recovered stale operation was not fenced.');
            $late[$operation] = ['status' => $error->status, 'response' => $error->details];
        }
    }
    $duplicate = $client->requestWorkflowCancellation($receipt['workflow_id'], 'post-terminal replacement', 3600, $receipt['run_id']);
    assertCooperativeRecovery($duplicate['duplicate'] === true && $duplicate['cancellation_request']['cleanup_deadline_at'] === $receipt['context']['cleanup_deadline_at'], 'Terminal duplicate changed the original budget.');
    $fresh = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null, workerProtocolVersion: '1.20');
    assertCooperativeRecovery(sameCooperativeValue($events, httpHistory($fresh, $receipt['workflow_id'], $receipt['run_id'])), 'Late calls or fresh reads changed terminal history.');
    try {
        $fresh->workflowHandle($receipt['workflow_id'], $receipt['run_id'])->resultOfSelectedRun(1);
        throw new RuntimeException('Recovered root returned success.');
    } catch (WorkflowCancelled $error) {
        $sdkOutcome = ['type' => $error::class, 'message' => $error->getMessage()];
    }
    $results[$outcome] = ['status' => $execution->status, 'execution' => $execution->raw, 'events' => $decoded,
        'cleanup' => $cleanup, 'late_calls' => $late, 'terminal_duplicate' => $duplicate, 'sdk_outcome' => $sdkOutcome, 'traffic' => $traffic];
}
echo json_encode(['outcome' => 'pass', 'cases' => $results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
