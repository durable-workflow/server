<?php

declare(strict_types=1);

// A real queue job authors the activity. The public embedded worker bridges
// then observe the same original attempt and retain its timeout parent delivery.
function activityDeadlineEmbeddedObservation(array $fixture, array $options): array
{
    $root = $options['application-root'];
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('server.mode') !== 'embedded') {
        throw new RuntimeException('Activity deadline recipe requires embedded mode');
    }

    final class ParityDeadlineState
    {
        public static array $spec;
        public static string $option;
        public static string $activityQueue;
        public static array $authoredOptions = [];
        public static int $activityInvocations = 0;
    }

    #[\Workflow\V2\Attributes\Type('parity.v1.deadline_activity')]
    final class ParityDeadlineActivity extends \Workflow\V2\Activity
    {
        public function handle(mixed $value): mixed
        {
            ParityDeadlineState::$activityInvocations++;

            return $value;
        }
    }

    #[\Workflow\V2\Attributes\Type('parity.v1.activity_deadline')]
    final class ParityDeadlineWorkflow extends \Workflow\V2\Workflow
    {
        public function handle(mixed $value): mixed
        {
            $spec = ParityDeadlineState::$spec;
            $options = new \Workflow\V2\Support\ActivityOptions(...[
                'queue' => ParityDeadlineState::$activityQueue,
                'maxAttempts' => $spec['terminal_max_attempts'],
                'backoff' => $spec['retry_backoff_seconds'],
                ParityDeadlineState::$option => $spec['timeout_seconds'],
            ]);
            ParityDeadlineState::$authoredOptions[] = $options->toSnapshot();

            return \Workflow\V2\activity(ParityDeadlineActivity::class, $options, $value);
        }
    }

    config(['queue.default' => 'database', 'workflows.v2.task_dispatch_mode' => 'queue',
        // Core 2.15.12 (Server 2.11.8) resolves a run through its instance's
        // namespace, and reserves a caller-named instance without model events.
        'workflows.v2.namespace' => 'default',
        'workflows.v2.types.workflows' => ['parity.v1.activity_deadline' => ParityDeadlineWorkflow::class],
        'workflows.v2.types.activities' => ['parity.v1.deadline_activity' => ParityDeadlineActivity::class]]);
    foreach ([\Workflow\V2\Models\WorkflowInstance::class, \Workflow\V2\Models\WorkflowRun::class,
        \Workflow\V2\Models\WorkflowTask::class] as $model) {
        $model::creating(static function ($row): void { $row->namespace = 'default'; });
    }
    $spec = $fixture['activity_deadline_authority'];
    ParityDeadlineState::$spec = $spec;
    $deadline = microtime(true) + $spec['overall_budget_seconds'];
    $checkBudget = static function () use ($deadline): void {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Original embedded activity deadline observation expired');
        }
    };
    $activityBridge = $app->make(\Workflow\V2\Support\DefaultActivityTaskBridge::class);
    $workflowBridge = $app->make(\Workflow\V2\Support\DefaultWorkflowTaskBridge::class);
    $optionNames = ['start_to_close_timeout' => 'startToCloseTimeout',
        'schedule_to_start_timeout' => 'scheduleToStartTimeout',
        'schedule_to_close_timeout' => 'scheduleToCloseTimeout', 'heartbeat_timeout' => 'heartbeatTimeout'];
    $steps = [];
    foreach ($spec['fields'] as $field) {
        $checkBudget();
        ParityDeadlineState::$option = $optionNames[$field];
        ParityDeadlineState::$authoredOptions = [];
        $id = $options['workflow-id'].'-'.$field;
        ParityDeadlineState::$activityQueue = $id.'-activity';
        $stub = \Workflow\V2\WorkflowStub::make(ParityDeadlineWorkflow::class, $id, 'default');
        $stub->start($fixture['input'], new \Workflow\WorkflowOptions(connection: 'database', queue: $id.'-workflow'),
            new \Workflow\V2\StartOptions(executionTimeoutSeconds: 1200, runTimeoutSeconds: 1200));
        \Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'database',
            '--queue' => $id.'-workflow', '--once' => true, '--stop-when-empty' => true,
            '--sleep' => 0, '--tries' => 1, '--max-time' => 5]);
        $workerOutput = \Illuminate\Support\Facades\Artisan::output();
        $runId = $stub->runId();
        $snapshot = static function () use ($runId): array {
            $run = \Workflow\V2\Models\WorkflowRun::query()->findOrFail($runId);
            $events = $run->historyEvents()->orderBy('sequence')->get()->map(static fn ($event): array => [
                'sequence' => $event->sequence, 'event_type' => $event->event_type->value,
                'timestamp' => $event->recorded_at->toISOString(), 'payload' => $event->payload])->all();

            return ['workflow_id' => $run->workflow_instance_id, 'run_id' => $run->id,
                'workflow_type' => $run->workflow_type, 'namespace' => $run->namespace,
                'execution' => $run->toArray(), 'typed_input' => typedValue($run->workflowArguments()),
                'events' => decodeHistory($events, static fn ($envelope) => \Workflow\Serializers\Serializer::unserializeWithCodec(
                    'avro', is_array($envelope) ? $envelope['blob'] : $envelope)),
                'activities' => $run->activityExecutions()->get()->map->toArray()->all(),
                'tasks' => $run->tasks()->orderBy('created_at')->get()->map->toArray()->all()];
        };
        $scheduled = $snapshot();
        $original = array_values(array_filter($scheduled['tasks'], static fn (array $task): bool => $task['task_type'] === 'workflow'));
        if (count($original) !== 1 || count(ParityDeadlineState::$authoredOptions) !== 1) {
            throw new RuntimeException('One actual original queue author is required');
        }
        $tasks = $activityBridge->poll('database', $id.'-activity', 1, namespace: 'default');
        if (count($tasks) !== 1) {
            throw new RuntimeException('Original embedded activity is missing');
        }
        $claim = $field === 'schedule_to_start_timeout' ? null
            : $activityBridge->claimStatus($tasks[0]['task_id'], $id.'-activity-worker');
        if ($claim !== null && ! $claim['claimed']) {
            throw new RuntimeException('Original embedded activity claim was refused');
        }
        $before = $snapshot();
        $statusBefore = $claim === null ? null : $activityBridge->status($claim['activity_attempt_id']);
        usleep(($spec['timeout_seconds'] * 1000000) + 250000);
        $checkBudget();
        $statusAfter = $claim === null ? null : $activityBridge->status($claim['activity_attempt_id']);
        $late = $claim === null ? [] : [
            ['id' => 'complete', 'response' => $activityBridge->complete($claim['activity_attempt_id'], $fixture['input'])],
            ['id' => 'fail', 'response' => $activityBridge->fail($claim['activity_attempt_id'], 'late deadline outcome')],
            ['id' => 'heartbeat', 'response' => $activityBridge->heartbeat($claim['activity_attempt_id'], ['message' => 'late deadline progress'])],
        ];
        $enforcement = \Workflow\V2\Support\ActivityTimeoutEnforcer::enforce($scheduled['activities'][0]['id']);
        $parents = $workflowBridge->poll('database', $id.'-workflow', 1, namespace: 'default');
        if (count($parents) !== 1) {
            throw new RuntimeException('Original embedded timeout parent is missing');
        }
        $parent = $workflowBridge->claimStatus($parents[0]['task_id'], $id.'-parent-worker');
        if (! $parent['claimed']) {
            throw new RuntimeException('Original embedded timeout parent was refused');
        }
        $delivery = $workflowBridge->historyPayload($parent['task_id']);
        $after = $snapshot();
        $parentRow = array_values(array_filter($after['tasks'], static fn (array $task): bool => $task['id'] === $parent['task_id']))[0];
        $steps[$field] = ['field' => $field, 'workflow_id' => $id, 'run_id' => $runId,
            'workflow_task' => ['workflow_id' => $id, 'run_id' => $runId, 'task_id' => $original[0]['id'],
                'workflow_task_attempt' => $original[0]['attempt_count']],
            'authored_options' => ParityDeadlineState::$authoredOptions[0], 'worker_output' => $workerOutput,
            'scheduled' => $scheduled, 'before_expiry' => $before, 'after_expiry' => $after,
            'activity_task' => $claim === null ? null : [...$claim,
                'workflow_id' => $claim['workflow_instance_id'], 'run_id' => $claim['workflow_run_id']],
            'status_before_expiry' => $statusBefore, 'status_after_expiry' => $statusAfter,
            'late_outcomes' => $late, 'enforcement' => $enforcement['enforced'],
            'parent_delivery' => [...$parent, 'workflow_id' => $parent['workflow_instance_id'],
                'run_id' => $parent['workflow_run_id'], 'workflow_task_attempt' => $parentRow['attempt_count'],
                'history_events' => $delivery['history_events']],
            'post_expiry_activity_poll' => $activityBridge->poll('database', $id.'-activity', 1, namespace: 'default'),
            'extra_parent_poll' => $workflowBridge->poll('database', $id.'-workflow', 1, namespace: 'default')];
    }

    return ['workflow_id' => $options['workflow-id'], 'workflow_type' => $fixture['workflow_type'],
        'namespace' => 'default', 'status' => 'deadline_observed', 'input' => [$fixture['input']], 'output' => null,
        'events' => [], 'workflow_source' => \Composer\InstalledVersions::getReference('durable-workflow/workflow'),
        'embedded_composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
        'activity_deadline_authority' => ['driver' => 'real_queue_then_public_embedded_bridges',
            'overall_budget_seconds' => $spec['overall_budget_seconds'], 'steps' => $steps, 'requests' => [],
            'activity_invocations' => ParityDeadlineState::$activityInvocations,
            'definition_sha256' => hash_file('sha256', __FILE__)]];
}
