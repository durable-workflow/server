<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ActivityTaskController;
use App\Http\Controllers\Api\BridgeAdapterController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\EmbeddedV2ImportController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\LegacyV1ProjectionController;
use App\Http\Controllers\Api\MessageStreamController;
use App\Http\Controllers\Api\NamespaceController;
use App\Http\Controllers\Api\RuntimeExternalPayloadController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SearchAttributeController;
use App\Http\Controllers\Api\ServiceCatalogController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskQueueController;
use App\Http\Controllers\Api\WorkerController;
use App\Http\Controllers\Api\WorkerDeregistrationController;
use App\Http\Controllers\Api\WorkerManagementController;
use App\Http\Controllers\Api\WorkerSessionController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\WorkflowStreamController;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\ControlPlaneVersionResolver;
use App\Http\Middleware\EnforceNamespaceRequestAdmission;
use App\Http\Middleware\EnforceStandaloneActivityHostQuota;
use App\Http\Middleware\NamespaceResolver;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireTopologyRoles;
use App\Http\Middleware\RequireWorkflowBootstrapReady;
use App\Http\Middleware\RuntimeExternalPayloadTransport;
use App\Http\Middleware\WorkerProtocolVersionResolver;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Durable Workflow Server API
|--------------------------------------------------------------------------
|
| Language-neutral control-plane and worker-plane APIs. All endpoints use
| plain HTTP + JSON. Workers interact via long-poll task leasing and
| request/response heartbeats and outcomes.
|
*/

Route::get('/health', [HealthController::class, 'check']);
Route::get('/ready', [HealthController::class, 'ready']);

