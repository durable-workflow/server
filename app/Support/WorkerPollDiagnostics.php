<?php

namespace App\Support;

use Throwable;

final class WorkerPollDiagnostics
{
    private const PREFIX = 'server:worker-poll-diagnostic:';

    public const RETENTION_SECONDS = 900;

    public function __construct(private readonly ServerPollingCache $cache) {}

    public function recordDisconnect(string $namespace, string $taskQueue, string $pollKind, float $observedSeconds, int $waitSeconds): void
    {
        if ($namespace === '' || $taskQueue === '' || $waitSeconds <= 0
            || $observedSeconds >= $waitSeconds || ! $this->cache->available()) {
            return;
        }

        try {
            $this->cache->store()->put($this->key($namespace, $taskQueue), [
                'level' => 'warning',
                'reason' => 'worker_poll_client_disconnected',
                'poll_kind' => $pollKind,
                'observed_seconds' => round($observedSeconds, 3),
                'configured_wait_seconds' => $waitSeconds,
                'observed_at' => now()->toISOString(),
                'remediation' => 'Compare worker HTTP deadlines and proxy idle timeouts with the Server wait. Use workers supporting negotiated poll progress.',
            ], self::RETENTION_SECONDS);
        } catch (Throwable) {
            // The normal disconnect warning still exists. Diagnostic storage
            // must not interrupt exact wait cleanup or change task authority.
        }
    }

    /** @return array{status: string, retention_seconds: int, last_disconnect: array|null} */
    public function snapshot(string $namespace, string $taskQueue): array
    {
        $result = ['status' => 'unavailable', 'retention_seconds' => self::RETENTION_SECONDS, 'last_disconnect' => null];

        if (! $this->cache->available()) {
            return $result;
        }

        try {
            $last = $this->cache->store()->get($this->key($namespace, $taskQueue));
        } catch (Throwable) {
            return $result;
        }

        if ($last !== null && ! is_array($last)) {
            return $result;
        }

        return ['status' => $last === null ? 'no_recent_observation' : 'warning',
            'retention_seconds' => self::RETENTION_SECONDS, 'last_disconnect' => $last];
    }

    private function key(string $namespace, string $taskQueue): string
    {
        return self::PREFIX.hash('sha256', json_encode([$namespace, $taskQueue], JSON_THROW_ON_ERROR));
    }
}
