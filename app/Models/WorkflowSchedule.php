<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowSchedule extends Model
{
    protected $table = 'workflow_schedules';

    protected $fillable = [
        'schedule_id',
        'namespace',
        'spec',
        'action',
        'overlap_policy',
        'paused',
        'note',
        'memo',
        'search_attributes',
        'last_fired_at',
        'next_fire_at',
        'fires_count',
        'failures_count',
        'recent_actions',
    ];

    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'action' => 'array',
            'memo' => 'array',
            'search_attributes' => 'array',
            'recent_actions' => 'array',
            'paused' => 'boolean',
            'last_fired_at' => 'datetime',
            'next_fire_at' => 'datetime',
        ];
    }

    public const OVERLAP_POLICIES = [
        'skip',
        'buffer_one',
        'buffer_all',
        'cancel_other',
        'terminate_other',
        'allow_all',
    ];

    /**
     * Compute the next fire time from the schedule spec.
     */
    public function computeNextFireAt(?\DateTimeInterface $after = null): ?\DateTimeInterface
    {
        $after = $after ?? now();
        $spec = $this->spec ?? [];
        $timezone = $spec['timezone'] ?? 'UTC';

        $cronExpressions = $spec['cron_expressions'] ?? [];

        if (empty($cronExpressions)) {
            return null;
        }

        $earliest = null;

        foreach ($cronExpressions as $expression) {
            $next = self::nextCronOccurrence($expression, $after, $timezone);
            if ($next !== null && ($earliest === null || $next < $earliest)) {
                $earliest = $next;
            }
        }

        return $earliest;
    }

    /**
     * Compute the next occurrence of a cron expression after a given time.
     *
     * Uses dragonmantank/cron-expression if available, otherwise uses a
     * simple minute-resolution scanner for standard 5-field cron expressions.
     */
    public static function nextCronOccurrence(string $expression, \DateTimeInterface $after, string $timezone = 'UTC'): ?\DateTimeInterface
    {
        if (class_exists(\Cron\CronExpression::class)) {
            $cron = new \Cron\CronExpression($expression);

            return \Carbon\Carbon::instance(
                $cron->getNextRunDate($after, 0, false, $timezone)
            );
        }

        // Fallback: scan minute-by-minute for up to 48 hours
        $tz = new \DateTimeZone($timezone);
        $candidate = \Carbon\Carbon::instance($after)->setTimezone($tz)->addMinute()->startOfMinute();
        $limit = (clone $candidate)->addHours(48);

        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            return null;
        }

        [$minSpec, $hourSpec, $domSpec, $monSpec, $dowSpec] = $parts;

        while ($candidate <= $limit) {
            if (
                self::cronFieldMatches($minSpec, $candidate->minute, 0, 59)
                && self::cronFieldMatches($hourSpec, $candidate->hour, 0, 23)
                && self::cronFieldMatches($domSpec, $candidate->day, 1, 31)
                && self::cronFieldMatches($monSpec, $candidate->month, 1, 12)
                && self::cronFieldMatches($dowSpec, $candidate->dayOfWeekIso % 7, 0, 6)
            ) {
                return $candidate->setTimezone('UTC');
            }
            $candidate->addMinute();
        }

        return null;
    }

    private static function cronFieldMatches(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            // Handle step values: */N or M-N/S
            if (str_contains($part, '/')) {
                [$range, $step] = explode('/', $part, 2);
                $step = (int) $step;
                if ($step < 1) {
                    continue;
                }

                if ($range === '*') {
                    $rangeStart = $min;
                    $rangeEnd = $max;
                } elseif (str_contains($range, '-')) {
                    [$rangeStart, $rangeEnd] = array_map('intval', explode('-', $range, 2));
                } else {
                    continue;
                }

                for ($i = $rangeStart; $i <= $rangeEnd; $i += $step) {
                    if ($i === $value) {
                        return true;
                    }
                }

                continue;
            }

            // Handle ranges: M-N
            if (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part, 2));
                if ($value >= $start && $value <= $end) {
                    return true;
                }

                continue;
            }

            // Exact value
            if ((int) $part === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the summary for list endpoints.
     */
    public function toListItem(): array
    {
        return [
            'schedule_id' => $this->schedule_id,
            'workflow_type' => $this->action['workflow_type'] ?? null,
            'paused' => $this->paused,
            'next_fire' => $this->next_fire_at?->toIso8601String(),
            'last_fire' => $this->last_fired_at?->toIso8601String(),
            'overlap_policy' => $this->overlap_policy,
            'note' => $this->note,
        ];
    }

    /**
     * Build the detail response.
     */
    public function toDetail(): array
    {
        return [
            'schedule_id' => $this->schedule_id,
            'spec' => $this->spec,
            'action' => $this->action,
            'overlap_policy' => $this->overlap_policy,
            'state' => [
                'paused' => $this->paused,
                'note' => $this->note,
            ],
            'info' => [
                'next_fire' => $this->next_fire_at?->toIso8601String(),
                'last_fire' => $this->last_fired_at?->toIso8601String(),
                'fires_count' => $this->fires_count,
                'failures_count' => $this->failures_count,
                'recent_actions' => $this->recent_actions ?? [],
            ],
            'memo' => $this->memo,
            'search_attributes' => $this->search_attributes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Record a fire attempt in the recent actions log (keeps last 10).
     */
    public function recordFire(string $workflowId, ?string $runId, string $outcome): void
    {
        $actions = $this->recent_actions ?? [];
        $actions[] = [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'outcome' => $outcome,
            'fired_at' => now()->toIso8601String(),
        ];

        // Keep last 10
        if (count($actions) > 10) {
            $actions = array_slice($actions, -10);
        }

        $this->recent_actions = array_values($actions);
        $this->fires_count++;
        $this->last_fired_at = now();
        $this->next_fire_at = $this->computeNextFireAt();
    }

    /**
     * Record a fire failure.
     */
    public function recordFailure(string $reason): void
    {
        $actions = $this->recent_actions ?? [];
        $actions[] = [
            'outcome' => 'failed',
            'reason' => $reason,
            'fired_at' => now()->toIso8601String(),
        ];

        if (count($actions) > 10) {
            $actions = array_slice($actions, -10);
        }

        $this->recent_actions = array_values($actions);
        $this->failures_count++;
        $this->last_fired_at = now();
        $this->next_fire_at = $this->computeNextFireAt();
    }
}
