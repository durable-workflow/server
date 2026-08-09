<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\UpdateWaitPolicy;
use Workflow\V2\Support\WorkflowExecutionGate;

final class ValidatedExternalWorkflowUpdateAdmission extends ExternalWorkflowUpdateAdmission
{
    public function __construct(
        private readonly ControlPlaneMutationRetrier $validationMutations,
        private readonly WorkflowUpdateValidationTaskBroker $validationTasks,
        private readonly ServerWorkflowControlPlane $controlPlane,
    ) {
        parent::__construct($validationMutations);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public function admit(
        string $namespace,
        string $workflowId,
        string $updateName,
        array $arguments,
        CommandContext $commandContext,
        string $waitFor = UpdateWaitPolicy::WAIT_FOR_ACCEPTED,
        ?int $waitTimeoutSeconds = null,
    ): ?array {
        $validation = $this->preAdmissionValidation(
            $namespace,
            $workflowId,
            $updateName,
            $arguments,
            $commandContext,
        );

        if (is_array($validation['result'] ?? null)) {
            $validationResult = $validation['result'];

            return ($validationResult['reason'] ?? null) === 'update_validator_rejected'
                ? $this->recordValidatorRejection(
                    $namespace,
                    $workflowId,
                    $updateName,
                    is_array($validation['arguments'] ?? null) ? $validation['arguments'] : $arguments,
                    $commandContext,
                    $validationResult,
                    $waitFor,
                    $waitTimeoutSeconds,
                )
                : $validationResult;
        }

        $taskId = is_string($validation['task_id'] ?? null) ? $validation['task_id'] : null;

        if ($taskId === null) {
            return parent::admit(
                $namespace,
                $workflowId,
                $updateName,
                $arguments,
                $commandContext,
                $waitFor,
                $waitTimeoutSeconds,
            );
        }

        $normalizedArguments = is_array($validation['arguments'] ?? null)
            ? $validation['arguments']
            : array_values($arguments);
        $validationRunId = is_string($validation['run_id'] ?? null) ? $validation['run_id'] : '';
        $inputHash = is_string($validation['input_hash'] ?? null) ? $validation['input_hash'] : '';
        $validatedContext = $this->validatedCommandContext($commandContext, $taskId, $inputHash);
        $result = $this->commitValidatorApproval(
            $namespace,
            $workflowId,
            $updateName,
            $normalizedArguments,
            $validatedContext,
            $taskId,
            $validationRunId,
            $inputHash,
            $waitTimeoutSeconds,
        );

        if (($result['update_status'] ?? null) === UpdateStatus::Accepted->value
            && $waitFor === UpdateWaitPolicy::WAIT_FOR_COMPLETED
        ) {
            return parent::admit(
                $namespace,
                $workflowId,
                $updateName,
                $normalizedArguments,
                $validatedContext,
                $waitFor,
                $waitTimeoutSeconds,
            ) ?? $result;
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function preAdmissionValidation(
        string $namespace,
        string $workflowId,
        string $updateName,
        array $arguments,
        CommandContext $commandContext,
    ): array {
        /** @var WorkflowInstance|null $instance */
        $instance = WorkflowInstance::query()
            ->where('namespace', $namespace)
            ->find($workflowId);
        /** @var WorkflowRun|null $run */
        $run = $instance instanceof WorkflowInstance && is_string($instance->current_run_id)
            ? WorkflowRun::query()->find($instance->current_run_id)
            : null;

        if (! $run instanceof WorkflowRun || ! $this->isRemoteUpdate($run, $updateName)) {
            return [];
        }

        $declaration = $this->validationTasks->declaration($run, $updateName);

        if ($declaration['state'] === 'missing') {
            return [
                'result' => $this->validationFailure(
                    $run,
                    $updateName,
                    'update_validator_contract_missing',
                    'The durable workflow contract does not state whether this update has a validator.',
                    409,
                ),
            ];
        }

        if ($declaration['state'] === 'not_required') {
            return [];
        }

        $validated = $this->validatedArguments($run, $updateName, $arguments);

        if ($validated['validation_errors'] !== []) {
            return [
                'arguments' => $validated['arguments'],
                'result' => $this->validationFailure(
                    $run,
                    $updateName,
                    'invalid_update_arguments',
                    sprintf('Workflow update [%s] argument validation failed.', $updateName),
                    422,
                    validationErrors: $validated['validation_errors'],
                ),
            ];
        }

        $requestId = $this->requestId($commandContext);

        if ($requestId === null) {
            return [
                'arguments' => $validated['arguments'],
                'result' => $this->validationFailure(
                    $run,
                    $updateName,
                    'update_validation_request_id_required',
                    'Updates with declared validators require request_id so validation and application remain idempotent across retries.',
                    422,
                    retryable: true,
                ),
            ];
        }

        $result = $this->validationTasks->validate(
            $namespace,
            $run,
            $updateName,
            $validated['arguments'],
            $requestId,
            $commandContext,
        );

        if (($result['approved'] ?? false) !== true) {
            return [
                'arguments' => $validated['arguments'],
                'result' => $result,
            ];
        }

        return [
            'arguments' => $validated['arguments'],
            'task_id' => $result['update_validation_task_id'],
            'run_id' => $result['run_id'],
            'input_hash' => $result['input_hash'],
        ];
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function commitValidatorApproval(
        string $namespace,
        string $workflowId,
        string $updateName,
        array $arguments,
        CommandContext $commandContext,
        string $taskId,
        string $validationRunId,
        string $inputHash,
        ?int $waitTimeoutSeconds,
    ): array {
        return $this->validationMutations->run(fn (): array => DB::transaction(function () use (
            $namespace,
            $workflowId,
            $updateName,
            $arguments,
            $commandContext,
            $taskId,
            $validationRunId,
            $inputHash,
            $waitTimeoutSeconds,
        ): array {
            /** @var WorkflowInstance|null $instance */
            $instance = WorkflowInstance::query()
                ->where('namespace', $namespace)
                ->lockForUpdate()
                ->find($workflowId);
            /** @var WorkflowRun|null $run */
            $run = $instance instanceof WorkflowInstance && is_string($instance->current_run_id)
                ? WorkflowRun::query()->lockForUpdate()->find($instance->current_run_id)
                : null;

            if (! $run instanceof WorkflowRun || $validationRunId !== $run->id) {
                return $this->staleValidationFailure(
                    $workflowId,
                    $validationRunId,
                    $updateName,
                    $taskId,
                    'The workflow advanced to a different run before validation could be committed.',
                );
            }

            if ($inputHash === '' || ! $this->validationTasks->approvedTask($taskId, $run->id, $inputHash)) {
                return $this->staleValidationFailure(
                    $workflowId,
                    $run->id,
                    $updateName,
                    $taskId,
                    'The validator approval no longer matches this update admission attempt.',
                );
            }

            $result = parent::admit(
                $namespace,
                $workflowId,
                $updateName,
                $arguments,
                $commandContext,
                UpdateWaitPolicy::WAIT_FOR_ACCEPTED,
                $waitTimeoutSeconds,
            );

            return is_array($result)
                ? $result
                : $this->staleValidationFailure(
                    $workflowId,
                    $run->id,
                    $updateName,
                    $taskId,
                    'The validated update could not be committed to the current workflow run.',
                );
        }));
    }

    /**
     * @param  list<mixed>  $arguments
     * @param  array<string, mixed>  $validationResult
     * @return array<string, mixed>
     */
    private function recordValidatorRejection(
        string $namespace,
        string $workflowId,
        string $updateName,
        array $arguments,
        CommandContext $commandContext,
        array $validationResult,
        string $waitFor,
        ?int $waitTimeoutSeconds,
    ): array {
        return $this->validationMutations->run(fn (): array => DB::transaction(function () use (
            $namespace,
            $workflowId,
            $updateName,
            $arguments,
            $commandContext,
            $validationResult,
            $waitFor,
            $waitTimeoutSeconds,
        ): array {
            /** @var WorkflowInstance|null $instance */
            $instance = WorkflowInstance::query()
                ->where('namespace', $namespace)
                ->lockForUpdate()
                ->find($workflowId);
            /** @var WorkflowRun|null $run */
            $run = $instance instanceof WorkflowInstance && is_string($instance->current_run_id)
                ? WorkflowRun::query()->lockForUpdate()->find($instance->current_run_id)
                : null;
            $taskId = is_string($validationResult['update_id'] ?? null)
                ? $validationResult['update_id']
                : null;

            if (! $instance instanceof WorkflowInstance
                || ! $run instanceof WorkflowRun
                || $taskId === null
                || ($validationResult['run_id'] ?? null) !== $run->id
            ) {
                return $this->staleValidationFailure(
                    $workflowId,
                    is_string($validationResult['run_id'] ?? null) ? $validationResult['run_id'] : '',
                    $updateName,
                    $taskId,
                    'The workflow run changed before the validator rejection could be recorded.',
                );
            }

            /** @var WorkflowUpdate|null $existing */
            $existing = WorkflowUpdate::query()->find($taskId);

            if ($existing instanceof WorkflowUpdate) {
                return $this->rejectionResponse(
                    $validationResult,
                    $existing,
                    $waitFor,
                    $waitTimeoutSeconds,
                );
            }

            [$argumentsEncoding, $serializedArguments] = $this->controlPlane->encodeArgumentsForRun($run, $arguments);
            $validationErrors = is_array($validationResult['validation_errors'] ?? null)
                ? $validationResult['validation_errors']
                : [];
            [$commandEncoding, $serializedCommand] = $this->controlPlane->encodeArgumentsForRun($run, [
                'name' => $updateName,
                'arguments' => $arguments,
                'validation_errors' => $validationErrors,
            ]);
            $rejectedAt = now();
            $command = WorkflowCommand::record($instance, $run, array_merge(
                $commandContext->attributes(),
                [
                    'command_type' => CommandType::Update->value,
                    'target_scope' => 'instance',
                    'status' => CommandStatus::Rejected->value,
                    'outcome' => CommandOutcome::RejectedInvalidArguments->value,
                    'payload_codec' => $commandEncoding,
                    'payload' => $serializedCommand,
                    'rejection_reason' => 'update_validator_rejected',
                    'rejected_at' => $rejectedAt,
                ],
            ));
            /** @var WorkflowUpdate $update */
            $update = WorkflowUpdate::query()->create([
                'id' => $taskId,
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'target_scope' => $command->target_scope,
                'requested_workflow_run_id' => $command->requestedRunId(),
                'resolved_workflow_run_id' => $command->resolvedRunId(),
                'update_name' => $updateName,
                'status' => UpdateStatus::Rejected->value,
                'outcome' => CommandOutcome::RejectedInvalidArguments->value,
                'command_sequence' => $command->command_sequence,
                'payload_codec' => $argumentsEncoding,
                'arguments' => $serializedArguments,
                'validation_errors' => $validationErrors,
                'rejection_reason' => 'update_validator_rejected',
                'rejected_at' => $rejectedAt,
                'closed_at' => $rejectedAt,
            ]);
            WorkflowHistoryEvent::record($run, HistoryEventType::UpdateRejected, [
                'workflow_command_id' => $command->id,
                'update_id' => $update->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'update_name' => $updateName,
                'arguments' => $serializedArguments,
                'validation_errors' => $validationErrors,
            ], null, $command);
            app(HistoryProjectionRole::class)->projectRun(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents']) ?? $run,
            );

            return $this->rejectionResponse(
                $validationResult,
                $update,
                $waitFor,
                $waitTimeoutSeconds,
                $command,
            );
        }));
    }

    private function isRemoteUpdate(WorkflowRun $run, string $updateName): bool
    {
        if (! in_array($run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true)) {
            return false;
        }

        if (WorkflowExecutionGate::blockedReason($run) !== WorkflowExecutionGate::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE) {
            return false;
        }

        $contract = RunCommandContract::forRun($run);

        return ($contract['source'] ?? null) === RunCommandContract::SOURCE_DURABLE_HISTORY
            && in_array($updateName, $this->stringList($contract['updates'] ?? []), true);
    }

    private function requestId(CommandContext $commandContext): ?string
    {
        $attributes = $commandContext->attributes();
        $value = data_get($attributes, 'context.server.metadata.request_id');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function validatedCommandContext(
        CommandContext $commandContext,
        string $taskId,
        string $inputHash,
    ): CommandContext {
        return $commandContext->with([
            'server' => [
                'metadata' => [
                    'update_validation_task_id' => $taskId,
                    'update_validation_input_hash' => $inputHash,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validationResult
     * @return array<string, mixed>
     */
    private function rejectionResponse(
        array $validationResult,
        WorkflowUpdate $update,
        string $waitFor,
        ?int $waitTimeoutSeconds,
        ?WorkflowCommand $command = null,
    ): array {
        $command ??= WorkflowCommand::query()->find($update->workflow_command_id);

        return array_merge($validationResult, [
            'accepted' => false,
            'command_id' => $command?->id,
            'workflow_command_id' => $command?->id,
            'command_status' => CommandStatus::Rejected->value,
            'outcome' => CommandOutcome::RejectedInvalidArguments->value,
            'update_id' => $update->id,
            'update_status' => UpdateStatus::Rejected->value,
            'rejection_reason' => 'update_validator_rejected',
            'wait_for' => $waitFor,
            'wait_timed_out' => false,
            'wait_timeout_seconds' => $waitTimeoutSeconds,
            'status' => 422,
        ]);
    }

    /**
     * @param  array<string, list<string>>  $validationErrors
     * @return array<string, mixed>
     */
    private function validationFailure(
        WorkflowRun $run,
        string $updateName,
        string $reason,
        string $message,
        int $status,
        bool $retryable = false,
        array $validationErrors = [],
    ): array {
        return array_filter([
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'update_name' => $updateName,
            'outcome' => 'update_validation_failed',
            'reason' => $reason,
            'rejection_reason' => $reason,
            'message' => $message,
            'validation_errors' => $validationErrors === [] ? null : $validationErrors,
            'retryable' => $retryable,
            'status' => $status,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function staleValidationFailure(
        string $workflowId,
        string $runId,
        string $updateName,
        ?string $taskId,
        string $message,
    ): array {
        return array_filter([
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'update_name' => $updateName,
            'update_id' => $taskId,
            'outcome' => 'update_validation_failed',
            'reason' => 'stale_update_validation_completion',
            'rejection_reason' => 'stale_update_validation_completion',
            'message' => $message,
            'retryable' => true,
            'status' => 409,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }
}
