<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class WorkflowTaskFailureMetrics
{
    public const METRIC_NAME = 'dw_workflow_task_consecutive_failures';

    private const DEFAULT_WORKFLOW_TYPE_LIMIT = 20;

    private const MAX_WORKFLOW_TYPE_LIMIT = 100;

    /**
     * @return array{
     *     max_consecutive_failures: int,
     *     failed_task_count: int,
     *     workflow_type_count: int,
     *     workflow_type_limit: int,
     *     workflow_types_truncated: bool,
     *     suppressed_workflow_type_count: int,
     *     suppressed_failed_task_count: int,
     *     label_cardinality_policy: array<string, mixed>,
     *     by_workflow_type: list<array{workflow_type: string, max_consecutive_failures: int, failed_task_count: int}>
     * }
     */
    public static function snapshot(string $namespace): array
    {
        $limit = self::workflowTypeLimit();
        $baseQuery = self::failedWorkflowTaskQuery($namespace);
        $taskTable = (new WorkflowTask)->getTable();
        $runTable = (new WorkflowRun)->getTable();

        $maxFailures = (int) ((clone $baseQuery)->max($taskTable.'.attempt_count') ?? 0);
        $failedTaskCount = (int) (clone $baseQuery)->count($taskTable.'.id');
        $workflowTypeCount = (int) (clone $baseQuery)
            ->distinct()
            ->count($runTable.'.workflow_type');

        $rows = (clone $baseQuery)
            ->select($runTable.'.workflow_type')
            ->selectRaw('MAX('.$taskTable.'.attempt_count) as max_consecutive_failures')
            ->selectRaw('COUNT(*) as failed_task_count')
            ->groupBy($runTable.'.workflow_type')
            ->orderByDesc('max_consecutive_failures')
            ->orderBy($runTable.'.workflow_type')
            ->limit($limit)
            ->get();

        $series = [];
        $reportedFailedTasks = 0;

        foreach ($rows as $row) {
            $failedTasks = (int) $row->failed_task_count;
            $reportedFailedTasks += $failedTasks;

            $series[] = [
                'workflow_type' => (string) $row->workflow_type,
                'max_consecutive_failures' => (int) $row->max_consecutive_failures,
                'failed_task_count' => $failedTasks,
            ];
        }

        return [
            'max_consecutive_failures' => $maxFailures,
            'failed_task_count' => $failedTaskCount,
            'workflow_type_count' => $workflowTypeCount,
            'workflow_type_limit' => $limit,
            'workflow_types_truncated' => $workflowTypeCount > count($series),
            'suppressed_workflow_type_count' => max(0, $workflowTypeCount - count($series)),
            'suppressed_failed_task_count' => max(0, $failedTaskCount - $reportedFailedTasks),
            'label_cardinality_policy' => [
                'namespace' => 'request_scope_not_label',
                'workflow_type' => [
                    'limit' => $limit,
                    'selection' => 'top_by_max_consecutive_failures_then_name',
                ],
            ],
            'by_workflow_type' => $series,
        ];
    }

    private static function workflowTypeLimit(): int
    {
        $configured = config('server.metrics.workflow_task_failure_type_limit', self::DEFAULT_WORKFLOW_TYPE_LIMIT);
        $limit = is_numeric($configured) ? (int) $configured : self::DEFAULT_WORKFLOW_TYPE_LIMIT;

        return max(1, min(self::MAX_WORKFLOW_TYPE_LIMIT, $limit));
    }

    /**
     * First failed attempt is reported as one consecutive failure. A retried
     * task that fails again keeps increasing the package attempt counter.
     */
    private static function failedWorkflowTaskQuery(string $namespace): Builder
    {
        $taskTable = (new WorkflowTask)->getTable();
        $runTable = (new WorkflowRun)->getTable();

        return WorkflowTask::query()
            ->join($runTable, $runTable.'.id', '=', $taskTable.'.workflow_run_id')
            ->where($taskTable.'.namespace', $namespace)
            ->where($taskTable.'.task_type', TaskType::Workflow->value)
            ->where($taskTable.'.status', TaskStatus::Failed->value)
            ->where($taskTable.'.attempt_count', '>', 0);
    }
}
