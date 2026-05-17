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

            $this->assertSame(2, $slots->maxConcurrentWaits());
        } finally {
            if ($previous === false) {
                putenv('PHP_CLI_SERVER_WORKERS');
            } else {
                putenv('PHP_CLI_SERVER_WORKERS='.$previous);
            }
        }
    }
}
