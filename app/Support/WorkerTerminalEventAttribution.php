<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;

final class WorkerTerminalEventAttribution
{
    public function __construct(
        private readonly WorkflowCommandContextFactory $commandContext,
    ) {}

    /**
     * Attribute a worker-caused terminal event to the authenticated request
     * principal. This runs in the same transaction as workflow-task
     * completion so a terminal event cannot commit without its audit actor.
     *
     * @param  array<string, mixed>  $outcome
     */
    public function record(Request $request, string $taskId, array $outcome): void
    {
        if (($outcome['completed'] ?? false) !== true) {
            return;
        }

        $runId = $outcome['workflow_run_id'] ?? null;
        if (! is_string($runId) || trim($runId) === '') {
            throw new RuntimeException('Completed workflow task did not report its workflow run id.');
        }

        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('workflow_task_id', $taskId)
            ->whereIn('event_type', [
                HistoryEventType::WorkflowCompleted->value,
                HistoryEventType::WorkflowFailed->value,
            ])
            ->orderByDesc('sequence')
            ->first();

        // A successfully completed task can transition the run without
        // recording a terminal completion/failure event (for example,
        // continue-as-new). Only attribute an event that actually exists.
        if (! $event instanceof WorkflowHistoryEvent) {
            return;
        }

        $principal = $this->commandContext->principalForRequest($request);
        if ($principal === null) {
            throw new RuntimeException('Completed workflow task did not have a server-authenticated principal.');
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $command = is_array($payload['command'] ?? null) ? $payload['command'] : [];

        $payload['command'] = array_filter(array_replace($command, [
            'type' => $event->event_type === HistoryEventType::WorkflowCompleted
                ? 'complete_workflow'
                : 'fail_workflow',
            'source' => 'worker_protocol',
            'principal_type' => $principal['type'],
            'principal_id' => $principal['id'],
            'principal_label' => $principal['label'] ?? null,
        ]), static fn (mixed $value): bool => $value !== null && $value !== '');

        $event->forceFill(['payload' => $payload])->save();
    }
}
