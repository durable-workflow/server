<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

class SystemMetricsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    public function test_system_metrics_reports_bounded_workflow_task_failure_series(): void
    {
        config(['server.metrics.workflow_task_failure_type_limit' => 2]);

        $this->createWorkflowTaskMetricRow('tests.metric-middle', 3);
        $this->createWorkflowTaskMetricRow('tests.metric-middle', 1);
        $this->createWorkflowTaskMetricRow('tests.metric-low', 2);
        $this->createWorkflowTaskMetricRow('tests.metric-high', 7);
        $this->createWorkflowTaskMetricRow('tests.other-namespace', 9, 'other');
        $this->createWorkflowTaskMetricRow('tests.ready-task', 99, 'default', TaskStatus::Ready);

        $response = $this->getJson('/api/system/metrics', $this->controlPlaneHeadersWithWorkerProtocol());

        $response->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.max_consecutive_failures', 7)
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.failed_task_count', 4)
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.workflow_type_count', 3)
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.workflow_type_limit', 2)
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.workflow_types_truncated', true)
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.suppressed_workflow_type_count', 1)
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.suppressed_failed_task_count', 1)
            ->assertJsonPath(
                'cardinality.metric_label_sets.dw_workflow_task_consecutive_failures.workflow_type.selection',
                'top_by_max_consecutive_failures_then_name',
            );

        $policy = require base_path('config/dw-bounded-growth.php');
        $this->assertSame(
            array_keys($policy['metrics']['dw_workflow_task_consecutive_failures']['dimensions']),
            array_keys($response->json('cardinality.metric_label_sets.dw_workflow_task_consecutive_failures')),
        );

        $this->assertSame([
            [
                'workflow_type' => 'tests.metric-high',
                'max_consecutive_failures' => 7,
                'failed_task_count' => 1,
            ],
            [
                'workflow_type' => 'tests.metric-middle',
                'max_consecutive_failures' => 3,
                'failed_task_count' => 2,
            ],
        ], $response->json('metrics.dw_workflow_task_consecutive_failures.by_workflow_type'));
    }

    public function test_system_metrics_reports_projection_drift_by_fixed_table_inventory(): void
    {
        $this->createWorkflowTaskMetricRow('tests.projection-drift', 1);

        $response = $this->getJson('/api/system/metrics', $this->controlPlaneHeadersWithWorkerProtocol());

        $response->assertOk()
            ->assertJsonPath('metrics.dw_projection_drift_total.total', 1)
            ->assertJsonPath('metrics.dw_projection_drift_total.table_count', 5)
            ->assertJsonPath('metrics.dw_projection_drift_total.tables_with_drift', 1)
            ->assertJsonPath('metrics.dw_projection_drift_total.scope', 'server')
            ->assertJsonPath(
                'cardinality.metric_label_sets.dw_projection_drift_total.table.selection',
                'fixed_projection_table_inventory',
            );

        $policy = require base_path('config/dw-bounded-growth.php');
        $this->assertSame(
            array_keys($policy['metrics']['dw_projection_drift_total']['dimensions']),
            array_keys($response->json('cardinality.metric_label_sets.dw_projection_drift_total')),
        );

        $this->assertSame([
            [
                'table' => 'run_summaries',
                'needs_rebuild' => 1,
            ],
            [
                'table' => 'run_waits',
                'needs_rebuild' => 0,
            ],
            [
                'table' => 'run_timeline_entries',
                'needs_rebuild' => 0,
            ],
            [
                'table' => 'run_timer_entries',
                'needs_rebuild' => 0,
            ],
            [
                'table' => 'run_lineage_entries',
                'needs_rebuild' => 0,
            ],
        ], $response->json('metrics.dw_projection_drift_total.by_table'));
    }

    private function createWorkflowTaskMetricRow(
        string $workflowType,
        int $attemptCount,
        string $namespace = 'default',
        TaskStatus $status = TaskStatus::Failed,
    ): void {
        $runId = (string) Str::ulid();

        WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => (string) Str::ulid(),
            'run_number' => 1,
            'workflow_class' => 'Tests\\Fixtures\\MetricWorkflow',
            'workflow_type' => $workflowType,
            'namespace' => $namespace,
            'status' => RunStatus::Running->value,
        ]);

        WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $runId,
            'namespace' => $namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => $status->value,
            'queue' => 'metrics',
            'attempt_count' => $attemptCount,
        ]);
    }
}
