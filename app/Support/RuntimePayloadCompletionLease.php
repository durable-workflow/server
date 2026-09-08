<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Carbon\CarbonImmutable;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Support\WorkflowTaskOwnership;

final class RuntimePayloadCompletionLease
{
    public function __construct(
        private readonly ActivityTaskBridge $activities,
        private readonly WorkflowTaskOwnership $workflows,
        private readonly WorkflowQueryTaskBroker $queries,
    ) {}

    public function expiresAt(string $namespace, RuntimePayloadCompletionContext $context): CarbonImmutable
    {
        $worker = WorkerRegistration::query()->where('namespace', $namespace)
            ->where('worker_id', $context->leaseOwner)->first();
        if (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker)) {
            throw self::rejected();
        }

        $expires = match ($context->kind) {
            'activity' => $this->activity($namespace, $context),
            'workflow' => $this->workflow($namespace, $context),
            'query' => $this->query($namespace, $context),
        };
        if (! is_string($expires) || $expires === '') {
            throw self::rejected();
        }
        try {
            $expiresAt = CarbonImmutable::parse($expires);
        } catch (\Exception) {
            throw self::rejected();
        }
        if ($expiresAt->lte(now())) {
            throw self::rejected();
        }

        return $expiresAt;
    }

    public function mayResume(string $namespace, RuntimePayloadCompletionContext $context): bool
    {
        // An expired activity can still be renewed before lease recovery. Keep its
        // budget until its identity is replaced or closed, not merely until TTL.
        if ($context->kind === 'query') {
            $task = $this->queries->task($context->taskId);

            return ($task['namespace'] ?? null) === $namespace && ($task['status'] ?? null) === 'leased'
                && ($task['lease_owner'] ?? null) === $context->leaseOwner
                && ($task['attempt_count'] ?? null) === $context->attempt;
        }
        $task = NamespaceWorkflowScope::task($namespace, $context->taskId);
        if ($task === null || $task->status->value !== 'leased' || $task->lease_owner !== $context->leaseOwner) {
            return false;
        }
        if ($context->kind === 'workflow') {
            return $task->attempt_count === $context->attempt;
        }
        $status = $this->activities->status($context->attempt);

        return ($status['can_continue'] ?? false) === true
            && ($status['workflow_task_id'] ?? null) === $context->taskId
            && ($status['lease_owner'] ?? null) === $context->leaseOwner;
    }

    private function activity(string $namespace, RuntimePayloadCompletionContext $context): ?string
    {
        $task = NamespaceWorkflowScope::task($namespace, $context->taskId);
        if ($task === null || $task->task_type !== TaskType::Activity || $task->lease_owner !== $context->leaseOwner
            || $task->lease_expires_at === null || $task->lease_expires_at->lte(now())) {
            throw self::rejected();
        }
        // The bridge checks the current attempt, task and run without renewing the lease.
        $status = $this->activities->status($context->attempt);
        if (($status['can_continue'] ?? false) !== true
            || ($status['workflow_task_id'] ?? null) !== $context->taskId
            || ($status['lease_owner'] ?? null) !== $context->leaseOwner) {
            throw self::rejected();
        }

        return $status['lease_expires_at'] ?? null;
    }

    private function workflow(string $namespace, RuntimePayloadCompletionContext $context): ?string
    {
        $guard = $this->workflows->guard(
            fn (string $ns, string $id) => NamespaceWorkflowScope::task($ns, $id),
            $namespace, $context->taskId, $context->attempt, $context->leaseOwner,
        );
        if (! $guard['valid'] || $guard['task']?->task_type !== TaskType::Workflow || in_array($guard['status']['run_status'] ?? null,
            ['completed', 'failed', 'cancelled', 'terminated'], true)) {
            throw self::rejected();
        }

        return $guard['status']['lease_expires_at'] ?? null;
    }

    private function query(string $namespace, RuntimePayloadCompletionContext $context): ?string
    {
        if ($this->queries->guardCompletion($namespace, $context->taskId,
            $context->leaseOwner, $context->attempt) !== null) {
            throw self::rejected();
        }

        return $this->queries->task($context->taskId)['lease_expires_at'] ?? null;
    }

    public static function rejected(): RuntimeExternalPayloadException
    {
        return new RuntimeExternalPayloadException('external_payload_completion_lease_rejected', 409, false,
            'The payload upload does not belong to a current worker completion lease.');
    }
}
