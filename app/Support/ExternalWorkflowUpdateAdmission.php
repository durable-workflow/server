<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\StructuralLimits;
use Workflow\V2\Support\UpdateCommandGate;
use Workflow\V2\Support\UpdateWaitPolicy;
use Workflow\V2\Support\WorkflowExecutionGate;
use Workflow\V2\Support\WorkflowTaskPayload;

class ExternalWorkflowUpdateAdmission
{
    public function __construct(
        private readonly ControlPlaneMutationRetrier $mutations,
    ) {}

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
        $result = $this->mutations->run(fn (): ?array => DB::transaction(function () use (
            $namespace,
            $workflowId,
            $updateName,
            $arguments,
            $commandContext,
            $waitFor,
            $waitTimeoutSeconds,
        ): ?array {
            /** @var WorkflowInstance|null $instance */
            $instance = WorkflowInstance::query()
                ->where('namespace', $namespace)
                ->lockForUpdate()
                ->find($workflowId);

            if (! $instance instanceof WorkflowInstance || ! is_string($instance->current_run_id)) {
                return null;
            }

            /** @var WorkflowRun|null $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->find($instance->current_run_id);

            if (! $run instanceof WorkflowRun || $run->workflow_instance_id !== $instance->id) {
                return null;
            }

            if (! $this->shouldHandle($run, $updateName)) {
                return null;
            }

            $requestId = $this->requestId($commandContext);
            $duplicate = $this->duplicateForRequest($run, $updateName, $requestId);

            if ($duplicate !== null) {
                return $this->resultPayload(
                    $duplicate['command'],
                    $duplicate['update'],
                    $waitFor,
                    false,
                    $waitTimeoutSeconds,
                );
            }

            $validated = $this->validatedArgumentsForRun($run, $updateName, $arguments);

            if ($validated['validation_errors'] !== []) {
                return null;
            }

            try {
                StructuralLimits::guardPendingUpdates($run);
            } catch (StructuralLimitExceededException) {
                return null;
            }

            $arguments = $validated['arguments'];
            $codec = PayloadCodecContract::canonicalize($run->payload_codec);
            $serializedArguments = Serializer::serializeWithCodec($codec, $arguments);

            try {
                StructuralLimits::guardPayloadSize($serializedArguments);
            } catch (StructuralLimitExceededException) {
                return null;
            }

            $now = now();
            $command = WorkflowCommand::record($instance, $run, array_merge(
                $commandContext->attributes(),
                [
                    'command_type' => CommandType::Update->value,
                    'target_scope' => 'instance',
                    'status' => CommandStatus::Accepted->value,
                    'payload_codec' => PayloadCodecContract::CODEC,
                    'payload' => Serializer::serializeWithCodec(PayloadCodecContract::CODEC, [
                        'name' => $updateName,
                        'arguments' => $arguments,
                        'validation_errors' => [],
                    ]),
                    'accepted_at' => $now,
                ],
            ));

            /** @var WorkflowUpdate $update */
            $update = WorkflowUpdate::query()->create([
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'target_scope' => $command->target_scope,
                'requested_workflow_run_id' => $command->requestedRunId(),
                'resolved_workflow_run_id' => $command->resolvedRunId(),
                'update_name' => $updateName,
                'status' => UpdateStatus::Accepted->value,
                'command_sequence' => $command->command_sequence,
                'payload_codec' => $codec,
                'arguments' => $serializedArguments,
                'accepted_at' => $command->accepted_at,
            ]);

            $predecessor = UpdateCommandGate::blockingSignal($run, $command->command_sequence);

            $accepted = WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
                'workflow_command_id' => $command->id,
                'update_id' => $update->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'update_name' => $updateName,
                'arguments' => $serializedArguments,
                'ordering_state' => $predecessor instanceof WorkflowCommand ? 'queued' : 'ready',
                'queued_behind_command_id' => $predecessor?->id,
                'queued_behind_command_sequence' => $predecessor?->command_sequence,
                'queued_behind_command_type' => $predecessor?->command_type?->value,
            ], null, $command);

            if ($requestId !== null) {
                $payload = is_array($accepted->payload) ? $accepted->payload : [];
                $payload['request_id'] = $requestId;
                $accepted->forceFill(['payload' => $payload])->save();
            }

            if (! $predecessor instanceof WorkflowCommand) {
                $this->wakeWorkflowTask($run, $update);
            }
            $this->projectRun($run);

            return $this->resultPayload($command, $update, $waitFor, false, $waitTimeoutSeconds);
        }));

        if (($result['update_status'] ?? null) === UpdateStatus::Accepted->value
            && $waitFor === UpdateWaitPolicy::WAIT_FOR_COMPLETED
        ) {
            return $this->waitForCompletion($result, $waitTimeoutSeconds);
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    public function validatedArguments(WorkflowRun $run, string $updateName, array $arguments): array
    {
        return $this->validatedArgumentsForRun($run, $updateName, $arguments);
    }

    private function shouldHandle(WorkflowRun $run, string $updateName): bool
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

    /**
     * @return array{command: WorkflowCommand, update: WorkflowUpdate}|null
     */
    private function duplicateForRequest(WorkflowRun $run, string $updateName, ?string $requestId): ?array
    {
        if ($requestId === null) {
            return null;
        }

        /** @var Collection<int, WorkflowCommand> $commands */
        $commands = WorkflowCommand::query()
            ->where('workflow_run_id', $run->id)
            ->where('command_type', CommandType::Update->value)
            ->orderByDesc('command_sequence')
            ->orderByDesc('created_at')
            ->get();

        foreach ($commands as $command) {
            if ($command->targetName() !== $updateName) {
                continue;
            }

            if ($this->requestIdFromStoredContext($command->context) !== $requestId) {
                continue;
            }

            /** @var WorkflowUpdate|null $update */
            $update = WorkflowUpdate::query()
                ->where('workflow_command_id', $command->id)
                ->first();

            if ($update instanceof WorkflowUpdate) {
                return [
                    'command' => $command,
                    'update' => $update,
                ];
            }
        }

        return null;
    }

    private function wakeWorkflowTask(WorkflowRun $run, WorkflowUpdate $update): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('available_at')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();

        if ($task instanceof WorkflowTask) {
            $this->mergeWorkflowTaskPayload($task, WorkflowTaskPayload::forUpdate($update));

            return;
        }

        $hasOpenTask = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->exists();

        if ($hasOpenTask) {
            return;
        }

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => WorkflowTaskPayload::forUpdate($update),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'priority' => $run->priority ?? 5,
            'fairness_key' => $run->fairness_key,
            'fairness_weight' => $run->fairness_weight ?? 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mergeWorkflowTaskPayload(WorkflowTask $task, array $payload): void
    {
        $existing = is_array($task->payload) ? $task->payload : [];
        $existingWaitKind = is_string($existing['workflow_wait_kind'] ?? null)
            ? $existing['workflow_wait_kind']
            : null;
        $newWaitKind = is_string($payload['workflow_wait_kind'] ?? null)
            ? $payload['workflow_wait_kind']
            : null;

        if ($existingWaitKind !== null && $newWaitKind !== null && $existingWaitKind !== $newWaitKind) {
            return;
        }

        $task->forceFill([
            'payload' => array_filter(
                array_merge($existing, $payload),
                static fn (mixed $value): bool => $value !== null,
            ),
        ])->save();
    }

    private function projectRun(WorkflowRun $run): void
    {
        app(HistoryProjectionRole::class)->projectRun(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents']) ?? $run,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function waitForCompletion(array $result, ?int $waitTimeoutSeconds): array
    {
        $updateId = is_string($result['update_id'] ?? null) ? $result['update_id'] : null;

        if ($updateId === null) {
            return $result;
        }

        $timeoutSeconds = $waitTimeoutSeconds ?? UpdateWaitPolicy::completionTimeoutSeconds();
        $deadline = microtime(true) + $timeoutSeconds;
        $interval = UpdateWaitPolicy::pollIntervalMilliseconds() / 1000;
        $timedOut = false;

        do {
            /** @var WorkflowUpdate|null $update */
            $update = WorkflowUpdate::query()->find($updateId);

            if (! $update instanceof WorkflowUpdate || $update->status !== UpdateStatus::Accepted) {
                /** @var WorkflowCommand|null $command */
                $command = $update instanceof WorkflowUpdate
                    ? WorkflowCommand::query()->find($update->workflow_command_id)
                    : null;

                if ($command instanceof WorkflowCommand && $update instanceof WorkflowUpdate) {
                    return $this->resultPayload($command, $update, UpdateWaitPolicy::WAIT_FOR_COMPLETED, false, $timeoutSeconds);
                }

                return $result;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            usleep((int) (min($interval, $remaining) * 1000000));
        } while (true);

        /** @var WorkflowUpdate|null $update */
        $update = WorkflowUpdate::query()->find($updateId);
        /** @var WorkflowCommand|null $command */
        $command = $update instanceof WorkflowUpdate
            ? WorkflowCommand::query()->find($update->workflow_command_id)
            : null;

        return $command instanceof WorkflowCommand && $update instanceof WorkflowUpdate
            ? $this->resultPayload($command, $update, UpdateWaitPolicy::WAIT_FOR_COMPLETED, $timedOut, $timeoutSeconds)
            : $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultPayload(
        WorkflowCommand $command,
        WorkflowUpdate $update,
        string $waitFor,
        bool $waitTimedOut,
        ?int $waitTimeoutSeconds,
    ): array {
        $result = null;
        $resultEnvelope = null;

        if ($update->status === UpdateStatus::Completed) {
            try {
                $result = $update->updateResult();
                $resultEnvelope = $update->resultEnvelope();
            } catch (Throwable) {
                $result = null;
                $resultEnvelope = null;
            }
        }

        $outcome = $command->outcome?->value;
        $updateStatus = $update->status?->value;

        return [
            'outcome' => $outcome,
            'workflow_id' => $command->workflow_instance_id,
            'workflow_instance_id' => $command->workflow_instance_id,
            'run_id' => $command->workflow_run_id,
            'requested_run_id' => $command->requestedRunId(),
            'resolved_run_id' => $command->resolvedRunId(),
            'command_id' => $command->id,
            'workflow_command_id' => $command->id,
            'command_sequence' => $command->command_sequence,
            'target_scope' => $command->target_scope,
            'workflow_type' => $command->workflow_type,
            'command_status' => $command->status?->value,
            'command_source' => $command->source,
            'command_reason' => $command->commandReason(),
            'reason' => $command->status === CommandStatus::Rejected ? $command->rejection_reason : null,
            'rejection_reason' => $command->rejection_reason,
            'validation_errors' => $command->validationErrors(),
            'update_id' => $update->id,
            'update_status' => $updateStatus,
            'update_name' => $update->update_name,
            'workflow_sequence' => $update->workflow_sequence,
            'result' => $result,
            'result_envelope' => $resultEnvelope,
            'failure_id' => $update->failure_id,
            'failure_message' => $update->failure_message,
            'principal' => $this->principalPayload($command),
            'accepted_at' => $update->accepted_at?->toJSON(),
            'applied_at' => $update->applied_at?->toJSON(),
            'rejected_at' => $update->rejected_at?->toJSON(),
            'closed_at' => $update->closed_at?->toJSON(),
            'wait_for' => $waitFor,
            'wait_timed_out' => $waitTimedOut,
            'wait_timeout_seconds' => $waitTimeoutSeconds,
            'accepted' => $command->status === CommandStatus::Accepted,
            'status' => $this->statusCode($command, $update, $outcome, $updateStatus),
        ];
    }

    private function statusCode(
        WorkflowCommand $command,
        WorkflowUpdate $update,
        ?string $outcome,
        ?string $updateStatus,
    ): int {
        return match (true) {
            $outcome === CommandOutcome::RejectedUnknownUpdate->value => 404,
            $outcome === CommandOutcome::RejectedInvalidArguments->value => 422,
            $command->status === CommandStatus::Rejected => 409,
            $update->status === UpdateStatus::Failed => 422,
            $updateStatus === UpdateStatus::Accepted->value => 202,
            default => 200,
        };
    }

    /**
     * @return array{type?: string, id?: string, label?: string}|null
     */
    private function principalPayload(WorkflowCommand $command): ?array
    {
        $principal = array_filter([
            'type' => $command->principalType(),
            'id' => $command->principalId(),
            'label' => $command->principalLabel(),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        return $principal === [] ? null : $principal;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function validatedArgumentsForRun(WorkflowRun $run, string $updateName, array $arguments): array
    {
        $normalized = array_is_list($arguments) ? array_values($arguments) : [];
        $contract = RunCommandContract::updateContract($run, $updateName);

        if ($contract === null) {
            return array_is_list($arguments)
                ? [
                    'arguments' => $normalized,
                    'validation_errors' => [],
                ]
                : [
                    'arguments' => [],
                    'validation_errors' => [
                        'arguments' => ['Named arguments require a durable workflow update contract.'],
                    ],
                ];
        }

        return array_is_list($arguments)
            ? $this->normalizePositionalArguments($contract, $arguments)
            : $this->normalizeNamedArguments($contract, $arguments);
    }

    /**
     * @param  array{name: string, parameters: list<array<string, mixed>>}  $contract
     * @param  array<int, mixed>  $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function normalizePositionalArguments(array $contract, array $arguments): array
    {
        $normalized = [];
        $errors = [];
        $providedCount = count($arguments);
        $consumed = 0;

        foreach ($contract['parameters'] as $parameter) {
            if (($parameter['variadic'] ?? false) === true) {
                while ($consumed < $providedCount) {
                    $normalized[] = $arguments[$consumed];
                    $this->appendParameterValidationErrors($errors, $parameter, $arguments[$consumed]);
                    $consumed++;
                }

                continue;
            }

            if ($consumed < $providedCount) {
                $normalized[] = $arguments[$consumed];
                $this->appendParameterValidationErrors($errors, $parameter, $arguments[$consumed]);
                $consumed++;

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : 'argument';
                $errors[$name][] = sprintf('The %s argument is required.', $name);
            }
        }

        if ($consumed < $providedCount) {
            $errors['arguments'][] = sprintf('Too many arguments were provided for update [%s].', $contract['name']);
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param  array{name: string, parameters: list<array<string, mixed>>}  $contract
     * @param  array<string, mixed>  $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function normalizeNamedArguments(array $contract, array $arguments): array
    {
        $normalized = [];
        $errors = [];
        $known = [];

        foreach ($contract['parameters'] as $parameter) {
            $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : null;

            if ($name === null || $name === '') {
                continue;
            }

            $known[] = $name;

            if (($parameter['variadic'] ?? false) === true) {
                if (! array_key_exists($name, $arguments)) {
                    continue;
                }

                $values = is_array($arguments[$name]) ? array_values($arguments[$name]) : [$arguments[$name]];

                foreach ($values as $value) {
                    $normalized[] = $value;
                    $this->appendParameterValidationErrors($errors, $parameter, $value);
                }

                continue;
            }

            if (array_key_exists($name, $arguments)) {
                $normalized[] = $arguments[$name];
                $this->appendParameterValidationErrors($errors, $parameter, $arguments[$name]);

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$name][] = sprintf('The %s argument is required.', $name);
            }
        }

        foreach (array_keys($arguments) as $name) {
            if (! in_array((string) $name, $known, true)) {
                $errors[(string) $name][] = sprintf('Unknown argument [%s].', (string) $name);
            }
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $parameter
     */
    private function appendParameterValidationErrors(array &$errors, array $parameter, mixed $value): void
    {
        $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : 'argument';

        foreach ($this->validationErrorsForParameterValue($parameter, $value) as $message) {
            $errors[$name][] = $message;
        }
    }

    /**
     * @param  array<string, mixed>  $parameter
     * @return list<string>
     */
    private function validationErrorsForParameterValue(array $parameter, mixed $value): array
    {
        $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : 'argument';

        if ($value === null) {
            return $this->parameterAllowsNull($parameter)
                ? []
                : [sprintf('The %s argument cannot be null.', $name)];
        }

        $type = is_string($parameter['type'] ?? null) ? trim($parameter['type']) : '';

        if ($type === '' || $type === 'mixed' || $this->valueMatchesDeclaredType($value, $type)) {
            return [];
        }

        return [sprintf('The %s argument must be of type %s.', $name, $type)];
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function parameterAllowsNull(array $parameter): bool
    {
        if (is_bool($parameter['allows_null'] ?? null)) {
            return $parameter['allows_null'];
        }

        $type = is_string($parameter['type'] ?? null) ? trim($parameter['type']) : '';

        return $type === ''
            || str_starts_with($type, '?')
            || in_array('null', $this->splitDeclaredType($type, '|'), true);
    }

    private function valueMatchesDeclaredType(mixed $value, string $type): bool
    {
        $type = trim($type);

        if ($type === '' || $type === 'mixed') {
            return true;
        }

        if (str_starts_with($type, '?')) {
            return $value === null || $this->valueMatchesDeclaredType($value, substr($type, 1));
        }

        $unionTypes = $this->splitDeclaredType($type, '|');

        if (count($unionTypes) > 1) {
            foreach ($unionTypes as $unionType) {
                if ($this->valueMatchesDeclaredType($value, $unionType)) {
                    return true;
                }
            }

            return false;
        }

        $type = trim($type, "() \t\n\r\0\x0B");

        return match ($type) {
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'iterable' => is_iterable($value),
            'scalar' => is_scalar($value),
            'true' => $value === true,
            'false' => $value === false,
            'null' => $value === null,
            default => is_object($value)
                && (
                    (! class_exists($type) && ! interface_exists($type) && ! enum_exists($type))
                    || $value instanceof $type
                ),
        };
    }

    /**
     * @return list<string>
     */
    private function splitDeclaredType(string $type, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($index = 0, $length = strlen($type); $index < $length; $index++) {
            $character = $type[$index];

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && $depth > 0) {
                $depth--;
            }

            if ($character === $delimiter && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function requestId(CommandContext $commandContext): ?string
    {
        $attributes = $commandContext->attributes();
        $context = is_array($attributes['context'] ?? null) ? $attributes['context'] : [];

        return $this->requestIdFromStoredContext($context);
    }

    private function requestIdFromStoredContext(mixed $context): ?string
    {
        if (! is_array($context)) {
            return null;
        }

        $metadata = is_array($context['server']['metadata'] ?? null)
            ? $context['server']['metadata']
            : [];
        $requestId = $metadata['request_id'] ?? null;

        return is_string($requestId) && trim($requestId) !== '' ? trim($requestId) : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }
}
