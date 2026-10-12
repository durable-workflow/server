<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\CooperativeWorkflowTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\ServiceOperationCancellation;
use Workflow\V2\Workflow;

final class NexusCooperativeCancellationPolicy
{
    public const CAPABILITY = 'nexus_cooperative_cancellation';

    public static function serverSupported(): bool
    {
        return CooperativeCancellationPolicy::serverSupported()
            && app(WorkflowTaskBridge::class) instanceof CooperativeWorkflowTaskBridge
            && class_exists(ServiceOperationCancellation::class)
            && is_callable([ServiceOperationCancellation::class, 'prepare'])
            && is_callable([ServiceOperationCancellation::class, 'unsupportedReason']);
    }

    /** @param list<string> $capabilities */
    public static function supports(array $capabilities, ?string $protocolVersion): bool
    {
        return CooperativeCancellationPolicy::supports($capabilities, $protocolVersion)
            && in_array(self::CAPABILITY, $capabilities, true);
    }

    public static function claimSupportsCancellation(WorkflowTask $task): bool
    {
        $claim = $task->payload['_server_workflow_claim'] ?? null;

        return CooperativeCancellationPolicy::claimSupportsCancellation($task)
            && is_array($claim)
            && self::supports($claim['capabilities'] ?? [], $claim['protocol_version'] ?? null);
    }

    /** @return array<string, mixed>|null */
    public static function pendingCall(WorkflowRun $run): ?array
    {
        return self::pendingCalls($run)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private static function pendingCalls(WorkflowRun $run): array
    {
        if (! $run->relationLoaded('historyEvents') && ! $run->historyEvents()
            ->where('event_type', HistoryEventType::ServiceCallStarted->value)->exists()) {
            return [];
        }
        $run->loadMissing('historyEvents');
        $calls = [];
        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            $payload = $event->payload;
            if ($event->event_type !== HistoryEventType::ServiceCallStarted
                || ($payload['operation_mode'] ?? null) !== 'sync'
                || ($payload['wait_for'] ?? null) !== 'completed') {
                continue;
            }
            $resolved = $run->historyEvents->contains(static fn ($candidate): bool => ($candidate->payload['sequence'] ?? null) === ($payload['sequence'] ?? null)
                && in_array($candidate->event_type, [HistoryEventType::ServiceCallCompleted,
                    HistoryEventType::ServiceCallFailed, HistoryEventType::ServiceCallCancelled], true));
            if (! $resolved) {
                $calls[] = array_intersect_key($payload, array_flip([
                    'sequence', 'service_call_id', 'endpoint_name', 'service_name', 'operation_name', 'resolved_binding_kind',
                ]));
            }
        }

        return $calls;
    }

