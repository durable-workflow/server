<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\NamespaceIdentity;

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
        return NamespaceIdentity::constrain(WorkflowInstance::query(), $namespace)
            ->whereKey($workflowId)
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

    public static function runQuery(string $namespace, string $workflowId): Builder
    {
        return self::ownedRunQuery(WorkflowRun::query(), $namespace)
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
        // Cached metadata is not authority for a legacy import's owner.
        // Keep filtering and pagination inside the canonical run boundary.
        return NamespaceIdentity::constrain(WorkflowRunSummary::query(), $namespace)
            ->whereHas('run', static fn (Builder $run) => self::ownedRunQuery($run, $namespace)
                ->whereColumn('workflow_runs.workflow_instance_id', 'workflow_run_summaries.workflow_instance_id'));
    }

    public static function ownedRunQuery(Builder $query, string $namespace): Builder
    {
        return NamespaceIdentity::constrain($query, $namespace)
            ->whereHas('instance', static fn (Builder $instance) => NamespaceIdentity::constrain($instance, $namespace));
    }

    public static function taskQuery(string $namespace): Builder
    {
        return NamespaceIdentity::constrain(WorkflowTask::query(), $namespace)
            ->whereHas('run', static fn (Builder $run) => self::ownedRunQuery($run, $namespace));
    }

    public static function task(string $namespace, string $taskId): ?WorkflowTask
    {
        return self::taskQuery($namespace)
            ->whereKey($taskId)
            ->first();
    }

    /** Acquire these locks inside the caller's mutation transaction. */
    public static function lockTaskForMutation(string $namespace, string $taskId): ?WorkflowTask
    {
        // Repair and cancellation admission lock the run before its tasks.
        // An unlocked lookup discovers the immutable run ID without holding a
        // task lock while waiting for that run. Revalidate it under the lock.
        $runId = self::taskQuery($namespace)->whereKey($taskId)->value('workflow_run_id');
        if (! is_string($runId)) {
            return null;
        }

        $run = self::ownedRunQuery(WorkflowRun::query(), $namespace)
            ->whereKey($runId)->lockForUpdate()->first();
        if (! $run instanceof WorkflowRun) {
            return null;
        }

        return self::taskQuery($namespace)->whereKey($taskId)
            ->where('workflow_run_id', $runId)->lockForUpdate()->first();
    }
}
