<?php

namespace App\Providers;

use App\Observers\WorkflowHistoryEventObserver;
use App\Observers\WorkflowLinkObserver;
use App\Observers\WorkflowRunLineageEntryObserver;
use App\Observers\WorkflowTaskObserver;
use Illuminate\Support\ServiceProvider;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowTask;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        WorkflowLink::observe(WorkflowLinkObserver::class);
        WorkflowRunLineageEntry::observe(WorkflowRunLineageEntryObserver::class);
        WorkflowTask::observe(WorkflowTaskObserver::class);
        WorkflowHistoryEvent::observe(WorkflowHistoryEventObserver::class);
    }
}
