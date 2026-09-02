<?php

use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\EnforcePayloadLimits;
use App\Http\Middleware\RemoveServerHeader;
use App\Support\BackendLockPressure;
use App\Support\ControlPlaneFailureDiagnostics;
use App\Support\ControlPlaneOperation;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceDurableStateException;
use App\Support\RuntimeExternalPayloadAudit;
use App\Support\RuntimeExternalPayloadException;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Workflow\V2\Exceptions\WorkflowOutputCodecUnavailableException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function (): void {
            Route::middleware('api')->group(base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            EnforcePayloadLimits::class,
        ]);
        $middleware->api(append: [
            CompressResponse::class,
            RemoveServerHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NamespaceDurableStateException $exception, Request $request) {
            $payload = array_filter([
                'schema' => 'durable-workflow.v2.namespace-durable-state-error.v1',
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
                'retryable' => $exception->retryable,
                'status' => $exception->status,
                'resource' => $exception->resource,
                'current_value' => $exception->currentValue,
                'configured_limit' => $exception->configuredLimit,
                'retry_after_seconds' => $exception->retryAfterSeconds,
            ], static fn (mixed $value): bool => $value !== null);

            $response = WorkerProtocol::isWorkerPlaneRequest($request)
                ? WorkerProtocol::json($payload, $exception->status)
                : ControlPlaneProtocol::jsonForRequest($request, $payload, $exception->status);

            if ($exception->retryAfterSeconds !== null) {
                $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
            }

            return $response;
        });

        $exceptions->render(function (RuntimeExternalPayloadException $exception, Request $request) {
            app(RuntimeExternalPayloadAudit::class)->record($request, 'external_payload.rejected', [
                'reason' => $exception->reason,
                'retryable' => $exception->retryable,
                'status' => $exception->status,
            ]);

            $payload = [
                'schema' => 'durable-workflow.v2.runtime-external-payload-error.v1',
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
                'retryable' => $exception->retryable,
                'status' => $exception->status,
            ];

            if ($exception->retryAfterSeconds !== null) {
                $payload['retry_after_seconds'] = $exception->retryAfterSeconds;
            }

            $response = WorkerProtocol::isWorkerPlaneRequest($request)
                ? WorkerProtocol::json($payload, $exception->status)
                : ControlPlaneProtocol::jsonForRequest($request, $payload, $exception->status);

            if ($exception->retryAfterSeconds !== null) {
                $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
            }

            return $response;
        });

        $exceptions->render(function (WorkflowOutputCodecUnavailableException $exception, Request $request) {
            if (ControlPlaneProtocol::requestVersion($request) !== ControlPlaneProtocol::VERSION) {
                return null;
            }

            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => $exception->getMessage(),
                'reason' => 'workflow_output_codec_unavailable',
            ], 500);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (WorkerProtocol::isWorkerPlaneRequest($request)
                && WorkerProtocol::requestUsesCompatibleProtocolVersion($request)
            ) {
                return WorkerProtocol::json([
                    'message' => $exception->getMessage(),
                    'reason' => 'validation_failed',
                    'errors' => $exception->errors(),
                    'validation_errors' => $exception->errors(),
                ], 422);
            }

            if (ControlPlaneProtocol::requestVersion($request) !== ControlPlaneProtocol::VERSION) {
                return null;
            }

            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => $exception->getMessage(),
                'reason' => 'validation_failed',
                'errors' => $exception->errors(),
                'validation_errors' => $exception->errors(),
            ], 422);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $status = $exception->getStatusCode();
            $message = trim($exception->getMessage()) !== ''
                ? $exception->getMessage()
                : (Response::$statusTexts[$status] ?? "HTTP {$status}");
            $reason = match ($status) {
                401 => 'unauthorized',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                default => null,
            };

            if (WorkerProtocol::isWorkerPlaneRequest($request)
                && WorkerProtocol::requestUsesCompatibleProtocolVersion($request)
            ) {
                return WorkerProtocol::json(array_filter([
                    'message' => $message,
                    'reason' => $reason,
                ], static fn (mixed $value): bool => $value !== null), $status);
            }

            if (ControlPlaneProtocol::requestVersion($request) !== ControlPlaneProtocol::VERSION) {
                return null;
            }

            $payload = array_filter([
                'message' => $message,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null);

            return ControlPlaneProtocol::jsonForRequest($request, $payload, $status);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! BackendLockPressure::is($exception)) {
                return null;
            }

            $controlPlaneOperation = ControlPlaneOperation::fromRequest($request);

            if (ControlPlaneProtocol::requestVersion($request) === ControlPlaneProtocol::VERSION
                && (BackendLockPressure::isSqliteBackend() || $controlPlaneOperation?->operation === 'signal')
            ) {
                $errorId = app(ControlPlaneFailureDiagnostics::class)->reportLockPressure($exception, $request);

                return BackendLockPressure::controlPlaneResponse($request, $errorId);
            }

            $requestedVersion = WorkerProtocol::requestVersion($request);
            $supportedVersion = (string) config('server.worker_protocol.version', WorkerProtocol::VERSION);

            if (! WorkerProtocol::isWorkerPlaneRequest($request)
                || $requestedVersion === null
                || ! WorkerProtocol::isCompatibleProtocolVersion($requestedVersion, $supportedVersion)
            ) {
                return null;
            }

            $taskKind = match (true) {
                $request->is('api/worker/workflow-tasks/poll') => 'workflow_task',
                $request->is('api/worker/activity-tasks/poll') => 'activity_task',
                $request->is('api/worker/query-tasks/poll') => 'query_task',
                default => null,
            };

            if ($taskKind === null) {
                if (! BackendLockPressure::isSqliteBackend()) {
                    return null;
                }

                if ($request->is('api/worker/heartbeat')) {
                    return BackendLockPressure::workerHeartbeatResponse($request);
                }

                return BackendLockPressure::workerOperationResponse($request);
            }

            $namespace = $request->attributes->get('namespace', $request->header('X-Namespace', 'default'));
            $taskQueue = $request->input('task_queue', '');

            return BackendLockPressure::workerPollResponse(
                $taskKind,
                is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : 'default',
                is_string($taskQueue) ? $taskQueue : '',
            );
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            $operation = ControlPlaneOperation::fromRequest($request);

            if (ControlPlaneProtocol::requestVersion($request) !== ControlPlaneProtocol::VERSION
                || ! $operation instanceof ControlPlaneOperation
                || ! in_array($operation->operation, ['signal', 'describe_run', 'history'], true)
            ) {
                return null;
            }

            $diagnostics = app(ControlPlaneFailureDiagnostics::class);
            $errorId = $diagnostics->reportUnhandled($exception, $request);

            if ($operation->operation !== 'signal') {
                return ControlPlaneProtocol::jsonForRequest($request, [
                    'message' => 'The control-plane read could not be completed.',
                    'reason' => 'control_plane_internal_error',
                    'retryable' => false,
                    'error_id' => $errorId,
                    'exception' => $diagnostics->publicException($exception),
                ], 500);
            }

            $payload = [
                'message' => 'The control-plane operation could not be completed.',
                'reason' => 'control_plane_internal_error',
                'command_status' => 'indeterminate',
                'outcome' => 'operation_failed',
                'rejection_category' => 'internal',
                'retryable' => false,
                'error_id' => $errorId,
                'signal_name' => $operation->operationName,
            ];

            return ControlPlaneProtocol::jsonForRequest($request, $payload, 500);
        });
    })->create();
