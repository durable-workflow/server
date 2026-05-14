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
     * server workers can race through the same ready-task probe and then try
     * to upgrade deferred transactions while claiming. Serializing only the
     * probe+claim section keeps normal long-poll waits concurrent while
     * avoiding generic SQLITE_BUSY failures from concurrent worker pollers.
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

        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            return $store
                ->lock($this->lockKey($namespace, $taskQueue, $taskKind), $this->lockTtlSeconds())
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

    private function lockKey(string $namespace, string $taskQueue, string $taskKind): string
    {
        return 'server:sqlite-worker-poll-claim:'.sha1($taskKind.'|'.$namespace.'|'.$taskQueue);
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
