<?php

namespace App\Providers;

use App\Auth\ConfiguredAuthProvider;
use App\Contracts\AuthProvider;
use App\Contracts\RuntimeSignalControlPlane;
use App\Database\GuardedMariaDbConnector;
use App\Database\GuardedMysqlConnector;
use App\Database\GuardedPostgresConnector;
use App\Database\GuardedSqliteConnection;
use App\Database\GuardedSqliteConnector;
use App\Models\WorkerRegistration;
use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use App\Observers\NamespaceDurableStateObserver;
use App\Observers\WorkflowHistoryEventObserver;
use App\Observers\WorkflowTaskObserver;
use App\Observers\WorkflowUpdateValidationObserver;
use App\Support\ExternalWorkflowUpdateAdmission;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\RemoteScheduleStarter;
use App\Support\ServerPollingCache;
use App\Support\ServerWorkflowControlPlane;
use App\Support\ServiceCallBoundary;
use App\Support\ServiceModeBusDispatcher;
use App\Support\SharedServiceBoundaryPolicy;
use App\Support\StoragePressure;
use App\Support\ValidatedExternalWorkflowUpdateAdmission;
use App\Support\WorkflowMemoRollingCompatibility;
use App\Support\WorkflowPackageApiFloor;
use App\Support\WorkflowTaskLeaseConfiguration;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\ServiceBoundaryAuditRecorder;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('sqlite', static fn ($pdo, $database, $prefix, $config) => new GuardedSqliteConnection($pdo, $database, $prefix, $config));
        // Install before package boot callbacks or commands can open a database.
        foreach ([
            'sqlite' => GuardedSqliteConnector::class,
            'mysql' => GuardedMysqlConnector::class,
            'mariadb' => GuardedMariaDbConnector::class,
            'pgsql' => GuardedPostgresConnector::class,
        ] as $driver => $connector) {
            $this->app->bind('db.connector.'.$driver, $connector);
        }

        // Workers can catch ordinary backend exceptions and keep looping.
        // Refuse ownership before entering those loops or destructive commands.
        $this->app->make('events')->listen(CommandStarting::class, static function (CommandStarting $event): void {
            if (in_array($event->command, [
                'server:bootstrap', 'db:seed', 'db:wipe',
                'queue:work', 'queue:listen',
                'schedule:run', 'schedule:work', 'schedule:evaluate',
                'workflow:v2:backfill-command-contracts', 'workflow:v2:schedule-tick',
                'workflow:v2:rebuild-projections', 'workflow:v2:history-import', 'workflow:v2:repair-pass',
            ], true) || preg_match('/^migrate(?::|$)/', $event->command) === 1) {
                $target = $event->input->getParameterOption('--database', null, true);
                $connection = DB::connection(is_string($target) && $target !== '' ? $target : null);
                $connection->getPdo();
                if ($connection->hasDirectConnection()) {
                    DB::connection($connection->getName().'::direct')->getPdo();
                }
            }
        });
        $this->app->booted(function (): void {
            if ($this->app->runningInConsole()) {
                // Laravel suppresses these events in APP_ENV=testing. Ownership
                // safety must still cover SQLite db:wipe's file-truncate path,
                // which can otherwise bypass the connector entirely.
                $this->app->make(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
            }
        });

        $this->normalizeRedisPorts();

        // Register before package boot callbacks can enqueue maintenance work.
        $this->app->make('events')->listen(Looping::class,
            fn (): ?bool => $this->app->make(StoragePressure::class)->acceptsNewWork() ? null : false);

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
        $this->app->singleton(DefaultWorkflowControlPlane::class);
        $this->app->singleton(ServerWorkflowControlPlane::class);
        $this->app->singleton(
            WorkflowControlPlane::class,
            fn ($app): ServerWorkflowControlPlane => $app->make(ServerWorkflowControlPlane::class),
        );
        $this->app->singleton(
            RuntimeSignalControlPlane::class,
            fn ($app): ServerWorkflowControlPlane => $app->make(ServerWorkflowControlPlane::class),
        );
        $this->app->bind(
            ExternalWorkflowUpdateAdmission::class,
            ValidatedExternalWorkflowUpdateAdmission::class,
        );
        $this->app->singleton(NamespaceExternalPayloadStorage::class);
        $this->app->singleton(
            ExternalPayloadStoragePolicy::class,
            fn ($app): NamespaceExternalPayloadStorage => $app->make(NamespaceExternalPayloadStorage::class),
        );

        // Cross-namespace service-call boundary. The server's
        // service_boundary config block layers on top of the workflow
        // package's defaults; merge them so operators can tune
        // namespace, rate, concurrency, and circuit-break policy from
        // the server image's environment without forking the workflow
        // package config. Configured admission budgets use shared cache so
        // every API process observes the same counters.
        $this->app->singleton(ServiceBoundaryPolicy::class, function ($app): ServiceBoundaryPolicy {
            $rules = array_replace_recursive(
                (array) config('workflows.v2.service_boundary.rules', []),
                (array) config('server.service_boundary.rules', []),
            );

            return new SharedServiceBoundaryPolicy(
                $app->make(ServerPollingCache::class),
                $rules,
            );
        });

        $this->app->singleton(
            ServiceControlPlane::class,
            fn ($app): ServiceControlPlane => new DefaultServiceControlPlane(
                $app->make(WorkflowControlPlane::class),
                $app->make(ServiceBoundaryPolicy::class),
            ),
        );

        $this->app->singleton(ServiceBoundaryAuditRecorder::class);

        $this->app->singleton(ServiceCallBoundary::class);
    }

    private function normalizeRedisPorts(): void
    {
        foreach (['default', 'cache'] as $connection) {
            $key = "database.redis.{$connection}.port";
            $port = config($key);

            if (is_numeric($port)) {
                config([$key => (int) $port]);
            }
        }
    }

    public function boot(): void
    {
        // Assert the installed workflow package meets the API floor this
        // server depends on. A stale cached install can otherwise produce
        // hard-to-diagnose fatals on /api/cluster/info or queue capability
        // failures in service mode.
        WorkflowPackageApiFloor::assert();

        WorkflowTaskLeaseConfiguration::apply();
        WorkflowMemoRollingCompatibility::register();

        config([
            'workflows.v2.fleet.validation_mode' => config('server.fleet_validation_mode', 'warn'),
        ]);

        if (config('server.mode') === 'service') {
            // By default the standalone server does not dispatch workflow
            // or activity jobs onto the Laravel queue — external workers poll
            // HTTP for ready tasks instead. Defaulting task_dispatch_mode=poll
            // keeps Workflow\V2\Support\TaskDispatcher from running the queue
            // capability check, which would otherwise throw
            // UnsupportedBackendCapabilitiesException on backends the server
            // never actually hands a job to (and the same check happens on
            // every activity completion and workflow task, producing the
            // 500 → stale_attempt 409 retry pattern). Operators can still opt
            // out by setting DW_TASK_DISPATCH_MODE (or its legacy
            // WORKFLOW_V2_TASK_DISPATCH_MODE alias) explicitly.
            //
            // The operator override is captured into server.task_dispatch_mode_override
            // at config-load time so `php artisan config:cache` bakes it in
            // (env() returns null at runtime once config is cached and dotenv
            // is no longer loaded).
            $taskDispatchMode = config('server.task_dispatch_mode_override') ?? 'poll';
            config(['workflows.v2.task_dispatch_mode' => $taskDispatchMode]);

            if ($taskDispatchMode === 'poll') {
                $inner = $this->app->make(BusDispatcher::class);
                $this->app->instance(BusDispatcher::class, new ServiceModeBusDispatcher($inner));
            }
        }

        WorkflowTask::observe(WorkflowTaskObserver::class);
        WorkflowHistoryEvent::observe(WorkflowHistoryEventObserver::class);
        WorkflowUpdate::observe(WorkflowUpdateValidationObserver::class);
        WorkflowInstance::observe(NamespaceDurableStateObserver::class);
        WorkflowRun::observe(NamespaceDurableStateObserver::class);
        WorkflowSchedule::observe(NamespaceDurableStateObserver::class);
        WorkflowScheduleHistoryEvent::observe(NamespaceDurableStateObserver::class);
        WorkerRegistration::observe(NamespaceDurableStateObserver::class);
        WorkflowHistoryEvent::observe(NamespaceDurableStateObserver::class);
        WorkflowTask::observe(NamespaceDurableStateObserver::class);
        WorkflowTimer::observe(NamespaceDurableStateObserver::class);
        WorkflowRunWait::observe(NamespaceDurableStateObserver::class);
        WorkflowCommand::observe(NamespaceDurableStateObserver::class);
        WorkflowDurableStream::observe(NamespaceDurableStateObserver::class);
        WorkflowDurableStreamItem::observe(NamespaceDurableStateObserver::class);
        WorkflowInboundStream::observe(NamespaceDurableStateObserver::class);
        WorkflowInboundStreamItem::observe(NamespaceDurableStateObserver::class);
    }
}
