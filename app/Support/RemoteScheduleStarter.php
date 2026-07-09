<?php

namespace App\Support;

use DateTimeInterface;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
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
        private readonly ServerReadiness $readiness,
        private readonly SearchAttributeValueValidator $searchAttributeValues,
    ) {}

    public function start(
        WorkflowSchedule $schedule,
        ?DateTimeInterface $occurrenceTime,
        string $outcome,
        ?string $effectiveOverlapPolicy = null,
    ): ScheduleStartResult {
        /** @var WorkflowSchedule|null $freshSchedule */
        $freshSchedule = WorkflowSchedule::query()->find($schedule->getKey());
        $status = $freshSchedule?->status;

        if ($freshSchedule === null || ! $status?->allowsTrigger()) {
            $reason = $status === ScheduleStatus::Deleted
                ? 'schedule_deleted'
                : ($freshSchedule === null ? 'schedule_missing' : 'schedule_not_triggerable');

            throw new WorkflowExecutionUnavailableException(
                'schedule_start',
                $schedule->schedule_id,
                $reason,
                sprintf(
                    'Schedule [%s] is no longer active and cannot start a workflow.',
                    $schedule->schedule_id,
                ),
            );
        }

        $schedule = $freshSchedule;

        $workflowStatus = $this->readiness->bootstrapStatus();
        $blockedBy = is_array($workflowStatus['blocked_by'] ?? null)
            ? array_values(array_filter($workflowStatus['blocked_by'], static fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];

        if (($workflowStatus['status'] ?? null) === 'blocked' && $blockedBy !== []) {
            throw new WorkflowExecutionUnavailableException(
                'schedule_start',
                $schedule->schedule_id,
                'workflow_v2_blocked',
                'Workflow v2 bootstrap blockers must clear before scheduled workflows can start.',
            );
        }

        $action = WorkflowSchedule::normalizeActionTimeouts(
            is_array($schedule->action) ? $schedule->action : [],
        );

        $policy = $effectiveOverlapPolicy ?? $schedule->overlap_policy ?? 'skip';
        $duplicatePolicy = $policy === 'skip'
            ? 'use-existing'
            : null;
        $searchAttributes = is_array($schedule->search_attributes) ? $schedule->search_attributes : [];
        $searchAttributeTypes = $this->searchAttributeValues->validateForNamespace(
            $schedule->namespace,
            $searchAttributes,
        );

        $payload = array_filter([
            'workflow_type' => $action['workflow_type'] ?? null,
            'task_queue' => $action['task_queue'] ?? null,
            'input' => $action['input'] ?? [],
            'execution_timeout_seconds' => isset($action['execution_timeout_seconds']) ? (int) $action['execution_timeout_seconds'] : null,
            'run_timeout_seconds' => isset($action['run_timeout_seconds']) ? (int) $action['run_timeout_seconds'] : null,
            'memo' => is_array($schedule->memo) ? $schedule->memo : null,
            'search_attributes' => is_array($schedule->search_attributes) ? $searchAttributes : null,
            'search_attribute_types' => $searchAttributeTypes,
            'visibility_labels' => is_array($schedule->visibility_labels) ? $schedule->visibility_labels : null,
            'duplicate_policy' => $duplicatePolicy,
        ], static fn (mixed $v): bool => $v !== null);

        // Scheduled fires may target unversioned workers from another SDK.
        // If no durable build-id pin was selected, keep the run unpinned.
        $result = $this->startService->start(
            $payload,
            $schedule->namespace,
            allowAmbientCompatibilityFallback: false,
        );
        $started = is_bool($result['started'] ?? null)
            ? $result['started']
            : ($result['reason'] ?? null) === null;

        if (! $started) {
            $blockedReason = is_string($result['reason'] ?? null) && trim($result['reason']) !== ''
                ? trim($result['reason'])
                : 'start_rejected';
            $message = is_string($result['message'] ?? null) && trim($result['message']) !== ''
                ? trim($result['message'])
                : sprintf(
                    'Schedule [%s] could not start workflow type [%s].',
                    $schedule->schedule_id,
                    $action['workflow_type'] ?? 'unknown',
                );

            throw new WorkflowExecutionUnavailableException(
                'schedule_start',
                $schedule->schedule_id,
                $blockedReason,
                $message,
            );
        }

        return new ScheduleStartResult(
            instanceId: (string) $result['workflow_id'],
            runId: $result['run_id'] ?? null,
        );
    }
}
