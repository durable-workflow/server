<?php

declare(strict_types=1);

// Use the installed package's Env and HistoryBudget, with no runtime database.
require $argv[1];

$container = new Illuminate\Container\Container();
$config = new Illuminate\Config\Repository();
$container->instance('config', $config);
Illuminate\Container\Container::setInstance($container);
$settings = [
    'CONTINUE_AS_NEW_EVENT_THRESHOLD' => ['continue_as_new_event_threshold', 10000],
    'CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD' => ['continue_as_new_size_bytes_threshold', 5242880],
    'CONTINUE_AS_NEW_FAN_OUT_THRESHOLD' => ['continue_as_new_fan_out_threshold', 200],
    'HISTORY_EVENT_WARNING_THRESHOLD' => ['event_warning_threshold', 8000],
    'HISTORY_SIZE_BYTES_WARNING_THRESHOLD' => ['size_bytes_warning_threshold', 4194304],
    'HISTORY_FAN_OUT_WARNING_THRESHOLD' => ['fan_out_warning_threshold', 160],
];
$cases = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
    foreach ($settings as $suffix => [$key, $default]) {
        foreach (['DW_V2_', 'WORKFLOW_V2_'] as $prefix) {
            $name = $prefix.$suffix;
            putenv(array_key_exists($name, $case['env']) ? $name.'='.$case['env'][$name] : $name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $config->set('workflows.v2.history_budget.'.$key, (int) Workflow\Support\Env::dw('DW_V2_'.$suffix, 'WORKFLOW_V2_'.$suffix, $default));
    }
    $budget = Workflow\V2\Support\HistoryBudget::fromCounters(...$case['counters']);
    if ($budget['pressure'] !== $case['pressure'] || $budget['pressure_dimensions'] !== $case['dimensions']
        || $budget['continue_as_new_recommended'] !== ($case['pressure'] === 'continue_as_new_recommended')) {
        throw new RuntimeException($case['name'].' '.json_encode($budget, JSON_THROW_ON_ERROR));
    }
}
echo json_encode(['outcome' => 'pass', 'cases' => count($cases), 'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow')], JSON_THROW_ON_ERROR)."\n";
