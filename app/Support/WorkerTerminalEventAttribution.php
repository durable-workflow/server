<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\HistoryBudget;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\RunTimelineProjector;

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

        // Keep potentially large payloads out of the database sort buffer.
        $eventId = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('workflow_task_id', $taskId)
            ->whereIn('event_type', [
                HistoryEventType::WorkflowCompleted->value,
                HistoryEventType::WorkflowFailed->value,
            ])
            ->orderByDesc('sequence')
            ->value('id');

        /** @var WorkflowHistoryEvent|null $event */
        $event = $eventId === null ? null : WorkflowHistoryEvent::query()->find($eventId);

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

        $previousSize = HistoryBudget::sizeOfEvent($event);
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

        /** @var WorkflowRun $run */
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->findOrFail($runId);
        $this->adjustSummaryBudget($run, HistoryBudget::sizeOfEvent($event) - $previousSize);
        RunTimelineProjector::projectWorkerTerminalEvent($run, $event);
    }

    private function adjustSummaryBudget(WorkflowRun $run, int $byteDelta): void
    {
        if ($byteDelta === 0) {
            return;
        }

        /** @var WorkflowRunSummary|null $summary */
        $summary = ConfiguredV2Models::query('run_summary_model', WorkflowRunSummary::class)
            ->lockForUpdate()->find($run->id);

        // The bridge has already counted the terminal event. Adjust only its
        // changed bytes, without rebuilding history or legitimizing an absent
        // or incomplete projection. The caller holds the completion transaction.
        if (! $summary instanceof WorkflowRunSummary
            || $summary->projection_schema_version !== RunSummaryProjector::SCHEMA_VERSION
            || ! HistoryBudget::hasCompleteSummary($run, $summary)) {
            return;
        }

        $budget = HistoryBudget::fromCounters(
            (int) $summary->history_event_count,
            (int) $summary->history_size_bytes + $byteDelta,
            (int) $summary->history_fan_out,
        );
        $summary->forceFill([
            'history_size_bytes' => $budget['history_size_bytes'],
            'continue_as_new_recommended' => $budget['continue_as_new_recommended'],
            'history_budget_pressure' => $budget['pressure'],
        ])->save();
    }
}
