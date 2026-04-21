<?php

namespace App\Support;

use Workflow\V2\Support\OperatorMetrics;

final class ProjectionDriftMetrics
{
    public const METRIC_NAME = 'dw_projection_drift_total';

    private const TABLES = [
        'run_summaries',
        'run_waits',
        'run_timeline_entries',
        'run_timer_entries',
        'run_lineage_entries',
    ];

    /**
     * @return array{
     *     total: int,
     *     table_count: int,
     *     tables_with_drift: int,
     *     scope: string,
     *     label_cardinality_policy: array<string, mixed>,
     *     by_table: list<array{table: string, needs_rebuild: int}>
     * }
     */
    public static function snapshot(): array
    {
        $projectionMetrics = OperatorMetrics::snapshot()['projections'] ?? [];
        $series = [];
        $total = 0;
        $tablesWithDrift = 0;

        foreach (self::TABLES as $table) {
            $needsRebuild = (int) ($projectionMetrics[$table]['needs_rebuild'] ?? 0);
            $total += $needsRebuild;

            if ($needsRebuild > 0) {
                $tablesWithDrift++;
            }

            $series[] = [
                'table' => $table,
                'needs_rebuild' => $needsRebuild,
            ];
        }

        return [
            'total' => $total,
            'table_count' => count(self::TABLES),
            'tables_with_drift' => $tablesWithDrift,
            'scope' => 'server',
            'label_cardinality_policy' => [
                'namespace' => 'server_scope_no_label',
                'table' => [
                    'values' => self::TABLES,
                    'selection' => 'fixed_projection_table_inventory',
                ],
            ],
            'by_table' => $series,
        ];
    }
}
