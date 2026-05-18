<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

class QueryTaskPollRequestStore
{
    private const CACHE_PREFIX = 'server:query-task-poll-request:';

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    public function tryStart(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): bool {
        return $this->store()->add(
            $this->pendingKey($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId),
            now()->toJSON(),
            now()->addSeconds($this->pendingTtlSeconds()),
        );
    }

    public function markCurrentIfFresh(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): bool {
        if (
            $this->store()->has($this->pendingKey($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId))
            || $this->store()->has($this->resultKey($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId))
        ) {
            return false;
        }

        $this->markCurrent($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);

        return true;
    }

    public function markCurrent(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): void {
        $this->store()->put(
            $this->currentKey($namespace, $taskQueue, $buildId, $leaseOwner),
            $pollRequestId,
            now()->addSeconds($this->pendingTtlSeconds()),
        );
    }

    public function isCurrent(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): bool {
        return $this->store()->get(
            $this->currentKey($namespace, $taskQueue, $buildId, $leaseOwner),
        ) === $pollRequestId;
    }

    /**
     * @return array{resolved: bool, task: array<string, mixed>|null, poll_status: string|null}
     */
    public function result(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): array {
        $payload = $this->store()->get(
            $this->resultKey($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId),
        );

        if (! is_array($payload) || ($payload['resolved'] ?? null) !== true) {
            return [
                'resolved' => false,
                'task' => null,
                'poll_status' => null,
            ];
        }

        $task = $payload['task'] ?? null;
        $pollStatus = $this->normalizePollStatus($payload['poll_status'] ?? null, $task);

        return [
            'resolved' => true,
            'task' => is_array($task) ? $task : null,
            'poll_status' => $pollStatus,
        ];
    }

    /**
     * @return array{resolved: bool, task: array<string, mixed>|null, poll_status: string|null}
     */
    public function waitForResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        ?int $timeoutMilliseconds = null,
    ): array {
        $pendingKey = $this->pendingKey($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);
        $deadline = microtime(true) + (($timeoutMilliseconds ?? $this->waitTimeoutMilliseconds()) / 1000);

        while (microtime(true) < $deadline) {
            $result = $this->result($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);

            if ($result['resolved']) {
                return $result;
            }

            if (! $this->store()->has($pendingKey)) {
                return [
                    'resolved' => false,
                    'task' => null,
                    'poll_status' => null,
                ];
            }

            $this->pause($this->pollIntervalMilliseconds());
        }

        return $this->result($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    public function rememberResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        ?array $task,
        string $pollStatus,
    ): void {
        $this->store()->put(
            $this->resultKey($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId),
            [
                'resolved' => true,
                'task' => $task,
                'poll_status' => $this->normalizePollStatus($pollStatus, $task),
            ],
            now()->addSeconds($this->resultTtlSeconds($task)),
        );

        $this->forgetPending($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);
    }

    public function forgetResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): void {
        $this->store()->forget(
            $this->resultKey($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId),
        );
    }

    public function forgetPending(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): void {
        $this->store()->forget(
            $this->pendingKey($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId),
        );
    }

    protected function pause(int $milliseconds): void
    {
        usleep(max(1, $milliseconds) * 1000);
    }

    private function pendingTtlSeconds(): int
    {
        return max(5, (int) config('server.polling.timeout', 30) + 5);
    }

    private function resultTtlSeconds(?array $task): int
    {
        $leaseExpiresAt = $this->leaseExpiresAt($task);

        if ($leaseExpiresAt instanceof Carbon && $leaseExpiresAt->gt(now())) {
            return max(5, min(3600, now()->diffInSeconds($leaseExpiresAt) + 5));
        }

        return max(5, min(60, $this->pendingTtlSeconds()));
    }

    private function waitTimeoutMilliseconds(): int
    {
        return max(250, ((int) config('server.polling.timeout', 30) * 1000) + 250);
    }

    private function pollIntervalMilliseconds(): int
    {
        return max(10, min(50, (int) config('server.polling.signal_check_interval_ms', 100)));
    }

    private function leaseExpiresAt(?array $task): ?Carbon
    {
        if (! is_array($task)) {
            return null;
        }

        $leaseExpiresAt = $task['lease_expires_at'] ?? null;

        if ($leaseExpiresAt instanceof \DateTimeInterface) {
            return Carbon::instance($leaseExpiresAt);
        }

        if (! is_string($leaseExpiresAt) || trim($leaseExpiresAt) === '') {
            return null;
        }

        try {
            return Carbon::parse($leaseExpiresAt);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function normalizePollStatus(mixed $pollStatus, ?array $task): string
    {
        if (is_string($pollStatus) && $pollStatus !== '') {
            return $pollStatus;
        }

        return is_array($task) ? 'leased' : 'empty';
    }

    private function pendingKey(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): string {
        return $this->cacheKey('pending', $namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);
    }

    private function resultKey(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): string {
        return $this->cacheKey('result', $namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);
    }

    private function currentKey(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
    ): string {
        return $this->cacheKey('current', $namespace, $taskQueue, $buildId, $leaseOwner, null);
    }

    private function cacheKey(
        string $kind,
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        ?string $pollRequestId,
    ): string {
        return self::CACHE_PREFIX.sha1(json_encode([
            'kind' => $kind,
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'build_id' => $buildId,
            'lease_owner' => $leaseOwner,
            'poll_request_id' => $pollRequestId,
        ], JSON_THROW_ON_ERROR));
    }

    private function store(): CacheRepository
    {
        return $this->cache->store();
    }
}
