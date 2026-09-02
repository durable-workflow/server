<?php

namespace App\Support;

use App\Contracts\RuntimeSignalControlPlane;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\CommandResult;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\CommandResponse;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Workflow;

final class ServerWorkflowControlPlane implements RuntimeSignalControlPlane, WorkflowControlPlane
{
    public function __construct(
        private readonly DefaultWorkflowControlPlane $inner,
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly ControlPlaneMutationRetrier $mutations,
        private readonly ControlPlaneFailureDiagnostics $failureDiagnostics,
        private readonly NamespaceDurableStateQuota $durableStateQuota,
    ) {}

    public function start(string $workflowType, ?string $instanceId = null, array $options = []): array
    {
        $namespace = $this->namespace($options);

        if ($namespace === null) {
            return $this->inner->start($workflowType, $instanceId, $options);
        }

        return $this->durableStateQuota->mutate(
            $namespace,
            [
                NamespaceDurableStateQuota::WORKFLOW_INSTANCES,
                NamespaceDurableStateQuota::WORKFLOW_RUNS,
                NamespaceDurableStateQuota::OPEN_WORKFLOW_RUNS,
            ],
            fn (): array => $this->inner->start($workflowType, $instanceId, $options),
        );
    }

    public function signal(string $instanceId, string $name, array $options = []): array
    {
        if (MessageStreamsContract::isRuntimeReservedSignal($name)) {
            return [
                'accepted' => false,
                'workflow_id' => $instanceId,
                'signal_name' => $name,
                'outcome' => 'rejected',
                'reason' => 'runtime_reserved_signal',
                'message' => 'This signal name is reserved for the durable runtime transport.',
                'status' => 422,
            ];
        }

        return $this->deliverSignal($instanceId, $name, $options, false);
    }

