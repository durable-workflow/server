<?php

namespace Tests\Unit;

use App\Models\WorkflowTaskProtocolLease;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkflowTaskLeaseRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class WorkflowTaskLeaseRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_the_same_attempt_when_the_same_worker_reclaims_an_active_lease_with_a_new_expiry(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $first = $registry->recordClaim('default', [
            'task_id' => 'task-stable-attempt',
            'workflow_instance_id' => 'workflow-stable-attempt',
            'workflow_run_id' => 'run-stable-attempt',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ]);

        $second = $registry->recordClaim('default', [
            'task_id' => 'task-stable-attempt',
            'workflow_instance_id' => 'workflow-stable-attempt',
            'workflow_run_id' => 'run-stable-attempt',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(10)->toJSON(),
        ]);

        $this->assertSame(1, $first->workflow_task_attempt);
        $this->assertSame(1, $second->workflow_task_attempt);
        $this->assertSame('worker-a', $second->lease_owner);
        $this->assertTrue(
            $second->lease_expires_at !== null
                && $second->lease_expires_at->greaterThan($first->lease_expires_at),
        );
    }

    public function test_it_increments_the_attempt_after_the_active_lease_is_cleared(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $first = $registry->recordClaim('default', [
            'task_id' => 'task-cleared-attempt',
            'workflow_instance_id' => 'workflow-cleared-attempt',
            'workflow_run_id' => 'run-cleared-attempt',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ]);

        $registry->clearActiveLease('task-cleared-attempt');

        $second = $registry->recordClaim('default', [
            'task_id' => 'task-cleared-attempt',
            'workflow_instance_id' => 'workflow-cleared-attempt',
            'workflow_run_id' => 'run-cleared-attempt',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(10)->toJSON(),
        ]);

        $this->assertSame(1, $first->workflow_task_attempt);
        $this->assertSame(2, $second->workflow_task_attempt);
    }

    public function test_it_uses_package_attempt_count_when_provided(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $lease = $registry->recordClaim('default', [
            'task_id' => 'task-package-attempt',
            'workflow_instance_id' => 'workflow-package-attempt',
            'workflow_run_id' => 'run-package-attempt',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ], packageAttemptCount: 3);

        $this->assertSame(3, $lease->workflow_task_attempt);
    }

    public function test_it_falls_back_to_independent_tracking_when_package_attempt_count_is_null(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $lease = $registry->recordClaim('default', [
            'task_id' => 'task-fallback-attempt',
            'workflow_instance_id' => 'workflow-fallback-attempt',
            'workflow_run_id' => 'run-fallback-attempt',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ], packageAttemptCount: null);

        $this->assertSame(1, $lease->workflow_task_attempt);
    }

    public function test_it_ignores_zero_package_attempt_count_and_falls_back(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $lease = $registry->recordClaim('default', [
            'task_id' => 'task-zero-attempt',
            'workflow_instance_id' => 'workflow-zero-attempt',
            'workflow_run_id' => 'run-zero-attempt',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ], packageAttemptCount: 0);

        // Zero is treated as not-provided; falls back to independent tracking
        $this->assertSame(1, $lease->workflow_task_attempt);
    }

    public function test_package_attempt_count_overrides_stale_mirror_value(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        // First claim sets mirror to attempt 1
        $registry->recordClaim('default', [
            'task_id' => 'task-stale-mirror',
            'workflow_instance_id' => 'workflow-stale-mirror',
            'workflow_run_id' => 'run-stale-mirror',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ]);

        $registry->clearActiveLease('task-stale-mirror');

        // Package says attempt 5 (e.g., task was retried multiple times in
        // the package while the mirror table was stale)
        $second = $registry->recordClaim('default', [
            'task_id' => 'task-stale-mirror',
            'workflow_instance_id' => 'workflow-stale-mirror',
            'workflow_run_id' => 'run-stale-mirror',
            'lease_owner' => 'worker-b',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ], packageAttemptCount: 5);

        $this->assertSame(5, $second->workflow_task_attempt);
    }

    public function test_sync_task_state_does_not_write_lease_fields_during_active_lease(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $lease = $registry->recordClaim('default', [
            'task_id' => 'task-sync-no-write',
            'workflow_instance_id' => 'workflow-sync-no-write',
            'workflow_run_id' => 'run-sync-no-write',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ]);

        $this->assertSame('worker-a', $lease->lease_owner);

        $task = new WorkflowTask([
            'id' => 'task-sync-no-write',
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'lease_owner' => 'worker-b',
            'lease_expires_at' => now()->addMinutes(10),
        ]);
        $task->exists = true;

        $registry->syncTaskState($task);

        $refreshed = WorkflowTaskProtocolLease::query()->find('task-sync-no-write');
        $this->assertInstanceOf(WorkflowTaskProtocolLease::class, $refreshed);
        // The mirror should retain the original claim values, not sync from
        // the package task, because per-update lease sync has been removed.
        $this->assertSame('worker-a', $refreshed->lease_owner);
    }

    public function test_sync_task_state_clears_mirror_when_task_leaves_leased_status(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $registry->recordClaim('default', [
            'task_id' => 'task-sync-clear',
            'workflow_instance_id' => 'workflow-sync-clear',
            'workflow_run_id' => 'run-sync-clear',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ], 'poll-123');

        $task = new WorkflowTask([
            'id' => 'task-sync-clear',
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
        ]);
        $task->exists = true;

        $registry->syncTaskState($task);

        $refreshed = WorkflowTaskProtocolLease::query()->find('task-sync-clear');
        $this->assertInstanceOf(WorkflowTaskProtocolLease::class, $refreshed);
        $this->assertNull($refreshed->lease_owner);
        $this->assertNull($refreshed->lease_expires_at);
        $this->assertNull($refreshed->last_poll_request_id);
    }

    public function test_active_lease_for_poll_request_returns_null_when_package_task_is_no_longer_leased(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => 'workflow-stale-poll',
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'test.workflow',
            'status' => 'pending',
        ]);

        NamespaceWorkflowScope::bind('default', 'workflow-stale-poll');

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'queue' => 'test-queue',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5),
            'attempt_count' => 1,
        ]);

        $registry->recordClaim('default', [
            'task_id' => $task->id,
            'workflow_instance_id' => 'workflow-stale-poll',
            'workflow_run_id' => $run->id,
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ], 'poll-stale-check');

        // Verify the lease is found when the package task is still leased.
        $found = $registry->activeLeaseForPollRequest(
            'default', 'test-queue', null, 'worker-a', 'poll-stale-check',
        );
        $this->assertInstanceOf(WorkflowTaskProtocolLease::class, $found);

        // Simulate the package task completing (no longer leased).
        $task->forceFill([
            'status' => TaskStatus::Ready->value,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ])->save();

        // The mirror still has stale lease data, but the query should verify
        // against the package's task and return null.
        $stale = $registry->activeLeaseForPollRequest(
            'default', 'test-queue', null, 'worker-a', 'poll-stale-check',
        );
        $this->assertNull($stale);
    }

    public function test_ownership_lease_resolves_workflow_instance_id_from_package_over_stale_mirror(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => 'workflow-package-resolve',
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'test.workflow',
            'status' => 'pending',
        ]);

        NamespaceWorkflowScope::bind('default', 'workflow-package-resolve');

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'queue' => 'test-queue',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5),
            'attempt_count' => 1,
        ]);

        // Record a claim with a deliberately wrong workflow_instance_id in the
        // mirror to simulate a stale cached value.
        $registry->recordClaim('default', [
            'task_id' => $task->id,
            'workflow_instance_id' => 'stale-cached-instance-id',
            'workflow_run_id' => $run->id,
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ], packageAttemptCount: 1);

        // ownershipLease should resolve workflow_instance_id from the package's
        // tables (via workflow_tasks → workflow_runs join) rather than the
        // mirror's stale cached value.
        $lease = $registry->ownershipLease(
            namespace: 'default',
            taskId: $task->id,
            expectedLeaseOwner: 'worker-a',
            workflowTaskAttempt: 1,
        );

        $this->assertInstanceOf(WorkflowTaskProtocolLease::class, $lease);
        $this->assertSame('workflow-package-resolve', $lease->workflow_instance_id);
    }

    public function test_ownership_lease_uses_package_attempt_count_when_mirror_is_stale(): void
    {
        $registry = app(WorkflowTaskLeaseRegistry::class);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => 'workflow-attempt-reconcile',
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\TestWorkflow',
            'workflow_type' => 'test.workflow',
            'status' => 'pending',
        ]);

        NamespaceWorkflowScope::bind('default', 'workflow-attempt-reconcile');

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'queue' => 'test-queue',
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5),
            'attempt_count' => 4,
        ]);

        // Record a claim with mirror attempt 1 (stale)
        $registry->recordClaim('default', [
            'task_id' => $task->id,
            'workflow_instance_id' => 'workflow-attempt-reconcile',
            'workflow_run_id' => $run->id,
            'lease_owner' => 'worker-a',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ], packageAttemptCount: 1);

        // ownershipLease should pick up the package's attempt_count (4)
        // during reconciliation, not the mirror's stale value (1).
        $lease = $registry->ownershipLease(
            namespace: 'default',
            taskId: $task->id,
            expectedLeaseOwner: 'worker-a',
            workflowTaskAttempt: 4,
        );

        $this->assertInstanceOf(WorkflowTaskProtocolLease::class, $lease);
        $this->assertSame(4, $lease->workflow_task_attempt);
    }
}
