<?php

declare(strict_types=1);

// Run the exact published closer in its own migrated, in-memory PHP database.
// Native source snapshots provide original identities and pre-closure inputs.
require $argv[1];
$app = require dirname($argv[1], 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\PendingUpdateCloser;

function terminalUpdateValue(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    foreach (['accepted_at', 'applied_at', 'rejected_at', 'available_at', 'leased_at', 'lease_expires_at', 'repair_available_at'] as $field) {
        if (is_string($value[$field] ?? null)) {
            $value[$field] = Carbon::parse($value[$field])->toJSON();
        }
    }
    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map(terminalUpdateValue(...), $value);
}

$cases = 0;
$queries = 0;
foreach (file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $reference = $case['summary']['terminal_update_reference'];
    $original = $reference['original'];
    $native = null;
    $nativeCursor = null;
    foreach ($case['raw_history'] as $event) {
        if ($event['event_type'] === 'UpdateCompleted') {
            $native = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        } elseif ($event['event_type'] === 'MessageCursorAdvanced') {
            $nativeCursor = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        }
    }
    if (! is_array($native) || ! is_array($nativeCursor)) {
        throw new RuntimeException('Expected actual source terminal-update and cursor evidence.');
    }
    DB::purge();
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Published PHP migrations failed.');
    }
    $instance = WorkflowInstance::query()->create([
        'id' => $original['workflow_id'], 'workflow_type' => 'echo', 'workflow_class' => 'echo',
        'current_run_id' => $original['run_id'], 'run_count' => 1, 'namespace' => 'default',
    ]);
    $reason = $native['terminal_reason'];
    $run = WorkflowRun::query()->create([
        'id' => $original['run_id'], 'workflow_instance_id' => $instance->id,
        'workflow_type' => 'echo', 'workflow_class' => 'echo', 'run_number' => 1,
        'status' => $reason, 'closed_reason' => $reason, 'namespace' => 'default',
        'payload_codec' => 'avro', 'arguments' => $reference['arguments'], 'queue' => 'test',
    ]);
    $command = WorkflowCommand::query()->create([
        'id' => $original['command_id'], 'workflow_instance_id' => $instance->id,
        'workflow_run_id' => $run->id, 'resolved_workflow_run_id' => $run->id,
        'command_type' => 'update', 'source' => 'control_plane', 'target_scope' => 'instance',
        'status' => 'accepted', 'workflow_type' => 'echo', 'payload_codec' => 'avro',
        'payload' => $reference['command_payload'], 'context' => $reference['command_context'],
        'command_sequence' => $original['command_sequence'], 'message_sequence' => $reference['message_sequence'],
        'request_id' => 'original', 'accepted_at' => $original['accepted_at'],
    ]);
    $update = WorkflowUpdate::query()->create([
        'id' => $original['update_id'], 'workflow_command_id' => $command->id,
        'workflow_instance_id' => $instance->id, 'workflow_run_id' => $run->id,
        'resolved_workflow_run_id' => $run->id, 'update_name' => 'set', 'status' => 'accepted',
        'command_sequence' => $original['command_sequence'], 'payload_codec' => 'avro',
        'arguments' => $reference['arguments'], 'accepted_at' => $original['accepted_at'],
    ]);
    $task = null;
    if (is_array($native['task'] ?? null)) {
        $attributes = $native['task'];
        $attributes['task_type'] = $attributes['type'];
        unset($attributes['type']);
        $attributes['workflow_run_id'] = $run->id;
        $attributes['namespace'] = 'default';
        $attributes['payload'] = [];
        // Keep the queue column distinct from Eloquent's storage connection.
        $attributes['connection'] = null;
        $task = WorkflowTask::query()->create($attributes);
        // History's sibling Model reads this protected property. The PHP
        // reference still uses its own isolated SQLite database for all writes.
        $task->setConnection($reference['storage_connection']);
    }
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();
    PendingUpdateCloser::closeForTerminalRun($run, $task);
    $queries += count(DB::connection()->getQueryLog());
    $completed = WorkflowHistoryEvent::query()->where('event_type', 'UpdateCompleted')->sole();
    $failure = WorkflowFailure::query()->sole();
    if ($completed->payload['failure_id'] !== $failure->id
        || $failure->source_kind !== 'workflow_command' || $failure->source_id !== $command->id
        || $failure->propagation_kind !== 'update' || $failure->failure_category->value !== 'application'
        || ! $failure->non_retryable || $failure->handled) {
        throw new RuntimeException('Published failure relationship differs.');
    }
    $expected = $completed->payload;
    // Only this newly generated failure ID differs between isolated databases.
    $expected['failure_id'] = $native['failure_id'];
    if (terminalUpdateValue($expected) !== terminalUpdateValue($native)) {
        throw new RuntimeException('Terminal update payload differs: '.json_encode(['php' => $expected, 'native' => $native], JSON_THROW_ON_ERROR));
    }
    $advanced = WorkflowHistoryEvent::query()->where('event_type', 'MessageCursorAdvanced')->sole();
    if (terminalUpdateValue($advanced->payload) !== terminalUpdateValue($nativeCursor)) {
        throw new RuntimeException('Published message cursor differs.');
    }
    $update->refresh();
    $command->refresh();
    if ($update->status->value !== 'failed' || $update->outcome->value !== 'update_failed'
        || $update->failure_id !== $failure->id || $update->failure_message !== $native['message']
        || $update->workflow_sequence !== null || $update->applied_at !== null || $update->closed_at === null
        || $command->status->value !== 'accepted' || $command->outcome->value !== 'update_failed'
        || $command->applied_at === null || WorkflowHistoryEvent::query()->where('event_type', 'UpdateApplied')->exists()) {
        throw new RuntimeException('Published terminal state differs.');
    }
    ++$cases;
}
if ($cases === 0) {
    throw new RuntimeException('Expected nonempty terminal-update source evidence.');
}
echo json_encode(['outcome' => 'pass', 'cases' => $cases, 'reference_queries' => $queries,
    'php' => PHP_VERSION, 'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow')], JSON_THROW_ON_ERROR)."\n";
