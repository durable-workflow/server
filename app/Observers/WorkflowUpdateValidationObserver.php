<?php

namespace App\Observers;

use App\Models\WorkflowUpdateValidationTask;
use LogicException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowUpdate;

final class WorkflowUpdateValidationObserver
{
    public function creating(WorkflowUpdate $update): void
    {
        /** @var WorkflowCommand|null $command */
        $command = WorkflowCommand::query()->find($update->workflow_command_id);
        $taskId = data_get($command?->context, 'server.metadata.update_validation_task_id');

        if (! is_string($taskId) || trim($taskId) === '') {
            return;
        }

        $inputHash = data_get($command?->context, 'server.metadata.update_validation_input_hash');
        $approved = is_string($inputHash)
            && WorkflowUpdateValidationTask::query()
                ->whereKey($taskId)
                ->where('workflow_run_id', $update->workflow_run_id)
                ->where('input_hash', $inputHash)
                ->where('status', WorkflowUpdateValidationTask::STATUS_APPROVED)
                ->exists();

        if (! $approved) {
            throw new LogicException('A workflow update cannot reuse an unapproved or stale validation task.');
        }

        $update->setAttribute($update->getKeyName(), $taskId);
    }
}
