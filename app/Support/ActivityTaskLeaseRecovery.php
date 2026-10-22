<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Http\Exceptions\HttpResponseException;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\TaskDispatcher;

final class ActivityTaskLeaseRecovery
{
    public static function supported(): bool
    {
        return method_exists(ActivityTimeoutEnforcer::class, 'revokeWorkerLease');
    }

    public static function sharesRegistrationConnection(): bool
    {
        $connection = (new WorkerRegistration)->getConnectionName() ?? config('database.default');
        foreach ([new WorkflowTask, new WorkflowRun, new ActivityExecution, new ActivityAttempt] as $model) {
            if (($model->getConnectionName() ?? config('database.default')) !== $connection) {
                return false;
            }
        }

        return true;
    }

    /** The caller retains the stopped registration's row/incarnation fence until commit. */
    public function recoverForStoppedWorker(string $namespace, string $workerId): void
    {
        if (! self::supported()) {
            return;
        }
        if (! self::sharesRegistrationConnection()) {
            throw new HttpResponseException(WorkerProtocol::json([
                'reason' => 'activity_lease_recovery_transaction_boundary_unavailable',
                'worker_id' => $workerId,
                'retryable' => false,
            ], 412));
        }

        // Do not lock tasks ahead of attempts/executions. Core rechecks the
        // original task, owner, generation and namespace under canonical locks.
        $taskIds = WorkflowTask::query()->where('namespace', $namespace)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', $workerId)->pluck('id');
        foreach ($taskIds as $taskId) {
            $outcome = ActivityTimeoutEnforcer::revokeWorkerLease($taskId, $namespace, $workerId);
            if ($outcome['next_task'] instanceof WorkflowTask) {
                TaskDispatcher::dispatch($outcome['next_task'], boundedWorkflowTaskProjection: true);
            }
        }
    }
}
