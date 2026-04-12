<?php

namespace App\Support;

use App\Models\WorkflowTaskProtocolLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;

final class WorkflowTaskLeaseRegistry
{
    /**
     * @param  array<string, mixed>  $claim
     * @param  int|null  $packageAttemptCount  When provided, the attempt counter is
     *     sourced from the package's WorkflowTask.attempt_count rather than being
     *     computed independently from the mirror table. This keeps the fencing token
     *     aligned with the package's authoritative counter.
     */
    public function recordClaim(string $namespace, array $claim, ?string $pollRequestId = null, ?int $packageAttemptCount = null): WorkflowTaskProtocolLease
    {
        $taskId = (string) ($claim['task_id'] ?? '');
        $workflowInstanceId = $this->nullableString($claim['workflow_instance_id'] ?? null);
        $workflowRunId = $this->nullableString($claim['workflow_run_id'] ?? null);
        $leaseOwner = $this->nullableString($claim['lease_owner'] ?? null);
        $leaseExpiresAt = $this->timestamp($claim['lease_expires_at'] ?? null);
        $pollRequestId = $this->nullableString($pollRequestId);

        return DB::transaction(function () use (
            $leaseExpiresAt,
            $leaseOwner,
            $namespace,
            $packageAttemptCount,
            $pollRequestId,
            $taskId,
            $workflowInstanceId,
            $workflowRunId,
        ): WorkflowTaskProtocolLease {
            /** @var WorkflowTaskProtocolLease $lease */
            $lease = WorkflowTaskProtocolLease::query()
                ->lockForUpdate()
                ->find($taskId)
                ?? new WorkflowTaskProtocolLease(['task_id' => $taskId]);

            $attempt = is_int($packageAttemptCount) && $packageAttemptCount > 0
                ? $packageAttemptCount
                : $this->nextWorkflowTaskAttempt(
                    $lease,
                    $namespace,
                    $workflowInstanceId,
                    $workflowRunId,
                    $leaseOwner,
                    $leaseExpiresAt,
                );

            $lease->fill([
                'namespace' => $namespace,
                'workflow_instance_id' => $workflowInstanceId,
                'workflow_run_id' => $workflowRunId,
                'workflow_task_attempt' => $attempt,
                'lease_owner' => $leaseOwner,
                'lease_expires_at' => $leaseExpiresAt,
                'last_claimed_at' => now(),
                'last_poll_request_id' => $pollRequestId,
            ]);
            $lease->save();

            return $lease->fresh() ?? $lease;
        });
    }

    public function activeLease(string $namespace, string $taskId): ?WorkflowTaskProtocolLease
    {
        /** @var WorkflowTaskProtocolLease|null $lease */
        $lease = WorkflowTaskProtocolLease::query()->find($taskId);

        if (! $lease instanceof WorkflowTaskProtocolLease) {
            return null;
        }

        return $lease->namespace === $namespace
            ? $lease
            : null;
    }

