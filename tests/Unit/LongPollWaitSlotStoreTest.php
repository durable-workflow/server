<?php

namespace Tests\Unit;

use App\Support\LongPollWaitSlotStore;
use App\Support\ServerPollingCache;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
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
            'server.polling.max_concurrent_waits_per_namespace' => null,
            'server.polling.reserved_http_workers' => null,
            'server.query_tasks.max_concurrent_poll_waits' => null,
            'server.query_tasks.max_concurrent_poll_waits_per_namespace' => null,
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

    public function test_namespace_cap_keeps_a_worker_wait_slot_available_to_another_namespace(): void
    {
        config([
            'server.polling.max_concurrent_waits' => 2,
            'server.polling.max_concurrent_waits_per_namespace' => 1,
        ]);

        /** @var LongPollWaitSlotStore $slots */
        $slots = app(LongPollWaitSlotStore::class);

        $tenantA = $slots->tryAcquire(30, 'tenant-a');
        $extraTenantA = $slots->tryAcquire(30, 'tenant-a');
        $tenantB = $slots->tryAcquire(30, 'tenant-b');

        $this->assertNotNull($tenantA);
        $this->assertNull($extraTenantA);
        $this->assertNotNull($tenantB);

        $tenantA->release();
        $replacementTenantA = $slots->tryAcquire(30, 'tenant-a');
        $this->assertNotNull($replacementTenantA);

        $tenantB->release();
        $replacementTenantA->release();
    }

    public function test_namespace_cap_is_independent_for_query_task_waits(): void
    {
        config([
            'server.query_tasks.max_concurrent_poll_waits' => 2,
            'server.query_tasks.max_concurrent_poll_waits_per_namespace' => 1,
        ]);

        /** @var LongPollWaitSlotStore $slots */
        $slots = app(LongPollWaitSlotStore::class);

        $tenantA = $slots->tryAcquireQueryTaskPoll(30, 'tenant-a');
        $extraTenantA = $slots->tryAcquireQueryTaskPoll(30, 'tenant-a');
        $tenantB = $slots->tryAcquireQueryTaskPoll(30, 'tenant-b');

        $this->assertNotNull($tenantA);
        $this->assertNull($extraTenantA);
        $this->assertNotNull($tenantB);

        $tenantA->release();
        $tenantB->release();
    }

    public function test_configured_namespace_cap_fails_closed_without_shared_cache_authority(): void
    {
        config([
            'server.polling.max_concurrent_waits' => 2,
            'server.polling.max_concurrent_waits_per_namespace' => 1,
        ]);

        $factory = Mockery::mock(CacheFactory::class);
        $factory->shouldReceive('store')->andThrow(new RuntimeException('cache unavailable'));
        $slots = new LongPollWaitSlotStore(new ServerPollingCache($factory, new Filesystem));

        $this->assertNull($slots->tryAcquire(30, 'tenant-a'));
    }

    public function test_invalid_namespace_cap_fails_closed_instead_of_becoming_unlimited(): void
    {
        config([
            'server.polling.max_concurrent_waits' => 2,
            'server.polling.max_concurrent_waits_per_namespace' => 'invalid',
        ]);

        /** @var LongPollWaitSlotStore $slots */
        $slots = app(LongPollWaitSlotStore::class);

        $this->assertSame(0, $slots->maxConcurrentWaitsPerNamespace());
        $this->assertNull($slots->tryAcquire(30, 'tenant-a'));
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

    public function test_published_apache_image_default_preserves_api_workers_for_load_profiles(): void
    {
        $workerCount = $this->apacheMaxRequestWorkers();

        config([
            'server.polling.max_concurrent_waits' => 2,
            'server.polling.reserved_http_workers' => null,
            'server.query_tasks.max_concurrent_poll_waits' => 1,
        ]);

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
    }

    private function apacheMaxRequestWorkers(): int
    {
        $apacheMpm = file_get_contents(base_path('docker/apache-mpm-prefork.conf'));

        $this->assertIsString($apacheMpm);
        $this->assertMatchesRegularExpression('/^\s*MaxRequestWorkers\s+(\d+)\s*$/m', $apacheMpm);

        preg_match('/^\s*MaxRequestWorkers\s+(\d+)\s*$/m', $apacheMpm, $matches);

        return (int) $matches[1];
    }
}
