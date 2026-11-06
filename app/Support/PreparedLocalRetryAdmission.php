<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\Contracts\PreparedLocalActivityRetryTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\PortableLocalActivityPreparation;
use Workflow\V2\Support\PreparedLocalActivityReport;
use Workflow\V2\Support\PreparedLocalRetryPolicy;

/** Optional contract-2 authority. The installed v1 bridge remains unchanged. */
final class PreparedLocalRetryAdmission
{
    public const CAPABILITY = 'activity_retry_delay_override_v1';

    public const SCHEMA = 'durable-workflow.server.prepared-local-authority/v2';

    public const RECOVERY_SCHEMA = 'durable-workflow.server.prepared-local-recovery-authority/v2';

    private const CLAIMS_KEY = '_server_prepared_local_activity_claims';

    private const RECOVERIES_KEY = '_server_prepared_local_activity_recoveries';

    private const RECOVERY_KEYS = ['schema', 'claim', 'activity_execution_id', 'activity_attempt_id',
        'local_activity_contract_version', 'retry_policy_snapshot', 'preparation_history_event_id',
        'descriptor_fingerprint', 'engine_event'];

    private const RECEIPT_KEYS = ['schema', 'claim', 'activity_execution_id', 'activity_attempt_id',
        'worker_attempt_id', 'local_activity_contract_version', 'retry_policy_snapshot',
        'preparation_history_event_id', 'descriptor_fingerprint'];

    public static function backendSupported(): bool
    {
        return PreparedLocalActivityPolicy::serverSupported()
            && interface_exists(PreparedLocalActivityRetryTaskBridge::class)
            && app(WorkflowTaskBridge::class) instanceof PreparedLocalActivityRetryTaskBridge
            && class_exists(PreparedLocalActivityReport::class)
            && class_exists(PreparedLocalRetryPolicy::class)
            && is_callable([PortableLocalActivityPreparation::class, 'originalStart']);
    }

    /** New policy members signal intent, without granting a stored mode. */
    public static function descriptorIntent(array $descriptor): bool
    {
        return is_array($descriptor['retry_policy'] ?? null)
            && array_intersect(array_keys($descriptor['retry_policy']),
                ['contract_version', 'snapshot_version', 'mode', 'maximum_interval_seconds']) !== [];
    }

    public static function reportIntent(array $report): bool
    {
        return array_key_exists('next_retry_delay_seconds', $report);
    }

    public static function retainedIntent(WorkflowTask $task, ActivityAttempt $attempt): bool
    {
        return ($task->payload[self::CLAIMS_KEY][$attempt->id]['schema'] ?? null) === self::SCHEMA;
    }

    public static function claimSupports(array $claim): bool
    {
        return is_array($claim['capabilities'] ?? null)
            && is_string($claim['protocol_version'] ?? null)
            && in_array(self::CAPABILITY, $claim['capabilities'], true)
            && in_array(PreparedLocalActivityPolicy::CAPABILITY, $claim['capabilities'], true)
            && CooperativeCancellationPolicy::supports($claim['capabilities'], $claim['protocol_version'] ?? null);
    }

