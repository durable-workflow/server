<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Model\ScheduleAction;
use DurableWorkflow\Model\ScheduleSpec;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleManager;

function parityScheduleHistory(Client $client, string $scheduleId): array
{
    $events = [];
    $after = null;
    do {
        $page = $client->scheduleHistory($scheduleId, 2, $after);
        $events = [...$events, ...$page['events']];
        $next = $page['next_cursor'] ?? null;
        if ($next !== null && ($after !== null && $next <= $after || $page['events'] === [])) {
            throw new RuntimeException('Schedule history cursor did not advance.');
        }
        $after = $next;
    } while ($after !== null);

    return $events;
}

function parityScheduleTrigger(array $events): ?array
{
    foreach ($events as $event) {
        if ($event['event_type'] === 'ScheduleTriggered') {
            return $event;
        }
    }

    return null;
}

function prepareHttpSchedule(Client $client, array $fixture, string $scheduleId, string $queue, array &$state): array
{
    $definition = $fixture['schedule'];
    $state = ['schedule_id' => $scheduleId];
    $handle = $client->createSchedule(
        new ScheduleSpec(intervals: $definition['spec']['intervals'], timezone: $definition['spec']['timezone']),
        new ScheduleAction($fixture['workflow_type'], $queue, [$fixture['input']],
            $definition['action_timeouts']['execution_timeout_seconds'], $definition['action_timeouts']['run_timeout_seconds']),
        scheduleId: $scheduleId, overlapPolicy: $definition['overlap_policy'],
        jitterSeconds: $definition['jitter_seconds'], maxRuns: $definition['max_runs'] ?? null,
    );
    $state['created_history'] = parityScheduleHistory($client, $scheduleId);
    if ($definition['phase'] === 'manual_lifecycle') {
        $state['created'] = $client->describeSchedule($scheduleId)->raw;
        $state['typed_action_input'] = typedValue($client->payloadCodec()->decodeEnvelope($state['created']['action']['input']));
        $client->pauseSchedule($scheduleId);
        $state['paused'] = $client->describeSchedule($scheduleId)->raw;
        $state['paused_trigger'] = $client->triggerSchedule($scheduleId);
        $state['paused_history'] = parityScheduleHistory($client, $scheduleId);
        $client->resumeSchedule($scheduleId);
        $state['resumed'] = $client->describeSchedule($scheduleId)->raw;
        $state['trigger'] = $client->triggerSchedule($scheduleId);
    } else {
        // The separate installed schedule:evaluate role fires the original
        // occurrence. This probe never moves a deadline or writes target rows.
        $deadline = microtime(true) + 10;
        do {
            $history = parityScheduleHistory($client, $scheduleId);
            $trigger = parityScheduleTrigger($history);
            if ($trigger !== null) {
                $state['trigger'] = ['schedule_id' => $scheduleId,
                    'workflow_id' => $trigger['workflow_instance_id'], 'run_id' => $trigger['workflow_run_id']];
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The installed scheduler did not fire the original occurrence within ten seconds.');
            }
            usleep(50000);
        } while (true);
        $state['typed_action_input'] = typedValue($client->payloadCodec()->decodeEnvelope($state['created_history'][0]['payload']['action']['input']));
    }
    $state['history_before_worker'] = parityScheduleHistory($client, $scheduleId);

    return $state['trigger'];
}

