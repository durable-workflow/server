<?php

namespace App\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;

final class DetachedActivityRetentionHold
{
    /** @param list<string> $runIds */
    public static function forRuns(string $namespace, array $runIds): ?string
    {
        $runs = WorkflowRun::query()->where('namespace', $namespace)->whereIn('id', $runIds)->get();
        foreach ($runs as $run) {
            if (method_exists(WorkflowRunRetentionCleanup::class, 'retentionHoldReason')) {
                $reason = WorkflowRunRetentionCleanup::retentionHoldReason($run);
                if ($reason !== null) {
                    return $reason;
                }

                continue;
            }
            // A downgraded backend must preserve newer detached work and its
            // payloads even when it cannot execute that policy itself.
            $schedules = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ActivityScheduled->value)
                ->where('payload->activity->cancellation_policy', 'abandon')->get();
            if ($schedules->isNotEmpty()) {
                // Restore a compatible backend to evaluate its canonical
                // outcome contract. Do not parse newer history with old enums.
                return 'activity_policy_backend_unsupported';
            }
        }

        return null;
    }
}
