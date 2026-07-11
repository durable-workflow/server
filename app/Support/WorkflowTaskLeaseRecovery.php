<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Fence and recover a task whose lease owner stopped heartbeating before
     * the task lease itself expired. The row lock makes the lease attempt the
     * hand-off boundary: either the old worker completes first, or recovery
     * expires that attempt and every later completion is rejected.
     */
    public function recoverAbandonedTaskLease(
        Request $request,
        string $namespace,
        WorkflowTask $candidate,
    ): bool {
        $task = DB::transaction(function () use ($namespace, $candidate): ?WorkflowTask {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()->whereKey($candidate->id)->lockForUpdate()->first();

            if (! $task instanceof WorkflowTask
                || $task->task_type !== TaskType::Workflow
                || $task->status !== TaskStatus::Leased
                || $task->lease_owner !== $candidate->lease_owner
                || $task->attempt_count !== $candidate->attempt_count
            ) {
                return null;
            }

            $owner = WorkerRegistration::query()
                ->where('namespace', $namespace)
                ->where('worker_id', $task->lease_owner)
                ->first();

            if ($owner instanceof WorkerRegistration && WorkerPollFence::isFresh($owner)) {
                return null;
            }

            if ($owner instanceof WorkerRegistration) {
                $owner->forceFill(['status' => WorkerRegistration::STATUS_SUPERSEDED])->save();
            }

            $task->forceFill(['lease_expires_at' => now()])->save();

            return $task->refresh();
        });

        if (! $task instanceof WorkflowTask) {
            return false;
        }

        $this->recoverExpiredTaskLease($request, $namespace, $task);

        return true;
    }
}
