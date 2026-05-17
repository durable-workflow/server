<?php

namespace App\Support;

final class LongPollWaitSlotStore
{
    private const CACHE_PREFIX = 'server:long-poll-wait-slot:';

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    public function tryAcquire(int $timeoutSeconds): ?LongPollWaitSlot
    {
        $maxConcurrentWaits = $this->maxConcurrentWaits();

        if ($maxConcurrentWaits === null) {
            return LongPollWaitSlot::unlimited();
        }

        if ($maxConcurrentWaits <= 0) {
            return null;
        }

        $owner = bin2hex(random_bytes(16));
        $expiresAt = now()->addSeconds(max(1, $timeoutSeconds + 5));

        for ($slot = 0; $slot < $maxConcurrentWaits; $slot++) {
            $key = $this->slotKey($slot);

            if ($this->cache->store()->add($key, $owner, $expiresAt)) {
                return LongPollWaitSlot::acquired($this->cache, $key, $owner);
            }
        }

        return null;
    }

    public function maxConcurrentWaits(): ?int
    {
        $configured = config('server.polling.max_concurrent_waits');

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        $phpServerWorkers = getenv('PHP_CLI_SERVER_WORKERS');

        if (! is_numeric($phpServerWorkers)) {
            return null;
        }

        $reservedWorkers = max(0, (int) config('server.polling.reserved_http_workers', 2));

        return max(0, (int) $phpServerWorkers - $reservedWorkers);
    }

    private function slotKey(int $slot): string
    {
        return self::CACHE_PREFIX.sha1((string) config('server.server_id', gethostname())).':'.$slot;
    }
}
