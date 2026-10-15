<?php

declare(strict_types=1);

// Compare actual native parent histories with the installed PHP child classifiers.
// Mutable child tables are deliberately empty: typed parent history must suffice.
// php verify-native-child-summary.php /app/vendor/autoload.php /evidence.jsonl
require $argv[1];

$container = new Illuminate\Container\Container();
$container->instance('config', new Illuminate\Config\Repository());
Illuminate\Container\Container::setInstance($container);
$database = new Illuminate\Database\Capsule\Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->bootEloquent();
$connection = $database->getConnection();
$connection->statement('CREATE TABLE workflow_runs (id TEXT PRIMARY KEY)');
$connection->statement('CREATE TABLE workflow_instances (id TEXT PRIMARY KEY)');
$connection->enableQueryLog();
$projector = Workflow\V2\Support\RunSummaryProjector::class;
$openChild = new ReflectionMethod($projector, 'openChildWait');
$pendingChild = new ReflectionMethod($projector, 'pendingChildResolutionWait');
$liveness = new ReflectionMethod($projector, 'liveness');
$childWaits = new ReflectionMethod(Workflow\V2\Support\RunWaitView::class, 'childWaits');
$count = 0;
$waitCount = 0;

foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $summary = $case['summary'];
    if ($summary['is_terminal'] || ! array_filter($case['raw_history'],
        static fn (array $event): bool => $event['event_type'] === 'ChildWorkflowScheduled',
    )) {
        continue;
    }
    Illuminate\Support\Carbon::setTestNow($summary['observed_at']);
    $run = new Workflow\V2\Models\WorkflowRun();
    $run->setRawAttributes(['id' => $summary['run_id'], 'status' => $summary['status'], 'compatibility' => null]);
    $events = [];
    foreach ($case['raw_history'] as $index => $raw) {
        $event = new Workflow\V2\Models\WorkflowHistoryEvent();
        $event->setRawAttributes([
            'workflow_run_id' => $summary['run_id'], 'sequence' => $index + 1,
            'event_type' => $raw['event_type'], 'payload' => $raw['payload_json'],
            'recorded_at' => $raw['recorded_at'] ?? throw new RuntimeException('Missing original history timestamp.'),
        ]);
        $events[] = $event;
    }
    $run->setRelation('historyEvents', new Illuminate\Database\Eloquent\Collection($events));
    $run->setRelation('childLinks', new Illuminate\Database\Eloquent\Collection());
    if (count($case['tasks']) > 1) {
        throw new RuntimeException('Expected at most one original open parent task.');
    }
    $nextTask = null;
    if ($case['tasks'] !== []) {
        $nextTask = new Workflow\V2\Models\WorkflowTask();
        $nextTask->setRawAttributes($case['tasks'][0]);
    }
    $open = $nextTask === null ? $openChild->invoke(null, $run) : null;
    $pending = $pendingChild->invoke(null, $run, $open !== null);
    [$state, $reason] = $liveness->invoke(null,
        $run, false, null, null, null, null, null, null, null,
        null, null, null, $nextTask, null, $open, null, $pending, null, null,
    );
    if ($state !== $summary['liveness_state'] || $reason !== $summary['liveness_reason']) {
        throw new RuntimeException($summary['run_id'].' differs from installed child liveness: '.$state.' / '.$reason);
    }
    $expected = array_values(array_filter($childWaits->invoke(null, $run, [], []),
        static fn (array $wait): bool => $wait['status'] === 'open',
    ));
    $actual = $summary['current_waits']['waits'];
    if (count($actual) !== count($expected)) {
        throw new RuntimeException('Open child wait count differs from installed PHP.');
    }
    foreach ($expected as $index => $wait) {
        foreach (['id' => 'id', 'kind' => 'kind', 'summary' => 'reason',
            'resume_source_id' => 'dependency_id', 'target_type' => 'dependency_type',
            'target_name' => 'target_name', 'deadline_at' => 'deadline_at',
            'history_unsupported_reason' => 'unavailable_reason'] as $source => $target) {
            if ($wait[$source] !== $actual[$index][$target]) {
                throw new RuntimeException('Child wait differs at '.$target);
            }
        }
        ++$waitCount;
    }
    ++$count;
}

Illuminate\Support\Carbon::setTestNow();
if ($count === 0 || $waitCount === 0) {
    throw new RuntimeException('Expected nonempty child liveness and open-wait evidence.');
}
echo json_encode([
    'outcome' => 'pass', 'cases' => $count, 'open_waits' => $waitCount,
    'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
    'database_queries' => count($connection->getQueryLog()),
    'mutable_child_rows' => 0,
], JSON_THROW_ON_ERROR)."\n";
