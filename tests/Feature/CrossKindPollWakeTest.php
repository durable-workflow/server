<?php

namespace Tests\Feature;

use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class CrossKindPollWakeTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    /** @return iterable<string, array{string}> */
    public static function pollPaths(): iterable
    {
        yield 'workflow' => ['/api/worker/workflow-tasks/poll'];
        yield 'activity' => ['/api/worker/activity-tasks/poll'];
        yield 'query' => ['/api/worker/query-tasks/poll'];
    }

    #[DataProvider('pollPaths')]
    public function test_capable_worker_returns_a_non_leasing_interrupt_for_each_poll_kind(string $path): void
    {
        config(['cache.default' => 'array']);
        $this->createNamespace('default');
        $this->registerWorker(
            workerId: 'mixed-worker',
            taskQueue: 'mixed-queue',
            capabilities: ['query_tasks', WorkerProtocol::CROSS_KIND_POLL_WAKE_CAPABILITY],
        );

        $signals = app(LongPollSignalStore::class);
        $channel = $signals->workerTaskQueueChannel('default', 'mixed-queue');
        $poller = new class($signals, app(LongPollWaitSlotStore::class)) extends LongPoller
        {
            public int $pauseCalls = 0;

            public \Closure $onPause;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;
                ($this->onPause)();
            }
        };
        $poller->onPause = static fn () => $signals->signal($channel);
        $this->app->instance(LongPoller::class, $poller);

        $this->postJson($path, [
            'worker_id' => 'mixed-worker',
            'task_queue' => 'mixed-queue',
            'poll_request_id' => 'cross-kind-'.basename(dirname($path)),
            'timeout_seconds' => 5,
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'task_queue_changed');

        $this->assertSame(1, $poller->pauseCalls);
    }

    public function test_existing_worker_keeps_its_original_poll_behavior(): void
    {
        config(['cache.default' => 'array']);
        $this->createNamespace('default');
        $this->registerWorker(
            workerId: 'existing-worker',
            taskQueue: 'mixed-queue',
            capabilities: ['query_tasks'],
        );

        $signals = app(LongPollSignalStore::class);
        $channel = $signals->workerTaskQueueChannel('default', 'mixed-queue');
        $poller = new class($signals, app(LongPollWaitSlotStore::class)) extends LongPoller
        {
            public int $pauseCalls = 0;

            public \Closure $onPause;

            protected function pause(int $milliseconds): void
            {
                $this->pauseCalls++;
                ($this->onPause)($this->pauseCalls);
                usleep($milliseconds * 1000);
            }
        };
        $poller->onPause = static function (int $calls) use ($signals, $channel): void {
            if ($calls === 1) {
                $signals->signal($channel);
            }
        };
        $this->app->instance(LongPoller::class, $poller);

        $started = microtime(true);
        $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'existing-worker',
            'task_queue' => 'mixed-queue',
            'poll_request_id' => 'existing-worker-poll',
            'timeout_seconds' => 1,
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        $this->assertGreaterThan(1, $poller->pauseCalls);
    }
}
