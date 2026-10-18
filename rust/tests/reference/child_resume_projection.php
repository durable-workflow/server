<?php
declare(strict_types=1);

// Use the exact published Server image with an isolated SQLite :memory: database.
// Arguments are its Composer autoloader and native JSONL captures produced with
// DW_TEST_CHILD_RESUME_REFERENCE. This calls the real projector, not an API worker.
require $argv[1];
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Support\RunWaitProjector;

Artisan::call('migrate', ['--force' => true]);
$count = 0;
$queries = 0;
foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $capture = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $metadata = $capture['summary'];
    $event = null;
    $scheduled = null;
    foreach ($capture['raw_history'] as $index => $stored) {
        $payload = json_decode($stored['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if ($stored['event_type'] === 'ChildWorkflowScheduled') {
            $scheduled = $payload;
        }
        if (in_array($stored['event_type'], ['ChildRunCompleted', 'ChildRunFailed', 'ChildRunCancelled'], true)) {
            $event = new WorkflowHistoryEvent([
                'id' => $stored['id'], 'workflow_run_id' => $metadata['run_id'],
                'event_type' => $stored['event_type'], 'payload' => $payload,
                'sequence' => $capture['history']['events'][$index]['sequence'],
                'recorded_at' => $stored['recorded_at'],
            ]);
        }
    }
    if ($event === null || $scheduled === null) {
        throw new RuntimeException('Canonical scheduled/resolved child history is required.');
    }
    DB::table('workflow_run_waits')->delete();
    $waitId = 'child:'.$scheduled['child_call_id'];
    if ($metadata['wait'] !== null) {
        WorkflowRunWait::query()->create([
            'id' => hash('sha256', $metadata['run_id'].':'.$waitId),
            'workflow_run_id' => $metadata['run_id'], 'workflow_instance_id' => $metadata['workflow_id'],
            'wait_id' => $waitId, 'position' => 0, 'kind' => 'child',
            'sequence' => $scheduled['sequence'], 'status' => 'open', 'source_status' => 'waiting',
            'task_backed' => false, 'history_authority' => 'typed_history',
        ]);
    }
    $run = new WorkflowRun(['id' => $metadata['run_id']]);
    $task = new WorkflowTask(['id' => $metadata['task_id'], 'workflow_run_id' => $metadata['run_id'],
        'task_type' => 'workflow', 'status' => $metadata['task_status']]);
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();
    RunWaitProjector::projectChildResolutionEvent($run, $task, $event);
    $queries += count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();
    $wait = WorkflowRunWait::query()->where('workflow_run_id', $run->id)->where('wait_id', $waitId)->first();
    if ($metadata['wait'] === null) {
        if ($wait !== null) {
            throw new RuntimeException('Published projector recreated a missing wait.');
        }
    } else {
        if ($wait === null) {
            throw new RuntimeException('Published projector lost the original wait.');
        }
        foreach ($metadata['wait'] as $field => $expected) {
            if ($wait->{$field} !== $expected) {
                throw new RuntimeException('Published wait mismatch: '.json_encode([$field, $expected, $wait->{$field}]));
            }
        }
    }
    ++$count;
}
if ($count === 0) {
    throw new RuntimeException('Reference input is empty.');
}
$hashes = [];
foreach ([RunWaitProjector::class, WorkflowRunWait::class] as $class) {
    $hashes[$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
}
echo json_encode(['captures'=>$count, 'projector_queries'=>$queries, 'php'=>PHP_VERSION, 'hashes'=>$hashes], JSON_THROW_ON_ERROR), "\n";
