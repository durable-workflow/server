<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use App\Models\WorkflowUpdateValidationTask;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class WorkflowUpdateValidationTaskBroker
{
    public const CAPABILITY = 'update_validation_tasks';

    public const CONTRACT_FIELD = 'update_validators';

    public function __construct(
        private readonly LongPoller $longPoller,
        private readonly LongPollSignalStore $signals,
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly ServerWorkflowControlPlane $controlPlane,
    ) {}

    /**
     * @return array{state: 'required'|'not_required'|'missing', validators: list<string>}
     */
    public function declaration(WorkflowRun $run, string $updateName): array
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->orderBy('sequence')
            ->first();
        $payload = $event?->payload;

        if (! is_array($payload) || ! array_key_exists('declared_update_validators', $payload)) {
            return ['state' => 'missing', 'validators' => []];
        }

        $validators = $this->stringList($payload['declared_update_validators']);

        return [
            'state' => in_array($updateName, $validators, true) ? 'required' : 'not_required',
            'validators' => $validators,
        ];
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array<string, mixed>
     */
    public function validate(
        string $namespace,
        WorkflowRun $run,
        string $updateName,
        array $arguments,
        string $requestId,
        CommandContext $commandContext,
    ): array {
        [$encoding, $serializedArguments] = $this->controlPlane->encodeArgumentsForRun($run, $arguments);
        $inputHash = hash('sha256', $encoding."\0".$serializedArguments);
        $idempotencyKey = hash('sha256', implode("\0", [
            $namespace,
            (string) $run->id,
            $updateName,
            $requestId,
        ]));

        $task = $this->findOrCreateTask(
            $namespace,
            $run,
            $updateName,
            $requestId,
            $idempotencyKey,
            $inputHash,
            $encoding,
            $serializedArguments,
            $commandContext,
        );

        if (! hash_equals($task->input_hash, $inputHash)) {
            return $this->failure(
                $run,
                $updateName,
                'update_validation_idempotency_conflict',
                'The update request ID was already used with different arguments.',
                409,
                updateId: $task->id,
            );
        }

        if ($this->terminal($task->status)) {
            return $this->result($task, $run, $updateName);
        }

        $route = $this->route($namespace, $run, $updateName);

        if (! $route['available']) {
            return $this->failure(
                $run,
                $updateName,
                (string) $route['reason'],
                (string) $route['message'],
                409,
                updateId: $task->id,
                retryable: (bool) $route['retryable'],
            );
        }

        $this->signals->signalUpdateValidationTaskQueue($namespace, $task->task_queue);
        $this->releaseDatabaseConnectionBeforeWait($run);

        /** @var WorkflowUpdateValidationTask $resolved */
        $resolved = $this->longPoller->until(
            fn (): WorkflowUpdateValidationTask => $task->fresh() ?? $task,
            fn (WorkflowUpdateValidationTask $candidate): bool => $this->terminal($candidate->status),
            $this->timeoutSeconds(),
            wakeChannels: [$this->signals->updateValidationTaskResultChannel($task->id)],
        );

        if (! $this->terminal($resolved->status)) {
            $resolved = $this->markTimedOut($resolved);
        }

        return $this->result($resolved, $run, $updateName);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function poll(
        string $namespace,
        WorkerRegistration $worker,
        ?int $timeoutSeconds = null,
    ): ?array {
        if (! $this->workerSupportsValidation($worker)) {
            return null;
        }

        $task = $this->longPoller->until(
            fn (): ?array => $this->claimAvailable($namespace, $worker),
            static fn (?array $candidate): bool => is_array($candidate),
            $timeoutSeconds,
            wakeChannels: $this->signals->updateValidationTaskPollChannels($namespace, $worker->task_queue),
            reserveWorkerWaitSlot: true,
            waitSlotPool: 'query-task',
            waitSlotNamespace: $namespace,
        );

        return is_array($task) ? $task : null;
    }

    /**
     * Claim at most one validation task without opening a nested long poll.
     *
     * @return array<string, mixed>|null
     */
    public function claimAvailable(string $namespace, WorkerRegistration $worker): ?array
    {
        if (! $this->workerSupportsValidation($worker)) {
            return null;
        }

        $task = $this->claim($namespace, $worker);

        return $task instanceof WorkflowUpdateValidationTask
            ? $this->taskPayload($task)
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function cachedTaskStillDeliverable(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $payload,
    ): bool {
        $taskId = $this->stringValue($payload['update_validation_task_id'] ?? null);

        if ($taskId === null) {
            return false;
        }

        /** @var WorkflowUpdateValidationTask|null $task */
        $task = WorkflowUpdateValidationTask::query()->find($taskId);

        if (! $task instanceof WorkflowUpdateValidationTask
            || $task->namespace !== $namespace
            || $task->task_queue !== $taskQueue
            || $task->status !== WorkflowUpdateValidationTask::STATUS_LEASED
            || $task->lease_owner !== $leaseOwner
            || ! $task->lease_expires_at instanceof Carbon
            || $task->lease_expires_at->lte(now())
        ) {
            return false;
        }

        $attempt = is_numeric($payload['update_validation_attempt'] ?? null)
            ? (int) $payload['update_validation_attempt']
            : null;

        return $attempt === null || $task->attempt_count === $attempt;
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(
        string $namespace,
        string $taskId,
        string $leaseOwner,
        int $attempt,
    ): array {
        return DB::transaction(function () use ($namespace, $taskId, $leaseOwner, $attempt): array {
            $task = WorkflowUpdateValidationTask::query()->lockForUpdate()->find($taskId);
            $guard = $this->guardCompletion($task, $namespace, $taskId, $leaseOwner, $attempt);

            if ($guard !== null) {
                return $guard;
            }

            $task->forceFill([
                'status' => WorkflowUpdateValidationTask::STATUS_APPROVED,
                'approved_at' => now(),
                'lease_expires_at' => null,
            ])->save();
            $this->signals->signalUpdateValidationTaskResult($taskId);

            return [
                'update_validation_task_id' => $taskId,
                'update_id' => $taskId,
                'update_validation_attempt' => $attempt,
                'outcome' => 'approved',
                'reason' => null,
                'status' => 200,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $failure
     * @return array<string, mixed>
     */
    public function reject(
        string $namespace,
        string $taskId,
        string $leaseOwner,
        int $attempt,
        array $failure,
    ): array {
        return DB::transaction(function () use ($namespace, $taskId, $leaseOwner, $attempt, $failure): array {
            $task = WorkflowUpdateValidationTask::query()->lockForUpdate()->find($taskId);
            $guard = $this->guardCompletion($task, $namespace, $taskId, $leaseOwner, $attempt);

            if ($guard !== null) {
                return $guard;
            }

            $reason = $this->stringValue($failure['reason'] ?? null) ?? 'update_validator_rejected';
            $validatorRejection = $reason === 'update_validator_rejected';
            $task->forceFill([
                'status' => $validatorRejection
                    ? WorkflowUpdateValidationTask::STATUS_REJECTED
                    : WorkflowUpdateValidationTask::STATUS_FAILED,
                'rejection_reason' => $reason,
                'rejection_message' => $this->stringValue($failure['message'] ?? null)
                    ?? ($validatorRejection ? 'The update validator rejected the request.' : 'Update validation failed.'),
                'failure_type' => $this->stringValue($failure['type'] ?? null),
                'validation_errors' => $this->validationErrors($failure['validation_errors'] ?? null),
                'rejected_at' => $validatorRejection ? now() : null,
                'failed_at' => $validatorRejection ? null : now(),
                'lease_expires_at' => null,
            ])->save();
            $this->signals->signalUpdateValidationTaskResult($taskId);

            return [
                'update_validation_task_id' => $taskId,
                'update_id' => $taskId,
                'update_validation_attempt' => $attempt,
                'outcome' => $validatorRejection ? 'rejected' : 'failed',
                'reason' => $reason,
                'status' => 200,
            ];
        });
    }

    public function approvedTask(string $taskId, string $runId, string $inputHash): bool
    {
        return WorkflowUpdateValidationTask::query()
            ->whereKey($taskId)
            ->where('workflow_run_id', $runId)
            ->where('input_hash', $inputHash)
            ->where('status', WorkflowUpdateValidationTask::STATUS_APPROVED)
            ->exists();
    }

    public function inputHash(WorkflowRun $run, array $arguments): string
    {
        [$encoding, $serializedArguments] = $this->controlPlane->encodeArgumentsForRun($run, $arguments);

        return hash('sha256', $encoding."\0".$serializedArguments);
    }

    private function findOrCreateTask(
        string $namespace,
        WorkflowRun $run,
        string $updateName,
        string $requestId,
        string $idempotencyKey,
        string $inputHash,
        string $encoding,
        string $arguments,
        CommandContext $commandContext,
    ): WorkflowUpdateValidationTask {
        $existing = WorkflowUpdateValidationTask::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof WorkflowUpdateValidationTask) {
            return $existing;
        }

        try {
            return WorkflowUpdateValidationTask::query()->create([
                'idempotency_key' => $idempotencyKey,
                'namespace' => $namespace,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'workflow_type' => $run->workflow_type,
                'task_queue' => $this->taskQueue($run),
                'compatibility' => $run->compatibility,
                'workflow_definition_fingerprint' => $this->recordedFingerprint($run),
                'update_name' => $updateName,
                'request_id' => $requestId,
                'input_hash' => $inputHash,
                'payload_codec' => $encoding,
                'arguments' => $arguments,
                'command_context' => $commandContext->attributes(),
                'status' => WorkflowUpdateValidationTask::STATUS_PENDING,
            ]);
        } catch (QueryException $exception) {
            $existing = WorkflowUpdateValidationTask::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof WorkflowUpdateValidationTask) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function claim(string $namespace, WorkerRegistration $worker): ?WorkflowUpdateValidationTask
    {
        return DB::transaction(function () use ($namespace, $worker): ?WorkflowUpdateValidationTask {
            /** @var Collection<int, WorkflowUpdateValidationTask> $tasks */
            $tasks = WorkflowUpdateValidationTask::query()
                ->where('namespace', $namespace)
                ->where('task_queue', $worker->task_queue)
                ->where(static function ($query): void {
                    $query->where('status', WorkflowUpdateValidationTask::STATUS_PENDING)
                        ->orWhere(static function ($query): void {
                            $query->where('status', WorkflowUpdateValidationTask::STATUS_LEASED)
                                ->where('lease_expires_at', '<=', now());
                        });
                })
                ->orderBy('created_at')
                ->lockForUpdate()
                ->limit(25)
                ->get();

            foreach ($tasks as $task) {
                if (! $this->workerMatchesTask($worker, $task)) {
                    continue;
                }

                $task->forceFill([
                    'status' => WorkflowUpdateValidationTask::STATUS_LEASED,
                    'attempt_count' => $task->attempt_count + 1,
                    'lease_owner' => $worker->worker_id,
                    'lease_expires_at' => now()->addSeconds($this->leaseTimeoutSeconds()),
                ])->save();

                return $task;
            }

            return null;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(WorkflowUpdateValidationTask $task): array
    {
        $run = WorkflowRun::query()->find($task->workflow_run_id);
        $replay = $run instanceof WorkflowRun
            ? $this->queryTasks->workflowReplayPayload($task->namespace, $run)
            : [];
        $argumentCarrier = new WorkflowRun;
        $argumentCarrier->forceFill([
            'namespace' => $task->namespace,
            'payload_codec' => $task->payload_codec,
            'arguments' => $task->arguments,
        ]);

        return array_merge($replay, [
            'task_kind' => 'update_validation',
            'update_validation_task_id' => $task->id,
            'update_id' => $task->id,
            'update_validation_attempt' => $task->attempt_count,
            'workflow_id' => $task->workflow_instance_id,
            'run_id' => $task->workflow_run_id,
            'workflow_type' => $task->workflow_type,
            'compatibility' => $task->compatibility,
            'update_name' => $task->update_name,
            'update_arguments' => $argumentCarrier->argumentsEnvelope(),
            'payload_codec' => $task->payload_codec,
            'task_queue' => $task->task_queue,
            'lease_owner' => $task->lease_owner,
            'lease_expires_at' => $task->lease_expires_at?->toJSON(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function guardCompletion(
        ?WorkflowUpdateValidationTask $task,
        string $namespace,
        string $taskId,
        string $leaseOwner,
        int $attempt,
    ): ?array {
        if (! $task instanceof WorkflowUpdateValidationTask || $task->namespace !== $namespace) {
            return $this->completionFailure($taskId, 'update_validation_task_not_found', 'Update validation task not found.', 404);
        }

        if ($this->terminal($task->status)) {
            if ($task->attempt_count !== $attempt || $task->lease_owner !== $leaseOwner) {
                return $this->completionFailure(
                    $taskId,
                    'stale_update_validation_completion',
                    'Update validation completion belongs to a stale delivery attempt.',
                    409,
                    $task,
                );
            }

            return $this->completionFailure(
                $taskId,
                'duplicate_update_validation_completion',
                'The update validation task already has a terminal result.',
                409,
                $task,
            );
        }

        if ($task->status !== WorkflowUpdateValidationTask::STATUS_LEASED) {
            return $this->completionFailure($taskId, 'update_validation_task_not_leased', 'Update validation task is not leased.', 409, $task);
        }

        if ($task->lease_owner !== $leaseOwner) {
            return $this->completionFailure($taskId, 'update_validation_lease_owner_mismatch', 'Update validation lease is owned by another worker.', 409, $task);
        }

        if ($task->attempt_count !== $attempt) {
            return $this->completionFailure($taskId, 'stale_update_validation_completion', 'Update validation completion belongs to a stale delivery attempt.', 409, $task);
        }

        if (! $task->lease_expires_at instanceof Carbon || $task->lease_expires_at->lte(now())) {
            return $this->completionFailure($taskId, 'update_validation_lease_expired', 'Update validation task lease has expired.', 409, $task);
        }

        $worker = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('worker_id', $leaseOwner)
            ->first();

        if (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker)) {
            return $this->completionFailure($taskId, 'update_validator_worker_lost', 'The validator worker is no longer active.', 409, $task);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function completionFailure(
        string $taskId,
        string $reason,
        string $message,
        int $status,
        ?WorkflowUpdateValidationTask $task = null,
    ): array {
        return array_filter([
            'update_validation_task_id' => $taskId,
            'update_id' => $taskId,
            'update_validation_attempt' => $task?->attempt_count,
            'outcome' => 'rejected',
            'reason' => $reason,
            'error' => $message,
            'status' => $status,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{available: bool, reason: string|null, message: string|null, retryable: bool}
     */
    private function route(string $namespace, WorkflowRun $run, string $updateName): array
    {
        $workers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $this->taskQueue($run))
            ->where('status', 'active')
            ->get()
            ->filter(fn (WorkerRegistration $worker): bool => WorkerPollFence::isFresh($worker));

        if ($workers->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'update_validator_worker_unavailable',
                'message' => 'No active worker is available to perform synchronous update validation.',
                'retryable' => true,
            ];
        }

        $capable = $workers->filter(
            fn (WorkerRegistration $worker): bool => $this->workerSupportsValidation($worker),
        );

        if ($capable->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'update_validation_capability_unsupported',
                'message' => 'Active workers do not advertise synchronous pre-accept update validation.',
                'retryable' => false,
            ];
        }

        $eligible = $capable->filter(function (WorkerRegistration $worker) use ($run, $updateName): bool {
            return $this->workerMatchesRun($worker, $run, $updateName);
        });

        if ($eligible->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'update_validator_worker_incompatible',
                'message' => 'Active workers do not advertise a validator-capable implementation matching this workflow run.',
                'retryable' => true,
            ];
        }

        return ['available' => true, 'reason' => null, 'message' => null, 'retryable' => false];
    }

    private function workerSupportsValidation(WorkerRegistration $worker): bool
    {
        return in_array(self::CAPABILITY, $this->stringList($worker->capabilities), true);
    }

    private function workerMatchesTask(WorkerRegistration $worker, WorkflowUpdateValidationTask $task): bool
    {
        if (! WorkerPollFence::isFresh($worker)
            || $worker->namespace !== $task->namespace
            || $worker->task_queue !== $task->task_queue
            || ! $this->workerSupportsValidation($worker)
        ) {
            return false;
        }

        if (! in_array($task->workflow_type, $this->stringList($worker->supported_workflow_types), true)) {
            return false;
        }

        if (! $this->workerContractDeclaresValidator($worker, $task->workflow_type, $task->update_name)) {
            return false;
        }

        if ($task->compatibility !== null && $worker->build_id !== $task->compatibility) {
            return false;
        }

        $fingerprints = is_array($worker->workflow_definition_fingerprints)
            ? $worker->workflow_definition_fingerprints
            : [];

        return $task->workflow_definition_fingerprint === null
            || ($fingerprints[$task->workflow_type] ?? null) === $task->workflow_definition_fingerprint;
    }

    private function workerMatchesRun(WorkerRegistration $worker, WorkflowRun $run, string $updateName): bool
    {
        if (! in_array((string) $run->workflow_type, $this->stringList($worker->supported_workflow_types), true)
            || ! $this->workerContractDeclaresValidator($worker, (string) $run->workflow_type, $updateName)
        ) {
            return false;
        }

        if ($run->compatibility !== null && $worker->build_id !== $run->compatibility) {
            return false;
        }

        $recorded = $this->recordedFingerprint($run);
        $fingerprints = is_array($worker->workflow_definition_fingerprints)
            ? $worker->workflow_definition_fingerprints
            : [];

        return $recorded === null || ($fingerprints[$run->workflow_type] ?? null) === $recorded;
    }

    private function workerContractDeclaresValidator(WorkerRegistration $worker, string $workflowType, string $updateName): bool
    {
        $contracts = is_array($worker->workflow_command_contracts)
            ? $worker->workflow_command_contracts
            : [];
        $contract = $contracts[$workflowType] ?? null;

        return is_array($contract)
            && in_array($updateName, $this->stringList($contract[self::CONTRACT_FIELD] ?? null), true);
    }

    private function recordedFingerprint(WorkflowRun $run): ?string
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->orderBy('sequence')
            ->first();

        return $this->stringValue($event?->payload['workflow_definition_fingerprint'] ?? null);
    }

    private function markTimedOut(WorkflowUpdateValidationTask $task): WorkflowUpdateValidationTask
    {
        return DB::transaction(function () use ($task): WorkflowUpdateValidationTask {
            /** @var WorkflowUpdateValidationTask $locked */
            $locked = WorkflowUpdateValidationTask::query()->lockForUpdate()->findOrFail($task->id);

            if ($this->terminal($locked->status)) {
                return $locked;
            }

            $worker = $locked->lease_owner === null
                ? null
                : WorkerRegistration::query()
                    ->where('namespace', $locked->namespace)
                    ->where('worker_id', $locked->lease_owner)
                    ->first();
            $workerLost = $locked->status === WorkflowUpdateValidationTask::STATUS_LEASED
                && (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker));
            $locked->forceFill([
                'status' => WorkflowUpdateValidationTask::STATUS_TIMED_OUT,
                'rejection_reason' => match (true) {
                    $workerLost => 'update_validator_worker_lost',
                    $locked->status === WorkflowUpdateValidationTask::STATUS_PENDING => 'update_validation_task_not_claimed',
                    default => 'update_validation_execution_timeout',
                },
                'rejection_message' => $workerLost
                    ? 'The validator worker was lost before returning a validation result.'
                    : 'Synchronous update validation did not complete before the acceptance deadline.',
                'timed_out_at' => now(),
                'lease_expires_at' => null,
            ])->save();

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function result(WorkflowUpdateValidationTask $task, WorkflowRun $run, string $updateName): array
    {
        if ($task->status === WorkflowUpdateValidationTask::STATUS_APPROVED) {
            return [
                'approved' => true,
                'update_validation_task_id' => $task->id,
                'update_id' => $task->id,
                'run_id' => $task->workflow_run_id,
                'input_hash' => $task->input_hash,
                'status' => 200,
            ];
        }

        return $this->failure(
            $run,
            $updateName,
            $task->rejection_reason ?? 'update_validation_failed',
            $task->rejection_message ?? 'Synchronous update validation failed.',
            match ($task->status) {
                WorkflowUpdateValidationTask::STATUS_REJECTED => 422,
                WorkflowUpdateValidationTask::STATUS_TIMED_OUT => 504,
                default => 409,
            },
            $this->validationErrors($task->validation_errors),
            $task->id,
            retryable: false,
            extra: array_filter([
                'failure_type' => $task->failure_type,
                'update_validation_attempt' => $task->attempt_count,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, list<string>>  $validationErrors
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failure(
        WorkflowRun $run,
        string $updateName,
        string $reason,
        string $message,
        int $status,
        array $validationErrors = [],
        ?string $updateId = null,
        bool $retryable = false,
        array $extra = [],
    ): array {
        return array_merge(array_filter([
            'approved' => false,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'update_name' => $updateName,
            'update_id' => $updateId,
            'update_status' => $reason === 'update_validator_rejected' ? 'rejected' : null,
            'outcome' => $reason === 'update_validator_rejected' ? 'rejected_invalid_arguments' : 'update_validation_failed',
            'reason' => $reason,
            'rejection_reason' => $reason,
            'message' => $message,
            'validation_errors' => $validationErrors === [] ? null : $validationErrors,
            'retryable' => $retryable,
            'status' => $status,
        ], static fn (mixed $value): bool => $value !== null), $extra);
    }

    private function releaseDatabaseConnectionBeforeWait(WorkflowRun $run): void
    {
        $connectionName = $run->getConnectionName();
        $connection = DB::connection($connectionName);

        if ($connection->transactionLevel() === 0) {
            DB::disconnect($connectionName);
        }
    }

    private function terminal(string $status): bool
    {
        return in_array($status, [
            WorkflowUpdateValidationTask::STATUS_APPROVED,
            WorkflowUpdateValidationTask::STATUS_REJECTED,
            WorkflowUpdateValidationTask::STATUS_FAILED,
            WorkflowUpdateValidationTask::STATUS_TIMED_OUT,
        ], true);
    }

    private function taskQueue(WorkflowRun $run): string
    {
        return $this->stringValue($run->queue) ?? 'default';
    }

    private function timeoutSeconds(): int
    {
        return max(0, (int) config('server.update_validation.timeout', 10));
    }

    private function leaseTimeoutSeconds(): int
    {
        return max(
            1,
            (int) config('server.update_validation.lease_timeout', $this->timeoutSeconds() + 5),
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringValue($item),
            $value,
        ))));
        sort($strings);

        return $strings;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validationErrors(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    $errors[(string) $field][] = $message;
                }
            }
        }

        return $errors;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
