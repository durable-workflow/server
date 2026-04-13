<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;

class NamespaceWorkflowScope
{
    public static function bind(string $namespace, string $workflowId, ?string $workflowType = null): void
    {
        // Backfill the native namespace column on the package's instance
        // when it was created without one (e.g., insertOrIgnore paths
        // that bypass model events).
        WorkflowInstance::query()
            ->whereKey($workflowId)
            ->whereNull('namespace')
            ->update(['namespace' => $namespace]);

        // Wake long-poll waiters so new tasks are picked up promptly.
        app(LongPollSignalStore::class)->signalWorkflowTaskQueuesForWorkflow(
            $workflowId,
            $namespace,
        );
    }

    public static function workflowBound(string $namespace, string $workflowId): bool
    {
        return WorkflowInstance::query()
            ->whereKey($workflowId)
            ->where('namespace', $namespace)
            ->exists();
    }

    public static function namespaceForWorkflow(string $workflowId): ?string
    {
        $namespace = WorkflowInstance::query()
            ->whereKey($workflowId)
            ->value('namespace');

        return is_string($namespace) && $namespace !== ''
            ? $namespace
            : null;
    }

    public static function bindLinkedChildWorkflow(WorkflowLink $link): void
    {
        if ($link->link_type !== 'child_workflow') {
            return;
        }

        $namespace = self::namespaceForWorkflow((string) $link->parent_workflow_instance_id);
        $childWorkflowId = is_string($link->child_workflow_instance_id ?? null)
            ? $link->child_workflow_instance_id
            : null;

        if ($namespace === null || $childWorkflowId === null || $childWorkflowId === '') {
            return;
        }

        self::bind($namespace, $childWorkflowId);
    }

    public static function bindChildWorkflowLineage(WorkflowRunLineageEntry $entry): void
    {
        if ($entry->direction !== 'child' || $entry->link_type !== 'child_workflow') {
            return;
        }

        $namespace = self::namespaceForWorkflow((string) $entry->workflow_instance_id);
        $childWorkflowId = is_string($entry->related_workflow_instance_id ?? null)
            ? $entry->related_workflow_instance_id
            : null;

        if ($namespace === null || $childWorkflowId === null || $childWorkflowId === '') {
            return;
        }

        self::bind($namespace, $childWorkflowId);
    }

    public static function runQuery(string $namespace, string $workflowId): Builder
    {
        return WorkflowRun::query()
            ->where('workflow_runs.namespace', $namespace)
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
            ->where('workflow_run_summaries.namespace', $namespace);
    }

    public static function taskQuery(string $namespace): Builder
    {
        return WorkflowTask::query()
            ->where('workflow_tasks.namespace', $namespace);
    }

    public static function task(string $namespace, string $taskId): ?WorkflowTask
    {
        return WorkflowTask::query()
            ->where('namespace', $namespace)
            ->whereKey($taskId)
            ->first();
    }
}
