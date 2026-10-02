<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneMutationRetrier;
use App\Support\ControlPlaneProtocol;
use App\Support\CooperativeCancellationPolicy;
use App\Support\LegacyV1Projection;
use App\Support\LongPollSignalStore;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerPollFence;
use App\Support\WorkerProtocol;
use App\Support\WorkerProtocolMutationRetrier;
use App\Support\WorkflowCommandContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\CooperativeWorkflowTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class CooperativeCancellationController
{
    public function __construct(
        private readonly ControlPlaneMutationRetrier $controlMutations,
        private readonly WorkerProtocolMutationRetrier $workerMutations,
        private readonly WorkflowCommandContextFactory $contexts,
    ) {}

    public function request(Request $request, string $workflowId, ?string $runId = null): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        if (! CooperativeCancellationPolicy::serverSupported()) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'This Server does not support cooperative cancellation requests.',
                'reason' => 'cooperative_cancellation_not_supported',
                'minimum_protocol_version' => CooperativeCancellationPolicy::MINIMUM_PROTOCOL_VERSION,
            ], 409);
        }

        $namespace = (string) $request->attributes->get('namespace');
        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, ['message' => 'Workflow not found.', 'reason' => 'instance_not_found'], 404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'cleanup_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
        ]);

        $result = $this->controlMutations->run(fn (): array => DB::transaction(function () use (
            $request, $namespace, $workflowId, $runId, $validated,
        ): array {
            $instance = WorkflowInstance::query()->where('namespace', $namespace)
                ->lockForUpdate()->find($workflowId);
            if (! $instance instanceof WorkflowInstance) {
                return ['reason' => 'instance_not_found', 'http_status' => 404];
            }

            $run = NamespaceWorkflowScope::runQuery($namespace, $workflowId)
                ->lockForUpdate()->find($runId ?? $instance->current_run_id);
            if (! $run instanceof WorkflowRun) {
                return ['reason' => 'run_not_found', 'http_status' => 404];
            }
            if ($run->id !== $instance->current_run_id) {
                return ['reason' => 'selected_run_not_current', 'http_status' => 409];
            }
            if (LegacyV1Projection::isProjectedRun($run)) {
                return ['reason' => 'legacy_v1_operation_not_supported', 'http_status' => 409];
            }

            // Duplicates keep the original identity and deadline even after
            // cleanup ended or the worker registration changed.
            if (($pending = CooperativeCancellationPolicy::pending($run)) !== null) {
                return [
                    'workflow_id' => $workflowId,
                    'run_id' => $run->id,
                    'accepted' => true,
                    'duplicate' => true,
                    'run_status' => $run->status->value,
                    'cancellation_request' => $pending,
                    'http_status' => 200,
                ];
            }
            if ($run->status->isTerminal()) {
                return ['reason' => 'run_not_active', 'run_status' => $run->status->value, 'http_status' => 409];
            }

            $claims = NamespaceWorkflowScope::taskQuery($namespace)
                ->where('workflow_tasks.workflow_run_id', $run->id)
                ->where('workflow_tasks.task_type', TaskType::Workflow->value)
                ->where('workflow_tasks.status', TaskStatus::Leased->value)
                ->where('workflow_tasks.lease_expires_at', '>', now())
                ->lockForUpdate()->get();
            foreach ($claims as $claim) {
                if (! CooperativeCancellationPolicy::claimSupportsCancellation($claim)) {
                    return [
                        'reason' => 'active_claim_cancellation_not_supported',
                        'task_id' => $claim->id,
                        'workflow_task_attempt' => $claim->attempt_count,
                        'http_status' => 409,
                    ];
                }
            }

            $command = WorkflowStub::loadSelection($workflowId, $runId, $namespace)
                ->withCommandContext($this->contexts->make($request, $workflowId, 'request_cancellation'))
                ->attemptRequestCancellation(
                    $validated['reason'] ?? null,
                    $validated['cleanup_timeout_seconds'] ?? 600,
                );
            $run->refresh();

            return [
                'workflow_id' => $workflowId,
                'run_id' => $run->id,
                'accepted' => $command->accepted(),
                'duplicate' => false,
                'run_status' => $run->status->value,
                'cancellation_request' => CooperativeCancellationPolicy::pending($run),
                'reason' => $command->rejectionReason(),
                'http_status' => $command->accepted() ? 202 : 409,
            ];
        }), allBackends: true);

        if (($result['accepted'] ?? false) === true) {
            app(LongPollSignalStore::class)->signalWorkflowTaskQueuesForWorkflow($workflowId, $namespace);
        }
        $status = $result['http_status'];
        unset($result['http_status']);
        if (($result['accepted'] ?? false) !== true) {
            $result['message'] = match ($result['reason'] ?? null) {
                'active_claim_cancellation_not_supported' => 'The active workflow-task claim cannot perform cooperative cancellation. Retry after a capable worker claims the task.',
                'selected_run_not_current' => 'The selected run is no longer the current run.',
                'run_not_active' => 'The workflow run is already closed.',
                'legacy_v1_operation_not_supported' => 'Imported legacy workflows do not support cooperative cancellation.',
                'run_not_found' => 'Workflow run not found.',
                default => 'Workflow not found.',
            };
        }

        return ControlPlaneProtocol::jsonForRequest($request, $result, $status);
    }

    public function deliver(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }
        if (! WorkerProtocol::versionMeetsMinimum(
            WorkerProtocol::requestVersion($request), CooperativeCancellationPolicy::MINIMUM_PROTOCOL_VERSION,
        )) {
            return WorkerProtocol::json(['reason' => 'cooperative_cancellation_not_supported'], 409);
        }

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'workflow_task_attempt' => ['required', 'integer', 'min:1'],
            'request_id' => ['required', 'string'],
            'sequence' => ['required', 'integer', 'min:1'],
            'call_kind' => ['required', 'string', 'in:activity,local_activity,timer,condition,signal,child,parallel,selection_handle'],
            'sequence_span' => ['nullable', 'integer', 'min:1'],
            'operation_sequence' => ['nullable', 'integer', 'min:1'],
            'operation_sequence_span' => ['nullable', 'integer', 'min:1'],
        ]);
        $namespace = (string) $request->attributes->get('namespace');
        $bridge = app(WorkflowTaskBridge::class);
        if (! $bridge instanceof CooperativeWorkflowTaskBridge) {
            return WorkerProtocol::json(['reason' => 'cooperative_cancellation_not_supported'], 409);
        }

        $result = $this->workerMutations->run(fn (): array => DB::transaction(function () use (
            $namespace, $taskId, $validated, $bridge,
        ): array {
            // Keep the ownership check and engine delivery under the same
            // task lock. A reclaim cannot replace the checked attempt.
            $task = NamespaceWorkflowScope::taskQuery($namespace)->lockForUpdate()->find($taskId);
            if (! $task instanceof WorkflowTask) {
                return ['delivered' => false, 'reason' => 'task_not_found'];
            }
            if ($task->lease_owner !== $validated['lease_owner']) {
                return ['delivered' => false, 'reason' => 'lease_owner_mismatch'];
            }
            if ($task->attempt_count !== (int) $validated['workflow_task_attempt']) {
                return ['delivered' => false, 'reason' => 'workflow_task_attempt_mismatch'];
            }
            if (! CooperativeCancellationPolicy::claimSupportsCancellation($task)) {
                return ['delivered' => false, 'reason' => 'active_claim_cancellation_not_supported'];
            }

            $worker = WorkerRegistration::query()->where('namespace', $namespace)
                ->where('worker_id', $validated['lease_owner'])->first();
            if (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker)) {
                return ['delivered' => false, 'reason' => 'stale_worker_registration'];
            }

            return $bridge->deliverCancellation(
                $taskId, $validated['request_id'], (int) $validated['sequence'], $validated['call_kind'],
                (int) ($validated['sequence_span'] ?? 1),
                isset($validated['operation_sequence']) ? (int) $validated['operation_sequence'] : null,
                (int) ($validated['operation_sequence_span'] ?? 1),
            );
        }));

        return WorkerProtocol::json($result, (($result['delivered'] ?? false)
            || (($result['claim_released'] ?? null) === true && in_array($result['reason'] ?? null,
                ['cancellation_waiting_for_child', 'cancellation_waiting_for_activity'], true))) ? 200
            : (($result['reason'] ?? null) === 'task_not_found' ? 404 : 409));
    }
}
