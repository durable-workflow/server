<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;

/** @internal Request-local observation; it never grants admission authority. */
final class WorkflowObservabilityAdmission
{
    public readonly int $ownerPid;

    private bool $runAttached = false;

    public function __construct(
        private readonly Request $request,
        private ?string $workflowId,
        private readonly string $operation,
    ) {
        $this->ownerPid = getmypid();
    }

    public function attachRun(WorkflowRun $run): void
    {
        if (! in_array($this->operation, ['start', 'signal_with_start'], true)
            || $this->runAttached
            || $run->namespace !== $this->namespace()
            || ($this->workflowId !== null && $run->workflow_instance_id !== $this->workflowId)
            || ! $this->hasCarrierColumn($run)) {
            return;
        }

        $run->setAttribute('observability_context', $this->carrier());
        $this->workflowId = $run->workflow_instance_id;
        $this->runAttached = true;
    }

    public function attachCommand(WorkflowCommand $command): void
    {
        $types = match ($this->operation) {
            'start' => ['start'],
            'signal_with_start' => ['start', 'signal'],
            'signal' => ['signal'],
            'update' => ['update'],
            'cancel' => ['cancel', 'request_cancellation'],
            default => [],
        };
        $type = $this->value($command->command_type);
        if ($command->workflow_instance_id !== $this->workflowId
            || $this->value($command->status) !== 'accepted'
            || ! in_array($type, $types, true)
            || ($type === 'start' && $this->value($command->outcome) !== 'started_new')
            || ! is_string($command->workflow_run_id)
            || ! $this->hasCarrierColumn($command)) {
            return;
        }
        $run = NamespaceWorkflowScope::run($this->namespace(), $this->workflowId, $command->workflow_run_id);
        if ($run === null) {
            return;
        }

        $command->setAttribute('observability_context', $this->carrier());
    }

    private function namespace(): string
    {
        $value = $this->request->attributes->get('namespace');

        return is_string($value) ? $value : '';
    }

    private function carrier(): ?array
    {
        return WorkflowObservability::normalize($this->request->input('observability_context'));
    }

    private function hasCarrierColumn(Model $model): bool
    {
        return WorkflowObservability::enabled()
            && $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'observability_context');
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
