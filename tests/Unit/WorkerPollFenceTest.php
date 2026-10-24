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

    public function test_persisted_process_identity_ignores_key_order_and_preserves_strict_fencing(): void
    {
        $worker = $this->createWorker('php-worker-json-fence');
        $worker->forceFill(['process_metrics' => [
            'host' => 'worker-host', 'process_started_at' => now()->toISOString(), 'process_id' => 123,
        ]])->save();
        $snapshot = WorkerPollFence::snapshot($worker);
        $snapshot['process_identity'] = array_reverse($snapshot['process_identity'], true);

        $this->assertTrue(WorkerPollFence::isCurrent($snapshot));
        $this->assertTrue(WorkerPollFence::isCurrentForUpdate($snapshot));
        foreach ([
            [...$snapshot['process_identity'], 'process_id' => 124],
            [...$snapshot['process_identity'], 'process_id' => '123'],
            [...$snapshot['process_identity'], 'extra' => 'unissued'],
            array_diff_key($snapshot['process_identity'], ['host' => true]),
        ] as $changedIdentity) {
            $this->assertFalse(WorkerPollFence::isCurrent([...$snapshot, 'process_identity' => $changedIdentity]));
        }
    }

    public function test_worker_fence_rejects_a_reregistration_that_reports_the_same_process(): void
    {
        $worker = $this->createWorker('php-worker-same-process-fence');
        $worker->forceFill([
            'registration_token' => bin2hex(random_bytes(16)),
            'process_metrics' => ['host' => 'worker-host', 'process_id' => 123, 'process_started_at' => '2026-10-24T00:00:00Z'],
        ])->save();
        $snapshot = WorkerPollFence::snapshot($worker->fresh());

        $worker->forceFill(['last_heartbeat_at' => now()])->save();
        $this->assertTrue(WorkerPollFence::isCurrent($snapshot), 'a heartbeat keeps the registration');

        $worker->forceFill(['registration_token' => bin2hex(random_bytes(16))])->save();
        $this->assertFalse(WorkerPollFence::isCurrent($snapshot));
        $this->assertFalse(WorkerPollFence::isCurrentForUpdate($snapshot));
    }

    public function test_worker_fence_rejects_a_reregistration_in_the_same_second_without_process_metadata(): void
    {
        $this->travelTo(now()->startOfSecond());
        $worker = $this->createWorker('php-worker-same-second-fence');
        $worker->forceFill(['registration_token' => bin2hex(random_bytes(16))])->save();
        $snapshot = WorkerPollFence::snapshot($worker->fresh());

        $worker->forceFill(['registration_token' => bin2hex(random_bytes(16))])->save();
        $replacement = $worker->fresh();

        $this->assertSame($snapshot['updated_at'], WorkerPollFence::snapshot($replacement)['updated_at']);
        $this->assertSame($snapshot['last_heartbeat_at'], WorkerPollFence::snapshot($replacement)['last_heartbeat_at']);
        $this->assertFalse(WorkerPollFence::isCurrent($snapshot));
        $this->assertFalse(WorkerPollFence::isCurrentForUpdate($snapshot));
    }

    public function test_worker_fence_snapshot_never_carries_the_registration_token(): void
    {
        $token = bin2hex(random_bytes(16));
        $worker = $this->createWorker('php-worker-token-digest-fence');
        $worker->forceFill(['registration_token' => $token])->save();
        $snapshot = WorkerPollFence::snapshot($worker->fresh());

        $this->assertSame(hash('sha256', $token), $snapshot['registration_incarnation']);
        $this->assertStringNotContainsString($token, json_encode($snapshot, JSON_THROW_ON_ERROR));
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
