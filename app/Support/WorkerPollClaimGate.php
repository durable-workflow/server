<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WorkerPollClaimGate
{
    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    /**
     * SQLite allows one writer at a time. In the quickstart image multiple
     * server workers can race through ready-task probes and then try to
     * upgrade deferred transactions while claiming. The writer lock is
     * database-wide, so the quickstart SQLite backend uses one cache-backed
     * gate for every workflow/activity claim probe. Normal long-poll waits
     * stay concurrent because only the short probe+claim section is gated.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function forSqliteClaim(string $namespace, string $taskQueue, string $taskKind, Closure $callback): mixed
    {
        if (! $this->usesSqlite()) {
            return $callback();
        }

        if (! $this->cache->available()) {
            return $callback();
        }

        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            return $store
                ->lock($this->lockKey(), $this->lockTtlSeconds())
                ->block($this->lockWaitSeconds(), $callback);
        } catch (LockTimeoutException $exception) {
            throw new BackendLockPressureException(
                'Timed out waiting for the SQLite worker poll claim lock.',
                0,
                $exception,
            );
        }
    }

    private function usesSqlite(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'sqlite';
        } catch (Throwable) {
            return false;
        }
    }

    private function lockKey(): string
    {
        return 'server:sqlite-worker-poll-claim:singleton';
    }

    private function lockTtlSeconds(): int
    {
        return max(1, (int) config('server.polling.sqlite_claim_lock_ttl_seconds', 10));
    }

    private function lockWaitSeconds(): int
    {
        return max(0, (int) config('server.polling.sqlite_claim_lock_wait_seconds', 5));
    }
}
