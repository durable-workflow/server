<?php

use App\Http\Controllers\Api\ActivityTaskController;
use App\Http\Controllers\Api\BridgeAdapterController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\NamespaceController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SearchAttributeController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskQueueController;
use App\Http\Controllers\Api\WorkerController;
use App\Http\Controllers\Api\WorkerManagementController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\ControlPlaneVersionResolver;
use App\Http\Middleware\NamespaceResolver;
use App\Http\Middleware\RequireRole;
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
// namespace does not exist (TD-S050). /api/cluster/info is deliberately
// omitted — it is the version-advertising endpoint and must remain callable
// without the header.
//
// WorkerProtocolVersionResolver follows the same ordering for worker-plane
// routes, keeping protocol skew and namespace errors in the worker envelope.
Route::middleware([Authenticate::class])->group(function () {
    $admin = RequireRole::class.':admin';
    $operator = RequireRole::class.':operator,admin';
    $worker = RequireRole::class.':worker';
    $authenticated = RequireRole::class.':worker,operator,admin';
    $ns = NamespaceResolver::class;
    $cpv = ControlPlaneVersionResolver::class;
    $wpv = WorkerProtocolVersionResolver::class;

    // ── System ───────────────────────────────────────────────────────
    Route::get('/cluster/info', [HealthController::class, 'clusterInfo'])->middleware([$authenticated, $ns]);

    // ── Namespaces ───────────────────────────────────────────────────
    Route::prefix('namespaces')->group(function () use ($admin, $operator, $ns, $cpv) {
        Route::get('/', [NamespaceController::class, 'index'])->middleware([$operator, $cpv, $ns]);
        Route::post('/', [NamespaceController::class, 'store'])->middleware([$admin, $cpv, $ns]);
        Route::get('/{namespace}', [NamespaceController::class, 'show'])->middleware([$operator, $cpv, $ns]);
        Route::put('/{namespace}', [NamespaceController::class, 'update'])->middleware([$admin, $cpv, $ns]);
    });

    // ── Workflows ────────────────────────────────────────────────────
    Route::prefix('workflows')->middleware([$operator, $cpv, $ns])->group(function () {
        Route::get('/', [WorkflowController::class, 'index']);
        Route::post('/', [WorkflowController::class, 'start']);
        Route::get('/{workflowId}', [WorkflowController::class, 'show']);
        Route::get('/{workflowId}/debug', [WorkflowController::class, 'debug']);
        Route::get('/{workflowId}/runs', [WorkflowController::class, 'runs']);
        Route::get('/{workflowId}/runs/{runId}', [WorkflowController::class, 'showRun']);
        Route::get('/{workflowId}/runs/{runId}/debug', [WorkflowController::class, 'debugRun']);

        // Commands (instance-targeted — always targets the current run)
        Route::post('/{workflowId}/signal/{signalName}', [WorkflowController::class, 'signal']);
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

        // History
        Route::get('/{workflowId}/runs/{runId}/history', [HistoryController::class, 'show']);
        Route::get('/{workflowId}/runs/{runId}/history/export', [HistoryController::class, 'export']);
    });

    // ── Bridge Adapters ──────────────────────────────────────────────
    Route::prefix('bridge-adapters')->middleware([$operator, $cpv, $ns])->group(function () {
        Route::post('/webhook/{adapter}', [BridgeAdapterController::class, 'webhook']);
    });

    // ── Worker Task Polling ──────────────────────────────────────────
    Route::prefix('worker')->middleware([$worker, $wpv, $ns])->group(function () {
        // Registration
        Route::post('/register', [WorkerController::class, 'register']);
        Route::post('/heartbeat', [WorkerController::class, 'heartbeat']);

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

        // Activity tasks (long-poll)
        Route::post('/activity-tasks/poll', [ActivityTaskController::class, 'poll']);
        Route::post('/activity-tasks/{taskId}/complete', [ActivityTaskController::class, 'complete']);
        Route::post('/activity-tasks/{taskId}/fail', [ActivityTaskController::class, 'fail']);
        Route::post('/activity-tasks/{taskId}/heartbeat', [ActivityTaskController::class, 'heartbeat']);
    });

    // ── Workers (Management) ──────────────────────────────────────────
    Route::prefix('workers')->group(function () use ($admin, $operator, $ns, $cpv) {
        Route::get('/', [WorkerManagementController::class, 'index'])->middleware([$operator, $cpv, $ns]);
        Route::get('/{workerId}', [WorkerManagementController::class, 'show'])->middleware([$operator, $cpv, $ns]);
        Route::delete('/{workerId}', [WorkerManagementController::class, 'destroy'])->middleware([$admin, $cpv, $ns]);
    });

    // ── Task Queues ──────────────────────────────────────────────────
    Route::prefix('task-queues')->middleware([$operator, $cpv, $ns])->group(function () {
        Route::get('/', [TaskQueueController::class, 'index']);
        Route::get('/{taskQueue}', [TaskQueueController::class, 'show']);
    });

    // ── Schedules ────────────────────────────────────────────────────
    Route::prefix('schedules')->middleware([$operator, $cpv, $ns])->group(function () {
        Route::get('/', [ScheduleController::class, 'index']);
        Route::post('/', [ScheduleController::class, 'store']);
        Route::get('/{scheduleId}', [ScheduleController::class, 'show']);
        Route::put('/{scheduleId}', [ScheduleController::class, 'update']);
        Route::delete('/{scheduleId}', [ScheduleController::class, 'destroy']);
        Route::post('/{scheduleId}/pause', [ScheduleController::class, 'pause']);
        Route::post('/{scheduleId}/resume', [ScheduleController::class, 'resume']);
        Route::post('/{scheduleId}/trigger', [ScheduleController::class, 'trigger']);
        Route::post('/{scheduleId}/backfill', [ScheduleController::class, 'backfill']);
    });

    // ── Search Attributes ────────────────────────────────────────────
    Route::prefix('search-attributes')->middleware([$operator, $cpv, $ns])->group(function () {
        Route::get('/', [SearchAttributeController::class, 'index']);
        Route::post('/', [SearchAttributeController::class, 'store']);
        Route::delete('/{name}', [SearchAttributeController::class, 'destroy']);
    });

    // ── System / Operations ─────────────────────────────────────────
    Route::prefix('system')->middleware([$admin, $cpv, $ns])->group(function () {
        Route::get('/metrics', [SystemController::class, 'metrics']);
        Route::get('/repair', [SystemController::class, 'repairStatus']);
        Route::post('/repair/pass', [SystemController::class, 'repairPass']);
        Route::get('/activity-timeouts', [SystemController::class, 'activityTimeoutStatus']);
        Route::post('/activity-timeouts/pass', [SystemController::class, 'activityTimeoutEnforcePass']);
        Route::get('/retention', [SystemController::class, 'retentionStatus']);
        Route::post('/retention/pass', [SystemController::class, 'retentionEnforcePass']);
    });
});
