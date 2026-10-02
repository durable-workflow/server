<?php

namespace App\Support;

use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowTask;

/** Candidate protocol 1.20 admission and original issued local authority. */
final class PreparedLocalActivityPolicy
{
    public const CAPABILITY = 'prepared_local_activities';

    public const MINIMUM_PROTOCOL_VERSION = '1.20';

    private const CLAIM_KEY = '_server_workflow_claim';

    private const LOCAL_CLAIMS_KEY = '_server_prepared_local_activity_claims';

    public static function backendSupported(): bool
    {
        return interface_exists(PreparedLocalActivityTaskBridge::class)
            && app(WorkflowTaskBridge::class) instanceof PreparedLocalActivityTaskBridge;
    }

    public static function serverSupported(): bool
    {
        return CooperativeCancellationPolicy::serverSupported() && self::backendSupported();
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
