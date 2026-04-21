<?php

namespace App\Providers;

use App\Auth\ConfiguredAuthProvider;
use App\Contracts\AuthProvider;
use App\Observers\WorkflowHistoryEventObserver;
use App\Observers\WorkflowTaskObserver;
use App\Support\RemoteScheduleStarter;
use App\Support\ServiceModeBusDispatcher;
use App\Support\WorkflowPackageApiFloor;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\ServiceProvider;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthProvider::class, function ($app): AuthProvider {
            $provider = config('server.auth.provider');

            if (is_string($provider) && trim($provider) !== '') {
                $instance = $app->make($provider);

                if (! $instance instanceof AuthProvider) {
                    throw new \InvalidArgumentException(sprintf(
                        'Configured auth provider [%s] must implement [%s].',
                        $provider,
                        AuthProvider::class,
                    ));
                }

                return $instance;
            }

            return $app->make(ConfiguredAuthProvider::class);
        });

        $this->app->singleton(ScheduleWorkflowStarter::class, RemoteScheduleStarter::class);
    }

    public function boot(): void
    {
        // Assert the installed workflow package meets the API floor this
        // server depends on. A stale cached install can otherwise produce
        // hard-to-diagnose fatals on /api/cluster/info or queue capability
        // failures in service mode.
        WorkflowPackageApiFloor::assert();

        if (config('server.mode') === 'service') {
            $inner = $this->app->make(BusDispatcher::class);
            $this->app->instance(BusDispatcher::class, new ServiceModeBusDispatcher($inner));

            // In service mode the standalone server does not dispatch workflow
            // or activity jobs onto the Laravel queue — external workers poll
            // HTTP for ready tasks instead. Defaulting task_dispatch_mode=poll
            // keeps Workflow\V2\Support\TaskDispatcher from running the queue
            // capability check, which would otherwise throw
            // UnsupportedBackendCapabilitiesException on backends the server
            // never actually hands a job to (and the same check happens on
            // every activity completion and workflow task, producing the
            // 500 → stale_attempt 409 retry pattern). Operators can still opt
            // out by setting WORKFLOW_V2_TASK_DISPATCH_MODE explicitly.
            //
            // The operator override is captured into server.task_dispatch_mode_override
            // at config-load time so `php artisan config:cache` bakes it in
            // (env() returns null at runtime once config is cached and dotenv
            // is no longer loaded).
            if (config('server.task_dispatch_mode_override') === null) {
                config(['workflows.v2.task_dispatch_mode' => 'poll']);
            }
        }

        WorkflowTask::observe(WorkflowTaskObserver::class);
        WorkflowHistoryEvent::observe(WorkflowHistoryEventObserver::class);
    }
}
