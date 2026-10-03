<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\CooperativeCancellationPolicy;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerPollFence;
use App\Support\WorkerProtocol;
use App\Support\WorkerProtocolMutationRetrier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\CancellationScopeTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** Internal scope admission for the unfrozen cooperative protocol candidate. */
final class CancellationScopeController
{
    public function __construct(private readonly WorkerProtocolMutationRetrier $mutations) {}

    public function open(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $version = WorkerProtocol::requestVersion($request);
        if (! WorkerProtocol::versionMeetsMinimum($version, CooperativeCancellationPolicy::MINIMUM_PROTOCOL_VERSION)) {
            return WorkerProtocol::json(['opened' => false, 'reason' => 'cancellation_scope_requires_protocol_1_20'], 409);
        }
        $bridge = app(WorkflowTaskBridge::class);
        if (! $bridge instanceof CancellationScopeTaskBridge) {
            return WorkerProtocol::json(['opened' => false, 'reason' => 'cancellation_scope_opening_unavailable',
                'unavailable' => ['installed_runtime_scope_opening']], 409);
        }

        $validated = $request->validate([
            'lease_owner' => ['required', 'string', 'max:255'],
            'workflow_task_attempt' => ['required', 'integer', 'min:1'],
            'sequence' => ['required', 'integer', 'min:1'],
            'parent_scope_id' => ['sometimes', 'required', 'string', 'max:255'],
            'shield_parent' => ['sometimes', 'required', 'boolean'],
        ]);
        $namespace = (string) $request->attributes->get('namespace');
        $result = $this->mutations->run(fn (): array => DB::transaction(function () use (
            $namespace, $taskId, $validated, $version, $bridge,
        ): array {
            $refused = static fn (string $reason): array => ['opened' => false, 'reason' => $reason];
            $snapshot = NamespaceWorkflowScope::taskQuery($namespace)->find($taskId);
            if (! $snapshot instanceof WorkflowTask) {
                return $refused('task_not_found');
            }

            // Native scope history locks the run before its task. Keep that
            // order while checking the namespace and immutable issued claim.
            $run = WorkflowRun::query()->where('namespace', $namespace)
                ->lockForUpdate()->find($snapshot->workflow_run_id);
            if ($run === null || ! NamespaceWorkflowScope::workflowBound($namespace, $run->workflow_instance_id)) {
                return $refused('task_not_found');
            }
            $task = NamespaceWorkflowScope::taskQuery($namespace)->lockForUpdate()->find($taskId);
            if (! $task instanceof WorkflowTask || $task->workflow_run_id !== $run->id) {
                return $refused('task_not_found');
            }
            if ($task->lease_owner !== $validated['lease_owner']) {
                return $refused('lease_owner_mismatch');
            }
            if ($task->attempt_count !== (int) $validated['workflow_task_attempt']) {
                return $refused('workflow_task_attempt_mismatch');
            }
            if (! CooperativeCancellationPolicy::claimSupportsCancellation($task)) {
                return $refused('active_claim_cancellation_not_supported');
            }
            $worker = WorkerRegistration::query()->where('namespace', $namespace)
                ->where('worker_id', $validated['lease_owner'])->lockForUpdate()->first();
            if (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker)) {
                return $refused('stale_worker_registration');
            }

            return $bridge->openCancellationScope(
                $taskId, $validated['lease_owner'], (int) $validated['workflow_task_attempt'],
                (int) $validated['sequence'], $validated['parent_scope_id'] ?? 'root',
                (bool) ($validated['shield_parent'] ?? false), $version,
            );
        }));

        return WorkerProtocol::json($result, ($result['opened'] ?? false) ? 200
            : (($result['reason'] ?? null) === 'task_not_found' ? 404 : 409));
    }
}
