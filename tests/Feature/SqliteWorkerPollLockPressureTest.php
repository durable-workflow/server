<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

class SqliteWorkerPollLockPressureTest extends TestCase
{
    private ?string $databasePath = null;

    private ?PDO $lockConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $databaseDirectory = is_dir('/dev/shm') && is_writable('/dev/shm')
            ? '/dev/shm'
            : sys_get_temp_dir();
        $path = tempnam($databaseDirectory, 'dw-server-sqlite-poll-');

        if ($path === false) {
            $this->fail('Could not allocate a SQLite database file for the lock-pressure test.');
        }

        $this->databasePath = $path;

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $path,
            'database.connections.sqlite.busy_timeout' => 1,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
        ]);

        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--force' => true])
            ->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        if ($this->lockConnection instanceof PDO) {
            if ($this->lockConnection->inTransaction()) {
                $this->lockConnection->rollBack();
            }

            $this->lockConnection = null;
        }

        DB::disconnect('sqlite');

        if (is_string($this->databasePath)) {
            foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        parent::tearDown();
    }

    public function test_activity_polls_return_structured_lock_pressure_for_two_external_workers_on_sqlite(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-sqlite-poll-lock-pressure');
        $start = $workflow->start('Ada');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Activity->value)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $task);

        $task->forceFill(['queue' => 'polyglot-shared'])->save();
        ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->update(['queue' => 'polyglot-shared']);

        $this->registerWorker('php-sqlite-poller', 'php');
        $this->registerWorker('python-sqlite-poller', 'python');

        $this->holdSqliteWriteLock($task->id);

        foreach (['php-sqlite-poller', 'python-sqlite-poller'] as $workerId) {
            $poll = $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/activity-tasks/poll', [
                    'worker_id' => $workerId,
                    'task_queue' => 'polyglot-shared',
                ]);

            $poll->assertStatus(503)
                ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
                ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
                ->assertHeader('Retry-After', '1')
                ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'backend_lock_pressure')
                ->assertJsonPath('reason', 'backend_lock_pressure')
                ->assertJsonPath('task_kind', 'activity_task')
                ->assertJsonPath('namespace', 'default')
                ->assertJsonPath('task_queue', 'polyglot-shared')
                ->assertJsonPath('retry_after_seconds', 1)
                ->assertJsonPath('backend.driver', 'sqlite')
                ->assertJsonPath('backend.lock_pressure', true)
                ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message)
                    && str_contains($message, 'Retry the poll with backoff'))
                ->assertJsonPath('server_capabilities.poll_status', true)
                ->assertJsonMissingPath('control_plane');

            DB::disconnect('sqlite');
        }
    }

    public function test_exhausted_worker_registration_pressure_exposes_the_managed_retry_contract(): void
    {
        Queue::fake();

        [, $leasedTask] = $this->createLeasedInteractiveSignalTask(
            'wf-sqlite-worker-registration-lock-response',
            'php-sqlite-existing-worker',
            'sqlite-worker-registration-lock-response-signal',
        );

        $this->holdSqliteWriteLock($leasedTask->id);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'php-sqlite-registration-retry-worker',
                'task_queue' => 'polyglot-shared',
                'runtime' => 'php',
            ])
            ->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('reason', 'backend_lock_pressure')
            ->assertJsonPath('operation', 'register_worker')
            ->assertJsonPath('worker_id', 'php-sqlite-registration-retry-worker')
            ->assertJsonPath('task_queue', 'polyglot-shared')
            ->assertJsonPath('registered', false)
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('retry_after_seconds', 1)
            ->assertJsonPath('backend.driver', 'sqlite')
            ->assertJsonPath('backend.lock_pressure', true)
            ->assertJsonMissing(['message' => 'Server Error']);

        $this->assertFalse(WorkerRegistration::query()
            ->where('worker_id', 'php-sqlite-registration-retry-worker')
            ->exists());
    }

    public function test_php_startup_activity_heartbeat_and_completion_retry_transient_sqlite_pressure(): void
    {
        Queue::fake();

        [$runId, $taskId, $attemptId, $leaseOwner] = $this->leaseExternalActivity(
            'wf-sqlite-php-startup-activity',
            'php-sqlite-startup-worker',
        );

        $heartbeatWriter = $this->startTransientControlPlaneUpdate($runId);

        try {
            $this->withHeaders($this->workerHeaders())
                ->postJson("/api/worker/activity-tasks/{$taskId}/heartbeat", [
                    'activity_attempt_id' => $attemptId,
                    'lease_owner' => $leaseOwner,
                    'details' => ['phase' => 'startup'],
                ])
                ->assertOk()
                ->assertJsonPath('task_id', $taskId)
                ->assertJsonPath('activity_attempt_id', $attemptId)
                ->assertJsonPath('lease_owner', $leaseOwner)
                ->assertJsonPath('can_continue', true)
                ->assertJsonPath('heartbeat_recorded', true)
                ->assertJsonPath('reason', null);
        } finally {
            $this->finishTransientLeaseUpdate($heartbeatWriter);
        }

        $completionWriter = $this->startTransientControlPlaneUpdate($runId);

        try {
            $this->withHeaders($this->workerHeaders())
                ->postJson("/api/worker/activity-tasks/{$taskId}/complete", [
                    'activity_attempt_id' => $attemptId,
                    'lease_owner' => $leaseOwner,
                    'result' => 'Hello, startup!',
                ])
                ->assertOk()
                ->assertJsonPath('task_id', $taskId)
                ->assertJsonPath('activity_attempt_id', $attemptId)
                ->assertJsonPath('outcome', 'completed')
                ->assertJsonPath('recorded', true)
                ->assertJsonPath('reason', null);
        } finally {
            $this->finishTransientLeaseUpdate($completionWriter);
        }

        $attempt = ActivityAttempt::query()->findOrFail($attemptId);

        $this->assertSame('completed', $attempt->status->value);
        $this->assertNotNull($attempt->last_heartbeat_at);
        $this->assertSame(1, ActivityAttempt::query()->whereKey($attemptId)->count());
    }

    public function test_exhausted_activity_heartbeat_pressure_is_non_fatal_to_released_workers(): void
    {
        Queue::fake();

        [, $taskId, $attemptId, $leaseOwner] = $this->leaseExternalActivity(
            'wf-sqlite-php-startup-heartbeat-pressure',
            'php-sqlite-startup-worker',
        );

        $this->holdSqliteWriteLock($taskId);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$taskId}/heartbeat", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => $leaseOwner,
                'details' => ['phase' => 'startup'],
            ])
            ->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('activity_attempt_id', $attemptId)
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('cancel_requested', false)
            ->assertJsonPath('can_continue', true)
            ->assertJsonPath('heartbeat_recorded', false)
            ->assertJsonPath('reason', 'backend_lock_pressure')
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('retry_after_seconds', 1)
            ->assertJsonPath('backend.driver', 'sqlite')
            ->assertJsonPath('backend.lock_pressure', true);
    }

    public function test_consecutive_signals_retry_a_worker_lease_write_without_recording_a_duplicate(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(
            InteractiveCommandWorkflow::class,
            'wf-sqlite-control-plane-lock-pressure',
        );
        $start = $workflow->start();

        NamespaceWorkflowScope::bind('default', $workflow->id(), InteractiveCommandWorkflow::class);

        $this->runReadyWorkflowTask($start->runId());

        $firstSignal = $this->withHeaders($this->controlPlaneHeaders())
            ->postJson('/api/workflows/wf-sqlite-control-plane-lock-pressure/signal/advance', [
                'input' => ['Ada'],
                'request_id' => 'sqlite-signal-advance',
            ]);

        $firstSignal->assertAccepted()
            ->assertJsonPath('reason', null)
            ->assertJsonPath('signal_name', 'advance');

        /** @var WorkflowTask|null $leasedTask */
        $leasedTask = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $leasedTask);

        $leasedTask->forceFill([
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'php-sqlite-worker',
            'leased_at' => now(),
            'lease_expires_at' => now()->addSeconds(30),
            'attempt_count' => 1,
        ])->save();

        $leaseWriter = $this->startTransientLeaseUpdate($leasedTask->id);

        try {
            $secondSignal = $this->withHeaders($this->controlPlaneHeaders())
                ->postJson('/api/workflows/wf-sqlite-control-plane-lock-pressure/signal/finish', [
                    'request_id' => 'sqlite-signal-finish',
                ]);

            $secondSignal->assertAccepted()
                ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
                ->assertJsonPath('reason', null)
                ->assertJsonPath('signal_name', 'finish')
                ->assertJsonPath('control_plane.operation', 'signal');
        } finally {
            $this->finishTransientLeaseUpdate($leaseWriter);
        }

        $signals = WorkflowSignal::query()
            ->where('workflow_run_id', $start->runId())
            ->orderBy('command_sequence')
            ->get();

        $this->assertSame(['advance', 'finish'], $signals->pluck('signal_name')->all());
        $this->assertSame(
            $signals[0]->command_sequence + 1,
            $signals[1]->command_sequence,
            'A rolled-back retry must not consume an extra command sequence.',
        );
        $this->assertSame(
            2,
            WorkflowCommand::query()
                ->where('workflow_run_id', $start->runId())
                ->where('command_type', CommandType::Signal->value)
                ->count(),
        );
    }

    public function test_workflow_task_heartbeat_retries_signal_side_write_pressure_and_preserves_the_lease(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(
            InteractiveCommandWorkflow::class,
            'wf-sqlite-heartbeat-lock-pressure',
        );
        $start = $workflow->start();

        NamespaceWorkflowScope::bind('default', $workflow->id(), InteractiveCommandWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        $signal = $this->withHeaders($this->controlPlaneHeaders())
            ->postJson('/api/workflows/wf-sqlite-heartbeat-lock-pressure/signal/advance', [
                'input' => ['Ada'],
                'request_id' => 'sqlite-heartbeat-signal-advance',
            ]);

        $signal->assertAccepted()
            ->assertJsonPath('reason', null)
            ->assertJsonPath('signal_name', 'advance');

        /** @var WorkflowTask|null $leasedTask */
        $leasedTask = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $leasedTask);

        $initialLeaseExpiresAt = now()->addSeconds(15);

        $leasedTask->forceFill([
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'php-sqlite-heartbeat-worker',
            'leased_at' => now(),
            'lease_expires_at' => $initialLeaseExpiresAt,
            'attempt_count' => 1,
        ])->save();

        $this->registerWorker('php-sqlite-heartbeat-worker', 'php');

        $signalWriter = $this->startTransientControlPlaneUpdate($start->runId());

        try {
            $heartbeat = $this->withHeaders($this->workerHeaders())
                ->postJson("/api/worker/workflow-tasks/{$leasedTask->id}/heartbeat", [
                    'lease_owner' => 'php-sqlite-heartbeat-worker',
                    'workflow_task_attempt' => 1,
                ]);

            $heartbeat->assertOk()
                ->assertJsonPath('task_id', $leasedTask->id)
                ->assertJsonPath('workflow_task_attempt', 1)
                ->assertJsonPath('lease_owner', 'php-sqlite-heartbeat-worker')
                ->assertJsonPath('renewed', true)
                ->assertJsonPath('reason', null);
        } finally {
            $this->finishTransientLeaseUpdate($signalWriter);
        }

        $renewedTask = WorkflowTask::query()->findOrFail($leasedTask->id);

        $this->assertSame(TaskStatus::Leased, $renewedTask->status);
        $this->assertSame('php-sqlite-heartbeat-worker', $renewedTask->lease_owner);
        $this->assertSame(1, $renewedTask->attempt_count);
        $this->assertTrue($renewedTask->lease_expires_at->greaterThan($initialLeaseExpiresAt));

        $this->assertSame(
            1,
            WorkflowSignal::query()
                ->where('workflow_run_id', $start->runId())
                ->where('signal_name', 'advance')
                ->count(),
        );
        $this->assertSame(
            1,
            WorkflowCommand::query()
                ->where('workflow_run_id', $start->runId())
                ->where('command_type', CommandType::Signal->value)
                ->count(),
        );
    }

    public function test_exhausted_workflow_task_heartbeat_lock_pressure_is_non_fatal_and_retryable(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(
            InteractiveCommandWorkflow::class,
            'wf-sqlite-heartbeat-lock-response',
        );
        $start = $workflow->start();

        NamespaceWorkflowScope::bind('default', $workflow->id(), InteractiveCommandWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        $this->withHeaders($this->controlPlaneHeaders())
            ->postJson('/api/workflows/wf-sqlite-heartbeat-lock-response/signal/advance', [
                'input' => ['Ada'],
                'request_id' => 'sqlite-heartbeat-lock-response-signal',
            ])
            ->assertAccepted();

        /** @var WorkflowTask|null $leasedTask */
        $leasedTask = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $leasedTask);

        $leaseExpiresAt = now()->addSeconds(30);

        $leasedTask->forceFill([
            'status' => TaskStatus::Leased->value,
            'lease_owner' => 'php-sqlite-heartbeat-worker',
            'leased_at' => now(),
            'lease_expires_at' => $leaseExpiresAt,
            'attempt_count' => 1,
        ])->save();

        $this->registerWorker('php-sqlite-heartbeat-worker', 'php');
        $this->holdSqliteWriteLock($leasedTask->id);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$leasedTask->id}/heartbeat", [
                'lease_owner' => 'php-sqlite-heartbeat-worker',
                'workflow_task_attempt' => 1,
            ])
            ->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task_id', $leasedTask->id)
            ->assertJsonPath('workflow_task_attempt', 1)
            ->assertJsonPath('lease_owner', 'php-sqlite-heartbeat-worker')
            ->assertJsonPath('renewed', false)
            ->assertJsonPath('reason', 'backend_lock_pressure')
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('retry_after_seconds', 1)
            ->assertJsonPath('backend.driver', 'sqlite')
            ->assertJsonPath('backend.lock_pressure', true);

        $this->assertTrue(
            WorkflowTask::query()->findOrFail($leasedTask->id)->lease_expires_at->equalTo($leaseExpiresAt),
        );
    }

    public function test_workflow_task_completion_retries_signal_side_write_pressure_without_duplicate_state(): void
    {
        Queue::fake();

        [$runId, $leasedTask] = $this->createLeasedInteractiveSignalTask(
            'wf-sqlite-completion-lock-pressure',
            'php-sqlite-completion-worker',
            'sqlite-completion-signal-advance',
        );

        $signalWriter = $this->startTransientControlPlaneUpdate($runId);

        try {
            $completion = $this->withHeaders($this->workerHeaders())
                ->postJson("/api/worker/workflow-tasks/{$leasedTask->id}/complete", [
                    'lease_owner' => 'php-sqlite-completion-worker',
                    'workflow_task_attempt' => 1,
                    'commands' => [[
                        'type' => 'open_signal_wait',
                        'signal_name' => 'finish',
                        'timeout_seconds' => 300,
                    ]],
                ]);

            $completion->assertOk()
                ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
                ->assertJsonPath('task_id', $leasedTask->id)
                ->assertJsonPath('workflow_task_attempt', 1)
                ->assertJsonPath('outcome', 'completed')
                ->assertJsonPath('recorded', true)
                ->assertJsonPath('reason', null);
        } finally {
            $this->finishTransientLeaseUpdate($signalWriter);
        }

        $this->assertSame(TaskStatus::Completed, WorkflowTask::query()->findOrFail($leasedTask->id)->status);
        $this->assertSame(
            1,
            WorkflowSignal::query()
                ->where('workflow_run_id', $runId)
                ->where('signal_name', 'advance')
                ->count(),
        );
        $this->assertSame(
            1,
            WorkflowCommand::query()
                ->where('workflow_run_id', $runId)
                ->where('command_type', CommandType::Signal->value)
                ->count(),
        );
    }

    public function test_exhausted_workflow_task_completion_lock_pressure_is_typed_and_fenced(): void
    {
        Queue::fake();

        [$runId, $leasedTask] = $this->createLeasedInteractiveSignalTask(
            'wf-sqlite-completion-lock-response',
            'php-sqlite-completion-worker',
            'sqlite-completion-lock-response-signal',
        );

        $this->holdSqliteWriteLock($leasedTask->id);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$leasedTask->id}/complete", [
                'lease_owner' => 'php-sqlite-completion-worker',
                'workflow_task_attempt' => 1,
                'commands' => [[
                    'type' => 'open_signal_wait',
                    'signal_name' => 'finish',
                    'timeout_seconds' => 300,
                ]],
            ])
            ->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('reason', 'backend_lock_pressure')
            ->assertJsonPath('operation', 'complete_workflow_task')
            ->assertJsonPath('task_id', $leasedTask->id)
            ->assertJsonPath('workflow_task_attempt', 1)
            ->assertJsonPath('lease_owner', 'php-sqlite-completion-worker')
            ->assertJsonPath('outcome', 'deferred')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('retry_after_seconds', 1)
            ->assertJsonPath('backend.driver', 'sqlite')
            ->assertJsonPath('backend.lock_pressure', true)
            ->assertJsonMissing(['message' => 'Server Error']);

        $this->assertSame(TaskStatus::Leased, WorkflowTask::query()->findOrFail($leasedTask->id)->status);
        $this->assertSame(
            1,
            WorkflowSignal::query()
                ->where('workflow_run_id', $runId)
                ->where('signal_name', 'advance')
                ->count(),
        );
    }

    public function test_worker_liveness_heartbeat_retries_normal_sqlite_write_contention(): void
    {
        Queue::fake();

        [$runId] = $this->createLeasedInteractiveSignalTask(
            'wf-sqlite-worker-heartbeat-lock-pressure',
            'php-sqlite-liveness-worker',
            'sqlite-worker-heartbeat-signal',
        );

        /** @var WorkerRegistration $worker */
        $worker = WorkerRegistration::query()
            ->where('worker_id', 'php-sqlite-liveness-worker')
            ->firstOrFail();
        $worker->forceFill(['last_heartbeat_at' => now()->subMinute()])->save();
        $previousHeartbeat = $worker->last_heartbeat_at;

        $signalWriter = $this->startTransientControlPlaneUpdate($runId);

        try {
            $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/heartbeat', [
                    'worker_id' => 'php-sqlite-liveness-worker',
                    'task_slots' => [
                        'workflow_available' => 1,
                        'activity_available' => 1,
                    ],
                ])
                ->assertOk()
                ->assertJsonPath('worker_id', 'php-sqlite-liveness-worker')
                ->assertJsonPath('acknowledged', true)
                ->assertJsonPath('reason', null);
        } finally {
            $this->finishTransientLeaseUpdate($signalWriter);
        }

        $this->assertTrue(
            WorkerRegistration::query()
                ->where('worker_id', 'php-sqlite-liveness-worker')
                ->firstOrFail()
                ->last_heartbeat_at
                ->greaterThan($previousHeartbeat),
        );
    }

    public function test_exhausted_worker_liveness_heartbeat_is_non_fatal_and_retryable(): void
    {
        Queue::fake();

        [, $leasedTask] = $this->createLeasedInteractiveSignalTask(
            'wf-sqlite-worker-heartbeat-lock-response',
            'php-sqlite-liveness-worker',
            'sqlite-worker-heartbeat-lock-response-signal',
        );

        $this->holdSqliteWriteLock($leasedTask->id);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'php-sqlite-liveness-worker',
                'task_slots' => [
                    'workflow_available' => 1,
                    'activity_available' => 1,
                ],
            ])
            ->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('worker_id', 'php-sqlite-liveness-worker')
            ->assertJsonPath('acknowledged', false)
            ->assertJsonPath('reason', 'backend_lock_pressure')
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('retry_after_seconds', 1)
            ->assertJsonPath('backend.driver', 'sqlite')
            ->assertJsonPath('backend.lock_pressure', true)
            ->assertJsonMissing(['message' => 'Server Error']);
    }

    public function test_exhausted_control_plane_lock_pressure_returns_a_typed_retryable_response(): void
    {
        Queue::fake();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(
            InteractiveCommandWorkflow::class,
            'wf-sqlite-control-plane-lock-response',
        );
        $start = $workflow->start();

        NamespaceWorkflowScope::bind('default', $workflow->id(), InteractiveCommandWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $task);

        $this->holdSqliteWriteLock($task->id);

        $this->withHeaders($this->controlPlaneHeaders())
            ->postJson('/api/workflows/wf-sqlite-control-plane-lock-response/signal/advance', [
                'input' => ['Ada'],
                'request_id' => 'sqlite-signal-exhausted-lock',
            ])
            ->assertStatus(503)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('reason', 'backend_lock_pressure')
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('error_id', static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->assertJsonPath('backend.driver', 'sqlite')
            ->assertJsonPath('backend.lock_pressure', true)
            ->assertJsonPath('control_plane.operation', 'signal');

        $this->assertSame(
            0,
            WorkflowCommand::query()
                ->where('workflow_run_id', $start->runId())
                ->where('command_type', CommandType::Signal->value)
                ->count(),
        );
    }

    private function registerWorker(string $workerId, string $runtime): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => 'default',
            'task_queue' => 'polyglot-shared',
            'runtime' => $runtime,
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }

    /**
     * @return array{string, string, string, string}
     */
    private function leaseExternalActivity(string $workflowId, string $workerId): array
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(ExternalGreetingWorkflow::class, $workflowId);
        $start = $workflow->start('startup');

        NamespaceWorkflowScope::bind('default', $workflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Activity->value)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $task);

        $task->forceFill(['queue' => 'polyglot-shared'])->save();
        ActivityExecution::query()
            ->where('workflow_run_id', $start->runId())
            ->update(['queue' => 'polyglot-shared']);

        $this->registerWorker($workerId, 'php');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'polyglot-shared',
                'timeout_seconds' => 0,
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.task_id', $task->id)
            ->assertJsonPath('task.lease_owner', $workerId)
            ->assertJsonPath('poll_status', 'leased');

        return [
            $start->runId(),
            (string) $poll->json('task.task_id'),
            (string) $poll->json('task.activity_attempt_id'),
            (string) $poll->json('task.lease_owner'),
        ];
    }

    /**
     * @return array{string, WorkflowTask}
     */
    private function createLeasedInteractiveSignalTask(
        string $workflowId,
        string $workerId,
        string $requestId,
    ): array {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        $workflow = WorkflowStub::make(InteractiveCommandWorkflow::class, $workflowId);
        $start = $workflow->start();

        NamespaceWorkflowScope::bind('default', $workflow->id(), InteractiveCommandWorkflow::class);
        $this->runReadyWorkflowTask($start->runId());

        $this->withHeaders($this->controlPlaneHeaders())
            ->postJson("/api/workflows/{$workflowId}/signal/advance", [
                'input' => ['Ada'],
                'request_id' => $requestId,
            ])
            ->assertAccepted();

        /** @var WorkflowTask|null $leasedTask */
        $leasedTask = WorkflowTask::query()
            ->where('workflow_run_id', $start->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $leasedTask);

        $leasedTask->forceFill([
            'status' => TaskStatus::Leased->value,
            'lease_owner' => $workerId,
            'leased_at' => now(),
            'lease_expires_at' => now()->addSeconds(30),
            'attempt_count' => 1,
        ])->save();

        $this->registerWorker($workerId, 'php');

        return [$start->runId(), $leasedTask];
    }

    private function holdSqliteWriteLock(string $taskId): void
    {
        $this->assertIsString($this->databasePath);

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 1');
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec('UPDATE workflow_tasks SET updated_at = updated_at WHERE id = '.$pdo->quote($taskId));

        $this->lockConnection = $pdo;
    }

    /**
     * @return array{process: resource, pipes: array<int, resource>, marker: string}
     */
    private function startTransientLeaseUpdate(string $taskId): array
    {
        return $this->startTransientSqliteWrite(
            "UPDATE workflow_tasks SET lease_expires_at = datetime('now', '+30 seconds'), updated_at = updated_at WHERE id = ?",
            $taskId,
        );
    }

    /**
     * @return array{process: resource, pipes: array<int, resource>, marker: string}
     */
    private function startTransientControlPlaneUpdate(string $runId): array
    {
        return $this->startTransientSqliteWrite(
            'UPDATE workflow_runs SET last_command_sequence = last_command_sequence, updated_at = updated_at WHERE id = ?',
            $runId,
        );
    }

    /**
     * @return array{process: resource, pipes: array<int, resource>, marker: string}
     */
    private function startTransientSqliteWrite(string $sql, string $id): array
    {
        $this->assertIsString($this->databasePath);

        $marker = tempnam(sys_get_temp_dir(), 'dw-server-sqlite-lease-lock-');

        if ($marker === false) {
            $this->fail('Could not allocate a marker for the SQLite lease writer.');
        }

        @unlink($marker);

        $script = <<<'PHP'
$pdo = new PDO('sqlite:'.$argv[1]);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 1000');
$pdo->beginTransaction();

try {
    $statement = $pdo->prepare($argv[4]);
    $statement->execute([$argv[2]]);
    file_put_contents($argv[3], 'locked');
    usleep(125000);

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
}
PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, $this->databasePath, $id, $marker, $sql],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            $this->fail('Could not start the SQLite lease writer process.');
        }

        fclose($pipes[0]);

        $deadline = microtime(true) + 2;

        while (! is_file($marker) && microtime(true) < $deadline) {
            usleep(1000);
        }

        if (! is_file($marker)) {
            $error = stream_get_contents($pipes[2]);
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            @unlink($marker);

            $this->fail('SQLite lease writer did not acquire its lock: '.$error);
        }

        return [
            'process' => $process,
            'pipes' => $pipes,
            'marker' => $marker,
        ];
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>, marker: string}  $leaseWriter
     */
    private function finishTransientLeaseUpdate(array $leaseWriter): void
    {
        $output = stream_get_contents($leaseWriter['pipes'][1]);
        $error = stream_get_contents($leaseWriter['pipes'][2]);

        fclose($leaseWriter['pipes'][1]);
        fclose($leaseWriter['pipes'][2]);

        $exitCode = proc_close($leaseWriter['process']);

        @unlink($leaseWriter['marker']);

        $this->assertSame(0, $exitCode, trim($error."\n".$output));
    }

    private function controlPlaneHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }

    private function workerHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');

        $this->assertIsString($taskId);

        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
