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
}