function finishHttpSchedule(Client $client, array $fixture, array $state, string $workflowId, string $runId): array
{
    $scheduleId = $state['schedule_id'];
    $state['history_after_workflow'] = parityScheduleHistory($client, $scheduleId);
    if ($fixture['schedule']['phase'] === 'manual_lifecycle') {
        $state['after_workflow'] = $client->describeSchedule($scheduleId)->raw;
        $client->deleteSchedule($scheduleId);
    } else {
        // Two additional real interval boundaries must not admit another fire.
        $state['later_started_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
        usleep($fixture['schedule']['later_interval_checks'] * 2 * 1000000);
        $state['later_finished_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }
    foreach (['describe', 'trigger'] as $operation) {
        try {
            $state['after_delete_'.$operation] = ['outcome' => 'observed', 'value' => $operation === 'describe'
                ? $client->describeSchedule($scheduleId)->raw : $client->triggerSchedule($scheduleId)];
        } catch (ServerException $error) {
            $state['after_delete_'.$operation] = ['status' => $error->status, 'reason' => $error->reason, 'response' => $error->details];
        }
    }
    $state['fresh_history'] = parityScheduleHistory($client, $scheduleId);
    $state['fresh_workflow'] = $client->describeWorkflow($workflowId, $runId)->raw;
    $state['fresh_workflow_history'] = httpHistory($client, $workflowId, $runId);
    $workerId = 'post-schedule-'.$scheduleId;
    $client->registerWorker($workerId, 'server-parity-v1', [$fixture['workflow_type']], []);
    try {
        $state['post_close_poll'] = $client->pollWorkflowTask($workerId, 'server-parity-v1', 0);
    } finally {
        $client->deregisterWorker($workerId);
    }

    return $state;
}

function parityEmbeddedScheduleHistory(WorkflowSchedule $schedule): array
{
    return $schedule->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
        'id' => $event->id, 'sequence' => (int) $event->sequence, 'event_type' => $event->event_type->value,
        'payload' => $event->payload, 'workflow_instance_id' => $event->workflow_instance_id,
        'workflow_run_id' => $event->workflow_run_id, 'recorded_at' => $event->recorded_at->toIso8601String(),
    ])->all();
}

function prepareEmbeddedSchedule(array $fixture, string $scheduleId, string $queue, string $class, array &$state): array
{
    // The fixture host is the Server image. Restore the installed package's
    // ordinary embedded starter instead of the host's remote Server binding.
    app()->singleton(\Workflow\V2\Contracts\ScheduleWorkflowStarter::class, \Workflow\V2\Support\PhpClassScheduleStarter::class);
    $definition = $fixture['schedule'];
    $schedule = ScheduleManager::createFromSpec($scheduleId, $definition['spec'],
        ['workflow_type' => $fixture['workflow_type'], 'workflow_class' => $class, 'input' => [$fixture['input']],
            ...$definition['action_timeouts']], ScheduleOverlapPolicy::Skip,
        jitterSeconds: $definition['jitter_seconds'], maxRuns: $definition['max_runs'] ?? null,
        connection: 'database', queue: $queue, namespace: 'default');
    $state = ['schedule_id' => $scheduleId, 'created' => $schedule->toArray(),
        'created_history' => parityEmbeddedScheduleHistory($schedule), 'typed_action_input' => typedValue($schedule->action['input'])];
    if ($definition['phase'] === 'manual_lifecycle') {
        ScheduleManager::pause($schedule);
        $state['paused'] = $schedule->fresh()->toArray();
        $skipped = ScheduleManager::triggerDetailed($schedule);
        $state['paused_trigger'] = ['outcome' => $skipped->outcome, 'reason' => $skipped->reason];
        $state['paused_history'] = parityEmbeddedScheduleHistory($schedule);
        ScheduleManager::resume($schedule);
        $state['resumed'] = $schedule->fresh()->toArray();
        $result = ScheduleManager::triggerDetailed($schedule);
        $state['trigger'] = ['schedule_id' => $scheduleId, 'outcome' => $result->outcome,
            'workflow_id' => $result->instanceId, 'run_id' => $result->runId];
    } else {
        $deadline = microtime(true) + 10;
        do {
            $state['tick_results'][] = ScheduleManager::tick(100);
            $trigger = parityScheduleTrigger(parityEmbeddedScheduleHistory($schedule));
            if ($trigger !== null) {
                $state['trigger'] = ['schedule_id' => $scheduleId,
                    'workflow_id' => $trigger['workflow_instance_id'], 'run_id' => $trigger['workflow_run_id']];
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Embedded schedule tick did not fire the original occurrence within ten seconds.');
            }
            usleep(50000);
        } while (true);
    }
    $state['history_before_worker'] = parityEmbeddedScheduleHistory($schedule);

    return $state['trigger'];
}

function finishEmbeddedSchedule(array $fixture, array $state, string $workflowId, string $runId): array
{
    $schedule = WorkflowSchedule::query()->where('namespace', 'default')->where('schedule_id', $state['schedule_id'])->firstOrFail();
    $state['history_after_workflow'] = parityEmbeddedScheduleHistory($schedule);
    $state['after_workflow'] = $schedule->toArray();
    if ($fixture['schedule']['phase'] === 'manual_lifecycle') {
        ScheduleManager::delete($schedule);
    } else {
        $state['later_started_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
        $deadline = microtime(true) + $fixture['schedule']['later_interval_checks'] * 2;
        do {
            $state['later_tick_results'][] = ScheduleManager::tick(100);
            usleep(50000);
        } while (microtime(true) < $deadline);
        $state['later_tick_results'][] = ScheduleManager::tick(100);
        $state['later_finished_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }
    $state['fresh_schedule'] = $schedule->fresh()->toArray();
    $state['physical_schedule_rows'] = WorkflowSchedule::query()->where('namespace', 'default')->where('schedule_id', $state['schedule_id'])->count();
    $state['related_run_ids'] = \Workflow\V2\Models\WorkflowHistoryEvent::query()
        ->where('event_type', 'ScheduleTriggered')->get()->filter(static fn ($event): bool => ($event->payload['schedule_id'] ?? null) === $state['schedule_id'])
        ->pluck('workflow_run_id')->values()->all();
    $state['fresh_history'] = parityEmbeddedScheduleHistory($schedule);
    $run = \Workflow\V2\Models\WorkflowRun::query()->findOrFail($runId);
    $state['fresh_workflow'] = ['workflow_id' => $run->workflow_instance_id, 'run_id' => $run->id, 'status' => $run->status->value,
        'typed_input' => typedValue($run->workflowArguments()), 'typed_output' => typedValue($run->workflowOutput())];

    return $state;
}
