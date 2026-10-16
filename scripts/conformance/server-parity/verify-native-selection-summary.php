<?php

declare(strict_types=1);

require $argv[1];
$container = new Illuminate\Container\Container();
$container->instance('config', new Illuminate\Config\Repository());
Illuminate\Container\Container::setInstance($container);
$database = new Illuminate\Database\Capsule\Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->bootEloquent();
$database->getConnection()->enableQueryLog();
$projector = Workflow\V2\Support\RunSummaryProjector::class;
$openActivity = new ReflectionMethod($projector, 'isAuthoritativeOpenActivity');
$openTimer = new ReflectionMethod($projector, 'isAuthoritativeOpenTimer');
$nextTask = new ReflectionMethod($projector, 'nextOpenTask');
$blockedTask = new ReflectionMethod($projector, 'replayBlockedTask');
$liveness = new ReflectionMethod($projector, 'liveness');
$taskProblem = new ReflectionMethod($projector, 'taskProblemDetected');
$count = 0;
$waitCount = 0;
foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (! array_filter($case['raw_history'], static fn (array $event): bool => isset(json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR)['parallel_group_id']))) { continue; }
    $summary = $case['summary'];
    Illuminate\Support\Carbon::setTestNow($summary['observed_at']);
    $run = new Workflow\V2\Models\WorkflowRun();
    $run->setRawAttributes(['id' => $summary['run_id'], 'workflow_instance_id' => $summary['instance_id'],
        'status' => $summary['status'], 'closed_reason' => $summary['closed_reason'], 'compatibility' => null]);
    $events = [];
    foreach ($case['raw_history'] as $index => $raw) {
        $event = new Workflow\V2\Models\WorkflowHistoryEvent();
        $event->setRawAttributes(['id' => $raw['id'], 'workflow_run_id' => $summary['run_id'],
            'sequence' => $index + 1, 'event_type' => $raw['event_type'], 'payload' => $raw['payload_json'], 'recorded_at' => $raw['recorded_at']]);
        $events[] = $event;
    }
    $run->setRelation('historyEvents', new Illuminate\Database\Eloquent\Collection($events));
    foreach (['activityExecutions', 'timers', 'commands', 'childLinks'] as $relation) {
        $run->setRelation($relation, new Illuminate\Database\Eloquent\Collection());
    }
    $tasks = [];
    foreach ($case['tasks'] as $raw) {
        $task = new Workflow\V2\Models\WorkflowTask();
        $task->setRawAttributes($raw);
        $tasks[] = $task;
    }
    $run->setRelation('tasks', new Illuminate\Database\Eloquent\Collection($tasks));
    $activities = Workflow\V2\Support\RunActivityView::activitiesForRun($run, decodePayloads: false);
    $timers = Workflow\V2\Support\RunTimerView::timersForRun($run);
    $active = array_values(array_filter($activities, static fn (array $item): bool => $openActivity->invoke(null, $item)))[0] ?? null;
    $timer = array_values(array_filter($timers, static fn (array $item): bool => $openTimer->invoke(null, $item)))[0] ?? null;
    $task = $nextTask->invoke(null, $run);
    $blocked = $blockedTask->invoke(null, $run);
    [$state, $reason] = $liveness->invoke(null, $run, $summary['is_terminal'], $active, null, null, null, null, null, null,
        $timer, null, null, $task, $blocked, null, null, null, null, null);
    if ($state !== $summary['liveness_state'] || $reason !== $summary['liveness_reason']) {
        throw new RuntimeException($summary['run_id'].' selection liveness: '.$state.' / '.$reason.'; native '.$summary['liveness_state'].' / '.$summary['liveness_reason']);
    }
    $kind = $active !== null ? 'activity' : ($timer !== null ? 'timer' : ($task === null ? null : 'workflow-task'));
    $problem = ! $summary['is_terminal'] && $taskProblem->invoke(null, $run, $task, $blocked, null, null, null, null, null, $kind, $state);
    if ($problem !== $case['stored_task_problem']) { throw new RuntimeException('Selection task_problem differs.'); }
    $expected = $summary['is_terminal'] ? [] : array_values(array_filter(Workflow\V2\Support\RunWaitView::forRun($run, $activities, $timers), static fn (array $wait): bool => $wait['status'] === 'open'));
    $actual = $summary['current_waits']['waits'];
    if (count($expected) !== count($actual)) { throw new RuntimeException('Selection open wait count differs.'); }
    foreach ($expected as $index => $wait) {
        foreach (['id' => 'id', 'kind' => 'kind', 'summary' => 'reason', 'resume_source_id' => 'dependency_id',
            'target_type' => 'dependency_type', 'target_name' => 'target_name', 'history_unsupported_reason' => 'unavailable_reason'] as $source => $target) {
            if ($wait[$source] !== $actual[$index][$target]) { throw new RuntimeException('Selection wait '.$target.' differs at '.$index); }
        }
        ++$waitCount;
    }
    ++$count;
}
Illuminate\Support\Carbon::setTestNow();
if ($count === 0) { throw new RuntimeException('Expected actual native selection observations.'); }
echo json_encode(['outcome' => 'pass', 'cases' => $count, 'open_waits' => $waitCount,
    'database_queries' => count($database->getConnection()->getQueryLog()), 'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow')], JSON_THROW_ON_ERROR)."\n";
