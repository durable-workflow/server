<?php

namespace Tests\Unit;

use App\Support\LongPollWaitSlotStore;
use App\Support\PollRequestTaskKindsConflict;
use App\Support\ServerPollingCache;
use App\Support\WorkflowTaskPollRequestStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTaskPollRequestStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
        ]);
    }

    public function test_it_returns_cached_results_scoped_by_worker_queue_build_and_poll_request(): void
    {
        $store = app(WorkflowTaskPollRequestStore::class);
        $task = [
            'task_id' => 'task-cached-response',
            'workflow_id' => 'workflow-cached-response',
            'run_id' => 'run-cached-response',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ];

        $this->assertTrue($store->tryStart('default', 'external-workflows', 'build-a', 'worker-a', 'poll-1'));

        $store->rememberResult(
            'default',
            'external-workflows',
            'build-a',
            'worker-a',
            'poll-1',
            $task,
            'leased',
        );

        $this->assertSame([
            'resolved' => true,
            'task' => $task,
            'poll_status' => 'leased',
        ], $store->result('default', 'external-workflows', 'build-a', 'worker-a', 'poll-1'));

        $this->assertSame([
            'resolved' => false,
            'task' => null,
            'poll_status' => null,
        ], $store->result('default', 'external-workflows', 'build-b', 'worker-a', 'poll-1'));
    }

    public function test_it_waits_for_an_in_flight_request_to_publish_a_result(): void
    {
        $task = [
            'task_id' => 'task-waited-response',
            'workflow_id' => 'workflow-waited-response',
            'run_id' => 'run-waited-response',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ];

        $store = new class(app(ServerPollingCache::class)) extends WorkflowTaskPollRequestStore
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

        $this->assertTrue($store->tryStart('default', 'external-workflows', null, 'worker-a', 'poll-2'));

        $store->afterPause = function (int $pauseCalls) use ($store, $task): void {
            if ($pauseCalls === 1) {
                $store->rememberResult('default', 'external-workflows', null, 'worker-a', 'poll-2', $task, 'leased');
            }
        };

        $result = $store->waitForResult('default', 'external-workflows', null, 'worker-a', 'poll-2', 100);

        $this->assertSame(1, $store->pauseCalls);
        $this->assertSame([
            'resolved' => true,
            'task' => $task,
            'poll_status' => 'leased',
        ], $result);
    }

    public function test_it_binds_reordered_task_kind_sets_and_rejects_different_sets(): void
    {
        $store = app(WorkflowTaskPollRequestStore::class);

        $this->assertSame(
            ['update_validation', 'workflow'],
            $store->bindTaskKinds(
                'default',
                'external-workflows',
                null,
                'worker-a',
                'poll-task-kinds',
                ['workflow', 'update_validation'],
            ),
        );
        $this->assertSame(
            ['update_validation', 'workflow'],
            $store->bindTaskKinds(
                'default',
                'external-workflows',
                null,
                'worker-a',
                'poll-task-kinds',
                ['update_validation', 'workflow'],
            ),
        );

        try {
            $store->bindTaskKinds(
                'default',
                'external-workflows',
                null,
                'worker-a',
                'poll-task-kinds',
                ['workflow'],
            );
            $this->fail('Expected a task-kind binding conflict.');
        } catch (PollRequestTaskKindsConflict $exception) {
            $this->assertSame('poll-task-kinds', $exception->pollRequestId);
            $this->assertSame(['workflow'], $exception->requestedTaskKinds);
            $this->assertSame(['update_validation', 'workflow'], $exception->boundTaskKinds);
        }
    }

    public function test_pending_task_kind_bindings_expire_after_the_poll_window(): void
    {
        config(['server.polling.timeout' => 1]);

        $store = app(WorkflowTaskPollRequestStore::class);
        $store->bindTaskKinds(
            'default',
            'external-workflows',
            null,
            'worker-a',
            'poll-task-kinds-ttl',
            ['workflow'],
        );

        $this->travel(7)->seconds();

        $this->assertSame(
            ['update_validation'],
            $store->bindTaskKinds(
                'default',
                'external-workflows',
                null,
                'worker-a',
                'poll-task-kinds-ttl',
                ['update_validation'],
            ),
        );
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
            'task_id' => 'task-slot-exhausted-response',
            'workflow_id' => 'workflow-slot-exhausted-response',
            'run_id' => 'run-slot-exhausted-response',
            'lease_expires_at' => now()->addMinutes(5)->toJSON(),
        ];

        $store = new class(app(ServerPollingCache::class)) extends WorkflowTaskPollRequestStore
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
                'external-workflows',
                null,
                'worker-a',
                'poll-slot-exhausted',
            ));

            $store->afterPause = function (int $pauseCalls) use ($store, $task): void {
                if ($pauseCalls === 1) {
                    $store->rememberResult(
                        'default',
                        'external-workflows',
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
                'external-workflows',
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

    public function test_it_allows_a_new_leader_after_the_pending_marker_is_cleared(): void
    {
        $store = app(WorkflowTaskPollRequestStore::class);

        $this->assertTrue($store->tryStart('default', 'external-workflows', null, 'worker-a', 'poll-3'));

        $store->forgetPending('default', 'external-workflows', null, 'worker-a', 'poll-3');

        $this->assertTrue($store->tryStart('default', 'external-workflows', null, 'worker-a', 'poll-3'));
    }

    public function test_pending_markers_expire_after_the_poll_window(): void
    {
        config(['server.polling.timeout' => 1]);

        $store = app(WorkflowTaskPollRequestStore::class);

        $this->assertTrue($store->tryStart('default', 'external-workflows', null, 'worker-a', 'poll-ttl'));
        $this->assertFalse($store->tryStart('default', 'external-workflows', null, 'worker-a', 'poll-ttl'));

        $this->travel(7)->seconds();

        $this->assertTrue($store->tryStart('default', 'external-workflows', null, 'worker-a', 'poll-ttl'));
    }

    public function test_empty_result_cache_entries_expire_after_the_poll_window(): void
    {
        config(['server.polling.timeout' => 1]);

        $store = app(WorkflowTaskPollRequestStore::class);

        $this->assertTrue($store->tryStart('default', 'external-workflows', null, 'worker-a', 'poll-empty-result'));

        $store->rememberResult('default', 'external-workflows', null, 'worker-a', 'poll-empty-result', null, 'empty');

        $this->assertSame([
            'resolved' => true,
            'task' => null,
            'poll_status' => 'empty',
        ], $store->result('default', 'external-workflows', null, 'worker-a', 'poll-empty-result'));

        $this->travel(7)->seconds();

        $this->assertSame(
            ['update_validation'],
            $store->bindTaskKinds(
                'default',
                'external-workflows',
                null,
                'worker-a',
                'poll-empty-result',
                ['update_validation'],
            ),
        );

        $this->assertSame([
            'resolved' => false,
            'task' => null,
            'poll_status' => null,
        ], $store->result(
            'default',
            'external-workflows',
            null,
            'worker-a',
            'poll-empty-result',
            ['update_validation'],
        ));
    }
}
