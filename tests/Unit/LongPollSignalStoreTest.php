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

    public function test_query_task_wake_is_scoped_to_the_worker_namespace_and_queue(): void
    {
        $signals = app(LongPollSignalStore::class);
        $matching = $signals->workerTaskQueueChannel('tenant-a', 'orders');
        $otherNamespace = $signals->workerTaskQueueChannel('tenant-b', 'orders');
        $otherQueue = $signals->workerTaskQueueChannel('tenant-a', 'billing');
        $snapshot = $signals->snapshot([$matching, $otherNamespace, $otherQueue]);

        $signals->signalQueuedQueryTask('tenant-a', 'orders');

        $this->assertTrue($signals->changed([$matching => $snapshot[$matching]]));
        $this->assertFalse($signals->changed([$otherNamespace => $snapshot[$otherNamespace]]));
        $this->assertFalse($signals->changed([$otherQueue => $snapshot[$otherQueue]]));
    }

    public function test_query_poll_registration_does_not_interrupt_other_task_kinds(): void
    {
        $signals = app(LongPollSignalStore::class);
        $workerChannel = $signals->workerTaskQueueChannel('tenant-a', 'orders');
        $queryChannel = $signals->queryTaskPollChannels('tenant-a', 'orders')[1];
        $snapshot = $signals->snapshot([$workerChannel, $queryChannel]);

        $signals->signalQueryTaskQueue('tenant-a', 'orders');

        $this->assertFalse($signals->changed([$workerChannel => $snapshot[$workerChannel]]));
        $this->assertTrue($signals->changed([$queryChannel => $snapshot[$queryChannel]]));
    }
}
