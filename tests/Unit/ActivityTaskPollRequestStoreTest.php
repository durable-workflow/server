<?php

namespace Tests\Unit;

use App\Support\ActivityTaskPollRequestStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\ServerPollingCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTaskPollRequestStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
        ]);
    }

    public function test_duplicate_waits_remain_idempotent_when_empty_poll_wait_slots_are_exhausted(): void
    {
        config([
            'server.polling.max_concurrent_waits' => 1,
        ]);

        /** @var LongPollWaitSlotStore $waitSlots */
        $waitSlots = app(LongPollWaitSlotStore::class);
        $heldSlot = $waitSlots->tryAcquire(1);
        $this->assertNotNull($heldSlot);

        $task = [
            'task_id' => 'activity-task-slot-exhausted-response',
            'workflow_id' => 'workflow-slot-exhausted-response',
            'run_id' => 'run-slot-exhausted-response',
            'activity_attempt_id' => 'activity-attempt-slot-exhausted-response',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ];

        $store = new class(app(ServerPollingCache::class)) extends ActivityTaskPollRequestStore
        {
            public int $pauseCalls = 0;

            /** @var callable(int): void|null */
            public $afterPause = null;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;

                if (is_callable($this->afterPause)) {
                    ($this->afterPause)($this->pauseCalls);
                }
            }
        };

        try {
            $this->assertTrue($store->tryStart(
                'default',
                'external-activities',
                null,
                'worker-a',
                'poll-slot-exhausted',
            ));

            $store->afterPause = function (int $pauseCalls) use ($store, $task): void {
                if ($pauseCalls === 1) {
                    $store->rememberResult(
                        'default',
                        'external-activities',
                        null,
                        'worker-a',
                        'poll-slot-exhausted',
                        $task,
                        'leased',
                    );
                }
            };

            $result = $store->waitForResult(
                'default',
                'external-activities',
                null,
                'worker-a',
                'poll-slot-exhausted',
                100,
            );
        } finally {
            $heldSlot->release();
        }

        $this->assertSame(1, $store->pauseCalls);
        $this->assertSame([
            'resolved' => true,
            'task' => $task,
            'poll_status' => 'leased',
        ], $result);
    }
}
