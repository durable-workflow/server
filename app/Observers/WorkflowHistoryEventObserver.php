<?php

namespace App\Observers;

use App\Support\LongPollSignalStore;
use Workflow\V2\Models\WorkflowHistoryEvent;

class WorkflowHistoryEventObserver
{
    public function created(WorkflowHistoryEvent $event): void
    {
        app(LongPollSignalStore::class)->signalHistoryEvent($event);
    }
}