// NamespaceResolver is intentionally applied AFTER route-level RequireRole,
// so wrong-role tokens cannot observe namespace existence via a 404/403
// difference on role-gated endpoints (TD-S049).
//
// ControlPlaneVersionResolver sits between RequireRole and NamespaceResolver
// on every version-gated control-plane endpoint, so a missing/unsupported
// X-Durable-Workflow-Control-Plane-Version returns 400 even when the named
// namespace does not exist (TD-S050). /api/cluster/info handles its discovery
// exemption in the controller: it remains callable without the header, but an
// explicitly unsupported header is refused as skew evidence before returning
// the discovery manifest.
//
// RequireTopologyRoles sits after protocol validation and before
// NamespaceResolver on hosted routes so wrong-node requests fail closed with a
// machine-readable topology reason without leaking namespace existence.
//
// RequireWorkflowBootstrapReady sits in the same slot for runtime-serving
// workflow routes. It only blocks on explicit database/migration bootstrap
// blockers so start, index, mutation, bridge, and worker-poll traffic fail
// closed during rollout/schema drift, while run-scoped debug/history
// diagnostics remain available for investigation and recovery.
//
// WorkerProtocolVersionResolver follows the same ordering for worker-plane
// routes, keeping protocol skew and namespace errors in the worker envelope.
Route::middleware([Authenticate::class, RuntimeExternalPayloadTransport::class])->group(function () {
    $admin = RequireRole::class.':admin';
    $operator = RequireRole::class.':operator,admin';
    $worker = RequireRole::class.':worker';
    $authenticated = RequireRole::class.':worker,operator,admin';
    $ns = NamespaceResolver::class;
    $cpv = ControlPlaneVersionResolver::class;
    $httpControl = RequireTopologyRoles::class.':api_ingress,control_plane';
    $httpWorker = RequireTopologyRoles::class.':api_ingress,control_plane';
    $workflowBootstrap = RequireWorkflowBootstrapReady::class;
    $wpv = WorkerProtocolVersionResolver::class;
    $namespaceAdmission = EnforceNamespaceRequestAdmission::class;

    // ── System ───────────────────────────────────────────────────────
    Route::get('/cluster/info', [HealthController::class, 'clusterInfo'])->middleware([$authenticated, $ns]);

    // Runtime-mediated payload transport is shared by control-plane clients
    // and workers. It deliberately has its own versioned path instead of
    // inheriting either protocol's request-version header.
    Route::prefix('external-payloads/v1')->middleware([$authenticated, $httpControl, $ns])->group(function () {
        Route::post('/', [RuntimeExternalPayloadController::class, 'store']);
        Route::get('/{referenceId}', [RuntimeExternalPayloadController::class, 'show']);
    });

    // ── Namespaces ───────────────────────────────────────────────────
    Route::prefix('namespaces')->group(function () use ($admin, $operator, $ns, $cpv, $httpControl, $namespaceAdmission) {
        Route::get('/', [NamespaceController::class, 'index'])->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission]);
        Route::post('/', [NamespaceController::class, 'store'])->middleware([$admin, $cpv, $httpControl, $ns, $namespaceAdmission]);
        Route::get('/{namespace}', [NamespaceController::class, 'show'])->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission]);
        Route::put('/{namespace}', [NamespaceController::class, 'update'])->middleware([$admin, $cpv, $httpControl, $ns, $namespaceAdmission]);
        Route::delete('/{namespace}', [NamespaceController::class, 'destroy'])->middleware([$admin, $cpv, $httpControl, $ns, $namespaceAdmission]);
        Route::put('/{namespace}/external-storage', [NamespaceController::class, 'updateExternalStorage'])->middleware([$admin, $cpv, $httpControl, $ns, $namespaceAdmission]);
    });

    // ── External Payload Storage ───────────────────────────────────
    Route::prefix('storage')->middleware([$admin, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
        Route::post('/test', [StorageController::class, 'test']);
    });

    // ── Workflows ────────────────────────────────────────────────────
    Route::prefix('workflows')->middleware([$admin, $cpv, $httpControl, $workflowBootstrap, $ns, $namespaceAdmission])->group(function () {
        Route::post('/import/embedded-v2', [EmbeddedV2ImportController::class, 'store']);
        Route::post('/import/waterline-v1', [LegacyV1ProjectionController::class, 'store']);
    });

    Route::prefix('workflows')->middleware([$operator, $cpv, $httpControl, $workflowBootstrap, $ns, $namespaceAdmission])->group(function () {
        Route::get('/', [WorkflowController::class, 'index']);
        Route::post('/', [WorkflowController::class, 'start']);
        Route::get('/{workflowId}', [WorkflowController::class, 'show']);

        // Commands (instance-targeted — always targets the current run)
        Route::post('/{workflowId}/signal/{signalName}', [WorkflowController::class, 'signal']);
        Route::post('/{workflowId}/message-streams/{streamName}/messages', [MessageStreamController::class, 'append']);
        Route::post('/{workflowId}/query/{queryName}', [WorkflowController::class, 'query']);
        Route::post('/{workflowId}/update/{updateName}', [WorkflowController::class, 'update']);
        Route::post('/{workflowId}/cancel', [WorkflowController::class, 'cancel']);
        Route::post('/{workflowId}/terminate', [WorkflowController::class, 'terminate']);
        Route::post('/{workflowId}/repair', [WorkflowController::class, 'repair']);
        Route::post('/{workflowId}/archive', [WorkflowController::class, 'archive']);

        // Commands (run-targeted — rejects historical runs explicitly)
        Route::post('/{workflowId}/runs/{runId}/signal/{signalName}', [WorkflowController::class, 'signalRun']);
        Route::post('/{workflowId}/runs/{runId}/query/{queryName}', [WorkflowController::class, 'queryRun']);
        Route::post('/{workflowId}/runs/{runId}/update/{updateName}', [WorkflowController::class, 'updateRun']);
        Route::post('/{workflowId}/runs/{runId}/cancel', [WorkflowController::class, 'cancelRun']);
        Route::post('/{workflowId}/runs/{runId}/terminate', [WorkflowController::class, 'terminateRun']);
        Route::post('/{workflowId}/runs/{runId}/repair', [WorkflowController::class, 'repairRun']);
        Route::post('/{workflowId}/runs/{runId}/archive', [WorkflowController::class, 'archiveRun']);

        // Durable workflow streams (producer side): append ordered items
        // to a named, run-scoped stream and close the stream on
        // producer completion or error. Routed in the bootstrap-gated
        // group with the rest of the run-targeted mutations.
        Route::post('/{workflowId}/runs/{runId}/streams/{streamName}/items', [WorkflowStreamController::class, 'append']);
        Route::post('/{workflowId}/runs/{runId}/streams/{streamName}/close', [WorkflowStreamController::class, 'close']);
    });

    Route::prefix('workflows')->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
        Route::get('/{workflowId}/debug', [WorkflowController::class, 'debug']);
        Route::get('/{workflowId}/runs', [WorkflowController::class, 'runs']);
        Route::get('/{workflowId}/runs/{runId}', [WorkflowController::class, 'showRun']);
        Route::get('/{workflowId}/runs/{runId}/debug', [WorkflowController::class, 'debugRun']);
        Route::get('/{workflowId}/message-streams', [MessageStreamController::class, 'index']);
        Route::get('/{workflowId}/message-streams/{streamName}', [MessageStreamController::class, 'show']);

        // History
        Route::get('/{workflowId}/runs/{runId}/history', [HistoryController::class, 'show']);
        Route::get('/{workflowId}/runs/{runId}/history/export', [HistoryController::class, 'export']);

        // Caller-side Nexus operations: every cross-namespace service call this
        // workflow run scheduled, indexed by the caller run id. Operators
        // debugging a failed run answer "what cross-namespace calls did this
        // workflow make and how did each one settle?" from this surface
        // without inspecting raw transport logs.
        Route::get('/{workflowId}/nexus-operations', [ServiceCatalogController::class, 'nexusOperationsForWorkflow']);
        Route::get('/{workflowId}/runs/{runId}/nexus-operations', [ServiceCatalogController::class, 'nexusOperationsForRun']);

        // Durable workflow streams (subscriber + observability side):
        // list streams on a run, describe a stream, and read an
        // ordered items window with optional long-poll for reconnect-
        // by-offset semantics. Producer-side append and close land in
        // the bootstrap-gated mutations group above.
        Route::get('/{workflowId}/runs/{runId}/streams', [WorkflowStreamController::class, 'index']);
        Route::get('/{workflowId}/runs/{runId}/streams/{streamName}', [WorkflowStreamController::class, 'show']);
        Route::get('/{workflowId}/runs/{runId}/streams/{streamName}/items', [WorkflowStreamController::class, 'items']);
    });

    // ── Standalone Activities ────────────────────────────────────────
    // Activities run as top-level durable jobs, reusing the same Activity
    // definition that workflows can also schedule via the activity() yield.
    // The server records each standalone activity inside a host run that
    // anchors retry, deadline, history, and cancellation accounting, so the
    // execution surfaces as a first-class top-level row on the run summary,
    // history, and listing APIs without authoring a wrapper Workflow.
    Route::prefix('activities')->middleware([$operator, $cpv, $httpControl, $workflowBootstrap, $ns, $namespaceAdmission])->group(function () {
        Route::get('/', [ActivityController::class, 'index']);
        Route::post('/', [ActivityController::class, 'start'])->middleware(EnforceStandaloneActivityHostQuota::class);
        Route::get('/{activityId}', [ActivityController::class, 'show']);
    });

    // ── Bridge Adapters ──────────────────────────────────────────────
    Route::prefix('bridge-adapters')->middleware([$operator, $cpv, $httpControl, $workflowBootstrap, $ns, $namespaceAdmission])->group(function () {
        Route::post('/webhook/{adapter}', [BridgeAdapterController::class, 'webhook']);
    });

    // ── Worker registration diagnostics ──────────────────────────────
    Route::prefix('worker')->middleware([$authenticated, $wpv, $httpWorker, $workflowBootstrap, $ns])->group(function () {
        Route::post('/register', [WorkerController::class, 'register']);
    });

    // ── Worker Task Polling ──────────────────────────────────────────
    Route::prefix('worker')->middleware([$worker, $wpv, $httpWorker, $workflowBootstrap, $ns])->group(function () {
        Route::delete('/registrations/{workerId}', [WorkerDeregistrationController::class, 'destroy']);
        Route::post('/heartbeat', [WorkerController::class, 'heartbeat']);

        // Worker sessions
        Route::post('/sessions', [WorkerSessionController::class, 'create']);
        Route::post('/sessions/{sessionId}/heartbeat', [WorkerSessionController::class, 'heartbeat']);
        Route::delete('/sessions/{sessionId}', [WorkerSessionController::class, 'close']);

        // Workflow tasks (long-poll)
        Route::post('/workflow-tasks/poll', [WorkerController::class, 'pollWorkflowTasks']);
        Route::post('/workflow-tasks/{taskId}/history', [WorkerController::class, 'workflowTaskHistory']);
        Route::post('/workflow-tasks/{taskId}/heartbeat', [WorkerController::class, 'heartbeatWorkflowTask']);
        Route::post('/workflow-tasks/{taskId}/complete', [WorkerController::class, 'completeWorkflowTask']);
        Route::post('/workflow-tasks/{taskId}/fail', [WorkerController::class, 'failWorkflowTask']);

        // Query tasks (ephemeral worker-routed workflow queries)
        Route::post('/query-tasks/poll', [WorkerController::class, 'pollQueryTasks']);
        Route::post('/query-tasks/{queryTaskId}/complete', [WorkerController::class, 'completeQueryTask']);
        Route::post('/query-tasks/{queryTaskId}/fail', [WorkerController::class, 'failQueryTask']);

        // Update validation tasks (durable, synchronous pre-acceptance checks)
        Route::post('/update-validation-tasks/poll', [WorkerController::class, 'pollUpdateValidationTasks']);
        Route::post('/update-validation-tasks/{taskId}/approve', [WorkerController::class, 'approveUpdateValidationTask']);
        Route::post('/update-validation-tasks/{taskId}/reject', [WorkerController::class, 'rejectUpdateValidationTask']);

        // Activity tasks (long-poll)
        Route::post('/activity-tasks/poll', [ActivityTaskController::class, 'poll']);
        Route::post('/activity-tasks/{taskId}/complete', [ActivityTaskController::class, 'complete']);
        Route::post('/activity-tasks/{taskId}/fail', [ActivityTaskController::class, 'fail']);
        Route::post('/activity-tasks/{taskId}/heartbeat', [ActivityTaskController::class, 'heartbeat']);
    });

    // ── Workers (Management) ──────────────────────────────────────────
    Route::prefix('workers')->group(function () use ($admin, $operator, $ns, $cpv, $httpControl, $namespaceAdmission) {
        Route::get('/', [WorkerManagementController::class, 'index'])->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission]);
        Route::get('/{workerId}', [WorkerManagementController::class, 'show'])->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission]);
        Route::delete('/{workerId}', [WorkerManagementController::class, 'destroy'])->middleware([$admin, $cpv, $httpControl, $ns, $namespaceAdmission]);
    });

    // ── Worker Sessions (Management) ────────────────────────────────
    Route::prefix('worker-sessions')->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
        Route::get('/', [WorkerSessionController::class, 'index']);
        Route::get('/{sessionId}', [WorkerSessionController::class, 'show']);
    });

    // ── Task Queues ──────────────────────────────────────────────────
    Route::prefix('task-queues')->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
        Route::get('/', [TaskQueueController::class, 'index']);
        Route::get('/{taskQueue}/build-ids', [TaskQueueController::class, 'buildIds']);
        Route::post('/{taskQueue}/build-ids/promote', [TaskQueueController::class, 'promoteBuildId']);
        Route::post('/{taskQueue}/build-ids/drain', [TaskQueueController::class, 'drainBuildId']);
        Route::post('/{taskQueue}/build-ids/resume', [TaskQueueController::class, 'resumeBuildId']);
        // Operator-facing snapshot of priority + fairness dispatch state for
        // a queue. Registered before the catch-all `{taskQueue}` show route
        // so the segment is not swallowed by the route parameter.
        Route::get('/{taskQueue}/priority-fairness', [TaskQueueController::class, 'priorityFairness']);
        Route::get('/{taskQueue}', [TaskQueueController::class, 'show']);
    });

    // ── Worker Deployments ───────────────────────────────────────────
    // First-class deployment lifecycle surface. Promote, drain, resume,
    // and rollback are exposed against deployment names of the form
    // `namespace/task_queue@build_id` (or `@unversioned` for the
    // pre-rollout cohort). Refusals carry a machine-readable
    // DeploymentBlockage list with a 409. The legacy
    // /api/task-queues/{taskQueue}/build-ids/{drain|resume} routes
    // continue to work unchanged; this surface layers on top.
    Route::prefix('deployments')->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
        Route::get('/', [DeploymentController::class, 'index']);

        // Lifecycle actions are registered before the catch-all show
        // route so the .+ constraint on `{name}` (which lets the name
        // contain slashes for the namespace/task_queue@build_id format)
        // does not swallow `/promote`, `/drain`, `/resume`, and
        // `/rollback` segments.
        Route::post('/{name}/promote', [DeploymentController::class, 'promote'])->where('name', '.+');
        Route::post('/{name}/drain', [DeploymentController::class, 'drain'])->where('name', '.+');
        Route::post('/{name}/resume', [DeploymentController::class, 'resume'])->where('name', '.+');
        Route::post('/{name}/rollback', [DeploymentController::class, 'rollback'])->where('name', '.+');

        Route::get('/{name}', [DeploymentController::class, 'show'])->where('name', '.+');
    });

    // ── Schedules ────────────────────────────────────────────────────
    Route::prefix('schedules')->group(function () use ($operator, $cpv, $httpControl, $workflowBootstrap, $ns, $namespaceAdmission) {
        Route::middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
            Route::get('/', [ScheduleController::class, 'index']);
            Route::get('/{scheduleId}', [ScheduleController::class, 'show']);
            Route::get('/{scheduleId}/history', [ScheduleController::class, 'history']);
        });

        Route::middleware([$operator, $cpv, $httpControl, $workflowBootstrap, $ns, $namespaceAdmission])->group(function () {
            Route::post('/', [ScheduleController::class, 'store']);
            Route::put('/{scheduleId}', [ScheduleController::class, 'update']);
            Route::delete('/{scheduleId}', [ScheduleController::class, 'destroy']);
            Route::post('/{scheduleId}/pause', [ScheduleController::class, 'pause']);
            Route::post('/{scheduleId}/resume', [ScheduleController::class, 'resume']);
            Route::post('/{scheduleId}/trigger', [ScheduleController::class, 'trigger']);
            Route::post('/{scheduleId}/backfill', [ScheduleController::class, 'backfill']);
        });
    });

    // ── Search Attributes ────────────────────────────────────────────
    Route::prefix('search-attributes')->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
        Route::get('/', [SearchAttributeController::class, 'index']);
        Route::post('/', [SearchAttributeController::class, 'store']);
        Route::delete('/{name}', [SearchAttributeController::class, 'destroy']);
    });

    // ── Service Catalog ──────────────────────────────────────────────
    // Catalog admin (CRUD on endpoints/services/operations) is admin-gated.
    // The execute-operation and service-call describe/cancel surfaces below are
    // operator-gated because they are runtime call surfaces, not registry
    // mutations — the same role that drives ordinary workflow signals and
    // queries should be able to dispatch service operations.
    Route::prefix('service-endpoints')->middleware([$admin, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
        Route::get('/', [ServiceCatalogController::class, 'endpointIndex']);
        Route::post('/', [ServiceCatalogController::class, 'endpointStore']);
        Route::get('/{endpointName}', [ServiceCatalogController::class, 'endpointShow']);
        Route::put('/{endpointName}', [ServiceCatalogController::class, 'endpointUpdate']);
        Route::delete('/{endpointName}', [ServiceCatalogController::class, 'endpointDestroy']);

        Route::get('/{endpointName}/services', [ServiceCatalogController::class, 'serviceIndex']);
        Route::post('/{endpointName}/services', [ServiceCatalogController::class, 'serviceStore']);
        Route::get('/{endpointName}/services/{serviceName}', [ServiceCatalogController::class, 'serviceShow']);
        Route::put('/{endpointName}/services/{serviceName}', [ServiceCatalogController::class, 'serviceUpdate']);
        Route::delete('/{endpointName}/services/{serviceName}', [ServiceCatalogController::class, 'serviceDestroy']);

        Route::get('/{endpointName}/services/{serviceName}/operations', [ServiceCatalogController::class, 'operationIndex']);
        Route::post('/{endpointName}/services/{serviceName}/operations', [ServiceCatalogController::class, 'operationStore']);
        Route::get('/{endpointName}/services/{serviceName}/operations/{operationName}', [ServiceCatalogController::class, 'operationShow']);
        Route::put('/{endpointName}/services/{serviceName}/operations/{operationName}', [ServiceCatalogController::class, 'operationUpdate']);
        Route::delete('/{endpointName}/services/{serviceName}/operations/{operationName}', [ServiceCatalogController::class, 'operationDestroy']);
    });

    // Service-call read surface stays available during rollout/schema drift
    // for observability of already-recorded durable calls.
    Route::prefix('service-endpoints')->middleware([$operator, $cpv, $httpControl, $ns, $namespaceAdmission])->group(function () {
        Route::get(
            '/{endpointName}/services/{serviceName}/operations/{operationName}/service-calls/{serviceCallId}',
            [ServiceCatalogController::class, 'serviceCallShow'],
        );
    });

    // Service-execution surface — runtime call to a registered operation.
    // The workflowBootstrap gate is included because dispatch may end up
    // creating a workflow, signal, update, or activity row, and those
    // surfaces refuse traffic during schema rollout.
    Route::prefix('service-endpoints')->middleware([$operator, $cpv, $httpControl, $workflowBootstrap, $ns, $namespaceAdmission])->group(function () {
        Route::post(
            '/{endpointName}/services/{serviceName}/operations/{operationName}/execute',
            [ServiceCatalogController::class, 'executeOperation'],
        );
        Route::post(
            '/{endpointName}/services/{serviceName}/operations/{operationName}/service-calls/{serviceCallId}/cancel',
            [ServiceCatalogController::class, 'serviceCallCancel'],
        );
    });

    // ── System / Operations ─────────────────────────────────────────
    // Keep admin-only diagnostics and remediation outside tenant request
    // admission so an exhausted namespace cannot hide its counters or block
    // an operator from repairing it.
    Route::prefix('system')->middleware([$admin, $cpv, $httpControl, $ns])->group(function () {
        Route::get('/health', [SystemController::class, 'health']);
        Route::match(['get', 'post'], '/metrics', [SystemController::class, 'metrics']);
        Route::get('/operator-dashboard', [SystemController::class, 'operatorDashboard']);
        Route::get('/operator-metrics', [SystemController::class, 'operatorMetrics']);
        Route::get('/prometheus-metrics', [SystemController::class, 'prometheusMetrics']);
        Route::get('/repair', [SystemController::class, 'repairStatus']);
        Route::post('/repair/pass', [SystemController::class, 'repairPass']);
        Route::get('/activity-timeouts', [SystemController::class, 'activityTimeoutStatus']);
        Route::post('/activity-timeouts/pass', [SystemController::class, 'activityTimeoutEnforcePass']);
        Route::get('/external-payload-cleanup', [SystemController::class, 'externalPayloadCleanupStatus']);
        Route::post('/external-payload-cleanup/pass', [SystemController::class, 'externalPayloadCleanupPass']);
        Route::get('/retention', [SystemController::class, 'retentionStatus']);
        Route::post('/retention/pass', [SystemController::class, 'retentionEnforcePass']);
    });
});
