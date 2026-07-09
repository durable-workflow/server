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

        $available = $this->availableNonReservedPhpServerWorkers($phpServerWorkers);

        return $this->defaultWorkerPollWaits($available, $this->queryTaskPollWaitReservation($available));
    }

    public function maxConcurrentQueryTaskPollWaits(): ?int
    {
        $configured = $this->configuredQueryTaskPollWaits();
        $phpServerWorkers = $this->phpCliServerWorkers();

        if ($phpServerWorkers === null) {
            return $configured;
        }

        $availableWorkers = $this->availableNonReservedPhpServerWorkers($phpServerWorkers);
        $queryWaitReservation = $this->queryTaskPollWaitReservation($availableWorkers);
        $workerWaits = $this->configuredMaxConcurrentWaits()
            ?? $this->defaultWorkerPollWaits($availableWorkers, $queryWaitReservation);
        $available = max(0, $availableWorkers - $workerWaits);

        if ($configured !== null) {
            return min($configured, $available);
        }

        return min($this->defaultQueryTaskPollWaits($availableWorkers), $available);
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

    private function queryTaskPollWaitReservation(int $availableWorkers): int
    {
        $configured = $this->configuredQueryTaskPollWaits();

        if ($configured !== null) {
            return min($configured, $availableWorkers);
        }

        return $this->defaultQueryTaskPollWaits($availableWorkers);
    }

    private function defaultWorkerPollWaits(int $availableWorkers, int $queryTaskPollWaits): int
    {
        if ($availableWorkers <= 0) {
            return 0;
        }

        $remainingAfterQueryPolls = max(0, $availableWorkers - $queryTaskPollWaits);

        if ($remainingAfterQueryPolls <= 1) {
            return $remainingAfterQueryPolls;
        }

        $controlPlaneReservation = max(1, intdiv($availableWorkers + 1, 2));

        return min(2, max(1, $remainingAfterQueryPolls - $controlPlaneReservation));
    }

    private function defaultQueryTaskPollWaits(int $availableWorkers): int
    {
        if ($availableWorkers <= 1) {
            return 0;
        }

        return 1;
    }

    private function phpCliServerWorkers(): ?int
    {
        $phpServerWorkers = getenv('PHP_CLI_SERVER_WORKERS');

        if (! is_numeric($phpServerWorkers)) {
            return null;
        }

        return max(0, (int) $phpServerWorkers);
    }

    private function availableNonReservedPhpServerWorkers(int $phpServerWorkers): int
    {
        return max(0, $phpServerWorkers - $this->reservedHttpWorkers($phpServerWorkers));
    }

    private function reservedHttpWorkers(int $phpServerWorkers): int
    {
        $configured = config('server.polling.reserved_http_workers');

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        if ($phpServerWorkers <= 0) {
            return 0;
        }

        if ($phpServerWorkers <= 2) {
            return 1;
        }

        return min($phpServerWorkers - 1, max(2, intdiv($phpServerWorkers, 2) + 1));
    }

    private function slotKey(int $slot, string $pool): string
    {
        $prefix = self::CACHE_PREFIX.sha1((string) config('server.server_id', gethostname()));

        return $pool === self::WORKER_POOL
            ? $prefix.':'.$slot
            : $prefix.':'.$pool.':'.$slot;
    }
}
