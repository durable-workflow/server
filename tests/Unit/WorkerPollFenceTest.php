<?php

namespace Tests\Unit;

use App\Models\WorkerRegistration;
use App\Support\WorkerPollFence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerPollFenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_fence_rejects_unchanged_registration_after_heartbeat_stale_window(): void
    {
        config(['server.workers.stale_after_seconds' => 3]);

        $registeredAt = now()->startOfSecond();
        $this->travelTo($registeredAt);

        $worker = $this->createWorker('php-worker-stale-fence');
        $snapshot = WorkerPollFence::snapshot($worker);

        $this->assertTrue(WorkerPollFence::isFresh($worker));
        $this->assertTrue(WorkerPollFence::isCurrent($snapshot));

        $this->travelTo($registeredAt->copy()->addSeconds(4));

        $staleWorker = $worker->fresh();

        $this->assertInstanceOf(WorkerRegistration::class, $staleWorker);
        $this->assertFalse(WorkerPollFence::isFresh($staleWorker));
        $this->assertFalse(WorkerPollFence::isCurrent($snapshot));
    }

    public function test_worker_fence_allows_worker_after_new_heartbeat_snapshot(): void
    {
        config(['server.workers.stale_after_seconds' => 3]);

        $registeredAt = now()->startOfSecond();
        $this->travelTo($registeredAt);

        $worker = $this->createWorker('php-worker-refreshed-fence');

        $this->travelTo($registeredAt->copy()->addSeconds(4));
        $staleWorker = $worker->fresh();

        $this->assertInstanceOf(WorkerRegistration::class, $staleWorker);
        $this->assertFalse(WorkerPollFence::isFresh($staleWorker));

        $worker->forceFill(['last_heartbeat_at' => now()])->save();
        $worker = $worker->fresh();

        $this->assertInstanceOf(WorkerRegistration::class, $worker);
        $this->assertTrue(WorkerPollFence::isFresh($worker));
        $this->assertTrue(WorkerPollFence::isCurrent(WorkerPollFence::snapshot($worker)));
    }

    public function test_worker_fence_rejects_superseded_registration_even_with_fresh_heartbeat(): void
    {
        $worker = $this->createWorker('php-worker-superseded-fence');
        $snapshot = WorkerPollFence::snapshot($worker);

        $worker->forceFill([
            'status' => WorkerRegistration::STATUS_SUPERSEDED,
            'last_heartbeat_at' => now(),
        ])->save();

        $worker = $worker->fresh();

        $this->assertInstanceOf(WorkerRegistration::class, $worker);
        $this->assertFalse(WorkerPollFence::isFresh($worker));
        $this->assertFalse(WorkerPollFence::isCurrent($snapshot));
    }

    private function createWorker(string $workerId): WorkerRegistration
    {
        return WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => 'default',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }
}
