<?php

namespace App\Support;

use Throwable;

final class LongPollWaitSlotStore
{
    private const ABSOLUTE_MAX_NAMESPACE_WAITS = 1024;

    private const WORKER_POOL = 'worker';

    private const QUERY_TASK_POOL = 'query-task';

    public function __construct(
        private readonly LongPollWaitPool $pool,
    ) {}

    public function tryAcquire(int $timeoutSeconds, ?string $namespace = null): ?LongPollWaitSlot
    {
        return $this->tryAcquireFromPool(
            $timeoutSeconds,
            $this->maxConcurrentWaits(),
            self::WORKER_POOL,
            $namespace,
            $this->maxConcurrentWaitsPerNamespace(),
        );
    }

    public function tryAcquireQueryTaskPoll(int $timeoutSeconds, ?string $namespace = null): ?LongPollWaitSlot
    {
        return $this->tryAcquireFromPool(
            $timeoutSeconds,
            $this->maxConcurrentQueryTaskPollWaits(),
            self::QUERY_TASK_POOL,
            $namespace,
            $this->maxConcurrentQueryTaskPollWaitsPerNamespace(),
        );
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

    public function maxConcurrentWaitsPerNamespace(): ?int
    {
        return $this->namespaceLimit(
            config('server.polling.max_concurrent_waits_per_namespace'),
            $this->maxConcurrentWaits(),
        );
    }

    public function maxConcurrentQueryTaskPollWaitsPerNamespace(): ?int
    {
        return $this->namespaceLimit(
            config('server.query_tasks.max_concurrent_poll_waits_per_namespace'),
            $this->maxConcurrentQueryTaskPollWaits(),
        );
    }

    private function tryAcquireFromPool(
        int $timeoutSeconds,
        ?int $maxConcurrentWaits,
        string $pool,
        ?string $namespace,
        ?int $maxConcurrentWaitsPerNamespace,
    ): ?LongPollWaitSlot {
        if ($pool !== self::WORKER_POOL && $pool !== self::QUERY_TASK_POOL) {
            return null;
        }

        if ($maxConcurrentWaits === null && $maxConcurrentWaitsPerNamespace === null) {
            return LongPollWaitSlot::unlimited();
        }

        if (($maxConcurrentWaits !== null && $maxConcurrentWaits <= 0)
            || ($maxConcurrentWaitsPerNamespace !== null && $maxConcurrentWaitsPerNamespace <= 0)) {
            return null;
        }

        $namespace = $namespace !== null ? trim($namespace) : null;

        if ($maxConcurrentWaitsPerNamespace !== null && ($namespace === null || $namespace === '')) {
            return null;
        }

        try {
            return $this->pool->tryAcquire(
                $this->poolDirectory($pool),
                $maxConcurrentWaits,
                $namespace,
                $maxConcurrentWaitsPerNamespace,
                $timeoutSeconds,
            );
        } catch (Throwable) {
            logger()->warning('Node-local long-poll capacity is unavailable; check that DW_POLLING_CACHE_PATH is writable.', [
                'wait_pool' => $pool,
            ]);

            return null;
        }
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

    private function namespaceLimit(mixed $configured, ?int $globalLimit): ?int
    {
        if ($configured === null || $configured === '') {
            return null;
        }

        $limit = filter_var($configured, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 0,
                'max_range' => self::ABSOLUTE_MAX_NAMESPACE_WAITS,
            ],
        ]);

        if ($limit === false) {
            return 0;
        }

        return $globalLimit === null ? $limit : min($limit, $globalLimit);
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

    private function poolDirectory(string $pool): string
    {
        $path = (string) config('server.polling.cache_path', storage_path('framework/cache/server-polling'));

        return rtrim($path, '/').'/wait-slots/'
            .sha1((string) config('server.server_id', gethostname())).'/'.$pool;
    }
}
