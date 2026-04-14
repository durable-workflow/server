<?php

namespace App\Http\Controllers\Api;

use App\Support\ScheduleOverlapEnforcer;
use App\Support\WorkflowStartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;

class ScheduleController
{
    public function __construct(
        private readonly WorkflowStartService $startService,
        private readonly ScheduleOverlapEnforcer $overlapEnforcer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        $schedules = WorkflowSchedule::query()
            ->where('namespace', $namespace)
            ->whereNot('status', ScheduleStatus::Deleted)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (WorkflowSchedule $s) => $this->formatListItem($s))
            ->all();

        return response()->json([
            'schedules' => $schedules,
            'next_page_token' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'schedule_id' => ['nullable', 'string', 'max:128'],
            'spec' => ['required', 'array'],
            'spec.cron_expressions' => ['nullable', 'array'],
            'spec.cron_expressions.*' => ['string'],
            'spec.intervals' => ['nullable', 'array'],
            'spec.intervals.*.every' => ['required_with:spec.intervals', 'string', 'max:64'],
            'spec.intervals.*.offset' => ['nullable', 'string', 'max:64'],
            'spec.timezone' => ['nullable', 'string', 'max:64'],
            'action' => ['required', 'array'],
            'action.workflow_type' => ['required', 'string'],
            'action.task_queue' => ['nullable', 'string'],
            'action.input' => ['nullable', 'array'],
            'action.execution_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'action.run_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'action.workflow_execution_timeout' => ['nullable', 'integer', 'min:1'],
            'action.workflow_run_timeout' => ['nullable', 'integer', 'min:1'],
            'overlap_policy' => ['nullable', 'string', 'in:'.implode(',', WorkflowSchedule::OVERLAP_POLICIES)],
            'jitter_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'max_runs' => ['nullable', 'integer', 'min:1'],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'paused' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['action'] = WorkflowSchedule::normalizeActionTimeouts($validated['action']);

        if (isset($validated['memo'])) {
            $memoSize = strlen(json_encode($validated['memo']));
            $maxMemoBytes = (int) config('server.limits.max_memo_bytes', 256 * 1024);

            if ($memoSize > $maxMemoBytes) {
                return response()->json([
                    'message' => sprintf('The memo exceeds the maximum allowed size of %d bytes.', $maxMemoBytes),
                    'reason' => 'memo_too_large',
                    'limit' => $maxMemoBytes,
                ], 422);
            }
        }

        $scheduleId = $validated['schedule_id'] ?? Str::ulid()->toBase32();

        $existing = WorkflowSchedule::query()
            ->where('namespace', $namespace)
            ->where('schedule_id', $scheduleId)
            ->whereNot('status', ScheduleStatus::Deleted)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => sprintf(
                    'Schedule [%s] already exists in namespace [%s].',
                    $scheduleId,
                    $namespace,
                ),
                'reason' => 'schedule_already_exists',
                'schedule_id' => $scheduleId,
            ], 409);
        }

        $isPaused = $validated['paused'] ?? false;
        $maxRuns = $validated['max_runs'] ?? null;

        $schedule = WorkflowSchedule::create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => $validated['spec'],
            'action' => $validated['action'],
            'overlap_policy' => $validated['overlap_policy'] ?? 'skip',
            'status' => $isPaused ? ScheduleStatus::Paused : ScheduleStatus::Active,
            'paused_at' => $isPaused ? now() : null,
            'jitter_seconds' => $validated['jitter_seconds'] ?? 0,
            'max_runs' => $maxRuns,
            'remaining_actions' => $maxRuns,
            'fires_count' => 0,
            'failures_count' => 0,
            'note' => $validated['note'] ?? null,
            'memo' => $validated['memo'] ?? null,
            'search_attributes' => $validated['search_attributes'] ?? null,
        ]);

        $schedule->next_fire_at = $schedule->computeNextFireAtWithJitter();
        $schedule->save();

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'created',
        ], 201);
    }

    public function show(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        return response()->json($this->formatDetail($schedule));
    }

    public function update(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $validated = $request->validate([
            'spec' => ['nullable', 'array'],
            'spec.cron_expressions' => ['nullable', 'array'],
            'spec.cron_expressions.*' => ['string'],
            'spec.intervals' => ['nullable', 'array'],
            'spec.intervals.*.every' => ['required_with:spec.intervals', 'string', 'max:64'],
            'spec.intervals.*.offset' => ['nullable', 'string', 'max:64'],
            'spec.timezone' => ['nullable', 'string', 'max:64'],
            'action' => ['nullable', 'array'],
            'action.workflow_type' => ['nullable', 'string'],
            'action.task_queue' => ['nullable', 'string'],
            'action.input' => ['nullable', 'array'],
            'action.execution_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'action.run_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'action.workflow_execution_timeout' => ['nullable', 'integer', 'min:1'],
            'action.workflow_run_timeout' => ['nullable', 'integer', 'min:1'],
            'overlap_policy' => ['nullable', 'string', 'in:'.implode(',', WorkflowSchedule::OVERLAP_POLICIES)],
            'jitter_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'max_runs' => ['nullable', 'integer', 'min:1'],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (isset($validated['action'])) {
            $validated['action'] = WorkflowSchedule::normalizeActionTimeouts($validated['action']);
        }

        if (isset($validated['memo'])) {
            $memoSize = strlen(json_encode($validated['memo']));
            $maxMemoBytes = (int) config('server.limits.max_memo_bytes', 256 * 1024);

            if ($memoSize > $maxMemoBytes) {
                return response()->json([
                    'message' => sprintf('The memo exceeds the maximum allowed size of %d bytes.', $maxMemoBytes),
                    'reason' => 'memo_too_large',
                    'limit' => $maxMemoBytes,
                ], 422);
            }
        }

        if (isset($validated['spec'])) {
            $schedule->spec = $validated['spec'];
        }

        if (isset($validated['action'])) {
            $schedule->action = array_merge($schedule->action ?? [], $validated['action']);
        }

        if (array_key_exists('memo', $validated)) {
            $schedule->memo = $validated['memo'];
        }

        if (array_key_exists('search_attributes', $validated)) {
            $schedule->search_attributes = $validated['search_attributes'];
        }

        if (isset($validated['overlap_policy'])) {
            $schedule->overlap_policy = $validated['overlap_policy'];
        }

        if (isset($validated['jitter_seconds'])) {
            $schedule->jitter_seconds = $validated['jitter_seconds'];
        }

        if (isset($validated['max_runs'])) {
            $schedule->max_runs = $validated['max_runs'];
            if ($schedule->remaining_actions === null || $validated['max_runs'] > (int) $schedule->fires_count) {
                $schedule->remaining_actions = $validated['max_runs'] - (int) $schedule->fires_count;
            }
        }

        if (array_key_exists('note', $validated)) {
            $schedule->note = $validated['note'];
        }

        $schedule->next_fire_at = $schedule->computeNextFireAtWithJitter();
        $schedule->save();

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'updated',
        ]);
    }

    public function destroy(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $schedule->forceFill([
            'status' => ScheduleStatus::Deleted,
            'deleted_at' => now(),
            'next_fire_at' => null,
        ])->save();

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'deleted',
        ]);
    }

    public function pause(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $schedule->status = ScheduleStatus::Paused;
        $schedule->paused_at = now();

        if (array_key_exists('note', $validated) && $validated['note'] !== null) {
            $schedule->note = $validated['note'];
        }

        $schedule->save();

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'paused',
        ]);
    }

    public function resume(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $schedule->status = ScheduleStatus::Active;
        $schedule->paused_at = null;

        if (array_key_exists('note', $validated) && $validated['note'] !== null) {
            $schedule->note = $validated['note'];
        }

        $schedule->next_fire_at = $schedule->computeNextFireAtWithJitter();
        $schedule->save();

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'resumed',
        ]);
    }

    public function trigger(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $validated = $request->validate([
            'overlap_policy' => ['nullable', 'string', 'in:'.implode(',', WorkflowSchedule::OVERLAP_POLICIES)],
        ]);

        if ($schedule->remaining_actions !== null && $schedule->remaining_actions <= 0) {
            return response()->json([
                'schedule_id' => $scheduleId,
                'outcome' => 'skipped',
                'reason' => 'remaining_actions_exhausted',
            ]);
        }

        $action = WorkflowSchedule::normalizeActionTimeouts($schedule->action ?? []);
        $overlapPolicy = $validated['overlap_policy'] ?? $schedule->overlap_policy;

        if ($this->overlapEnforcer->isBufferPolicy($overlapPolicy)) {
            if ($this->overlapEnforcer->lastFiredWorkflowIsRunning($schedule)) {
                if ($schedule->isAtBufferCapacity($overlapPolicy)) {
                    $this->recordSkip($schedule, 'buffer_full');

                    return response()->json([
                        'schedule_id' => $scheduleId,
                        'outcome' => 'buffer_full',
                        'reason' => sprintf(
                            'Previous workflow is still running and buffer is at capacity (%s).',
                            $overlapPolicy,
                        ),
                    ]);
                }

                $schedule->bufferAction();
                $schedule->save();

                return response()->json([
                    'schedule_id' => $scheduleId,
                    'outcome' => 'buffered',
                    'buffer_depth' => count($schedule->buffered_actions ?? []),
                ]);
            }
        }

        try {
            $this->overlapEnforcer->enforce($schedule, $overlapPolicy);

            $result = $this->startService->start(array_filter([
                'workflow_type' => $action['workflow_type'],
                'task_queue' => $action['task_queue'] ?? null,
                'input' => $action['input'] ?? [],
                'execution_timeout_seconds' => isset($action['execution_timeout_seconds']) ? (int) $action['execution_timeout_seconds'] : null,
                'run_timeout_seconds' => isset($action['run_timeout_seconds']) ? (int) $action['run_timeout_seconds'] : null,
                'memo' => $schedule->memo,
                'search_attributes' => $schedule->search_attributes,
                'duplicate_policy' => $this->overlapEnforcer->duplicateStartPolicy($overlapPolicy),
            ], static fn (mixed $value): bool => $value !== null), $schedule->namespace);

            $schedule->recordFire($result['workflow_id'], $result['run_id'], $result['outcome'] ?? 'started');
            $schedule->latest_workflow_instance_id = $result['workflow_id'];

            if ($schedule->remaining_actions !== null) {
                $schedule->remaining_actions = max(0, (int) $schedule->remaining_actions - 1);
            }

            $schedule->save();

            if ($schedule->remaining_actions !== null && $schedule->remaining_actions <= 0) {
                $schedule->forceFill([
                    'status' => ScheduleStatus::Deleted,
                    'deleted_at' => now(),
                    'next_fire_at' => null,
                ])->save();
            }

            return response()->json([
                'schedule_id' => $scheduleId,
                'outcome' => 'triggered',
                'workflow_id' => $result['workflow_id'],
                'run_id' => $result['run_id'],
            ]);
        } catch (\Throwable $e) {
            $schedule->recordFailure($e->getMessage());
            $schedule->save();

            return response()->json([
                'schedule_id' => $scheduleId,
                'outcome' => 'trigger_failed',
                'reason' => $e->getMessage(),
            ], 500);
        }
    }

    public function backfill(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $validated = $request->validate([
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date'],
            'overlap_policy' => ['nullable', 'string', 'in:'.implode(',', WorkflowSchedule::OVERLAP_POLICIES)],
        ]);

        $startTime = new \DateTimeImmutable($validated['start_time']);
        $endTime = new \DateTimeImmutable($validated['end_time']);

        if ($endTime <= $startTime) {
            return response()->json([
                'message' => 'end_time must be after start_time.',
                'reason' => 'invalid_time_range',
            ], 422);
        }

        // Compute all fire times in the backfill window using the schedule's
        // full spec (cron expressions + interval specs).
        $fireTimes = [];
        $cursor = $startTime;
        $maxBackfillCount = 1000;

        while (count($fireTimes) < $maxBackfillCount) {
            $nextFire = $schedule->computeNextFireAt($cursor);

            if ($nextFire === null || $nextFire >= $endTime) {
                break;
            }

            $fireTimes[] = $nextFire;
            $cursor = $nextFire;
        }

        $action = WorkflowSchedule::normalizeActionTimeouts($schedule->action ?? []);
        $overlapPolicy = $validated['overlap_policy'] ?? $schedule->overlap_policy;
        $results = [];

        foreach ($fireTimes as $fireTime) {
            try {
                $result = $this->startService->start(array_filter([
                    'workflow_type' => $action['workflow_type'],
                    'task_queue' => $action['task_queue'] ?? null,
                    'input' => $action['input'] ?? [],
                    'execution_timeout_seconds' => isset($action['execution_timeout_seconds']) ? (int) $action['execution_timeout_seconds'] : null,
                    'run_timeout_seconds' => isset($action['run_timeout_seconds']) ? (int) $action['run_timeout_seconds'] : null,
                    'memo' => $schedule->memo,
                    'search_attributes' => $schedule->search_attributes,
                    'duplicate_policy' => $this->overlapEnforcer->duplicateStartPolicy($overlapPolicy),
                ], static fn (mixed $value): bool => $value !== null), $schedule->namespace);

                $schedule->recordFire($result['workflow_id'], $result['run_id'], 'backfilled');
                $results[] = [
                    'fire_time' => $fireTime->format('c'),
                    'workflow_id' => $result['workflow_id'],
                    'outcome' => 'started',
                ];
            } catch (\Throwable $e) {
                $schedule->recordFailure($e->getMessage());
                $results[] = [
                    'fire_time' => $fireTime->format('c'),
                    'outcome' => 'failed',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $schedule->save();

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'backfill_started',
            'fires_attempted' => count($fireTimes),
            'results' => $results,
        ]);
    }

    /**
     * @return WorkflowSchedule|JsonResponse
     */
    private function findOrFail(Request $request, string $scheduleId): WorkflowSchedule|JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        $schedule = WorkflowSchedule::query()
            ->where('namespace', $namespace)
            ->where('schedule_id', $scheduleId)
            ->whereNot('status', ScheduleStatus::Deleted)
            ->first();

        if (! $schedule) {
            return response()->json([
                'message' => sprintf(
                    'Schedule [%s] not found in namespace [%s].',
                    $scheduleId,
                    $namespace,
                ),
                'reason' => 'schedule_not_found',
            ], 404);
        }

        return $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(WorkflowSchedule $schedule): array
    {
        return array_merge($schedule->toListItem(), array_filter([
            'fires_count' => (int) $schedule->fires_count,
            'jitter_seconds' => (int) $schedule->jitter_seconds > 0 ? (int) $schedule->jitter_seconds : null,
            'max_runs' => $schedule->max_runs !== null ? (int) $schedule->max_runs : null,
            'remaining_actions' => $schedule->remaining_actions !== null ? (int) $schedule->remaining_actions : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(WorkflowSchedule $schedule): array
    {
        $detail = $schedule->toDetail();

        $detail['jitter_seconds'] = (int) $schedule->jitter_seconds;
        $detail['max_runs'] = $schedule->max_runs !== null ? (int) $schedule->max_runs : null;
        $detail['remaining_actions'] = $schedule->remaining_actions !== null ? (int) $schedule->remaining_actions : null;
        $detail['latest_workflow_instance_id'] = $schedule->latest_workflow_instance_id;

        $detail['info']['skipped_trigger_count'] = (int) ($schedule->skipped_trigger_count ?? 0);
        $detail['info']['last_skip_reason'] = $schedule->last_skip_reason;
        $detail['info']['last_skipped_at'] = $schedule->last_skipped_at?->toIso8601String();

        return $detail;
    }

    private function recordSkip(WorkflowSchedule $schedule, string $reason): void
    {
        $schedule->forceFill([
            'last_skip_reason' => $reason,
            'last_skipped_at' => now(),
            'skipped_trigger_count' => ($schedule->skipped_trigger_count ?? 0) + 1,
        ])->save();
    }
}
