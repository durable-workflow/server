<?php

namespace App\Support;

final class CoordinationHealthContract
{
    public const SCHEMA = 'durable-workflow.v2.coordination-health.contract';

    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $workflowCheck
     * @return array<string, mixed>
     */
    public static function manifest(array $workflowCheck): array
    {
        return [
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
        ];
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
}
