<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Models\ActivityExecution;

final class ActivityHeartbeatRecorder
{
    /**
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    public function record(ActivityTaskBridge $bridge, string $attemptId, array $progress): array
    {
        return DB::transaction(static function () use ($bridge, $attemptId, $progress): array {
            $status = $bridge->heartbeat($attemptId, $progress);

            if (($status['heartbeat_recorded'] ?? false) !== true) {
                return $status;
            }

            $executionId = $status['activity_execution_id'] ?? null;

            if (! is_string($executionId) || $executionId === '') {
                return $status;
            }

            /** @var ActivityExecution|null $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->find($executionId);

            if (
                ! $execution instanceof ActivityExecution
                || $execution->status !== ActivityStatus::Running
                || $execution->current_attempt_id !== $attemptId
                || $execution->last_heartbeat_at === null
            ) {
                return $status;
            }

            $retryPolicy = is_array($execution->retry_policy) ? $execution->retry_policy : [];
            $heartbeatTimeout = is_int($retryPolicy['heartbeat_timeout'] ?? null)
                && $retryPolicy['heartbeat_timeout'] > 0
                    ? $retryPolicy['heartbeat_timeout']
                    : null;
            $heartbeatDeadlineAt = $heartbeatTimeout !== null
                ? $execution->last_heartbeat_at->copy()
                    ->addSeconds($heartbeatTimeout)
                : null;
            $deadlineChanged = $execution->heartbeat_deadline_at === null
                ? $heartbeatDeadlineAt !== null
                : $heartbeatDeadlineAt === null
                    || ! $execution->heartbeat_deadline_at->equalTo($heartbeatDeadlineAt);

            if ($deadlineChanged) {
                $execution->forceFill([
                    'heartbeat_deadline_at' => $heartbeatDeadlineAt,
                ])->save();
            }

            return $status;
        });
    }
}
