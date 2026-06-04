<?php

namespace App\Observers;

use App\Support\LongPollSignalStore;
use App\Support\ServiceModeTimerDispatcher;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;

class WorkflowTaskObserver
{
    public function created(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);
        $this->dispatchServiceModeTimer($task);
    }

    public function updated(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);

        if ($this->timerBecameDispatchable($task)) {
            $this->dispatchServiceModeTimer($task);
        }
    }

    public function deleted(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);
    }

    private function timerBecameDispatchable(WorkflowTask $task): bool
    {
        if (! $this->isReadyTimerTask($task)) {
            return false;
        }

        return $task->wasChanged('status')
            || $task->wasChanged('available_at')
            || $task->wasChanged('repair_count');
    }

    private function dispatchServiceModeTimer(WorkflowTask $task): void
    {
        app(ServiceModeTimerDispatcher::class)->dispatch($task);
    }

    private function isReadyTimerTask(WorkflowTask $task): bool
    {
        return ($task->task_type === TaskType::Timer || $task->task_type === TaskType::Timer->value)
            && ($task->status === TaskStatus::Ready || $task->status === TaskStatus::Ready->value);
    }
}
