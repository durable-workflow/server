<?php

namespace App\Support;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use Illuminate\Support\Collection;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\RoutingResolver;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\WorkflowMetadata;

class WorkflowStartService
{
    public function __construct(
        private readonly WorkflowControlPlane $controlPlane,
        private readonly ConfiguredWorkflowTypeValidator $workflowTypes,
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
        private readonly WorkflowStartVersionPin $versionPin,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     started: bool,
     *     workflow_id: string,
     *     run_id: string|null,
     *     workflow_type: string,
     *     outcome: string|null,
     *     reason: string|null,
     *     rejection_reason: string|null,
     *     message: string|null,
     * }
     */
    public function start(
        array $validated,
        ?string $namespace = null,
        ?CommandContext $commandContext = null,
        bool $allowAmbientCompatibilityFallback = true,
    ): array {
        $workflowType = (string) $validated['workflow_type'];
        $workflowId = isset($validated['workflow_id']) && is_string($validated['workflow_id'])
            ? $validated['workflow_id']
            : null;
        $taskQueue = $this->resolveTaskQueue($workflowType, $validated['task_queue'] ?? null);

        return $this->startRemoteWorkflow(
            $workflowType,
            $workflowId,
            $taskQueue,
            $validated,
            $namespace,
            $commandContext,
            $allowAmbientCompatibilityFallback,
        );
    }

    public function resolveTaskQueue(string $workflowType, mixed $requestedTaskQueue = null): string
    {
        $this->workflowTypes->assertLoadable($workflowType);

        if (is_string($requestedTaskQueue) && trim($requestedTaskQueue) !== '') {
            return trim($requestedTaskQueue);
        }

        $workflowClass = $this->workflowTypes->resolveWorkflowClass($workflowType);

        if ($workflowClass !== null) {
            $resolvedQueue = RoutingResolver::workflowQueue($workflowClass, new WorkflowMetadata([]));

            if (is_string($resolvedQueue) && trim($resolvedQueue) !== '') {
                return trim($resolvedQueue);
            }
        }

        return $this->defaultTaskQueue();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     started: bool,
     *     workflow_id: string,
     *     run_id: string|null,
     *     workflow_type: string,
     *     outcome: string|null,
     *     reason: string|null,
     *     rejection_reason: string|null,
     *     message: string|null,
     * }
     */
    private function startRemoteWorkflow(
        string $workflowType,
        ?string $workflowId,
        string $taskQueue,
        array $validated,
        ?string $namespace = null,
        ?CommandContext $commandContext = null,
        bool $allowAmbientCompatibilityFallback = true,
    ): array {
        $envelope = AvroPayloadEnvelopeResolver::resolve(
            $validated['input'] ?? null,
            'input',
            $this->externalPayloadStorage->driverFor($namespace),
        );

        // When the client sends no input (or an empty array), emit a
        // default-codec-encoded empty arg list so the run's `arguments`
        // column stays non-null. The default codec is Avro.
        $defaultCodec = PayloadCodecContract::CODEC;
        $arguments = $envelope['blob'] ?? Serializer::serializeWithCodec($defaultCodec, []);
        $payloadCodec = $envelope['codec'] ?? $defaultCodec;

        $startCohort = $namespace !== null
            ? $this->versionPin->resolveForStart($namespace, $taskQueue, $workflowType)
            : [
                'build_id' => null,
                'contract_build_id' => null,
                'contract_scope' => WorkflowStartVersionPin::CONTRACT_SCOPE_NONE,
            ];
        $pinnedBuildId = $startCohort['build_id'];

        $startOptions = array_filter([
            'arguments' => $arguments,
            'payload_codec' => $payloadCodec,
            'queue' => $taskQueue,
            'business_key' => isset($validated['business_key']) && is_string($validated['business_key'])
                ? $validated['business_key']
                : null,
            'search_attributes' => $this->arrayValue($validated, 'search_attributes'),
            'search_attribute_types' => $this->arrayValue($validated, 'search_attribute_types'),
            'memo' => $this->arrayValue($validated, 'memo'),
            'labels' => $this->arrayValue($validated, 'visibility_labels') ?: null,
            'duplicate_start_policy' => $this->controlPlaneDuplicatePolicy($validated['duplicate_policy'] ?? null),
            'execution_timeout_seconds' => $this->intValue($validated, 'execution_timeout_seconds'),
            'run_timeout_seconds' => $this->intValue($validated, 'run_timeout_seconds'),
            // Dispatch-shaping fields are passed through to the control
            // plane so the workflow run (and every workflow/activity task
            // it later spawns) carries the priority + fairness tags. The
            // package's normalizers clamp ranges and lowercase the key.
            'priority' => $this->intValue($validated, 'priority'),
            'fairness_key' => $this->stringValue($validated, 'fairness_key'),
            'fairness_weight' => $this->intValue($validated, 'fairness_weight'),
            'namespace' => $namespace,
            'command_context' => $commandContext,
            'build_id' => $pinnedBuildId,
        ], static fn (mixed $value): bool => $value !== null);

        $result = $this->withAmbientCompatibilityFallback(
            $pinnedBuildId,
            $allowAmbientCompatibilityFallback,
            fn (): array => $this->controlPlane->start($workflowType, $workflowId, $startOptions),
        );

        $started = (bool) ($result['started'] ?? false);
        $reason = isset($result['reason']) && is_string($result['reason'])
            ? $result['reason']
            : null;
        $message = isset($result['message']) && is_string($result['message'])
            ? $result['message']
            : null;

        if ($started) {
            $this->enrichStartedEventWithExternalCommandContract(
                $namespace,
                $taskQueue,
                $workflowType,
                $startCohort['contract_build_id'],
                $startCohort['contract_scope'],
                $result['workflow_run_id'] ?? null,
            );
        }

        return [
            'started' => $started,
            'workflow_id' => $result['workflow_instance_id'],
            'run_id' => $result['workflow_run_id'],
            'workflow_type' => $result['workflow_type'],
            'outcome' => $result['outcome'],
            'reason' => $reason,
            'rejection_reason' => $started ? null : $reason,
            'message' => $message,
        ];
    }

