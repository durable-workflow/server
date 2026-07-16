<?php

namespace Tests\Unit;

use App\Support\ControlPlaneMutationRetrier;
use RuntimeException;
use Tests\TestCase;

class ControlPlaneMutationRetrierTest extends TestCase
{
    public function test_it_retries_transient_contention_on_non_sqlite_control_plane_storage(): void
    {
        config(['workflows.storage.transaction_attempts' => 3]);
        $attempts = 0;

        $result = app(ControlPlaneMutationRetrier::class)->run(
            function () use (&$attempts): string {
                $attempts++;

                if ($attempts === 1) {
                    throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
                }

                return 'accepted';
            },
            allBackends: true,
        );

        $this->assertSame('accepted', $result);
        $this->assertSame(2, $attempts);
    }
}
