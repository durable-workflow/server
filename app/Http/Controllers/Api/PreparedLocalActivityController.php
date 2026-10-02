<?php

namespace App\Http\Controllers\Api;

use App\Support\AvroPayloadEnvelopeResolver;
use App\Support\BackendLockPressure;
use App\Support\ExternalPayloadStorageUnavailable;
use App\Support\LongPollSignalStore;
use App\Support\NamespaceDurableStateQuota;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\NamespaceWorkflowScope;
use App\Support\PayloadCodecContract;
use App\Support\PreparedLocalActivityAdmissionRefused;
use App\Support\PreparedLocalActivityPolicy;
use App\Support\ServiceModeTimerDispatcher;
use App\Support\WorkerPollFence;
use App\Support\WorkerProtocol;
use App\Support\WorkerProtocolMutationRetrier;
use App\Support\WorkflowHistoryPageToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;

final class PreparedLocalActivityController
{
    public function __construct(
        private readonly WorkerProtocolMutationRetrier $mutations,
        private readonly NamespaceDurableStateQuota $quota,
    ) {}

    public function prepare(Request $request, string $taskId): JsonResponse
    {
        return $this->admit($request, $taskId, false);
    }

    public function recover(Request $request, string $taskId): JsonResponse
    {
        return $this->admit($request, $taskId, true);
    }

    private function admit(Request $request, string $taskId, bool $recover): JsonResponse
    {
        if ($response = self::unavailable($request)) {
            return $response;
        }
        $validated = $request->validate([
            ...self::claimRules(),
            'sequence' => ['required', 'integer', 'min:1'],
            'worker_attempt_id' => [$recover ? 'nullable' : 'required', 'string', 'max:255'],
            'descriptor' => ['required', 'array'],
        ]);
        $namespace = (string) $request->attributes->get('namespace');
        $task = NamespaceWorkflowScope::task($namespace, $taskId);
        if ($task === null) {
            return WorkerProtocol::json(['reason' => 'task_not_found'], 404);
        }
        if (array_key_exists('cancellation_policy', $validated['descriptor'])
            && ($response = self::cancellationPolicyRefusal($request, $task, $validated['descriptor']['cancellation_policy']))) {
            return $response;
        }
        $claim = PreparedLocalActivityPolicy::currentClaim($task);
        if ($claim === null) {
            return self::refused($validated['lease_owner'], ['worker_claim_capability']);
        }
        try {
            $validated['descriptor'] = $this->resolvePayload($validated['descriptor'], 'arguments', 'descriptor', $namespace);
            $reply = $this->mutations->run(fn (): array|JsonResponse => DB::transaction(function () use ($request, $namespace, $taskId, $validated, $claim, $recover): array|JsonResponse {
                $quotaSnapshot = $this->quota->snapshotForMutation($namespace, self::quotaResources());
                // Registration precedes Native's attempt/execution/run/task
                // locks. Do not lock the hosting task before entering Native.
                if (! WorkerPollFence::isCurrentForUpdate($claim)) {
                    return WorkerProtocol::json(['reason' => 'stale_worker_registration'], 409);
                }
                if (array_key_exists('cancellation_policy', $validated['descriptor'])
                    && ($response = self::cancellationPolicyRefusal($request, NamespaceWorkflowScope::task($namespace, $taskId), $validated['descriptor']['cancellation_policy']))) {
                    return $response;
                }
                /** @var PreparedLocalActivityTaskBridge $bridge */
                $bridge = app(WorkflowTaskBridge::class);
                $reply = $recover
                    ? $bridge->recoverLocalActivity($taskId, $validated['lease_owner'], (int) $validated['workflow_task_attempt'], (int) $validated['sequence'], $validated['descriptor'], WorkerProtocol::requestVersion($request))
                    : $bridge->prepareLocalActivity($taskId, $validated['lease_owner'], (int) $validated['workflow_task_attempt'], (int) $validated['sequence'], $validated['worker_attempt_id'], $validated['descriptor'], WorkerProtocol::requestVersion($request));
                if (! $recover && ($reply['prepared'] ?? false)) {
                    $task = NamespaceWorkflowScope::taskQuery($namespace)->lockForUpdate()->find($taskId);
                    $attemptId = $reply['activity_attempt_id'] ?? null;
                    $attempt = is_string($attemptId) ? ActivityAttempt::query()->find($attemptId) : null;
                    if (! $task instanceof WorkflowTask || ! $attempt instanceof ActivityAttempt
                        || ! PreparedLocalActivityPolicy::remember($task, $attempt, $claim)) {
                        throw new PreparedLocalActivityAdmissionRefused;
                    }
                }

                $this->quota->assertNoIncreasePastLimit($quotaSnapshot);

                return $reply;
            }));
        } catch (PreparedLocalActivityAdmissionRefused) {
            return self::refused($validated['lease_owner'], ['original_claim_persistence']);
        } catch (\Throwable $exception) {
            if ($response = $this->payloadFailure($exception)) {
                return $response;
            }
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, false);
        }

