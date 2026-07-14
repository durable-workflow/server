<?php

namespace App\Support;

use Throwable;

final class SqliteControlPlaneMutationRetrier
{
    private const RETRY_DELAY_MILLISECONDS = 25;

    /**
     * Retry a control-plane mutation only when SQLite reports transient write
     * contention. The workflow mutation transactions are atomic, so a failed
     * attempt is rolled back before the whole operation is invoked again.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $mutation
     * @return TResult
     */
    public function run(callable $mutation): mixed
    {
        if (! BackendLockPressure::isSqliteBackend()) {
            return $mutation();
        }

        $attempts = max(1, (int) config('workflows.storage.transaction_attempts', 5));

        for ($attempt = 1; ; $attempt++) {
            try {
                return $mutation();
            } catch (Throwable $exception) {
                if ($attempt >= $attempts || ! BackendLockPressure::is($exception)) {
                    throw $exception;
                }

                usleep($attempt * self::RETRY_DELAY_MILLISECONDS * 1000);
            }
        }
    }
}
