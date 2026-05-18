<?php

namespace Tests\Unit;

use App\Support\LongPollWaitSlotStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LongPollWaitSlotStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'server.polling.max_concurrent_waits' => 2,
            'server.query_tasks.max_concurrent_poll_waits' => null,
        ]);
    }

    public function test_it_caps_and_releases_wait_slots(): void
    {
        /** @var LongPollWaitSlotStore $slots */
        $slots = app(LongPollWaitSlotStore::class);

        $first = $slots->tryAcquire(30);
        $second = $slots->tryAcquire(30);
        $third = $slots->tryAcquire(30);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNull($third);

        $first->release();

        $replacement = $slots->tryAcquire(30);

        $this->assertNotNull($replacement);

        $second->release();
        $replacement->release();
    }

    public function test_it_derives_capacity_from_php_cli_server_workers(): void
    {
        $previous = getenv('PHP_CLI_SERVER_WORKERS');
        putenv('PHP_CLI_SERVER_WORKERS=4');

        config([
            'server.polling.max_concurrent_waits' => null,
            'server.polling.reserved_http_workers' => 2,
        ]);

        try {
            /** @var LongPollWaitSlotStore $slots */
            $slots = app(LongPollWaitSlotStore::class);

            $this->assertSame(1, $slots->maxConcurrentWaits());
            $this->assertSame(1, $slots->maxConcurrentQueryTaskPollWaits());
        } finally {
            if ($previous === false) {
                putenv('PHP_CLI_SERVER_WORKERS');
            } else {
                putenv('PHP_CLI_SERVER_WORKERS='.$previous);
            }
        }
    }

    public function test_query_task_poll_slots_are_separate_from_worker_slots(): void
    {
        config([
            'server.polling.max_concurrent_waits' => 1,
            'server.query_tasks.max_concurrent_poll_waits' => 1,
        ]);

        /** @var LongPollWaitSlotStore $slots */
        $slots = app(LongPollWaitSlotStore::class);

        $worker = $slots->tryAcquire(30);
        $query = $slots->tryAcquireQueryTaskPoll(30);
        $extraWorker = $slots->tryAcquire(30);
        $extraQuery = $slots->tryAcquireQueryTaskPoll(30);

        $this->assertNotNull($worker);
        $this->assertNotNull($query);
        $this->assertNull($extraWorker);
        $this->assertNull($extraQuery);

        $worker->release();
        $query->release();

        $newWorker = $slots->tryAcquire(30);
        $newQuery = $slots->tryAcquireQueryTaskPoll(30);

        $this->assertNotNull($newWorker);
        $this->assertNotNull($newQuery);

        $newWorker->release();
        $newQuery->release();
    }

    public function test_explicit_query_task_poll_capacity_reduces_derived_worker_slots(): void
    {
        $previous = getenv('PHP_CLI_SERVER_WORKERS');
        putenv('PHP_CLI_SERVER_WORKERS=8');

        config([
            'server.polling.max_concurrent_waits' => null,
            'server.polling.reserved_http_workers' => 2,
            'server.query_tasks.max_concurrent_poll_waits' => 3,
        ]);

        try {
            /** @var LongPollWaitSlotStore $slots */
            $slots = app(LongPollWaitSlotStore::class);

            $this->assertSame(3, $slots->maxConcurrentWaits());
            $this->assertSame(3, $slots->maxConcurrentQueryTaskPollWaits());
        } finally {
            if ($previous === false) {
                putenv('PHP_CLI_SERVER_WORKERS');
            } else {
                putenv('PHP_CLI_SERVER_WORKERS='.$previous);
            }
        }
    }
}
