<?php

namespace App\Support;

use App\Models\WorkflowTaskProtocolLease;
use Illuminate\Http\Request;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class WorkflowTaskLeaseRecovery
{
    public function __construct(
        private readonly WorkflowCommandContextFactory $commandContexts,
        private readonly WorkflowTaskLeaseRegistry $leases,
    ) {}

    /**
     * Recover an expired lease using the package's WorkflowTask as the source
     * of truth for lease state. The mirror table row is used only for metadata
     * enrichment (attempt counter, cached workflow IDs) when available.
     */
    public function recoverExpiredTaskLease(
        Request $request,
        string $namespace,
        WorkflowTask $task,
    ): void {
        if ($task->task_type !== TaskType::Workflow) {
            return;
        }

        if ($task->status !== TaskStatus::Leased) {
            $this->leases->syncTaskState($task);

            return;
        }

        if ($task->lease_expires_at === null || $task->lease_expires_at->gt(now())) {
            $this->leases->syncTaskState($task);

            return;
        }

        $lease = $this->leases->activeLease($namespace, $task->id);

        $workflowRunId = $task->workflow_run_id;

        if ($lease instanceof WorkflowTaskProtocolLease && is_string($lease->workflow_run_id) && $lease->workflow_run_id !== '') {
            $workflowRunId = $lease->workflow_run_id;
        }

        $workflowId = $lease instanceof WorkflowTaskProtocolLease
            && is_string($lease->workflow_instance_id)
            && $lease->workflow_instance_id !== ''
                ? $lease->workflow_instance_id
                : WorkflowRun::query()->whereKey($workflowRunId)->value('workflow_instance_id');

        if (! is_string($workflowId) || $workflowId === '' || ! is_string($workflowRunId) || $workflowRunId === '') {
            return;
        }

        $metadata = array_filter([
            'trigger' => 'expired_workflow_task_lease',
            'task_id' => $task->id,
            'lease_owner' => $task->lease_owner,
            'workflow_task_attempt' => $lease?->workflow_task_attempt
                ?? (is_int($task->attempt_count) ? (int) $task->attempt_count : null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            WorkflowStub::loadSelection($workflowId, $workflowRunId)
                ->withCommandContext($this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'repair',
                    metadata: $metadata,
                ))
                ->attemptRepair();
        } catch (Throwable) {
            // Repair is best-effort on the worker fence path. If the run cannot be
            // selected here, the caller still gets the lease-expired fence response
            // and the normal repair loop remains available.
        }
    }

    /**
     * Recover an expired lease from a mirror table row. This delegates to
     * recoverExpiredTaskLease() after resolving the WorkflowTask from the
     * package's table. Retained for callers that already hold a lease (e.g.
     * the ownership guard on complete/heartbeat/fail).
     */
    public function recoverExpiredLease(
        Request $request,
        string $namespace,
        WorkflowTaskProtocolLease $lease,
    ): void {
        $task = NamespaceWorkflowScope::task($namespace, $lease->task_id);

        if (! $task instanceof WorkflowTask || $task->task_type !== TaskType::Workflow) {
            $this->leases->clearActiveLease($lease->task_id);

            return;
        }

        $this->recoverExpiredTaskLease($request, $namespace, $task);
    }
}
