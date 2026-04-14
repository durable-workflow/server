<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\ScheduleManager;

class ScheduleController
{
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

        $validated = $request->validate($this->storeRules());

        if (($memoError = $this->validateMemoSize($validated['memo'] ?? null)) !== null) {
            return $memoError;
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

        $overlapPolicy = ScheduleOverlapPolicy::tryFrom($validated['overlap_policy'] ?? 'skip')
            ?? ScheduleOverlapPolicy::Skip;

        $schedule = ScheduleManager::createFromSpec(
            scheduleId: $scheduleId,
            spec: $validated['spec'],
            action: $validated['action'],
            overlapPolicy: $overlapPolicy,
            memo: $validated['memo'] ?? [],
            searchAttributes: $validated['search_attributes'] ?? [],
            jitterSeconds: (int) ($validated['jitter_seconds'] ?? 0),
            maxRuns: $validated['max_runs'] ?? null,
            note: $validated['note'] ?? null,
            namespace: $namespace,
        );

        if (! empty($validated['paused'])) {
            ScheduleManager::pause($schedule);
        }

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

        $validated = $request->validate($this->updateRules());

        if (($memoError = $this->validateMemoSize($validated['memo'] ?? null)) !== null) {
            return $memoError;
        }

        $overlapPolicy = isset($validated['overlap_policy'])
            ? ScheduleOverlapPolicy::tryFrom($validated['overlap_policy'])
            : null;

        ScheduleManager::update(
            schedule: $schedule,
            overlapPolicy: $overlapPolicy,
            jitterSeconds: isset($validated['jitter_seconds']) ? (int) $validated['jitter_seconds'] : null,
            notes: array_key_exists('note', $validated) ? $validated['note'] : null,
            spec: $validated['spec'] ?? null,
            action: isset($validated['action'])
                ? array_merge(is_array($schedule->action) ? $schedule->action : [], $validated['action'])
                : null,
            memo: array_key_exists('memo', $validated) ? ($validated['memo'] ?? []) : null,
            searchAttributes: array_key_exists('search_attributes', $validated)
                ? ($validated['search_attributes'] ?? [])
                : null,
            maxRuns: isset($validated['max_runs']) ? (int) $validated['max_runs'] : null,
        );

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

        ScheduleManager::delete($schedule);

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

        ScheduleManager::pause($schedule, $validated['note'] ?? null);

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

        ScheduleManager::resume($schedule);

        if (array_key_exists('note', $validated) && $validated['note'] !== null) {
            $schedule->refresh();
            $schedule->note = $validated['note'];
            $schedule->save();
        }

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

        $overlap = isset($validated['overlap_policy'])
            ? ScheduleOverlapPolicy::tryFrom($validated['overlap_policy'])
            : null;

        try {
            $result = ScheduleManager::triggerDetailed($schedule, $overlap);
        } catch (\Throwable $e) {
            return response()->json([
                'schedule_id' => $scheduleId,
                'outcome' => 'trigger_failed',
                'reason' => $e->getMessage(),
            ], 500);
        }

        return match ($result->outcome) {
            'triggered' => response()->json([
                'schedule_id' => $scheduleId,
                'outcome' => 'triggered',
                'workflow_id' => $result->instanceId,
                'run_id' => $result->runId,
            ]),
            'buffered' => response()->json([
                'schedule_id' => $scheduleId,
                'outcome' => 'buffered',
                'buffer_depth' => count($schedule->fresh()->buffered_actions ?? []),
            ]),
            'buffer_full' => response()->json([
                'schedule_id' => $scheduleId,
                'outcome' => 'buffer_full',
                'reason' => 'Previous workflow is still running and buffer is at capacity.',
            ]),
            default => response()->json([
                'schedule_id' => $scheduleId,
                'outcome' => 'skipped',
                'reason' => $result->reason,
            ]),
        };
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

        $overlap = isset($validated['overlap_policy'])
            ? ScheduleOverlapPolicy::tryFrom($validated['overlap_policy'])
            : null;

        $occurrences = ScheduleManager::backfill($schedule, $startTime, $endTime, $overlap);

        $results = array_map(static fn (array $row): array => [
            'fire_time' => $row['cron_time'],
            'workflow_id' => $row['instance_id'],
            'outcome' => $row['instance_id'] !== null ? 'started' : 'skipped',
        ], $occurrences);

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'backfill_started',
            'fires_attempted' => count($results),
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

    private function validateMemoSize(mixed $memo): ?JsonResponse
    {
        if (! is_array($memo)) {
            return null;
        }

        $memoSize = strlen(json_encode($memo));
        $maxMemoBytes = (int) config('server.limits.max_memo_bytes', 256 * 1024);

        if ($memoSize > $maxMemoBytes) {
            return response()->json([
                'message' => sprintf('The memo exceeds the maximum allowed size of %d bytes.', $maxMemoBytes),
                'reason' => 'memo_too_large',
                'limit' => $maxMemoBytes,
            ], 422);
        }

        return null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function storeRules(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function updateRules(): array
    {
        return [
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
        ];
    }
}
