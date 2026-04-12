<?php

namespace App\Observers;

use App\Support\LongPollSignalStore;
use App\Support\WorkflowTaskLeaseRegistry;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;

class WorkflowTaskObserver
{
    public function created(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);
    }

    public function updated(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);
        $this->syncWorkflowTaskLease($task);
    }

    public function deleted(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);
        $this->syncWorkflowTaskLease($task, deleted: true);
    }

    private function syncWorkflowTaskLease(WorkflowTask $task, bool $deleted = false): void
    {
        if ($task->task_type !== TaskType::Workflow) {
            return;
        }

        /** @var WorkflowTaskLeaseRegistry $leases */
        $leases = app(WorkflowTaskLeaseRegistry::class);

        if ($deleted) {
            $leases->clearActiveLease($task->id);

            return;
        }

        $leases->syncTaskState($task);
    }
}
