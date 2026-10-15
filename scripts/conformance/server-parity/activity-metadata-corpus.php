<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityAttemptSnapshots;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HeartbeatProgress;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\RunActivityView;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
ob_start();
$app->make(Kernel::class)->bootstrap();
ob_end_clean();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, 'activity_metadata_corpus_failed: '.$error::class.': '.$error->getMessage()."\n");
    exit(1);
});

// These released projector bytes are identical in Workflow 2.5.20 and 2.5.22.
// Check the bytes instead of presenting a cached older package as a new install.
$referenceHashes = [
    RunActivityView::class => 'e47b5420f353a82a3e23946d108663ec79e478e217482495ce5b95c14fc67afe',
    ActivitySnapshot::class => '4ca26cd0e08d1cf6e131c7a21234f1fb1889bd615bca196ac8e5fdde87ef67da',
    ActivityAttemptSnapshots::class => '63b135d20207668963747b50a5a195b2fb0187b285d29b36d7f68136c05055db',
    HeartbeatProgress::class => '57d326fbee217142f1f4ecaf02a657047f4f4e9348d179d7b2b4d04ee4683010',
    ParallelChildGroup::class => '5a6dec40fe73d388543bcf2e733bf21424a466713e01eda9a62fae0936ad55e5',
];
foreach ($referenceHashes as $class => $hash) {
    if (hash_file('sha256', (new ReflectionClass($class))->getFileName()) !== $hash) {
        throw new RuntimeException('Released activity projector source changed: '.$class);
    }
}
$queries = 0;
DB::connection()->beforeExecuting(static function () use (&$queries): never {
    $queries++;
    throw new RuntimeException('The in-memory projection must not query a database.');
});
$policy = new class implements ExternalPayloadStoragePolicy
{
    public int $calls = 0;

    public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
    {
        $this->calls++;
        throw new RuntimeException('Metadata must not resolve external payload storage.');
    }

    public function thresholdBytesFor(?string $namespace): ?int
    {
        $this->calls++;
        throw new RuntimeException('Metadata must not consult an external payload threshold.');
    }
};
$app->instance(ExternalPayloadStoragePolicy::class, $policy);

function metadataEvent(string $type, int $sequence, array $payload = []): array
{
    return ['sequence' => $sequence, 'event_type' => $type,
        'recorded_at' => sprintf('2026-10-15T10:00:%02d.123456Z', $sequence),
        'workflow_task_id' => 'task-1',
        'payload' => array_replace(['activity_execution_id' => 'activity-1',
            'sequence' => 1, 'activity_type' => 'fixture.echo', 'activity_class' => 'FixtureActivity'], $payload)];
}

function metadataExecution(array $overrides = [], array $attempts = []): array
{
    return array_replace(['id' => 'activity-1', 'sequence' => 1,
        'activity_type' => 'fixture.echo', 'activity_class' => 'FixtureActivity',
        'status' => 'running', 'attempt_count' => 1, 'current_attempt_id' => 'attempt-1',
        'payload_codec' => 'avro', 'retry_policy' => ['max_attempts' => 2, 'backoff' => []],
        'queue' => 'fixture-queue', 'connection' => 'fixture-connection',
        'created_at' => '2026-10-15T10:00:01.123456Z',
        'started_at' => '2026-10-15T10:00:02.123456Z', 'attempts' => $attempts], $overrides);
}

function metadataAttempt(array $overrides = []): array
{
    return array_replace(['id' => 'attempt-1', 'worker_attempt_id' => 'worker-attempt-1',
        'activity_execution_id' => 'activity-1', 'workflow_task_id' => 'task-1',
        'attempt_number' => 1, 'status' => 'running', 'lease_owner' => 'worker-1',
        'started_at' => '2026-10-15T10:00:02.123456Z',
        'lease_expires_at' => '2026-10-15T10:00:32.123456Z'], $overrides);
}

$scheduled = metadataEvent('ActivityScheduled', 1, ['activity' => [
    'id' => 'activity-1', 'idempotency_key' => 'original-key', 'sequence' => 1,
    'type' => 'fixture.echo', 'class' => 'FixtureActivity', 'status' => 'pending',
    'attempt_count' => 1, 'retry_policy' => ['max_attempts' => 2, 'backoff' => []],
    'queue' => 'fixture-queue', 'connection' => 'fixture-connection',
]]);
$started = metadataEvent('ActivityStarted', 2, ['activity' => [
    'id' => 'activity-1', 'attempt_id' => 'attempt-1', 'attempt_count' => 1,
], 'activity_attempt' => ['id' => 'attempt-1', 'attempt_number' => 1,
    'worker_attempt_id' => 'worker-attempt-1'],
    'task' => ['id' => 'task-1', 'attempt_count' => 1, 'lease_owner' => 'worker-1',
        'lease_expires_at' => '2026-10-15T10:00:32.123456Z']]);
