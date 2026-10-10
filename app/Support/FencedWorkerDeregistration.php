<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use App\Models\WorkerRegistrationIncarnation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class FencedWorkerDeregistration
{
    public function __construct(private readonly WorkflowTaskLeaseRecovery $recovery) {}

    public static function available(): bool
    {
        // Independent connection handles cannot commit one receipt covering
        // both registration deletion and workflow-task recovery.
        return ((new WorkerRegistration)->getConnectionName() ?? config('database.default'))
            === ((new WorkflowTask)->getConnectionName() ?? config('database.default'));
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function complete(Request $request, string $namespace, string $workerId, string $token): array
    {
        if (! self::available()) {
            return $this->failure('worker_registration_transaction_boundary_unavailable', 412, $workerId, $token);
        }
        $receipt = $this->receipt($namespace, $workerId, $token)->first();

        if (! $receipt instanceof WorkerRegistrationIncarnation) {
            return $this->failure('worker_registration_token_not_found', 404, $workerId, $token);
        }

        // An immutable completed receipt must replay even when a newer
        // registration owns the same worker ID. Never inspect its authority.
        if ($receipt->status === WorkerRegistrationIncarnation::DEREGISTERED) {
            return $this->replay($receipt);
        }

        return DB::transaction(function () use ($request, $namespace, $workerId, $token): array {
            // Registration also locks this row before retiring its prior
            // incarnation. Keep the same lock order on both lifecycle paths.
            $worker = WorkerRegistration::query()
                ->where('namespace', $namespace)
                ->where('worker_id', $workerId)
                ->lockForUpdate()
                ->first();
            $receipt = $this->receipt($namespace, $workerId, $token)->lockForUpdate()->first();

            if (! $receipt instanceof WorkerRegistrationIncarnation) {
                return $this->failure('worker_registration_token_not_found', 404, $workerId, $token);
            }
            if ($receipt->status === WorkerRegistrationIncarnation::DEREGISTERED) {
                return $this->replay($receipt);
            }
            if ($receipt->status !== WorkerRegistrationIncarnation::ACTIVE
                || ! $worker instanceof WorkerRegistration
                || $worker->registration_token !== $token) {
                return $this->failure('worker_registration_lost_authority', 409, $workerId, $token);
            }

            $worker->forceFill(['status' => WorkerRegistration::STATUS_SUPERSEDED])->save();
            $tasks = WorkflowTask::query()
                ->where('namespace', $namespace)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Leased->value)
                ->where('lease_owner', $workerId)
                ->lockForUpdate()
                ->get();
            $recovered = 0;
            foreach ($tasks as $task) {
                if ($this->recovery->recoverAbandonedTaskLease($request, $namespace, $task, strict: true)) {
                    $recovered++;
                }
            }

            WorkerCompatibilityFleet::forgetWorkerForNamespace($namespace, $workerId);
            $worker->delete();
            $receipt->forceFill([
                'status' => WorkerRegistrationIncarnation::DEREGISTERED,
                'recovered_workflow_task_count' => $recovered,
                'finished_at' => now(),
            ])->save();

            return $this->replay($receipt);
        });
    }

    /** @return Builder<WorkerRegistrationIncarnation> */
    private function receipt(string $namespace, string $workerId, string $token): Builder
    {
        return WorkerRegistrationIncarnation::query()
            ->whereKey($token)
            ->where('namespace', $namespace)
            ->where('worker_id', $workerId);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function replay(WorkerRegistrationIncarnation $receipt): array
    {
        if ($receipt->finished_at === null
            || $receipt->finished_at->lte(now()->subSeconds(WorkerRegistrationIncarnation::RECEIPT_RETENTION_SECONDS))) {
            return $this->failure('worker_registration_receipt_expired', 410, $receipt->worker_id, $receipt->token);
        }

        return ['status' => 200, 'body' => [
            'worker_id' => $receipt->worker_id,
            'registration_token' => $receipt->token,
            'outcome' => 'deregistered',
            'recovered_workflow_task_count' => $receipt->recovered_workflow_task_count,
        ]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function failure(string $reason, int $status, string $workerId, string $token): array
    {
        return ['status' => $status, 'body' => [
            'reason' => $reason,
            'worker_id' => $workerId,
            'registration_token' => $token,
            'retryable' => false,
        ]];
    }
}
