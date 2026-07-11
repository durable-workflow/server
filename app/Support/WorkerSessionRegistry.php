<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use App\Models\WorkerSessionLease;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;

final class WorkerSessionRegistry
{
    private const DEFAULT_LEASE_SECONDS = 120;
    private const DEFAULT_TTL_SECONDS = 1800;
    private const DEFAULT_MAX_CONCURRENT_ACTIVITIES = 1;

    /**
     * @param  array<string, mixed>|null  $workerSession
     * @return array<string, mixed>|null
     */
    public function normalizeOptions(
        ?array $workerSession,
        ?string $fallbackQueue = null,
        ?string $fallbackConnection = null,
    ): ?array {
        if ($workerSession === null) {
            return null;
        }

        $sessionId = $this->stringValue($workerSession['session_id'] ?? null);

        if ($sessionId === null) {
            return null;
        }

        return [
            'session_id' => $sessionId,
            'connection' => $this->stringValue($workerSession['connection'] ?? null) ?? $fallbackConnection,
            'queue' => $this->stringValue($workerSession['queue'] ?? null) ?? $fallbackQueue,
            'requirements' => $this->stringList($workerSession['requirements'] ?? []),
            'lease_seconds' => $this->positiveInt($workerSession['lease_seconds'] ?? null)
                ?? self::DEFAULT_LEASE_SECONDS,
            'ttl_seconds' => $this->positiveInt($workerSession['ttl_seconds'] ?? null)
                ?? self::DEFAULT_TTL_SECONDS,
            'max_concurrent_activities' => $this->positiveInt($workerSession['max_concurrent_activities'] ?? null)
                ?? self::DEFAULT_MAX_CONCURRENT_ACTIVITIES,
            'create_if_missing' => $this->boolValue($workerSession['create_if_missing'] ?? null, true),
            'allow_reacquire_after_failure' => $this->boolValue(
                $workerSession['allow_reacquire_after_failure'] ?? null,
                true,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function createOrReacquire(
        string $namespace,
        WorkerRegistration $worker,
        array $options,
    ): array {
        return DB::transaction(function () use ($namespace, $worker, $options): array {
            return $this->acquireLocked($namespace, $worker, $options, null);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function admitActivity(
        string $namespace,
        WorkerRegistration $worker,
        array $options,
        string $taskId,
    ): array {
        return DB::transaction(function () use ($namespace, $worker, $options, $taskId): array {
            return $this->acquireLocked($namespace, $worker, $options, $taskId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function heartbeat(string $namespace, string $workerId, string $sessionId, ?int $leaseSeconds = null): array
    {
        return DB::transaction(function () use ($namespace, $workerId, $sessionId, $leaseSeconds): array {
            /** @var WorkerSessionLease|null $session */
            $session = WorkerSessionLease::query()
                ->where('namespace', $namespace)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return $this->failure('session_not_found', 'Worker session was not found.', 404);
            }

            $session = $this->refreshStatusLocked($session);

            if ($session->status !== WorkerSessionLease::STATUS_ACTIVE) {
                return $this->failure(
                    'session_not_active',
                    'Worker session is not active.',
                    409,
                    $this->sessionSnapshot($session),
                );
            }

            if ($session->lease_owner !== $workerId) {
                return $this->failure(
                    'session_owner_mismatch',
                    'Worker session lease is owned by another worker.',
                    409,
                    $this->sessionSnapshot($session),
                );
            }

            $leaseSeconds = max(1, $leaseSeconds ?? (int) $session->lease_seconds);
            $now = now();

            $session->forceFill([
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                'last_heartbeat_at' => $now,
                'failure_reason' => null,
            ])->save();

            return $this->success($session, 'heartbeat_recorded');
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function heartbeatForAttempt(string $namespace, string $attemptId, string $workerId): ?array
    {
        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()->find($attemptId);

        if (! $attempt instanceof ActivityAttempt) {
            return null;
        }

        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()->find($attempt->activity_execution_id);

        if (! $execution instanceof ActivityExecution) {
            return null;
        }

        $options = $this->normalizeOptions(
            is_array($execution->activity_options['worker_session'] ?? null)
                ? $execution->activity_options['worker_session']
                : null,
            $this->stringValue($execution->queue),
            $this->stringValue($execution->connection),
        );

        if ($options === null) {
            return null;
        }

        return $this->heartbeat(
            $namespace,
            $workerId,
            $options['session_id'],
            $options['lease_seconds'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function close(string $namespace, string $workerId, string $sessionId): array
    {
        return DB::transaction(function () use ($namespace, $workerId, $sessionId): array {
            /** @var WorkerSessionLease|null $session */
            $session = WorkerSessionLease::query()
                ->where('namespace', $namespace)
                ->where('session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return $this->failure('session_not_found', 'Worker session was not found.', 404);
            }

            $session = $this->refreshStatusLocked($session);

            if ($session->status === WorkerSessionLease::STATUS_CLOSED && $session->lease_owner === $workerId) {
                return $this->success($session, 'already_closed');
            }

            if ($session->lease_owner !== $workerId) {
                return $this->failure(
                    'session_owner_mismatch',
                    'Worker session lease is owned by another worker.',
                    409,
                    $this->sessionSnapshot($session),
                );
            }

            $session->forceFill([
                'status' => WorkerSessionLease::STATUS_CLOSED,
                'closed_at' => now(),
                'failure_reason' => null,
            ])->save();

            return $this->success($session, 'closed');
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function optionsForExecution(?string $executionId): ?array
    {
        if ($executionId === null || $executionId === '') {
            return null;
        }

        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()->find($executionId);

        if (! $execution instanceof ActivityExecution) {
            return null;
        }

        return $this->normalizeOptions(
            is_array($execution->activity_options['worker_session'] ?? null)
                ? $execution->activity_options['worker_session']
                : null,
            $this->stringValue($execution->queue),
            $this->stringValue($execution->connection),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function workerSessionForExecution(?string $executionId): ?array
    {
        $options = $this->optionsForExecution($executionId);

        if ($options === null) {
            return null;
        }

        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()->find($executionId);

        /** @var WorkerSessionLease|null $session */
        $session = WorkerSessionLease::query()
            ->where('namespace', $execution instanceof ActivityExecution
                ? ($this->namespaceForExecution($execution) ?? 'default')
                : 'default')
            ->where('session_id', $options['session_id'])
            ->first();

        return array_filter([
            ...$options,
            'status' => $session?->status,
            'lease_owner' => $session?->lease_owner,
            'lease_expires_at' => $session?->lease_expires_at?->toJSON(),
            'ttl_expires_at' => $session?->ttl_expires_at?->toJSON(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function visibility(string $namespace, ?string $sessionId = null): array
    {
        $this->refreshNamespace($namespace);

        $query = WorkerSessionLease::query()
            ->where('namespace', $namespace)
            ->orderBy('updated_at', 'desc')
            ->orderBy('session_id');

        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        }

        $sessions = $query->get()
            ->map(fn (WorkerSessionLease $session): array => $this->sessionSnapshot($session))
            ->values()
            ->all();

        return [
            'namespace' => $namespace,
            'metrics' => $this->metrics($namespace),
            'sessions' => $sessions,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function metrics(string $namespace): array
    {
        $this->refreshNamespace($namespace);

        $counts = WorkerSessionLease::query()
            ->where('namespace', $namespace)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return [
            'total' => array_sum(array_map('intval', $counts)),
            'active' => (int) ($counts[WorkerSessionLease::STATUS_ACTIVE] ?? 0),
            'closed' => (int) ($counts[WorkerSessionLease::STATUS_CLOSED] ?? 0),
            'expired' => (int) ($counts[WorkerSessionLease::STATUS_EXPIRED] ?? 0),
            'failed' => (int) ($counts[WorkerSessionLease::STATUS_FAILED] ?? 0),
            'orphaned' => (int) ($counts[WorkerSessionLease::STATUS_ORPHANED] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function acquireLocked(
        string $namespace,
        WorkerRegistration $worker,
        array $options,
        ?string $activityTaskId,
    ): array {
        $route = $this->routingFailure($worker, $options);
        if ($route !== null) {
            return $route;
        }

        $capabilities = $this->capabilityFailure($worker, $options['requirements']);
        if ($capabilities !== null) {
            return $capabilities;
        }

        /** @var WorkerSessionLease|null $session */
        $session = WorkerSessionLease::query()
            ->where('namespace', $namespace)
            ->where('session_id', $options['session_id'])
            ->lockForUpdate()
            ->first();

        if ($session instanceof WorkerSessionLease) {
            $session = $this->refreshStatusLocked($session);
        }

        if (! $session instanceof WorkerSessionLease) {
            if ($options['create_if_missing'] !== true) {
                return $this->failure('session_missing', 'Worker session does not exist and creation is disabled.', 409);
            }

            $limit = $this->workerSessionLimitFailure($namespace, $worker);
            if ($limit !== null) {
                return $limit;
            }

            $now = now();

            $session = WorkerSessionLease::query()->create([
                'namespace' => $namespace,
                'session_id' => $options['session_id'],
                'connection' => $options['connection'],
                'queue' => $options['queue'],
                'requirements' => $options['requirements'],
                'status' => WorkerSessionLease::STATUS_ACTIVE,
                'lease_owner' => $worker->worker_id,
                'lease_expires_at' => $now->copy()->addSeconds($options['lease_seconds']),
                'ttl_expires_at' => $now->copy()->addSeconds($options['ttl_seconds']),
                'lease_seconds' => $options['lease_seconds'],
                'ttl_seconds' => $options['ttl_seconds'],
                'max_concurrent_activities' => $options['max_concurrent_activities'],
                'create_if_missing' => $options['create_if_missing'],
                'allow_reacquire_after_failure' => $options['allow_reacquire_after_failure'],
                'last_heartbeat_at' => $now,
            ]);

            return $this->success($session, 'created', $activityTaskId);
        }

        if ($session->status === WorkerSessionLease::STATUS_ACTIVE) {
            if ($session->lease_owner !== $worker->worker_id) {
                return $this->failure(
                    'session_owned_by_another_worker',
                    'Worker session is currently leased by another worker.',
                    409,
                    $this->sessionSnapshot($session),
                );
            }

            if ($activityTaskId !== null) {
                $concurrency = $this->sessionConcurrencyFailure($namespace, $session, $activityTaskId);
                if ($concurrency !== null) {
                    return $concurrency;
                }
            }

            $session = $this->renewHeldSession($session, $options, $worker->worker_id);

            return $this->success($session, 'reused', $activityTaskId);
        }

        if ($session->status === WorkerSessionLease::STATUS_CLOSED) {
            return $this->failure(
                'session_closed',
                'Worker session was closed and cannot be reacquired.',
                409,
                $this->sessionSnapshot($session),
            );
        }

        if (($session->failure_reason === 'ttl_expired') || $session->allow_reacquire_after_failure !== true) {
            return $this->failure(
                'session_reacquire_disallowed',
                'Worker session is terminal and cannot be reacquired.',
                409,
                $this->sessionSnapshot($session),
            );
        }

        if ($options['allow_reacquire_after_failure'] !== true) {
            return $this->failure(
                'session_reacquire_disallowed',
                'Worker session options disallow reacquisition after holder failure.',
                409,
                $this->sessionSnapshot($session),
            );
        }

        $limit = $this->workerSessionLimitFailure($namespace, $worker, $session->session_id);
        if ($limit !== null) {
            return $limit;
        }

        $session = $this->renewHeldSession($session, $options, $worker->worker_id, true);

        return $this->success($session, 'reacquired', $activityTaskId);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function renewHeldSession(
        WorkerSessionLease $session,
        array $options,
        string $workerId,
        bool $resetTtl = false,
    ): WorkerSessionLease {
        $now = now();

        $session->forceFill(array_filter([
            'connection' => $options['connection'],
            'queue' => $options['queue'],
            'requirements' => $options['requirements'],
            'status' => WorkerSessionLease::STATUS_ACTIVE,
            'lease_owner' => $workerId,
            'lease_expires_at' => $now->copy()->addSeconds($options['lease_seconds']),
            'ttl_expires_at' => $resetTtl ? $now->copy()->addSeconds($options['ttl_seconds']) : null,
            'closed_at' => null,
            'failure_reason' => null,
            'lease_seconds' => $options['lease_seconds'],
            'ttl_seconds' => $options['ttl_seconds'],
            'max_concurrent_activities' => $options['max_concurrent_activities'],
            'create_if_missing' => $options['create_if_missing'],
            'allow_reacquire_after_failure' => $options['allow_reacquire_after_failure'],
            'last_heartbeat_at' => $now,
        ], static fn (mixed $value): bool => $value !== null))->save();

        return $session;
    }

    private function refreshStatusLocked(WorkerSessionLease $session): WorkerSessionLease
    {
        if ($session->status !== WorkerSessionLease::STATUS_ACTIVE) {
            return $session;
        }

        $now = now();

        if ($session->ttl_expires_at !== null && $session->ttl_expires_at->lte($now)) {
            $session->forceFill([
                'status' => WorkerSessionLease::STATUS_EXPIRED,
                'failure_reason' => 'ttl_expired',
            ])->save();

            return $session;
        }

        if ($session->lease_expires_at !== null && $session->lease_expires_at->lte($now)) {
            $session->forceFill([
                'status' => WorkerSessionLease::STATUS_EXPIRED,
                'failure_reason' => 'lease_expired',
            ])->save();

            return $session;
        }

        if ($session->lease_owner !== null && $this->workerIsStale($session->namespace, $session->lease_owner)) {
            $session->forceFill([
                'status' => WorkerSessionLease::STATUS_ORPHANED,
                'failure_reason' => 'worker_heartbeat_stale',
            ])->save();
        }

        return $session;
    }

    private function refreshNamespace(string $namespace): void
    {
        WorkerSessionLease::query()
            ->where('namespace', $namespace)
            ->where('status', WorkerSessionLease::STATUS_ACTIVE)
            ->orderBy('id')
            ->get()
            ->each(function (WorkerSessionLease $session): void {
                DB::transaction(function () use ($session): void {
                    /** @var WorkerSessionLease|null $locked */
                    $locked = WorkerSessionLease::query()
                        ->whereKey($session->getKey())
                        ->lockForUpdate()
                        ->first();

                    if ($locked instanceof WorkerSessionLease) {
                        $this->refreshStatusLocked($locked);
                    }
                });
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function routingFailure(WorkerRegistration $worker, array $options): ?array
    {
        $queue = $this->stringValue($options['queue'] ?? null);

        if ($queue !== null && $worker->task_queue !== $queue) {
            return $this->failure(
                'session_queue_mismatch',
                sprintf(
                    'Worker is registered for task queue [%s], not worker-session queue [%s].',
                    $worker->task_queue,
                    $queue,
                ),
                409,
            );
        }

        return null;
    }

    /**
     * @param  list<string>  $requirements
     * @return array<string, mixed>|null
     */
    private function capabilityFailure(WorkerRegistration $worker, array $requirements): ?array
    {
        if ($requirements === []) {
            return null;
        }

        $capabilities = array_flip($this->stringList($worker->capabilities ?? []));
        $missing = array_values(array_filter(
            $requirements,
            static fn (string $requirement): bool => ! isset($capabilities[$requirement]),
        ));

        if ($missing === []) {
            return null;
        }

        return $this->failure(
            'session_requirements_not_met',
            'Worker does not advertise every worker-session requirement.',
            409,
            ['missing_requirements' => $missing],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function workerSessionLimitFailure(
        string $namespace,
        WorkerRegistration $worker,
        ?string $excludingSessionId = null,
    ): ?array {
        $limit = max(0, (int) ($worker->max_concurrent_worker_sessions ?? 0));

        if ($limit === 0) {
            return $this->failure(
                'worker_session_limit_exceeded',
                'Worker is not configured to hold worker-session leases.',
                409,
            );
        }

        $query = WorkerSessionLease::query()
            ->where('namespace', $namespace)
            ->where('lease_owner', $worker->worker_id)
            ->where('status', WorkerSessionLease::STATUS_ACTIVE)
            ->where(function ($builder): void {
                $builder->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '>', now());
            });

        if ($excludingSessionId !== null) {
            $query->where('session_id', '!=', $excludingSessionId);
        }

        if ($query->count() < $limit) {
            return null;
        }

        return $this->failure(
            'worker_session_limit_exceeded',
            'Worker has reached max_concurrent_worker_sessions.',
            409,
            ['max_concurrent_worker_sessions' => $limit],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sessionConcurrencyFailure(
        string $namespace,
        WorkerSessionLease $session,
        ?string $activityTaskId,
    ): ?array {
        $limit = max(1, (int) $session->max_concurrent_activities);
        $active = $this->activeActivityCount($namespace, $session->session_id, $session->lease_owner, $activityTaskId);

        if ($active < $limit) {
            return null;
        }

        return $this->failure(
            'session_activity_limit_exceeded',
            'Worker session has reached max_concurrent_activities.',
            409,
            $this->sessionSnapshot($session) + ['max_concurrent_activities' => $limit],
        );
    }

    private function activeActivityCount(
        string $namespace,
        string $sessionId,
        ?string $leaseOwner,
        ?string $excludingTaskId = null,
    ): int {
        $query = ActivityAttempt::query()
            ->join('activity_executions', 'activity_executions.id', '=', 'activity_attempts.activity_execution_id')
            ->join('workflow_tasks', 'workflow_tasks.id', '=', 'activity_attempts.workflow_task_id')
            ->where('workflow_tasks.namespace', $namespace)
            ->where('activity_attempts.status', ActivityAttemptStatus::Running->value)
            ->where('activity_attempts.lease_expires_at', '>', now())
            ->where('activity_executions.activity_options->worker_session->session_id', $sessionId);

        if ($leaseOwner !== null) {
            $query->where('activity_attempts.lease_owner', $leaseOwner);
        }

        if ($excludingTaskId !== null) {
            $query->where('activity_attempts.workflow_task_id', '!=', $excludingTaskId);
        }

        return (int) $query->count();
    }

    private function workerIsStale(string $namespace, string $workerId): bool
    {
        /** @var WorkerRegistration|null $worker */
        $worker = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('worker_id', $workerId)
            ->first();

        if (! $worker instanceof WorkerRegistration || $worker->last_heartbeat_at === null) {
            return true;
        }

        return $worker->last_heartbeat_at->lt(now()->subSeconds($this->workerStaleAfterSeconds()));
    }

    private function workerStaleAfterSeconds(): int
    {
        return max(1, (int) config('server.workers.stale_after_seconds', 30));
    }

    /**
     * @return array<string, mixed>
     */
    private function success(WorkerSessionLease $session, string $outcome, ?string $activityTaskId = null): array
    {
        return [
            'admitted' => true,
            'outcome' => $outcome,
            'session' => $this->sessionSnapshot($session),
            'activity_task_id' => $activityTaskId,
            'status' => $outcome === 'created' ? 201 : 200,
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function failure(string $reason, string $message, int $status = 409, array $context = []): array
    {
        return [
            'admitted' => false,
            'error' => $message,
            'reason' => $reason,
            'status' => $status,
            ...$context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionSnapshot(WorkerSessionLease $session): array
    {
        $holder = null;

        if ($session->lease_owner !== null) {
            /** @var WorkerRegistration|null $worker */
            $worker = WorkerRegistration::query()
                ->where('namespace', $session->namespace)
                ->where('worker_id', $session->lease_owner)
                ->first();

            $holder = [
                'worker_id' => $session->lease_owner,
                'task_queue' => $worker?->task_queue,
                'status' => $worker?->status,
                'last_heartbeat_at' => $worker?->last_heartbeat_at?->toJSON(),
                'is_stale' => $this->workerIsStale($session->namespace, $session->lease_owner),
            ];
        }

        return [
            'session_id' => $session->session_id,
            'namespace' => $session->namespace,
            'connection' => $session->connection,
            'queue' => $session->queue,
            'requirements' => $this->stringList($session->requirements ?? []),
            'status' => $session->status,
            'lease_owner' => $session->lease_owner,
            'holder' => $holder,
            'lease_expires_at' => $session->lease_expires_at?->toJSON(),
            'ttl_expires_at' => $session->ttl_expires_at?->toJSON(),
            'closed_at' => $session->closed_at?->toJSON(),
            'failure_reason' => $session->failure_reason,
            'lease_seconds' => (int) $session->lease_seconds,
            'ttl_seconds' => (int) $session->ttl_seconds,
            'max_concurrent_activities' => (int) $session->max_concurrent_activities,
            'create_if_missing' => (bool) $session->create_if_missing,
            'allow_reacquire_after_failure' => (bool) $session->allow_reacquire_after_failure,
            'active_activity_count' => $this->activeActivityCount(
                $session->namespace,
                $session->session_id,
                $session->lease_owner,
            ),
            'last_heartbeat_at' => $session->last_heartbeat_at?->toJSON(),
            'created_at' => $session->created_at?->toJSON(),
            'updated_at' => $session->updated_at?->toJSON(),
        ];
    }

    private function namespaceForExecution(ActivityExecution $execution): ?string
    {
        return ActivityAttempt::query()
            ->join('workflow_tasks', 'workflow_tasks.id', '=', 'activity_attempts.workflow_task_id')
            ->where('activity_attempts.activity_execution_id', $execution->id)
            ->value('workflow_tasks.namespace');
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            $string = $this->stringValue($item);

            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }

        return $default;
    }
}