    public function ownershipLease(
        string $namespace,
        string $taskId,
        string $expectedLeaseOwner,
        int $workflowTaskAttempt,
    ): ?WorkflowTaskProtocolLease {
        return DB::transaction(function () use (
            $expectedLeaseOwner,
            $namespace,
            $taskId,
            $workflowTaskAttempt,
        ): ?WorkflowTaskProtocolLease {
            /** @var WorkflowTaskProtocolLease|null $lease */
            $lease = WorkflowTaskProtocolLease::query()
                ->lockForUpdate()
                ->find($taskId);

            if ($lease instanceof WorkflowTaskProtocolLease && $lease->namespace !== $namespace) {
                $lease = null;
            }

            /** @var WorkflowTask|null $task */
            $task = NamespaceWorkflowScope::taskQuery($namespace)
                ->where('workflow_tasks.id', $taskId)
                ->lockForUpdate()
                ->first();

            if (! $task instanceof WorkflowTask || $task->task_type !== TaskType::Workflow) {
                return $lease;
            }

            if ($task->status !== TaskStatus::Leased) {
                if ($lease instanceof WorkflowTaskProtocolLease) {
                    $this->clearLeaseModel($lease);
                }

                return $lease?->fresh() ?? $lease;
            }

            $taskLeaseOwner = $this->nullableString($task->lease_owner);
            $taskLeaseExpiresAt = $task->lease_expires_at;

            if ($taskLeaseOwner === null || $taskLeaseExpiresAt === null) {
                if ($lease instanceof WorkflowTaskProtocolLease) {
                    $this->clearLeaseModel($lease);
                }

                return $lease?->fresh() ?? $lease;
            }

            // Prefer the package's tables as the authoritative source for
            // workflow_instance_id. The mirror's cached value is used only as
            // a fallback for edge cases where the join path is unavailable.
            $workflowInstanceId = $this->nullableString(
                    WorkflowTask::query()
                        ->whereKey($taskId)
                        ->join('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
                        ->value('workflow_runs.workflow_instance_id'),
                )
                ?? $this->nullableString($lease?->workflow_instance_id);

            if ($taskLeaseOwner !== $expectedLeaseOwner) {
                if (
                    $lease instanceof WorkflowTaskProtocolLease
                    && $lease->hasActiveLease()
                    && $lease->lease_owner === $taskLeaseOwner
                    && $this->sameTimestamp($lease->lease_expires_at, $taskLeaseExpiresAt)
                ) {
                    return $lease;
                }

                return $this->syntheticLease(
                    taskId: $taskId,
                    namespace: $namespace,
                    workflowInstanceId: $workflowInstanceId,
                    workflowRunId: $task->workflow_run_id,
                    workflowTaskAttempt: $workflowTaskAttempt,
                    leaseOwner: $taskLeaseOwner,
                    leaseExpiresAt: $taskLeaseExpiresAt,
                );
            }

            $lease ??= new WorkflowTaskProtocolLease(['task_id' => $taskId]);

            // Incorporate the package's authoritative attempt counter when
            // reconciling. This keeps the mirror table aligned even when its
            // own value is stale or the row was missing.
            $packageAttemptCount = is_int($task->attempt_count)
                ? (int) $task->attempt_count
                : null;

            $attempt = $this->recoveredWorkflowTaskAttempt(
                lease: $lease,
                expectedLeaseOwner: $expectedLeaseOwner,
                expectedWorkflowTaskAttempt: $workflowTaskAttempt,
                packageAttemptCount: $packageAttemptCount,
            );

            $preservePollRequestId = $lease->exists
                && $lease->hasActiveLease()
                && $lease->lease_owner === $taskLeaseOwner;

            $lease->fill([
                'namespace' => $namespace,
                'workflow_instance_id' => $workflowInstanceId,
                'workflow_run_id' => $task->workflow_run_id,
                'workflow_task_attempt' => $attempt,
                'lease_owner' => $taskLeaseOwner,
                'lease_expires_at' => $taskLeaseExpiresAt,
                'last_claimed_at' => $task->leased_at ?? $lease->last_claimed_at ?? now(),
                'last_poll_request_id' => $preservePollRequestId
                    ? $lease->last_poll_request_id
                    : null,
            ]);
            $lease->save();

            return $lease->fresh() ?? $lease;
        });
    }

    public function activeLeaseForPollRequest(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): ?WorkflowTaskProtocolLease {
        /** @var WorkflowTaskProtocolLease|null $lease */
        $lease = WorkflowTaskProtocolLease::query()
            ->select('workflow_task_protocol_leases.*')
            ->join('workflow_tasks', 'workflow_tasks.id', '=', 'workflow_task_protocol_leases.task_id')
            ->where('workflow_task_protocol_leases.namespace', $namespace)
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->where('workflow_tasks.lease_owner', $leaseOwner)
            ->where('workflow_task_protocol_leases.last_poll_request_id', $pollRequestId)
            ->when($buildId === null, static function ($query): void {
                $query->where(function ($builder): void {
                    $builder->whereNull('workflow_tasks.compatibility')
                        ->orWhere('workflow_tasks.compatibility', '');
                });
            }, static function ($query) use ($buildId): void {
                $query->where('workflow_tasks.compatibility', $buildId);
            })
            ->orderByDesc('workflow_task_protocol_leases.last_claimed_at')
            ->first();

        if (! $lease instanceof WorkflowTaskProtocolLease) {
            return null;
        }

        if (! $this->packageLeaseStillActive($lease->task_id)) {
            return null;
        }

        return $lease;
    }

    public function renewLease(
        string $namespace,
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        mixed $leaseExpiresAt,
    ): void {
        $lease = $this->activeLease($namespace, $taskId);

        if (! $lease instanceof WorkflowTaskProtocolLease) {
            return;
        }

        if ((int) $lease->workflow_task_attempt !== $workflowTaskAttempt || (string) $lease->lease_owner !== $leaseOwner) {
            return;
        }

        $lease->forceFill([
            'lease_expires_at' => $this->timestamp($leaseExpiresAt),
        ])->save();
    }

    public function clearActiveLease(string $taskId): void
    {
        /** @var WorkflowTaskProtocolLease|null $lease */
        $lease = WorkflowTaskProtocolLease::query()->find($taskId);

        if (! $lease instanceof WorkflowTaskProtocolLease) {
            return;
        }

        $this->clearLeaseModel($lease);
    }

    public function syncTaskState(WorkflowTask $task): void
    {
        if ($task->task_type !== TaskType::Workflow) {
            return;
        }

        if ($task->status === TaskStatus::Leased) {
            // The mirror's lease_owner and lease_expires_at are set at claim
            // time by recordClaim() and kept current by renewLease(). The
            // package's WorkflowTask is the authoritative source for these
            // fields; callers (ownershipLease, activeLeaseForPollRequest) now
            // verify against the package directly, so per-update sync is no
            // longer needed for correctness.
            return;
        }

        $this->clearActiveLease($task->id);
    }

    private function nextWorkflowTaskAttempt(
        WorkflowTaskProtocolLease $lease,
        string $namespace,
        ?string $workflowInstanceId,
        ?string $workflowRunId,
        ?string $leaseOwner,
        ?Carbon $leaseExpiresAt,
    ): int {
        $currentAttempt = max(0, (int) $lease->workflow_task_attempt);

        if ($this->sameActiveClaim(
            $lease,
            $namespace,
            $workflowInstanceId,
            $workflowRunId,
            $leaseOwner,
            $leaseExpiresAt,
        )) {
            return max(1, $currentAttempt);
        }

        return $currentAttempt + 1;
    }

    private function recoveredWorkflowTaskAttempt(
        WorkflowTaskProtocolLease $lease,
        string $expectedLeaseOwner,
        int $expectedWorkflowTaskAttempt,
        ?int $packageAttemptCount = null,
    ): int {
        $currentAttempt = max(1, (int) $lease->workflow_task_attempt);

        // When the package's authoritative counter is available and higher
        // than the mirror table's value, use it as the floor. This corrects
        // stale or missing mirror rows without lowering the fencing bar.
        $packageFloor = is_int($packageAttemptCount) && $packageAttemptCount > 0
            ? $packageAttemptCount
            : 0;

        if (
            $lease->exists
            && $lease->hasActiveLease()
            && $lease->lease_owner === $expectedLeaseOwner
        ) {
            return max($currentAttempt, $packageFloor);
        }

        return max(1, $expectedWorkflowTaskAttempt, $currentAttempt, $packageFloor);
    }

    private function sameActiveClaim(
        WorkflowTaskProtocolLease $lease,
        string $namespace,
        ?string $workflowInstanceId,
        ?string $workflowRunId,
        ?string $leaseOwner,
        ?Carbon $leaseExpiresAt,
    ): bool {
        if (! $lease->exists || ! $lease->hasActiveLease() || ! $this->leaseStillActive($lease->lease_expires_at)) {
            return false;
        }

        if ($leaseExpiresAt === null || ! $leaseExpiresAt->gt(now())) {
            return false;
        }

        return $lease->namespace === $namespace
            && $lease->workflow_instance_id === $workflowInstanceId
            && $lease->workflow_run_id === $workflowRunId
            && $lease->lease_owner === $leaseOwner;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function leaseStillActive(?Carbon $leaseExpiresAt): bool
    {
        return $leaseExpiresAt instanceof Carbon
            && $leaseExpiresAt->gt(now());
    }

    private function packageLeaseStillActive(string $taskId): bool
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()->find($taskId);

        return $task instanceof WorkflowTask
            && $task->status === TaskStatus::Leased
            && $task->lease_expires_at instanceof Carbon
            && $task->lease_expires_at->gt(now());
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function clearLeaseModel(WorkflowTaskProtocolLease $lease): void
    {
        $lease->forceFill([
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_poll_request_id' => null,
        ])->save();
    }

    private function syntheticLease(
        string $taskId,
        string $namespace,
        ?string $workflowInstanceId,
        ?string $workflowRunId,
        int $workflowTaskAttempt,
        string $leaseOwner,
        Carbon $leaseExpiresAt,
    ): WorkflowTaskProtocolLease {
        return new WorkflowTaskProtocolLease([
            'task_id' => $taskId,
            'namespace' => $namespace,
            'workflow_instance_id' => $workflowInstanceId,
            'workflow_run_id' => $workflowRunId,
            'workflow_task_attempt' => max(1, $workflowTaskAttempt),
            'lease_owner' => $leaseOwner,
            'lease_expires_at' => $leaseExpiresAt,
        ]);
    }

    private function sameTimestamp(?Carbon $left, ?Carbon $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return $left->equalTo($right);
    }
}
