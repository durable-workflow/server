<?php

use App\Http\Controllers\Api\ActivityTaskController;
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

Route::middleware(Authenticate::class)->group(function () {

    // ── System ───────────────────────────────────────────────────────
    Route::get('/cluster/info', [HealthController::class, 'clusterInfo']);

    // ── Namespaces ───────────────────────────────────────────────────
    Route::prefix('namespaces')->group(function () {
        Route::get('/', [NamespaceController::class, 'index']);
        Route::post('/', [NamespaceController::class, 'store']);
        Route::get('/{namespace}', [NamespaceController::class, 'show']);
        Route::put('/{namespace}', [NamespaceController::class, 'update']);
    });

    // ── Workflows ────────────────────────────────────────────────────
    Route::prefix('workflows')->group(function () {
        Route::get('/', [WorkflowController::class, 'index']);
        Route::post('/', [WorkflowController::class, 'start']);
        Route::get('/{workflowId}', [WorkflowController::class, 'show']);
        Route::get('/{workflowId}/runs', [WorkflowController::class, 'runs']);
        Route::get('/{workflowId}/runs/{runId}', [WorkflowController::class, 'showRun']);

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

    // ── Worker Task Polling ──────────────────────────────────────────
    Route::prefix('worker')->group(function () {
        // Registration
        Route::post('/register', [WorkerController::class, 'register']);
        Route::post('/heartbeat', [WorkerController::class, 'heartbeat']);

        // Workflow tasks (long-poll)
        Route::post('/workflow-tasks/poll', [WorkerController::class, 'pollWorkflowTasks']);
        Route::post('/workflow-tasks/{taskId}/history', [WorkerController::class, 'workflowTaskHistory']);
        Route::post('/workflow-tasks/{taskId}/heartbeat', [WorkerController::class, 'heartbeatWorkflowTask']);
        Route::post('/workflow-tasks/{taskId}/complete', [WorkerController::class, 'completeWorkflowTask']);
        Route::post('/workflow-tasks/{taskId}/fail', [WorkerController::class, 'failWorkflowTask']);

        // Activity tasks (long-poll)
        Route::post('/activity-tasks/poll', [ActivityTaskController::class, 'poll']);
        Route::post('/activity-tasks/{taskId}/complete', [ActivityTaskController::class, 'complete']);
        Route::post('/activity-tasks/{taskId}/fail', [ActivityTaskController::class, 'fail']);
        Route::post('/activity-tasks/{taskId}/heartbeat', [ActivityTaskController::class, 'heartbeat']);
    });

    // ── Workers (Management) ──────────────────────────────────────────
    Route::prefix('workers')->group(function () {
        Route::get('/', [WorkerManagementController::class, 'index']);
        Route::get('/{workerId}', [WorkerManagementController::class, 'show']);
        Route::delete('/{workerId}', [WorkerManagementController::class, 'destroy']);
    });

    // ── Task Queues ──────────────────────────────────────────────────
    Route::prefix('task-queues')->group(function () {
        Route::get('/', [TaskQueueController::class, 'index']);
        Route::get('/{taskQueue}', [TaskQueueController::class, 'show']);
    });

    // ── Schedules ────────────────────────────────────────────────────
    Route::prefix('schedules')->group(function () {
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
    Route::prefix('search-attributes')->group(function () {
        Route::get('/', [SearchAttributeController::class, 'index']);
        Route::post('/', [SearchAttributeController::class, 'store']);
        Route::delete('/{name}', [SearchAttributeController::class, 'destroy']);
    });

    // ── System / Operations ─────────────────────────────────────────
    Route::prefix('system')->group(function () {
        Route::get('/repair', [SystemController::class, 'repairStatus']);
        Route::post('/repair/pass', [SystemController::class, 'repairPass']);
        Route::get('/activity-timeouts', [SystemController::class, 'activityTimeoutStatus']);
        Route::post('/activity-timeouts/pass', [SystemController::class, 'activityTimeoutEnforcePass']);
    });
});
