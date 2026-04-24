<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkflowCommandContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\ScheduleManager;

class ScheduleController
{
    public function __construct(
        private readonly WorkflowCommandContextFactory $commandContexts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $schedules = WorkflowSchedule::query()
            ->where('namespace', $namespace)
            ->whereNot('status', ScheduleStatus::Deleted)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (WorkflowSchedule $s) => $this->formatListItem($s))
            ->all();

        return ControlPlaneProtocol::json([
            'schedules' => $schedules,
            'next_page_token' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

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
            return ControlPlaneProtocol::json([
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

        $context = $this->commandContexts->make($request, $scheduleId, 'schedule.create');

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
            context: $context,
        );

        if (! empty($validated['paused'])) {
            ScheduleManager::pause(
                $schedule,
                context: $this->commandContexts->make($request, $scheduleId, 'schedule.pause'),
            );
        }

        return ControlPlaneProtocol::json([
            'schedule_id' => $scheduleId,
            'outcome' => 'created',
        ], 201);
    }

    public function show(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        return ControlPlaneProtocol::json($this->formatDetail($schedule));
    }

    public function update(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

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
            context: $this->commandContexts->make($request, $scheduleId, 'schedule.update'),
        );

        return ControlPlaneProtocol::json([
            'schedule_id' => $scheduleId,
            'outcome' => 'updated',
        ]);
    }

    public function destroy(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        ScheduleManager::delete(
            $schedule,
            $this->commandContexts->make($request, $scheduleId, 'schedule.delete'),
        );

        return ControlPlaneProtocol::json([
            'schedule_id' => $scheduleId,
            'outcome' => 'deleted',
        ]);
    }

    public function pause(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        ScheduleManager::pause(
            $schedule,
            $validated['note'] ?? null,
            $this->commandContexts->make($request, $scheduleId, 'schedule.pause'),
        );

        return ControlPlaneProtocol::json([
            'schedule_id' => $scheduleId,
            'outcome' => 'paused',
        ]);
    }

    public function resume(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $schedule = $this->findOrFail($request, $scheduleId);

        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        ScheduleManager::resume(
            $schedule,
            $this->commandContexts->make($request, $scheduleId, 'schedule.resume'),
        );

        if (array_key_exists('note', $validated) && $validated['note'] !== null) {
            $schedule->refresh();
            $schedule->note = $validated['note'];
            $schedule->save();
        }

        return ControlPlaneProtocol::json([
            'schedule_id' => $scheduleId,
            'outcome' => 'resumed',
        ]);
    }

    public function trigger(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

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
            $result = ScheduleManager::triggerDetailed(
                $schedule,
                $overlap,
                $this->commandContexts->make($request, $scheduleId, 'schedule.trigger'),
            );
        } catch (\Throwable $e) {
            return ControlPlaneProtocol::json([
                'schedule_id' => $scheduleId,
                'outcome' => 'trigger_failed',
                'reason' => $e->getMessage(),
            ], 500);
        }

        return match ($result->outcome) {
            'triggered' => ControlPlaneProtocol::json([
                'schedule_id' => $scheduleId,
                'outcome' => 'triggered',
                'workflow_id' => $result->instanceId,
                'run_id' => $result->runId,
            ]),
            'buffered' => ControlPlaneProtocol::json([
                'schedule_id' => $scheduleId,
                'outcome' => 'buffered',
                'buffer_depth' => count($schedule->fresh()->buffered_actions ?? []),
            ]),
            'buffer_full' => ControlPlaneProtocol::json([
                'schedule_id' => $scheduleId,
                'outcome' => 'buffer_full',
                'reason' => 'Previous workflow is still running and buffer is at capacity.',
            ]),
            default => ControlPlaneProtocol::json([
                'schedule_id' => $scheduleId,
                'outcome' => 'skipped',
                'reason' => $result->reason,
            ]),
        };
    }

    public function backfill(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

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
            return ControlPlaneProtocol::json([
                'message' => 'end_time must be after start_time.',
                'reason' => 'invalid_time_range',
            ], 422);
        }

        $overlap = isset($validated['overlap_policy'])
            ? ScheduleOverlapPolicy::tryFrom($validated['overlap_policy'])
            : null;

        $occurrences = ScheduleManager::backfill(
            $schedule,
            $startTime,
            $endTime,
            $overlap,
            $this->commandContexts->make($request, $scheduleId, 'schedule.backfill'),
        );

        $results = array_map(static fn (array $row): array => array_filter([
            'fire_time' => $row['cron_time'],
            'workflow_id' => $row['instance_id'],
            'outcome' => isset($row['error']) ? 'failed' : ($row['instance_id'] !== null ? 'started' : 'skipped'),
            'reason' => $row['error'] ?? null,
        ], static fn (mixed $v): bool => $v !== null), $occurrences);

        return ControlPlaneProtocol::json([
            'schedule_id' => $scheduleId,
            'outcome' => 'backfill_started',
            'fires_attempted' => count($results),
            'results' => $results,
        ]);
    }

    public function history(Request $request, string $scheduleId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $schedule = WorkflowSchedule::query()
            ->where('namespace', $namespace)
            ->where('schedule_id', $scheduleId)
            ->first();

        if (! $schedule) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Schedule [%s] not found in namespace [%s].',
                    $scheduleId,
                    $namespace,
                ),
                'reason' => 'schedule_not_found',
            ], 404);
        }

        $limit = $this->parseHistoryLimit($request->query('limit'));
        $afterSequence = $this->parseAfterSequence($request->query('after_sequence'));

        if ($afterSequence === false) {
            return ControlPlaneProtocol::json([
                'message' => 'after_sequence must be a non-negative integer.',
                'reason' => 'invalid_after_sequence',
            ], 422);
        }

        $query = $schedule->historyEvents();

        if ($afterSequence !== null) {
            $query->where('sequence', '>', $afterSequence);
        }

        $events = $query->limit($limit + 1)->get();
        $hasMore = $events->count() > $limit;
        $events = $events->take($limit);

        $nextCursor = $hasMore && $events->isNotEmpty()
            ? (int) $events->last()->sequence
            : null;

        return ControlPlaneProtocol::json([
            'schedule_id' => $schedule->schedule_id,
            'namespace' => $schedule->namespace,
            'events' => $events->map(static fn (WorkflowScheduleHistoryEvent $event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type?->value,
                'payload' => is_array($event->payload) ? $event->payload : [],
                'workflow_instance_id' => $event->workflow_instance_id,
                'workflow_run_id' => $event->workflow_run_id,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ]);
    }

    private function parseHistoryLimit(mixed $raw): int
    {
        $default = 100;
        $max = 500;

        if (! is_string($raw) && ! is_int($raw)) {
            return $default;
        }

        $value = (int) $raw;

        if ($value <= 0) {
            return $default;
        }

        return min($value, $max);
    }

    /**
     * Returns null when absent, an int >= 0 when valid, or false when
     * the supplied value is non-integer or negative.
     */
    private function parseAfterSequence(mixed $raw): int|false|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) && ! is_int($raw)) {
            return false;
        }

        if (is_string($raw) && ! preg_match('/^-?\d+$/', $raw)) {
            return false;
        }

        $value = (int) $raw;

        if ($value < 0) {
            return false;
        }

        return $value;
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
            return ControlPlaneProtocol::json([
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
            return ControlPlaneProtocol::json([
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
