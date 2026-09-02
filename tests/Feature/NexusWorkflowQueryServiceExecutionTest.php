<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\QueryTaskPollRequestStore;
use App\Support\ServerPollingCache;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

class NexusWorkflowQueryServiceExecutionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->createNamespace('default');
    }

    public function test_workflow_query_service_operation_retries_through_public_query_worker_and_completes(): void
    {
        $this->bindScriptedQueryWorker([
            [
                'outcome' => 'failed',
                'reason' => 'transient_greeter_failure',
                'message' => 'first transient failure',
                'type' => 'TransientGreetingFailure',
            ],
            [
                'outcome' => 'completed',
                'result' => ['greeting' => 'hello from public query worker'],
            ],
        ]);

        $this->registerQueryWorker('python-query-worker', 'python-queries', ['python.queryable']);
        $run = $this->startRemoteWorkflow('shared-greeter-workflow');
        $this->primeQueryTaskPoller('python-query-worker');
        $this->createWorkflowQueryOperation([
            'max_attempts' => 2,
            'backoff_seconds' => [0],
        ]);

        $callerRunId = (string) Str::ulid();
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/greeter/services/shared/operations/greet/execute', [
                'arguments' => ['Ada'],
                'caller_workflow_instance_id' => 'caller-retry-workflow',
                'caller_workflow_run_id' => $callerRunId,
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('retry_policy.max_attempts', 2)
            ->assertJsonPath('retry_attempt_count', 2)
            ->assertJsonPath('service_call_attempts.0.failure_type', 'TransientGreetingFailure')
            ->assertJsonPath('service_call_attempts.0.retry_scheduled', true)
            ->assertJsonPath('service_call_attempts.1.status', 'completed')
            ->assertJsonPath('linked_workflow_instance_id', $run->workflow_instance_id);

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf('/api/workflows/caller-retry-workflow/runs/%s/nexus-operations', $callerRunId));

        $history->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('nexus_operations.0.service_call_id', $response->json('id'))
            ->assertJsonPath('nexus_operations.0.retry_policy.max_attempts', 2)
            ->assertJsonPath('nexus_operations.0.retry_attempt_count', 2)
            ->assertJsonPath('nexus_operations.0.service_call_attempts.0.failure_type', 'TransientGreetingFailure')
            ->assertJsonPath('nexus_operations.0.service_call_attempts.1.status', 'completed');
    }

    public function test_workflow_query_service_operation_preserves_non_retryable_typed_worker_error(): void
    {
        $this->bindScriptedQueryWorker([
            [
                'outcome' => 'failed',
                'reason' => 'service_error',
                'message' => 'shared greeter is permanently unavailable',
                'type' => 'SharedGreeterUnavailable',
            ],
        ]);

        $this->registerQueryWorker('python-query-worker', 'python-queries', ['python.queryable']);
        $this->startRemoteWorkflow('shared-greeter-workflow');
        $this->primeQueryTaskPoller('python-query-worker');
        $this->createWorkflowQueryOperation([
            'max_attempts' => 3,
            'non_retryable_error_types' => ['SharedGreeterUnavailable'],
        ]);

        $callerRunId = (string) Str::ulid();
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/greeter/services/shared/operations/greet/execute', [
                'caller_workflow_instance_id' => 'caller-typed-error-workflow',
                'caller_workflow_run_id' => $callerRunId,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('caller_observed_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('typed_error_message', 'shared greeter is permanently unavailable')
            ->assertJsonPath('retry_attempt_count', 1);

        $serviceCallId = (string) $response->json('id');
        $detail = $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf(
                '/api/service-endpoints/greeter/services/shared/operations/greet/service-calls/%s',
                $serviceCallId,
            ));

        $detail->assertOk()
            ->assertJsonPath('service_call_id', $serviceCallId)
            ->assertJsonPath('outcome_metadata.service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('caller_observed_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('typed_error_message', 'shared greeter is permanently unavailable')
            ->assertJsonPath('retry_attempt_count', 1);

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf('/api/workflows/caller-typed-error-workflow/runs/%s/nexus-operations', $callerRunId));

        $history->assertOk()
            ->assertJsonPath('nexus_operations.0.service_call_id', $serviceCallId)
            ->assertJsonPath('nexus_operations.0.service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('nexus_operations.0.caller_observed_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('nexus_operations.0.typed_error_message', 'shared greeter is permanently unavailable')
            ->assertJsonPath('nexus_operations.0.retry_attempt_count', 1);
    }

    public function test_cross_namespace_workflow_query_service_operation_preserves_non_retryable_typed_worker_error(): void
    {
        $this->createNamespace('tenant-a', 'Tenant namespace');
        $this->createNamespace('shared', 'Shared namespace');

        $this->bindScriptedQueryWorker([
            [
                'outcome' => 'failed',
                'reason' => 'service_error',
                'message' => 'shared greeter is permanently unavailable',
                'type' => 'SharedGreeterUnavailable',
            ],
        ], 'shared', 'shared-query-worker', 'shared-queries');

        $this->registerQueryWorker('shared-query-worker', 'shared-queries', ['python.queryable'], 'shared');
        $this->startRemoteWorkflow('shared-greeter-workflow', 'shared', 'python.queryable', 'shared-queries');
        $this->createWorkflowQueryOperation(
            [
                'max_attempts' => 3,
                'non_retryable_error_types' => ['SharedGreeterUnavailable'],
            ],
            'shared',
            'permanent-endpoint',
            'permanent-service',
            'permanent-query',
            'shared-greeter-workflow',
        );

        $callerRunId = (string) Str::ulid();
        $response = $this->withHeaders($this->apiHeaders('shared'))
            ->postJson('/api/service-endpoints/permanent-endpoint/services/permanent-service/operations/permanent-query/execute', [
                'arguments' => ['scenario' => 'permanent_failure_preserves_typed_error'],
                'mode_override' => 'sync',
                'wait_for' => 'completed',
                'caller_namespace' => 'tenant-a',
                'caller_workflow_instance_id' => 'nexus-permanent-caller',
                'caller_workflow_run_id' => $callerRunId,
                'idempotency_key' => 'permanent-typed-error',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('caller_observed_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('typed_error_message', 'shared greeter is permanently unavailable')
            ->assertJsonPath('retry_policy.max_attempts', 3)
            ->assertJsonPath('retry_attempt_count', 1);

        $serviceCallId = (string) $response->json('id');
        $detail = $this->withHeaders($this->apiHeaders('shared'))
            ->getJson(sprintf(
                '/api/service-endpoints/permanent-endpoint/services/permanent-service/operations/permanent-query/service-calls/%s',
                $serviceCallId,
            ));

        $detail->assertOk()
            ->assertJsonPath('service_call_id', $serviceCallId)
            ->assertJsonPath('outcome_metadata.service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('caller_observed_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('typed_error_message', 'shared greeter is permanently unavailable')
            ->assertJsonPath('retry_attempt_count', 1);

        $history = $this->withHeaders($this->apiHeaders('tenant-a'))
            ->getJson(sprintf('/api/workflows/nexus-permanent-caller/runs/%s/nexus-operations', $callerRunId));

        $history->assertOk()
            ->assertJsonPath('nexus_operations.0.service_call_id', $serviceCallId)
            ->assertJsonPath('nexus_operations.0.service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('nexus_operations.0.caller_observed_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('nexus_operations.0.typed_error_message', 'shared greeter is permanently unavailable')
            ->assertJsonPath('nexus_operations.0.retry_attempt_count', 1);
    }

    /**
     * @param list<array<string, mixed>> $outcomes
     */
    private function bindScriptedQueryWorker(
        array $outcomes,
        string $namespace = 'default',
        string $workerId = 'python-query-worker',
        string $taskQueue = 'python-queries',
    ): void
    {
        $signals = app(LongPollSignalStore::class);
        $cache = app(ServerPollingCache::class);
        $workerStep = function (array $task) use (&$outcomes, $namespace, $workerId, $taskQueue): void {
            if (($task['status'] ?? null) !== 'pending' || ! is_string($task['query_task_id'] ?? null)) {
                return;
            }

            $poll = $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
            ], $this->workerHeaders($namespace));

            $poll->assertOk()
                ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
                ->assertJsonPath('poll_status', 'leased')
                ->assertJsonPath('task.query_task_id', $task['query_task_id']);

            $outcome = array_shift($outcomes) ?? ['outcome' => 'completed', 'result' => null];
            $queryTaskId = (string) $poll->json('task.query_task_id');
            $leaseOwner = (string) $poll->json('task.lease_owner');
            $attempt = (int) $poll->json('task.query_task_attempt');

            if (($outcome['outcome'] ?? null) === 'failed') {
                $this->postJson("/api/worker/query-tasks/{$queryTaskId}/fail", [
                    'lease_owner' => $leaseOwner,
                    'query_task_attempt' => $attempt,
                    'failure' => [
                        'reason' => $outcome['reason'] ?? 'query_rejected',
                        'message' => $outcome['message'] ?? 'Query failed on the worker.',
                        'type' => $outcome['type'] ?? null,
                    ],
                ], $this->workerHeaders($namespace))->assertOk();

                return;
            }

            $this->postJson("/api/worker/query-tasks/{$queryTaskId}/complete", [
                'lease_owner' => $leaseOwner,
                'query_task_attempt' => $attempt,
                'result' => $outcome['result'] ?? null,
            ], $this->workerHeaders($namespace))->assertOk();
        };

        $longPoller = new class($signals, app(LongPollWaitSlotStore::class), $workerStep) extends LongPoller
        {
            public function __construct(
                LongPollSignalStore $signals,
                LongPollWaitSlotStore $waitSlots,
                private readonly \Closure $workerStep,
            ) {
                parent::__construct($signals, $waitSlots);
            }

            public function until(
                callable $probe,
                callable $ready,
                ?int $timeoutSeconds = null,
                ?int $intervalMilliseconds = null,
                array $wakeChannels = [],
                ?callable $nextProbeAt = null,
                bool $reserveWorkerWaitSlot = false,
                string $waitSlotPool = 'worker',
                ?string $waitSlotNamespace = null,
            ): mixed {
                $value = $probe();

                if ($ready($value)) {
                    return $value;
                }

                if (is_array($value)) {
                    ($this->workerStep)($value);
                    $value = $probe();
                }

                return $value;
            }
        };

        $this->app->instance(WorkflowQueryTaskBroker::class, new WorkflowQueryTaskBroker(
            $cache,
            $longPoller,
            $signals,
            app(ExternalPayloadEnvelopeService::class),
            app(QueryTaskPollRequestStore::class),
        ));
    }

    private function startRemoteWorkflow(
        string $workflowId,
        string $namespace = 'default',
        string $workflowType = 'python.queryable',
        string $taskQueue = 'python-queries',
    ): WorkflowRun
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => $workflowId,
            'workflow_type' => $workflowType,
            'task_queue' => $taskQueue,
            'input' => ['Ada'],
        ], $this->apiHeaders($namespace));

        $start->assertCreated();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail((string) $start->json('run_id'));

        return $run;
    }

    /**
     * @param list<string> $supportedWorkflowTypes
     */
    private function registerQueryWorker(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes,
        string $namespace = 'default',
    ): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => $workerId,
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'runtime' => 'python',
            'sdk_version' => 'durable-workflow-python/0.4.93',
            'build_id' => null,
            'supported_workflow_types' => $supportedWorkflowTypes,
            'workflow_definition_fingerprints' => [],
            'supported_activity_types' => [],
            'capabilities' => ['query_tasks'],
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);
    }

    private function primeQueryTaskPoller(
        string $workerId,
        string $namespace = 'default',
        string $taskQueue = 'python-queries',
    ): void
    {
        $pollingTimeout = config('server.polling.timeout');

        config(['server.polling.timeout' => 0]);

        try {
            $this->postJson('/api/worker/query-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
            ], $this->workerHeaders($namespace))
                ->assertOk()
                ->assertJsonPath('task', null)
                ->assertJsonPath('poll_status', 'empty');
        } finally {
            config(['server.polling.timeout' => $pollingTimeout]);
        }
    }

    /**
     * @param array<string, mixed> $retryPolicy
     * @return array{0: WorkflowServiceEndpoint, 1: WorkflowService, 2: WorkflowServiceOperation}
     */
    private function createWorkflowQueryOperation(
        array $retryPolicy,
        string $namespace = 'default',
        string $endpointName = 'greeter',
        string $serviceName = 'shared',
        string $operationName = 'greet',
        string $workflowInstanceId = 'shared-greeter-workflow',
        string $queryName = 'greet',
    ): array
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => $namespace,
            'endpoint_name' => $endpointName,
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => $namespace,
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => $serviceName,
        ]);

        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => $namespace,
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => $operationName,
            'operation_mode' => 'sync',
            'handler_binding_kind' => 'workflow_query',
            'handler_target_reference' => $queryName,
            'handler_binding' => [
                'workflow_instance_id' => $workflowInstanceId,
                'query_name' => $queryName,
            ],
            'retry_policy' => $retryPolicy,
        ]);

        return [$endpoint, $service, $operation];
    }
}
