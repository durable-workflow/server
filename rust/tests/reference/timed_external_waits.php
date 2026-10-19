<?php

declare(strict_types=1);

// Compare in-process Rust test snapshots with the published PHP wait projector.
// php timed_external_waits.php /app/vendor/autoload.php /evidence.jsonl
require $argv[1];

$container = new Illuminate\Container\Container();
$container->instance('config', new Illuminate\Config\Repository());
Illuminate\Container\Container::setInstance($container);
$database = new Illuminate\Database\Capsule\Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->bootEloquent();
$database->getConnection()->enableQueryLog();
$cases = 0;
$waitCount = 0;
$livenessCount = 0;
$currentWait = new ReflectionMethod(Workflow\V2\Support\RunCurrentWaits::class, 'wait');
$projector = Workflow\V2\Support\RunSummaryProjector::class;
$condition = new ReflectionMethod($projector, 'openConditionWait');
$signal = new ReflectionMethod($projector, 'openSignalWait');
$nextTask = new ReflectionMethod($projector, 'nextOpenTask');
$liveness = new ReflectionMethod($projector, 'liveness');

foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $summary = $case['summary'];
    $run = new Workflow\V2\Models\WorkflowRun();
    $run->setRawAttributes(['id' => $summary['run_id'], 'status' => $summary['status'], 'closed_reason' => $summary['closed_reason']]);
    foreach (['commands', 'activityExecutions', 'timers', 'childLinks', 'updates', 'failures'] as $relation) {
        $run->setRelation($relation, new Illuminate\Database\Eloquent\Collection());
    }
    $events = [];
    foreach ($case['raw_history'] as $index => $raw) {
        $event = new Workflow\V2\Models\WorkflowHistoryEvent();
        $event->setRawAttributes(['id' => $raw['id'], 'workflow_run_id' => $summary['run_id'],
            'sequence' => $index + 1, 'event_type' => $raw['event_type'], 'payload' => $raw['payload_json'],
            'recorded_at' => $raw['recorded_at']]);
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
    $expected = array_values(array_filter(Workflow\V2\Support\RunWaitView::forRun($run, [], []),
        static fn (array $wait): bool => $wait['status'] === 'open'));
    $actual = $summary['current_waits']['waits'];
    if (count($expected) !== count($actual)) {
        throw new RuntimeException('Timed external open wait count differs.');
    }
    // Native observation wire dates have seconds precision. New condition
    // snapshots record real clock bounds around the read; require the same
    // PHP result at both bounds rather than inventing a fractional instant.
    $clocks = isset($summary['reference_clock_start'], $summary['reference_clock_end'])
        ? [$summary['reference_clock_start'], $summary['reference_clock_end']]
        : [$summary['observed_at']];
    if (Illuminate\Support\Carbon::parse($clocks[0])->gt(Illuminate\Support\Carbon::parse(end($clocks)))) {
        throw new RuntimeException('Observation clock bounds are reversed.');
    }
    foreach ($clocks as $clock) {
        Illuminate\Support\Carbon::setTestNow($clock);
        if (! $summary['is_terminal']) {
            [$state, $reason] = $liveness->invoke(null, $run, false,
                null, null, null, null, null, null, $condition->invoke(null, $run), null, null, null,
                $nextTask->invoke(null, $run), null, null, null, null, null, $signal->invoke(null, $run));
            if ($state !== $summary['liveness_state'] || $reason !== $summary['liveness_reason']) {
                throw new RuntimeException('Timed external liveness differs: '.json_encode([$state,$reason,$summary['liveness_state'],$summary['liveness_reason']],JSON_THROW_ON_ERROR));
            }
        }
        foreach ($expected as $index => $wait) {
            $row = new Workflow\V2\Models\WorkflowRunWait();
            $attributes = $wait;
            $attributes['wait_id'] = $wait['id'];
            $attributes['deadline_at'] = $wait['deadline_at']?->toJSON();
            $attributes['history_authority'] = 'typed_history';
            $row->setRawAttributes($attributes);
            $task = $run->tasks->firstWhere('id', $wait['task_id']);
            $canonical = $currentWait->invoke(null, $row, $task, null, null, Illuminate\Support\Carbon::now());
            ksort($canonical);
            $observed = $actual[$index];
            ksort($observed);
            if ($canonical !== $observed) {
                throw new RuntimeException('Timed external current wait differs: '.json_encode([$canonical,$observed],JSON_THROW_ON_ERROR));
            }
        }
    }
    $waitCount += count($expected);
    $livenessCount += $summary['is_terminal'] ? 0 : 1;
    ++$cases;
}

Illuminate\Support\Carbon::setTestNow();
if ($cases === 0 || $waitCount === 0 || $database->getConnection()->getQueryLog() !== []) {
    throw new RuntimeException('Expected timed external snapshots and zero reference queries.');
}
echo json_encode(['outcome' => 'pass', 'cases' => $cases, 'open_waits' => $waitCount,
    'liveness_cases' => $livenessCount,
    'database_queries' => 0, 'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow')], JSON_THROW_ON_ERROR)."\n";
