<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Carbon\CarbonImmutable;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowRun;
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
        // Activities heartbeat their own leases while application code runs;
        // their worker roster can age or drain without revoking that authority.
        if ($context->kind !== 'activity') {
            $worker = WorkerRegistration::query()->where('namespace', $namespace)
                ->where('worker_id', $context->leaseOwner)->first();
            if (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker)) {
                throw self::rejected();
            }
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

        if ($context->schema === RuntimePayloadCompletionContext::PREPARED_SCHEMA) {
            $task = $guard['task'];
            $claim = PreparedLocalActivityPolicy::currentClaim($task);
            if (! PreparedLocalActivityPolicy::serverSupported() || $claim === null || ! WorkerPollFence::isCurrent($claim)) {
                throw self::rejected();
            }
            $expires = [$guard['status']['lease_expires_at'] ?? null];
            $run = WorkflowRun::query()->find($task->workflow_run_id);
            if (! $run instanceof WorkflowRun) {
                throw self::rejected();
            }
            if ($run->cancellation_request_command_id !== null) {
                if ($run->cancellation_deadline_at === null) {
                    throw self::rejected();
                }
                $expires[] = $run->cancellation_deadline_at->toISOString();
            }
            if ($context->operation === 'local_activity_outcome') {
                $attempt = ActivityAttempt::query()->where('workflow_task_id', $task->id)->find($context->activityAttemptId);
                if (! $attempt instanceof ActivityAttempt
                    || PreparedLocalActivityPolicy::originalClaim($task, $attempt, $context->leaseOwner, $context->attempt) !== $claim) {
                    throw self::rejected();
                }
                /** @var PreparedLocalActivityTaskBridge $bridge */
                $bridge = app(WorkflowTaskBridge::class);
                $control = $bridge->controlLocalActivity($attempt->id, $context->leaseOwner, $context->attempt, false,
                    PreparedLocalActivityPolicy::MINIMUM_PROTOCOL_VERSION);
                if (($control['active'] ?? null) !== true
                    || ($control['activity_attempt_id'] ?? null) !== $attempt->id
                    || ($control['workflow_task_id'] ?? null) !== $task->id
                    || ($control['workflow_task_attempt'] ?? null) !== $context->attempt
                    || ($control['lease_owner'] ?? null) !== $context->leaseOwner
                    || ($control['renewed'] ?? null) !== false) {
                    throw self::rejected();
                }
                foreach (['lease_expires_at', 'workflow_lease_expires_at', 'start_to_close_deadline_at',
                    'schedule_to_close_deadline_at', 'heartbeat_deadline_at'] as $field) {
                    if (! array_key_exists($field, $control)
                        || (str_contains($field, 'lease_expires_at') && (! is_string($control[$field]) || $control[$field] === ''))) {
                        throw self::rejected();
                    }
                    if (($control[$field] ?? null) !== null) {
                        $expires[] = $control[$field];
                    }
                }
            }
            $earliest = null;
            foreach ($expires as $expiry) {
                if (! is_string($expiry) || $expiry === '') {
                    throw self::rejected();
                }
                try {
                    $deadline = CarbonImmutable::parse($expiry);
                } catch (\Exception) {
                    throw self::rejected();
                }
                $earliest = $earliest === null || $deadline->lt($earliest) ? $deadline : $earliest;
            }

            return $earliest?->toISOString();
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
