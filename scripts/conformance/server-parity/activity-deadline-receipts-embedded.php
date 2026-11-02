<?php

declare(strict_types=1);

require_once __DIR__.'/activity-heartbeat-renewal-probe.php';

function activityDeadlineReceiptsEmbeddedObservation(array $fixture, array $options): array
{
    $root = $options['application-root'];
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('server.mode') !== 'embedded') {
        throw new RuntimeException('Receipt recipe requires embedded mode');
    }

    final class ParityReceiptState
    {
        public static array $spec;
        public static string $queue;
        public static array $authoredOptions = [];
        public static int $activityInvocations = 0;
    }

    #[\Workflow\V2\Attributes\Type('parity.v1.deadline_activity')]
    final class ParityReceiptActivity extends \Workflow\V2\Activity
    {
        public function handle(mixed $value): mixed
        {
            ParityReceiptState::$activityInvocations++;

            return $value;
        }
    }

    #[\Workflow\V2\Attributes\Type('parity.v1.activity_deadline_receipts')]
    final class ParityReceiptWorkflow extends \Workflow\V2\Workflow
    {
        public function handle(mixed $value): mixed
        {
            $spec = ParityReceiptState::$spec;
            $activity = new \Workflow\V2\Support\ActivityOptions(queue: ParityReceiptState::$queue,
                maxAttempts: $spec['max_attempts'], backoff: $spec['backoff_seconds'],
                startToCloseTimeout: $spec['start_to_close_timeout'],
                scheduleToCloseTimeout: $spec['schedule_to_close_timeout'],
                heartbeatTimeout: $spec['heartbeat_timeout'] ?? null);
            ParityReceiptState::$authoredOptions[] = $activity->toSnapshot();

            return \Workflow\V2\activity(ParityReceiptActivity::class, $activity, $value);
        }
    }

    config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
        // Core 2.15.12 (Server 2.11.8) resolves a run through its instance's
        // namespace, and reserves a caller-named instance without model events.
        'workflows.v2.namespace' => 'default',
        'workflows.v2.types.workflows' => [$fixture['workflow_type'] => ParityReceiptWorkflow::class],
        'workflows.v2.types.activities' => ['parity.v1.deadline_activity' => ParityReceiptActivity::class]]);
    foreach ([\Workflow\V2\Models\WorkflowInstance::class, \Workflow\V2\Models\WorkflowRun::class,
        \Workflow\V2\Models\WorkflowTask::class] as $model) {
        $model::creating(static function ($row): void { $row->namespace = 'default'; });
    }
    $id = $options['workflow-id'];
    $spec = $fixture['activity_deadline_receipts'];
    ParityReceiptState::$spec = $spec;
    ParityReceiptState::$queue = $id.'-activity';
    $deadline = microtime(true) + $spec['overall_budget_seconds'];
    $budget = static function () use ($deadline): void {
        if (microtime(true) >= $deadline) throw new RuntimeException('Original embedded receipt budget expired');
    };
    $stub = \Workflow\V2\WorkflowStub::make(ParityReceiptWorkflow::class, $id, 'default');
    $stub->start($fixture['input'], new \Workflow\WorkflowOptions(connection: 'database', queue: $id.'-workflow'),
        new \Workflow\V2\StartOptions(executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200));
    \Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'database',
        '--queue' => $id.'-workflow', '--once' => true, '--stop-when-empty' => true,
        '--sleep' => 0, '--tries' => 1, '--max-time' => 5]);
    $workerOutput = \Illuminate\Support\Facades\Artisan::output();
    $admittedAt = microtime(true);
    $runId = $stub->runId();
    $snapshot = static function () use ($runId): array {
        $run = \Workflow\V2\Models\WorkflowRun::query()->findOrFail($runId);
        $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
            'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
            'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload])->all();

        return ['workflow_id' => $run->workflow_instance_id, 'run_id' => $run->id,
            'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace,
            'status' => strtolower($run->status->value), 'execution' => $run->toArray(),
            'input' => $run->workflowArguments(), 'output' => $run->workflowOutput(),
            'events' => decodeHistory($events, static fn ($envelope) => \Workflow\Serializers\Serializer::unserializeWithCodec(
                'avro', is_array($envelope) ? $envelope['blob'] : $envelope)),
            'activities' => $run->activityExecutions()->get()->map->toArray()->all(),
            'tasks' => $run->tasks()->orderBy('created_at')->get()->map->toArray()->all()];
    };
    $activityBridge = $app->make(\Workflow\V2\Support\DefaultActivityTaskBridge::class);
    $workflowBridge = $app->make(\Workflow\V2\Support\DefaultWorkflowTaskBridge::class);
    $claimObservations = [];
    $claim = static function () use ($activityBridge, $id, &$claimObservations): ?array {
        $tasks = $activityBridge->poll('database', $id.'-activity', 1, namespace: 'default');
        if ($tasks === []) return null;
        $claim = $activityBridge->claimStatus($tasks[0]['task_id'], $id.'-activity-worker');
        $claimObservations[] = $claim;
        // Poll may expose a future retry within its documented availability
        // tolerance. Claim remains authoritative and never skips the backoff.
        if (! $claim['claimed'] && $claim['reason'] === 'task_not_due') return null;
        if (! $claim['claimed']) throw new RuntimeException('Original embedded activity claim refused: '.$claim['reason']);

        return [...$claim, 'workflow_id' => $claim['workflow_instance_id'], 'run_id' => $claim['workflow_run_id']];
    };
    $first = $claim();
    if ($first === null) throw new RuntimeException('Original embedded activity missing');
    $before = $snapshot();
    $original = array_values(array_filter($before['tasks'], static fn (array $task): bool => $task['task_type'] === 'workflow'));
    if (count($original) !== 1 || count(ParityReceiptState::$authoredOptions) !== 1) {
        throw new RuntimeException('One real original queue author is required');
    }
    $calls = [];
    $complete = static function (string $attempt, mixed $value) use ($activityBridge, &$calls): array {
        $response = $activityBridge->complete($attempt, $value);
        $calls[] = ['operation' => 'complete_activity', 'activity_attempt_id' => $attempt,
            'typed_value' => typedValue($value), 'response' => $response];

        return $response;
    };
    if ($spec['heartbeat_control'] ?? false) {
        $observed = activityHeartbeatRenewalObservation($fixture, [
            'driver' => 'real_queue_then_public_embedded_bridges',
            'workflow_task' => [...$original[0], 'workflow_id' => $id, 'run_id' => $runId,
                'task_id' => $original[0]['id'], 'workflow_task_attempt' => $original[0]['attempt_count']],
            'first_attempt' => $first, 'before_expiry' => $before,
            'authored_options' => ParityReceiptState::$authoredOptions[0], 'worker_output' => $workerOutput,
            'activity_invocations' => ParityReceiptState::$activityInvocations,
            'definition_sha256' => hash_file('sha256', __FILE__),
        ], $admittedAt, $snapshot, $budget, [
            'heartbeat' => static fn (array $progress): array => $activityBridge->heartbeat($first['activity_attempt_id'], ['details' => $progress]),
            'status' => static fn (): array => $activityBridge->status($first['activity_attempt_id']),
            'complete' => static fn (): array => $complete($first['activity_attempt_id'], $fixture['input']),
            'finish' => static function () use ($workflowBridge, $id, $runId, $fixture): array {
                $parents = $workflowBridge->poll('database', $id.'-workflow', 1, namespace: 'default');
                if (count($parents) !== 1) throw new RuntimeException('Renewed embedded parent missing');
                $parent = $workflowBridge->claimStatus($parents[0]['task_id'], $id.'-parent-worker');
                if (! $parent['claimed']) throw new RuntimeException('Renewed embedded parent refused');
                $row = \Workflow\V2\Models\WorkflowTask::query()->findOrFail($parent['task_id']);
                $parent = [...$parent, 'workflow_id' => $id, 'run_id' => $runId, 'workflow_task_attempt' => $row->attempt_count];
                $response = $workflowBridge->complete($parent['task_id'], [['type' => 'complete_workflow', 'payload_codec' => 'avro',
                    'result' => \Workflow\Serializers\Serializer::serializeWithCodec('avro', $fixture['input'])]]);

                return [$parent, $response];
            },
            'extra_activity_poll' => static fn (): array => $activityBridge->poll('database', $id.'-activity', 1, namespace: 'default'),
        ], static fn (): array => []);

        return [...$observed, 'workflow_source' => \Composer\InstalledVersions::getReference('durable-workflow/workflow'),
            'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock')];
    }
    usleep($spec['start_to_close_timeout'] * 1000000 + 250000);
    $budget();
    $refused = $complete($first['activity_attempt_id'], $fixture['input']);
    $enforcement = \Workflow\V2\Support\ActivityTimeoutEnforcer::enforce($first['activity_execution_id']);
    do {
        $budget();
        $second = $claim();
        if ($second === null) usleep(25000);
    } while ($second === null);
    $retried = $snapshot();
    if ($spec['global_timeout_control'] ?? false) {
        $remaining = $admittedAt + $spec['schedule_to_close_timeout'] + 0.25 - microtime(true);
        if ($remaining > 0) usleep((int) ceil($remaining * 1000000));
        $budget();
        $elapsed = microtime(true) - $admittedAt;
        $status = $activityBridge->status($second['activity_attempt_id']);
        $late = [
            ['id' => 'complete', 'response' => $complete($second['activity_attempt_id'], $fixture['input'])],
            ['id' => 'fail', 'response' => $activityBridge->fail($second['activity_attempt_id'], 'late global result')],
            ['id' => 'heartbeat', 'response' => $activityBridge->heartbeat($second['activity_attempt_id'], ['message' => 'late global progress'])],
        ];
        $globalEnforcement = \Workflow\V2\Support\ActivityTimeoutEnforcer::enforce($second['activity_execution_id']);
        $parents = $workflowBridge->poll('database', $id.'-workflow', 1, namespace: 'default');
        if (count($parents) !== 1) throw new RuntimeException('Original global-timeout parent missing');
        $parent = $workflowBridge->claimStatus($parents[0]['task_id'], $id.'-parent-worker');
        if (! $parent['claimed']) throw new RuntimeException('Original global-timeout parent refused');
        $delivery = $workflowBridge->historyPayload($parent['task_id']);
        $parentRow = \Workflow\V2\Models\WorkflowTask::query()->findOrFail($parent['task_id']);
        $parent = [...$parent, 'workflow_id' => $parent['workflow_instance_id'], 'run_id' => $parent['workflow_run_id'],
            'workflow_task_attempt' => $parentRow->attempt_count, 'history_events' => $delivery['history_events']];
        $after = $snapshot();

        return [...$after, 'status' => 'global_deadline_observed',
            'workflow_source' => \Composer\InstalledVersions::getReference('durable-workflow/workflow'),
            'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
            'activity_deadline_receipts' => ['driver' => 'real_queue_then_public_embedded_bridges',
                'overall_budget_seconds' => $spec['overall_budget_seconds'], 'global_elapsed_seconds' => $elapsed,
                'workflow_task' => [...$original[0], 'workflow_id' => $id, 'run_id' => $runId,
                    'task_id' => $original[0]['id'], 'workflow_task_attempt' => $original[0]['attempt_count']],
                'authored_options' => ParityReceiptState::$authoredOptions[0], 'worker_output' => $workerOutput,
                'activity_invocations' => ParityReceiptState::$activityInvocations,
                'first_enforcement' => $enforcement, 'global_enforcement' => $globalEnforcement,
                'first_attempt' => $first, 'before_expiry' => $before, 'first_refusal' => $refused,
                'second_attempt' => $second, 'after_retry' => $retried, 'status_after_expiry' => $status,
                'late_outcomes' => $late, 'parent_delivery' => $parent, 'requests' => [],
                'post_expiry_activity_polls' => [$activityBridge->poll('database', $id.'-activity', 1, namespace: 'default')],
                'extra_parent_poll' => $workflowBridge->poll('database', $id.'-workflow', 1, namespace: 'default'),
                'bridge_calls' => $calls, 'claim_observations' => $claimObservations,
                'definition_sha256' => hash_file('sha256', __FILE__)]];
    }
    $completion = $complete($second['activity_attempt_id'], $fixture['input']);
    $parents = $workflowBridge->poll('database', $id.'-workflow', 1, namespace: 'default');
    if (count($parents) !== 1) throw new RuntimeException('Original successful retry parent missing');
    $parent = $workflowBridge->claimStatus($parents[0]['task_id'], $id.'-parent-worker');
    if (! $parent['claimed']) throw new RuntimeException('Original successful retry parent refused');
    $parentRow = \Workflow\V2\Models\WorkflowTask::query()->findOrFail($parent['task_id']);
    $parent = [...$parent, 'workflow_id' => $parent['workflow_instance_id'], 'run_id' => $parent['workflow_run_id'],
        'workflow_task_attempt' => $parentRow->attempt_count];
    $finish = [['type' => 'complete_workflow', 'payload_codec' => 'avro',
        'result' => \Workflow\Serializers\Serializer::serializeWithCodec('avro', $fixture['input'])]];
    $activeControl = (bool) ($spec['active_receipt_control'] ?? false);
    $activeBeforeElapsed = $activeControl ? microtime(true) - $admittedAt : null;
    $activeBefore = $activeControl ? $complete($second['activity_attempt_id'], $fixture['input']) : null;
    $workflowCompletion = $activeControl ? null : $workflowBridge->complete($parent['task_id'], $finish);
    $committed = $snapshot();
    $remaining = $admittedAt + $spec['schedule_to_close_timeout'] + 0.25 - microtime(true);
    if ($remaining > 0) usleep((int) ceil($remaining * 1000000));
    $budget();
    $elapsed = microtime(true) - $admittedAt;
    $duplicate = $complete($second['activity_attempt_id'], $fixture['input']);
    $conflict = $complete($second['activity_attempt_id'], ['different' => true]);
    $activeAfter = $activeControl ? $snapshot() : null;
    if ($activeControl) $workflowCompletion = $workflowBridge->complete($parent['task_id'], $finish);
    $workflowDuplicate = $workflowBridge->complete($parent['task_id'], $finish);
    $after = $snapshot();

    return [...$after, 'workflow_source' => \Composer\InstalledVersions::getReference('durable-workflow/workflow'),
        'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
        'activity_deadline_receipts' => ['driver' => 'real_queue_then_public_embedded_bridges',
            'overall_budget_seconds' => $spec['overall_budget_seconds'], 'global_elapsed_seconds' => $elapsed,
            'workflow_task' => [...$original[0], 'workflow_id' => $id, 'run_id' => $runId,
                'task_id' => $original[0]['id'], 'workflow_task_attempt' => $original[0]['attempt_count']],
            'authored_options' => ParityReceiptState::$authoredOptions[0], 'worker_output' => $workerOutput,
            'activity_invocations' => ParityReceiptState::$activityInvocations, 'enforcement' => $enforcement,
            'first_attempt' => $first, 'before_expiry' => $before, 'first_refusal' => $refused,
            'second_attempt' => $second, 'after_retry' => $retried, 'activity_completion' => $completion,
            'parent_delivery' => $parent, 'workflow_command' => $finish, 'workflow_completion' => $workflowCompletion,
            'committed' => $committed, 'duplicate' => $duplicate, 'conflict' => $conflict,
            'active_before_expiry' => $activeBefore, 'active_before_expiry_elapsed_seconds' => $activeBeforeElapsed,
            'active_after_expiry' => $activeAfter, 'workflow_duplicate' => $workflowDuplicate,
            'requests' => [], 'bridge_calls' => $calls, 'claim_observations' => $claimObservations,
            'definition_sha256' => hash_file('sha256', __FILE__)]];
}