$progress = ['message' => "  進捗 λ \t", 'current' => '9007199254740993',
    'total' => '9007199254740994', 'unit' => ' items ', 'unknown' => true,
    'details' => ['z' => null, 'int64' => 9007199254740993, 'boolean' => false,
        'text' => ' 日本語λ ', 'bad key' => 'drop', 'nested' => ['drop']]];
$heartbeat = metadataEvent('ActivityHeartbeatRecorded', 3, ['activity_attempt_id' => 'attempt-1',
    'heartbeat_at' => '2026-10-15T10:00:03.123456Z', 'progress' => $progress,
    'activity' => ['id' => 'activity-1', 'attempt_id' => 'attempt-1', 'attempt_count' => 1],
    'lease_expires_at' => '2026-10-15T10:00:33.123456Z']);
$cases = [];
$add = static function (string $name, array $events = [], array $executions = []) use (&$cases): void {
    $cases[] = ['name' => $name, 'input' => ['events' => $events, 'executions' => $executions]];
};
$add('empty');
$add('scheduled_attempt_zero', [$scheduled]);
$add('running_attempt_and_lease', [$scheduled, $started], [metadataExecution([], [metadataAttempt()])]);
$add('heartbeat_progress_int64_and_unicode', [$scheduled, $started, $heartbeat]);
$add('expired_lease_is_advisory_metadata', [$scheduled, $started], [metadataExecution([], [
    metadataAttempt(['lease_expires_at' => '2020-01-01T00:00:00.000001Z']),
])]);
$add('mutable_pending_fallback', [], [metadataExecution(['status' => 'pending',
    'attempt_count' => 1, 'current_attempt_id' => null, 'started_at' => null])]);
$add('mutable_running_fallback_and_synthetic_attempt', [], [metadataExecution()]);
foreach (['completed', 'failed', 'cancelled'] as $status) {
    $add('mutable_'.$status.'_is_unsupported', [], [metadataExecution(['status' => $status,
        'closed_at' => '2026-10-15T10:00:04.123456Z', 'result' => 'unread-inline-value'])]);
}
foreach (['ActivityCompleted' => 'completed', 'ActivityFailed' => 'failed',
    'ActivityTimedOut' => 'expired', 'ActivityCancelled' => 'cancelled'] as $type => $attemptStatus) {
    $terminal = metadataEvent($type, 4, ['activity_attempt_id' => 'attempt-1',
        'activity' => ['id' => 'activity-1', 'attempt_id' => 'attempt-1', 'attempt_count' => 1],
        'result' => ['codec' => 'avro', 'blob' => 'not-decoded-in-metadata']]);
    $add('history_'.$attemptStatus.'_without_mutable_row', [$scheduled, $started, $heartbeat, $terminal]);
}
$completed = metadataEvent('ActivityCompleted', 4, ['activity_attempt_id' => 'attempt-1',
    'activity' => ['id' => 'activity-1', 'attempt_id' => 'attempt-1', 'attempt_count' => 1]]);
$add('typed_terminal_wins_stale_running_rows_and_clears_lease', [$scheduled, $started, $completed], [
    metadataExecution([], [metadataAttempt()]),
]);
$add('typed_open_wins_stale_terminal_row', [$scheduled, $started], [
    metadataExecution(['status' => 'completed', 'closed_at' => '2026-10-15T10:00:04.123456Z'], [
        metadataAttempt(['status' => 'completed', 'closed_at' => '2026-10-15T10:00:04.123456Z']),
    ]),
]);
$retry = metadataEvent('ActivityRetryScheduled', 4, ['retry_after_attempt' => 1,
    'activity_attempt_id' => 'attempt-1', 'activity' => ['id' => 'activity-1',
        'attempt_id' => 'attempt-1', 'status' => 'pending', 'attempt_count' => 1,
        'started_at' => '2026-10-15T10:00:02.123456Z']]);
