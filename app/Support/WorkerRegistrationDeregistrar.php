<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class WorkerRegistrationDeregistrar
{
    public function __construct(
        private readonly WorkflowTaskLeaseRecovery $workflowTaskLeaseRecovery,
    ) {}

    public function deregister(Request $request, string $namespace, string $workerId): ?int
    {
        /** @var Collection<int, WorkflowTask>|null $leasedWorkflowTasks */
        $leasedWorkflowTasks = DB::transaction(function () use ($namespace, $workerId): ?Collection {
            $worker = WorkerRegistration::query()
                ->where('worker_id', $workerId)
                ->where('namespace', $namespace)
                ->lockForUpdate()
                ->first();

            if (! $worker instanceof WorkerRegistration) {
                return null;
            }

            // Deregistration is the orderly process hand-off boundary. Fence
            // the registration before recovering its leases so any long poll
            // left behind by the stopped process cannot claim new work while
            // the replacement is starting.
            $worker
                ->forceFill(['status' => WorkerRegistration::STATUS_SUPERSEDED])
                ->save();

            return WorkflowTask::query()
                ->where('namespace', $namespace)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Leased->value)
                ->where('lease_owner', $workerId)
                ->lockForUpdate()
                ->get();
        });

        if (! $leasedWorkflowTasks instanceof Collection) {
            return null;
        }

        $recoveredWorkflowTaskCount = $leasedWorkflowTasks
            ->filter(fn (WorkflowTask $task): bool => $this->workflowTaskLeaseRecovery
                ->recoverAbandonedTaskLease($request, $namespace, $task))
            ->count();

        WorkerRegistration::query()
            ->where('worker_id', $workerId)
            ->where('namespace', $namespace)
            ->delete();
        WorkerCompatibilityFleet::forgetWorkerForNamespace($namespace, $workerId);

        return $recoveredWorkflowTaskCount;
    }
}
