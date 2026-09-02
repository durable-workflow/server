<?php

namespace App\Support;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkflowNamespace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class RuntimeExternalPayloadQuota
{
    public const METRIC_NAME = 'dw_runtime_external_payload_namespace_usage';

    private const REASONS = [
        'external_payload_namespace_bytes_exhausted',
        'external_payload_namespace_objects_exhausted',
        'external_payload_namespace_quota_unavailable',
    ];

    private const ABSOLUTE_MAX_BYTES = 1_125_899_906_842_624;

    private const ABSOLUTE_MAX_OBJECTS = 100_000_000;

    public function __construct(
        private readonly ServerPollingCache $cache,
    ) {}

    public function admitCreate(string $namespace, int $sizeBytes): void
    {
        $namespace = strtolower(trim($namespace));

        try {
            $limits = $this->limits($namespace);

            if ($limits['max_bytes'] === null && $limits['max_objects'] === null) {
                return;
            }

            if (DB::connection()->transactionLevel() < 1) {
                throw new InvalidArgumentException('External payload quota admission requires an active transaction.');
            }

            $namespaceRow = WorkflowNamespace::query()
                ->where('name', $namespace)
                ->lockForUpdate()
                ->first();

            if (! $namespaceRow instanceof WorkflowNamespace) {
                throw new InvalidArgumentException('External payload quota namespace does not exist.');
            }

            $usage = $this->usage($namespace);
        } catch (RuntimeExternalPayloadException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->reject(
                $namespace,
                'external_payload_namespace_quota_unavailable',
                503,
                'External payload namespace quota could not be evaluated.',
                $exception,
            );
        }

        if ($limits['max_objects'] !== null && $usage['objects'] >= $limits['max_objects']) {
            throw $this->reject(
                $namespace,
                'external_payload_namespace_objects_exhausted',
                429,
                'External payload object capacity is exhausted for this namespace.',
            );
        }

        if ($limits['max_bytes'] !== null && $sizeBytes > $limits['max_bytes'] - $usage['bytes']) {
            throw $this->reject(
                $namespace,
                'external_payload_namespace_bytes_exhausted',
                429,
                'External payload byte capacity is exhausted for this namespace.',
            );
        }
    }

    /**
     * @return array{
     *     max_bytes: int|null,
     *     max_objects: int|null,
     *     used_bytes: int|null,
     *     used_objects: int|null,
     *     remaining_bytes: int|null,
     *     remaining_objects: int|null,
     *     rejections_this_minute: int,
     *     rejections_by_reason: array<string, int>,
     *     configuration_status: string,
     *     measurement_status: string,
     *     label_cardinality_policy: array<string, string>
     * }
     */
    public function metrics(string $namespace): array
    {
        $namespace = strtolower(trim($namespace));

        try {
            $limits = $this->limits($namespace);
            $configurationStatus = 'valid';
        } catch (Throwable) {
            $limits = ['max_bytes' => null, 'max_objects' => null];
            $configurationStatus = 'invalid';
        }

        try {
            $usage = $this->usage($namespace);
            $measurementStatus = 'available';
        } catch (Throwable) {
            $usage = ['bytes' => null, 'objects' => null];
            $measurementStatus = 'unavailable';
        }

        $bucket = $this->minuteBucket();
        $byReason = [];

        foreach (self::REASONS as $reason) {
            $byReason[$reason] = $this->cacheValue($this->rejectionCounterKey($namespace, $bucket, $reason));
        }

        return [
            ...$limits,
            'used_bytes' => $usage['bytes'],
            'used_objects' => $usage['objects'],
            'remaining_bytes' => $this->remaining($limits['max_bytes'], $usage['bytes']),
            'remaining_objects' => $this->remaining($limits['max_objects'], $usage['objects']),
            'rejections_this_minute' => array_sum($byReason),
            'rejections_by_reason' => $byReason,
            'configuration_status' => $configurationStatus,
            'measurement_status' => $measurementStatus,
            'label_cardinality_policy' => [
                'namespace' => 'request_scope_not_label',
                'reason' => 'finite_three_reason_inventory',
            ],
        ];
    }

