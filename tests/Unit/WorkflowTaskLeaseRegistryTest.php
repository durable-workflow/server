<?php

namespace Tests\Unit;

use App\Support\WorkflowTaskLeaseRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
}
