<?php

namespace App\Support;

use Workflow\V2\Contracts\PreparedLocalActivityGroupTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;

/** Candidate protocol 1.20 admission and original issued local authority. */
final class PreparedLocalActivityPolicy
{
    public const CAPABILITY = 'prepared_local_activities';

    public const GROUP_CAPABILITY = 'prepared_local_activity_groups';

    public const CANCELLATION_POLICIES_CAPABILITY = 'prepared_local_activity_cancellation_policies';

    public const MINIMUM_PROTOCOL_VERSION = '1.20';

    private const CLAIM_KEY = '_server_workflow_claim';

    private const LOCAL_CLAIMS_KEY = '_server_prepared_local_activity_claims';

    public static function backendSupported(): bool
    {
        return interface_exists(PreparedLocalActivityTaskBridge::class)
            && app(WorkflowTaskBridge::class) instanceof PreparedLocalActivityTaskBridge
            && method_exists(app(WorkflowTaskBridge::class), 'heartbeatLocalActivity');
    }

    public static function serverSupported(): bool
    {
        return CooperativeCancellationPolicy::serverSupported() && self::backendSupported();
    }

    public static function groupsSupported(): bool
    {
        return self::serverSupported() && interface_exists(PreparedLocalActivityGroupTaskBridge::class)
            && app(WorkflowTaskBridge::class) instanceof PreparedLocalActivityGroupTaskBridge;
    }

    /** @return list<string> */
    public static function cancellationPolicies(): array
    {
        $bridge = app(WorkflowTaskBridge::class);
        if (! self::serverSupported() || ! method_exists($bridge, 'supportedLocalActivityCancellationPolicies')
            || ! is_callable([$bridge, 'supportedLocalActivityCancellationPolicies'])) {
            return [];
        }

        $policies = $bridge->supportedLocalActivityCancellationPolicies();

        return is_array($policies)
            ? array_values(array_filter(['try_cancel', 'wait_cancellation_completed'], static fn (string $policy): bool => in_array($policy, $policies, true)))
            : [];
    }

    /** @param list<string> $capabilities */
    public static function supportsCancellationPolicies(array $capabilities, ?string $protocolVersion): bool
    {
        return CooperativeCancellationPolicy::supports($capabilities, $protocolVersion)
            && in_array(self::CAPABILITY, $capabilities, true)
            && in_array(self::CANCELLATION_POLICIES_CAPABILITY, $capabilities, true);
    }

    /** @return list<string> */
    public static function cancellationPolicyUnavailableReasons(?WorkflowTask $task, mixed $policy, ?string $requestVersion): array
    {
        $reasons = self::unavailableReasons($requestVersion);
        if (! in_array($policy, ['try_cancel', 'wait_cancellation_completed'], true)) {
            $reasons[] = 'local_activity_cancellation_policy';
        } elseif (! in_array($policy, self::cancellationPolicies(), true)) {
            $reasons[] = 'installed_runtime_local_activity_cancellation_policy';
        }
        $claim = $task instanceof WorkflowTask ? self::currentClaim($task) : null;
        if ($claim === null || ! self::supportsCancellationPolicies($claim['capabilities'] ?? [], $claim['protocol_version'] ?? null)) {
            $reasons[] = 'worker_claim_cancellation_policy_capability';
        }

        return $reasons;
    }

    /**
     * Canonical admission determines replay compatibility. Registration cannot
     * upgrade the authority of an existing claim, and a legacy worker cannot
     * reinterpret an explicitly authored policy during takeover.
     *
     * @param  list<string>  $capabilities
     */
    public static function canReplayRun(string $runId, array $capabilities, ?string $protocolVersion): bool
    {
        $policies = self::cancellationPolicies();
        $workerSupported = self::supportsCancellationPolicies($capabilities, $protocolVersion);
        if ($workerSupported && count($policies) === 2) {
            return true;
        }
        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityScheduled)
            ->where('payload->execution_mode', 'local')
            ->whereNotNull('payload->activity->cancellation_policy');
        if ($workerSupported && $policies !== []) {
            $events->whereNotIn('payload->activity->cancellation_policy', $policies);
        }