$add('retry_pending_preserves_first_attempt', [$scheduled, $started, $heartbeat, $retry]);
$second = metadataEvent('ActivityStarted', 5, ['activity' => ['id' => 'activity-1',
    'attempt_id' => 'attempt-2', 'attempt_count' => 2], 'activity_attempt' => [
        'id' => 'attempt-2', 'attempt_number' => 2, 'worker_attempt_id' => 'worker-attempt-2'],
    'task' => ['attempt_count' => 2, 'lease_owner' => 'worker-2',
        'lease_expires_at' => '2026-10-15T10:00:35.123456Z']]);
$secondComplete = metadataEvent('ActivityCompleted', 6, ['activity' => ['id' => 'activity-1',
    'attempt_id' => 'attempt-2', 'attempt_count' => 2], 'activity_attempt_id' => 'attempt-2',
    'task' => ['attempt_count' => 2]]);
$add('retry_completed_two_distinct_attempts', [$scheduled, $started, $heartbeat, $retry, $second, $secondComplete]);
$add('prestart_cancel_has_no_attempt', [$scheduled, metadataEvent('ActivityCancelled', 2)]);
$add('synthetic_current_attempt_from_snapshot', [metadataEvent('ActivityStarted', 1, [
    'activity' => ['id' => 'activity-1', 'attempt_id' => 'synthetic-1', 'attempt_count' => 3],
])], [metadataExecution(['current_attempt_id' => 'synthetic-1', 'attempt_count' => 3])]);
$add('local_attempt_is_independent_of_workflow_claim_counter', [metadataEvent('ActivityScheduled', 1, [
    'execution_mode' => 'local', 'activity' => ['id' => 'activity-1', 'execution_mode' => 'local', 'attempt_count' => 1],
]), metadataEvent('ActivityCompleted', 2, ['execution_mode' => 'local',
    'activity' => ['id' => 'activity-1', 'execution_mode' => 'local', 'attempt_count' => 1],
    'task' => ['attempt_count' => 7]])]);
$reference = new ExternalPayloadReference('file:///activity-metadata-object-never-created', str_repeat('a', 64), 8388608, 'avro');
$envelope = ['codec' => 'avro', 'external_storage' => $reference->toArray()];
$add('reused_external_result_is_not_resolved', [metadataEvent('ActivityCompleted', 1, [
    'reused_from_run_id' => 'previous-run', 'reused_activity_execution_id' => 'previous-activity',
    'reused_recorded_at' => '2026-10-14T09:00:00.000001Z', 'result' => $envelope,
    'activity' => ['id' => 'activity-1', 'arguments' => $envelope, 'result' => $envelope],
])]);
$add('mutable_external_payloads_are_not_resolved', [], [metadataExecution([
    'arguments' => ExternalPayloads::encodeStoredEnvelope($envelope),
    'result' => ExternalPayloads::encodeStoredEnvelope($envelope),
])]);
$group = ['parallel_group_id' => 'all-activities:1:2', 'parallel_group_kind' => 'activity',
    'parallel_group_base_sequence' => 1, 'parallel_group_size' => 2, 'parallel_group_index' => 1];
$select = ['parallel_group_id' => 'select-calls:3:2', 'parallel_group_kind' => 'call',
    'parallel_group_mode' => 'select', 'parallel_group_base_sequence' => 3,
    'parallel_group_size' => 2, 'parallel_group_index' => 0, 'selection_member_key' => 'winner',
    'selection_member_index' => 0, 'selection_member_kind' => 'activity'];
