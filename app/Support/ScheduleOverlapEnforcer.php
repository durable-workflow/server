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
     * still in a running state. Prefers the dedicated latest_workflow_instance_id
     * column, falling back to the recent_actions ring.
     */
    public function lastFiredWorkflowIsRunning(WorkflowSchedule $schedule): bool
    {
        $workflowId = $this->resolveLatestWorkflowId($schedule);

        if ($workflowId === null) {
            return false;
        }

        $description = $this->controlPlane->describe($workflowId, []);

        if (! ($description['found'] ?? false)) {
            return false;
        }

        $statusBucket = $description['run']['status_bucket'] ?? null;

        return $statusBucket === 'running';
    }

    /**
     * Enforce cancel_other / terminate_other by stopping the most recently
     * started workflow from the schedule.
     */
    public function enforce(WorkflowSchedule $schedule, string $overlapPolicy): void
    {
        if (! in_array($overlapPolicy, ['cancel_other', 'terminate_other'], true)) {
            return;
        }

        $workflowId = $this->resolveLatestWorkflowId($schedule);

        if ($workflowId === null) {
            return;
        }

        $command = $overlapPolicy === 'cancel_other' ? 'cancel' : 'terminate';

        $this->controlPlane->$command($workflowId, [
            'reason' => sprintf('Schedule overlap policy: %s', $overlapPolicy),
        ]);
    }

    private function resolveLatestWorkflowId(WorkflowSchedule $schedule): ?string
    {
        $instanceId = $schedule->latest_workflow_instance_id;

        if (is_string($instanceId) && $instanceId !== '') {
            return $instanceId;
        }

        $recentActions = $schedule->recent_actions ?? [];
        $lastAction = end($recentActions) ?: null;
        $workflowId = $lastAction['workflow_id'] ?? null;

        return is_string($workflowId) && $workflowId !== '' ? $workflowId : null;
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
