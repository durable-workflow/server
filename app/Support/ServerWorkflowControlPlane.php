<?php

namespace App\Support;

use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Workflow;

final class ServerWorkflowControlPlane implements WorkflowControlPlane
{
    public function __construct(
        private readonly DefaultWorkflowControlPlane $inner,
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly SqliteControlPlaneMutationRetrier $mutations,
    ) {}

    public function start(string $workflowType, ?string $instanceId = null, array $options = []): array
    {
        return $this->inner->start($workflowType, $instanceId, $options);
    }

    public function signal(string $instanceId, string $name, array $options = []): array
    {
        return $this->mutations->run(
            fn (): array => $this->inner->signal($instanceId, $name, $options),
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
     * @param array<string, mixed> $options
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
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function queryArgumentsEnvelope(array $options, WorkflowRun $run): array
    {
        $codec = $this->nonEmptyString($options['payload_codec'] ?? null)
            ?? $this->nonEmptyString($run->payload_codec)
            ?? CodecRegistry::defaultCodec();

        $payloadBlob = $this->nonEmptyString($options['payload_blob'] ?? null);
        if ($payloadBlob !== null) {
            return [
                'codec' => CodecRegistry::canonicalize($codec),
                'blob' => $payloadBlob,
            ];
        }

        $arguments = $options['arguments'] ?? [];

        if (! is_array($arguments)) {
            $arguments = [];
        }

        return [
            'codec' => CodecRegistry::canonicalize($codec),
            'blob' => Serializer::serializeWithCodec($codec, $arguments),
        ];
    }

    /**
     * @param array<string, mixed> $options
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
