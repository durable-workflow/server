<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ControlPlaneFailureDiagnostics
{
    private const EXCEPTION_MESSAGE_LIMIT_BYTES = 2048;

    private const PUBLIC_EXCEPTION_MESSAGE_LIMIT_BYTES = 512;

    /**
     * @return array{type: string, message?: string}
     */
    public function publicException(Throwable $exception): array
    {
        $message = mb_strcut(
            Str::squish($exception->getMessage()),
            0,
            self::PUBLIC_EXCEPTION_MESSAGE_LIMIT_BYTES,
            'UTF-8',
        );

        return array_filter([
            'type' => $exception::class,
            'message' => $message !== '' ? $message : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function reportUnhandled(Throwable $exception, Request $request): string
    {
        $errorId = (string) Str::ulid();
        $operation = ControlPlaneOperation::fromRequest($request);
        $command = $operation instanceof ControlPlaneOperation
            ? $this->commandForRequest($operation, $request)
            : null;

        Log::error('Unhandled control-plane operation exception.', $this->context(
            exception: $exception,
            errorId: $errorId,
            operation: $operation,
            command: $command,
            request: $request,
            recovery: null,
        ));

        return $errorId;
    }

    public function reportLockPressure(Throwable $exception, Request $request): string
    {
        $errorId = (string) Str::ulid();
        $operation = ControlPlaneOperation::fromRequest($request);
        $command = $operation instanceof ControlPlaneOperation
            ? $this->commandForRequest($operation, $request)
            : null;

        Log::warning('Control-plane storage contention exhausted retries.', $this->context(
            exception: $exception,
            errorId: $errorId,
            operation: $operation,
            command: $command,
            request: $request,
            recovery: 'retry_exhausted',
        ));

        return $errorId;
    }

    public function reportRecoveredSignal(
        Throwable $exception,
        WorkflowCommand $command,
        string $signalName,
    ): void {
        $errorId = (string) Str::ulid();
        $operation = new ControlPlaneOperation(
            operation: 'signal',
            operationName: $signalName,
            workflowId: $command->workflow_instance_id,
            runId: $command->requestedRunId(),
        );

        Log::warning('Recovered a committed signal after a delivery-hint exception.', $this->context(
            exception: $exception,
            errorId: $errorId,
            operation: $operation,
            command: $command,
            request: null,
            recovery: 'committed_signal',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(
        Throwable $exception,
        string $errorId,
        ?ControlPlaneOperation $operation,
        ?WorkflowCommand $command,
        ?Request $request,
        ?string $recovery,
    ): array {
        $workflowId = $operation?->workflowId ?? $command?->workflow_instance_id;
        $run = $this->runFor($operation, $command);

        return array_filter([
            'error_id' => $errorId,
            'exception' => $exception,
            'exception_chain' => $this->exceptionChain($exception),
            'operation' => $operation instanceof ControlPlaneOperation ? [
                'name' => $operation->operation,
                'target_name' => $operation->operationName,
                'workflow_id' => $workflowId,
                'requested_run_id' => $operation->runId,
                'request_id' => $request !== null
                    ? $this->requestId($request)
                    : $this->commandRequestId($command),
                'operation_id' => $this->commandOperationId($command),
                'request_path' => $request?->getPathInfo()
                    ?? $this->commandContextString($command, ['request', 'path']),
            ] : null,
            'command' => $this->commandSnapshot($command),
            'workflow' => $this->workflowSnapshot($run, $workflowId),
            'worker_routes' => $this->workerRoutes($run),
            'lease_state' => $this->leaseState($run),
            'recovery' => $recovery,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return list<array{class: string, message: string, code: int|string, file: string, line: int}>
     */
    private function exceptionChain(Throwable $exception): array
    {
        $chain = [];

        for ($current = $exception; $current instanceof Throwable && count($chain) < 8; $current = $current->getPrevious()) {
            $chain[] = [
                'class' => $current::class,
                'message' => mb_strcut($current->getMessage(), 0, self::EXCEPTION_MESSAGE_LIMIT_BYTES, 'UTF-8'),
                'code' => $current->getCode(),
                'file' => $current->getFile(),
                'line' => $current->getLine(),
            ];
        }

        return $chain;
    }

    private function commandForRequest(ControlPlaneOperation $operation, Request $request): ?WorkflowCommand
    {
        if ($operation->workflowId === null || $this->requestId($request) === null) {
            return null;
        }

        $requestId = $this->requestId($request);

        return $this->safe(function () use ($operation, $requestId): ?WorkflowCommand {
            return WorkflowCommand::query()
                ->where('workflow_instance_id', $operation->workflowId)
                ->where('command_type', $operation->operation)
                ->orderByDesc('command_sequence')
                ->limit(20)
                ->get()
                ->first(function (WorkflowCommand $command) use ($operation, $requestId): bool {
                    return $this->commandRequestId($command) === $requestId
                        && ($operation->operationName === null || $command->targetName() === $operation->operationName);
                });
        });
    }

    private function runFor(
        ?ControlPlaneOperation $operation,
        ?WorkflowCommand $command,
    ): ?WorkflowRun {
        return $this->safe(function () use ($operation, $command): ?WorkflowRun {
            $runId = $command?->resolvedRunId()
                ?? $command?->workflow_run_id
                ?? $operation?->runId;

            if (is_string($runId) && $runId !== '') {
                return WorkflowRun::query()->find($runId);
            }

            $workflowId = $operation?->workflowId ?? $command?->workflow_instance_id;

            if (! is_string($workflowId) || $workflowId === '') {
                return null;
            }

            return WorkflowRun::query()
                ->where('workflow_instance_id', $workflowId)
                ->orderByDesc('run_number')
                ->first();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function commandSnapshot(?WorkflowCommand $command): ?array
    {
        if (! $command instanceof WorkflowCommand) {
            return null;
        }

        return [
            'id' => $command->id,
            'type' => $command->command_type?->value,
            'target_name' => $command->targetName(),
            'status' => $command->status?->value,
            'outcome' => $command->outcome?->value,
            'sequence' => $command->command_sequence,
            'workflow_id' => $command->workflow_instance_id,
            'run_id' => $command->workflow_run_id,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'request_id' => $this->commandRequestId($command),
            'operation_id' => $this->commandOperationId($command),
            'accepted_at' => $command->accepted_at?->toJSON(),
            'applied_at' => $command->applied_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function workflowSnapshot(?WorkflowRun $run, ?string $workflowId): ?array
    {
        if (! $run instanceof WorkflowRun && (! is_string($workflowId) || $workflowId === '')) {
            return null;
        }

        return array_filter([
            'workflow_id' => $run?->workflow_instance_id ?? $workflowId,
            'run_id' => $run?->id,
            'status' => $run?->status?->value,
            'workflow_type' => $run?->workflow_type,
            'namespace' => $run?->namespace,
            'task_queue' => $run?->queue,
            'compatibility' => $run?->compatibility,
            'last_history_sequence' => $run?->last_history_sequence,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workerRoutes(?WorkflowRun $run): array
    {
        if (! $run instanceof WorkflowRun || ! is_string($run->queue) || $run->queue === '') {
            return [];
        }

        return $this->safe(function () use ($run): array {
            return WorkerRegistration::query()
                ->where('namespace', $run->namespace ?? 'default')
                ->where('task_queue', $run->queue)
                ->orderByDesc('last_heartbeat_at')
                ->limit(10)
                ->get()
                ->filter(function (WorkerRegistration $worker) use ($run): bool {
                    $types = is_array($worker->supported_workflow_types)
                        ? $worker->supported_workflow_types
                        : [];

                    return $types === [] || in_array($run->workflow_type, $types, true);
                })
                ->map(static fn (WorkerRegistration $worker): array => [
                    'worker_id' => $worker->worker_id,
                    'runtime' => $worker->runtime,
                    'sdk_version' => $worker->sdk_version,
                    'build_id' => $worker->build_id,
                    'status' => $worker->status,
                    'namespace' => $worker->namespace,
                    'task_queue' => $worker->task_queue,
                    'last_heartbeat_at' => $worker->last_heartbeat_at?->toJSON(),
                    'available_workflow_slots' => $worker->available_workflow_slots,
                    'max_concurrent_workflow_tasks' => $worker->max_concurrent_workflow_tasks,
                ])
                ->values()
                ->all();
        }) ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leaseState(?WorkflowRun $run): array
    {
        if (! $run instanceof WorkflowRun) {
            return [];
        }

        return $this->safe(function () use ($run): array {
            return WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(static fn (WorkflowTask $task): array => [
                    'task_id' => $task->id,
                    'task_type' => $task->task_type?->value,
                    'status' => $task->status?->value,
                    'task_queue' => $task->queue,
                    'attempt_count' => $task->attempt_count,
                    'lease_owner' => $task->lease_owner,
                    'leased_at' => $task->leased_at?->toJSON(),
                    'lease_expires_at' => $task->lease_expires_at?->toJSON(),
                    'last_dispatch_attempt_at' => $task->last_dispatch_attempt_at?->toJSON(),
                    'last_dispatch_error' => $task->last_dispatch_error,
                ])
                ->all();
        }) ?? [];
    }

    private function requestId(Request $request): ?string
    {
        foreach ([
            $request->header('X-Request-Id'),
            $request->input('request_id'),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function commandRequestId(?WorkflowCommand $command): ?string
    {
        foreach ([
            $this->commandContextString($command, ['request', 'request_id']),
            $this->commandContextString($command, ['server', 'metadata', 'request_id']),
        ] as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function commandOperationId(?WorkflowCommand $command): ?string
    {
        return $this->commandContextString($command, ['server', 'operation_id']);
    }

    /**
     * @param  list<string>  $path
     */
    private function commandContextString(?WorkflowCommand $command, array $path): ?string
    {
        if (! $command instanceof WorkflowCommand) {
            return null;
        }

        $value = data_get($command->commandContext(), implode('.', $path));

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult|null
     */
    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