    public static function requiresCapabilityForReplay(WorkflowRun $run): bool
    {
        if (! $run->relationLoaded('historyEvents') && ! $run->historyEvents()
            ->where('event_type', HistoryEventType::ServiceCallStarted->value)->exists()) {
            return false;
        }
        $run->loadMissing('historyEvents');
        $calls = [];
        $outcomes = [];
        $requestSequence = null;
        foreach ($run->historyEvents as $event) {
            $payload = $event->payload;
            if ($event->event_type === HistoryEventType::CooperativeCancellationDelivered
                && ($payload['call_kind'] ?? null) === 'service_operation') {
                return true;
            }
            if ($event->event_type === HistoryEventType::CooperativeCancellationRequested
                && ($payload['workflow_command_id'] ?? null) === $run->cancellation_request_command_id) {
                $requestSequence = $event->sequence;
            }
            if ($event->event_type === HistoryEventType::ServiceCallStarted
                && ($payload['operation_mode'] ?? null) === 'sync'
                && ($payload['wait_for'] ?? null) === 'completed') {
                $calls[] = $payload['sequence'] ?? null;
            }
            if (in_array($event->event_type, [HistoryEventType::ServiceCallCompleted,
                HistoryEventType::ServiceCallFailed, HistoryEventType::ServiceCallCancelled], true)) {
                $outcomes[$payload['sequence'] ?? null] = $event->sequence;
            }
        }
        foreach ($calls as $sequence) {
            if (! isset($outcomes[$sequence])
                || ($run->cancellation_delivery_sequence === null
                    && ($requestSequence === null || $outcomes[$sequence] > $requestSequence))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    public static function requestUnavailable(WorkflowRun $run): ?array
    {
        $call = self::pendingCall($run);
        if ($call === null) {
            return null;
        }
        $unavailable = self::serverSupported() ? [] : ['installed_runtime_nexus_cancellation'];
        $ownership = self::serverSupported() ? ServiceOperationCancellation::unsupportedReason($run) : null;
        if ($ownership !== null) {
            $unavailable[] = $ownership['unavailable_reason'];
        }
        // Admission already holds the caller run. Lock its original bindings
        // and targets before validating ownership again and inspecting leases.
        // Target dispatch also holds the run lock, so a new claim cannot slip
        // between this check and the cancellation command commit.
        if ($unavailable === []) {
            foreach (self::pendingCalls($run) as $pending) {
                $bound = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                    ->where('caller_namespace', $run->namespace)
                    ->where('caller_workflow_instance_id', $run->workflow_instance_id)
                    ->where('caller_workflow_run_id', $run->id)
                    ->lockForUpdate()->find($pending['service_call_id'] ?? null);
                $targetInstance = $bound === null ? null : ConfiguredV2Models::query('instance_model', WorkflowInstance::class)
                    ->where('namespace', $bound->target_namespace)
                    ->lockForUpdate()->find($bound->linked_workflow_instance_id);
                $target = $bound === null ? null : ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->where('namespace', $bound->target_namespace)
                    ->where('workflow_instance_id', $bound->linked_workflow_instance_id)
                    ->lockForUpdate()->find($bound->linked_workflow_run_id);
                if ($target === null || $targetInstance === null
                    || (! $target->status->isTerminal() && $targetInstance->current_run_id !== $target->id)) {
                    $unavailable[] = 'nexus_cancellation_owner_unavailable';
                    break;
                }
                // Repeat package ownership/root validation on locked state.
                $ownership = ServiceOperationCancellation::unsupportedReason($run);
                if ($ownership !== null) {
                    $unavailable[] = $ownership['unavailable_reason'];
                    break;
                }
                if (! $target->status->isTerminal()
                    && ($diagnostic = self::targetUnavailable($target)) !== null) {
                    return [...$diagnostic, 'call_kind' => 'service_operation', 'service_call' => $pending];
                }
            }
        }
        $claims = $run->tasks()->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Leased->value)->where('lease_expires_at', '>', now())->get();
        $workers = WorkerRegistration::query()->where('namespace', $run->namespace)
            ->where('task_queue', $run->queue)->get()->filter(static fn (WorkerRegistration $worker): bool => WorkerPollFence::isFresh($worker)
                && in_array($run->workflow_type, $worker->supported_workflow_types ?? [], true));
        $diagnostics = [];
        foreach ($claims as $claim) {
            if (! self::claimSupportsCancellation($claim)) {
                $unavailable[] = 'active_claim_nexus_cancellation';
            }
            $snapshot = $claim->payload['_server_workflow_claim'] ?? [];
            $diagnostics[] = [
                'task_id' => $claim->id, 'workflow_task_attempt' => $claim->attempt_count,
                'worker_id' => $claim->lease_owner, 'protocol_version' => $snapshot['protocol_version'] ?? null,
                'capabilities' => $snapshot['capabilities'] ?? [],
            ];
        }
        if ($claims->isEmpty() && ! is_subclass_of((string) $run->workflow_class, Workflow::class)
            && ! $workers->contains(static fn (WorkerRegistration $worker): bool => in_array(CooperativeCancellationPolicy::CAPABILITY, $worker->capabilities ?? [], true)
                && in_array(self::CAPABILITY, $worker->capabilities ?? [], true)
                && ($run->compatibility === null || $worker->build_id === $run->compatibility))) {
            $unavailable[] = 'capable_workflow_worker_unavailable';
        }
        if ($unavailable === []) {
            return null;
        }

        return [
            'reason' => 'nexus_cooperative_cancellation_not_supported',
            'required_capabilities' => [CooperativeCancellationPolicy::CAPABILITY, self::CAPABILITY],
            'minimum_protocol_version' => CooperativeCancellationPolicy::MINIMUM_PROTOCOL_VERSION,
            'call_kind' => 'service_operation', 'service_call' => $call,
            'unavailable' => array_values(array_unique($unavailable)), 'active_claims' => $diagnostics,
            'available_workers' => $workers->map(static fn (WorkerRegistration $worker): array => [
                'worker_id' => $worker->worker_id, 'runtime' => $worker->runtime, 'sdk_version' => $worker->sdk_version,
                'build_id' => $worker->build_id, 'capabilities' => $worker->capabilities,
            ])->values()->all(),
        ];
    }

    /**
     * Inspect an original owned target inside the admission transaction.
     * Embedded execution is the installed backend's responsibility.
     *
     * @return array<string, mixed>|null
     */
    public static function targetUnavailable(WorkflowRun $run): ?array
    {
        if (DB::connection($run->getConnectionName())->transactionLevel() === 0) {
            throw new \LogicException('Nexus target eligibility requires an admission transaction.');
        }
        if (is_subclass_of((string) $run->workflow_class, Workflow::class)) {
            return null;
        }
        $nexus = self::requiresCapabilityForReplay($run);
        $required = $nexus ? [CooperativeCancellationPolicy::CAPABILITY, self::CAPABILITY]
            : [CooperativeCancellationPolicy::CAPABILITY];
        $workers = WorkerRegistration::query()->where('namespace', $run->namespace)
            ->where('task_queue', $run->queue)->orderBy('worker_id')->lockForUpdate()->get();
        $claims = $run->tasks()->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Leased->value)->where('lease_expires_at', '>', now())
            ->orderBy('id')->lockForUpdate()->get();
        $diagnostics = [];
        $unavailable = [];
        $retryable = true;
        foreach ($claims as $claim) {
            $snapshot = $claim->payload['_server_workflow_claim'] ?? [];
            $worker = $workers->firstWhere('worker_id', $claim->lease_owner);
            $supported = $nexus ? self::claimSupportsCancellation($claim)
                : CooperativeCancellationPolicy::claimSupportsCancellation($claim);
            if (! $supported) {
                $unavailable[] = 'target_active_claim_cancellation_not_supported';
                $retryable = false;
            } elseif (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker)
                || ! is_string($snapshot['registration_incarnation'] ?? null)
                || ! is_string($worker->registration_token)
                || ! hash_equals($snapshot['registration_incarnation'], hash('sha256', $worker->registration_token))) {
                $unavailable[] = 'target_active_claim_incarnation_unavailable';
            }
            $diagnostics[] = [
                'task_id' => $claim->id, 'workflow_task_attempt' => $claim->attempt_count,
                'worker_id' => $claim->lease_owner, 'protocol_version' => $snapshot['protocol_version'] ?? null,
                'capabilities' => $snapshot['capabilities'] ?? [],
                'runtime' => $snapshot['runtime'] ?? null, 'sdk_version' => $snapshot['sdk_version'] ?? null,
            ];
        }
        $matching = $workers->filter(static fn (WorkerRegistration $worker): bool => WorkerPollFence::isFresh($worker)
            && in_array($run->workflow_type, $worker->supported_workflow_types ?? [], true)
            && ($run->compatibility === null || $run->compatibility === '' || $worker->build_id === $run->compatibility)
            && self::matchesDefinition($run, $worker));
        if ($claims->isEmpty() && ! $matching->contains(static fn (WorkerRegistration $worker): bool =>
            // Registration rejects these capabilities below protocol 1.20.
            array_diff($required, $worker->capabilities ?? []) === [])) {
            $retryable = $matching->isEmpty();
            $unavailable[] = $retryable ? 'target_matching_worker_unavailable' : 'target_worker_cancellation_not_supported';
        }
        if ($unavailable === []) {
            return null;
        }

        return [
            'reason' => $retryable ? 'nexus_cooperative_cancellation_temporarily_unavailable'
                : 'nexus_cooperative_cancellation_not_supported',
            'retryable' => $retryable,
            'required_capabilities' => $required,
            'minimum_protocol_version' => CooperativeCancellationPolicy::MINIMUM_PROTOCOL_VERSION,
            'target' => ['namespace' => $run->namespace, 'workflow_id' => $run->workflow_instance_id,
                'run_id' => $run->id, 'task_queue' => $run->queue, 'workflow_type' => $run->workflow_type,
                'build_id' => $run->compatibility],
            'unavailable' => array_values(array_unique($unavailable)),
            'active_claims' => $diagnostics,
            'available_workers' => $matching->map(static fn (WorkerRegistration $worker): array => [
                'worker_id' => $worker->worker_id, 'runtime' => $worker->runtime,
                'sdk_version' => $worker->sdk_version, 'build_id' => $worker->build_id,
                'capabilities' => $worker->capabilities,
            ])->values()->all(),
        ];
    }

    private static function matchesDefinition(WorkflowRun $run, WorkerRegistration $worker): bool
    {
        $run->loadMissing('historyEvents');
        $started = $run->historyEvents->first(static fn ($event): bool => $event->event_type === HistoryEventType::WorkflowStarted);
        if (($started?->payload['workflow_definition_fingerprint_source'] ?? null) !== 'worker') {
            return true;
        }
        $recorded = $started->payload['workflow_definition_fingerprint'] ?? null;
        $advertised = $worker->workflow_definition_fingerprints[$run->workflow_type] ?? null;

        return is_string($recorded) && trim($recorded) !== '' && is_string($advertised)
            && hash_equals(trim($recorded), trim($advertised));
    }
}
