<?php

namespace App\Support;

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
    ) {}

    /**
     * Recover an expired lease using the package's WorkflowTask as the source
     * of truth for lease state.
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
            return;
        }

        if ($task->lease_expires_at === null || $task->lease_expires_at->gt(now())) {
            return;
        }

        $workflowRunId = $task->workflow_run_id;

        $workflowId = is_string($workflowRunId) && $workflowRunId !== ''
            ? WorkflowRun::query()->whereKey($workflowRunId)->value('workflow_instance_id')
            : null;

        if (! is_string($workflowId) || $workflowId === '' || ! is_string($workflowRunId) || $workflowRunId === '') {
            return;
        }

        $metadata = array_filter([
            'trigger' => 'expired_workflow_task_lease',
            'task_id' => $task->id,
            'lease_owner' => $task->lease_owner,
            'workflow_task_attempt' => is_int($task->attempt_count) ? (int) $task->attempt_count : null,
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
            // Repair is best-effort on the worker fence path.
        }
    }
}
