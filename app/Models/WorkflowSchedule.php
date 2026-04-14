<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WorkflowSchedule extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_schedules';

    protected $keyType = 'string';

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
        'buffered_actions',
    ];

    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'memo' => 'array',
            'search_attributes' => 'array',
            'recent_actions' => 'array',
            'buffered_actions' => 'array',
            'paused' => 'boolean',
            'last_fired_at' => 'datetime',
            'next_fire_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $schedule): void {
            $schedule->syncPackageColumns();
        });
    }

    private function syncPackageColumns(): void
    {
        $action = is_string($this->attributes['action'] ?? null)
            ? json_decode($this->attributes['action'], true) ?? []
            : ($this->attributes['action'] ?? []);

        $this->attributes['workflow_type'] = $action['workflow_type'] ?? $this->attributes['workflow_type'] ?? 'unknown';
        $this->attributes['workflow_class'] = $this->attributes['workflow_class'] ?? $this->attributes['workflow_type'] ?? 'unknown';

        $spec = is_string($this->attributes['spec'] ?? null)
            ? json_decode($this->attributes['spec'], true) ?? []
            : ($this->attributes['spec'] ?? []);

        $cronExpressions = $spec['cron_expressions'] ?? [];
        $this->attributes['cron_expression'] = $this->attributes['cron_expression']
            ?? (! empty($cronExpressions) ? implode('; ', $cronExpressions) : '* * * * *');
        $this->attributes['timezone'] = $spec['timezone'] ?? $this->attributes['timezone'] ?? 'UTC';

        $this->attributes['status'] = $this->paused ? 'paused' : ($this->attributes['status'] ?? 'active');
    }

    protected function action(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value) {
                $decoded = is_string($value) ? (json_decode($value, true) ?? []) : ($value ?? []);

                return self::normalizeActionTimeouts($decoded);
            },
            set: fn (mixed $value) => is_string($value) ? $value : json_encode($value),
        );
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public static function normalizeActionTimeouts(array $action): array
    {
        if (! isset($action['execution_timeout_seconds']) && isset($action['workflow_execution_timeout'])) {
            $action['execution_timeout_seconds'] = (int) $action['workflow_execution_timeout'];
        }

        if (! isset($action['run_timeout_seconds']) && isset($action['workflow_run_timeout'])) {
            $action['run_timeout_seconds'] = (int) $action['workflow_run_timeout'];
        }

        unset($action['workflow_execution_timeout'], $action['workflow_run_timeout']);

        return $action;
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
     *
     * Evaluates both cron expressions and interval specs and returns the
     * earliest upcoming fire time across all of them.
     */
    public function computeNextFireAt(?\DateTimeInterface $after = null): ?\DateTimeInterface
    {
        $after = $after ?? now();
        $spec = $this->spec ?? [];
        $timezone = $spec['timezone'] ?? 'UTC';

        $earliest = null;

        $cronExpressions = $spec['cron_expressions'] ?? [];

        foreach ($cronExpressions as $expression) {
            $next = self::nextCronOccurrence($expression, $after, $timezone);
            if ($next !== null && ($earliest === null || $next < $earliest)) {
                $earliest = $next;
            }
        }

        $intervals = $spec['intervals'] ?? [];

        foreach ($intervals as $interval) {
            $next = $this->nextIntervalOccurrence($interval, $after);
            if ($next !== null && ($earliest === null || $next < $earliest)) {
                $earliest = $next;
            }
        }

        return $earliest;
    }

    /**
     * Compute the next interval occurrence after a given time.
     *
     * Each interval entry supports:
     *   - "every": an ISO 8601 duration string (e.g. "PT30M", "PT1H", "P1D")
     *   - "offset": optional ISO 8601 duration offset from the epoch
     *
     * The interval grid is anchored at the Unix epoch (or epoch + offset)
     * and the next grid point after $after is returned.
     */
    private function nextIntervalOccurrence(array $interval, \DateTimeInterface $after): ?\DateTimeInterface
    {
        $everySpec = $interval['every'] ?? null;

        if (! is_string($everySpec) || $everySpec === '') {
            return null;
        }

        try {
            $duration = new \DateInterval($everySpec);
        } catch (\Exception) {
            return null;
        }

        $everySeconds = self::dateIntervalToSeconds($duration);

        if ($everySeconds <= 0) {
            return null;
        }

        $offsetSeconds = 0;
        $offsetSpec = $interval['offset'] ?? null;

        if (is_string($offsetSpec) && $offsetSpec !== '') {
            try {
                $offsetSeconds = self::dateIntervalToSeconds(new \DateInterval($offsetSpec));
            } catch (\Exception) {
                // Ignore invalid offset, default to 0
            }
        }

        $afterTimestamp = $after->getTimestamp();

        // Grid: epoch + offset, epoch + offset + every, epoch + offset + 2*every, ...
        // Find the first grid point > afterTimestamp
        $elapsed = $afterTimestamp - $offsetSeconds;
        $periodsPassed = (int) floor($elapsed / $everySeconds);
        $nextTimestamp = $offsetSeconds + ($periodsPassed + 1) * $everySeconds;

        return \Carbon\Carbon::createFromTimestamp($nextTimestamp, 'UTC');
    }

    /**
     * Convert a DateInterval to total seconds (approximate for months/years).
     */
    private static function dateIntervalToSeconds(\DateInterval $interval): int
    {
        return ($interval->y * 365 * 86400)
            + ($interval->m * 30 * 86400)
            + ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
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
                'buffered_actions' => $this->buffered_actions ?? [],
            ],
            'memo' => $this->memo,
            'search_attributes' => $this->search_attributes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Add a fire to the buffer queue.
     */
    public function bufferAction(): void
    {
        $buffer = $this->buffered_actions ?? [];
        $buffer[] = [
            'buffered_at' => now()->toIso8601String(),
        ];
        $this->buffered_actions = array_values($buffer);
    }

    /**
     * Remove and return the next buffered action.
     */
    public function drainBuffer(): ?array
    {
        $buffer = $this->buffered_actions ?? [];

        if (empty($buffer)) {
            return null;
        }

        $next = array_shift($buffer);
        $this->buffered_actions = empty($buffer) ? null : array_values($buffer);

        return $next;
    }

    public function hasBufferedActions(): bool
    {
        return ! empty($this->buffered_actions);
    }

    /**
     * Whether the buffer is at capacity for the given overlap policy.
     *
     * buffer_one allows at most 1 buffered action; buffer_all is unbounded.
     */
    public function isAtBufferCapacity(string $overlapPolicy): bool
    {
        if ($overlapPolicy !== 'buffer_one') {
            return false;
        }

        return count($this->buffered_actions ?? []) >= 1;
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
