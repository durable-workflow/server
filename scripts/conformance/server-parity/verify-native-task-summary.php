<?php

declare(strict_types=1);

// Compare synthetic native task snapshots with the installed PHP classifier.
// php verify-native-task-summary.php /app/vendor/autoload.php /evidence.jsonl
require $argv[1];

$container = new Illuminate\Container\Container();
$container->instance('config', new Illuminate\Config\Repository());
Illuminate\Container\Container::setInstance($container);
$database = new Illuminate\Database\Capsule\Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->bootEloquent();
$database->getConnection()->enableQueryLog();
$classifier = new ReflectionMethod(Workflow\V2\Support\RunSummaryProjector::class, 'taskLiveness');
$count = 0;

foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $summary = $case['summary'];

    if (! in_array($summary['liveness_state'], ['workflow_task_ready', 'workflow_task_leased'], true)) {
        continue;
    }

    if (count($case['tasks']) !== 1) {
        throw new RuntimeException('Expected one actual open workflow task.');
    }

    $task = new Workflow\V2\Models\WorkflowTask();
    $task->setRawAttributes($case['tasks'][0]);
    $run = new Workflow\V2\Models\WorkflowRun();
    $run->setRawAttributes(['id' => $summary['run_id'], 'status' => $summary['status'], 'compatibility' => null]);
    [$state, $reason] = $classifier->invoke(null, $task, $run, 'Workflow');

    if ($state !== $summary['liveness_state'] || $reason !== $summary['liveness_reason']) {
        throw new RuntimeException($summary['run_id'].' liveness differs from the installed PHP classifier.');
    }

    ++$count;
}

if ($count === 0 || $database->getConnection()->getQueryLog() !== []) {
    throw new RuntimeException('Expected nonempty task evidence and no reference database queries.');
}

echo json_encode([
    'outcome' => 'pass',
    'cases' => $count,
    'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
    'database_queries' => 0,
], JSON_THROW_ON_ERROR)."\n";
