<?php

namespace App\Support;

final class LongPollWaitSlotStore
{
    private const CACHE_PREFIX = 'server:long-poll-wait-slot:';
    private const WORKER_POOL = 'worker';
    private const QUERY_TASK_POOL = 'query-task';

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    public function tryAcquire(int $timeoutSeconds): ?LongPollWaitSlot
    {
        return $this->tryAcquireFromPool($timeoutSeconds, $this->maxConcurrentWaits(), self::WORKER_POOL);
    }

    public function tryAcquireQueryTaskPoll(int $timeoutSeconds): ?LongPollWaitSlot
    {
        return $this->tryAcquireFromPool($timeoutSeconds, $this->maxConcurrentQueryTaskPollWaits(), self::QUERY_TASK_POOL);
    }

    public function maxConcurrentWaits(): ?int
    {
        $configured = $this->configuredMaxConcurrentWaits();

        if ($configured !== null) {
            return $configured;
        }

        $phpServerWorkers = $this->phpCliServerWorkers();

        if ($phpServerWorkers === null) {
            return null;
        }

        return max(0, $phpServerWorkers - $this->reservedHttpWorkers() - $this->queryTaskPollWaitReservation($phpServerWorkers));
    }

    public function maxConcurrentQueryTaskPollWaits(): ?int
    {
        $configured = $this->configuredQueryTaskPollWaits();
        $phpServerWorkers = $this->phpCliServerWorkers();

        if ($phpServerWorkers === null) {
            return $configured;
        }

        $workerWaits = $this->configuredMaxConcurrentWaits()
            ?? max(0, $phpServerWorkers - $this->reservedHttpWorkers() - $this->queryTaskPollWaitReservation($phpServerWorkers));
        $available = max(0, $phpServerWorkers - $this->reservedHttpWorkers() - $workerWaits);

        if ($configured !== null) {
            return min($configured, $available);
        }

        return min(1, $available);
    }

    private function tryAcquireFromPool(int $timeoutSeconds, ?int $maxConcurrentWaits, string $pool): ?LongPollWaitSlot
    {
        if ($pool !== self::WORKER_POOL && $pool !== self::QUERY_TASK_POOL) {
            return null;
        }

        if ($maxConcurrentWaits === null) {
            return LongPollWaitSlot::unlimited();
        }

        if ($maxConcurrentWaits <= 0) {
            return null;
        }

        $owner = bin2hex(random_bytes(16));
        $expiresAt = now()->addSeconds(max(1, $timeoutSeconds + 5));

        for ($slot = 0; $slot < $maxConcurrentWaits; $slot++) {
            $key = $this->slotKey($slot, $pool);

            if ($this->cache->store()->add($key, $owner, $expiresAt)) {
                return LongPollWaitSlot::acquired($this->cache, $key, $owner);
            }
        }

        return null;
    }

    private function configuredMaxConcurrentWaits(): ?int
    {
        $configured = config('server.polling.max_concurrent_waits');

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return null;
    }

    private function configuredQueryTaskPollWaits(): ?int
    {
        $configured = config('server.query_tasks.max_concurrent_poll_waits');

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return null;
    }

    private function queryTaskPollWaitReservation(int $phpServerWorkers): int
    {
        $available = max(0, $phpServerWorkers - $this->reservedHttpWorkers());
        $configured = $this->configuredQueryTaskPollWaits();

        if ($configured !== null) {
            return min($configured, $available);
        }

        return min(1, $available);
    }

    private function phpCliServerWorkers(): ?int
    {
        $phpServerWorkers = getenv('PHP_CLI_SERVER_WORKERS');

        if (! is_numeric($phpServerWorkers)) {
            return null;
        }

        return max(0, (int) $phpServerWorkers);
    }

    private function reservedHttpWorkers(): int
    {
        return max(0, (int) config('server.polling.reserved_http_workers', 2));
    }

    private function slotKey(int $slot, string $pool): string
    {
        $prefix = self::CACHE_PREFIX.sha1((string) config('server.server_id', gethostname()));

        return $pool === self::WORKER_POOL
            ? $prefix.':'.$slot
            : $prefix.':'.$pool.':'.$slot;
    }
}
