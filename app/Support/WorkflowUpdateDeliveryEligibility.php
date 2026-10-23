<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

final class WorkflowUpdateDeliveryEligibility
{
    public static function allows(WorkerRegistration $worker, WorkflowRun $run, WorkflowTask $task): bool
    {
        $updateId = $task->payload['workflow_update_id'] ?? null;

        if ($updateId === null) {
            return true;
        }

        if (! is_string($updateId) || $updateId === ''
            || $worker->namespace !== $run->namespace
            || $task->namespace !== $run->namespace
            || $task->workflow_run_id !== $run->id
            || ! in_array('workflow_updates', $worker->capabilities ?? [], true)) {
            return false;
        }

        $contract = $worker->workflow_command_contracts[$run->workflow_type] ?? null;
        $updates = is_array($contract) ? ($contract['updates'] ?? null) : null;

        if (! is_array($updates)) {
            return false;
        }

        $update = WorkflowUpdate::query()->where('workflow_run_id', $run->id)
            ->where('status', UpdateStatus::Accepted->value)->find($updateId);

        return $update instanceof WorkflowUpdate
            && $update->id === $updateId
            && in_array($update->update_name, $updates, true);
    }
}
