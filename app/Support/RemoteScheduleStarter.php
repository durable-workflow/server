<?php

namespace App\Support;

use DateTimeInterface;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleStartResult;

/**
 * Server-mode starter: workflows are identified by `workflow_type` and started
 * through the control plane rather than loading a PHP class in-process.
 */
final class RemoteScheduleStarter implements ScheduleWorkflowStarter
{
    public function __construct(
        private readonly WorkflowStartService $startService,
    ) {}

    public function start(
        WorkflowSchedule $schedule,
        ?DateTimeInterface $occurrenceTime,
        string $outcome,
    ): ScheduleStartResult {
        $action = WorkflowSchedule::normalizeActionTimeouts(
            is_array($schedule->action) ? $schedule->action : [],
        );

        $duplicatePolicy = ($schedule->overlap_policy ?? 'skip') === 'skip'
            ? 'use-existing'
            : null;

        $payload = array_filter([
            'workflow_type' => $action['workflow_type'] ?? null,
            'task_queue' => $action['task_queue'] ?? null,
            'input' => $action['input'] ?? [],
            'execution_timeout_seconds' => isset($action['execution_timeout_seconds']) ? (int) $action['execution_timeout_seconds'] : null,
            'run_timeout_seconds' => isset($action['run_timeout_seconds']) ? (int) $action['run_timeout_seconds'] : null,
            'memo' => is_array($schedule->memo) ? $schedule->memo : null,
            'search_attributes' => is_array($schedule->search_attributes) ? $schedule->search_attributes : null,
            'visibility_labels' => is_array($schedule->visibility_labels) ? $schedule->visibility_labels : null,
            'duplicate_policy' => $duplicatePolicy,
        ], static fn (mixed $v): bool => $v !== null);

        $result = $this->startService->start($payload, $schedule->namespace);

        return new ScheduleStartResult(
            instanceId: (string) $result['workflow_id'],
            runId: $result['run_id'] ?? null,
        );
    }
}
