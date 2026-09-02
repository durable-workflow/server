<?php

namespace App\Http\Controllers\Api;

use App\Support\AvroPayloadEnvelopeResolver;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceDurableStateException;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\ScheduleVisibilityQuery;
use App\Support\ScheduleVisibilityQueryException;
use App\Support\SearchAttributeValueValidator;
use App\Support\WorkflowCommandContextFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\ScheduleManager;

class ScheduleController
{
    public function __construct(
        private readonly WorkflowCommandContextFactory $commandContexts,
        private readonly SearchAttributeValueValidator $searchAttributeValues,
        private readonly ScheduleVisibilityQuery $visibilityQuery,
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $filterInput = $request->query();
        unset($filterInput['namespace']);

        $allowed = ['status', 'workflow_type', 'query', 'page_size', 'next_page_token'];
        $unsupported = array_values(array_diff(array_keys($filterInput), $allowed));

        if ($unsupported !== []) {
            $field = (string) $unsupported[0];

            return $this->scheduleListError(
                $request,
                422,
                'unsupported_schedule_list_filter',
                $field,
                sprintf('Schedule list filter [%s] is not supported.', $field),
            );
        }

        $validator = Validator::make($filterInput, [
            'status' => ['sometimes', 'required', 'string', 'in:active,paused'],
            'workflow_type' => ['sometimes', 'required', 'string', 'min:1', 'max:255', 'regex:/\S/'],
            'query' => ['sometimes', 'required', 'string', 'min:1', 'max:2048'],
            'page_size' => ['sometimes', 'required', 'integer', 'min:1', 'max:200'],
            'next_page_token' => ['sometimes', 'required', 'string', 'min:1', 'max:8192'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
            $field = (string) array_key_first($errors);

            return $this->scheduleListError(
                $request,
                422,
                'validation_failed',
                $field,
                (string) ($errors[$field][0] ?? 'Schedule list filter validation failed.'),
                $errors,
            );
        }

        /** @var array{
         *     status?: string,
         *     workflow_type?: string,
         *     query?: string,
         *     page_size?: int,
         *     next_page_token?: string
         * } $filters
         */
        $filters = $validator->validated();
        $token = null;

        if (isset($filters['next_page_token'])) {
            $token = $this->decodeSchedulePageToken($filters['next_page_token']);

            if ($token === null) {
                return $this->scheduleListError(
                    $request,
                    400,
                    'malformed_schedule_page_token',
                    'next_page_token',
                    'Schedule continuation token is malformed or has an invalid signature.',
                );
            }
        }

        $lastSafeCursor = $token === null ? null : $this->publicScheduleCursor($token['cursor']);

        if ($token !== null && ! hash_equals($token['namespace'], $namespace)) {
            return $this->scheduleListError(
                $request,
                403,
                'schedule_page_token_namespace_mismatch',
                'next_page_token',
                'Schedule continuation token belongs to a different namespace.',
                lastSafeCursor: $lastSafeCursor,
            );
        }

        try {
            $predicates = isset($filters['query'])
                ? $this->visibilityQuery->parse($namespace, $filters['query'])
                : [];
        } catch (ScheduleVisibilityQueryException $exception) {
            return $this->scheduleListError(
                $request,
                422,
                $exception->reason,
                'query',
                $exception->getMessage(),
                lastSafeCursor: $lastSafeCursor,
            );
        }

        $fingerprint = $this->scheduleFilterFingerprint($filters, $predicates);

        if ($token !== null && ! hash_equals($token['filter_fingerprint'], $fingerprint)) {
            return $this->scheduleListError(
                $request,
                409,
                'schedule_page_token_filter_mismatch',
                'next_page_token',
                'Schedule continuation token must be reused with the same status, workflow type, and visibility query.',
                lastSafeCursor: $lastSafeCursor,
            );
        }

        $query = $this->scheduleListQuery($namespace, $filters, $predicates);

        if ($token !== null && ! $this->scheduleCursorIsCurrent(clone $query, $token['cursor'])) {
            return $this->scheduleListError(
                $request,
                409,
                'stale_schedule_page_token',
                'next_page_token',
                'Schedule continuation token cursor is no longer present in the unchanged filtered set. '
                    .'Restart pagination without a token.',
                lastSafeCursor: $lastSafeCursor,
            );
        }

        if ($token !== null) {
            $cursor = $token['cursor'];
            $query->where(function (Builder $after) use ($cursor): void {
                $after->where('created_at', '<', $cursor['created_at'])
                    ->orWhere(function (Builder $sameTime) use ($cursor): void {
                        $sameTime->where('created_at', '=', $cursor['created_at'])
                            ->where('schedule_id', '>', $cursor['schedule_id']);
                    });
            });
        }

        $pageSize = (int) ($filters['page_size'] ?? 50);
        $rows = $query
            ->orderByDesc('created_at')
            ->orderBy('schedule_id')
            ->limit($pageSize + 1)
            ->get();
        $hasMore = $rows->count() > $pageSize;
        $page = $hasMore ? $rows->slice(0, $pageSize)->values() : $rows->values();
        $last = $page->last();

        return ControlPlaneProtocol::jsonForRequest($request, [
            'schedules' => $page->map(fn (WorkflowSchedule $schedule) => $this->formatListItem($schedule))->all(),
            'schedule_count' => $page->count(),
            'next_page_token' => $hasMore && $last instanceof WorkflowSchedule
                ? $this->encodeSchedulePageToken($namespace, $fingerprint, $last)
                : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate($this->storeRules());
        $this->validateActionInput(
            $validated['action'],
            is_string($namespace) ? $namespace : null,
        );

        if (($memoError = $this->validateMemoSize($validated['memo'] ?? null)) !== null) {
            return $memoError;
        }

        if (isset($validated['search_attributes'])) {
            $this->searchAttributeValues->validateForNamespace(
                is_string($namespace) ? $namespace : null,
                $validated['search_attributes'],
            );
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

        try {
            $schedule = DB::transaction(function () use (
                $request,
                $validated,
                $scheduleId,
                $namespace,
                $overlapPolicy,
                $context
            ): WorkflowSchedule {
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

                return $schedule;
            });
        } catch (LogicException $exception) {
            return $this->invalidScheduleSpecResponse($exception, $scheduleId);
        }

        return ControlPlaneProtocol::json([
            'schedule_id' => $schedule->schedule_id,
            'outcome' => 'created',
        ], 201);
    }

    private function invalidScheduleSpecResponse(LogicException $exception, string $scheduleId): JsonResponse
    {
        $message = $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'Schedule spec is invalid.';
        $isInvalidCron = str_starts_with($message, 'Invalid cron expression');
        $field = $isInvalidCron ? 'spec.cron_expressions' : 'spec';

        return ControlPlaneProtocol::json([
            'message' => $message,
            'reason' => $isInvalidCron ? 'invalid_cron_expression' : 'invalid_schedule_spec',
            'schedule_id' => $scheduleId,
            'errors' => [
                $field => [$message],
            ],
        ], 422);
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
        $this->validateActionInput(
            $validated['action'] ?? null,
            is_string($schedule->namespace) ? $schedule->namespace : null,
        );

        if (($memoError = $this->validateMemoSize($validated['memo'] ?? null)) !== null) {
            return $memoError;
        }

        if (isset($validated['search_attributes'])) {
            $this->searchAttributeValues->validateForNamespace(
                is_string($schedule->namespace) ? $schedule->namespace : null,
                $validated['search_attributes'],
            );
        }

        $overlapPolicy = isset($validated['overlap_policy'])
            ? ScheduleOverlapPolicy::tryFrom($validated['overlap_policy'])
            : null;

        DB::transaction(fn (): WorkflowSchedule => ScheduleManager::update(
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
        ));

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

        DB::transaction(fn (): WorkflowSchedule => ScheduleManager::pause(
            $schedule,
            $validated['note'] ?? null,
            $this->commandContexts->make($request, $scheduleId, 'schedule.pause'),
        ));

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

        DB::transaction(function () use ($request, $scheduleId, $schedule, $validated): void {
            ScheduleManager::resume(
                $schedule,
                $this->commandContexts->make($request, $scheduleId, 'schedule.resume'),
            );

            if (array_key_exists('note', $validated) && $validated['note'] !== null) {
                $schedule->refresh();
                $schedule->note = $validated['note'];
                $schedule->save();
            }
        });

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
        } catch (NamespaceDurableStateException $exception) {
            throw $exception;
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
     * @param array{
     *     status?: string|null,
     *     workflow_type?: string|null,
     *     query?: string|null,
     *     page_size?: int|null,
     *     next_page_token?: string|null
     * } $filters
     * @param  list<array{field: string, column: string|null, type: string, literal: bool|float|int|string}>  $predicates
     */
    private function scheduleListQuery(string $namespace, array $filters, array $predicates): Builder
    {
        $query = WorkflowSchedule::query()
            ->where('namespace', $namespace)
            ->whereNot('status', ScheduleStatus::Deleted)
            ->when(
                isset($filters['status']),
                static fn (Builder $builder) => $builder->where('status', $filters['status']),
            )
            ->when(
                isset($filters['workflow_type']),
                static fn (Builder $builder) => $builder->where('action->workflow_type', $filters['workflow_type']),
            );

        $this->visibilityQuery->apply($query, $predicates);

        return $query;
    }

    /**
     * @param array{
     *     status?: string|null,
     *     workflow_type?: string|null,
     *     query?: string|null,
     *     page_size?: int|null,
     *     next_page_token?: string|null
     * } $filters
     * @param  list<array{field: string, column: string|null, type: string, literal: bool|float|int|string}>  $predicates
     */
    private function scheduleFilterFingerprint(array $filters, array $predicates): string
    {
        return hash('sha256', json_encode([
            'status' => $filters['status'] ?? null,
            'workflow_type' => $filters['workflow_type'] ?? null,
            'visibility_predicates' => $predicates,
        ], JSON_THROW_ON_ERROR));
    }

    private function encodeSchedulePageToken(
        string $namespace,
        string $filterFingerprint,
        WorkflowSchedule $schedule,
    ): string {
        $payload = [
            'version' => 1,
            'namespace' => $namespace,
            'filter_fingerprint' => $filterFingerprint,
            'cursor' => [
                'created_at' => $this->scheduleCursorTimestamp($schedule->created_at),
                'schedule_id' => (string) $schedule->schedule_id,
            ],
        ];
        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $this->schedulePageTokenKey());

        return $encoded.'.'.$signature;
    }

    /**
     * @return array{
     *     version: int,
     *     namespace: string,
     *     filter_fingerprint: string,
     *     cursor: array{created_at: string, schedule_id: string}
     * }|null
     */
    private function decodeSchedulePageToken(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || $parts[0] === '' || preg_match('/^[a-f0-9]{64}$/', $parts[1]) !== 1) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = hash_hmac('sha256', $encoded, $this->schedulePageTokenKey());

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($encoded);

        if ($json === null) {
            return null;
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['namespace'] ?? null)
            || $payload['namespace'] === ''
            || ! is_string($payload['filter_fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $payload['filter_fingerprint']) !== 1
            || ! is_array($payload['cursor'] ?? null)
            || ! is_string($payload['cursor']['created_at'] ?? null)
            || ! is_string($payload['cursor']['schedule_id'] ?? null)
            || $payload['cursor']['schedule_id'] === ''
            || ! $this->validScheduleCursorTimestamp($payload['cursor']['created_at'])
        ) {
            return null;
        }

        /** @var array{
         *     version: int,
         *     namespace: string,
         *     filter_fingerprint: string,
         *     cursor: array{created_at: string, schedule_id: string}
         * } $payload
         */
        return $payload;
    }

    /**
     * @param  array{created_at: string, schedule_id: string}  $cursor
     */
    private function scheduleCursorIsCurrent(Builder $query, array $cursor): bool
    {
        $schedule = $query
            ->where('schedule_id', $cursor['schedule_id'])
            ->first();

        return $schedule instanceof WorkflowSchedule
            && hash_equals($cursor['created_at'], $this->scheduleCursorTimestamp($schedule->created_at));
    }

    private function scheduleCursorTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        return (string) $value;
    }

    private function validScheduleCursorTimestamp(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value);

        return $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d H:i:s.u') === $value;
    }

    private function schedulePageTokenKey(): string
    {
        return hash('sha256', 'durable-workflow.schedule-list.page-token|'.(string) config('app.key'), true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @param  array{created_at: string, schedule_id: string}  $cursor
     * @return array{created_at: string, schedule_id: string}
     */
    private function publicScheduleCursor(array $cursor): array
    {
        $createdAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $cursor['created_at']);

        return [
            'created_at' => $createdAt instanceof \DateTimeImmutable
                ? $createdAt->format('Y-m-d\TH:i:s.u\Z')
                : $cursor['created_at'],
            'schedule_id' => $cursor['schedule_id'],
        ];
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array{created_at: string, schedule_id: string}|null  $lastSafeCursor
     */
    private function scheduleListError(
        Request $request,
        int $status,
        string $reason,
        string $field,
        string $message,
        ?array $errors = null,
        ?array $lastSafeCursor = null,
    ): JsonResponse {
        $errors ??= [$field => [$message]];

        return ControlPlaneProtocol::jsonForRequest($request, [
            'message' => $message,
            'reason' => $reason,
            'field' => $field,
            'errors' => $errors,
            'validation_errors' => $errors,
            'last_safe_cursor' => $lastSafeCursor,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(WorkflowSchedule $schedule): array
    {
        $item = $schedule->toListItem();
        $item['namespace'] = $schedule->namespace;
        $item['spec'] = $schedule->spec;
        $item['action'] = is_array($schedule->action)
            ? WorkflowSchedule::normalizeActionTimeouts($schedule->action)
            : null;
        $item['next_fire_at'] = $schedule->next_fire_at?->toIso8601String();
        $item['last_fired_at'] = $schedule->last_fired_at?->toIso8601String();
        $item['search_attributes'] = $schedule->search_attributes;
        $item['created_at'] = $schedule->created_at?->toIso8601String();
        $item['updated_at'] = $schedule->updated_at?->toIso8601String();

        return array_merge($item, array_filter([
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

        $detail['namespace'] = $schedule->namespace;
        $detail['status'] = $schedule->status?->value;
        $detail['paused'] = $schedule->isPaused();
        $detail['note'] = $schedule->note;
        $detail['fires_count'] = (int) $schedule->fires_count;
        $detail['failures_count'] = (int) $schedule->failures_count;
        $detail['next_fire_at'] = $schedule->next_fire_at?->toIso8601String();
        $detail['last_fired_at'] = $schedule->last_fired_at?->toIso8601String();
        $detail['paused_at'] = $schedule->paused_at?->toIso8601String();

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
     * @param  array<string, mixed>|null  $action
     */
    private function validateActionInput(?array $action, ?string $namespace): void
    {
        if ($action === null || ! array_key_exists('input', $action)) {
            return;
        }

        AvroPayloadEnvelopeResolver::resolve(
            $action['input'],
            'action.input',
            $this->externalPayloadStorage->driverFor($namespace),
        );
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
