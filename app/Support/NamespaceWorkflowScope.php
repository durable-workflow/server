<?php

namespace App\Support;

use App\Models\WorkflowNamespaceWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

class NamespaceWorkflowScope
{
    public static function bind(string $namespace, string $workflowId, ?string $workflowType = null): WorkflowNamespaceWorkflow
    {
        return WorkflowNamespaceWorkflow::query()->updateOrCreate(
            ['workflow_instance_id' => $workflowId],
            [
                'namespace' => $namespace,
                'workflow_type' => $workflowType,
            ],
        );
    }

    public static function workflowBound(string $namespace, string $workflowId): bool
    {
        return WorkflowNamespaceWorkflow::query()
            ->where('namespace', $namespace)
            ->where('workflow_instance_id', $workflowId)
            ->exists();
    }

    public static function namespaceForWorkflow(string $workflowId): ?string
    {
        $namespace = WorkflowNamespaceWorkflow::query()
            ->where('workflow_instance_id', $workflowId)
            ->value('namespace');

        return is_string($namespace) && $namespace !== ''
            ? $namespace
            : null;
    }

    public static function bindLinkedChildWorkflow(WorkflowLink $link): ?WorkflowNamespaceWorkflow
    {
        if ($link->link_type !== 'child_workflow') {
            return null;
        }

        $namespace = self::namespaceForWorkflow((string) $link->parent_workflow_instance_id);
        $childWorkflowId = is_string($link->child_workflow_instance_id ?? null)
            ? $link->child_workflow_instance_id
            : null;

        if ($namespace === null || $childWorkflowId === null || $childWorkflowId === '') {
            return null;
        }

        return self::bind($namespace, $childWorkflowId);
    }

    public static function bindChildWorkflowLineage(WorkflowRunLineageEntry $entry): ?WorkflowNamespaceWorkflow
    {
        if ($entry->direction !== 'child' || $entry->link_type !== 'child_workflow') {
            return null;
        }

        $namespace = self::namespaceForWorkflow((string) $entry->workflow_instance_id);
        $childWorkflowId = is_string($entry->related_workflow_instance_id ?? null)
            ? $entry->related_workflow_instance_id
            : null;

        if ($namespace === null || $childWorkflowId === null || $childWorkflowId === '') {
            return null;
        }

        $workflowType = is_string($entry->related_workflow_type ?? null)
            ? $entry->related_workflow_type
            : null;

        return self::bind($namespace, $childWorkflowId, $workflowType);
    }

    public static function runQuery(string $namespace, string $workflowId): Builder
    {
        return WorkflowRun::query()
            ->select('workflow_runs.*')
            ->join('workflow_namespace_workflows as namespace_workflows', function ($join) use ($namespace) {
                $join->on('namespace_workflows.workflow_instance_id', '=', 'workflow_runs.workflow_instance_id')
                    ->where('namespace_workflows.namespace', '=', $namespace);
            })
            ->where('workflow_runs.workflow_instance_id', $workflowId);
    }

    public static function currentRun(string $namespace, string $workflowId): ?WorkflowRun
    {
        return self::runQuery($namespace, $workflowId)
            ->orderByDesc('workflow_runs.run_number')
            ->first();
    }

    public static function run(string $namespace, string $workflowId, string $runId): ?WorkflowRun
    {
        return self::runQuery($namespace, $workflowId)
            ->where('workflow_runs.id', $runId)
            ->first();
    }

    public static function runSummaryQuery(string $namespace): Builder
    {
        return WorkflowRunSummary::query()
            ->select('workflow_run_summaries.*')
            ->join('workflow_namespace_workflows as namespace_workflows', function ($join) use ($namespace) {
                $join->on('namespace_workflows.workflow_instance_id', '=', 'workflow_run_summaries.workflow_instance_id')
                    ->where('namespace_workflows.namespace', '=', $namespace);
            });
    }

    public static function taskQuery(string $namespace): Builder
    {
        return WorkflowTask::query()
            ->select('workflow_tasks.*')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
            ->join('workflow_namespace_workflows as namespace_workflows', function ($join) use ($namespace) {
                $join->on('namespace_workflows.workflow_instance_id', '=', 'workflow_runs.workflow_instance_id')
                    ->where('namespace_workflows.namespace', '=', $namespace);
            });
    }

    public static function task(string $namespace, string $taskId): ?WorkflowTask
    {
        return self::taskQuery($namespace)
            ->where('workflow_tasks.id', $taskId)
            ->first();
    }
}