$add('nested_parallel_and_selection_path', [metadataEvent('ActivityScheduled', 1, [
    'parallel_group_path' => [$group, ['invalid' => true], $select],
])]);
$add('mutable_parallel_path', [], [metadataExecution(['parallel_group_path' => [$group, $select]])]);
$add('mutable_single_parallel_group_object', [], [metadataExecution(['parallel_group_path' => $group])]);
$add('php_millisecond_rounding_then_missing_sequence', [
    array_replace(metadataEvent('ActivityScheduled', 1, ['activity_execution_id' => 'z', 'sequence' => 2]), ['recorded_at' => '2026-10-15T10:00:01.123001Z']),
    array_replace(metadataEvent('ActivityScheduled', 2, ['activity_execution_id' => 'a', 'sequence' => 2]), ['recorded_at' => '2026-10-15T10:00:01.123999Z']),
    metadataEvent('ActivityScheduled', 3, ['activity_execution_id' => 'first', 'sequence' => 1]),
    metadataEvent('ActivityScheduled', 4, ['activity_execution_id' => 'string-sequence', 'sequence' => '1']),
]);
$add('rounded_millisecond_tie_uses_id', [
    array_replace(metadataEvent('ActivityScheduled', 1, ['activity_execution_id' => 'z', 'sequence' => 2]), ['recorded_at' => '2026-10-15T10:00:01.123001Z']),
    array_replace(metadataEvent('ActivityScheduled', 2, ['activity_execution_id' => 'a', 'sequence' => 2]), ['recorded_at' => '2026-10-15T10:00:01.123400Z']),
]);
$add('php_decimal_pre_rounding_near_half_millisecond', [
    array_replace(metadataEvent('ActivityScheduled', 1, ['activity_execution_id' => 'z', 'sequence' => 2]), ['recorded_at' => '2026-10-15T10:00:01.123001Z']),
    array_replace(metadataEvent('ActivityScheduled', 2, ['activity_execution_id' => 'a', 'sequence' => 2]), ['recorded_at' => '2026-10-15T10:00:01.123498Z']),
]);
$add('unrelated_and_missing_identity_history_ignored', [
    ['sequence' => 1, 'event_type' => 'WorkflowStarted', 'payload' => [], 'recorded_at' => '2026-10-15T10:00:01.123456Z'],
    ['sequence' => 2, 'event_type' => 'ActivityScheduled', 'payload' => [], 'recorded_at' => '2026-10-15T10:00:02.123456Z'],
]);
$details = [];
for ($index = 0; $index < 24; $index++) {
    $details['entry-'.$index] = $index;
}
$add('stored_progress_reader_preserves_more_than_write_limit', [metadataEvent('ActivityHeartbeatRecorded', 1, [
    'progress' => ['message' => ' ', 'current' => '1e3', 'total' => '12.50', 'unit' => ' batches ', 'details' => $details],
    'activity_attempt' => ['id' => 'attempt-1', 'attempt_number' => '2'],
])]);

foreach ($cases as &$case) {
    $run = new WorkflowRun(['id' => 'fixture-run', 'namespace' => 'fixture-namespace', 'payload_codec' => 'avro']);
    $executions = [];
    foreach ($case['input']['executions'] as $attributes) {
        $attempts = $attributes['attempts'];
        unset($attributes['attempts']);
        $execution = new ActivityExecution($attributes);
        $execution->setRelation('attempts', new Collection(array_map(
            static fn (array $row): ActivityAttempt => new ActivityAttempt($row), $attempts,
        )));
        $execution->setRelation('run', $run);
        $executions[] = $execution;
    }
    $run->setRelation('activityExecutions', new Collection($executions));
    $run->setRelation('historyEvents', new Collection(array_map(
        static fn (array $row): WorkflowHistoryEvent => new WorkflowHistoryEvent($row), $case['input']['events'],
    )));
    $case['expected'] = json_decode(json_encode(RunActivityView::activitiesForRun(
        $run, decodePayloads: false,
    ), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    foreach ($case['expected'] as $activity) {
        foreach (['arguments', 'result', 'exception'] as $field) {
            if (array_key_exists($field, $activity)) {
                throw new RuntimeException('Metadata exposed payload field '.$field);
            }
        }
    }
}
unset($case);
if ($queries !== 0 || $policy->calls !== 0) {
    throw new RuntimeException('Projection performed database or provider work.');
}
$result = ['schema' => 'durable-workflow.activity-metadata-projection.v1',
    'scope' => 'constructed_loaded_model_projection_not_executed_workflows',
    'reference_workflow' => '2.5.22', 'reference_source' => '908e2db9b4337ceae52ebe5fc5e64f3de8e5338b',
    'projector_sha256' => $referenceHashes, 'cases' => $cases];
if (($argv[1] ?? '') === '--verify') {
    $stored = json_decode(file_get_contents('/fixture/v1.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($stored !== $result) {
        throw new RuntimeException('The released PHP projection differs from the committed corpus.');
    }
    echo json_encode(['outcome' => 'pass', 'cases' => count($cases), 'database_queries' => $queries,
        'provider_policy_calls' => $policy->calls, 'workflow_execution_claimed' => false,
        'installed_workflow' => InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
        'installed_reference' => InstalledVersions::getReference('durable-workflow/workflow')], JSON_THROW_ON_ERROR)."\n";
} else {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}