    /** Pure policy checks precede any argument resolution or preparation. */
    public static function validatePolicy(array $descriptor): void
    {
        if (! class_exists(PreparedLocalRetryPolicy::class)) {
            throw new LogicException('The installed prepared retry policy role is unavailable.');
        }
        try {
            if (! is_array($descriptor['retry_policy'] ?? null)) {
                throw new InvalidArgumentException;
            }
            PreparedLocalRetryPolicy::normalize($descriptor['retry_policy'], array_intersect_key(
                $descriptor, array_flip(['start_to_close_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout']),
            ));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'descriptor.retry_policy' => ['Expected a valid version-2 prepared retry policy.'],
            ]);
        }
    }

    /** No lookup, payload resolution, callback or clock sample occurs here. */
    public static function reportRefusal(array $report): ?string
    {
        if (! class_exists(PreparedLocalActivityReport::class)) {
            throw new LogicException('The installed prepared retry report role is unavailable.');
        }

        return PreparedLocalActivityReport::refusal($report);
    }

    /** Persist only after the actual optional bridge prepared under the outer transaction. */
    public static function remember(WorkflowTask $task, ActivityAttempt $attempt, array $claim): bool
    {
        if (DB::transactionLevel() < 1 || ! self::backendSupported()
            || PreparedLocalActivityPolicy::currentClaim($task) !== $claim || ! self::claimSupports($claim)) {
            return false;
        }
        $graph = self::graph($task, $attempt, $claim['lease_owner'], $claim['attempt']);
        if ($graph === null) {
            return false;
        }
        $payload = is_array($task->payload) ? $task->payload : [];
        $receipts = $payload[self::CLAIMS_KEY] ?? [];
        if (! is_array($receipts)) {
            return false;
        }
        if (array_key_exists($attempt->id, $receipts)) {
            // A v1 receipt, or a changed v2 graph, is never rewritten.
            return self::originalClaim($task, $attempt, $claim['lease_owner'], $claim['attempt']) === $claim;
        }
        $receipts[$attempt->id] = ['schema' => self::SCHEMA, 'claim' => $claim,
            'activity_execution_id' => $attempt->activity_execution_id,
            'activity_attempt_id' => $attempt->id, 'worker_attempt_id' => $attempt->worker_attempt_id,
            'local_activity_contract_version' => 2, ...$graph];
        $payload[self::CLAIMS_KEY] = $receipts;
        $task->forceFill(['payload' => $payload])->save();

        return true;
    }

    /** Original authority survives replacement of the hosting task's current claim. */
    public static function originalClaim(WorkflowTask $task, ActivityAttempt $attempt, string $owner, int $epoch): ?array
    {
        $receipt = $task->payload[self::CLAIMS_KEY][$attempt->id] ?? null;
        $claim = is_array($receipt) ? ($receipt['claim'] ?? null) : null;
        if (! self::backendSupported() || ! is_array($receipt) || ! is_array($claim)
            || ! self::exactKeys($receipt, self::RECEIPT_KEYS)
            || ($receipt['schema'] ?? null) !== self::SCHEMA
            || ($receipt['local_activity_contract_version'] ?? null) !== 2
            || ($receipt['activity_execution_id'] ?? null) !== $attempt->activity_execution_id
            || ($receipt['activity_attempt_id'] ?? null) !== $attempt->id
            || ($receipt['worker_attempt_id'] ?? null) !== $attempt->worker_attempt_id
            || ($claim['task_id'] ?? null) !== $task->id || ($claim['run_id'] ?? null) !== $task->workflow_run_id
            || ($claim['namespace'] ?? null) !== $task->namespace || ($claim['worker_id'] ?? null) !== $owner
            || ($claim['lease_owner'] ?? null) !== $owner || ($claim['attempt'] ?? null) !== $epoch
            || ! self::claimSupports($claim)) {
            return null;
        }
        $graph = self::graph($task, $attempt, $owner, $epoch);
        if ($graph === null || ($receipt['preparation_history_event_id'] ?? null) !== $graph['preparation_history_event_id']
            || ($receipt['descriptor_fingerprint'] ?? null) !== $graph['descriptor_fingerprint']) {
            return null;
        }
        try {
            if (! is_array($receipt['retry_policy_snapshot'])
                || PreparedLocalRetryPolicy::validateSnapshot($receipt['retry_policy_snapshot']) !== $graph['retry_policy_snapshot']) {
                return null;
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        return $claim;
    }

    /** Original callback authority for a fresh recovery or its retained duplicate. */
    public static function recoveryAuthority(WorkflowTask $task, int $sequence, string $owner, int $epoch): ?array
    {
        if (! self::backendSupported()) {
            return null;
        }
        $execution = ActivityExecution::query()->where('workflow_run_id', $task->workflow_run_id)
            ->where('sequence', $sequence)->first();
        if (! $execution instanceof ActivityExecution || ! is_string($execution->current_attempt_id)) {
            return null;
        }
        // A following attempt may already exist. A duplicate recovery still
        // binds the attempt retained by this exact recovery identity.
        $receipts = WorkflowHistoryEvent::query()->where('workflow_run_id', $task->workflow_run_id)
            ->whereIn('event_type', [HistoryEventType::ActivityRetryScheduled, HistoryEventType::ActivityFailed,
                HistoryEventType::ActivityTimedOut])
            ->where('payload->sequence', $sequence)
            ->where('payload->local_recovery->workflow_task_id', $task->id)
            ->where('payload->local_recovery->workflow_task_attempt', $epoch)
            ->where('payload->local_recovery->lease_owner', $owner)->limit(2)->get();
        if ($receipts->count() > 1) {
            return null;
        }
        $attemptId = $receipts->isEmpty() ? $execution->current_attempt_id : ($receipts->first()->payload['activity_attempt_id'] ?? null);
        if (! is_string($attemptId)) {
            return null;
        }
        $attempt = ActivityAttempt::query()->where('workflow_run_id', $task->workflow_run_id)
            ->where('activity_execution_id', $execution->id)->find($attemptId);
        $originalTask = $attempt instanceof ActivityAttempt
            ? WorkflowTask::query()->where('namespace', $task->namespace)->where('workflow_run_id', $task->workflow_run_id)->find($attempt->workflow_task_id)
            : null;
        $originalEpoch = $originalTask?->payload[self::CLAIMS_KEY][$attemptId]['claim']['attempt'] ?? null;
        if (! $attempt instanceof ActivityAttempt || ! $originalTask instanceof WorkflowTask || ! is_int($originalEpoch)
            || ! is_string($attempt->lease_owner) || $attempt->lease_owner === '') {
            return null;
        }
        $claim = self::originalClaim($originalTask, $attempt, $attempt->lease_owner, $originalEpoch);

        return $claim === null ? null : ['task' => $originalTask, 'attempt' => $attempt, 'claim' => $claim,
            'event' => $receipts->first()];
    }

    /** A retained recovery claim authorizes only Core's exact duplicate. */
    public static function retainedRecovery(WorkflowTask $task, int $sequence, string $owner, int $epoch): ?array
    {
        $authority = self::recoveryAuthority($task, $sequence, $owner, $epoch);
        $event = $authority['event'] ?? null;
        if (! $event instanceof WorkflowHistoryEvent) {
            return null;
        }
        $receipt = $task->payload[self::RECOVERIES_KEY][$event->id] ?? null;
        $claim = is_array($receipt) ? ($receipt['claim'] ?? null) : null;
        if (! is_array($receipt) || ! self::exactKeys($receipt, self::RECOVERY_KEYS)
            || ($receipt['schema'] ?? null) !== self::RECOVERY_SCHEMA
            || ($receipt['local_activity_contract_version'] ?? null) !== 2
            || ! is_array($claim) || ! self::claimSupports($claim)
            || ($claim['task_id'] ?? null) !== $task->id || ($claim['run_id'] ?? null) !== $task->workflow_run_id
            || ($claim['namespace'] ?? null) !== $task->namespace
            || ($claim['lease_owner'] ?? null) !== $owner || ($claim['worker_id'] ?? null) !== $owner
            || ($claim['attempt'] ?? null) !== $epoch) {
            return null;
        }
        $graph = self::graph($authority['task'], $authority['attempt'], $authority['claim']['lease_owner'], $authority['claim']['attempt']);
        $engine = self::recoveryEvent($event, $task, $authority['attempt'], $claim);
        if ($graph === null || $engine === null
            || ($receipt['activity_execution_id'] ?? null) !== $authority['attempt']->activity_execution_id
            || ($receipt['activity_attempt_id'] ?? null) !== $authority['attempt']->id
            || ($receipt['preparation_history_event_id'] ?? null) !== $graph['preparation_history_event_id']
            || ($receipt['descriptor_fingerprint'] ?? null) !== $graph['descriptor_fingerprint']
            || ! self::sameRecoveryEvent($receipt['engine_event'], $engine)) {
            return null;
        }
        try {
            if (! is_array($receipt['retry_policy_snapshot'])
                || PreparedLocalRetryPolicy::validateSnapshot($receipt['retry_policy_snapshot']) !== $graph['retry_policy_snapshot']) {
                return null;
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['task' => $task, 'claim' => $claim, 'event_id' => $event->id, 'authority' => $authority];
    }

    /** No backfill: only the fresh engine recovery and its issued claim may create this receipt. */
    public static function rememberRecovery(WorkflowTask $task, int $sequence, array $claim, array $reply): bool
    {
        if (DB::transactionLevel() < 1 || ! self::backendSupported() || ! self::claimSupports($claim)
            || ($reply['recovered'] ?? null) !== true || ($reply['duplicate'] ?? null) !== false
            || ($task->payload['_server_workflow_claim'] ?? null) !== $claim) {
            return false;
        }
        $authority = self::recoveryAuthority($task, $sequence, $claim['lease_owner'], $claim['attempt']);
        $event = $authority['event'] ?? null;
        if (! $event instanceof WorkflowHistoryEvent || ($reply['event_id'] ?? null) !== $event->id) {
            return false;
        }
        $graph = self::graph($authority['task'], $authority['attempt'], $authority['claim']['lease_owner'], $authority['claim']['attempt']);
        $engine = self::recoveryEvent($event, $task, $authority['attempt'], $claim);
        $payload = is_array($task->payload) ? $task->payload : [];
        $receipts = $payload[self::RECOVERIES_KEY] ?? [];
        if ($graph === null || $engine === null || ! is_array($receipts) || array_key_exists($event->id, $receipts)) {
            return false;
        }
        if (! self::matchesRecoveryReply(['task' => $task, 'claim' => $claim, 'event_id' => $event->id,
            'authority' => $authority], $reply, false)) {
            return false;
        }
        $receipts[$event->id] = ['schema' => self::RECOVERY_SCHEMA, 'claim' => $claim,
            'activity_execution_id' => $authority['attempt']->activity_execution_id,
            'activity_attempt_id' => $authority['attempt']->id, 'local_activity_contract_version' => 2,
            ...$graph, 'engine_event' => $engine];
        $payload[self::RECOVERIES_KEY] = $receipts;
        $task->forceFill(['payload' => $payload])->save();

        return true;
    }

    /** The bridge's carrier must match independently retained event authority. */
    public static function matchesRecoveryReply(array $context, array $reply, bool $duplicate): bool
    {
        $event = $context['authority']['event'];
        $attempt = $context['authority']['attempt'];
        $engine = self::recoveryEvent($event, $context['task'], $attempt, $context['claim']);
        if ($engine === null || ! self::exactKeys($reply, ['recovered', 'duplicate', 'reason', 'event_id', 'event_type',
            'activity_execution_id', 'activity_attempt_id', 'workflow_task_id', 'recorded_at', 'callback_stop_state',
            'claim_released', 'created_task_ids', 'retry_disposition'])) {
            return false;
        }

        return $reply['recovered'] === true && $reply['duplicate'] === $duplicate && $reply['reason'] === null
            && $reply['event_id'] === $engine['event_id'] && $reply['event_type'] === $engine['event_type']
            && $reply['activity_execution_id'] === $attempt->activity_execution_id && $reply['activity_attempt_id'] === $attempt->id
            && $reply['workflow_task_id'] === $context['task']->id && $reply['recorded_at'] === $engine['recorded_at']
            && $reply['callback_stop_state'] === 'unknown'
            && $reply['claim_released'] === ($engine['event_type'] === HistoryEventType::ActivityRetryScheduled->value)
            && $reply['created_task_ids'] === ($engine['retry_task_id'] === null ? [] : [$engine['retry_task_id']])
            && self::flatObject($reply['retry_disposition']) === $engine['retry_disposition'];
    }

    private static function recoveryEvent(WorkflowHistoryEvent $event, WorkflowTask $task, ActivityAttempt $attempt, array $claim): ?array
    {
        $payload = $event->payload;
        $recovery = self::flatObject($payload['local_recovery'] ?? null);
        $decision = self::flatObject($payload['retry_disposition'] ?? null);
        $retryTask = $payload['retry_task_id'] ?? null;
        if ($recovery === null || $decision === null || $event->recorded_at === null
            || ($decision['contract_version'] ?? null) !== 2 || ($decision['snapshot_version'] ?? null) !== 2
            || ($event->event_type === HistoryEventType::ActivityRetryScheduled && (! is_string($retryTask) || $retryTask === ''))
            || ($event->event_type !== HistoryEventType::ActivityRetryScheduled && $retryTask !== null)
            || $event->workflow_run_id !== $task->workflow_run_id || $event->workflow_task_id !== $task->id
            || ($payload['activity_execution_id'] ?? null) !== $attempt->activity_execution_id
            || ($payload['activity_attempt_id'] ?? null) !== $attempt->id
            || ($payload['worker_attempt_id'] ?? null) !== $attempt->worker_attempt_id
            || ($payload['task']['id'] ?? null) !== $task->id
            || ($payload['task']['lease_owner'] ?? null) !== $claim['lease_owner']
            || ($payload['task']['attempt_count'] ?? null) !== $claim['attempt']
            || ($recovery['version'] ?? null) !== 1 || ($recovery['workflow_task_id'] ?? null) !== $task->id
            || ($recovery['workflow_task_attempt'] ?? null) !== $claim['attempt']
            || ($recovery['lease_owner'] ?? null) !== $claim['lease_owner']
            || ($recovery['callback_stop_state'] ?? null) !== 'unknown') {
            return null;
        }

        return ['event_id' => $event->id, 'event_type' => $event->event_type->value,
            'event_sequence' => $event->sequence, 'recorded_at' => $event->recorded_at->toISOString(),
            'retry_task_id' => $retryTask, 'local_recovery' => $recovery, 'retry_disposition' => $decision];
    }

    private static function sameRecoveryEvent(mixed $recorded, array $current): bool
    {
        if (! is_array($recorded) || ! self::exactKeys($recorded, array_keys($current))) {
            return false;
        }
        foreach (['event_id', 'event_type', 'event_sequence', 'recorded_at', 'retry_task_id'] as $field) {
            if ($recorded[$field] !== $current[$field]) {
                return false;
            }
        }

        return self::flatObject($recorded['local_recovery']) === $current['local_recovery']
            && self::flatObject($recorded['retry_disposition']) === $current['retry_disposition'];
    }

    /** The closed engine decision and recovery identity contain only scalar fields. */
    private static function flatObject(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return null;
        }
        foreach ($value as $key => $item) {
            if (! is_string($key) || ($item !== null && ! is_string($item) && ! is_int($item) && ! is_bool($item))) {
                return null;
            }
        }
        ksort($value);

        return $value;
    }

    /** Read canonical Core authority. The engine rechecks it under its locks. */
    private static function graph(WorkflowTask $task, ActivityAttempt $attempt, string $owner, int $epoch): ?array
    {
        if ($epoch < 1 || $attempt->workflow_task_id !== $task->id || $attempt->workflow_run_id !== $task->workflow_run_id
            || $attempt->lease_owner !== $owner || ! is_string($attempt->worker_attempt_id) || $attempt->worker_attempt_id === '') {
            return null;
        }
        $run = WorkflowRun::query()->where('namespace', $task->namespace)->find($task->workflow_run_id);
        $execution = ActivityExecution::query()->where('workflow_run_id', $task->workflow_run_id)->find($attempt->activity_execution_id);
        if (! $run instanceof WorkflowRun || ! $execution instanceof ActivityExecution
            || ($execution->activity_options['local_activity_contract_version'] ?? null) !== 2) {
            return null;
        }
        // Do not let a first-row selection hide conflicting start authority.
        $starts = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityStarted)
            ->where('payload->activity_attempt_id', $attempt->id)->limit(2)->get();
        if ($starts->count() !== 1) {
            return null;
        }
        $started = PortableLocalActivityPreparation::originalStart($run, $execution, $attempt, $owner, $epoch, 2);
        if (! $started instanceof WorkflowHistoryEvent || $started->id !== $starts->first()->id
            || ($started->payload['local_preparation']['version'] ?? null) !== 2
            || ($started->payload['local_preparation']['local_activity_contract_version'] ?? null) !== 2
            || ! is_string($started->payload['local_preparation']['descriptor_fingerprint'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $started->payload['local_preparation']['descriptor_fingerprint']) !== 1
            || ! is_array($started->payload['activity']['retry_policy'] ?? null)
            || ! is_array($execution->retry_policy)) {
            return null;
        }
        try {
            $snapshot = PreparedLocalRetryPolicy::validateSnapshot($execution->retry_policy);
            if (PreparedLocalRetryPolicy::validateSnapshot($started->payload['activity']['retry_policy']) !== $snapshot) {
                return null;
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        return ['retry_policy_snapshot' => $snapshot, 'preparation_history_event_id' => $started->id,
            'descriptor_fingerprint' => $started->payload['local_preparation']['descriptor_fingerprint']];
    }

    private static function exactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }
}
