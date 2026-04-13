<?php

namespace App\Providers;

use App\Observers\WorkflowHistoryEventObserver;
use App\Observers\WorkflowLinkObserver;
use App\Observers\WorkflowRunLineageEntryObserver;
use App\Observers\WorkflowTaskObserver;
use App\Support\ServiceModeBusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\ServiceProvider;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowTask;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (config('server.mode') === 'service') {
            $inner = $this->app->make(BusDispatcher::class);
            $this->app->instance(BusDispatcher::class, new ServiceModeBusDispatcher($inner));
        }

        WorkflowLink::observe(WorkflowLinkObserver::class);
        WorkflowRunLineageEntry::observe(WorkflowRunLineageEntryObserver::class);
        WorkflowTask::observe(WorkflowTaskObserver::class);
        WorkflowHistoryEvent::observe(WorkflowHistoryEventObserver::class);
    }
}
