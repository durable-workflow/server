<?php

namespace App\Support;

use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Contracts\WorkflowControlPlane;

final class ScheduleOverlapEnforcer
{
    public function __construct(
        private readonly WorkflowControlPlane $controlPlane,
    ) {}

    /**
     * Whether the overlap policy is a buffer policy (buffer_one or buffer_all).
     */
    public function isBufferPolicy(string $overlapPolicy): bool
    {
        return in_array($overlapPolicy, ['buffer_one', 'buffer_all'], true);
    }

    /**
     * Check whether the most recently fired workflow from this schedule is
     * still in a running state.
     */
    public function lastFiredWorkflowIsRunning(WorkflowSchedule $schedule): bool
    {
        $recentActions = $schedule->recent_actions ?? [];
        $lastAction = end($recentActions) ?: null;
        $workflowId = $lastAction['workflow_id'] ?? null;
        $runId = $lastAction['run_id'] ?? null;

        if (! is_string($workflowId) || $workflowId === '') {
            return false;
        }

        $options = is_string($runId) && $runId !== '' ? ['run_id' => $runId] : [];
        $description = $this->controlPlane->describe($workflowId, $options);

        if (! ($description['found'] ?? false)) {
            return false;
        }

        $statusBucket = $description['run']['status_bucket'] ?? null;

        return $statusBucket === 'running';
    }

    /**
     * Enforce cancel_other / terminate_other by stopping the most recently
     * started workflow from the schedule's recent actions.
     */
    public function enforce(WorkflowSchedule $schedule, string $overlapPolicy): void
    {
        if (! in_array($overlapPolicy, ['cancel_other', 'terminate_other'], true)) {
            return;
        }

        $recentActions = $schedule->recent_actions ?? [];
        $lastAction = end($recentActions) ?: null;
        $workflowId = $lastAction['workflow_id'] ?? null;

        if (! is_string($workflowId) || $workflowId === '') {
            return;
        }

        $command = $overlapPolicy === 'cancel_other' ? 'cancel' : 'terminate';

        $this->controlPlane->$command($workflowId, [
            'reason' => sprintf('Schedule overlap policy: %s', $overlapPolicy),
        ]);
    }

    /**
     * Map the schedule overlap policy to the control-plane duplicate start policy.
     *
     * Returns 'use-existing' for skip (so a duplicate start returns the existing
     * workflow), null for all other policies.
     */
    public function duplicateStartPolicy(string $overlapPolicy): ?string
    {
        return $overlapPolicy === 'skip' ? 'use-existing' : null;
    }
}
