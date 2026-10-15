<?php

declare(strict_types=1);

// Run inside the exact published PHP image against synthetic native test output:
// php verify-native-history-budget.php /app/vendor/autoload.php /evidence.jsonl
require $argv[1];

$container = new Illuminate\Container\Container();
$container->instance('config', new Illuminate\Config\Repository());
Illuminate\Container\Container::setInstance($container);
$database = new Illuminate\Database\Capsule\Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->bootEloquent();
$database->connection()->enableQueryLog();
$count = 0;

foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $events = [];

    foreach ($case['raw_history'] as $row) {
        $event = new Workflow\V2\Models\WorkflowHistoryEvent();
        $event->setRawAttributes([
            'event_type' => $row['event_type'],
            'payload' => $row['payload_json'],
        ]);
        $events[] = $event;
    }

    $run = new Workflow\V2\Models\WorkflowRun();
    $run->setRelation('historyEvents', new Illuminate\Database\Eloquent\Collection($events));
    $budget = Workflow\V2\Support\HistoryBudget::forRun($run);

    foreach (['history_event_count', 'history_size_bytes', 'history_fan_out'] as $field) {
        if ($budget[$field] !== $case['summary'][$field]) {
            throw new RuntimeException($case['summary']['run_id'].' '.$field.': PHP='.$budget[$field].', native='.$case['summary'][$field]);
        }
    }

    ++$count;
}

if ($count === 0 || $database->connection()->getQueryLog() !== []) {
    throw new RuntimeException('Expected nonempty native evidence and no reference database queries.');
}

echo json_encode([
    'outcome' => 'pass',
    'cases' => $count,
    'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
    'database_queries' => 0,
], JSON_THROW_ON_ERROR)."\n";
