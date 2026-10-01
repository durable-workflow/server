<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ChildCancellation;
use Workflow\V2\WorkflowStub;

final class CooperativeCancellationPolicy
{
    public const CAPABILITY = 'cooperative_cancellation';

    public const MINIMUM_PROTOCOL_VERSION = '1.20';

    private const CLAIM_KEY = '_server_workflow_claim';

    public static function serverSupported(): bool
    {
        return WorkerProtocol::versionMeetsMinimum(
            (string) config('server.worker_protocol.version', WorkerProtocol::VERSION),
            self::MINIMUM_PROTOCOL_VERSION,
        );
    }

    public static function childPolicyBackendSupported(): bool
    {
        return enum_exists(CancellationPolicy::class)
            && defined(ParentClosePolicy::class.'::RequestCancellation')
            && class_exists(ChildCancellation::class)
            && method_exists(WorkflowStub::class, 'attemptRequestCancellationFromParent');
    }

    /** @return list<string> */
    public static function childPolicyUnavailableReasons(?WorkflowTask $task, ?string $requestVersion): array
    {
        $reasons = [];
        if (! self::serverSupported()) {
            $reasons[] = 'server_protocol';
        }
        if (! WorkerProtocol::versionMeetsMinimum($requestVersion, self::MINIMUM_PROTOCOL_VERSION)) {
            $reasons[] = 'request_protocol';
        }
        if ($task === null || ! self::claimSupportsCancellation($task)) {
            $reasons[] = 'worker_claim_capability';
        }
        if (! self::childPolicyBackendSupported()) {
            $reasons[] = 'installed_runtime_child_policy';
        }

        return $reasons;
    }

    /** @return array<string, mixed> */
    public static function workerSnapshot(WorkerRegistration $worker, ?string $protocolVersion): array
    {
        return [
            ...WorkerPollFence::snapshot($worker),
            'registration_id' => $worker->getKey(),
            'protocol_version' => $protocolVersion,
            'capabilities' => is_array($worker->capabilities) ? $worker->capabilities : [],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function bindClaim(WorkflowTask $task, array $snapshot): void
    {
        $payload = is_array($task->payload) ? $task->payload : [];
        $payload[self::CLAIM_KEY] = [
            ...$snapshot,
            'task_id' => $task->id,
            'run_id' => $task->workflow_run_id,
            'lease_owner' => $task->lease_owner,
            'attempt' => $task->attempt_count,
        ];
        $task->forceFill(['payload' => $payload])->save();
    }

    public static function claimSupportsCancellation(WorkflowTask $task): bool
    {
        $claim = $task->payload[self::CLAIM_KEY] ?? null;

        return is_array($claim)
            && ($claim['task_id'] ?? null) === $task->id
            && ($claim['run_id'] ?? null) === $task->workflow_run_id
            && ($claim['namespace'] ?? null) === $task->namespace
            && ($claim['worker_id'] ?? null) === $task->lease_owner
            && ($claim['lease_owner'] ?? null) === $task->lease_owner
            && ($claim['attempt'] ?? null) === $task->attempt_count
            && self::supports($claim['capabilities'] ?? [], $claim['protocol_version'] ?? null);
    }

    /** @param list<string> $capabilities */
    public static function supports(array $capabilities, ?string $protocolVersion): bool
    {
        return in_array(self::CAPABILITY, $capabilities, true)
            && WorkerProtocol::versionMeetsMinimum($protocolVersion, self::MINIMUM_PROTOCOL_VERSION);
    }

    /**
     * Keep an undelivered request runnable when a worker commits the commands
     * preceding its cancellation boundary. Call inside the fenced completion
     * transaction so the prefix and its successor commit together.
     */
    public static function resumeUndeliveredRequest(WorkflowTask $completedTask): ?WorkflowTask
    {
        if ($completedTask->status !== TaskStatus::Completed
            || ! self::claimSupportsCancellation($completedTask)) {
            return null;
        }

        $run = WorkflowRun::query()->where('namespace', $completedTask->namespace)
            ->lockForUpdate()->find($completedTask->workflow_run_id);
        if (! $run instanceof WorkflowRun
            || $run->status->isTerminal()
            || ! is_string($run->cancellation_request_command_id)
            || $run->cancellation_delivery_sequence !== null
            || $run->cancellation_deadline_at === null
            || ! $run->cancellation_deadline_at->isFuture()) {
            return null;
        }

        if (WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])->exists()) {
            return null;
        }

        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => [
                'resume_source_kind' => 'cancellation_request',
                'resume_source_id' => $run->cancellation_request_command_id,
                'workflow_command_id' => $run->cancellation_request_command_id,
            ],
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function pending(WorkflowRun $run): ?array
    {
        if (! is_string($run->cancellation_request_command_id)) {
            return null;
        }

        return [
            'request_id' => $run->cancellation_request_command_id,
            'requested_at' => $run->cancellation_requested_at?->toISOString(),
            'cleanup_deadline_at' => $run->cancellation_deadline_at?->toISOString(),
            'delivery_sequence' => $run->cancellation_delivery_sequence,
            'delivered_at' => $run->cancellation_delivered_at?->toISOString(),
            'history_refresh_page_token' => WorkflowHistoryPageToken::encode(0),
        ];
    }
}
