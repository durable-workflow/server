<?php

namespace App\Support;

use Throwable;

final class ControlPlaneMutationRetrier
{
    private const RETRY_DELAY_MILLISECONDS = 25;

    /**
     * Retry a storage mutation only when the backend reports transient write
     * contention. Callers must recover any already-committed result before an
     * exception reaches this boundary.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $mutation
     * @return TResult
     */
    public function run(callable $mutation, bool $allBackends = false): mixed
    {
        if (! $allBackends && ! BackendLockPressure::isSqliteBackend()) {
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
