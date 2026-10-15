<?php

declare(strict_types=1);

// Compare actual native histories with the installed external-wait classifiers.
// php verify-native-external-summary.php /app/vendor/autoload.php /evidence.jsonl
require $argv[1];

$container = new Illuminate\Container\Container();
$container->instance('config', new Illuminate\Config\Repository());
Illuminate\Container\Container::setInstance($container);
$database = new Illuminate\Database\Capsule\Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->bootEloquent();
$database->getConnection()->enableQueryLog();
$projector = Workflow\V2\Support\RunSummaryProjector::class;
$condition = new ReflectionMethod($projector, 'openConditionWait');
$signal = new ReflectionMethod($projector, 'openSignalWait');
$liveness = new ReflectionMethod($projector, 'liveness');
$count = 0;

foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $summary = $case['summary'];
    if ($summary['is_terminal'] || ! array_filter($case['raw_history'],
        static fn (array $event): bool => in_array($event['event_type'], ['ConditionWaitOpened', 'SignalWaitOpened'], true),
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
            'workflow_run_id' => $summary['run_id'],
            'sequence' => $index + 1,
            'event_type' => $raw['event_type'],
            'payload' => $raw['payload_json'],
            'recorded_at' => $raw['recorded_at'] ?? throw new RuntimeException('Missing original history timestamp.'),
        ]);
        $events[] = $event;
    }

    $run->setRelation('historyEvents', new Illuminate\Database\Eloquent\Collection($events));
    $nextTask = null;

    if (count($case['tasks']) > 1) {
        throw new RuntimeException('This external-wait component expects at most one actual open task.');
    }

    if ($case['tasks'] !== []) {
        $nextTask = new Workflow\V2\Models\WorkflowTask();
        $nextTask->setRawAttributes($case['tasks'][0]);
    }

    [$state, $reason] = $liveness->invoke(null,
        $run, false, null, null, null, null, null, null,
        $condition->invoke(null, $run), null, null, null,
        $nextTask, null, null, null, null, null, $signal->invoke(null, $run),
    );

    if ($state !== $summary['liveness_state'] || $reason !== $summary['liveness_reason']) {
        throw new RuntimeException($summary['run_id'].' differs from installed external-wait liveness: '.$state.' / '.$reason);
    }

    ++$count;
}

Illuminate\Support\Carbon::setTestNow();

if ($count === 0 || $database->getConnection()->getQueryLog() !== []) {
    throw new RuntimeException('Expected nonempty external-wait evidence and no reference database queries.');
}

echo json_encode([
    'outcome' => 'pass',
    'cases' => $count,
    'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
    'database_queries' => 0,
], JSON_THROW_ON_ERROR)."\n";
