<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class NamespaceRequestAdmission
{
    public const METRIC_NAME = 'dw_namespace_request_admission_rejections';

    private const REASONS = [
        'namespace_request_rate_exhausted',
        'namespace_request_concurrency_exhausted',
        'namespace_request_admission_unavailable',
    ];

    private const ABSOLUTE_MAX_REQUESTS_PER_MINUTE = 10_000_000;

    private const ABSOLUTE_MAX_CONCURRENT_REQUESTS = 1024;

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @param  Closure(array<string, mixed>): TResult  $reject
     * @return TResult
     */
    public function execute(string $namespace, Closure $callback, Closure $reject): mixed
    {
        try {
            $limits = $this->limits($namespace);
        } catch (InvalidArgumentException) {
            return $reject($this->reject(
                $namespace,
                'namespace_request_admission_unavailable',
                503,
                1,
                null,
            ));
        }

        if ($limits['max_requests_per_minute'] === null
            && $limits['max_concurrent_requests'] === null) {
            return $callback();
        }

        if (! $this->cache->available()) {
            return $reject($this->reject(
                $namespace,
                'namespace_request_admission_unavailable',
                503,
                1,
                null,
            ));
        }

        $store = $this->cache->store();

        if (! $store->getStore() instanceof LockProvider) {
            return $reject($this->reject(
                $namespace,
                'namespace_request_admission_unavailable',
                503,
                1,
                null,
            ));
        }

        $rateRejection = $this->admitRate($namespace, $limits['max_requests_per_minute']);

        if ($rateRejection !== null) {
            return $reject($rateRejection);
        }

        $slot = $this->acquireConcurrencySlot($namespace, $limits['max_concurrent_requests']);

        if (is_array($slot)) {
            return $reject($slot);
        }

        try {
            return $callback();
        } finally {
            if ($slot instanceof Lock) {
                try {
                    $slot->release();
                } catch (Throwable $exception) {
                    Log::warning('Namespace request admission could not release a concurrency slot.', [
                        'namespace' => $namespace,
                        'reason' => 'namespace_request_admission_release_failed',
                        'exception' => $exception::class,
                    ]);
                }
            }
        }
    }

    /**
     * @return array{
     *     max_requests_per_minute: int|null,
     *     max_concurrent_requests: int|null,
     *     requests_this_minute: int,
     *     rejections_this_minute: int,
     *     rejections_by_reason: array<string, int>,
     *     configuration_status: string,
     *     label_cardinality_policy: array<string, string>
     * }
     */
    public function metrics(string $namespace): array
    {
        try {
            $limits = $this->limits($namespace);
            $configurationStatus = 'valid';
        } catch (InvalidArgumentException) {
            $limits = [
                'max_requests_per_minute' => null,
                'max_concurrent_requests' => null,
            ];
            $configurationStatus = 'invalid';
        }

        $bucket = $this->minuteBucket();
        $byReason = [];

        foreach (self::REASONS as $reason) {
            $byReason[$reason] = $this->cacheValue(
                $this->rejectionCounterKey($namespace, $bucket, $reason),
            );
        }

        return [
            ...$limits,
            'requests_this_minute' => $this->cacheValue($this->rateCounterKey($namespace, $bucket)),
            'rejections_this_minute' => array_sum($byReason),
            'rejections_by_reason' => $byReason,
            'configuration_status' => $configurationStatus,
            'label_cardinality_policy' => [
                'namespace' => 'request_scope_not_label',
                'reason' => 'finite_three_reason_inventory',
            ],
        ];
    }

    /**
     * @return array{max_requests_per_minute: int|null, max_concurrent_requests: int|null}
     */
    public function limits(string $namespace): array
    {
        return [
            'max_requests_per_minute' => $this->effectiveLimit(
                $namespace,
                'max_requests_per_minute',
                self::ABSOLUTE_MAX_REQUESTS_PER_MINUTE,
            ),
            'max_concurrent_requests' => $this->effectiveLimit(
                $namespace,
                'max_concurrent_requests',
                self::ABSOLUTE_MAX_CONCURRENT_REQUESTS,
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function admitRate(string $namespace, ?int $limit): ?array
    {
        if ($limit === null) {
            return null;
        }

        $bucket = $this->minuteBucket();
        $provider = $this->cache->store()->getStore();
        $lock = $provider->lock(
            $this->rateLockKey($namespace, $bucket),
            $this->lockTtlSeconds(),
        );

        try {
            return $lock->block($this->lockWaitSeconds(), function () use ($namespace, $bucket, $limit): ?array {
                $store = $this->cache->store();
                $counterKey = $this->rateCounterKey($namespace, $bucket);
                $count = max(0, (int) $store->get($counterKey, 0));

                if ($count >= $limit) {
                    return $this->reject(
                        $namespace,
                        'namespace_request_rate_exhausted',
                        429,
                        $this->secondsUntilNextMinute(),
                        $limit,
                    );
                }

                if (! $store->add($counterKey, 1, 120)) {
                    $store->increment($counterKey);
                }

                return null;
            });
        } catch (Throwable $exception) {
            Log::warning('Namespace request rate admission is unavailable.', [
                'namespace' => $namespace,
                'reason' => 'namespace_request_admission_unavailable',
                'exception' => $exception::class,
            ]);

            return $this->reject(
                $namespace,
                'namespace_request_admission_unavailable',
                503,
                1,
                $limit,
            );
        }
    }

    /**
     * @return Lock|array<string, mixed>|null
     */
    private function acquireConcurrencySlot(string $namespace, ?int $limit): Lock|array|null
    {
        if ($limit === null) {
            return null;
        }

        $provider = $this->cache->store()->getStore();

        try {
            for ($slot = 0; $slot < $limit; $slot++) {
                $lock = $provider->lock(
                    $this->concurrencySlotKey($namespace, $slot),
                    $this->requestLeaseTtlSeconds(),
                );

                if ($lock->get()) {
                    return $lock;
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Namespace request concurrency admission is unavailable.', [
                'namespace' => $namespace,
                'reason' => 'namespace_request_admission_unavailable',
                'exception' => $exception::class,
            ]);

            return $this->reject(
                $namespace,
                'namespace_request_admission_unavailable',
                503,
                1,
                $limit,
            );
        }

        return $this->reject(
            $namespace,
            'namespace_request_concurrency_exhausted',
            429,
            1,
            $limit,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reject(
        string $namespace,
        string $reason,
        int $status,
        int $retryAfterSeconds,
        ?int $limit,
    ): array {
        $this->recordRejection($namespace, $reason);

        return [
            'reason' => $reason,
            'status' => $status,
            'retryable' => true,
            'retry_after_seconds' => $retryAfterSeconds,
            'namespace' => $namespace,
            'limit' => $limit,
        ];
    }

    private function recordRejection(string $namespace, string $reason): void
    {
        try {
            $store = $this->cache->store();
            $bucket = $this->minuteBucket();
            $key = $this->rejectionCounterKey($namespace, $bucket, $reason);

            if (! $store->add($key, 1, 120)) {
                $store->increment($key);
            }

            if ($store->add($this->rejectionLogKey($namespace, $bucket, $reason), true, 120)) {
                Log::warning('Namespace request admission rejected a request.', [
                    'namespace' => $namespace,
                    'reason' => $reason,
                ]);
            }
        } catch (Throwable) {
            Log::warning('Namespace request admission rejected a request while metrics storage was unavailable.', [
                'namespace' => $namespace,
                'reason' => $reason,
            ]);
        }
    }

    private function effectiveLimit(string $namespace, string $field, int $absoluteMaximum): ?int
    {
        $default = $this->positiveIntOrNull(
            config("server.namespace_admission.{$field}"),
            "server.namespace_admission.{$field}",
        );
        $hard = $this->positiveIntOrNull(
            config("server.namespace_admission.hard_{$field}"),
            "server.namespace_admission.hard_{$field}",
        );
        $overrides = config('server.namespace_admission.overrides', []);
        $override = is_array($overrides)
            && is_array($overrides[$namespace] ?? null)
            && array_key_exists($field, $overrides[$namespace])
                ? $this->positiveIntOrNull(
                    $overrides[$namespace][$field],
                    "server.namespace_admission.overrides.{$namespace}.{$field}",
                )
                : null;
        $configured = $override ?? $default;

        if ($configured === null && $hard === null) {
            return null;
        }

        return min(
            $absoluteMaximum,
            ...array_filter([$configured, $hard], static fn (?int $value): bool => $value !== null),
        );
    }

    private function positiveIntOrNull(mixed $value, string $path): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException("{$path} must be null or a positive integer.");
    }

    private function cacheValue(string $key): int
    {
        try {
            if (! $this->cache->available()) {
                return 0;
            }

            return max(0, (int) $this->cache->store()->get($key, 0));
        } catch (Throwable) {
            return 0;
        }
    }

    private function minuteBucket(): int
    {
        return intdiv(now()->getTimestamp(), 60);
    }

    private function secondsUntilNextMinute(): int
    {
        return max(1, 60 - (now()->getTimestamp() % 60));
    }

    private function lockTtlSeconds(): int
    {
        return max(1, min(30, (int) config('server.namespace_admission.lock_ttl_seconds', 5)));
    }

    private function lockWaitSeconds(): int
    {
        return max(1, min(5, (int) config('server.namespace_admission.lock_wait_seconds', 1)));
    }

    private function requestLeaseTtlSeconds(): int
    {
        return max(5, min(3600, (int) config('server.namespace_admission.request_lease_ttl_seconds', 120)));
    }

    private function namespaceKey(string $namespace): string
    {
        return hash('sha256', $namespace);
    }

    private function rateCounterKey(string $namespace, int $bucket): string
    {
        return "server:namespace-request-admission:rate:{$this->namespaceKey($namespace)}:{$bucket}";
    }

    private function rateLockKey(string $namespace, int $bucket): string
    {
        return "server:namespace-request-admission:rate-lock:{$this->namespaceKey($namespace)}:{$bucket}";
    }

    private function concurrencySlotKey(string $namespace, int $slot): string
    {
        return "server:namespace-request-admission:concurrency:{$this->namespaceKey($namespace)}:{$slot}";
    }

    private function rejectionCounterKey(string $namespace, int $bucket, string $reason): string
    {
        return "server:namespace-request-admission:rejections:{$this->namespaceKey($namespace)}:{$bucket}:{$reason}";
    }

    private function rejectionLogKey(string $namespace, int $bucket, string $reason): string
    {
        return "server:namespace-request-admission:rejection-log:{$this->namespaceKey($namespace)}:{$bucket}:{$reason}";
    }
}
