<?php

namespace Tests\Unit;

use App\Support\BoundedMetricPolicy;
use InvalidArgumentException;
use Tests\TestCase;

class BoundedMetricPolicyTest extends TestCase
{
    public function test_label_set_rejects_metrics_without_bounded_growth_policy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Metric dw_unreviewed_metric is missing from config/dw-bounded-growth.php.');

        BoundedMetricPolicy::labelSet('dw_unreviewed_metric');
    }

    public function test_label_set_rejects_runtime_dimensions_missing_from_policy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Metric dw_projection_drift_total disclosed undeclared runtime dimension(s): workflow_type.',
        );

        BoundedMetricPolicy::labelSet('dw_projection_drift_total', [
            'table' => [
                'values' => ['run_summaries'],
            ],
            'workflow_type' => [
                'selection' => 'all',
            ],
        ]);
    }

    public function test_label_set_merges_declared_cardinality_policy_with_runtime_metadata(): void
    {
        $labelSet = BoundedMetricPolicy::labelSet('dw_projection_drift_total', [
            'table' => [
                'values' => ['run_summaries', 'run_waits'],
                'selection' => 'fixed_projection_table_inventory',
            ],
        ]);

        $this->assertSame([
            'namespace' => 'server_scope_no_label',
            'table' => [
                'cardinality_class' => 'finite_projection_table_inventory',
                'values' => ['run_summaries', 'run_waits'],
                'selection' => 'fixed_projection_table_inventory',
            ],
        ], $labelSet);
    }
}
