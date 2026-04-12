<?php

namespace Tests\Unit;

use App\Support\LongPollSignalStore;
use App\Support\LongPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LongPollerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
        ]);
    }

    public function test_it_reprobes_as_soon_as_a_wake_channel_changes(): void
    {
        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $channel = $signals->workflowTaskPollChannels('default', null, 'external-workflows')[0];

        $probeCount = 0;

        $poller = new class($signals) extends LongPoller
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

                usleep(1000);
            }
        };

        $poller->afterPause = function (int $pauseCalls) use ($signals, $channel): void {
            if ($pauseCalls === 1) {
                $signals->signal($channel);
            }
        };

        $result = $poller->until(
            function () use (&$probeCount): ?string {
                $probeCount++;

                return $probeCount >= 2 ? 'ready' : null;
            },
            static fn (?string $value): bool => $value === 'ready',
            timeoutSeconds: 1,
            intervalMilliseconds: 1000,
            wakeChannels: [$channel],
        );

        $this->assertSame('ready', $result);
        $this->assertSame(2, $probeCount);
        $this->assertSame(1, $poller->pauseCalls);
    }

    public function test_it_still_forces_periodic_rechecks_without_a_signal_change(): void
    {
        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);

        $probeCount = 0;

        $poller = new class($signals) extends LongPoller
        {
            protected function pause(int $milliseconds): void
            {
                usleep(max(1, $milliseconds) * 1000);
            }
        };

        $result = $poller->until(
            function () use (&$probeCount): ?string {
                $probeCount++;

                return $probeCount >= 2 ? 'ready' : null;
            },
            static fn (?string $value): bool => $value === 'ready',
            timeoutSeconds: 1,
            intervalMilliseconds: 5,
        );

        $this->assertSame('ready', $result);
        $this->assertSame(2, $probeCount);
    }

    public function test_it_honors_an_earlier_next_probe_hint_before_the_default_interval(): void
    {
        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);

        $probeCount = 0;
        $nextProbeAt = null;

        $poller = new class($signals) extends LongPoller
        {
            protected function pause(int $milliseconds): void
            {
                usleep(max(1, $milliseconds) * 1000);
            }
        };

        $startedAt = microtime(true);

        $result = $poller->until(
            function () use (&$probeCount, &$nextProbeAt): ?string {
                $probeCount++;

                if ($probeCount === 1) {
                    $nextProbeAt = now()->addMilliseconds(20);

                    return null;
                }

                return 'ready';
            },
            static fn (?string $value): bool => $value === 'ready',
            timeoutSeconds: 1,
            intervalMilliseconds: 1000,
            nextProbeAt: function () use (&$nextProbeAt): mixed {
                return $nextProbeAt;
            },
        );

        $elapsedSeconds = microtime(true) - $startedAt;

        $this->assertSame('ready', $result);
        $this->assertSame(2, $probeCount);
        $this->assertLessThan(0.8, $elapsedSeconds);
    }
}
