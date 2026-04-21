<?php

namespace Tests\Unit;

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
        );

        $this->assertSame([
            'resolved' => true,
            'task' => $task,
        ], $store->result('default', 'external-workflows', 'build-a', 'worker-a', 'poll-1'));

        $this->assertSame([
            'resolved' => false,
            'task' => null,
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
                $store->rememberResult('default', 'external-workflows', null, 'worker-a', 'poll-2', $task);
            }
        };

        $result = $store->waitForResult('default', 'external-workflows', null, 'worker-a', 'poll-2', 100);

        $this->assertSame(1, $store->pauseCalls);
        $this->assertSame([
            'resolved' => true,
            'task' => $task,
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

        $store->rememberResult('default', 'external-workflows', null, 'worker-a', 'poll-empty-result', null);

        $this->assertSame([
            'resolved' => true,
            'task' => null,
        ], $store->result('default', 'external-workflows', null, 'worker-a', 'poll-empty-result'));

        $this->travel(7)->seconds();

        $this->assertSame([
            'resolved' => false,
            'task' => null,
        ], $store->result('default', 'external-workflows', null, 'worker-a', 'poll-empty-result'));
    }
}
