<?php

declare(strict_types=1);

// Compare actual native histories/tasks with installed update wait/liveness code.
// Command shells come from typed admission events; no mutable update rows exist.
// php verify-native-update-summary.php /app/vendor/autoload.php /evidence.jsonl
require $argv[1];

$container = new Illuminate\Container\Container();
$container->instance('config', new Illuminate\Config\Repository());
Illuminate\Container\Container::setInstance($container);
$database = new Illuminate\Database\Capsule\Manager($container);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->bootEloquent();
$database->getConnection()->statement('CREATE TABLE workflow_commands (id TEXT PRIMARY KEY, workflow_run_id TEXT, command_type TEXT, status TEXT, applied_at TEXT, command_sequence INTEGER, created_at TEXT)');
$database->getConnection()->enableQueryLog();
$projector = Workflow\V2\Support\RunSummaryProjector::class;
$openUpdate = new ReflectionMethod($projector, 'openUpdateWait');
$openSignal = new ReflectionMethod($projector, 'openSignalWait');
$openCondition = new ReflectionMethod($projector, 'openConditionWait');
$liveness = new ReflectionMethod($projector, 'liveness');
$taskProblem = new ReflectionMethod($projector, 'taskProblemDetected');
$count = 0;
$waitCount = 0;

foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $summary = $case['summary'];
    if ($summary['is_terminal'] || $summary['summary_state'] !== 'observed'
        || ! array_filter($case['raw_history'], static fn (array $event): bool => $event['event_type'] === 'UpdateAccepted')) {
        continue;
    }
    Illuminate\Support\Carbon::setTestNow($summary['observed_at']);
    $run = new Workflow\V2\Models\WorkflowRun();
    $run->setRawAttributes(['id' => $summary['run_id'], 'status' => $summary['status'], 'compatibility' => null]);
    $events = [];
    $commands = [];
    foreach ($case['raw_history'] as $index => $raw) {
        $event = new Workflow\V2\Models\WorkflowHistoryEvent();
        $event->setRawAttributes([
            'workflow_run_id' => $summary['run_id'], 'sequence' => $index + 1,
            'event_type' => $raw['event_type'], 'payload' => $raw['payload_json'],
            'recorded_at' => $raw['recorded_at'],
        ]);
        $events[] = $event;
        if ($raw['event_type'] === 'UpdateAccepted') {
            $payload = json_decode($raw['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $command = new Workflow\V2\Models\WorkflowCommand();
            $command->setRawAttributes([
                'id' => $payload['workflow_command_id'], 'command_type' => 'update',
                'workflow_instance_id' => $payload['workflow_instance_id'], 'workflow_run_id' => $summary['run_id'],
                'command_sequence' => $payload['command']['sequence'], 'status' => 'accepted',
                'payload_codec' => 'avro', 'context' => json_encode(['update_name' => $payload['update_name']], JSON_THROW_ON_ERROR),
                'accepted_at' => $raw['recorded_at'],
            ]);
            $commands[] = $command;
        }
    }
    $run->setRelation('historyEvents', new Illuminate\Database\Eloquent\Collection($events));
    $run->setRelation('commands', new Illuminate\Database\Eloquent\Collection($commands));
    $run->setRelation('updates', new Illuminate\Database\Eloquent\Collection());
    $run->setRelation('failures', new Illuminate\Database\Eloquent\Collection());
    $tasks = array_map(static function (array $raw): Workflow\V2\Models\WorkflowTask {
        $task = new Workflow\V2\Models\WorkflowTask();
        $task->setRawAttributes($raw);
        return $task;
    }, $case['tasks']);
    if (count($tasks) > 1) {
        throw new RuntimeException('Expected at most one original update task.');
    }
    $run->setRelation('tasks', new Illuminate\Database\Eloquent\Collection($tasks));
    $open = $openUpdate->invoke(null, $run);
    $task = $tasks[0] ?? null;
    $condition = $open === null ? $openCondition->invoke(null, $run) : null;
    $signal = $open === null && $condition === null ? $openSignal->invoke(null, $run) : null;
    [$state, $reason] = $liveness->invoke(null,
        $run, false, null, null, null, $open, $task, null, $condition,
        null, null, null, $task, null, null, null, null, null, $signal,
    );
    if ($state !== $summary['liveness_state'] || $reason !== $summary['liveness_reason']) {
        throw new RuntimeException($summary['run_id'].' differs from installed update liveness: '.$state.' / '.$reason);
    }
    $problem = $taskProblem->invoke(null, $run, $task, null, $open, $task, null, $condition, null,
        $open === null ? ($condition === null ? 'signal' : 'condition') : 'update', $state,
    );
    if ($problem !== $case['stored_task_problem']) {
        throw new RuntimeException('Stored update task_problem differs from installed PHP.');
    }
    $expected = array_values(array_filter(Workflow\V2\Support\UpdateWaits::forRun($run),
        static fn (array $wait): bool => $wait['status'] === 'open',
    ));
    $actual = array_values(array_filter($summary['current_waits']['waits'],
        static fn (array $wait): bool => $wait['kind'] === 'update',
    ));
    if (count($expected) !== count($actual)) {
        throw new RuntimeException('Open update wait count differs from installed PHP.');
    }
    foreach ($expected as $index => $wait) {
        foreach (['id' => 'id', 'kind' => 'kind', 'summary' => 'reason',
            'resume_source_id' => 'dependency_id', 'target_type' => 'dependency_type',
            'target_name' => 'target_name', 'task_id' => 'task_id', 'task_status' => 'task_status',
            'deadline_at' => 'deadline_at'] as $source => $target) {
            if ($wait[$source] !== $actual[$index][$target]) {
                throw new RuntimeException('Update wait differs at '.$target);
            }
        }
        ++$waitCount;
    }
    ++$count;
}

Illuminate\Support\Carbon::setTestNow();
if ($count === 0 || $waitCount === 0) {
    throw new RuntimeException('Expected nonempty update liveness and wait evidence.');
}
echo json_encode([
    'outcome' => 'pass', 'cases' => $count, 'open_waits' => $waitCount,
    'php' => PHP_VERSION,
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
    'database_queries' => count($database->getConnection()->getQueryLog()), 'mutable_update_rows' => 0,
    'blocking_signal_rows' => 0,
], JSON_THROW_ON_ERROR)."\n";
