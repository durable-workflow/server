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

    private array $createdRuns = [];

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

        $this->attach($run, $this->carrier());
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

        $this->attach($command, $this->carrier());
    }

    public function rememberCreatedRun(WorkflowRun $run): void
    {
        if ($this->operation === 'workflow_complete' && $run->namespace === $this->namespace() && is_string($run->id)) {
            $this->createdRuns[$run->id] = true;
        }
    }

    public function createdRunIds(): array
    {
        return array_keys($this->createdRuns);
    }

    private function attach(Model $model, ?array $carrier): void
    {
        $attributes = $model->getAttributes();
        $attributes['observability_context'] = $carrier === null ? null : json_encode($carrier, JSON_THROW_ON_ERROR);
        $model->setRawAttributes($attributes);
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
