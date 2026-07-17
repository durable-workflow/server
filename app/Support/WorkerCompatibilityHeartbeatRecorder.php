<?php

namespace App\Support;

use Throwable;
use Workflow\V2\Support\StandaloneWorkerVisibility;

final class WorkerCompatibilityHeartbeatRecorder
{
    private const CACHE_PREFIX = 'server:worker-compatibility-heartbeat:';

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    /**
     * Refresh the package compatibility fleet at most once per shared write
     * interval. The standalone CLI server runs several request processes, so
     * an in-process throttle alone lets simultaneous worker heartbeats all
     * replace the same database row.
     */
    public function record(
        string $namespace,
        string $workerId,
        ?string $taskQueue,
        ?string $buildId,
        bool $force = false,
    ): bool {
        try {
            $store = $this->cache->store();
            $key = $this->cacheKey($namespace, $workerId);
            $expiresAt = now()->addSeconds($this->writeIntervalSeconds());

            if ($force) {
                $store->put($key, true, $expiresAt);
            } elseif (! $store->add($key, true, $expiresAt)) {
                return false;
            }
        } catch (Throwable) {
            // Fleet visibility already has its own durable/cache fallback.
            // A throttle outage must not suppress the authoritative refresh.
        }

        StandaloneWorkerVisibility::recordCompatibility(
            namespace: $namespace,
            workerId: $workerId,
            taskQueue: $taskQueue,
            buildId: $buildId,
        );

        return true;
    }

    private function writeIntervalSeconds(): int
    {
        $ttl = max(1, (int) config('workflows.v2.compatibility.heartbeat_ttl_seconds', 30));

        return max(1, (int) floor($ttl / 3));
    }

    private function cacheKey(string $namespace, string $workerId): string
    {
        return self::CACHE_PREFIX.sha1(json_encode([
            'namespace' => $namespace,
            'worker_id' => $workerId,
        ], JSON_THROW_ON_ERROR));
    }
}
