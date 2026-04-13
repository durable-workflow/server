<?php

namespace App\Observers;

use App\Support\LongPollSignalStore;
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
    }

    public function deleted(WorkflowTask $task): void
    {
        app(LongPollSignalStore::class)->signalTask($task);
    }
}
