<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use App\Support\StoragePressure;
use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceStorageAdmission
{
    private const DRAIN_ACTIONS = [
        'WorkerController@heartbeat',
        'WorkerController@heartbeatWorkflowTask',
        'WorkerController@completeWorkflowTask',
        'WorkerController@failWorkflowTask',
        'WorkerController@completeQueryTask',
        'WorkerController@failQueryTask',
        'WorkerController@approveUpdateValidationTask',
        'WorkerController@rejectUpdateValidationTask',
        'ActivityTaskController@complete',
        'ActivityTaskController@fail',
        'ActivityTaskController@heartbeat',
        'WorkerSessionController@heartbeat',
    ];

    private const POLL_ACTIONS = [
        'WorkerController@pollWorkflowTasks',
        'WorkerController@pollQueryTasks',
        'WorkerController@pollUpdateValidationTasks',
        'ActivityTaskController@poll',
    ];

    public function __construct(private readonly StoragePressure $pressure) {}

    public function handle(Request $request, Closure $next): Response
    {
        $snapshot = $this->pressure->snapshot();
        $action = class_basename((string) $request->route()?->getActionName());
        if ($snapshot['state'] === 'normal' || $request->isMethodSafe()
            || $action === 'WorkerController@workflowTaskHistory'
            || ($snapshot['state'] === 'draining' && in_array($action, self::DRAIN_ACTIONS, true))) {
            return $next($request);
        }

        return $this->reject($request, $snapshot, true);
    }

    public function reject(Request $request, array $snapshot, bool $beforeHandler): Response
    {
        $action = class_basename((string) $request->route()?->getActionName());
        $payload = [
            'reason' => $snapshot['reason'],
            'message' => 'Storage admission is paused. Wait for the operator to restore capacity.',
            'storage_state' => $snapshot['state'],
            'retryable' => true,
            'retry_after_seconds' => 5,
        ];
        if ($beforeHandler) {
            $payload['request_admitted'] = false;
        }

        if (in_array($action, self::POLL_ACTIONS, true)) {
            $pollId = $request->input('poll_request_id');
            // A previous attempt with this poll ID may already own a lease.
            // This refusal makes no assertion about that earlier outcome.
            $payload += [
                'task' => null,
                'poll_status' => $snapshot['reason'],
                'poll_request_id' => is_string($pollId) && $pollId !== '' && strlen($pollId) <= 255 ? $pollId : null,
                'retry_same_poll_request_id' => true,
                'claim_admitted' => false,
            ];
        }

        $response = WorkerProtocol::isWorkerPlaneRequest($request)
            ? WorkerProtocol::json($payload, 503)
            : ControlPlaneProtocol::jsonForRequest($request, $payload, 503);

        return $response->header('Retry-After', '5');
    }
}
