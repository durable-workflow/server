<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowSchedule;
use App\Support\ScheduleOverlapEnforcer;
use App\Support\WorkflowStartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (WorkflowSchedule $s) => $s->toListItem())
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
            'action.workflow_execution_timeout' => ['nullable', 'integer'],
            'action.workflow_run_timeout' => ['nullable', 'integer'],
            'overlap_policy' => ['nullable', 'string', 'in:'.implode(',', WorkflowSchedule::OVERLAP_POLICIES)],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'paused' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

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

        $schedule = WorkflowSchedule::create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => $validated['spec'],
            'action' => $validated['action'],
            'overlap_policy' => $validated['overlap_policy'] ?? 'skip',
            'paused' => $validated['paused'] ?? false,
            'note' => $validated['note'] ?? null,
            'memo' => $validated['memo'] ?? null,
            'search_attributes' => $validated['search_attributes'] ?? null,
        ]);

        $schedule->next_fire_at = $schedule->computeNextFireAt();
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

        return response()->json($schedule->toDetail());
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
            'action.workflow_execution_timeout' => ['nullable', 'integer'],
            'action.workflow_run_timeout' => ['nullable', 'integer'],
            'overlap_policy' => ['nullable', 'string', 'in:'.implode(',', WorkflowSchedule::OVERLAP_POLICIES)],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

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

        if (array_key_exists('note', $validated)) {
            $schedule->note = $validated['note'];
        }

        $schedule->next_fire_at = $schedule->computeNextFireAt();
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

        $schedule->delete();

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

        $schedule->paused = true;

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

        $schedule->paused = false;

        if (array_key_exists('note', $validated) && $validated['note'] !== null) {
            $schedule->note = $validated['note'];
        }

        $schedule->next_fire_at = $schedule->computeNextFireAt();
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

        $action = $schedule->action;
        $overlapPolicy = $validated['overlap_policy'] ?? $schedule->overlap_policy;

        // Buffer policies: check if the previous workflow is still running
        if ($this->overlapEnforcer->isBufferPolicy($overlapPolicy)) {
            if ($this->overlapEnforcer->lastFiredWorkflowIsRunning($schedule)) {
                if ($schedule->isAtBufferCapacity($overlapPolicy)) {
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

            // Previous workflow is not running — fire normally (fall through)
        }

        try {
            $this->overlapEnforcer->enforce($schedule, $overlapPolicy);

            $result = $this->startService->start([
                'workflow_type' => $action['workflow_type'],
                'task_queue' => $action['task_queue'] ?? null,
                'input' => $action['input'] ?? [],
                'memo' => $schedule->memo,
                'search_attributes' => $schedule->search_attributes,
                'duplicate_policy' => $this->overlapEnforcer->duplicateStartPolicy($overlapPolicy),
            ], $schedule->namespace);

            $schedule->recordFire($result['workflow_id'], $result['run_id'], $result['outcome'] ?? 'started');
            $schedule->save();

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

        $action = $schedule->action;
        $overlapPolicy = $validated['overlap_policy'] ?? $schedule->overlap_policy;
        $results = [];

        foreach ($fireTimes as $fireTime) {
            try {
                $result = $this->startService->start([
                    'workflow_type' => $action['workflow_type'],
                    'task_queue' => $action['task_queue'] ?? null,
                    'input' => $action['input'] ?? [],
                    'memo' => $schedule->memo,
                    'search_attributes' => $schedule->search_attributes,
                    'duplicate_policy' => $this->overlapEnforcer->duplicateStartPolicy($overlapPolicy),
                ], $schedule->namespace);

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
}