    private function enrichStartedEventWithExternalCommandContract(
        ?string $namespace,
        string $taskQueue,
        string $workflowType,
        ?string $contractBuildId,
        string $contractScope,
        mixed $runId,
    ): void {
        if (! is_string($runId) || trim($runId) === '') {
            return;
        }

        $contract = $this->externalCommandContract(
            $namespace,
            $taskQueue,
            $workflowType,
            $contractBuildId,
            $contractScope,
        );

        if ($contract === null) {
            return;
        }

        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->first();

        if (! $event instanceof WorkflowHistoryEvent) {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];

        if (array_key_exists('declared_signals', $payload)
            || array_key_exists('declared_query_contracts', $payload)
        ) {
            return;
        }

        $event->payload = array_merge($payload, [
            'declared_queries' => $this->stringList($contract['queries'] ?? []),
            'declared_query_contracts' => $this->contractList($contract['query_contracts'] ?? []),
            'declared_signals' => $this->stringList($contract['signals'] ?? []),
            'declared_signal_contracts' => $this->contractList($contract['signal_contracts'] ?? []),
            'declared_updates' => $this->stringList($contract['updates'] ?? []),
            'declared_update_contracts' => $this->contractList($contract['update_contracts'] ?? []),
            ...(array_key_exists(WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD, $contract)
                ? [
                    'declared_update_validators' => $this->stringList(
                        $contract[WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD],
                    ),
                ]
                : []),
            'declared_entry_method' => $this->nullableString($contract['entry_method'] ?? null) ?? 'handle',
            'declared_entry_mode' => $this->nullableString($contract['entry_mode'] ?? null) ?? 'canonical',
            'declared_entry_declaring_class' => $this->nullableString($contract['entry_declaring_class'] ?? null)
                ?? sprintf('external:%s', $workflowType),
        ]);
        $event->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function externalCommandContract(
        ?string $namespace,
        string $taskQueue,
        string $workflowType,
        ?string $contractBuildId,
        string $contractScope,
    ): ?array {
        if ($namespace === null || trim($namespace) === '') {
            return null;
        }

        if ($contractScope === WorkflowStartVersionPin::CONTRACT_SCOPE_NONE) {
            return null;
        }

        /** @var Collection<int, WorkerRegistration> $workers */
        $workers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->orderByDesc('last_heartbeat_at')
            ->orderByDesc('id')
            ->get();

        foreach ($workers as $worker) {
            if (! $this->workerCanClaimStartedContract($worker, $contractBuildId, $contractScope)) {
                continue;
            }

            $supportedTypes = $this->stringList($worker->supported_workflow_types ?? []);

            if (! in_array($workflowType, $supportedTypes, true)) {
                continue;
            }

            $contracts = $worker->workflow_command_contracts ?? [];

            if (! is_array($contracts) || ! is_array($contracts[$workflowType] ?? null)) {
                continue;
            }

            return $contracts[$workflowType];
        }

        return null;
    }

    private function workerCanClaimStartedContract(
        WorkerRegistration $worker,
        ?string $contractBuildId,
        string $contractScope,
    ): bool {
        if (! $this->workerIsActive($worker) || ! $this->workerIsFresh($worker)) {
            return false;
        }

        return match ($contractScope) {
            WorkflowStartVersionPin::CONTRACT_SCOPE_BUILD_ID => $contractBuildId !== null
                && $this->workerBuildId($worker) === $contractBuildId,
            WorkflowStartVersionPin::CONTRACT_SCOPE_UNVERSIONED => $this->workerBuildId($worker) === null,
            default => false,
        };
    }

    private function workerIsActive(WorkerRegistration $worker): bool
    {
        $status = $this->nullableString($worker->status);

        return $status === null || $status === WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE;
    }

    private function workerIsFresh(WorkerRegistration $worker): bool
    {
        $heartbeat = $worker->last_heartbeat_at;

        if (! $heartbeat instanceof \DateTimeInterface) {
            return true;
        }

        return $heartbeat->getTimestamp() >= now()
            ->subSeconds($this->workerStaleAfterSeconds())
            ->getTimestamp();
    }

    private function workerBuildId(WorkerRegistration $worker): ?string
    {
        return $this->nullableString($worker->build_id);
    }

    private function workerStaleAfterSeconds(): int
    {
        $configured = config('server.workers.stale_after_seconds');
        $pollingTimeout = config('server.polling.timeout');

        return StandaloneWorkerVisibility::staleAfterSeconds(
            is_numeric($configured) ? (int) $configured : null,
            is_numeric($pollingTimeout) ? (int) $pollingTimeout : null,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        $strings = array_values(array_unique($strings));
        sort($strings);

        return $strings;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contractList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $contract): bool => is_array($contract)
                && is_string($contract['name'] ?? null)
                && trim($contract['name']) !== ''
                && is_array($contract['parameters'] ?? null),
        ));
    }

    private function controlPlaneDuplicatePolicy(?string $policy): string
    {
        return match ($policy) {
            'use-existing' => 'return_existing_active',
            default => 'reject_duplicate',
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int|string, mixed>
     */
    private function arrayValue(array $validated, string $key): array
    {
        $value = $validated[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function intValue(array $validated, string $key): ?int
    {
        $value = $validated[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function stringValue(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function defaultTaskQueue(): string
    {
        $connection = config('queue.default');

        if (! is_string($connection) || trim($connection) === '') {
            return 'default';
        }

        $queue = config('queue.connections.'.trim($connection).'.queue', 'default');

        return is_string($queue) && trim($queue) !== ''
            ? trim($queue)
            : 'default';
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withAmbientCompatibilityFallback(
        ?string $explicitBuildId,
        bool $allowFallback,
        callable $callback,
    ): mixed {
        if ($explicitBuildId !== null || $allowFallback) {
            return $callback();
        }

        $previousCurrent = config('workflows.v2.compatibility.current');

        config(['workflows.v2.compatibility.current' => null]);

        try {
            return $callback();
        } finally {
            config(['workflows.v2.compatibility.current' => $previousCurrent]);
        }
    }
}
