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

        if ($task->status !== TaskStatus::Leased) {
            $this->leases->syncTaskState($task);

            return;
        }

        if ($task->lease_expires_at === null || $task->lease_expires_at->gt(now())) {
            $this->leases->syncTaskState($task);

            return;
        }

        $workflowRunId = is_string($lease->workflow_run_id) && $lease->workflow_run_id !== ''
            ? $lease->workflow_run_id
            : $task->workflow_run_id;

        $workflowId = is_string($lease->workflow_instance_id) && $lease->workflow_instance_id !== ''
            ? $lease->workflow_instance_id
            : WorkflowRun::query()->whereKey($workflowRunId)->value('workflow_instance_id');

        if (! is_string($workflowId) || $workflowId === '' || ! is_string($workflowRunId) || $workflowRunId === '') {
            return;
        }

        try {
            WorkflowStub::loadSelection($workflowId, $workflowRunId)
                ->withCommandContext($this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'repair',
                    metadata: array_filter([
                        'trigger' => 'expired_workflow_task_lease',
                        'task_id' => $lease->task_id,
                        'lease_owner' => $lease->lease_owner,
                        'workflow_task_attempt' => $lease->workflow_task_attempt,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                ))
                ->attemptRepair();
        } catch (Throwable) {
            // Repair is best-effort on the worker fence path. If the run cannot be
            // selected here, the caller still gets the lease-expired fence response
            // and the normal repair loop remains available.
        }
    }
}
