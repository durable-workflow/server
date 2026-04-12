<?php

namespace Tests\Unit;

use App\Support\LongPollSignalStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LongPollSignalStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
        ]);
    }

    public function test_signal_versions_expire_after_the_configured_ttl(): void
    {
        config([
            'server.polling.wake_signal_ttl_seconds' => 2,
        ]);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $channel = $signals->historyRunChannel('run-expiring-signal');

        $signals->signal($channel);

        $snapshot = $signals->snapshot([$channel]);

        $this->assertIsString($snapshot[$channel]);
        $this->assertFalse($signals->changed($snapshot));

        $this->travel(3)->seconds();

        $this->assertTrue($signals->changed($snapshot));
        $this->assertSame([$channel => null], $signals->snapshot([$channel]));
    }

    public function test_signalling_the_same_channel_refreshes_the_expiry_window(): void
    {
        config([
            'server.polling.wake_signal_ttl_seconds' => 3,
        ]);

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $channel = $signals->historyRunChannel('run-refreshed-signal');

        $signals->signal($channel);
        $firstSnapshot = $signals->snapshot([$channel]);
        $firstVersion = $firstSnapshot[$channel];

        $this->assertIsString($firstVersion);

        $this->travel(2)->seconds();

        $signals->signal($channel);

        $secondSnapshot = $signals->snapshot([$channel]);
        $secondVersion = $secondSnapshot[$channel];

        $this->assertIsString($secondVersion);
        $this->assertNotSame($firstVersion, $secondVersion);
        $this->assertTrue($signals->changed($firstSnapshot));

        $this->travel(2)->seconds();

        $this->assertFalse($signals->changed($secondSnapshot));

        $this->travel(2)->seconds();

        $this->assertTrue($signals->changed($secondSnapshot));
        $this->assertSame([$channel => null], $signals->snapshot([$channel]));
    }
}
