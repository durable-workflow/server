<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\WorkflowQueryContract;

final class WorkflowQueryTaskBroker
{
    private const CACHE_PREFIX = 'server:workflow-query-task:';

    private const QUERY_TASKS_CAPABILITY = 'query_tasks';

    private const COMPATIBILITY_SCOPE_UNVERSIONED = 'unversioned';

    public function __construct(
        private readonly ServerPollingCache $cache,
        private readonly LongPoller $longPoller,
        private readonly LongPollSignalStore $signals,
        private readonly ExternalPayloadEnvelopeService $payloadEnvelopes,
        private readonly QueryTaskPollRequestStore $pollRequests,
    ) {}

    public function hasWorkerFor(string $namespace, WorkflowRun $run): bool
    {
        return $this->registeredQueryRoute($namespace, $run)['servable'];
    }

    /**
     * @param  array<string, mixed>  $queryArguments
     * @return array<string, mixed>
     */
    public function query(
        string $namespace,
        WorkflowRun $run,
        string $queryName,
        array $queryArguments,
        ?CommandContext $commandContext = null,
    ): array {
        if ($run->status->isTerminal() && $run->status !== RunStatus::Completed) {
            return $this->queryFailed(
                $run,
                $queryName,
                'run_not_active',
                sprintf(
                    'Workflow query [%s] cannot execute because run [%s] is terminal with status [%s].',
                    $queryName,
                    $run->id,
                    $run->status->value,
                ),
                409,
                extra: [
                    'run_status' => $run->status->value,
                    'is_terminal' => true,
                ],
            );
        }

        $validatedQuery = $this->validateContractedQueryArguments($namespace, $run, $queryName, $queryArguments);

        if (($validatedQuery['failed'] ?? false) === true) {
            return $this->queryFailed(
                $run,
                $queryName,
                (string) $validatedQuery['reason'],
                (string) $validatedQuery['message'],
                (int) $validatedQuery['status'],
                $this->validationErrors($validatedQuery['validation_errors'] ?? null),
            );
        }

        if (is_array($validatedQuery['query_arguments'] ?? null)) {
            /** @var array{codec: string, blob: string} $queryArguments */
            $queryArguments = $validatedQuery['query_arguments'];
        }

        $route = $this->awaitRegisteredQueryRoute($namespace, $run);

        if (! $route['servable']) {
            return $this->queryFailed(
                $run,
                $queryName,
                $route['reason'] ?? 'query_worker_unavailable',
                $route['message'] ?? sprintf(
                    'No compatible query-capable worker is available for workflow type [%s] on task queue [%s].',
                    $this->stringValue($run->workflow_type) ?? 'unknown',
                    $this->taskQueue($run),
                ),
                409,
            );
        }

        try {
            $task = $this->enqueue($namespace, $run, $queryName, $queryArguments, $commandContext);
        } catch (QueryTaskQueueFullException $exception) {
            return $this->queryFailed(
                $run,
                $queryName,
                'query_task_queue_full',
                $exception->getMessage(),
                429,
            );
        } catch (QueryTaskQueueUnavailableException $exception) {
            return $this->queryFailed(
                $run,
                $queryName,
                'query_task_queue_unavailable',
                $exception->getMessage(),
                503,
            );
        }

        Log::info('workflow_query_task_enqueued', [
            'namespace' => $namespace,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'query_name' => $queryName,
            'query_task_id' => $task['query_task_id'] ?? null,
            'task_queue' => $task['task_queue'] ?? null,
        ]);

        $this->releaseDatabaseConnectionBeforeResultWait($run);
        $result = $this->waitForResult((string) $task['query_task_id']);

        Log::info('workflow_query_result_wait_finished', [
            'namespace' => $namespace,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'query_name' => $queryName,
            'query_task_id' => $task['query_task_id'] ?? null,
            'query_task_status' => $result['status'] ?? null,
        ]);

        if (($result['status'] ?? null) === 'completed') {
            $resultEnvelope = is_array($result['result_envelope'] ?? null)
                ? $this->resultEnvelope($namespace, $result['result_envelope'])
                : null;

            $payload = [
                'success' => true,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_id' => $run->workflow_instance_id,
                'run_id' => $run->id,
                'target_scope' => 'instance',
                'query_name' => $queryName,
                'result' => $result['result'] ?? null,
                'result_envelope' => $resultEnvelope,
                'reason' => null,
                'status' => 200,
            ];

            $principal = $this->taskPrincipal($task);
            if ($principal !== null) {
                $payload['principal'] = $principal;
            }

            return $payload;
        }

        if (($result['status'] ?? null) === 'failed') {
            $message = $this->stringValue($result['message'] ?? null) ?? 'Query failed on the worker.';
            $failureType = $this->stringValue($result['failure_type'] ?? null);

            return $this->queryFailed(
                $run,
                $queryName,
                $this->stringValue($result['reason'] ?? null) ?? 'query_rejected',
                $message,
                (int) ($result['http_status'] ?? 409),
                $this->validationErrors($result['validation_errors'] ?? null),
                array_filter([
                    'error_type' => $failureType,
                    'failure_type' => $failureType,
                    'service_error_type' => $failureType,
                    'caller_observed_error_type' => $failureType,
                    'typed_error_message' => $failureType !== null ? $message : null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        $timeoutTask = is_array($result) ? $result : $task;
        $timeout = $this->timeoutFailure($namespace, $run, $queryName, $timeoutTask);

        $this->markTimedOut((string) $task['query_task_id']);

        return $this->queryFailed(
            $run,
            $queryName,
            $timeout['reason'],
            $timeout['message'],
            $timeout['status'],
        );
    }

    /**
     * @param  array<string, mixed>  $queryArguments
     * @return array<string, mixed>
     */
    private function validateContractedQueryArguments(
        string $namespace,
        WorkflowRun $run,
        string $queryName,
        array $queryArguments,
    ): array {
        $contract = RunCommandContract::forRun($run);

        if (($contract['queries'] ?? []) === [] && ($contract['query_contracts'] ?? []) === []) {
            return ['failed' => false];
        }

        if (WorkflowQueryContract::resolveTargetForRun($run, $queryName) === null) {
            return [
                'failed' => true,
                'reason' => 'rejected_unknown_query',
                'message' => sprintf('Workflow query [%s] is not declared on workflow [%s].', $queryName, $run->workflow_instance_id),
                'status' => 404,
                'validation_errors' => [],
            ];
        }

        $codec = is_string($queryArguments['codec'] ?? null)
            ? $queryArguments['codec']
            : PayloadCodecContract::CODEC;
        $blob = $queryArguments['blob'] ?? null;

        if ($this->isEmptyQueryInput($queryArguments)) {
            $arguments = [];
        } else {
            if (! is_string($blob)) {
                if (! array_key_exists('external_storage', $queryArguments)) {
                    return [
                        'failed' => true,
                        'reason' => 'invalid_query_arguments',
                        'message' => sprintf('Workflow query [%s] arguments are missing a payload blob.', $queryName),
                        'status' => 422,
                        'validation_errors' => [
                            'arguments' => ['Query arguments must be encoded as a payload blob.'],
                        ],
                    ];
                }
            }

            $resolvedEnvelope = $this->resolveContractedQueryArgumentsEnvelope($namespace, $queryName, $queryArguments);

            if (($resolvedEnvelope['failed'] ?? false) === true) {
                return $resolvedEnvelope;
            }

            $codec = is_string($resolvedEnvelope['codec'] ?? null)
                ? $resolvedEnvelope['codec']
                : $codec;
            $blob = $resolvedEnvelope['blob'] ?? null;

            if (! is_string($blob)) {
                return [
                    'failed' => true,
                    'reason' => 'invalid_query_arguments',
                    'message' => sprintf('Workflow query [%s] arguments are missing a payload blob.', $queryName),
                    'status' => 422,
                    'validation_errors' => [
                        'arguments' => ['Query arguments must be encoded as a payload blob.'],
                    ],
                ];
            }

            try {
                $arguments = Serializer::unserializeWithCodec($codec, $blob);
            } catch (\Throwable) {
                return [
                    'failed' => true,
                    'reason' => 'invalid_query_arguments',
                    'message' => sprintf('Workflow query [%s] arguments could not be decoded.', $queryName),
                    'status' => 422,
                    'validation_errors' => [
                        'arguments' => ['Query arguments could not be decoded.'],
                    ],
                ];
            }
        }

        if (! is_array($arguments)) {
            return [
                'failed' => true,
                'reason' => 'invalid_query_arguments',
                'message' => sprintf('Workflow query [%s] arguments must decode to an array.', $queryName),
                'status' => 422,
                'validation_errors' => [
                    'arguments' => ['Query arguments must decode to an array.'],
                ],
            ];
        }

        $validated = WorkflowQueryContract::validatedArgumentsForRun($run, $queryName, $arguments);
        $validationErrors = $this->validationErrors($validated['validation_errors'] ?? null);

        if ($validationErrors !== []) {
            return [
                'failed' => true,
                'reason' => 'invalid_query_arguments',
                'message' => sprintf('Workflow query [%s] argument validation failed.', $queryName),
                'status' => 422,
                'validation_errors' => $validationErrors,
            ];
        }

        return [
            'failed' => false,
            'query_arguments' => [
                'codec' => $codec,
                'blob' => Serializer::serializeWithCodec($codec, $validated['arguments']),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $queryArguments
     * @return array{codec: string, blob: string}|array<string, mixed>
     */
    private function resolveContractedQueryArgumentsEnvelope(
        string $namespace,
        string $queryName,
        array $queryArguments,
    ): array {
        try {
            $externalStorage = app(NamespaceExternalPayloadStorage::class)->driverFor($namespace);

            if (array_key_exists('external_storage', $queryArguments)) {
                return AvroPayloadEnvelopeResolver::resolve($queryArguments, 'query_arguments', $externalStorage);
            }

            $codec = is_string($queryArguments['codec'] ?? null)
                ? $queryArguments['codec']
                : PayloadCodecContract::CODEC;
            $blob = $queryArguments['blob'] ?? null;

            if (! is_string($blob)) {
                return [
                    'codec' => $codec,
                    'blob' => $blob,
                ];
            }

            if (! ExternalPayloads::isStoredReference($blob)) {
                return [
                    'codec' => $codec,
                    'blob' => $blob,
                ];
            }

            $blob = ExternalPayloads::resolveStoredPayload($blob, $codec, $namespace, $externalStorage);

            return [
                'codec' => PayloadCodecContract::canonicalize($codec),
                'blob' => $blob,
            ];
        } catch (ValidationException $exception) {
            return [
                'failed' => true,
                'reason' => 'invalid_query_arguments',
                'message' => sprintf('Workflow query [%s] arguments could not be resolved.', $queryName),
                'status' => 422,
                'validation_errors' => $exception->errors(),
            ];
        } catch (\Throwable $exception) {
            return [
                'failed' => true,
                'reason' => 'invalid_query_arguments',
                'message' => sprintf('Workflow query [%s] arguments could not be resolved.', $queryName),
                'status' => 422,
                'validation_errors' => [
                    'query_arguments' => [$exception->getMessage()],
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $queryArguments
     */
    private function isEmptyQueryInput(array $queryArguments): bool
    {
        if ($queryArguments === []) {
            return true;
        }

        return array_key_exists('blob', $queryArguments)
            && $queryArguments['blob'] === null
            && (! array_key_exists('codec', $queryArguments) || $queryArguments['codec'] === null);
    }

    private function markTimedOut(string $queryTaskId): void
    {
        $task = $this->task($queryTaskId);

        if (! is_array($task)) {
            return;
        }

        if (in_array($task['status'] ?? null, ['completed', 'failed'], true)) {
            return;
        }

        $task['status'] = 'timed_out';
        $task['timed_out_at'] = now()->toJSON();

        $this->forgetLeasedTask($task);
        $this->putTask($task);
        $this->store()->forget($this->leaseKey($queryTaskId));
        $this->signals->signalQueryTaskResult($queryTaskId);
    }

    /**
     * @param  array{codec: string, blob: string}  $queryArguments
     * @return array<string, mixed>
     */
    public function enqueue(
        string $namespace,
        WorkflowRun $run,
        string $queryName,
        array $queryArguments,
        ?CommandContext $commandContext = null,
    ): array {
        $queryTaskId = Str::ulid()->toBase32();
        $taskQueue = $this->taskQueue($run);
        $commandContextAttributes = $commandContext?->attributes();
        $principal = $this->commandContextPrincipal($commandContextAttributes);
        $compatibilityScope = $this->queryTaskCompatibilityScope($run);
        $historyObservedSequence = (int) ($run->last_history_sequence ?? 0);
        $historyCutoffSequence = $this->hasActiveWorkflowTaskLeaseForRun((string) $run->id)
            ? $historyObservedSequence
            : null;
        $task = [
            'query_task_id' => $queryTaskId,
            'status' => 'pending',
            'namespace' => $namespace,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'workflow_type' => $run->workflow_type,
            'workflow_definition_fingerprint' => $this->recordedWorkflowDefinitionFingerprint($run),
            'compatibility' => $this->stringValue($run->compatibility),
            'task_queue' => $taskQueue,
            'payload_codec' => PayloadCodecContract::canonicalize($run->payload_codec),
            'query_name' => $queryName,
            'query_arguments' => $queryArguments,
            'attempt_count' => 0,
            'created_at' => now()->toJSON(),
            'history_observed_sequence' => $historyObservedSequence,
        ];

        if ($historyCutoffSequence !== null) {
            $task['history_cutoff_sequence'] = $historyCutoffSequence;
        }

        if ($compatibilityScope !== null) {
            $task['compatibility_scope'] = $compatibilityScope;
        }

        if ($commandContextAttributes !== null) {
            $task['command_context'] = $commandContextAttributes;
        }

        if ($principal !== null) {
            $task['principal'] = $principal;
        }

        $this->putTask($task);

        try {
            $this->appendPendingTask($namespace, $taskQueue, $queryTaskId);
        } catch (QueryTaskQueueFullException|QueryTaskQueueUnavailableException $exception) {
            $this->store()->forget($this->taskKey($queryTaskId));

            throw $exception;
        }

        $this->signals->signalQueryTaskQueue($namespace, $taskQueue);

        return $task;
    }

    public function wakeTaskQueue(string $namespace, ?string $taskQueue): void
    {
        $taskQueue = $this->stringValue($taskQueue);

        if ($taskQueue === null) {
            return;
        }

        $this->signals->signalQueryTaskQueue($namespace, $taskQueue);
    }

    /**
     * Return the authoritative replay snapshot shared by read-only query and
     * pre-accept update-validation tasks.
     *
     * @return array<string, mixed>
     */
    public function workflowReplayPayload(string $namespace, WorkflowRun $run): array
    {
        return [
            'workflow_class' => $run->workflow_class,
            'workflow_arguments' => $run->argumentsEnvelope(),
            'run_status' => $run->status->value,
            'last_history_sequence' => (int) ($run->last_history_sequence ?? 0),
            'history_events' => $this->historyEvents($run),
            'history_export' => $this->historyExport($run, null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function poll(
        string $namespace,
        WorkerRegistration $worker,
        ?string $pollRequestId = null,
        ?int $timeoutSeconds = null,
    ): ?array {
        return $this->pollResult($namespace, $worker, $pollRequestId, $timeoutSeconds)['task'];
    }

    /**
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    public function pollResult(
        string $namespace,
        WorkerRegistration $worker,
        ?string $pollRequestId = null,
        ?int $timeoutSeconds = null,
    ): array {
        $pollRequestId = $this->stringValue($pollRequestId);
        $taskQueue = (string) $worker->task_queue;
        $buildId = $this->stringValue($worker->build_id);
        $supportedWorkflowTypes = $this->stringArray($worker->supported_workflow_types);
        $workflowDefinitionFingerprints = $this->fingerprintMap($worker->workflow_definition_fingerprints);

        if (! WorkerPollFence::isFresh($worker)) {
            return [
                'task' => null,
                'poll_status' => 'stale_worker_registration',
            ];
        }

        $workerPollFence = WorkerPollFence::snapshot($worker);

        $this->rememberQueryTaskPollingWorker($namespace, $taskQueue, $worker->worker_id, $timeoutSeconds);

        if ($pollRequestId !== null) {
            $this->pollRequests->markCurrentIfFresh($namespace, $taskQueue, $buildId, $worker->worker_id, $pollRequestId);

            return $this->coordinatedPollResult(
                $namespace,
                $taskQueue,
                $buildId,
                $worker->worker_id,
                $pollRequestId,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $this->queryPollTimeoutSeconds($timeoutSeconds),
                $workerPollFence,
            );
        }

        return $this->pollResultFromValue($this->performPoll(
            $namespace,
            $taskQueue,
            $worker->worker_id,
            $supportedWorkflowTypes,
            $workflowDefinitionFingerprints,
            $buildId,
            timeoutSeconds: $this->queryPollTimeoutSeconds($timeoutSeconds),
            workerPollFence: $workerPollFence,
        ));
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function coordinatedPollResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?int $timeoutSeconds,
        array $workerPollFence,
    ): array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (! WorkerPollFence::isCurrent($workerPollFence)) {
                return [
                    'task' => null,
                    'poll_status' => 'stale_worker_registration',
                ];
            }

            $cached = $this->cachedPollResult($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);

            if ($cached['resolved']) {
                return $this->pollResultFromCached($cached);
            }

            if ($this->pollRequests->tryStart($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId)) {
                return $this->runCoordinatedPollLeaderResult(
                    $namespace,
                    $taskQueue,
                    $buildId,
                    $leaseOwner,
                    $pollRequestId,
                    $supportedWorkflowTypes,
                    $workflowDefinitionFingerprints,
                    $timeoutSeconds,
                    $workerPollFence,
                );
            }

            $observed = $this->pollRequests->waitForResult(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
            );

            if ($observed['resolved']) {
                if (! WorkerPollFence::isCurrent($workerPollFence)) {
                    return [
                        'task' => null,
                        'poll_status' => 'stale_worker_registration',
                    ];
                }

                return $this->pollResultFromCached($observed);
            }
        }

        if (! WorkerPollFence::isCurrent($workerPollFence)) {
            return [
                'task' => null,
                'poll_status' => 'stale_worker_registration',
            ];
        }

        return $this->pollResultFromCached(
            $this->cachedPollResult($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId),
        );
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function runCoordinatedPollLeaderResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?int $timeoutSeconds,
        array $workerPollFence,
    ): array {
        try {
            $task = $this->performPoll(
                $namespace,
                $taskQueue,
                $leaseOwner,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $buildId,
                $pollRequestId,
                $timeoutSeconds,
                $workerPollFence,
            );
        } catch (\Throwable $exception) {
            $this->pollRequests->forgetPending($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);

            throw $exception;
        }

        $pollResult = $this->pollResultFromValue($task);

        $this->pollRequests->rememberResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
            $pollResult['task'],
            $pollResult['poll_status'],
        );

        return $pollResult;
    }

    /**
     * @param  array{resolved?: bool, task?: array<string, mixed>|null, poll_status?: string|null}  $cached
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function pollResultFromCached(array $cached): array
    {
        $task = $cached['task'] ?? null;
        $pollStatus = $this->stringValue($cached['poll_status'] ?? null);

        return [
            'task' => is_array($task) ? $task : null,
            'poll_status' => $pollStatus ?? (is_array($task) ? 'leased' : 'empty'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $task
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function pollResultFromValue(?array $task): array
    {
        if (is_array($task) && ! array_key_exists('query_task_id', $task)) {
            return [
                'task' => null,
                'poll_status' => $this->stringValue($task['poll_status'] ?? null) ?? 'empty',
            ];
        }

        return [
            'task' => $task,
            'poll_status' => is_array($task) ? 'leased' : 'empty',
        ];
    }

    /**
     * @return array{resolved: bool, task: array<string, mixed>|null, poll_status: string|null}
     */
    private function cachedPollResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): array {
        $cached = $this->pollRequests->result($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);

        if (! $cached['resolved']) {
            return $cached;
        }

        if ($this->cachedTaskStillDeliverable($namespace, $taskQueue, $buildId, $leaseOwner, $cached['task'])) {
            return $cached;
        }

        $this->pollRequests->rememberResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
            null,
            'empty',
        );

        return [
            'resolved' => true,
            'task' => null,
            'poll_status' => 'empty',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function cachedTaskStillDeliverable(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        ?array $task,
    ): bool {
        if ($task === null) {
            return true;
        }

        $queryTaskId = $this->stringValue($task['query_task_id'] ?? null);

        if ($queryTaskId === null) {
            return false;
        }

        if (($task['task_queue'] ?? null) !== $taskQueue || ($task['lease_owner'] ?? null) !== $leaseOwner) {
            return false;
        }

        $current = $this->task($queryTaskId);

        if (! is_array($current) || ($current['status'] ?? null) !== 'leased') {
            return false;
        }

        if (
            ($current['namespace'] ?? null) !== $namespace
            || ($current['task_queue'] ?? null) !== $taskQueue
            || ($current['lease_owner'] ?? null) !== $leaseOwner
        ) {
            return false;
        }

        if (! $this->matchesCompatibility(
            $buildId,
            $current['compatibility'] ?? ($task['compatibility'] ?? null),
            $current['compatibility_scope'] ?? ($task['compatibility_scope'] ?? null),
        )) {
            return false;
        }

        $leaseExpiresAt = $this->timestamp($current['lease_expires_at'] ?? null);

        if (! $leaseExpiresAt instanceof Carbon || $leaseExpiresAt->lte(now())) {
            return false;
        }

        return (int) ($current['attempt_count'] ?? 0) === (int) ($task['query_task_attempt'] ?? 0);
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function performPoll(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId = null,
        ?string $pollRequestId = null,
        ?int $timeoutSeconds = null,
        array $workerPollFence = [],
    ): ?array {
        $workflowBlockStatus = null;

        $result = $this->longPoller->until(
            function () use ($namespace, $taskQueue, $leaseOwner, $supportedWorkflowTypes, $workflowDefinitionFingerprints, $buildId, $pollRequestId, $workerPollFence, &$workflowBlockStatus): ?array {
                if ($workerPollFence !== [] && ! WorkerPollFence::isCurrent($workerPollFence)) {
                    return ['poll_status' => 'stale_worker_registration'];
                }

                if (
                    $pollRequestId !== null
                    && ! $this->pollRequests->isCurrent($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId)
                ) {
                    return ['poll_status' => 'superseded'];
                }

                if ($pollRequestId !== null) {
                    $activeLease = $this->activeLeasedTaskForPoller(
                        $namespace,
                        $taskQueue,
                        $leaseOwner,
                        $supportedWorkflowTypes,
                        $workflowDefinitionFingerprints,
                        $buildId,
                        $pollRequestId,
                    );

                    if (is_array($activeLease)) {
                        return $this->queryTaskPayload($activeLease);
                    }
                }

                $workflowBlockStatus = $this->pendingTaskWorkflowBlockStatus(
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $supportedWorkflowTypes,
                    $workflowDefinitionFingerprints,
                    $buildId,
                );

                if ($workflowBlockStatus === 'workflow_task_pending') {
                    return ['poll_status' => $workflowBlockStatus];
                }

                if ($workflowBlockStatus === 'workflow_task_leased') {
                    return null;
                }

                return $this->claimNext(
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $supportedWorkflowTypes,
                    $workflowDefinitionFingerprints,
                    $buildId,
                    $pollRequestId,
                    $workerPollFence,
                );
            },
            static fn (?array $task): bool => $task !== null,
            timeoutSeconds: $timeoutSeconds,
            wakeChannels: $this->signals->queryTaskPollChannels($namespace, $taskQueue),
            reserveWorkerWaitSlot: true,
            waitSlotPool: 'query-task',
            waitSlotNamespace: $namespace,
        );

        if ($result === null && $workflowBlockStatus === 'workflow_task_leased') {
            return ['poll_status' => $workflowBlockStatus];
        }

        return in_array(
            $result['poll_status'] ?? null,
            ['superseded', 'stale_worker_registration'],
            true,
        ) ? null : $result;
    }

    /**
     * @param  array{codec: string, blob: string}|null  $resultEnvelope
     * @return array<string, mixed>
     */
    public function complete(
        string $namespace,
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
        mixed $result,
        ?array $resultEnvelope,
    ): array {
        $task = $this->task($queryTaskId);
        $guard = $this->guardLease($task, $namespace, $queryTaskId, $leaseOwner, $queryTaskAttempt);

        if ($guard !== null) {
            return $guard;
        }

        $task['status'] = 'completed';
        $task['result'] = $result;
        $task['result_envelope'] = $resultEnvelope;
        $task['completed_at'] = now()->toJSON();

        $this->forgetLeasedTask($task);
        $this->putTask($task);
        $this->signals->signalQueryTaskResult($queryTaskId);

        return [
            'query_task_id' => $queryTaskId,
            'query_task_attempt' => $queryTaskAttempt,
            'outcome' => 'completed',
            'reason' => null,
            'status' => 200,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function guardCompletion(
        string $namespace,
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
    ): ?array {
        return $this->guardLease(
            $this->task($queryTaskId),
            $namespace,
            $queryTaskId,
            $leaseOwner,
            $queryTaskAttempt,
        );
    }

    /**
     * @param  array<string, mixed>  $failure
     * @return array<string, mixed>
     */
    public function fail(
        string $namespace,
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
        array $failure,
    ): array {
        $task = $this->task($queryTaskId);
        $guard = $this->guardLease($task, $namespace, $queryTaskId, $leaseOwner, $queryTaskAttempt);

        if ($guard !== null) {
            return $guard;
        }

        $reason = $this->stringValue($failure['reason'] ?? null) ?? 'query_rejected';
        $validationErrors = $this->validationErrors($failure['validation_errors'] ?? null);
        $task['status'] = 'failed';
        $task['reason'] = $reason;
        $task['message'] = $this->stringValue($failure['message'] ?? null) ?? 'Query failed on the worker.';
        $task['failure_type'] = $this->stringValue($failure['type'] ?? null);
        $task['validation_errors'] = $validationErrors;
        $task['http_status'] = match ($reason) {
            'rejected_unknown_query' => 404,
            'invalid_query_arguments' => 422,
            default => 409,
        };
        $task['failed_at'] = now()->toJSON();

        $this->forgetLeasedTask($task);
        $this->putTask($task);
        $this->signals->signalQueryTaskResult($queryTaskId);

        return [
            'query_task_id' => $queryTaskId,
            'query_task_attempt' => $queryTaskAttempt,
            'outcome' => 'failed',
            'reason' => $reason,
            'validation_errors' => $validationErrors === [] ? null : $validationErrors,
            'status' => 200,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function task(string $queryTaskId): ?array
    {
        $task = $this->store()->get($this->taskKey($queryTaskId));

        return is_array($task) ? $task : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function waitForResult(string $queryTaskId): ?array
    {
        return $this->longPoller->until(
            fn (): ?array => $this->taskForResultWait($queryTaskId),
            static function (?array $task): bool {
                $status = $task['status'] ?? null;

                return $status === 'completed' || $status === 'failed';
            },
            $this->queryTimeoutSeconds(),
            null,
            [$this->signals->queryTaskResultChannel($queryTaskId)],
        );
    }

    /**
     * A public query waits on shared cache state after its task is enqueued.
     * Keeping the request's database session attached during that wait can
     * retain a backend read lock and make the query responder's mandatory
     * heartbeat wait on the very query it is meant to answer.
     */
    private function releaseDatabaseConnectionBeforeResultWait(WorkflowRun $run): void
    {
        $connectionName = $run->getConnectionName();
        $connection = DB::connection($connectionName);

        if ($connection->transactionLevel() === 0) {
            DB::disconnect($connectionName);
        }
    }

    /**
     * Requeue a query lease as soon as its worker registration is stale. Query
     * tasks live in the polling cache rather than the workflow-task table, so
     * they need their own fenced recovery path instead of waiting for the
     * longer query lease TTL.
     *
     * @return array<string, mixed>|null
     */
    private function taskForResultWait(string $queryTaskId): ?array
    {
        $task = $this->task($queryTaskId);

        if (! is_array($task) || ($task['status'] ?? null) !== 'leased') {
            return $task;
        }

        $namespace = $this->stringValue($task['namespace'] ?? null);
        $taskQueue = $this->stringValue($task['task_queue'] ?? null);
        $leaseOwner = $this->stringValue($task['lease_owner'] ?? null);

        if ($namespace === null || $taskQueue === null || $leaseOwner === null) {
            return $task;
        }

        $owner = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('worker_id', $leaseOwner)
            ->first();

        if ($owner instanceof WorkerRegistration && WorkerPollFence::isFresh($owner)) {
            return $task;
        }

        $recovered = $this->withQueueLock(
            $namespace,
            $taskQueue,
            function () use ($namespace, $taskQueue, $leaseOwner, $queryTaskId, $task): bool {
                $current = $this->task($queryTaskId);

                if (! is_array($current)
                    || ($current['status'] ?? null) !== 'leased'
                    || ($current['lease_owner'] ?? null) !== $leaseOwner
                    || (int) ($current['attempt_count'] ?? 0) !== (int) ($task['attempt_count'] ?? 0)
                ) {
                    return false;
                }

                $owner = WorkerRegistration::query()
                    ->where('namespace', $namespace)
                    ->where('worker_id', $leaseOwner)
                    ->first();

                if ($owner instanceof WorkerRegistration && WorkerPollFence::isFresh($owner)) {
                    return false;
                }

                if ($owner instanceof WorkerRegistration) {
                    $owner->forceFill(['status' => WorkerRegistration::STATUS_SUPERSEDED])->save();
                }

                $this->forgetLeasedTask($current);
                $current['status'] = 'pending';
                $current['abandoned_lease_owner'] = $leaseOwner;
                $current['abandoned_lease_attempt'] = (int) ($current['attempt_count'] ?? 0);
                $current['recovered_at'] = now()->toJSON();
                unset($current['lease_owner'], $current['lease_expires_at'], $current['leased_at']);

                $this->putTask($current);
                $this->store()->forget($this->leaseKey($queryTaskId));
                $pending = $this->pendingTaskIds($namespace, $taskQueue);
                $pending[] = $queryTaskId;
                $this->storePendingTaskIds($namespace, $taskQueue, array_values(array_unique($pending)));

                return true;
            },
        );

        if ($recovered) {
            $this->signals->signalQueryTaskQueue($namespace, $taskQueue);
        }

        return $this->task($queryTaskId);
    }

    /**
     * @return array{
     *     servable: bool,
     *     reason: string|null,
     *     message: string|null,
     *     task_queue: string,
     *     active_worker_count: int,
     *     query_capable_worker_count: int,
     *     workflow_type_worker_count: int,
     *     compatible_worker_count: int
     * }
     */
    public function queryRoute(string $namespace, WorkflowRun $run): array
    {
        return $this->resolveQueryRoute($namespace, $run, requirePollingWorker: true);
    }

    /**
     * @return array{
     *     servable: bool,
     *     reason: string|null,
     *     message: string|null,
     *     task_queue: string,
     *     active_worker_count: int,
     *     query_capable_worker_count: int,
     *     workflow_type_worker_count: int,
     *     compatible_worker_count: int
     * }
     */
    private function registeredQueryRoute(string $namespace, WorkflowRun $run): array
    {
        return $this->resolveQueryRoute($namespace, $run, requirePollingWorker: false);
    }

    /**
     * @return array{
     *     servable: bool,
     *     reason: string|null,
     *     message: string|null,
     *     task_queue: string,
     *     active_worker_count: int,
     *     query_capable_worker_count: int,
     *     workflow_type_worker_count: int,
     *     compatible_worker_count: int
     * }
     */
    private function resolveQueryRoute(string $namespace, WorkflowRun $run, bool $requirePollingWorker): array
    {
        $taskQueue = $this->taskQueue($run);
        $workflowType = $this->stringValue($run->workflow_type);
        $recordedFingerprint = $this->recordedWorkflowDefinitionFingerprint($run);
        $compatibility = $this->stringValue($run->compatibility);
        $compatibilityScope = $this->queryTaskCompatibilityScope($run);

        $activeWorkers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->where('status', 'active')
            ->get()
            ->filter(fn (WorkerRegistration $worker): bool => $this->workerCanRouteQuery($namespace, $worker))
            ->values();

        if ($activeWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_unavailable',
                sprintf('No active worker is registered on task queue [%s].', $taskQueue),
                $taskQueue,
                0,
                0,
                0,
                0,
            );
        }

        $queryWorkers = $activeWorkers
            ->filter(fn (WorkerRegistration $worker): bool => $this->workerSupportsQueryTasks($namespace, $worker))
            ->values();

        if ($queryWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_unavailable',
                sprintf(
                    'Active workers are registered on task queue [%s], but none are accepting workflow query tasks.',
                    $taskQueue,
                ),
                $taskQueue,
                $activeWorkers->count(),
                0,
                0,
                0,
            );
        }

        $typeWorkers = $queryWorkers
            ->filter(fn (WorkerRegistration $worker): bool => $this->matchesWorkflowType(
                $this->stringArray($worker->supported_workflow_types),
                $workflowType,
            ))
            ->values();

        if ($typeWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_incompatible',
                sprintf(
                    'Query-capable workers on task queue [%s] do not advertise workflow type [%s].',
                    $taskQueue,
                    $workflowType ?? 'unknown',
                ),
                $taskQueue,
                $activeWorkers->count(),
                $queryWorkers->count(),
                0,
                0,
            );
        }

        $buildCompatibleWorkers = $typeWorkers
            ->filter(fn (WorkerRegistration $worker): bool => $this->matchesCompatibility(
                $this->stringValue($worker->build_id),
                $compatibility,
                $compatibilityScope,
            ))
            ->values();

        if ($buildCompatibleWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_incompatible',
                sprintf(
                    'Query-capable workers on task queue [%s] support workflow type [%s], but none match run compatibility [%s].',
                    $taskQueue,
                    $workflowType ?? 'unknown',
                    $this->compatibilityLabel($compatibility, $compatibilityScope),
                ),
                $taskQueue,
                $activeWorkers->count(),
                $queryWorkers->count(),
                $typeWorkers->count(),
                0,
            );
        }

        $compatibleWorkers = $buildCompatibleWorkers
            ->filter(fn (WorkerRegistration $worker): bool => $this->matchesWorkflowDefinitionFingerprint(
                $this->fingerprintMap($worker->workflow_definition_fingerprints),
                $workflowType,
                $recordedFingerprint,
            ))
            ->values();

        if ($compatibleWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_incompatible',
                sprintf(
                    'Query-capable workers on task queue [%s] support workflow type [%s], but none advertise the recorded workflow definition fingerprint.',
                    $taskQueue,
                    $workflowType ?? 'unknown',
                ),
                $taskQueue,
                $activeWorkers->count(),
                $queryWorkers->count(),
                $typeWorkers->count(),
                0,
            );
        }

        if (! $requirePollingWorker) {
            return $this->queryRouteResult(
                true,
                null,
                null,
                $taskQueue,
                $activeWorkers->count(),
                $queryWorkers->count(),
                $typeWorkers->count(),
                $compatibleWorkers->count(),
            );
        }

        $pollingWorkers = $compatibleWorkers
            ->filter(fn (WorkerRegistration $worker): bool => $this->workerAcceptsQueryTasks($namespace, $worker))
            ->values();

        if ($pollingWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_unavailable',
                sprintf(
                    'Compatible query-capable workers on task queue [%s] are not currently polling workflow query tasks.',
                    $taskQueue,
                ),
                $taskQueue,
                $activeWorkers->count(),
                $queryWorkers->count(),
                $typeWorkers->count(),
                $compatibleWorkers->count(),
            );
        }

        return $this->queryRouteResult(
            true,
            null,
            null,
            $taskQueue,
            $activeWorkers->count(),
            $queryWorkers->count(),
            $typeWorkers->count(),
            $pollingWorkers->count(),
        );
    }

    /**
     * @return array{
     *     servable: bool,
     *     reason: string|null,
     *     message: string|null,
     *     task_queue: string,
     *     active_worker_count: int,
     *     query_capable_worker_count: int,
     *     workflow_type_worker_count: int,
     *     compatible_worker_count: int
     * }
     */
    private function awaitRegisteredQueryRoute(string $namespace, WorkflowRun $run): array
    {
        $route = $this->registeredQueryRoute($namespace, $run);

        if (! $this->shouldAwaitQueryRoute($route)) {
            return $route;
        }

        return $this->longPoller->until(
            fn (): array => $this->registeredQueryRoute($namespace, $run),
            fn (array $candidate): bool => $candidate['servable'] || ! $this->shouldAwaitQueryRoute($candidate),
            $this->queryTimeoutSeconds(),
            wakeChannels: $this->signals->queryTaskPollChannels($namespace, $route['task_queue']),
        );
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function shouldAwaitQueryRoute(array $route): bool
    {
        return ($route['servable'] ?? false) !== true
            && ($route['reason'] ?? null) === 'query_worker_unavailable'
            && $this->queryTimeoutSeconds() > 0;
    }

    /**
     * @return array{
     *     servable: bool,
     *     reason: string|null,
     *     message: string|null,
     *     task_queue: string,
     *     active_worker_count: int,
     *     query_capable_worker_count: int,
     *     workflow_type_worker_count: int,
     *     compatible_worker_count: int
     * }
     */
    private function queryRouteResult(
        bool $servable,
        ?string $reason,
        ?string $message,
        string $taskQueue,
        int $activeWorkerCount,
        int $queryCapableWorkerCount,
        int $workflowTypeWorkerCount,
        int $compatibleWorkerCount,
    ): array {
        return [
            'servable' => $servable,
            'reason' => $reason,
            'message' => $message,
            'task_queue' => $taskQueue,
            'active_worker_count' => $activeWorkerCount,
            'query_capable_worker_count' => $queryCapableWorkerCount,
            'workflow_type_worker_count' => $workflowTypeWorkerCount,
            'compatible_worker_count' => $compatibleWorkerCount,
        ];
    }

    /**
     * @return array{
     *     budget_source: string,
     *     max_pending_per_queue: int,
     *     approximate_pending_count: int,
     *     remaining_pending_capacity: int,
     *     lock_required: bool,
     *     lock_supported: bool,
     *     status: string
     * }
     */
    public function queueAdmission(string $namespace, string $taskQueue): array
    {
        $maxPending = $this->maxPendingPerQueue();
        $pendingCount = count($this->pendingTaskIds($namespace, $taskQueue));
        $lockSupported = $this->store()->getStore() instanceof LockProvider;

        return [
            'budget_source' => 'server.query_tasks.max_pending_per_queue',
            'max_pending_per_queue' => $maxPending,
            'approximate_pending_count' => $pendingCount,
            'remaining_pending_capacity' => max(0, $maxPending - $pendingCount),
            'lock_required' => true,
            'lock_supported' => $lockSupported,
            'status' => $this->queueAdmissionStatus($pendingCount, $maxPending, $lockSupported),
        ];
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    public function hasPendingTaskForPoller(
        string $namespace,
        string $taskQueue,
        array $supportedWorkflowTypes,
        ?string $buildId = null,
    ): bool {
        foreach ($this->pendingTaskIds($namespace, $taskQueue) as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (! is_array($task) || ($task['status'] ?? null) !== 'pending') {
                continue;
            }

            if (! $this->matchesCompatibility(
                $buildId,
                $task['compatibility'] ?? null,
                $task['compatibility_scope'] ?? null,
            )) {
                continue;
            }

            if ($this->matchesWorkflowType($supportedWorkflowTypes, $task['workflow_type'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     */
    public function hasClaimablePendingTaskForPoller(
        string $namespace,
        string $taskQueue,
        array $supportedWorkflowTypes,
        ?string $buildId = null,
        bool $acceptsQueryTasks = true,
        array $workflowDefinitionFingerprints = [],
    ): bool {
        if (! $acceptsQueryTasks) {
            return false;
        }

        foreach ($this->pendingTaskIds($namespace, $taskQueue) as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (! is_array($task) || ($task['status'] ?? null) !== 'pending') {
                continue;
            }

            if ($this->canClaimPendingTaskForPoller(
                $task,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $buildId,
                $acceptsQueryTasks,
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     */
    public function hasPendingTaskForActiveWorkflowLeaseOwner(
        string $namespace,
        string $taskQueue,
        array $supportedWorkflowTypes,
        ?string $buildId = null,
        array $workflowDefinitionFingerprints = [],
        ?string $leaseOwner = null,
    ): bool {
        if ($leaseOwner === null || $leaseOwner === '') {
            return false;
        }

        foreach ($this->pendingTaskIds($namespace, $taskQueue) as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (! is_array($task) || ($task['status'] ?? null) !== 'pending') {
                continue;
            }

            if (! $this->queryTaskMatchesPoller(
                $task,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $buildId,
                acceptsQueryTasks: true,
            )) {
                continue;
            }

            if ($this->hasActiveWorkflowTaskLeaseOwnedBy($task, $leaseOwner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function claimNext(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId = null,
        ?string $pollRequestId = null,
        array $workerPollFence = [],
    ): ?array {
        $task = $this->withQueueLock(
            $namespace,
            $taskQueue,
            fn (): ?array => $this->claimNextPendingTask(
                $namespace,
                $taskQueue,
                $leaseOwner,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $buildId,
                $pollRequestId,
                $workerPollFence,
            ),
        );

        if (in_array($task['poll_status'] ?? null, ['superseded', 'stale_worker_registration'], true)) {
            return $task;
        }

        return is_array($task) ? $this->queryTaskPayload($task) : null;
    }

    /**
     * Return only the live query lease assigned to this logical poll. A fresh
     * concurrent request from the same worker must remain an independent slot.
     *
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function activeLeasedTaskForPoller(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId = null,
        ?string $pollRequestId = null,
    ): ?array {
        $ids = $this->leasedTaskIds($namespace, $taskQueue, $leaseOwner);
        $activeIds = [];
        $matchingTask = null;

        foreach ($ids as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (
                ! is_array($task)
                || ($task['status'] ?? null) !== 'leased'
                || ($task['namespace'] ?? null) !== $namespace
                || ($task['task_queue'] ?? null) !== $taskQueue
                || ($task['lease_owner'] ?? null) !== $leaseOwner
            ) {
                continue;
            }

            $leaseExpiresAt = $this->timestamp($task['lease_expires_at'] ?? null);

            if (! $leaseExpiresAt instanceof Carbon || $leaseExpiresAt->lte(now())) {
                continue;
            }

            $activeIds[] = $queryTaskId;

            if (
                $matchingTask === null
                && ($task['poll_request_id'] ?? null) === $pollRequestId
                && $this->queryTaskMatchesPoller(
                    $task,
                    $supportedWorkflowTypes,
                    $workflowDefinitionFingerprints,
                    $buildId,
                    acceptsQueryTasks: true,
                )
            ) {
                $matchingTask = $task;
            }
        }

        if ($activeIds !== $ids) {
            $this->storeLeasedTaskIds($namespace, $taskQueue, $leaseOwner, $activeIds);
        }

        return $matchingTask;
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function claimNextPendingTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId = null,
        ?string $pollRequestId = null,
        array $workerPollFence = [],
    ): ?array {
        $ids = $this->pendingTaskIds($namespace, $taskQueue);
        $remaining = [];

        foreach ($ids as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (! is_array($task) || ($task['status'] ?? null) !== 'pending') {
                continue;
            }

            if (! $this->canClaimPendingTaskForPoller(
                $task,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $buildId,
                acceptsQueryTasks: true,
            )) {
                $remaining[] = $queryTaskId;

                continue;
            }

            if (
                $pollRequestId !== null
                && ! $this->pollRequests->isCurrent($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId)
            ) {
                return ['poll_status' => 'superseded'];
            }

            if ($workerPollFence !== [] && ! WorkerPollFence::isCurrent($workerPollFence)) {
                return ['poll_status' => 'stale_worker_registration'];
            }

            if (! $this->store()->add($this->leaseKey($queryTaskId), $leaseOwner, now()->addSeconds($this->leaseTtlSeconds()))) {
                $remaining[] = $queryTaskId;

                continue;
            }

            $attempt = ((int) ($task['attempt_count'] ?? 0)) + 1;
            $task['status'] = 'leased';
            $task['lease_owner'] = $leaseOwner;
            $task['lease_expires_at'] = now()->addSeconds($this->leaseTtlSeconds())->toJSON();
            $task['attempt_count'] = $attempt;
            $task['leased_at'] = now()->toJSON();
            $task['poll_request_id'] = $pollRequestId;

            $this->putTask($task);
            $this->appendLeasedTask($namespace, $taskQueue, $leaseOwner, $queryTaskId);
            $this->storePendingTaskIds(
                $namespace,
                $taskQueue,
                array_values(array_filter(
                    $ids,
                    static fn (string $id): bool => $id !== $queryTaskId,
                )),
            );

            return $task;
        }

        $this->storePendingTaskIds($namespace, $taskQueue, $remaining);

        return null;
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     */
    private function canClaimPendingTaskForPoller(
        array $task,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId,
        bool $acceptsQueryTasks = true,
    ): bool {
        return $this->queryTaskMatchesPoller(
            $task,
            $supportedWorkflowTypes,
            $workflowDefinitionFingerprints,
            $buildId,
            $acceptsQueryTasks,
        )
            && ! $this->hasActiveWorkflowTaskLease($task)
            && ! $this->hasReadyWorkflowResumeTask($task, $buildId);
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     */
    private function queryTaskMatchesPoller(
        array $task,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId,
        bool $acceptsQueryTasks = true,
    ): bool {
        return $acceptsQueryTasks
            && $this->matchesCompatibility(
                $buildId,
                $task['compatibility'] ?? null,
                $task['compatibility_scope'] ?? null,
            )
            && $this->matchesWorkflowType($supportedWorkflowTypes, $task['workflow_type'] ?? null)
            && $this->matchesWorkflowDefinitionFingerprint(
                $workflowDefinitionFingerprints,
                $task['workflow_type'] ?? null,
                $task['workflow_definition_fingerprint'] ?? null,
            );
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     */
    private function pendingTaskWorkflowBlockStatus(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId,
    ): ?string {
        $blockedByActiveLease = false;
        $blockedByReadyResume = false;

        foreach ($this->pendingTaskIds($namespace, $taskQueue) as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (! is_array($task) || ($task['status'] ?? null) !== 'pending') {
                continue;
            }

            if (! $this->queryTaskMatchesPoller(
                $task,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $buildId,
            )) {
                continue;
            }

            if ($this->hasActiveWorkflowTaskLease($task)) {
                $blockedByActiveLease = true;

                continue;
            }

            if ($this->hasReadyWorkflowResumeTask($task, $buildId)) {
                $blockedByReadyResume = true;

                continue;
            }

            return null;
        }

        if ($blockedByActiveLease) {
            return 'workflow_task_leased';
        }

        return $blockedByReadyResume ? 'workflow_task_pending' : null;
    }

    /**
     * @param  array<string, mixed>  $queryTask
     */
    private function hasReadyWorkflowResumeTask(array $queryTask, ?string $buildId): bool
    {
        $runId = $this->stringValue($queryTask['run_id'] ?? null);
        $taskQueue = $this->stringValue($queryTask['task_queue'] ?? null);

        if ($runId === null || $taskQueue === null) {
            return false;
        }

        $tasks = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('queue', $taskQueue)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->get(['id', 'payload', 'compatibility', 'available_at', 'created_at']);

        foreach ($tasks as $task) {
            if ($this->workflowTaskAvailableAtIsFuture($task->available_at)) {
                continue;
            }

            if (
                $this->queryTaskHasHistoryCutoff($queryTask)
                && $this->workflowTaskCreatedAfterQueryTask($task->created_at, $queryTask['created_at'] ?? null)
            ) {
                continue;
            }

            $payload = $this->workflowTaskPayload($task);

            if (! $this->isWorkflowResumePayload($payload)) {
                continue;
            }

            if ($this->resumePayloadAlreadyVisibleToQuery($queryTask, $payload)) {
                continue;
            }

            $compatibility = $this->stringValue($task->compatibility)
                ?? $this->stringValue($queryTask['compatibility'] ?? null);

            if ($this->matchesCompatibility($buildId, $compatibility, $queryTask['compatibility_scope'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $queryTask
     * @param  array<string, mixed>  $payload
     */
    private function resumePayloadAlreadyVisibleToQuery(array $queryTask, array $payload): bool
    {
        $observedSequence = $this->integerValue($queryTask['history_observed_sequence'] ?? null);

        if ($observedSequence === null || $observedSequence < 1) {
            return false;
        }

        if ($this->stringValue($payload['resume_source_kind'] ?? null) !== 'workflow_signal') {
            return false;
        }

        $signalId = $this->stringValue($payload['workflow_signal_id'] ?? null)
            ?? $this->stringValue($payload['resume_source_id'] ?? null);

        if ($signalId === null) {
            return false;
        }

        $signalReceivedSequence = $this->signalReceivedHistorySequence(
            $this->stringValue($queryTask['run_id'] ?? null),
            $signalId,
        );

        return $signalReceivedSequence !== null && $signalReceivedSequence <= $observedSequence;
    }

    private function signalReceivedHistorySequence(?string $runId, string $signalId): ?int
    {
        if ($runId === null) {
            return null;
        }

        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->orderBy('sequence')
            ->get(['sequence', 'payload'])
            ->first(function (WorkflowHistoryEvent $event) use ($signalId): bool {
                return $this->stringValue($event->payload['signal_id'] ?? null) === $signalId;
            });

        return $event instanceof WorkflowHistoryEvent ? (int) $event->sequence : null;
    }

    /**
     * @param  array<string, mixed>  $queryTask
     */
    private function queryTaskHasHistoryCutoff(array $queryTask): bool
    {
        return $this->integerValue($queryTask['history_cutoff_sequence'] ?? null) !== null;
    }

    private function workflowTaskCreatedAfterQueryTask(mixed $workflowTaskCreatedAt, mixed $queryTaskCreatedAt): bool
    {
        $queryCreatedAt = $this->timestamp($queryTaskCreatedAt);
        $workflowCreatedAt = $this->timestamp($workflowTaskCreatedAt);

        if (! $queryCreatedAt instanceof Carbon || ! $workflowCreatedAt instanceof Carbon) {
            return false;
        }

        return $workflowCreatedAt->gt($queryCreatedAt);
    }

    private function workflowTaskAvailableAtIsFuture(mixed $availableAt): bool
    {
        if ($availableAt instanceof \DateTimeInterface) {
            return $availableAt > now();
        }

        if (! is_string($availableAt) || trim($availableAt) === '') {
            return false;
        }

        try {
            return Carbon::parse($availableAt) > now();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowTaskPayload(WorkflowTask $task): array
    {
        if (is_array($task->payload)) {
            return $task->payload;
        }

        if (! is_string($task->payload) || trim($task->payload) === '') {
            return [];
        }

        $decoded = json_decode($task->payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isWorkflowResumePayload(array $payload): bool
    {
        return in_array($this->stringValue($payload['resume_source_kind'] ?? null), [
            'workflow_signal',
            'workflow_update',
            'activity_execution',
            'child_workflow_run',
            'timer',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $queryTask
     */
    private function hasActiveWorkflowTaskLease(array $queryTask): bool
    {
        $runId = $this->stringValue($queryTask['run_id'] ?? null);

        if ($runId === null) {
            return false;
        }

        return $this->hasActiveWorkflowTaskLeaseForRun($runId);
    }

    private function hasActiveWorkflowTaskLeaseForRun(string $runId): bool
    {
        if (! Schema::hasTable('workflow_tasks')) {
            return false;
        }

        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Leased->value)
            ->where(static function ($query): void {
                $query
                    ->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $queryTask
     */
    private function hasActiveWorkflowTaskLeaseOwnedBy(array $queryTask, string $leaseOwner): bool
    {
        $runId = $this->stringValue($queryTask['run_id'] ?? null);

        if ($runId === null) {
            return false;
        }

        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', $leaseOwner)
            ->where(static function ($query): void {
                $query
                    ->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function queryTaskPayload(array $task): array
    {
        $run = WorkflowRun::query()->find($task['run_id'] ?? null);
        $historyCutoffSequence = $this->historyCutoffSequence($task, $run);
        $lastHistorySequence = $historyCutoffSequence
            ?? (int) ($run?->last_history_sequence ?? 0);

        $payload = [
            'query_task_id' => $task['query_task_id'],
            'query_task_attempt' => (int) ($task['attempt_count'] ?? 1),
            'workflow_id' => $task['workflow_id'],
            'run_id' => $task['run_id'],
            'workflow_type' => $task['workflow_type'],
            'workflow_class' => $run?->workflow_class,
            'compatibility' => $this->stringValue($task['compatibility'] ?? null),
            'query_name' => $task['query_name'],
            'payload_codec' => $task['payload_codec'],
            'workflow_arguments' => $run instanceof WorkflowRun && is_string($run->arguments)
                ? $this->payloadEnvelopes->workerEnvelope(
                    is_string($task['namespace'] ?? null) ? $task['namespace'] : null,
                    PayloadCodecContract::canonicalize($run->payload_codec),
                    $run->arguments,
                )
                : null,
            'query_arguments' => $this->queryArgumentsEnvelope($task),
            'run_status' => $run?->status?->value,
            'last_history_sequence' => $lastHistorySequence,
            'history_cutoff_sequence' => $historyCutoffSequence,
            'history_events' => $run instanceof WorkflowRun ? $this->historyEvents($run, $historyCutoffSequence) : [],
            'history_export' => $run instanceof WorkflowRun
                ? $this->historyExport($run, $historyCutoffSequence)
                : null,
            'task_queue' => $task['task_queue'],
            'lease_owner' => $task['lease_owner'] ?? null,
            'lease_expires_at' => $task['lease_expires_at'] ?? null,
        ];

        $compatibilityScope = $this->stringValue($task['compatibility_scope'] ?? null);
        if ($compatibilityScope !== null) {
            $payload['compatibility_scope'] = $compatibilityScope;
        }

        $principal = $this->taskPrincipal($task);
        if ($principal !== null) {
            $payload['principal'] = $principal;
        }

        if (is_array($task['command_context'] ?? null)) {
            $payload['command_context'] = $task['command_context'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $attributes
     * @return array{type: string, id: string, label?: string}|null
     */
    private function commandContextPrincipal(?array $attributes): ?array
    {
        $context = $attributes['context'] ?? null;

        if (! is_array($context)) {
            return null;
        }

        return $this->principalPayload($context['principal'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{type: string, id: string, label?: string}|null
     */
    private function taskPrincipal(array $task): ?array
    {
        return $this->principalPayload($task['principal'] ?? null);
    }

    /**
     * @return array{type: string, id: string, label?: string}|null
     */
    private function principalPayload(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $type = $this->stringValue($value['type'] ?? null);
        $id = $this->stringValue($value['id'] ?? null);

        if ($type === null || $id === null) {
            return null;
        }

        $principal = [
            'type' => $type,
            'id' => $id,
        ];

        $label = $this->stringValue($value['label'] ?? null);
        if ($label !== null) {
            $principal['label'] = $label;
        }

        return $principal;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function queryArgumentsEnvelope(array $task): array
    {
        $arguments = $task['query_arguments'] ?? null;

        if (! is_array($arguments)) {
            return $this->emptyArgumentsEnvelope();
        }

        $blob = $arguments['blob'] ?? null;
        if ($blob === null && ! array_key_exists('external_storage', $arguments)) {
            return $this->emptyArgumentsEnvelope($arguments['codec'] ?? null);
        }

        if (! is_string($blob)) {
            return $arguments;
        }

        return $this->payloadEnvelopes->workerEnvelope(
            is_string($task['namespace'] ?? null) ? $task['namespace'] : null,
            PayloadCodecContract::canonicalize($arguments['codec'] ?? null),
            $blob,
        ) ?? ['codec' => PayloadCodecContract::CODEC, 'blob' => null];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private function emptyArgumentsEnvelope(mixed $codec = null): array
    {
        $codec = PayloadCodecContract::canonicalize($codec);

        return [
            'codec' => $codec,
            'blob' => Serializer::serializeWithCodec($codec, []),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>|null
     */
    private function resultEnvelope(string $namespace, array $envelope): ?array
    {
        $blob = $envelope['blob'] ?? null;

        if (! is_string($blob)) {
            return $envelope;
        }

        return $this->payloadEnvelopes->workerEnvelope(
            $namespace,
            PayloadCodecContract::canonicalize($envelope['codec'] ?? null),
            $blob,
        );
    }

    private function historyCutoffSequence(array $task, ?WorkflowRun $run): ?int
    {
        $cutoff = $this->integerValue($task['history_cutoff_sequence'] ?? null);

        if ($cutoff === null || $run === null) {
            return null;
        }

        return max(0, min($cutoff, (int) ($run->last_history_sequence ?? $cutoff)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function historyExport(WorkflowRun $run, ?int $historyCutoffSequence): ?array
    {
        if ($historyCutoffSequence !== null) {
            return null;
        }

        return HistoryExport::forRun($run);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyEvents(WorkflowRun $run, ?int $historyCutoffSequence = null): array
    {
        $query = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id);

        if ($historyCutoffSequence !== null) {
            $query->where('sequence', '<=', $historyCutoffSequence);
        }

        $events = $query->orderBy('sequence')
            ->get()
            ->map(fn (WorkflowHistoryEvent $event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => is_array($event->payload)
                    ? $this->payloadEnvelopes->historyPayload(
                        $run->namespace,
                        $event->payload,
                        $run->payload_codec,
                        $event->event_type->value,
                    )
                    : [],
                'workflow_task_id' => $event->workflow_task_id,
                'workflow_command_id' => $event->workflow_command_id,
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])
            ->values()
            ->all();

        $events = $this->historyEventsWithSignalArgumentEnvelopes($events, $run->namespace);

        return app(MessageStreamWorkerDelivery::class)->historyEvents($run->namespace, $events);
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<int, mixed>
     */
    private function historyEventsWithSignalArgumentEnvelopes(array $events, ?string $namespace): array
    {
        $signalIds = [];

        foreach ($events as $event) {
            if (! is_array($event) || ($event['event_type'] ?? null) !== 'SignalReceived') {
                continue;
            }

            $payload = $event['payload'] ?? null;
            if (! is_array($payload)) {
                continue;
            }

            $signalId = $this->stringValue($payload['signal_id'] ?? null);
            if ($signalId !== null) {
                $signalIds[] = $signalId;
            }
        }

        $signalIds = array_values(array_unique($signalIds));
        if ($signalIds === []) {
            return $events;
        }

        /** @var array<string, WorkflowSignal> $signals */
        $signals = WorkflowSignal::query()
            ->whereIn('id', $signalIds)
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($events as $index => $event) {
            if (! is_array($event) || ($event['event_type'] ?? null) !== 'SignalReceived') {
                continue;
            }

            $payload = $event['payload'] ?? [];
            if (! is_array($payload)) {
                $payload = [];
            }

            $signalId = $this->stringValue($payload['signal_id'] ?? null);
            $signal = $signalId === null ? null : ($signals[$signalId] ?? null);
            $envelope = $signal instanceof WorkflowSignal
                ? $this->signalArgumentsEnvelopeFromRecord($signal, $namespace)
                : null;
            $changed = false;

            if ($signal instanceof WorkflowSignal && is_int($signal->workflow_sequence)) {
                $payload['workflow_sequence'] ??= $signal->workflow_sequence;
                $changed = true;
            }

            if ($envelope !== null) {
                $payload['payload_codec'] ??= $envelope['codec'];
                $payload['arguments'] ??= $envelope;
                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            $event['payload'] = $payload;
            $events[$index] = $event;
        }

        return $events;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function signalArgumentsEnvelopeFromRecord(WorkflowSignal $signal, ?string $namespace): ?array
    {
        if (! is_string($signal->arguments) || $signal->arguments === '') {
            return null;
        }

        return $this->payloadEnvelopes->workerEnvelope(
            $namespace,
            PayloadCodecContract::canonicalize($this->stringValue($signal->payload_codec)),
            $signal->arguments,
        );
    }

    /**
     * @param  array<string, mixed>|null  $task
     * @return array<string, mixed>|null
     */
    private function guardLease(
        ?array $task,
        string $namespace,
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
    ): ?array {
        if (! is_array($task) || ($task['namespace'] ?? null) !== $namespace) {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'query_task_not_found',
                'error' => 'Query task not found.',
                'status' => 404,
            ];
        }

        if (($task['status'] ?? null) === 'timed_out') {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'query_task_timed_out',
                'error' => 'Query task timed out before completion.',
                'timed_out_at' => $task['timed_out_at'] ?? null,
                'status' => 409,
            ];
        }

        if (($task['status'] ?? null) !== 'leased') {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'query_task_not_leased',
                'error' => 'Query task is not currently leased.',
                'status' => 409,
            ];
        }

        if (($task['lease_owner'] ?? null) !== $leaseOwner) {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'lease_owner_mismatch',
                'error' => 'Query task lease is owned by another worker.',
                'lease_owner' => $task['lease_owner'] ?? null,
                'status' => 409,
            ];
        }

        if ((int) ($task['attempt_count'] ?? 0) !== $queryTaskAttempt) {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'query_task_attempt_mismatch',
                'error' => 'Query task lease attempt does not match the current claim.',
                'current_attempt' => (int) ($task['attempt_count'] ?? 0),
                'status' => 409,
            ];
        }

        $leaseWorker = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('worker_id', $leaseOwner)
            ->first();

        if (! $leaseWorker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($leaseWorker)) {
            return [
                'query_task_id' => $queryTaskId,
                'query_task_attempt' => $queryTaskAttempt,
                'outcome' => 'rejected',
                'reason' => 'stale_worker_registration',
                'error' => 'Query task lease owner is no longer an active worker.',
                'lease_owner' => $leaseOwner,
                'status' => 409,
            ];
        }

        $leaseExpiresAt = $this->timestamp($task['lease_expires_at'] ?? null);

        if ($leaseExpiresAt instanceof Carbon && $leaseExpiresAt->lte(now())) {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'lease_expired',
                'error' => 'Query task lease has expired.',
                'lease_expires_at' => $leaseExpiresAt->toJSON(),
                'status' => 409,
            ];
        }

        return null;
    }

    private function workerIsFresh(WorkerRegistration $worker): bool
    {
        return WorkerPollFence::isFresh($worker);
    }

    private function workerCanRouteQuery(string $namespace, WorkerRegistration $worker): bool
    {
        return $this->workerIsFresh($worker);
    }

    /**
     * @param  list<string>  $supportedTypes
     */
    private function matchesWorkflowType(array $supportedTypes, mixed $workflowType): bool
    {
        if ($supportedTypes === []) {
            return false;
        }

        return is_string($workflowType) && in_array($workflowType, $supportedTypes, true);
    }

    /**
     * @param  array<string, string>  $workflowDefinitionFingerprints
     */
    private function matchesWorkflowDefinitionFingerprint(
        array $workflowDefinitionFingerprints,
        mixed $workflowType,
        mixed $recordedFingerprint,
    ): bool {
        $recordedFingerprint = $this->stringValue($recordedFingerprint);

        if ($recordedFingerprint === null) {
            return true;
        }

        $workflowType = $this->stringValue($workflowType);

        if ($workflowType === null) {
            return false;
        }

        $advertisedFingerprint = $this->stringValue($workflowDefinitionFingerprints[$workflowType] ?? null);

        return $advertisedFingerprint !== null && hash_equals($recordedFingerprint, $advertisedFingerprint);
    }

    private function matchesCompatibility(?string $buildId, mixed $compatibility, mixed $compatibilityScope = null): bool
    {
        $compatibility = $this->stringValue($compatibility);

        if ($compatibility !== null) {
            return $buildId !== null && hash_equals($compatibility, $buildId);
        }

        if ($this->stringValue($compatibilityScope) === self::COMPATIBILITY_SCOPE_UNVERSIONED) {
            return $buildId === null;
        }

        return true;
    }

    private function queryTaskCompatibilityScope(WorkflowRun $run): ?string
    {
        if ($this->stringValue($run->compatibility) !== null) {
            return null;
        }

        if (! $this->canReadWorkflowStartedEvent($run)) {
            return null;
        }

        $contract = RunCommandContract::forRun($run);

        if (($contract['queries'] ?? []) === [] && ($contract['query_contracts'] ?? []) === []) {
            return null;
        }

        return self::COMPATIBILITY_SCOPE_UNVERSIONED;
    }

    private function compatibilityLabel(?string $compatibility, ?string $compatibilityScope): string
    {
        if ($compatibility !== null) {
            return $compatibility;
        }

        if ($compatibilityScope === self::COMPATIBILITY_SCOPE_UNVERSIONED) {
            return 'unversioned';
        }

        return 'legacy';
    }

    public function workerAcceptsQueryTasks(string $namespace, WorkerRegistration $worker): bool
    {
        return $this->workerSupportsQueryTasks($namespace, $worker)
            && $this->queryPollingWorkerIsCurrent($namespace, $worker);
    }

    public function workerSupportsQueryTasks(string $namespace, WorkerRegistration $worker): bool
    {
        if (in_array(self::QUERY_TASKS_CAPABILITY, $this->stringArray($worker->capabilities), true)) {
            return true;
        }

        return $this->queryPollingWorkerIsCurrent($namespace, $worker);
    }

    private function queryPollingWorkerIsCurrent(string $namespace, WorkerRegistration $worker): bool
    {
        if (! $this->cache->available()) {
            return false;
        }

        try {
            return $this->store()->get($this->queryPollingWorkerKey(
                $namespace,
                (string) $worker->task_queue,
                $worker->worker_id,
            )) === true;
        } catch (\Throwable) {
            // Query-task eligibility is an acceleration mirror. A cache
            // interruption must not prevent ordinary durable workflow polls.
            return false;
        }
    }

    private function rememberQueryTaskPollingWorker(
        string $namespace,
        string $taskQueue,
        string $workerId,
        ?int $timeoutSeconds,
    ): void {
        $this->store()->put(
            $this->queryPollingWorkerKey($namespace, $taskQueue, $workerId),
            true,
            now()->addSeconds($this->queryPollingWorkerTtlSeconds($timeoutSeconds)),
        );

        $this->signals->signalQueryTaskQueue($namespace, $taskQueue);
    }

    private function recordedWorkflowDefinitionFingerprint(WorkflowRun $run): ?string
    {
        if (! $this->canReadWorkflowStartedEvent($run)) {
            return null;
        }

        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', 'WorkflowStarted')
            ->orderBy('sequence')
            ->first();

        return $this->stringValue($event?->payload['workflow_definition_fingerprint'] ?? null);
    }

    private function canReadWorkflowStartedEvent(WorkflowRun $run): bool
    {
        if (! $run->exists) {
            return false;
        }

        $event = new WorkflowHistoryEvent;
        $connection = $event->getConnectionName();

        try {
            return $connection === null
                ? Schema::hasTable($event->getTable())
                : Schema::connection($connection)->hasTable($event->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    private function fingerprintMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fingerprints = [];

        foreach ($value as $workflowType => $fingerprint) {
            if (! is_string($workflowType) || ! is_string($fingerprint)) {
                continue;
            }

            $workflowType = trim($workflowType);
            $fingerprint = trim($fingerprint);

            if ($workflowType === '' || $fingerprint === '') {
                continue;
            }

            $fingerprints[$workflowType] = $fingerprint;
        }

        ksort($fingerprints);

        return $fingerprints;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function putTask(array $task): void
    {
        $this->store()->put($this->taskKey((string) $task['query_task_id']), $task, now()->addSeconds($this->taskTtlSeconds()));
    }

    private function appendPendingTask(string $namespace, string $taskQueue, string $queryTaskId): void
    {
        $this->withQueueLock($namespace, $taskQueue, function () use ($namespace, $taskQueue, $queryTaskId): void {
            $ids = $this->pendingTaskIds($namespace, $taskQueue);

            if (! in_array($queryTaskId, $ids, true) && count($ids) >= $this->maxPendingPerQueue()) {
                throw new QueryTaskQueueFullException($namespace, $taskQueue, $this->maxPendingPerQueue());
            }

            $ids[] = $queryTaskId;

            $this->storePendingTaskIds($namespace, $taskQueue, array_values(array_unique($ids)));
        });
    }

    /**
     * @return list<string>
     */
    private function pendingTaskIds(string $namespace, string $taskQueue): array
    {
        $ids = $this->stringArray($this->store()->get($this->queueKey($namespace, $taskQueue)));
        $pending = [];

        foreach ($ids as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (is_array($task) && ($task['status'] ?? null) === 'pending') {
                $pending[] = $queryTaskId;
            }
        }

        if ($pending !== $ids) {
            $this->storePendingTaskIds($namespace, $taskQueue, $pending);
        }

        return $pending;
    }

    /**
     * @param  list<string>  $ids
     */
    private function storePendingTaskIds(string $namespace, string $taskQueue, array $ids): void
    {
        $this->store()->put($this->queueKey($namespace, $taskQueue), array_values($ids), now()->addSeconds($this->taskTtlSeconds()));
    }

    private function appendLeasedTask(string $namespace, string $taskQueue, string $leaseOwner, string $queryTaskId): void
    {
        $ids = $this->leasedTaskIds($namespace, $taskQueue, $leaseOwner);
        $ids[] = $queryTaskId;

        $this->storeLeasedTaskIds($namespace, $taskQueue, $leaseOwner, array_values(array_unique($ids)));
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function forgetLeasedTask(array $task): void
    {
        $namespace = $this->stringValue($task['namespace'] ?? null);
        $taskQueue = $this->stringValue($task['task_queue'] ?? null);
        $leaseOwner = $this->stringValue($task['lease_owner'] ?? null);
        $queryTaskId = $this->stringValue($task['query_task_id'] ?? null);

        if ($namespace === null || $taskQueue === null || $leaseOwner === null || $queryTaskId === null) {
            return;
        }

        $this->storeLeasedTaskIds(
            $namespace,
            $taskQueue,
            $leaseOwner,
            array_values(array_filter(
                $this->leasedTaskIds($namespace, $taskQueue, $leaseOwner),
                static fn (string $id): bool => $id !== $queryTaskId,
            )),
        );
    }

    /**
     * @return list<string>
     */
    private function leasedTaskIds(string $namespace, string $taskQueue, string $leaseOwner): array
    {
        return $this->stringArray($this->store()->get($this->leasedKey($namespace, $taskQueue, $leaseOwner)));
    }

    /**
     * @param  list<string>  $ids
     */
    private function storeLeasedTaskIds(string $namespace, string $taskQueue, string $leaseOwner, array $ids): void
    {
        $ids = array_values($ids);

        if ($ids === []) {
            $this->store()->forget($this->leasedKey($namespace, $taskQueue, $leaseOwner));

            return;
        }

        $this->store()->put(
            $this->leasedKey($namespace, $taskQueue, $leaseOwner),
            $ids,
            now()->addSeconds($this->taskTtlSeconds()),
        );
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withQueueLock(string $namespace, string $taskQueue, Closure $callback): mixed
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new QueryTaskQueueUnavailableException($namespace, $taskQueue, 'The configured polling cache store does not support atomic locks.');
        }

        try {
            return $store
                ->lock($this->queueLockKey($namespace, $taskQueue), $this->queueLockTtlSeconds())
                ->block($this->queueLockWaitSeconds(), $callback);
        } catch (LockTimeoutException $exception) {
            throw new QueryTaskQueueUnavailableException($namespace, $taskQueue, 'Timed out waiting for the query task queue lock.', $exception);
        }
    }

    private function queryFailed(
        WorkflowRun $run,
        string $queryName,
        string $reason,
        string $message,
        int $status,
        array $validationErrors = [],
        array $extra = [],
    ): array {
        $payload = array_merge([
            'success' => false,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'target_scope' => 'instance',
            'query_name' => $queryName,
            'result' => null,
            'reason' => $reason,
            'message' => $message,
            'status' => $status,
        ], $extra);

        if ($validationErrors !== []) {
            $payload['validation_errors'] = $validationErrors;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{reason: string, message: string, status: int}
     */
    private function timeoutFailure(string $namespace, WorkflowRun $run, string $queryName, array $task): array
    {
        $status = $this->stringValue($task['status'] ?? null) ?? 'unknown';

        if ($status === 'pending') {
            $route = $this->registeredQueryRoute($namespace, $run);

            if (! $route['servable']) {
                return [
                    'reason' => $route['reason'] ?? 'query_worker_unavailable',
                    'message' => $route['message'] ?? 'No compatible query-capable worker is available for this workflow query.',
                    'status' => 409,
                ];
            }

            return [
                'reason' => 'query_task_not_claimed',
                'message' => sprintf(
                    'Timed out waiting for a compatible worker to claim workflow query [%s] on task queue [%s].',
                    $queryName,
                    $route['task_queue'],
                ),
                'status' => 504,
            ];
        }

        if ($status === 'leased') {
            $leaseOwner = $this->stringValue($task['lease_owner'] ?? null);

            return [
                'reason' => 'query_worker_execution_timeout',
                'message' => $leaseOwner === null
                    ? sprintf('Timed out waiting for the worker that leased workflow query [%s] to complete it.', $queryName)
                    : sprintf('Timed out waiting for worker [%s] to complete workflow query [%s].', $leaseOwner, $queryName),
                'status' => 504,
            ];
        }

        return [
            'reason' => 'query_worker_timeout',
            'message' => sprintf(
                'Timed out waiting for workflow query [%s] to complete; last query task status was [%s].',
                $queryName,
                $status,
            ),
            'status' => 504,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function validationErrors(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    $errors[(string) $field][] = $message;
                }
            }
        }

        return $errors;
    }

    private function taskQueue(WorkflowRun $run): string
    {
        return $this->stringValue($run->queue) ?? 'default';
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): ?string => $this->stringValue($item), $value),
            static fn (?string $item): bool => $item !== null,
        ));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '' && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function queryTimeoutSeconds(): int
    {
        return max(0, (int) config('server.query_tasks.timeout', config('server.polling.timeout', 30)));
    }

    private function queryPollTimeoutSeconds(?int $timeoutSeconds): int
    {
        $requested = $timeoutSeconds ?? max(0, (int) config('server.polling.timeout', 30));

        if ($requested <= 0) {
            return 0;
        }

        $cap = max(1, (int) config('server.query_tasks.poll_timeout', 5));

        return min($requested, $cap);
    }

    private function leaseTtlSeconds(): int
    {
        $configured = max(1, (int) config('server.query_tasks.lease_timeout', config('server.lease.workflow_task_timeout', 60)));
        $queryTimeout = $this->queryTimeoutSeconds();

        if ($queryTimeout === 0) {
            return $configured;
        }

        return max($configured, $queryTimeout + $this->leaseGraceSeconds());
    }

    private function taskTtlSeconds(): int
    {
        return max(
            60,
            (int) config('server.query_tasks.ttl_seconds', 0),
            $this->queryTimeoutSeconds() + $this->leaseTtlSeconds() + 60,
        );
    }

    private function maxPendingPerQueue(): int
    {
        return max(1, min(10000, (int) config('server.query_tasks.max_pending_per_queue', 1024)));
    }

    private function queueAdmissionStatus(int $pendingCount, int $maxPending, bool $lockSupported): string
    {
        if (! $lockSupported) {
            return 'unavailable';
        }

        return $pendingCount >= $maxPending ? 'full' : 'accepting';
    }

    private function queueLockTtlSeconds(): int
    {
        return 10;
    }

    private function queueLockWaitSeconds(): int
    {
        return 5;
    }

    private function staleAfterSeconds(): int
    {
        return max(1, (int) config('server.workers.stale_after_seconds', 30));
    }

    private function leaseGraceSeconds(): int
    {
        return 5;
    }

    private function queryPollingWorkerTtlSeconds(?int $timeoutSeconds = null): int
    {
        if ($timeoutSeconds !== null) {
            return max(1, $timeoutSeconds) + $this->leaseGraceSeconds();
        }

        return max($this->staleAfterSeconds(), $this->queryTimeoutSeconds() + $this->leaseGraceSeconds());
    }

    private function taskKey(string $queryTaskId): string
    {
        return self::CACHE_PREFIX.'task:'.$queryTaskId;
    }

    private function leaseKey(string $queryTaskId): string
    {
        return self::CACHE_PREFIX.'lease:'.$queryTaskId;
    }

    private function leasedKey(string $namespace, string $taskQueue, string $leaseOwner): string
    {
        return self::CACHE_PREFIX.'leased:'.sha1($namespace.'|'.$taskQueue.'|'.$leaseOwner);
    }

    private function queueKey(string $namespace, string $taskQueue): string
    {
        return self::CACHE_PREFIX.'queue:'.sha1($namespace.'|'.$taskQueue);
    }

    private function queueLockKey(string $namespace, string $taskQueue): string
    {
        return self::CACHE_PREFIX.'queue-lock:'.sha1($namespace.'|'.$taskQueue);
    }

    private function queryPollingWorkerKey(string $namespace, string $taskQueue, string $workerId): string
    {
        return self::CACHE_PREFIX.'query-poller:'.sha1($namespace.'|'.$taskQueue.'|'.$workerId);
    }

    private function store(): CacheRepository
    {
        return $this->cache->store();
    }
}