        return $this->reply($request, $reply);
    }

    public function control(Request $request, string $taskId, string $attemptId): JsonResponse
    {
        return $this->attemptOperation($request, $taskId, $attemptId, 'control');
    }

    public function outcome(Request $request, string $taskId, string $attemptId): JsonResponse
    {
        return $this->attemptOperation($request, $taskId, $attemptId, 'outcome');
    }

    public function heartbeat(Request $request, string $taskId, string $attemptId): JsonResponse
    {
        return $this->attemptOperation($request, $taskId, $attemptId, 'heartbeat');
    }

    public function acknowledgeCancellation(Request $request, string $taskId, string $attemptId): JsonResponse
    {
        return $this->attemptOperation($request, $taskId, $attemptId, 'acknowledge');
    }

    private function attemptOperation(Request $request, string $taskId, string $attemptId, string $operation): JsonResponse
    {
        if ($response = self::unavailable($request)) {
            return $response;
        }
        $validated = $request->validate([
            ...self::claimRules(),
            'renew_lease' => ['nullable', 'boolean'],
            'report' => [$operation === 'outcome' ? 'required' : 'nullable', 'array'],
            'request_id' => [$operation === 'acknowledge' ? 'required' : 'nullable', 'string', 'max:255'],
            'progress' => ['nullable', 'array'],
        ]);
        $namespace = (string) $request->attributes->get('namespace');
        $task = NamespaceWorkflowScope::task($namespace, $taskId);
        $attempt = ActivityAttempt::query()->where('workflow_task_id', $taskId)->find($attemptId);
        if ($task === null || $attempt === null || $attempt->workflow_run_id !== $task->workflow_run_id) {
            return WorkerProtocol::json(['reason' => 'activity_attempt_not_found'], 404);
        }
        $claim = PreparedLocalActivityPolicy::originalClaim($task, $attempt, $validated['lease_owner'], (int) $validated['workflow_task_attempt']);
        if ($claim === null) {
            return self::refused($validated['lease_owner'], ['original_prepared_local_claim']);
        }
        try {
            if ($operation === 'outcome' && ($validated['report']['outcome'] ?? null) === 'completed') {
                $validated['report'] = $this->resolvePayload($validated['report'], 'result', 'report', $namespace);
            }
            $reply = $this->mutations->run(fn (): array => DB::transaction(function () use ($request, $validated, $claim, $attemptId, $operation): array {
                $quotaSnapshot = $this->quota->snapshotForMutation($claim['namespace'], self::quotaResources());
                /** @var PreparedLocalActivityTaskBridge $bridge */
                $bridge = app(WorkflowTaskBridge::class);
                $version = WorkerProtocol::requestVersion($request);
                $epoch = (int) $validated['workflow_task_attempt'];
                $owner = $validated['lease_owner'];
                if ($operation === 'acknowledge') {
                    // A dead, drained or replaced registration may still have
                    // a surviving supervisor reporting its actual joined stop.
                    $reply = $bridge->acknowledgeLocalActivityCancellation($attemptId, $owner, $validated['request_id'], $epoch, $version);
                    $this->quota->assertNoIncreasePastLimit($quotaSnapshot);

                    return $reply;
                }
                $current = WorkerPollFence::isCurrentForUpdate($claim);
                if ($operation === 'outcome') {
                    // Lost responses may still read their immutable receipt.
                    // Cancellation may fence, but a stale registration cannot
                    // publish a fresh result under an unreclaimed task epoch.
                    if (! $current && ! WorkflowHistoryEvent::query()
                        ->where('workflow_run_id', $claim['run_id'])
                        ->where('payload->activity_attempt_id', $attemptId)
                        ->whereNotNull('payload->local_outcome')->exists()
                    ) {
                        // A stale registration can observe and fence its old
                        // cancelled callback. It cannot publish shielded
                        // cleanup merely because cancellation was accepted.
                        $control = $bridge->controlLocalActivity($attemptId, $owner, $epoch, false, $version);
                        if (! in_array($control['reason'] ?? null, ['cancellation_requested', 'cancellation_deadline_expired'], true)) {
                            return ['recorded' => false, 'reason' => 'stale_worker_registration'];
                        }
                    }
                    $reply = $bridge->recordLocalActivityOutcome($attemptId, $owner, $epoch, $validated['report'], $version);
                    $this->quota->assertNoIncreasePastLimit($quotaSnapshot);

                    return $reply;
                }
                $reply = $operation === 'heartbeat' && $current
                    ? $bridge->heartbeatLocalActivity($attemptId, $owner, $epoch, $validated['progress'] ?? [], $version)
                    : $bridge->controlLocalActivity($attemptId, $owner, $epoch, $current && ($validated['renew_lease'] ?? false), $version);
                if (! $current && ($reply['active'] ?? false)) {
                    $reply = [...$reply, 'active' => false, 'renewed' => false, 'heartbeat_recorded' => false,
                        'stop_required' => true, 'reason' => 'stale_worker_registration'];
                }
                $this->quota->assertNoIncreasePastLimit($quotaSnapshot);

                return $reply;
            }));
        } catch (\Throwable $exception) {
            if ($response = $this->payloadFailure($exception)) {
                return $response;
            }
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, false);
        }

        return $this->reply($request, $reply);
    }

    /** @return array<string, list<string>> */
    private static function claimRules(): array
    {
        return ['lease_owner' => ['required', 'string', 'max:255'], 'workflow_task_attempt' => ['required', 'integer', 'min:1']];
    }

    /** @return list<string> */
    private static function quotaResources(): array
    {
        return [NamespaceDurableStateQuota::WORKFLOW_HISTORY_EVENTS, NamespaceDurableStateQuota::WORKFLOW_TASKS,
            NamespaceDurableStateQuota::PENDING_WORKFLOW_TASKS];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolvePayload(array $payload, string $key, string $prefix, string $namespace): array
    {
        if (array_key_exists('payload_codec', $payload)) {
            try {
                $payload['payload_codec'] = PayloadCodecContract::canonicalize($payload['payload_codec']);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages([$prefix.'.payload_codec' => [$exception->getMessage()]]);
            }
        }
        $value = $payload[$key] ?? null;
        if (is_string($value)) {
            AvroPayloadEnvelopeResolver::assertSerializedPayload($value, $prefix.'.'.$key);
        } elseif (is_array($value)) {
            $resolved = AvroPayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                $value, $prefix.'.'.$key, app(NamespaceExternalPayloadStorage::class)->driverFor($namespace),
            );
            $payload[$key] = $resolved['payload'];
            $payload['payload_codec'] ??= $resolved['codec'];
        }

        return $payload;
    }

    private function payloadFailure(\Throwable $exception): ?JsonResponse
    {
        if ($exception instanceof StructuralLimitExceededException) {
            return WorkerProtocol::json(['recorded' => false, 'reason' => 'structural_limit_exceeded',
                'limit_kind' => $exception->limitKind->value, 'current_value' => $exception->currentValue,
                'configured_limit' => $exception->configuredLimit], 422);
        }
        if ($exception instanceof ExternalPayloadIntegrityException || $exception instanceof ExternalPayloadStorageUnavailable) {
            return WorkerProtocol::json(['recorded' => false, 'reason' => $exception instanceof ExternalPayloadIntegrityException
                ? 'external_payload_integrity_failed' : 'external_payload_storage_unavailable'],
                $exception instanceof ExternalPayloadIntegrityException ? 422 : 503);
        }

        return null;
    }

    public static function unavailable(Request $request): ?JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }
        $reasons = PreparedLocalActivityPolicy::unavailableReasons(WorkerProtocol::requestVersion($request));

        return $reasons === [] ? null : self::refused((string) $request->input('lease_owner', ''), $reasons);
    }

    public static function cancellationPolicyRefusal(Request $request, ?WorkflowTask $task, mixed $policy): ?JsonResponse
    {
        $reasons = PreparedLocalActivityPolicy::cancellationPolicyUnavailableReasons($task, $policy, WorkerProtocol::requestVersion($request));
        if ($reasons === []) {
            return null;
        }

        return WorkerProtocol::json([
            'reason' => 'prepared_local_activity_cancellation_policy_not_supported',
            'recorded' => false,
            'worker_id' => $task?->lease_owner ?? (string) $request->input('lease_owner', ''),
            'requested_policy' => $policy,
            'supported_policies' => PreparedLocalActivityPolicy::cancellationPolicies(),
            'required_capability' => PreparedLocalActivityPolicy::CANCELLATION_POLICIES_CAPABILITY,
            'minimum_protocol_version' => PreparedLocalActivityPolicy::MINIMUM_PROTOCOL_VERSION,
            'requested_version' => WorkerProtocol::requestVersion($request),
            'unavailable' => $reasons,
            'remediation' => 'Use an installed backend with the requested local policy and a new protocol 1.20 claim issued with prepared_local_activity_cancellation_policies. Local abandon requires independently bounded remote work.',
        ], 409);
    }

    /** @param list<string> $reasons */
    public static function refused(string $workerId, array $reasons, string $capability = PreparedLocalActivityPolicy::CAPABILITY): JsonResponse
    {
        return WorkerProtocol::json([
            'reason' => $capability === PreparedLocalActivityPolicy::GROUP_CAPABILITY
                ? 'prepared_local_activity_groups_not_supported' : 'prepared_local_activity_not_supported',
            'required_capability' => $capability,
            'minimum_protocol_version' => PreparedLocalActivityPolicy::MINIMUM_PROTOCOL_VERSION,
            'worker_id' => $workerId,
            'unavailable' => $reasons,
            'remediation' => 'Use a prepared-local backend and a claim issued to a capable protocol 1.20 worker.',
        ], 409);
    }

    /** @param array<string, mixed>|JsonResponse $reply */
    private function reply(Request $request, array|JsonResponse $reply): JsonResponse
    {
        if ($reply instanceof JsonResponse) {
            return $reply;
        }
        // This is a cursor, not authority. History paging still requires the
        // current namespace, lease owner and workflow claim epoch.
        $reply['history_refresh_page_token'] = WorkflowHistoryPageToken::encode(0);
        try {
            $this->mutations->run(function () use ($reply): void {
                app(ServiceModeTimerDispatcher::class)->dispatchCreatedTaskIds($reply['created_task_ids'] ?? []);
                foreach ($reply['created_task_ids'] ?? [] as $taskId) {
                    $task = WorkflowTask::query()->find($taskId);
                    if ($task instanceof WorkflowTask) {
                        app(LongPollSignalStore::class)->signalTask($task);
                    }
                }
            });
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, true);
        }
        $reason = $reply['reason'] ?? null;
        $ok = $reason === null || isset($reply['cancellation_request']);

        return WorkerProtocol::json($reply, $ok ? 200 : (in_array($reason, ['task_not_found', 'activity_attempt_not_found'], true) ? 404 : 409));
    }
}
