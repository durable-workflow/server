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
            'server.polling.reserved_http_workers' => null,
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

    public function test_default_query_task_poll_capacity_keeps_api_capacity_with_configured_http_reserve(): void
    {
        $previous = getenv('PHP_CLI_SERVER_WORKERS');
        putenv('PHP_CLI_SERVER_WORKERS=8');

        config([
            'server.polling.max_concurrent_waits' => null,
            'server.polling.reserved_http_workers' => 2,
            'server.query_tasks.max_concurrent_poll_waits' => null,
        ]);

        try {
            /** @var LongPollWaitSlotStore $slots */
            $slots = app(LongPollWaitSlotStore::class);

            $this->assertSame(2, $slots->maxConcurrentWaits());
            $this->assertSame(1, $slots->maxConcurrentQueryTaskPollWaits());

            $firstQuery = $slots->tryAcquireQueryTaskPoll(30);
            $secondQuery = $slots->tryAcquireQueryTaskPoll(30);

            $this->assertNotNull($firstQuery);
            $this->assertNull($secondQuery);

            $firstQuery->release();
        } finally {
            if ($previous === false) {
                putenv('PHP_CLI_SERVER_WORKERS');
            } else {
                putenv('PHP_CLI_SERVER_WORKERS='.$previous);
            }
        }
    }

    public function test_unset_http_reserve_derives_from_php_cli_server_workers(): void
    {
        $previous = getenv('PHP_CLI_SERVER_WORKERS');
        putenv('PHP_CLI_SERVER_WORKERS=8');

        config([
            'server.polling.max_concurrent_waits' => null,
            'server.polling.reserved_http_workers' => null,
            'server.query_tasks.max_concurrent_poll_waits' => null,
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

            $this->assertSame(1, $slots->maxConcurrentWaits());
            $this->assertSame(3, $slots->maxConcurrentQueryTaskPollWaits());
        } finally {
            if ($previous === false) {
                putenv('PHP_CLI_SERVER_WORKERS');
            } else {
                putenv('PHP_CLI_SERVER_WORKERS='.$previous);
            }
        }
    }

    public function test_default_worker_poll_capacity_reserves_api_workers_under_large_cli_server_pools(): void
    {
        $previous = getenv('PHP_CLI_SERVER_WORKERS');
        putenv('PHP_CLI_SERVER_WORKERS=32');

        config([
            'server.polling.max_concurrent_waits' => null,
            'server.polling.reserved_http_workers' => null,
            'server.query_tasks.max_concurrent_poll_waits' => null,
        ]);

        try {
            /** @var LongPollWaitSlotStore $slots */
            $slots = app(LongPollWaitSlotStore::class);

            $this->assertSame(2, $slots->maxConcurrentWaits());
            $this->assertSame(1, $slots->maxConcurrentQueryTaskPollWaits());
        } finally {
            if ($previous === false) {
                putenv('PHP_CLI_SERVER_WORKERS');
            } else {
                putenv('PHP_CLI_SERVER_WORKERS='.$previous);
            }
        }
    }

    public function test_published_image_default_preserves_api_workers_for_load_profiles(): void
    {
        $previous = getenv('PHP_CLI_SERVER_WORKERS');
        $workerCount = $this->dockerfilePhpCliServerWorkers();
        putenv('PHP_CLI_SERVER_WORKERS='.$workerCount);

        config([
            'server.polling.max_concurrent_waits' => null,
            'server.polling.reserved_http_workers' => null,
            'server.query_tasks.max_concurrent_poll_waits' => null,
        ]);

        try {
            /** @var LongPollWaitSlotStore $slots */
            $slots = app(LongPollWaitSlotStore::class);

            $this->assertGreaterThanOrEqual(
                24,
                $workerCount,
                'The published request pool must keep liveness capacity during the mixed-load profile.',
            );
            $this->assertSame(2, $slots->maxConcurrentWaits());
            $this->assertSame(1, $slots->maxConcurrentQueryTaskPollWaits());
            $this->assertGreaterThanOrEqual(
                21,
                $workerCount - $slots->maxConcurrentWaits() - $slots->maxConcurrentQueryTaskPollWaits(),
                'Idle long polls must leave enough request workers for starts, control-plane traffic, and liveness.',
            );

            $firstWorker = $slots->tryAcquire(30);
            $secondWorker = $slots->tryAcquire(30);
            $thirdWorker = $slots->tryAcquire(30);
            $query = $slots->tryAcquireQueryTaskPoll(30);
            $extraQuery = $slots->tryAcquireQueryTaskPoll(30);

            $this->assertNotNull($firstWorker);
            $this->assertNotNull($secondWorker);
            $this->assertNull($thirdWorker);
            $this->assertNotNull($query);
            $this->assertNull($extraQuery);

            $firstWorker->release();
            $secondWorker->release();
            $query->release();
        } finally {
            if ($previous === false) {
                putenv('PHP_CLI_SERVER_WORKERS');
            } else {
                putenv('PHP_CLI_SERVER_WORKERS='.$previous);
            }
        }
    }

    private function dockerfilePhpCliServerWorkers(): int
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertIsString($dockerfile);
        $this->assertMatchesRegularExpression('/ENV\s+PHP_CLI_SERVER_WORKERS=(\d+)\b/', $dockerfile);

        preg_match('/ENV\s+PHP_CLI_SERVER_WORKERS=(\d+)\b/', $dockerfile, $matches);

        return (int) $matches[1];
    }
}
