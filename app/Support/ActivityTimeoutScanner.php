<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Models\ActivityExecution;

final class ActivityTimeoutScanner
{
    /**
     * @return list<string>
     */
    public static function expiredExecutionIds(int $limit = 100, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $deadlineBoundary = self::deadlineBoundary($now);

        return ActivityExecution::query()
            ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
            ->where(static function ($query) use ($deadlineBoundary): void {
                $query->where(static function ($schedule) use ($deadlineBoundary): void {
                    $schedule->where('status', ActivityStatus::Pending->value)
                        ->whereNotNull('schedule_deadline_at')
                        ->where('schedule_deadline_at', '<=', $deadlineBoundary);
                })->orWhere(static function ($close) use ($deadlineBoundary): void {
                    $close->where('status', ActivityStatus::Running->value)
                        ->whereNotNull('close_deadline_at')
                        ->where('close_deadline_at', '<=', $deadlineBoundary);
                })->orWhere(static function ($scheduleToClose) use ($deadlineBoundary): void {
                    $scheduleToClose->whereNotNull('schedule_to_close_deadline_at')
                        ->where('schedule_to_close_deadline_at', '<=', $deadlineBoundary);
                })->orWhere(static function ($heartbeat) use ($deadlineBoundary): void {
                    $heartbeat->where('status', ActivityStatus::Running->value)
                        ->whereNotNull('heartbeat_deadline_at')
                        ->where('heartbeat_deadline_at', '<=', $deadlineBoundary);
                });
            })
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    private static function deadlineBoundary(CarbonInterface $deadline): string
    {
        return $deadline->format((new ActivityExecution())->getDateFormat());
    }
}