        return ! $events->exists();
    }

    /** @return list<string> */
    public static function groupUnavailableReasons(?string $requestVersion): array
    {
        $reasons = self::unavailableReasons($requestVersion);
        if (! interface_exists(PreparedLocalActivityGroupTaskBridge::class)
            || ! app(WorkflowTaskBridge::class) instanceof PreparedLocalActivityGroupTaskBridge) {
            $reasons[] = 'installed_runtime_prepared_local_activity_groups';
        }

        return $reasons;
    }

    /** @return list<string> */
    public static function unavailableReasons(?string $requestVersion): array
    {
        $reasons = [];
        if (! CooperativeCancellationPolicy::serverSupported()) {
            $reasons[] = 'server_protocol';
        }
        if (! WorkerProtocol::versionMeetsMinimum($requestVersion, self::MINIMUM_PROTOCOL_VERSION)) {
            $reasons[] = 'request_protocol';
        }
        if (! self::backendSupported()) {
            $reasons[] = 'installed_runtime_prepared_local_activity';
        }

        return $reasons;
    }

    /** @return array<string, mixed>|null */
    public static function currentClaim(WorkflowTask $task): ?array
    {
        $claim = $task->payload[self::CLAIM_KEY] ?? null;
        if (! is_array($claim) || ! CooperativeCancellationPolicy::claimSupportsCancellation($task)
            || ! in_array(self::CAPABILITY, $claim['capabilities'] ?? [], true)) {
            return null;
        }

        return $claim;
    }

    /** @return array<string, mixed>|null */
    public static function currentGroupClaim(WorkflowTask $task): ?array
    {
        $claim = self::currentClaim($task);

        return $claim !== null && in_array(self::GROUP_CAPABILITY, $claim['capabilities'] ?? [], true) ? $claim : null;
    }

    /**
     * Persist only after Native preparation succeeds, in the same transaction.
     * Native's attempt/execution/run/task locks must already be held. This
     * receipt is issued authority, not a second lease or attempt tracker.
     *
     * @param  array<string, mixed>  $claim
     */
    public static function remember(WorkflowTask $task, ActivityAttempt $attempt, array $claim): bool
    {
        if (self::currentClaim($task) !== $claim || $attempt->workflow_task_id !== $task->id
            || $attempt->workflow_run_id !== $task->workflow_run_id
            || $attempt->lease_owner !== ($claim['lease_owner'] ?? null)
            || ! is_string($attempt->worker_attempt_id) || $attempt->worker_attempt_id === '') {
            return false;
        }
        $payload = is_array($task->payload) ? $task->payload : [];
        $receipts = $payload[self::LOCAL_CLAIMS_KEY] ?? [];
        if (! is_array($receipts)) {
            return false;
        }
        $receipt = [
            'schema' => 'durable-workflow.server.prepared-local-authority/v1',
            'claim' => $claim,
            'activity_execution_id' => $attempt->activity_execution_id,
            'activity_attempt_id' => $attempt->id,
            'worker_attempt_id' => $attempt->worker_attempt_id,
        ];
        if (array_key_exists($attempt->id, $receipts)) {
            // JSON objects may be reordered by MySQL. The validated original
            // claim and current claim are both read from the same task payload.
            return self::originalClaim($task, $attempt, $claim['lease_owner'], $claim['attempt']) === $claim;
        }
        $receipts[$attempt->id] = $receipt;
        $payload[self::LOCAL_CLAIMS_KEY] = $receipts;
        $task->forceFill(['payload' => $payload])->save();

        return true;
    }

    /**
     * Takeover may replace the current claim. Stop observation and receipts
     * still use the immutable claim that admitted this particular callback.
     * Native separately validates its canonical Started event and authority.
     *
     * @return array<string, mixed>|null
     */
    public static function originalClaim(
        WorkflowTask $task,
        ActivityAttempt $attempt,
        string $leaseOwner,
        int $workflowTaskAttempt,
    ): ?array {
        $receipt = $task->payload[self::LOCAL_CLAIMS_KEY][$attempt->id] ?? null;
        $claim = is_array($receipt) ? ($receipt['claim'] ?? null) : null;
        if (! is_array($claim)
            || ($receipt['schema'] ?? null) !== 'durable-workflow.server.prepared-local-authority/v1'
            || ($receipt['activity_execution_id'] ?? null) !== $attempt->activity_execution_id
            || ($receipt['activity_attempt_id'] ?? null) !== $attempt->id
            || ($receipt['worker_attempt_id'] ?? null) !== $attempt->worker_attempt_id
            || $attempt->workflow_task_id !== $task->id || $attempt->workflow_run_id !== $task->workflow_run_id
            || $attempt->lease_owner !== $leaseOwner || $workflowTaskAttempt < 1
            || ($claim['task_id'] ?? null) !== $task->id || ($claim['run_id'] ?? null) !== $task->workflow_run_id
            || ($claim['namespace'] ?? null) !== $task->namespace
            || ($claim['worker_id'] ?? null) !== $leaseOwner || ($claim['lease_owner'] ?? null) !== $leaseOwner
            || ($claim['attempt'] ?? null) !== $workflowTaskAttempt
            || ! CooperativeCancellationPolicy::supports($claim['capabilities'] ?? [], $claim['protocol_version'] ?? null)
            || ! in_array(self::CAPABILITY, $claim['capabilities'] ?? [], true)) {
            return null;
        }

        return $claim;
    }
}
