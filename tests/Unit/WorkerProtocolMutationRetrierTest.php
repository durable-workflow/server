<?php

namespace Tests\Unit;

use App\Support\WorkerProtocolMutationRetrier;
use RuntimeException;
use Tests\TestCase;

class WorkerProtocolMutationRetrierTest extends TestCase
{
    public function test_it_retries_worker_protocol_contention_across_supported_backends(): void
    {
        config(['workflows.storage.transaction_attempts' => 3]);
        $attempts = 0;

        $result = app(WorkerProtocolMutationRetrier::class)->run(function () use (&$attempts): string {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('Lock wait timeout exceeded; try restarting transaction');
            }

            return 'acknowledged';
        });

        $this->assertSame('acknowledged', $result);
        $this->assertSame(2, $attempts);
    }
}
