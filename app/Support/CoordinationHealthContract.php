<?php

namespace App\Support;

final class CoordinationHealthContract
{
    public const SCHEMA = 'durable-workflow.v2.coordination-health.contract';

    public const VERSION = 2;

    /**
     * @param  array<string, mixed>  $workflowCheck
     * @return array<string, mixed>
     */
    public static function manifest(array $workflowCheck, ?array $routingDrains = null): array
    {
        $manifest = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'namespace_scope' => 'all_namespaces',
            'status' => is_string($workflowCheck['status'] ?? null) ? $workflowCheck['status'] : 'error',
            'http_status' => is_int($workflowCheck['http_status'] ?? null) ? $workflowCheck['http_status'] : 503,
            'generated_at' => is_string($workflowCheck['generated_at'] ?? null) ? $workflowCheck['generated_at'] : null,
            'categories' => is_array($workflowCheck['categories'] ?? null) ? $workflowCheck['categories'] : [],
            'warning_checks' => self::stringList($workflowCheck['warning_checks'] ?? []),
            'error_checks' => self::stringList($workflowCheck['error_checks'] ?? []),
            'checks' => self::checkList($workflowCheck['checks'] ?? []),
            'routing_drains' => self::routingDrains($routingDrains),
        ];

        foreach (['blocked_by', 'message', 'remediation'] as $key) {
            if (array_key_exists($key, $workflowCheck)) {
                $manifest[$key] = $workflowCheck[$key];
            }
        }

        return $manifest;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }

    /**
     * @return list<array{name: string, status: string, category: ?string, message: ?string}>
     */
    private static function checkList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $checks = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $checks[] = [
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : 'unknown',
                'status' => is_string($entry['status'] ?? null) ? $entry['status'] : 'unknown',
                'category' => is_string($entry['category'] ?? null) ? $entry['category'] : null,
                'message' => is_string($entry['message'] ?? null) ? $entry['message'] : null,
            ];
        }

        return $checks;
    }

    /**
     * @return array{
     *     queues_with_drains: int,
     *     draining_build_id_count: int,
     *     active_worker_count: int,
     *     draining_worker_count: int,
     *     stale_worker_count: int,
     *     queues: list<array<string, mixed>>
     * }
     */
    private static function routingDrains(?array $value): array
    {
        $value ??= [];

        return [
            'queues_with_drains' => self::integer($value['queues_with_drains'] ?? 0),
            'draining_build_id_count' => self::integer($value['draining_build_id_count'] ?? 0),
            'active_worker_count' => self::integer($value['active_worker_count'] ?? 0),
            'draining_worker_count' => self::integer($value['draining_worker_count'] ?? 0),
            'stale_worker_count' => self::integer($value['stale_worker_count'] ?? 0),
            'queues' => self::routingDrainQueues($value['queues'] ?? []),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function routingDrainQueues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $queues = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $queues[] = [
                'namespace' => is_string($entry['namespace'] ?? null) ? $entry['namespace'] : null,
                'task_queue' => is_string($entry['task_queue'] ?? null) ? $entry['task_queue'] : 'default',
                'active_build_id_count' => self::integer($entry['active_build_id_count'] ?? 0),
                'draining_build_id_count' => self::integer($entry['draining_build_id_count'] ?? 0),
                'active_worker_count' => self::integer($entry['active_worker_count'] ?? 0),
                'draining_worker_count' => self::integer($entry['draining_worker_count'] ?? 0),
                'stale_worker_count' => self::integer($entry['stale_worker_count'] ?? 0),
                'latest_drained_at' => is_string($entry['latest_drained_at'] ?? null)
                    ? $entry['latest_drained_at']
                    : null,
                'build_ids' => self::buildIdEntries($entry['build_ids'] ?? []),
            ];
        }

        return $queues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildIdEntries(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entries[] = [
                'build_id' => is_string($entry['build_id'] ?? null) ? $entry['build_id'] : null,
                'rollout_status' => is_string($entry['rollout_status'] ?? null) ? $entry['rollout_status'] : 'unknown',
                'drain_intent' => is_string($entry['drain_intent'] ?? null) ? $entry['drain_intent'] : 'active',
                'drained_at' => is_string($entry['drained_at'] ?? null) ? $entry['drained_at'] : null,
                'active_worker_count' => self::integer($entry['active_worker_count'] ?? 0),
                'draining_worker_count' => self::integer($entry['draining_worker_count'] ?? 0),
                'stale_worker_count' => self::integer($entry['stale_worker_count'] ?? 0),
                'total_worker_count' => self::integer($entry['total_worker_count'] ?? 0),
                'runtimes' => self::stringList($entry['runtimes'] ?? []),
                'sdk_versions' => self::stringList($entry['sdk_versions'] ?? []),
                'last_heartbeat_at' => is_string($entry['last_heartbeat_at'] ?? null)
                    ? $entry['last_heartbeat_at']
                    : null,
                'first_seen_at' => is_string($entry['first_seen_at'] ?? null)
                    ? $entry['first_seen_at']
                    : null,
            ];
        }

        return $entries;
    }

    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
