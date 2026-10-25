<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkerSessionLease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerSessionHolderMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrading_keeps_active_sessions_with_the_registration_that_holds_them(): void
    {
        $migration = require base_path('database/migrations/2026_10_24_000100_add_worker_session_holder_incarnation.php');
        $migration->down();

        $token = bin2hex(random_bytes(16));
        $this->seedWorker('holder', $token);
        $this->seedWorker('tokenless', null);
        $this->seedSession('held', 'holder', WorkerSessionLease::STATUS_ACTIVE);
        $this->seedSession('closed', 'holder', WorkerSessionLease::STATUS_CLOSED);
        $this->seedSession('tokenless-held', 'tokenless', WorkerSessionLease::STATUS_ACTIVE);
        $this->seedSession('unregistered', 'gone', WorkerSessionLease::STATUS_ACTIVE);

        $migration->up();

        $holders = WorkerSessionLease::query()->pluck('lease_owner_incarnation', 'session_id')->all();
        $this->assertSame([
            'held' => hash('sha256', $token),
            'closed' => null,
            'tokenless-held' => null,
            'unregistered' => null,
        ], $holders);
    }

    private function seedWorker(string $workerId, ?string $token): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => 'default',
            'task_queue' => 'gpu-activities',
            'runtime' => 'php',
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ])->forceFill(['registration_token' => $token])->save();
    }

    private function seedSession(string $sessionId, string $owner, string $status): void
    {
        WorkerSessionLease::query()->create([
            'namespace' => 'default',
            'session_id' => $sessionId,
            'status' => $status,
            'lease_owner' => $owner,
            'lease_expires_at' => now()->addMinutes(2),
            'ttl_expires_at' => now()->addMinutes(30),
            'lease_seconds' => 120,
            'ttl_seconds' => 1800,
            'max_concurrent_activities' => 1,
            'create_if_missing' => true,
            'allow_reacquire_after_failure' => true,
            'last_heartbeat_at' => now(),
        ]);
    }
}
