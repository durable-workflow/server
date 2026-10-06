<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\CooperativeCancellationPolicy;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerPollFence;
use App\Support\WorkerProtocol;
use App\Support\WorkerProtocolMutationRetrier;
use App\Support\WorkflowHistoryPageToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\CancellationScopeTaskBridge;
use Workflow\V2\Contracts\PreparedCancellationScopeTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** Internal scope admission for the unfrozen cooperative protocol candidate. */
final class CancellationScopeController
{
    public function __construct(private readonly WorkerProtocolMutationRetrier $mutations) {}

    public function prepare(Request $request, string $taskId): JsonResponse
    {
        return $this->delivery($request, $taskId, true);
    }

    public function deliver(Request $request, string $taskId): JsonResponse
    {
        return $this->delivery($request, $taskId, false);
    }

    private function delivery(Request $request, string $taskId, bool $preparing): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }
        $version = WorkerProtocol::requestVersion($request);
        $refused = static fn (string $reason): array => ['prepared' => false, 'delivered' => false,
            'claim_released' => false, 'task_id' => $taskId, 'created_task_ids' => [], 'reason' => $reason];
        if (! WorkerProtocol::versionMeetsMinimum($version, CooperativeCancellationPolicy::MINIMUM_PROTOCOL_VERSION)) {
            return WorkerProtocol::json($refused('cancellation_scope_requires_protocol_1_20'), 409);
        }
        $bridge = app(WorkflowTaskBridge::class);
        if (! $bridge instanceof PreparedCancellationScopeTaskBridge) {
            return WorkerProtocol::json($refused('cancellation_scope_delivery_unavailable')
                + ['unavailable' => ['installed_runtime_scope_preparation_delivery']], 409);
        }
        $validated = $request->validate([
            'lease_owner' => ['required', 'string', 'max:255'],
            'workflow_task_attempt' => ['required', 'integer', 'min:1'],
            'scope_id' => ['required', 'string', 'max:255'],
            'request_id' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:1'],
            'call_kind' => ['required', 'in:activity,local_activity,timer,condition,signal,child,parallel,selection_handle'],
            'sequence_span' => ['sometimes', 'required', 'integer', 'min:1'],
            'operation_sequence' => ['nullable', 'integer', 'min:1'],
            'operation_sequence_span' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);
        $namespace = (string) $request->attributes->get('namespace');
        // Preparation owns its transaction and commits before Activity locks.
        // Check tenant/issued capability/registration without an outer lock;
        // Native rechecks the live owner and attempt under its run/task locks.
        $result = $this->mutations->run(function () use ($namespace, $taskId, $validated, $version, $bridge, $preparing, $refused): array {
            $task = NamespaceWorkflowScope::taskQuery($namespace)->find($taskId);
            if (! $task instanceof WorkflowTask) {
                return $refused('task_not_found');
            }
            $run = WorkflowRun::query()->where('namespace', $namespace)->find($task->workflow_run_id);
            if ($run === null || ! NamespaceWorkflowScope::workflowBound($namespace, $run->workflow_instance_id)) {
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
                ->where('worker_id', $validated['lease_owner'])->first();
            if (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker)) {
                return $refused('stale_worker_registration');
            }
            $method = $preparing ? 'prepareCancellationScopeDelivery' : 'deliverCancellationScope';

            return $bridge->$method($taskId, $validated['lease_owner'], (int) $validated['workflow_task_attempt'],
                $validated['scope_id'], $validated['request_id'], (int) $validated['sequence'], $validated['call_kind'],
                (int) ($validated['sequence_span'] ?? 1), isset($validated['operation_sequence']) ? (int) $validated['operation_sequence'] : null,
                (int) ($validated['operation_sequence_span'] ?? 1), $version);
        });
        if (($result['prepared'] ?? false) === true) {
            $result['lease_owner'] = $validated['lease_owner'];
            $result['workflow_task_attempt'] = (int) $validated['workflow_task_attempt'];
            $result['history_refresh_page_token'] = WorkflowHistoryPageToken::encode(0);
        }
        $accepted = $preparing ? ($result['prepared'] ?? false) : ($result['delivered'] ?? false);
        $pending = ! $preparing && ($result['prepared'] ?? false) === true
            && ($result['reason'] ?? null) === 'cancellation_scope_activity_stop_not_acknowledged';
        $successful = ($accepted && ($result['reason'] ?? null) === null) || $pending;

        return WorkerProtocol::json($result, $successful ? 200
            : (($result['reason'] ?? null) === 'task_not_found' ? 404 : 409));
    }

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

        if (($result['opened'] ?? false) === true) {
            $result['lease_owner'] = $validated['lease_owner'];
            $result['workflow_task_attempt'] = (int) $validated['workflow_task_attempt'];
            $result['history_refresh_page_token'] = WorkflowHistoryPageToken::encode(0);
        }

        return WorkerProtocol::json($result, ($result['opened'] ?? false) ? 200
            : (($result['reason'] ?? null) === 'task_not_found' ? 404 : 409));
    }
}
