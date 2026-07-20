<?php

namespace App\Support;

use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;

final class PollRequestLeaseBinding
{
    /**
     * The workflow task payload is already the durable, task-scoped record.
     * Keeping the binding there makes it atomic with the lease claim and adds
     * no independently growing mirror collection.
     */
    private const PAYLOAD_KEY = '_server_poll_request_id';

    public function activeTask(
        string $namespace,
        TaskType $taskType,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        string $pollRequestId,
    ): ?WorkflowTask {
        /** @var WorkflowTask|null $task */
        $task = NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', $taskType->value)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->where('workflow_tasks.lease_owner', $leaseOwner)
            ->where('workflow_tasks.payload->'.self::PAYLOAD_KEY, $pollRequestId)
            ->whereNotNull('workflow_tasks.lease_expires_at')
            ->where('workflow_tasks.lease_expires_at', '>', now())
            ->where(static function ($compatibility) use ($buildId): void {
                $compatibility
                    ->whereNull('workflow_tasks.compatibility')
                    ->orWhere('workflow_tasks.compatibility', '');

                if ($buildId !== null) {
                    $compatibility->orWhere('workflow_tasks.compatibility', $buildId);
                }
            })
            ->orderByDesc('workflow_tasks.leased_at')
            ->orderBy('workflow_tasks.id')
            ->first();

        return $task;
    }

    public function ensureUnbound(
        string $namespace,
        TaskType $taskType,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?string $pollRequestId,
    ): void {
        if (
            $pollRequestId !== null
            && $this->activeTask(
                $namespace,
                $taskType,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $pollRequestId,
            ) instanceof WorkflowTask
        ) {
            throw new PollRequestAlreadyBound;
        }
    }

    public function bindClaimedTask(
        string $namespace,
        string $taskId,
        string $leaseOwner,
        ?string $pollRequestId,
    ): void {
        /** @var WorkflowTask|null $task */
        $task = NamespaceWorkflowScope::taskQuery($namespace)
            ->whereKey($taskId)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->where('workflow_tasks.lease_owner', $leaseOwner)
            ->lockForUpdate()
            ->first();

        if (! $task instanceof WorkflowTask) {
            return;
        }

        $payload = is_array($task->payload) ? $task->payload : [];

        if ($pollRequestId === null) {
            unset($payload[self::PAYLOAD_KEY]);
        } else {
            $payload[self::PAYLOAD_KEY] = $pollRequestId;
        }

        $task->forceFill([
            'payload' => $payload === [] ? null : $payload,
        ])->save();
    }
}