    /** @return array{max_bytes: int|null, max_objects: int|null} */
    public function limits(string $namespace): array
    {
        return [
            'max_bytes' => $this->effectiveLimit($namespace, 'max_bytes', self::ABSOLUTE_MAX_BYTES),
            'max_objects' => $this->effectiveLimit($namespace, 'max_objects', self::ABSOLUTE_MAX_OBJECTS),
        ];
    }

    /** @return array{bytes: int, objects: int} */
    private function usage(string $namespace): array
    {
        $query = RuntimeExternalPayload::query()->where('namespace', $namespace);

        return [
            'bytes' => max(0, (int) (clone $query)->sum('size_bytes')),
            'objects' => max(0, (int) $query->count()),
        ];
    }

    private function effectiveLimit(string $namespace, string $field, int $absoluteMaximum): ?int
    {
        $default = $this->nonNegativeIntOrNull(
            config("server.external_payload_transport.{$field}_per_namespace"),
            "server.external_payload_transport.{$field}_per_namespace",
        );
        $hard = $this->nonNegativeIntOrNull(
            config("server.external_payload_transport.hard_{$field}_per_namespace"),
            "server.external_payload_transport.hard_{$field}_per_namespace",
        );
        $overrides = config('server.external_payload_transport.namespace_overrides', []);

        if (! is_array($overrides)) {
            throw new InvalidArgumentException('server.external_payload_transport.namespace_overrides must be a JSON object.');
        }

        $namespaceOverride = $overrides[$namespace] ?? null;

        if (array_key_exists($namespace, $overrides) && ! is_array($namespaceOverride)) {
            throw new InvalidArgumentException(
                "server.external_payload_transport.namespace_overrides.{$namespace} must be a JSON object.",
            );
        }

        $override = is_array($namespaceOverride) && array_key_exists($field, $namespaceOverride)
            ? $this->nonNegativeIntOrNull(
                $namespaceOverride[$field],
                "server.external_payload_transport.namespace_overrides.{$namespace}.{$field}",
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

    private function nonNegativeIntOrNull(mixed $value, string $path): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException("{$path} must be null or a non-negative integer.");
    }

    private function reject(
        string $namespace,
        string $reason,
        int $status,
        string $message,
        ?Throwable $previous = null,
    ): RuntimeExternalPayloadException {
        $this->recordRejection($namespace, $reason);

        return new RuntimeExternalPayloadException(
            $reason,
            $status,
            true,
            $message,
            $previous,
            60,
        );
    }

    private function recordRejection(string $namespace, string $reason): void
    {
        $shouldLog = false;

        try {
            $store = $this->cache->store();
            $bucket = $this->minuteBucket();
            $key = $this->rejectionCounterKey($namespace, $bucket, $reason);

            if (! $store->add($key, 1, 120)) {
                $store->increment($key);
            }

            $shouldLog = $store->add($this->rejectionLogKey($namespace, $bucket, $reason), true, 120);
        } catch (Throwable) {
            // Quota correctness is database-authoritative; telemetry is best effort.
        }

        if ($shouldLog) {
            Log::warning('Runtime external payload namespace quota rejected an object.', [
                'namespace' => $namespace,
                'reason' => $reason,
            ]);
        }
    }

    private function remaining(?int $limit, ?int $used): ?int
    {
        return $limit === null || $used === null ? null : max(0, $limit - $used);
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

    private function rejectionCounterKey(string $namespace, int $bucket, string $reason): string
    {
        return sprintf(
            'server:external-payload-quota:rejections:%s:%d:%s',
            hash('sha256', $namespace),
            $bucket,
            $reason,
        );
    }

    private function rejectionLogKey(string $namespace, int $bucket, string $reason): string
    {
        return sprintf(
            'server:external-payload-quota:rejection-log:%s:%d:%s',
            hash('sha256', $namespace),
            $bucket,
            $reason,
        );
    }
}