    public function runtimeSignal(string $instanceId, string $name, array $options = []): array
    {
        return $this->deliverSignal($instanceId, $name, $options, true);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function deliverSignal(string $instanceId, string $name, array $options, bool $runtimeReserved): array
    {
        return $this->mutations->run(
            function () use ($instanceId, $name, $options, $runtimeReserved): array {
                try {
                    return $runtimeReserved
                        ? $this->inner->runtimeSignal($instanceId, $name, $options)
                        : $this->inner->signal($instanceId, $name, $options);
                } catch (Throwable $exception) {
                    $command = $this->committedSignalForRequest($instanceId, $name, $options);

                    if (! $command instanceof WorkflowCommand) {
                        throw $exception;
                    }

                    $this->failureDiagnostics->reportRecoveredSignal($exception, $command, $name);

                    return $this->signalResult($command, $instanceId, $name);
                }
            },
            allBackends: true,
        );
    }

    public function query(string $instanceId, string $name, array $options = []): array
    {
        $namespace = $this->namespace($options);
        $run = $namespace !== null
            ? NamespaceWorkflowScope::currentRun($namespace, $instanceId)
            : null;

        if ($run instanceof WorkflowRun && $this->rejectsTerminalQuery($run)) {
            return $this->terminalRunQueryFailure($run, $name);
        }

        if ($namespace !== null
            && $run instanceof WorkflowRun
            && ($this->queryTasks->hasWorkerFor($namespace, $run) || $this->requiresQueryTaskRouting($run))) {
            return $this->queryTasks->query(
                $namespace,
                $run,
                $name,
                $this->queryArgumentsEnvelope($options, $run),
                $this->commandContext($options),
            );
        }

        return $this->inner->query($instanceId, $name, $options);
    }

    public function update(string $instanceId, string $name, array $options = []): array
    {
        return $this->mutations->run(
            fn (): array => $this->inner->update($instanceId, $name, $options),
        );
    }

    public function cancel(string $instanceId, array $options = []): array
    {
        return $this->mutations->run(
            fn (): array => $this->inner->cancel($instanceId, $options),
        );
    }

    public function terminate(string $instanceId, array $options = []): array
    {
        return $this->mutations->run(
            fn (): array => $this->inner->terminate($instanceId, $options),
        );
    }

    public function repair(string $instanceId, array $options = []): array
    {
        return $this->mutations->run(
            fn (): array => $this->inner->repair($instanceId, $options),
        );
    }

    public function archive(string $instanceId, array $options = []): array
    {
        return $this->mutations->run(
            fn (): array => $this->inner->archive($instanceId, $options),
        );
    }

    public function describe(string $instanceId, array $options = []): array
    {
        return $this->inner->describe($instanceId, $options);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array{string, string}
     */
    public function encodeArgumentsForRun(WorkflowRun $run, array $arguments): array
    {
        return array_values($this->queryArgumentsEnvelope([
            'arguments' => $arguments,
        ], $run));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function namespace(array $options): ?string
    {
        $namespace = $options['namespace'] ?? null;

        return is_string($namespace) && trim($namespace) !== ''
            ? trim($namespace)
            : null;
    }

    private function rejectsTerminalQuery(WorkflowRun $run): bool
    {
        return $run->status->isTerminal()
            && $run->status !== RunStatus::Completed;
    }

    private function requiresQueryTaskRouting(WorkflowRun $run): bool
    {
        return $this->nonEmptyString($run->compatibility) !== null
            || ! $this->canReplayQueryInProcess($run);
    }

    private function canReplayQueryInProcess(WorkflowRun $run): bool
    {
        $workflowClass = is_string($run->workflow_class) ? trim($run->workflow_class) : '';

        return $workflowClass !== ''
            && class_exists($workflowClass)
            && is_subclass_of($workflowClass, Workflow::class);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function queryArgumentsEnvelope(array $options, WorkflowRun $run): array
    {
        $codec = $this->nonEmptyString($options['payload_codec'] ?? null)
            ?? $this->nonEmptyString($run->payload_codec)
            ?? PayloadCodecContract::CODEC;

        $payloadBlob = $this->nonEmptyString($options['payload_blob'] ?? null);
        if ($payloadBlob !== null) {
            return [
                'codec' => PayloadCodecContract::canonicalize($codec),
                'blob' => $payloadBlob,
            ];
        }

        $arguments = $options['arguments'] ?? [];

        if (! is_array($arguments)) {
            $arguments = [];
        }

        return [
            'codec' => PayloadCodecContract::canonicalize($codec),
            'blob' => Serializer::serializeWithCodec($codec, $arguments),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function commandContext(array $options): ?CommandContext
    {
        $context = $options['command_context'] ?? null;

        return $context instanceof CommandContext ? $context : null;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function committedSignalForRequest(
        string $instanceId,
        string $signalName,
        array $options,
    ): ?WorkflowCommand {
        $context = $options['command_context'] ?? null;

        if (! $context instanceof CommandContext) {
            return null;
        }

        $attributes = $context->attributes();
        $operationId = data_get($attributes, 'context.server.operation_id');
        $requestId = data_get($attributes, 'context.request.request_id');
        $fingerprint = data_get($attributes, 'context.request.fingerprint');

        if (! is_string($operationId) || trim($operationId) === ''
            || ! is_string($fingerprint) || trim($fingerprint) === '') {
            return null;
        }

        return WorkflowCommand::query()
            ->where('workflow_instance_id', $instanceId)
            ->where('command_type', 'signal')
            ->where('status', CommandStatus::Accepted->value)
            ->orderByDesc('command_sequence')
            ->limit(20)
            ->get()
            ->first(function (WorkflowCommand $command) use ($operationId, $requestId, $fingerprint, $signalName): bool {
                $context = $command->commandContext();
                $storedRequestId = data_get($context, 'request.request_id');

                return data_get($context, 'server.operation_id') === $operationId
                    && data_get($context, 'request.fingerprint') === $fingerprint
                    && (! is_string($requestId) || trim($requestId) === '' || $storedRequestId === $requestId)
                    && $command->targetName() === $signalName
                    && $command->signalRecord()->exists()
                    && $command->historyEvents()
                        ->where('event_type', HistoryEventType::SignalReceived->value)
                        ->exists();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function signalResult(
        WorkflowCommand $command,
        string $instanceId,
        string $signalName,
    ): array {
        $result = new CommandResult($command);

        return array_merge(CommandResponse::payload($result), [
            'accepted' => true,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => $command->id,
            'signal_name' => $signalName,
            'command_reason' => $result->reason(),
            'reason' => null,
            'status' => 202,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalRunQueryFailure(WorkflowRun $run, string $queryName): array
    {
        return [
            'success' => false,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'target_scope' => 'instance',
            'query_name' => $queryName,
            'result' => null,
            'reason' => 'run_not_active',
            'message' => sprintf(
                'Workflow query [%s] cannot execute because run [%s] is terminal with status [%s].',
                $queryName,
                $run->id,
                $run->status->value,
            ),
            'run_status' => $run->status->value,
            'is_terminal' => true,
            'status' => 409,
        ];
    }
}
