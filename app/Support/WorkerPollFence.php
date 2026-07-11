<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Carbon\CarbonInterface;
use Workflow\V2\Support\StandaloneWorkerVisibility;

final class WorkerPollFence
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(WorkerRegistration $worker): array
    {
        return [
            'worker_id' => self::stringValue($worker->worker_id),
            'namespace' => self::stringValue($worker->namespace),
            'routing_revision' => self::routingRevision($worker),
            'process_identity' => self::workerProcessIdentity($worker->process_metrics),
            'updated_at' => self::timestamp($worker->updated_at),
            'last_heartbeat_at' => self::timestamp($worker->last_heartbeat_at),
        ];
    }

    /**
     * Return false when the worker registration changed after a long-poll
     * request started. That fences HTTP polls left behind by a crashed process
     * so they cannot lease work intended for the replacement process.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function isCurrent(array $snapshot): bool
    {
        return self::matchesCurrent($snapshot, false);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function isCurrentForUpdate(array $snapshot): bool
    {
        return self::matchesCurrent($snapshot, true);
    }

    public static function isFresh(WorkerRegistration $worker): bool
    {
        return $worker->status === 'active' && ! self::isStale($worker);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function matchesCurrent(array $snapshot, bool $lockForUpdate): bool
    {
        $workerId = self::stringValue($snapshot['worker_id'] ?? null);
        $namespace = self::stringValue($snapshot['namespace'] ?? null);

        if ($workerId === null || $namespace === null) {
            return false;
        }

        $query = WorkerRegistration::query()
            ->where('worker_id', $workerId)
            ->where('namespace', $namespace);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $current = $query->first();

        if (! $current instanceof WorkerRegistration) {
            return false;
        }

        if (! self::isFresh($current)) {
            return false;
        }

        $currentSnapshot = self::snapshot($current);

        if (($snapshot['routing_revision'] ?? null) !== ($currentSnapshot['routing_revision'] ?? null)) {
            return false;
        }

        $processIdentity = is_array($snapshot['process_identity'] ?? null)
            ? $snapshot['process_identity']
            : [];
        $currentProcessIdentity = is_array($currentSnapshot['process_identity'] ?? null)
            ? $currentSnapshot['process_identity']
            : [];

        if ($processIdentity !== [] || $currentProcessIdentity !== []) {
            return $processIdentity === $currentProcessIdentity;
        }

        return ($snapshot['updated_at'] ?? null) === ($currentSnapshot['updated_at'] ?? null)
            && ($snapshot['last_heartbeat_at'] ?? null) === ($currentSnapshot['last_heartbeat_at'] ?? null);
    }

    private static function isStale(WorkerRegistration $worker): bool
    {
        $heartbeat = $worker->last_heartbeat_at;

        if (! $heartbeat instanceof CarbonInterface) {
            return true;
        }

        return $heartbeat->lt(now()->subSeconds(self::workerStaleAfterSeconds()));
    }

    private static function workerStaleAfterSeconds(): int
    {
        $configured = config('server.workers.stale_after_seconds');
        $pollingTimeout = config('server.polling.timeout');

        return StandaloneWorkerVisibility::staleAfterSeconds(
            is_numeric($configured) ? (int) $configured : null,
            is_numeric($pollingTimeout) ? (int) $pollingTimeout : null,
        );
    }

    private static function routingRevision(WorkerRegistration $worker): string
    {
        return sha1(json_encode([
            'task_queue' => self::stringValue($worker->task_queue),
            'build_id' => self::stringValue($worker->build_id),
            'supported_workflow_types' => self::stringList($worker->supported_workflow_types),
            'workflow_definition_fingerprints' => self::arrayValue($worker->workflow_definition_fingerprints),
            'workflow_command_contracts' => self::arrayValue($worker->workflow_command_contracts),
            'supported_activity_types' => self::stringList($worker->supported_activity_types),
            'capabilities' => self::stringList($worker->capabilities),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, int|string>
     */
    private static function workerProcessIdentity(mixed $processMetrics): array
    {
        if (! is_array($processMetrics)) {
            return [];
        }

        $identity = [];

        foreach (['host', 'process_started_at'] as $key) {
            $value = $processMetrics[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $identity[$key] = trim($value);
            }
        }

        $processId = $processMetrics['process_id'] ?? null;

        if (is_int($processId) || (is_string($processId) && ctype_digit($processId))) {
            $identity['process_id'] = (int) $processId;
        }

        return $identity;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item !== '') {
                $strings[$item] = $item;
            }
        }

        $strings = array_values($strings);
        sort($strings);

        return $strings;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        ksort($value);

        return $value;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('U.u');
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }
}
