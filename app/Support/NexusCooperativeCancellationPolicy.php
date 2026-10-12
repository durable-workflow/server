<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Workflow\V2\Contracts\CooperativeWorkflowTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
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
        if (! $run->relationLoaded('historyEvents') && ! $run->historyEvents()
            ->where('event_type', HistoryEventType::ServiceCallStarted->value)->exists()) {
            return null;
        }
        $run->loadMissing('historyEvents');
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
                return array_intersect_key($payload, array_flip([
                    'sequence', 'service_call_id', 'endpoint_name', 'service_name', 'operation_name', 'resolved_binding_kind',
                ]));
            }
        }

        return null;
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
}
