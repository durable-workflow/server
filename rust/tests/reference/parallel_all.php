<?php

declare(strict_types=1);

// Pure published projectors consume actual source-test history and metadata.
require $argv[1];
$container = new Illuminate\Container\Container();
$container->instance('config', new Illuminate\Config\Repository());
Illuminate\Container\Container::setInstance($container);
$database = new Illuminate\Database\Capsule\Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->bootEloquent();
$database->getConnection()->enableQueryLog();
$projector = Workflow\V2\Support\RunSummaryProjector::class;
$methods = [];
foreach (['isAuthoritativeOpenActivity', 'isAuthoritativeOpenTimer', 'nextOpenTask', 'replayBlockedTask', 'liveness', 'taskProblemDetected'] as $method) {
    $methods[$method] = new ReflectionMethod($projector, $method);
}
$currentWait = new ReflectionMethod(Workflow\V2\Support\RunCurrentWaits::class, 'wait');
$cases = 0;
$waits = 0;
foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $summary = $case['summary'];
    $run = new Workflow\V2\Models\WorkflowRun();
    $run->setRawAttributes(['id' => $summary['run_id'], 'workflow_instance_id' => $summary['instance_id'],
        'status' => $summary['status'], 'closed_reason' => $summary['closed_reason'], 'compatibility' => null]);
    foreach (['commands', 'activityExecutions', 'timers', 'childLinks', 'updates', 'failures'] as $relation) {
        $run->setRelation($relation, new Illuminate\Database\Eloquent\Collection());
    }
    $events = [];
    foreach ($case['raw_history'] as $index => $raw) {
        $event = new Workflow\V2\Models\WorkflowHistoryEvent();
        $event->setRawAttributes(['id' => $raw['id'], 'workflow_run_id' => $summary['run_id'],
            'sequence' => $index + 1, 'event_type' => $raw['event_type'],
            'payload' => $raw['payload_json'], 'recorded_at' => $raw['recorded_at']]);
        $events[] = $event;
    }
    $run->setRelation('historyEvents', new Illuminate\Database\Eloquent\Collection($events));
    $tasks = [];
    foreach ($case['tasks'] as $raw) {
        $task = new Workflow\V2\Models\WorkflowTask();
        $task->setRawAttributes($raw);
        $tasks[] = $task;
    }
    $run->setRelation('tasks', new Illuminate\Database\Eloquent\Collection($tasks));
    $metadata = [];
    $attempts = [];
    foreach ($summary['reference_activities'] as $raw) {
        $activity = new Workflow\V2\Models\ActivityExecution();
        $activity->setRawAttributes($raw);
        $metadata[$raw['id']] = $activity;
        if ($raw['current_attempt_id'] !== null) {
            $attempt = new Workflow\V2\Models\ActivityAttempt();
            $attempt->setRawAttributes(['id' => $raw['current_attempt_id'], 'activity_execution_id' => $raw['id'],
                'attempt_number' => $raw['attempt_number']]);
            $attempts[$raw['id']] = $attempt;
        }
    }
    $activities = Workflow\V2\Support\RunActivityView::activitiesForRun($run, decodePayloads: false);
    $timers = Workflow\V2\Support\RunTimerView::timersForRun($run);
    $active = array_values(array_filter($activities, static fn (array $item): bool => $methods['isAuthoritativeOpenActivity']->invoke(null, $item)))[0] ?? null;
    $timer = array_values(array_filter($timers, static fn (array $item): bool => $methods['isAuthoritativeOpenTimer']->invoke(null, $item)))[0] ?? null;
    $expected = $summary['is_terminal'] ? [] : array_values(array_filter(
        Workflow\V2\Support\RunWaitView::forRun($run, $activities, $timers),
        static fn (array $wait): bool => $wait['status'] === 'open'));
    $limit = $summary['current_waits']['limit'];
    if (($summary['current_waits']['has_more'] !== (count($expected) > $limit))
        || count($summary['current_waits']['waits']) !== min(count($expected), $limit)) {
        throw new RuntimeException('All-group bounded wait count differs.');
    }
    $expected = array_slice($expected, 0, $limit);
    foreach ([$summary['reference_clock_start'], $summary['reference_clock_end']] as $clock) {
        Illuminate\Support\Carbon::setTestNow($clock);
        $task = $methods['nextOpenTask']->invoke(null, $run);
        $blocked = $methods['replayBlockedTask']->invoke(null, $run);
        [$state, $reason] = $methods['liveness']->invoke(null, $run, $summary['is_terminal'], $active,
            null, null, null, null, null, null, $timer, null, null, $task, $blocked, null, null, null, null, null);
        if ($state !== $summary['liveness_state'] || $reason !== $summary['liveness_reason']) {
            throw new RuntimeException('All-group liveness differs: '.json_encode([$state, $reason, $summary['liveness_state'], $summary['liveness_reason']], JSON_THROW_ON_ERROR));
        }
        $kind = $active !== null ? 'activity' : ($timer !== null ? 'timer' : ($task === null ? null : 'workflow-task'));
        $problem = ! $summary['is_terminal'] && $methods['taskProblemDetected']->invoke(null, $run, $task,
            $blocked, null, null, null, null, null, $kind, $state);
        if ($problem !== $case['stored_task_problem']) {
            throw new RuntimeException('All-group task problem differs.');
        }
        foreach ($expected as $index => $wait) {
            $row = new Workflow\V2\Models\WorkflowRunWait();
            $attributes = $wait;
            $attributes['wait_id'] = $wait['id'];
            $attributes['deadline_at'] = $wait['deadline_at']?->toJSON();
            $attributes['history_authority'] = 'typed_history';
            $row->setRawAttributes($attributes);
            $activity = $wait['kind'] === 'activity' ? ($metadata[$wait['resume_source_id']] ?? null) : null;
            $task = $run->tasks->firstWhere('id', $wait['task_id']);
            $canonical = $currentWait->invoke(null, $row, $task, $activity,
                $activity === null ? null : ($attempts[$activity->id] ?? null), Illuminate\Support\Carbon::now());
            $actual = $summary['current_waits']['waits'][$index];
            ksort($canonical);
            ksort($actual);
            if ($canonical !== $actual) {
                throw new RuntimeException('All-group current wait differs: '.json_encode([$canonical, $actual], JSON_THROW_ON_ERROR));
            }
        }
    }
    $waits += count($expected);
    ++$cases;
}
Illuminate\Support\Carbon::setTestNow();
if ($cases === 0 || $waits === 0 || $database->getConnection()->getQueryLog() !== []) {
    throw new RuntimeException('Expected all-group snapshots and zero reference queries.');
}
echo json_encode(['outcome' => 'pass', 'cases' => $cases, 'open_waits' => $waits,
    'database_queries' => 0, 'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow')], JSON_THROW_ON_ERROR)."\n";
