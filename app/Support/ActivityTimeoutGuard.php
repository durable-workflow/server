<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTimeoutEnforcer;

final class ActivityTimeoutGuard
{
    /**
     * @return array{enforced: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    public static function enforce(string $executionId): array
    {
        return DB::transaction(static function () use ($executionId): array {
            /** @var ActivityExecution|null $snapshot */
            $snapshot = ActivityExecution::query()->find($executionId);

            if (! $snapshot instanceof ActivityExecution) {
                return self::skipped('execution_not_found');
            }

            $snapshotAttemptId = self::runningAttemptId($snapshot);
            $attempt = $snapshotAttemptId !== null
                ? ActivityAttempt::query()
                    ->lockForUpdate()
                    ->find($snapshotAttemptId)
                : null;

            /** @var ActivityExecution|null $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->find($executionId);

            if (! $execution instanceof ActivityExecution) {
                return self::skipped('execution_not_found');
            }

            if (! in_array($execution->status, [ActivityStatus::Pending, ActivityStatus::Running], true)) {
                return self::skipped('execution_already_terminal');
            }

            $attemptStateReason = self::currentAttemptStateReason(
                $execution,
                $attempt,
                $snapshotAttemptId,
            );

            if ($attemptStateReason !== null) {
                return self::skipped($attemptStateReason);
            }

            return ActivityTimeoutEnforcer::enforce($executionId);
        });
    }

    private static function runningAttemptId(ActivityExecution $execution): ?string
    {
        if ($execution->status !== ActivityStatus::Running) {
            return null;
        }

        $attemptId = $execution->current_attempt_id;

        return is_string($attemptId) && $attemptId !== ''
            ? $attemptId
            : null;
    }

    private static function currentAttemptStateReason(
        ActivityExecution $execution,
        ?ActivityAttempt $attempt,
        ?string $snapshotAttemptId,
    ): ?string {
        if ($execution->status !== ActivityStatus::Running) {
            return null;
        }

        if (
            $snapshotAttemptId === null
            || $execution->current_attempt_id !== $snapshotAttemptId
        ) {
            return 'current_attempt_changed';
        }

        if (! $attempt instanceof ActivityAttempt) {
            return 'current_attempt_not_found';
        }

        if (
            $attempt->activity_execution_id !== $execution->id
            || $attempt->attempt_number !== (int) $execution->attempt_count
        ) {
            return 'current_attempt_changed';
        }

        if ($attempt->status !== ActivityAttemptStatus::Running) {
            return 'current_attempt_not_running';
        }

        return null;
    }

    /**
     * @return array{enforced: bool, reason: string, next_task: null}
     */
    private static function skipped(string $reason): array
    {
        return [
            'enforced' => false,
            'reason' => $reason,
            'next_task' => null,
        ];
    }
}
