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
$database->getConnection()->enableQueryLog();
$count = 0;
$settings = [
    'CONTINUE_AS_NEW_EVENT_THRESHOLD' => ['continue_as_new_event_threshold', 10000],
    'CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD' => ['continue_as_new_size_bytes_threshold', 5242880],
    'CONTINUE_AS_NEW_FAN_OUT_THRESHOLD' => ['continue_as_new_fan_out_threshold', 200],
    'HISTORY_EVENT_WARNING_THRESHOLD' => ['event_warning_threshold', 8000],
    'HISTORY_SIZE_BYTES_WARNING_THRESHOLD' => ['size_bytes_warning_threshold', 4194304],
    'HISTORY_FAN_OUT_WARNING_THRESHOLD' => ['fan_out_warning_threshold', 160],
];

foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $environment = $case['summary']['budget_env'] ?? [];
    foreach ($settings as $suffix => [$key, $default]) {
        foreach (['DW_V2_', 'WORKFLOW_V2_'] as $prefix) {
            $name = $prefix.$suffix;
            putenv(array_key_exists($name, $environment) ? $name.'='.$environment[$name] : $name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $container->make('config')->set('workflows.v2.history_budget.'.$key, (int) Workflow\Support\Env::dw('DW_V2_'.$suffix, 'WORKFLOW_V2_'.$suffix, $default));
    }
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
    foreach (['continue_as_new_recommended' => 'continue_as_new_recommended', 'history_budget_pressure' => 'pressure', 'history_budget_pressure_dimensions' => 'pressure_dimensions'] as $field => $key) {
        if (array_key_exists($field, $case['summary']) && $budget[$key] !== $case['summary'][$field]) {
            throw new RuntimeException($case['summary']['run_id'].' '.$field.': PHP='.json_encode($budget[$key]).', native='.json_encode($case['summary'][$field]));
        }
    }

    ++$count;
}

if ($count === 0 || $database->getConnection()->getQueryLog() !== []) {
    throw new RuntimeException('Expected nonempty native evidence and no reference database queries.');
}

echo json_encode([
    'outcome' => 'pass',
    'cases' => $count,
    'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
    'database_queries' => 0,
], JSON_THROW_ON_ERROR)."\n";
