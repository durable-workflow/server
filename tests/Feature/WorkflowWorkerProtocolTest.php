<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ActivityTaskPollRequestStore;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\SingleRegionFailoverContract;
use App\Support\WorkerProtocol;
use App\Support\WorkflowTaskLeaseConfiguration;
use App\Support\WorkflowTaskPollRequestStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\StickyExecution;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Support\WorkerHistoryPayloadContract;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowTaskLease;

class WorkflowWorkerProtocolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'file',
        ]);
    }

    public function test_it_starts_workflows_and_completes_workflow_tasks_through_the_external_worker_protocol(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-complete',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
                'business_key' => 'invoice-42',
                'memo' => ['source' => 'server-api'],
                'search_attributes' => ['tenant' => 'acme'],
            ]);

        $start->assertCreated()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-external-worker-complete')
            ->assertJsonPath('workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('outcome', 'started_new');

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}");

        $describe->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('business_key', 'invoice-42')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('run_number', 1)
            ->assertJsonPath('run_count', 1)
            ->assertJsonPath('is_current_run', true)
            ->assertJsonPath('task_queue', 'external-workflows')
            ->assertJsonPath('input.0', 'Ada')
            ->assertJsonPath('memo.source', 'server-api')
            ->assertJsonPath('search_attributes.tenant', 'acme')
            ->assertJsonPath('actions.can_signal', true)
            ->assertJsonPath('actions.can_query', true)
            ->assertJsonPath('actions.can_update', true)
            ->assertJsonPath('actions.can_cancel', true)
            ->assertJsonPath('actions.can_terminate', true);

        $this->registerWorker('php-worker-1', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-1',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath(
                'server_capabilities.supported_workflow_task_commands',
                WorkerProtocol::supportedWorkflowTaskCommands(),
            )
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-1')
            ->assertJsonPath('task.task_queue', 'external-workflows');

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $eventTypes = array_column((array) $poll->json('task.history_events'), 'event_type');

        $this->assertContains('StartAccepted', $eventTypes);
        $this->assertContains('WorkflowStarted', $eventTypes);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec((string) config('workflows.serializer'), [
                            'greeting' => 'Hello, Ada!',
                            'workflow_id' => $workflowId,
                        ]),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', 1)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'completed');

        $showRun = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}");

        $showRun->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.greeting', 'Hello, Ada!')
            ->assertJsonPath('output.workflow_id', $workflowId);

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}/history");

        $history->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2');

        $historyEventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('WorkflowCompleted', $historyEventTypes);
    }

    public function test_late_external_completion_records_the_selected_run_timeout(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-run-timeout',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'execution_timeout_seconds' => 30,
                'run_timeout_seconds' => 1,
                'input' => ['Ada'],
            ]);

        $start->assertCreated();
        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $persistedRun = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame(1, $persistedRun->run_timeout_seconds);
        $this->assertNotNull($persistedRun->run_deadline_at);
        $this->assertNotNull($persistedRun->started_at);
        $this->assertTrue(
            $persistedRun->run_deadline_at->equalTo($persistedRun->started_at->copy()->addSecond()),
        );

        $this->registerWorker('php-worker-timeout', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-timeout',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->travel(2)->seconds();
        $this->assertTrue(now()->gte($persistedRun->run_deadline_at));

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serialize('too late'),
                    ],
                ],
            ]);

        $complete->assertConflict()
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'failed')
            ->assertJsonPath('reason', 'run_timed_out');

        $selectedRun = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}");

        $selectedRun->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('closed_reason', 'timed_out')
            ->assertJsonPath('failure.reason', 'run_timeout')
            ->assertJsonPath('failure.failure_category', 'timeout');

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $runId,
            'event_type' => HistoryEventType::WorkflowTimedOut->value,
        ]);
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $runId,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
        ]);
    }

    public function test_it_starts_remote_durable_types_without_local_registration_and_completes_them_through_the_worker_protocol(): void
    {
        Queue::fake();

        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-remote-durable-type',
                'workflow_type' => 'remote.greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
                'memo' => ['source' => 'remote-client'],
                'search_attributes' => ['team' => 'remote-worker'],
            ]);

        $start->assertCreated()
            ->assertJsonPath('workflow_id', 'wf-remote-durable-type')
            ->assertJsonPath('workflow_type', 'remote.greeting-workflow')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('outcome', 'started_new');

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}");

        $describe->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('workflow_type', 'remote.greeting-workflow')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('task_queue', 'external-workflows')
            ->assertJsonPath('input.0', 'Ada')
            ->assertJsonPath('memo.source', 'remote-client')
            ->assertJsonPath('search_attributes.team', 'remote-worker');

        $this->registerWorker(
            'php-worker-remote',
            'external-workflows',
            supportedWorkflowTypes: ['remote.greeting-workflow'],
        );

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-remote',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_type', 'remote.greeting-workflow')
            ->assertJsonPath('task.task_queue', 'external-workflows');

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $eventTypes = array_column((array) $poll->json('task.history_events'), 'event_type');

        $this->assertContains('StartAccepted', $eventTypes);
        $this->assertContains('WorkflowStarted', $eventTypes);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec((string) config('workflows.serializer'), [
                            'greeting' => 'Hello from remote worker, Ada!',
                            'workflow_id' => $workflowId,
                        ]),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', 1)
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'completed');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('workflow_type', 'remote.greeting-workflow')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.greeting', 'Hello from remote worker, Ada!')
            ->assertJsonPath('output.workflow_id', $workflowId);
    }

    public function test_worker_registration_and_heartbeat_advertise_protocol_capabilities_and_package_fleet_visibility(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders([
            'X-Namespace' => 'default',
        ])->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'php-worker-register-missing-version',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
        ])->assertStatus(400)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'missing_protocol_version')
            ->assertJsonPath('supported_version', WorkerProtocol::VERSION)
            ->assertJsonPath('requested_version', null)
            ->assertJsonStructure(['remediation']);

        $register = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'php-worker-register',
                'task_queue' => 'external-workflows',
                'runtime' => 'php',
                'build_id' => 'build-register',
            ]);

        $register->assertCreated()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('worker_id', 'php-worker-register')
            ->assertJsonPath('registered', true)
            ->assertJsonPath('server_capabilities.long_poll_timeout', 0)
            ->assertJsonPath(
                'server_capabilities.supported_workflow_task_commands',
                WorkerProtocol::supportedWorkflowTaskCommands(),
            );

        $this->withHeaders([
            'X-Namespace' => 'default',
        ])->getJson('/api/cluster/info?include=diagnostics')
            ->assertOk()
            ->assertJsonPath('worker_fleet.namespace', 'default')
            ->assertJsonPath('control_plane.version', '2')
            ->assertJsonPath('control_plane.header', 'X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('control_plane.response_contract.schema', 'durable-workflow.v2.control-plane-response')
            ->assertJsonPath(
                'control_plane.response_contract.contract.schema',
                'durable-workflow.v2.control-plane-response.contract',
            )
            ->assertJsonPath(
                'control_plane.request_contract.schema',
                'durable-workflow.v2.control-plane-request.contract',
            )
            ->assertJsonPath('control_plane.request_contract.version', 1)
            ->assertJsonPath(
                'control_plane.request_contract.operations.start.fields.duplicate_policy.canonical_values.1',
                'use-existing',
            )
            ->assertJsonPath(
                'control_plane.request_contract.operations.update.removed_fields.wait_policy',
                'Use wait_for.',
            )
            ->assertJsonPath('worker_fleet.active_workers', 1)
            ->assertJsonPath('worker_fleet.active_worker_scopes', 1)
            ->assertJsonPath('worker_fleet.build_ids.0', 'build-register')
            ->assertJsonPath('worker_fleet.queues.0', 'external-workflows')
            ->assertJsonPath('worker_fleet.workers.0.worker_id', 'php-worker-register')
            ->assertJsonPath('worker_fleet.workers.0.build_ids.0', 'build-register')
            ->assertJsonPath('worker_fleet.workers.0.queues.0', 'external-workflows');

        WorkerCompatibilityFleet::clear();

        // A shared cross-process throttle suppresses duplicate compatibility
        // row replacement until the next bounded fleet refresh interval.
        $this->travel(11)->seconds();

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'php-worker-register',
            ]);

        $heartbeat->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('worker_id', 'php-worker-register')
            ->assertJsonPath('acknowledged', true)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath(
                'server_capabilities.supported_workflow_task_commands',
                WorkerProtocol::supportedWorkflowTaskCommands(),
            );

        $this->withHeaders([
            'X-Namespace' => 'default',
        ])->getJson('/api/cluster/info?include=diagnostics')
            ->assertOk()
            ->assertJsonPath('worker_protocol.version', WorkerProtocol::VERSION)
            ->assertJsonPath('worker_fleet.active_workers', 1)
            ->assertJsonPath('worker_fleet.build_ids.0', 'build-register')
            ->assertJsonPath(
                'worker_protocol.server_capabilities.supported_workflow_task_commands.2',
                'continue_as_new',
            );

        $this->withHeaders([
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Protocol-Version' => '999',
        ])->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'php-worker-register-unsupported',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
        ])->assertStatus(400)
            ->assertJsonPath('reason', 'unsupported_protocol_version')
            ->assertJsonPath('supported_version', WorkerProtocol::VERSION)
            ->assertJsonPath('requested_version', '999')
            ->assertJsonStructure(['remediation']);
    }

    public function test_worker_registration_accepts_first_party_rust_runtime(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $register = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'rust-worker-register',
                'task_queue' => 'rust-workers',
                'runtime' => 'rust',
                'sdk_version' => 'durable-workflow-rust/0.1.0',
                'supported_workflow_types' => ['hello.workflow'],
                'supported_activity_types' => ['hello.activity'],
            ]);

        $register->assertCreated()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('worker_id', 'rust-worker-register')
            ->assertJsonPath('registered', true);

        $this->assertDatabaseHas('workflow_worker_registrations', [
            'namespace' => 'default',
            'worker_id' => 'rust-worker-register',
            'task_queue' => 'rust-workers',
            'runtime' => 'rust',
            'sdk_version' => 'durable-workflow-rust/0.1.0',
            'status' => 'active',
        ]);
    }

    public function test_portable_worker_affinity_capability_manifest_is_truthful_and_persisted(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $refusalManifest = $this->portableWorkerAffinityRefusalManifest();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'python-omitting-portable-affinity',
                'task_queue' => 'portable-affinity',
                'runtime' => 'python',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capability_manifest']);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'python-partial-portable-affinity',
                'task_queue' => 'portable-affinity',
                'runtime' => 'python',
                'capability_manifest' => [
                    'local_activities' => $refusalManifest['local_activities'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capability_manifest']);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'python-refusing-portable-affinity',
                'task_queue' => 'portable-affinity',
                'runtime' => 'python',
                'capability_manifest' => $refusalManifest,
            ])
            ->assertCreated()
            ->assertJsonPath('capability_manifest', $refusalManifest);

        $this->assertSame(
            $refusalManifest,
            WorkerRegistration::query()
                ->where('worker_id', 'python-refusing-portable-affinity')
                ->firstOrFail()
                ->capability_manifest,
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'worker_id' => 'php-optimistic-portable-affinity',
                'task_queue' => 'portable-affinity',
                'runtime' => 'php',
                'capabilities' => ['local_activities'],
                'capability_manifest' => $refusalManifest,
            ])
            ->assertConflict()
            ->assertJsonPath('reason', 'worker_capability_manifest_mismatch')
            ->assertJsonPath('capability', 'local_activities');
    }

    public function test_local_activity_completion_fails_closed_without_registered_support(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'wf-local-activity-refused',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'portable-affinity',
            'input' => ['Ada'],
        ])->assertCreated();

        $this->withHeaders($this->workerHeaders())->postJson('/api/worker/register', [
            'worker_id' => 'python-local-activity-refused',
            'task_queue' => 'portable-affinity',
            'runtime' => 'python',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capability_manifest' => array_replace($this->portableWorkerAffinityRefusalManifest(), [
                'local_activities' => [
                    'supported' => false,
                    'minimum_protocol_version' => '1.18',
                    'reason' => 'not_implemented',
                ],
            ]),
        ])->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'python-local-activity-refused',
            'task_queue' => 'portable-affinity',
        ])->assertOk();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => 'python-local-activity-refused',
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'record_local_activity',
                    'activity_type' => 'charge-card',
                    'arguments' => Serializer::serialize(['order-1']),
                    'result' => Serializer::serialize('receipt-1'),
                    'outcome' => 'completed',
                    'attempts' => [['attempt_number' => 1, 'outcome' => 'completed']],
                ]],
            ])
            ->assertConflict()
            ->assertJsonPath('reason', 'worker_capability_not_supported')
            ->assertJsonPath('capability', 'local_activities')
            ->assertJsonPath('recorded', false);

        $this->assertSame('pending', $start->json('status'));
    }

    public function test_local_activity_completion_records_one_atomic_replay_sequence(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'wf-local-activity-supported',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'portable-affinity',
            'input' => ['Ada'],
        ])->assertCreated();
        $runId = (string) $start->json('run_id');

        $this->withHeaders($this->workerHeaders())->postJson('/api/worker/register', [
            'worker_id' => 'php-local-activity-supported',
            'task_queue' => 'portable-affinity',
            'runtime' => 'php',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => ['local_activities'],
            'capability_manifest' => array_replace($this->portableWorkerAffinityRefusalManifest(), [
                'local_activities' => [
                    'supported' => true,
                    'minimum_protocol_version' => '1.18',
                    'implementation' => 'record_local_activity',
                ],
            ]),
        ])->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'php-local-activity-supported',
            'task_queue' => 'portable-affinity',
        ])->assertOk();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => 'php-local-activity-supported',
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'record_local_activity',
                    'activity_type' => 'charge-card',
                    'result' => Serializer::serialize('receipt-1'),
                    'outcome' => 'completed',
                    'attempts' => [
                        [
                            'attempt_number' => 1,
                            'outcome' => 'failed',
                            'message' => 'retry once',
                            'retry_reason' => 'failure',
                            'backoff_seconds' => 1,
                        ],
                        ['attempt_number' => 1, 'outcome' => 'completed'],
                    ],
                    'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [1]],
                    'execution_mode' => 'local',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['commands.0.attempts.1.attempt_number']);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                'lease_owner' => 'php-local-activity-supported',
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'record_local_activity',
                        'activity_type' => 'charge-card',
                        'arguments' => Serializer::serialize(['order-1']),
                        'result' => Serializer::serialize('receipt-1'),
                        'outcome' => 'completed',
                        'attempts' => [
                            [
                                'attempt_id' => 'local-attempt-1',
                                'attempt_number' => 1,
                                'outcome' => 'failed',
                                'duration_ms' => 12,
                                'message' => 'retry once',
                                'retry_reason' => 'failure',
                                'backoff_seconds' => 1,
                            ],
                            [
                                'attempt_id' => 'local-attempt-2',
                                'attempt_number' => 2,
                                'outcome' => 'completed',
                                'duration_ms' => 7,
                            ],
                        ],
                        'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [1]],
                        'execution_mode' => 'local',
                    ],
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serialize(['Ada']),
                        'queue' => 'external-activities',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->where('activity_type', 'charge-card')
            ->sole();
        $this->assertSame('charge-card', $execution->activity_type);
        $this->assertSame(2, $execution->attempt_count);
        $this->assertSame('receipt-1', $execution->activityResult());
        $attempts = ActivityAttempt::query()
            ->where('activity_execution_id', $execution->id)
            ->orderBy('attempt_number')
            ->get();
        $this->assertSame(['failed', 'completed'], $attempts->pluck('status')
            ->map(static fn (ActivityAttemptStatus $status): string => $status->value)
            ->all());
        $this->assertSame(
            ['local-attempt-1', 'local-attempt-2'],
            $attempts->pluck('worker_attempt_id')->all(),
        );
        $this->assertSame((string) $attempts->last()->id, $execution->current_attempt_id);
        $this->assertSame(0, WorkflowFailure::query()
            ->where('source_kind', 'activity_execution')
            ->where('source_id', $execution->id)
            ->count());
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->count());
        $this->assertSame([
            HistoryEventType::ActivityScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityRetryScheduled->value,
            HistoryEventType::ActivityStarted->value,
            HistoryEventType::ActivityCompleted->value,
            HistoryEventType::ActivityScheduled->value,
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityRetryScheduled->value,
                HistoryEventType::ActivityCompleted->value,
            ])
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $eventType): string => $eventType->value)
            ->all());
    }

    public function test_local_activity_completion_uses_the_negotiated_attempt_report_grammar(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        foreach (['1.18', '1.19'] as $protocolVersion) {
            $workerId = 'php-local-activity-'.$protocolVersion;
            $workflowId = 'wf-local-activity-'.$protocolVersion;
            $headers = $this->workerHeaders(protocolVersion: $protocolVersion);
            $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'portable-affinity-'.$protocolVersion,
                'input' => ['Ada'],
            ])->assertCreated();

            $this->withHeaders($headers)->postJson('/api/worker/register', [
                'worker_id' => $workerId,
                'task_queue' => 'portable-affinity-'.$protocolVersion,
                'runtime' => 'php',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'capabilities' => ['local_activities'],
                'capability_manifest' => array_replace($this->portableWorkerAffinityRefusalManifest(), [
                    'local_activities' => [
                        'supported' => true,
                        'minimum_protocol_version' => '1.18',
                        'implementation' => 'record_local_activity',
                    ],
                ]),
            ])->assertCreated();

            $poll = $this->withHeaders($headers)->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => 'portable-affinity-'.$protocolVersion,
            ])->assertOk();
            $command = [
                'type' => 'record_local_activity',
                'activity_type' => 'charge-card',
                'result' => Serializer::serialize('receipt-'.$protocolVersion),
                'outcome' => 'completed',
            ];
            $completion = $this->withHeaders($headers)
                ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                    'lease_owner' => $workerId,
                    'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                    'commands' => [$command],
                ]);

            if ($protocolVersion === '1.18') {
                $completion->assertOk()
                    ->assertJsonPath('outcome', 'completed')
                    ->assertJsonPath('run_status', 'waiting');
                $execution = ActivityExecution::query()
                    ->where('workflow_run_id', $start->json('run_id'))
                    ->sole();
                $attempt = ActivityAttempt::query()
                    ->where('activity_execution_id', $execution->id)
                    ->sole();
                $this->assertSame(1, $execution->attempt_count);
                $this->assertNull($attempt->worker_attempt_id);

                continue;
            }

            $completion->assertUnprocessable()
                ->assertJsonValidationErrors(['commands.0.attempts']);
            $this->assertSame(
                TaskStatus::Leased,
                WorkflowTask::query()->findOrFail($poll->json('task.task_id'))->status,
            );
            $this->assertSame(0, ActivityExecution::query()
                ->where('workflow_run_id', $start->json('run_id'))
                ->count());
        }
    }

    public function test_local_activity_completion_uses_negotiated_nested_object_strictness(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        foreach (['1.18', '1.19'] as $protocolVersion) {
            $workerId = 'php-local-activity-extensions-'.$protocolVersion;
            $workflowId = 'wf-local-activity-extensions-'.$protocolVersion;
            $taskQueue = 'portable-affinity-extensions-'.$protocolVersion;
            $headers = $this->workerHeaders(protocolVersion: $protocolVersion);
            $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
                'workflow_id' => $workflowId,
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => $taskQueue,
                'input' => ['Ada'],
            ])->assertCreated();

            $this->withHeaders($headers)->postJson('/api/worker/register', [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => ['tests.external-greeting-workflow'],
                'capabilities' => ['local_activities'],
                'capability_manifest' => array_replace($this->portableWorkerAffinityRefusalManifest(), [
                    'local_activities' => [
                        'supported' => true,
                        'minimum_protocol_version' => '1.18',
                        'implementation' => 'record_local_activity',
                    ],
                ]),
            ])->assertCreated();

            $poll = $this->withHeaders($headers)->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
            ])->assertOk();
            $command = [
                'type' => 'record_local_activity',
                'activity_type' => 'charge-card',
                'result' => Serializer::serialize('receipt-'.$protocolVersion),
                'outcome' => 'completed',
                'attempts' => [[
                    'attempt_id' => 'worker-attempt-'.$protocolVersion,
                    'attempt_number' => 1,
                    'outcome' => 'completed',
                    'duration_ms' => 10,
                    'worker_extension' => ['build' => 'next'],
                    'heartbeats' => [[
                        'elapsed_ms' => 5,
                        'heartbeat_extension' => true,
                    ]],
                ]],
                'retry_policy' => [
                    'max_attempts' => 1,
                    'retry_extension' => 'future-policy',
                ],
            ];
            $completion = $this->withHeaders($headers)
                ->postJson('/api/worker/workflow-tasks/'.$poll->json('task.task_id').'/complete', [
                    'lease_owner' => $workerId,
                    'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                    'commands' => [$command],
                ]);

            if ($protocolVersion === '1.18') {
                $completion->assertOk()
                    ->assertJsonPath('outcome', 'completed')
                    ->assertJsonPath('run_status', 'waiting');
                $execution = ActivityExecution::query()
                    ->where('workflow_run_id', $start->json('run_id'))
                    ->sole();
                $attempt = ActivityAttempt::query()
                    ->where('activity_execution_id', $execution->id)
                    ->sole();
                $this->assertSame('worker-attempt-1.18', $attempt->worker_attempt_id);
                $this->assertNotNull($attempt->last_heartbeat_at);

                continue;
            }

            $completion->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'commands.0.attempts.0.worker_extension',
                    'commands.0.attempts.0.heartbeats.0.heartbeat_extension',
                    'commands.0.retry_policy.retry_extension',
                ]);
            $this->assertSame(
                TaskStatus::Leased,
                WorkflowTask::query()->findOrFail($poll->json('task.task_id'))->status,
            );
            $this->assertSame(0, ActivityExecution::query()
                ->where('workflow_run_id', $start->json('run_id'))
                ->count());
        }
    }

    public function test_sticky_claim_requires_exact_identity_and_persists_only_as_an_optimization(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'wf-sticky-service-worker',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'portable-affinity',
            'input' => ['Ada'],
        ])->assertCreated();
        $runId = (string) $start->json('run_id');

        $capabilities = ['local_activities', 'worker_sessions', 'sticky_execution'];
        $manifest = [];
        foreach ($capabilities as $capability) {
            $manifest[$capability] = [
                'supported' => true,
                'minimum_protocol_version' => '1.18',
                'implementation' => 'php-worker',
            ];
        }
        $this->withHeaders($this->workerHeaders())->postJson('/api/worker/register', [
            'worker_id' => 'php-sticky-worker',
            'task_queue' => 'portable-affinity',
            'runtime' => 'php',
            'sdk_version' => 'durable-workflow-php/test',
            'build_id' => 'build-a',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'capabilities' => $capabilities,
            'capability_manifest' => $manifest,
        ])->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'php-sticky-worker',
            'task_queue' => 'portable-affinity',
            'build_id' => 'build-a',
        ])->assertOk();
        $taskId = (string) $poll->json('task.task_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-sticky-worker',
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [['type' => 'start_timer', 'delay_seconds' => 5]],
                'sticky_cache' => [
                    'worker_id' => 'php-sticky-worker',
                    'workflow_id' => 'wf-sticky-service-worker',
                    'run_id' => $runId,
                    'build_id' => 'build-a',
                    'ttl_seconds' => 120,
                    'metrics' => ['hit' => 1, 'miss' => 2, 'eviction' => 3, 'forced_cold_replay' => 4],
                ],
            ])
            ->assertOk();

        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame('php-sticky-worker', $run->sticky_worker_id);
        $this->assertNotNull($run->sticky_until);
        $this->assertSame(
            ['hit' => 1, 'miss' => 2, 'eviction' => 3, 'forced_cold_replay' => 4],
            WorkerRegistration::query()
                ->where('worker_id', 'php-sticky-worker')
                ->firstOrFail()
                ->process_metrics['sticky_cache'],
        );
    }

    public function test_worker_heartbeat_is_scoped_to_the_resolved_namespace(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->createNamespace('other', 'Other namespace');
        $this->createNamespace('missing', 'Namespace with no matching worker');

        $otherHeartbeatAt = now()->subHours(2)->startOfSecond();
        $defaultHeartbeatAt = now()->subHour()->startOfSecond();

        WorkerRegistration::query()->create([
            'worker_id' => 'php-worker-shared-id',
            'namespace' => 'other',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'last_heartbeat_at' => $otherHeartbeatAt,
            'status' => 'active',
        ]);

        WorkerRegistration::query()->create([
            'worker_id' => 'php-worker-shared-id',
            'namespace' => 'default',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'last_heartbeat_at' => $defaultHeartbeatAt,
            'status' => 'active',
        ]);

        $heartbeatAt = now()->addMinutes(5)->startOfSecond();
        $this->travelTo($heartbeatAt);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'php-worker-shared-id',
            ])
            ->assertOk()
            ->assertJsonPath('worker_id', 'php-worker-shared-id')
            ->assertJsonPath('acknowledged', true);

        $defaultWorker = WorkerRegistration::query()
            ->where('worker_id', 'php-worker-shared-id')
            ->where('namespace', 'default')
            ->firstOrFail();
        $otherWorker = WorkerRegistration::query()
            ->where('worker_id', 'php-worker-shared-id')
            ->where('namespace', 'other')
            ->firstOrFail();

        $this->assertSame($heartbeatAt->toJSON(), $defaultWorker->last_heartbeat_at?->toJSON());
        $this->assertSame('active', $defaultWorker->status);
        $this->assertSame($otherHeartbeatAt->toJSON(), $otherWorker->last_heartbeat_at?->toJSON());

        $this->withHeaders($this->workerHeaders(namespace: 'missing'))
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'php-worker-shared-id',
            ])
            ->assertNotFound()
            ->assertJsonPath('error', 'Worker not registered.')
            ->assertJsonPath('reason', 'worker_not_registered')
            ->assertJsonPath('worker_id', 'php-worker-shared-id');
    }

    public function test_it_scopes_workflow_task_polling_by_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');
        $this->createNamespace('other', 'Other namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-hidden-workflow-task',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-2', 'external-workflows', 'other');

        $this->withHeaders($this->workerHeaders(namespace: 'other'))
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-2',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
    }

    public function test_duplicate_empty_poll_request_ids_replay_the_same_poll_status(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-empty-poll', 'external-workflows');

        $first = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-empty-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'empty-poll-request-1',
            ]);

        $first->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $second = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-empty-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'empty-poll-request-1',
            ]);

        $second->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');
    }

    public function test_it_uses_a_server_local_lease_counter_for_workflow_task_attempts(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-poll-discovery',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'existing-worker',
            'attempt_count' => 0,
        ])->save();

        $leaseExpiresAt = now()->addMinutes(5)->toJSON();
        $recordedAt = now()->toJSON();
        $duplicateLeaseExpiresAt = now()->addMinutes(10)->toJSON();
        $historyPayload = [
            'task_id' => $task->id,
            'workflow_run_id' => $runId,
            'workflow_instance_id' => $workflowId,
            'namespace' => 'default',
            'workflow_type' => 'tests.external-greeting-workflow',
            'workflow_class' => ExternalGreetingWorkflow::class,
            'payload_codec' => (string) config('workflows.serializer'),
            'arguments' => null,
            'arguments_envelope' => null,
            'run_status' => 'pending',
            'sticky_worker_id' => null,
            'sticky_until' => null,
            'sticky_replay_mode' => null,
            'last_history_sequence' => 2,
            'total_history_events' => 2,
            'history_size_bytes' => 512,
            'history_fan_out' => 1,
            'continue_as_new_recommended' => false,
            'history_budget_pressure' => 'ok',
            'history_budget_pressure_dimensions' => [],
            'history_events' => [
                [
                    'id' => 'evt-start-accepted',
                    'sequence' => 1,
                    'event_type' => 'StartAccepted',
                    'payload' => [],
                    'workflow_task_id' => null,
                    'workflow_command_id' => null,
                    'recorded_at' => $recordedAt,
                ],
                [
                    'id' => 'evt-workflow-started',
                    'sequence' => 2,
                    'event_type' => 'WorkflowStarted',
                    'payload' => [],
                    'workflow_task_id' => null,
                    'workflow_command_id' => null,
                    'recorded_at' => $recordedAt,
                ],
            ],
        ];
        $claimCalls = 0;

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use (
            $leaseExpiresAt,
            $duplicateLeaseExpiresAt,
            $recordedAt,
            $runId,
            $task,
            $workflowId,
            $historyPayload,
            &$claimCalls,
        ): void {
            $mock->shouldReceive('poll')
                ->times(2)
                ->with(null, 'external-workflows', 10, null, 'default', ['tests.external-greeting-workflow'])
                ->andReturn(
                    [[
                        'task_id' => $task->id,
                        'workflow_run_id' => $runId,
                        'workflow_instance_id' => $workflowId,
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'workflow_class' => ExternalGreetingWorkflow::class,
                        'connection' => null,
                        'queue' => 'external-workflows',
                        'compatibility' => null,
                        'available_at' => $recordedAt,
                    ]],
                    [[
                        'task_id' => $task->id,
                        'workflow_run_id' => $runId,
                        'workflow_instance_id' => $workflowId,
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'workflow_class' => ExternalGreetingWorkflow::class,
                        'connection' => null,
                        'queue' => 'external-workflows',
                        'compatibility' => null,
                        'available_at' => $recordedAt,
                    ]],
                );

            $mock->shouldReceive('claimStatus')
                ->times(2)
                ->andReturnUsing(function (string $claimedTaskId, string $leaseOwner) use (
                    &$claimCalls,
                    $leaseExpiresAt,
                    $duplicateLeaseExpiresAt,
                    $runId,
                    $task,
                    $workflowId,
                ): array {
                    $claimCalls++;
                    $this->assertSame($task->id, $claimedTaskId);
                    $this->assertSame('php-worker-bridge', $leaseOwner);

                    return [
                        'claimed' => true,
                        'task_id' => $task->id,
                        'workflow_run_id' => $runId,
                        'workflow_instance_id' => $workflowId,
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'workflow_class' => ExternalGreetingWorkflow::class,
                        'payload_codec' => (string) config('workflows.serializer'),
                        'connection' => null,
                        'queue' => 'external-workflows',
                        'compatibility' => null,
                        'lease_owner' => $leaseOwner,
                        'lease_expires_at' => match ($claimCalls) {
                            1 => $leaseExpiresAt,
                            default => $duplicateLeaseExpiresAt,
                        },
                        'reason' => null,
                        'reason_detail' => null,
                    ];
                });

            $mock->shouldReceive('historyPayload')
                ->times(2)
                ->with($task->id)
                ->andReturn($historyPayload, $historyPayload);

            $mock->shouldReceive('heartbeat')
                ->andReturnUsing(function (string $heartbeatTaskId) use ($leaseExpiresAt): array {
                    return [
                        'renewed' => true,
                        'lease_expires_at' => $leaseExpiresAt,
                        'run_status' => 'pending',
                        'task_status' => 'leased',
                        'reason' => null,
                    ];
                });

            $mock->shouldReceive('status')
                ->andReturnUsing(function (string $taskId) {
                    return app()->make(DefaultWorkflowTaskBridge::class)->status($taskId);
                });
        });

        $this->registerWorker('php-worker-bridge', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-bridge',
                'task_queue' => 'external-workflows',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.task_id', $task->id)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-bridge')
            ->assertJsonPath('task.total_history_events', 2)
            ->assertJsonPath('task.history_size_bytes', 512)
            ->assertJsonPath('task.history_fan_out', 1)
            ->assertJsonPath('task.continue_as_new_recommended', false)
            ->assertJsonPath('task.history_budget_pressure', 'ok')
            ->assertJsonPath('task.history_budget_pressure_dimensions', []);

        // The server sources workflow_task_attempt from the package's
        // authoritative attempt_count, normalized to >= 1 for the protocol.
        $firstAttempt = $firstPoll->json('task.workflow_task_attempt');
        $this->assertGreaterThanOrEqual(1, $firstAttempt);

        // Simulate the DB side-effect that the real bridge's claimStatus()
        // performs — the mock doesn't touch the DB, so we mirror the claim
        // state manually so the ownership guard can verify it.
        $task->forceFill([
            'lease_owner' => 'php-worker-bridge',
            'lease_expires_at' => now()->addMinutes(5),
            'attempt_count' => 1,
        ])->save();

        // Duplicate polls return the same attempt value for the same lease.
        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-bridge',
                'task_queue' => 'external-workflows',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $task->id)
            ->assertJsonPath('task.workflow_task_attempt', $firstAttempt)
            ->assertJsonPath('task.lease_owner', 'php-worker-bridge');

        // The ownership guard fences stale workers using the package's
        // lease_owner and attempt_count directly.
        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$task->id}/heartbeat", [
                'lease_owner' => 'php-worker-bridge',
                'workflow_task_attempt' => $firstAttempt,
            ])
            ->assertOk()
            ->assertJsonPath('renewed', true);

        // Wrong attempt number is fenced.
        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$task->id}/heartbeat", [
                'lease_owner' => 'php-worker-bridge',
                'workflow_task_attempt' => $firstAttempt + 999,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');
    }

    public function test_it_redelivers_the_same_workflow_task_for_duplicate_poll_request_ids(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $firstStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-a',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $firstStart->assertCreated();

        $secondStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-b',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $secondStart->assertCreated();

        $this->registerWorker(
            'php-worker-duplicate-poll',
            'external-workflows',
            maxConcurrentWorkflowTasks: 3,
        );
        $this->registerWorker('php-worker-other', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-poll');

        $taskId = (string) $firstPoll->json('task.task_id');
        $workflowId = (string) $firstPoll->json('task.workflow_id');
        $attempt = (int) $firstPoll->json('task.workflow_task_attempt');
        $this->assertContains($workflowId, [
            'wf-duplicate-poll-request-a',
            'wf-duplicate-poll-request-b',
        ]);

        app(WorkflowTaskPollRequestStore::class)->forgetResult(
            'default',
            'external-workflows',
            null,
            'php-worker-duplicate-poll',
            'poll-request-1',
        );

        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-1',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.workflow_task_attempt', $attempt)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-poll');

        $freshPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-2',
            ]);

        $freshPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-poll');

        $freshTaskId = (string) $freshPoll->json('task.task_id');
        $freshWorkflowId = (string) $freshPoll->json('task.workflow_id');
        $this->assertNotSame($taskId, $freshTaskId);
        $this->assertNotSame($workflowId, $freshWorkflowId);
        $this->assertContains($freshWorkflowId, [
            'wf-duplicate-poll-request-a',
            'wf-duplicate-poll-request-b',
        ]);

        $nextConcurrentSlot = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-poll',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-3',
                'timeout_seconds' => 0,
            ]);

        $nextConcurrentSlot->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-other',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-1',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $taskRow = WorkflowTask::query()->findOrFail($taskId);
        $freshTaskRow = WorkflowTask::query()->findOrFail($freshTaskId);

        $this->assertSame(1, $taskRow->attempt_count);
        $this->assertSame(1, $freshTaskRow->attempt_count);
    }

    public function test_duplicate_poll_request_redelivery_is_scoped_by_task_queue(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-queue-a',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-a',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-queue-b',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-b',
                'input' => ['Grace'],
            ])
            ->assertCreated();

        $this->registerWorker('php-worker-shared-poll-request', 'external-workflows-a');

        $queueAPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-shared-poll-request',
                'task_queue' => 'external-workflows-a',
                'poll_request_id' => 'shared-poll-request',
            ]);

        $queueAPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-queue-a')
            ->assertJsonPath('task.task_queue', 'external-workflows-a')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $queueATaskId = (string) $queueAPoll->json('task.task_id');

        $this->registerWorker('php-worker-shared-poll-request', 'external-workflows-b');

        $queueBPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-shared-poll-request',
                'task_queue' => 'external-workflows-b',
                'poll_request_id' => 'shared-poll-request',
            ]);

        $queueBPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-queue-b')
            ->assertJsonPath('task.task_queue', 'external-workflows-b')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $queueBTaskId = (string) $queueBPoll->json('task.task_id');

        $this->registerWorker('php-worker-shared-poll-request', 'external-workflows-a');

        $duplicateQueueA = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-shared-poll-request',
                'task_queue' => 'external-workflows-a',
                'poll_request_id' => 'shared-poll-request',
            ]);

        $duplicateQueueA->assertOk()
            ->assertJsonPath('task.task_id', $queueATaskId)
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-queue-a')
            ->assertJsonPath('task.task_queue', 'external-workflows-a')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $this->registerWorker('php-worker-shared-poll-request', 'external-workflows-b');

        $duplicateQueueB = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-shared-poll-request',
                'task_queue' => 'external-workflows-b',
                'poll_request_id' => 'shared-poll-request',
            ]);

        $duplicateQueueB->assertOk()
            ->assertJsonPath('task.task_id', $queueBTaskId)
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-queue-b')
            ->assertJsonPath('task.task_queue', 'external-workflows-b')
            ->assertJsonPath('task.workflow_task_attempt', 1);
    }

    public function test_restarted_worker_completes_signaled_workflow_without_replaying_the_observed_lease_to_another_slot(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-restarted-worker-signal-slot',
                'workflow_type' => 'tests.interactive-command-workflow',
                'task_queue' => 'external-workflows',
            ])
            ->assertCreated();

        $registration = [
            'worker_id' => 'php-worker-restarted-signal-slot',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.interactive-command-workflow'],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 3,
            'max_concurrent_activity_tasks' => 0,
            'process_metrics' => [
                'host' => 'worker-host',
                'process_id' => 101,
                'process_started_at' => '2026-07-20T00:00:00Z',
            ],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $initialPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $registration['worker_id'],
                'task_queue' => $registration['task_queue'],
                'poll_request_id' => 'restarted-signal-initial-poll',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-restarted-worker-signal-slot');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$initialPoll->json('task.task_id').'/complete', [
                'lease_owner' => $registration['worker_id'],
                'workflow_task_attempt' => $initialPoll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'open_signal_wait',
                    'signal_name' => 'advance',
                    'timeout_seconds' => 45,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $registration['process_metrics'] = [
            'host' => 'worker-host',
            'process_id' => 202,
            'process_started_at' => '2026-07-20T00:01:00Z',
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-restarted-worker-signal-slot/signal/advance', [
                'input' => ['continue'],
                'request_id' => 'restarted-worker-signal',
            ])
            ->assertAccepted();

        $signalPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $registration['worker_id'],
                'task_queue' => $registration['task_queue'],
                'poll_request_id' => 'restarted-signal-poll-1',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-restarted-worker-signal-slot');

        app(WorkflowTaskPollRequestStore::class)->forgetResult(
            'default',
            'external-workflows',
            null,
            'php-worker-restarted-signal-slot',
            'restarted-signal-poll-1',
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $registration['worker_id'],
                'task_queue' => $registration['task_queue'],
                'poll_request_id' => 'restarted-signal-poll-1',
            ])
            ->assertOk()
            ->assertJsonPath('task.task_id', $signalPoll->json('task.task_id'));

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => $registration['worker_id'],
                'task_queue' => $registration['task_queue'],
                'poll_request_id' => 'restarted-signal-poll-2',
                'timeout_seconds' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$signalPoll->json('task.task_id').'/complete', [
                'lease_owner' => $registration['worker_id'],
                'workflow_task_attempt' => $signalPoll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'complete_workflow',
                    'result' => Serializer::serializeWithCodec('avro', ['completed' => true]),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-restarted-worker-signal-slot')
            ->assertOk()
            ->assertJsonPath('run_id', $start->json('run_id'))
            ->assertJsonPath('status', 'completed');
    }

    public function test_it_replays_cached_duplicate_poll_request_results_even_if_the_lease_row_is_missing(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-cache',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-cache', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-cache',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-cache-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-cache')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-cache');

        $taskId = (string) $firstPoll->json('task.task_id');

        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-cache',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-cache-1',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-cache');
    }

    public function test_it_replays_cached_duplicate_poll_request_results_after_the_old_short_cache_window_when_the_lease_is_still_active(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-cache-late-retry',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-cache-late', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-cache-late',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-cache-late-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-cache-late-retry')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-cache-late');

        $taskId = (string) $firstPoll->json('task.task_id');

        $this->travel(10)->seconds();

        try {
            $duplicatePoll = $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/workflow-tasks/poll', [
                    'worker_id' => 'php-worker-duplicate-cache-late',
                    'task_queue' => 'external-workflows',
                    'poll_request_id' => 'poll-request-cache-late-1',
                ]);

            $duplicatePoll->assertOk()
                ->assertJsonPath('task.task_id', $taskId)
                ->assertJsonPath('task.workflow_task_attempt', 1)
                ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-cache-late');
        } finally {
            $this->travelBack();
        }
    }

    public function test_it_redelivers_the_same_activity_task_for_duplicate_poll_request_ids(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-activity-poll-request',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-activity-scheduler', 'external-workflows');
        $this->registerWorker(
            'php-worker-duplicate-activity-poll',
            'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            maxConcurrentActivityTasks: 3,
        );

        $workflowPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-activity-scheduler',
                'task_queue' => 'external-workflows',
            ]);

        $workflowPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-activity-poll-request')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $workflowTaskId = (string) $workflowPoll->json('task.task_id');
        $workflowAttempt = (int) $workflowPoll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$workflowTaskId}/complete", [
                'lease_owner' => 'php-worker-activity-scheduler',
                'workflow_task_attempt' => $workflowAttempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec((string) config('workflows.serializer'), ['Ada']),
                        'queue' => 'external-activities',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-activity-poll',
                'task_queue' => 'external-activities',
                'poll_request_id' => 'activity-poll-request-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-activity-poll-request')
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-activity-poll');

        $activityTaskId = (string) $firstPoll->json('task.task_id');
        $activityAttemptId = (string) $firstPoll->json('task.activity_attempt_id');

        app(ActivityTaskPollRequestStore::class)->forgetResult(
            'default',
            'external-activities',
            null,
            'php-worker-duplicate-activity-poll',
            'activity-poll-request-1',
        );

        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-activity-poll',
                'task_queue' => 'external-activities',
                'poll_request_id' => 'activity-poll-request-1',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $activityTaskId)
            ->assertJsonPath('task.activity_attempt_id', $activityAttemptId)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-activity-poll');

        $freshPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-activity-poll',
                'task_queue' => 'external-activities',
                'poll_request_id' => 'activity-poll-request-2',
                'timeout_seconds' => 0,
            ]);

        $freshPoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$activityTaskId}/complete", [
                'activity_attempt_id' => $activityAttemptId,
                'lease_owner' => 'php-worker-duplicate-activity-poll',
                'result' => 'Hello, Ada!',
            ])
            ->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true);

        $this->assertSame(1, ActivityAttempt::query()->whereKey($activityAttemptId)->count());
    }

    public function test_idless_activity_polls_from_concurrent_slots_do_not_share_an_active_lease(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-idless-activity-poll-slots',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('php-worker-idless-activity-scheduler', 'external-workflows');
        $this->registerWorker(
            'php-worker-idless-activity-slots',
            'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            maxConcurrentActivityTasks: 3,
        );

        $workflowPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-idless-activity-scheduler',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-idless-activity-poll-slots');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/'.$workflowPoll->json('task.task_id').'/complete', [
                'lease_owner' => 'php-worker-idless-activity-scheduler',
                'workflow_task_attempt' => $workflowPoll->json('task.workflow_task_attempt'),
                'commands' => [[
                    'type' => 'schedule_activity',
                    'activity_type' => 'tests.external-greeting-activity',
                    'arguments' => Serializer::serializeWithCodec((string) config('workflows.serializer'), ['Ada']),
                    'queue' => 'external-activities',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true);

        $firstSlot = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-idless-activity-slots',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-idless-activity-poll-slots');

        $taskId = (string) $firstSlot->json('task.task_id');
        $activityAttemptId = (string) $firstSlot->json('task.activity_attempt_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-idless-activity-slots',
                'task_queue' => 'external-activities',
                'timeout_seconds' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'empty');

        $this->assertSame(1, ActivityAttempt::query()->whereKey($activityAttemptId)->count());
        $this->assertSame(1, WorkflowTask::query()->whereKey($taskId)->where('attempt_count', 1)->count());
    }

    public function test_it_does_not_replay_cached_duplicate_poll_results_after_the_task_is_completed(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-completed',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-complete', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-complete',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-complete-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-completed')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-complete');

        $taskId = (string) $firstPoll->json('task.task_id');
        $attempt = (int) $firstPoll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $firstPoll->json('task.lease_owner');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', [
                            'greeting' => 'Hello, Ada!',
                        ]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-complete',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-complete-1',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_duplicate_poll_request_redelivery_refreshes_live_lease_metadata_after_a_heartbeat(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-duplicate-poll-request-heartbeat-refresh',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-duplicate-heartbeat', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-heartbeat',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-heartbeat-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-duplicate-poll-request-heartbeat-refresh')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-heartbeat');

        $taskId = (string) $firstPoll->json('task.task_id');
        $attempt = (int) $firstPoll->json('task.workflow_task_attempt');
        $initialLeaseExpiresAt = (string) $firstPoll->json('task.lease_expires_at');

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => 'php-worker-duplicate-heartbeat',
                'workflow_task_attempt' => $attempt,
            ]);

        $heartbeat->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'php-worker-duplicate-heartbeat')
            ->assertJsonPath('renewed', true)
            ->assertJsonPath('reason', null);

        $renewedLeaseExpiresAt = (string) $heartbeat->json('lease_expires_at');

        $this->assertNotSame($initialLeaseExpiresAt, $renewedLeaseExpiresAt);

        $duplicatePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-duplicate-heartbeat',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'poll-request-heartbeat-1',
            ]);

        $duplicatePoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.workflow_task_attempt', $attempt)
            ->assertJsonPath('task.lease_owner', 'php-worker-duplicate-heartbeat')
            ->assertJsonPath('task.lease_expires_at', $renewedLeaseExpiresAt);
    }

    public function test_completion_succeeds_for_a_standard_workflow_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-missing-workflow-task-lease-complete',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-missing-lease-complete', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-missing-lease-complete',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', [
                            'greeting' => 'Hello, Ada!',
                        ]),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');
    }

    public function test_completion_accepts_open_condition_wait_command_and_marks_run_waiting(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-open-condition-wait',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-open-condition-wait', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-open-condition-wait',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'open_condition_wait',
                        'condition_key' => 'order-ready',
                        'timeout_seconds' => 60,
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');
    }

    public function test_completion_accepts_open_signal_wait_command_and_records_wait(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-open-signal-wait',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-open-signal-wait', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-open-signal-wait',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'open_signal_wait',
                        'signal_name' => 'advance',
                        'timeout_seconds' => 45,
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $opened = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::SignalWaitOpened->value)
            ->firstOrFail();

        $this->assertSame('advance', $opened->payload['signal_name'] ?? null);
        $this->assertSame(45, $opened->payload['timeout_seconds'] ?? null);
        $this->assertIsString($opened->payload['signal_wait_id'] ?? null);
    }

    public function test_completion_returns_422_when_structural_limit_exceeded(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-structural-limit',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-structural-limit', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-structural-limit',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->instance(
            WorkflowTaskBridge::class,
            \Mockery::mock(WorkflowTaskBridge::class, static function (MockInterface $mock) {
                $mock->shouldReceive('complete')
                    ->andThrow(
                        StructuralLimitExceededException::pendingActivityCount(2000, 2000),
                    );

                $mock->shouldReceive('status')
                    ->andReturnUsing(function (string $taskId) {
                        return app()->make(DefaultWorkflowTaskBridge::class)->status($taskId);
                    });
            }),
        );

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'greeting.send',
                    ],
                ],
            ]);

        $complete->assertStatus(422)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'structural_limit_exceeded')
            ->assertJsonPath('limit_kind', 'pending_activity_count')
            ->assertJsonPath('current_value', 2000)
            ->assertJsonPath('configured_limit', 2000);
    }

    public function test_heartbeat_succeeds_for_a_leased_workflow_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-stale-workflow-task-lease-heartbeat',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-stale-lease-heartbeat', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-stale-lease-heartbeat',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
            ]);

        $heartbeat->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('renewed', true)
            ->assertJsonPath('reason', null);
    }

    public function test_it_reports_lease_owner_mismatch_when_wrong_worker_sends_heartbeat(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-missing-workflow-task-lease-mismatch',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-mirror-owner', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-mirror-owner',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => 'php-worker-wrong-owner',
                'workflow_task_attempt' => $attempt,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch')
            ->assertJsonPath('lease_owner', 'php-worker-mirror-owner');
    }

    public function test_it_drops_claimed_workflow_tasks_when_the_bridge_cannot_build_the_history_payload(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        WorkflowInstance::query()->create([
            'id' => 'wf-bridge-missing-task',
            'workflow_class' => ExternalGreetingWorkflow::class,
            'workflow_type' => 'tests.external-greeting-workflow',
            'namespace' => 'default',
            'run_count' => 0,
        ]);

        $recordedAt = now()->toJSON();

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use ($recordedAt): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(null, 'external-workflows', 10, null, 'default', ['tests.external-greeting-workflow'])
                ->andReturn([
                    [
                        'task_id' => 'wf-task-missing-row',
                        'workflow_run_id' => 'run-bridge-missing-task',
                        'workflow_instance_id' => 'wf-bridge-missing-task',
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'workflow_class' => ExternalGreetingWorkflow::class,
                        'connection' => null,
                        'queue' => 'external-workflows',
                        'compatibility' => null,
                        'available_at' => $recordedAt,
                    ],
                ]);

            $mock->shouldReceive('claimStatus')
                ->once()
                ->with('wf-task-missing-row', 'php-worker-missing-row')
                ->andReturn([
                    'claimed' => true,
                    'task_id' => 'wf-task-missing-row',
                    'workflow_run_id' => 'run-bridge-missing-task',
                    'workflow_instance_id' => 'wf-bridge-missing-task',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'payload_codec' => (string) config('workflows.serializer'),
                    'connection' => null,
                    'queue' => 'external-workflows',
                    'compatibility' => null,
                    'lease_owner' => 'php-worker-missing-row',
                    'lease_expires_at' => now()->addMinutes(5)->toJSON(),
                    'reason' => null,
                    'reason_detail' => null,
                ]);

            $mock->shouldReceive('historyPayload')
                ->once()
                ->with('wf-task-missing-row')
                ->andReturn(null);
        });

        $this->registerWorker('php-worker-missing-row', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-missing-row',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_workflow_poll_uses_bridge_as_the_only_ready_task_source(): void
    {
        // Source-of-truth contract: the package bridge owns ready-task
        // discovery, including workflow_type filtering. If it returns no
        // candidates, the server must not run its own workflow_tasks to
        // workflow_runs query as a second predicate source.
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $workflowType = 'tests.external-greeting-workflow';
        $taskQueue = 'external-workflows';

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-only-source',
                'workflow_type' => $workflowType,
                'task_queue' => $taskQueue,
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use ($taskQueue, $workflowType): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(null, $taskQueue, 10, null, 'default', [$workflowType])
                ->andReturn([]);

            $mock->shouldNotReceive('claimStatus');
        });

        $this->registerWorker(
            'php-worker-bridge-only-source',
            $taskQueue,
            supportedWorkflowTypes: [$workflowType],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-bridge-only-source',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_workflow_poll_drops_bridge_candidates_outside_registered_types_without_local_dispatch_query(): void
    {
        // The bridge is the only source of ready-task candidates. The
        // server still guards against a polluted bridge response before
        // claiming, but it must not compensate by materialising a
        // second local dispatch query from workflow_tasks/workflow_runs.
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $matchingWorkflowType = 'tests.external-greeting-workflow';
        $taskQueue = 'shared-disjoint-types';

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-disjoint-types-routing',
                'workflow_type' => $matchingWorkflowType,
                'task_queue' => $taskQueue,
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use ($taskQueue): void {
            $mock->shouldReceive('poll')
                ->twice()
                ->andReturn([[
                    'task_id' => 'phantom-bridge-task',
                    'workflow_run_id' => 'phantom-bridge-run',
                    'workflow_instance_id' => 'phantom-bridge-instance',
                    'workflow_type' => 'phantom.unrelated-workflow-type',
                    'workflow_class' => null,
                    'connection' => null,
                    'queue' => $taskQueue,
                    'compatibility' => null,
                    'sticky_worker_id' => null,
                    'sticky_until' => null,
                    'available_at' => now()->subSecond()->toJSON(),
                    'priority' => 5,
                    'fairness_key' => null,
                    'fairness_weight' => 1,
                ]]);

            $mock->shouldNotReceive('claimStatus');
        });

        $this->registerWorker(
            'worker-with-matching-type',
            $taskQueue,
            supportedWorkflowTypes: [$matchingWorkflowType],
        );

        $this->registerWorker(
            'worker-with-disjoint-type',
            $taskQueue,
            supportedWorkflowTypes: ['tests.disjoint-other-workflow'],
        );

        // Worker registered for a disjoint workflow type must come back
        // empty: the only ready task on the queue is for a type the
        // disjoint-typed worker did not register for.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-with-disjoint-type',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        // Even the worker registered for the run's stored workflow_type
        // comes back empty if the bridge did not surface that task. The
        // real bridge's typed poll contract is covered separately by
        // the shared-queue integration tests; this test prevents a
        // second server-owned predicate from returning.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-with-matching-type',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_workflow_dispatch_does_not_fall_back_for_untyped_polls_when_bridge_returns_no_tasks(): void
    {
        // Untyped-poll contract: workers that registered no workflow
        // types are not workflow workers, so the dispatch path must
        // never materialise a workflow task for them. An untyped
        // worker is short-circuited with
        // the no_workflow_capability poll status before the bridge is
        // asked anything at all.
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-untyped-no-bridge-poll',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        // A bridge that, if asked, would return nothing — the test
        // asserts the bridge is never asked for an untyped worker.
        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('poll');
        });

        $this->registerWorker(
            'php-worker-untyped',
            'external-workflows',
            supportedWorkflowTypes: [],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-untyped',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'no_workflow_capability');
    }

    public function test_it_passes_supported_workflow_types_to_the_workflow_bridge_poll(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock): void {
            $mock->shouldReceive('poll')
                ->once()
                ->with(
                    null,
                    'external-workflows',
                    10,
                    null,
                    'default',
                    ['tests.external-greeting-workflow'],
                )
                ->andReturn([]);
        });

        $this->registerWorker(
            'php-worker-typed-bridge',
            'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-typed-bridge',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_passes_the_next_visible_workflow_task_deadline_into_long_polling(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-task-next-probe',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $futureAvailableAt = now()->addMinutes(2)->startOfSecond();

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail()
            ->forceFill([
                'available_at' => $futureAvailableAt,
            ])->save();

        /** @var LongPollSignalStore $signals */
        $signals = app(LongPollSignalStore::class);
        $expectedChannels = [
            ...$signals->workflowTaskPollChannels('default', null, 'external-workflows'),
            ...$signals->queryTaskPollChannels('default', 'external-workflows'),
        ];

        $this->mock(LongPoller::class, function (MockInterface $mock) use (
            $expectedChannels,
            $futureAvailableAt,
        ): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                    ?int $intervalMilliseconds = null,
                    array $wakeChannels = [],
                    ?callable $nextProbeAt = null,
                ) use ($expectedChannels, $futureAvailableAt) {
                    $this->assertSame($expectedChannels, $wakeChannels);

                    $initial = $probe();

                    $this->assertNull($initial);
                    $this->assertFalse($ready($initial));
                    $this->assertIsCallable($nextProbeAt);

                    $hint = $nextProbeAt();

                    $this->assertInstanceOf(\DateTimeInterface::class, $hint);
                    $this->assertSame(
                        $futureAvailableAt->format('U.u'),
                        $hint->format('U.u'),
                    );

                    return null;
                });
        });

        $this->registerWorker('php-worker-next-probe', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-next-probe',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_filters_workflow_tasks_by_worker_build_id(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-build-compatible',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Margaret'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill([
            'compatibility' => 'build-a',
        ])->save();

        $this->registerWorker('php-worker-no-build', 'external-workflows');
        $this->registerWorker('php-worker-build-a', 'external-workflows', buildId: 'build-a');

        // Worker with no registered build_id cannot claim a task with compatibility=build-a.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-no-build',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        // Worker registered with build_id=build-a claims the compatible task.
        // The build_id for routing is derived from the registration record,
        // not the poll request parameter — so build_id is intentionally
        // omitted from the poll body.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-build-a',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.compatibility', 'build-a')
            ->assertJsonPath('task.lease_owner', 'php-worker-build-a');
    }

    public function test_it_uses_run_pin_when_ready_task_compatibility_is_missing(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker('php-worker-v1', 'external-workflows', buildId: 'build-v1');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-stale-task-compatibility',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Margaret'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $run = WorkflowRun::query()->findOrFail($runId);
        self::assertSame('build-v1', $run->compatibility);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill(['compatibility' => null])->save();

        $this->registerWorker('php-worker-v2', 'external-workflows', buildId: 'build-v2');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-v2',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $task->refresh();
        self::assertSame(TaskStatus::Ready, $task->status);
        self::assertNull($task->lease_owner);
        self::assertSame('build-v1', $task->compatibility);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-v1',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.compatibility', 'build-v1')
            ->assertJsonPath('task.lease_owner', 'php-worker-v1');
    }

    public function test_it_rejects_workflow_task_poll_when_build_id_mismatches_registration(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker(
            'php-worker-build-mismatch',
            'external-workflows',
            buildId: 'build-v1',
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-build-mismatch',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-v2',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'build_id_mismatch')
            ->assertJsonPath('worker_id', 'php-worker-build-mismatch')
            ->assertJsonPath('registered_build_id', 'build-v1')
            ->assertJsonPath('requested_build_id', 'build-v2');
    }

    public function test_it_allows_workflow_task_poll_when_build_id_matches_registration(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker(
            'php-worker-build-match',
            'external-workflows',
            buildId: 'build-v1',
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-build-match',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-v1',
            ])
            ->assertOk();
    }

    public function test_it_allows_workflow_task_poll_when_worker_has_no_registered_build_id(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker('php-worker-no-build', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-no-build',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-v2',
            ])
            ->assertOk();
    }

    public function test_it_rejects_activity_task_poll_when_build_id_mismatches_registration(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->registerWorker(
            'php-activity-worker-mismatch',
            'default',
            buildId: 'build-v1',
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-mismatch',
                'task_queue' => 'default',
                'build_id' => 'build-v2',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'build_id_mismatch')
            ->assertJsonPath('worker_id', 'php-activity-worker-mismatch')
            ->assertJsonPath('registered_build_id', 'build-v1')
            ->assertJsonPath('requested_build_id', 'build-v2');
    }

    public function test_it_routes_workflow_tasks_by_registered_build_id_when_poll_omits_build_id(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-registration-authority',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Alice'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        // Stamp the task with a compatibility marker.
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill(['compatibility' => 'build-reg'])->save();

        // Register worker WITH build_id, then poll WITHOUT build_id in the
        // request body.  The server should derive build_id from the
        // registration record and still claim the compatible task.
        $this->registerWorker('php-worker-reg-build', 'external-workflows', buildId: 'build-reg');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-reg-build',
                'task_queue' => 'external-workflows',
                // build_id intentionally omitted
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.compatibility', 'build-reg')
            ->assertJsonPath('task.lease_owner', 'php-worker-reg-build');
    }

    public function test_it_ignores_poll_build_id_when_worker_has_no_registered_build_id(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-no-reg-build',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Bob'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // Stamp the task with a compatibility marker.
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->firstOrFail();

        $task->forceFill(['compatibility' => 'build-phantom'])->save();

        // Register worker WITHOUT build_id, then poll WITH a build_id.
        // The server should derive build_id=null from the registration and
        // NOT use the poll's build_id for routing.  The task has a
        // compatibility marker so it should not be claimed.
        $this->registerWorker('php-worker-no-reg-build', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-no-reg-build',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-phantom',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_it_routes_activity_tasks_by_registered_build_id_not_poll_parameter(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        // Start a workflow and complete the workflow task so an activity task
        // is created — then stamp the activity task with a compatibility
        // marker and verify registration-backed routing.
        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-activity-build-route',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Carol'],
            ]);

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        // Register a worker with build_id for the activity poll.
        $this->registerWorker(
            'php-activity-build-worker',
            'external-workflows',
            buildId: 'build-act-1',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        // Find the activity task (if one was created by workflow completion).
        // If none exists, stamp the workflow task as an activity for this
        // isolated routing test.
        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'activity')
            ->first();

        if ($activityTask) {
            $activityTask->forceFill(['compatibility' => 'build-act-1'])->save();

            // Poll without build_id — registration should supply it.
            $this->withHeaders($this->workerHeaders())
                ->postJson('/api/worker/activity-tasks/poll', [
                    'worker_id' => 'php-activity-build-worker',
                    'task_queue' => 'external-workflows',
                    // build_id intentionally omitted
                ])
                ->assertOk()
                ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity');
        }

        // Regardless of activity task availability, verify that a worker
        // without a registered build_id does not route with the poll's
        // build_id claim.
        $this->registerWorker('php-activity-no-build', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-no-build',
                'task_queue' => 'external-workflows',
                'build_id' => 'build-act-1',
            ])
            ->assertOk();
    }

    public function test_it_fences_stale_workflow_task_workers_and_records_failures(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-fail',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Linus'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-3', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-3',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'wrong-worker',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Replay failed',
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'php-worker-3',
                'workflow_task_attempt' => $attempt + 1,
                'failure' => [
                    'message' => 'Replay failed',
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'php-worker-3',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Determinism violation',
                    'type' => 'determinism_violation',
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true);

        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Determinism violation', $task->last_error);
        $this->assertTrue(($task->payload['replay_blocked'] ?? false) === true);

        $debug = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-external-worker-fail/debug');

        $debug->assertOk()
            ->assertJsonPath('execution.liveness_state', 'workflow_replay_blocked')
            ->assertJsonFragment(['code' => 'workflow_replay_blocked']);
    }

    public function test_structured_replay_failure_identity_blocks_retry_and_remains_diagnostic(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-structured-replay-failure',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Linus'],
            ])
            ->assertCreated();

        $this->registerWorker('structured-replay-worker', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'structured-replay-worker',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'structured-replay-worker',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'workflow task failed',
                    'type' => 'RuntimeError',
                    'reason' => 'parallel_group_shape_mismatch',
                    'sequence' => 7,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('next_task_id', null);

        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('parallel_group_shape_mismatch', $task->payload['failure_reason'] ?? null);
        $this->assertSame(7, $task->payload['failure_sequence'] ?? null);
        $this->assertTrue(($task->payload['replay_blocked'] ?? false) === true);
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $task->workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->count());

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-structured-replay-failure/debug')
            ->assertOk()
            ->assertJsonPath('execution.liveness_state', 'workflow_replay_blocked')
            ->assertJsonPath('latest_workflow_task_failure.task_id', $taskId)
            ->assertJsonPath('latest_workflow_task_failure.reason', 'parallel_group_shape_mismatch')
            ->assertJsonPath('latest_workflow_task_failure.sequence', 7)
            ->assertJsonPath('latest_workflow_task_failure.replay_blocked', true);

        $this->assertSame($start->json('run_id'), $task->workflow_run_id);
    }

    public function test_workflow_task_failure_identity_is_bounded_before_the_leased_task_is_mutated(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bounded-replay-failure',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('bounded-replay-worker', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'bounded-replay-worker',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'bounded-replay-worker',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'workflow task failed',
                    'type' => str_repeat('T', 513),
                    'reason' => str_repeat('r', 192),
                    'sequence' => 19,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['failure.type', 'failure.reason']);

        $task = WorkflowTask::query()->findOrFail($taskId);
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertNull($task->last_error);
        $this->assertArrayNotHasKey('failure_reason', (array) $task->payload);
        $this->assertArrayNotHasKey('failure_sequence', (array) $task->payload);
        $this->assertArrayNotHasKey('failure_type', (array) $task->payload);
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $task->workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->count());

        $acceptedType = str_repeat('T', 512);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'bounded-replay-worker',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'workflow task failed',
                    'type' => $acceptedType,
                    'reason' => 'parallel_group_shape_mismatch',
                    'sequence' => 19,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('next_task_id', null);

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('parallel_group_shape_mismatch', $task->payload['failure_reason'] ?? null);
        $this->assertSame(19, $task->payload['failure_sequence'] ?? null);
        $this->assertSame($acceptedType, $task->payload['failure_type'] ?? null);
        $this->assertTrue(($task->payload['replay_blocked'] ?? false) === true);
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $task->workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->count());
    }

    public function test_workflow_task_failure_rolls_back_before_identity_post_processing_and_can_be_retried(): void
    {
        Queue::fake();
        config(['workflows.storage.transaction_attempts' => 1]);

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-atomic-structured-replay-failure',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ])
            ->assertCreated();

        $this->registerWorker('atomic-replay-worker', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'atomic-replay-worker',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $defaultBridge = app(DefaultWorkflowTaskBridge::class);
        $bridgeCalls = 0;

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use (
            $defaultBridge,
            &$bridgeCalls,
        ): void {
            $mock->shouldReceive('fail')
                ->twice()
                ->andReturnUsing(function (string $failedTaskId, array $failure) use (
                    $defaultBridge,
                    &$bridgeCalls,
                ): array {
                    $outcome = $defaultBridge->fail($failedTaskId, $failure);
                    $bridgeCalls++;

                    if ($bridgeCalls === 1) {
                        throw new \RuntimeException('Lock wait timeout exceeded during injected post-failure processing.');
                    }

                    return $outcome;
                });
            $mock->shouldReceive('status')
                ->andReturnUsing(fn (string $statusTaskId): array => $defaultBridge->status($statusTaskId));
        });

        $failure = [
            'lease_owner' => 'atomic-replay-worker',
            'workflow_task_attempt' => $attempt,
            'failure' => [
                'message' => 'workflow task failed',
                'type' => 'RuntimeError',
                'reason' => 'parallel_group_shape_mismatch',
                'sequence' => 11,
            ],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", $failure)
            ->assertStatus(503)
            ->assertJsonPath('reason', 'backend_lock_pressure')
            ->assertJsonPath('recorded', false);

        $task = WorkflowTask::query()->findOrFail($taskId);
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertNull($task->last_error);
        $this->assertArrayNotHasKey('failure_reason', (array) $task->payload);
        $this->assertArrayNotHasKey('failure_sequence', (array) $task->payload);
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $task->workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->count());

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", $failure)
            ->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('next_task_id', null);

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('parallel_group_shape_mismatch', $task->payload['failure_reason'] ?? null);
        $this->assertSame(11, $task->payload['failure_sequence'] ?? null);
        $this->assertTrue(($task->payload['replay_blocked'] ?? false) === true);
        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $task->workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->count());
    }

    public function test_waiting_for_scheduled_history_workflow_task_failure_acknowledges_the_wait(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-waiting-history',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Linus'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-waiting-history', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-waiting-history',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'php-worker-waiting-history',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'workflow task waiting for scheduled history: Workflow\V2\Support\ActivityCall has no completed history yet',
                    'type' => 'WorkflowTaskWaitingForHistory',
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'waiting_for_history')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('next_task_id', null);

        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->last_error);
        $this->assertTrue(($task->payload['waiting_for_history_acknowledged'] ?? false) === true);
        $this->assertSame(
            'workflow task waiting for scheduled history: Workflow\V2\Support\ActivityCall has no completed history yet',
            $task->payload['waiting_for_history_message'] ?? null,
        );
        $this->assertSame('WorkflowTaskWaitingForHistory', $task->payload['waiting_for_history_failure_type'] ?? null);
        $this->assertFalse(($task->payload['replay_blocked'] ?? false) === true);
        $this->assertSame('waiting', WorkflowRun::query()->findOrFail($task->workflow_run_id)->status->value);
        $this->assertFalse(
            WorkflowTask::query()
                ->where('workflow_run_id', $task->workflow_run_id)
                ->where('task_type', TaskType::Workflow->value)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->exists(),
        );
    }

    public function test_waiting_for_history_failure_type_acknowledges_the_wait_without_message_phrase(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-waiting-history-type',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Linus'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-waiting-history-type', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-waiting-history-type',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'php-worker-waiting-history-type',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'history is not ready yet',
                    'type' => 'WorkflowTaskWaitingForHistory',
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'waiting_for_history')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('next_task_id', null);

        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNull($task->last_error);
        $this->assertTrue(($task->payload['waiting_for_history_acknowledged'] ?? false) === true);
        $this->assertSame('history is not ready yet', $task->payload['waiting_for_history_message'] ?? null);
        $this->assertSame('WorkflowTaskWaitingForHistory', $task->payload['waiting_for_history_failure_type'] ?? null);
        $this->assertFalse(($task->payload['replay_blocked'] ?? false) === true);
        $this->assertSame('waiting', WorkflowRun::query()->findOrFail($task->workflow_run_id)->status->value);
        $this->assertFalse(
            WorkflowTask::query()
                ->where('workflow_run_id', $task->workflow_run_id)
                ->where('task_type', TaskType::Workflow->value)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->exists(),
        );
    }

    public function test_it_heartbeats_leased_workflow_tasks_and_fences_stale_workers(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-heartbeat',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-heartbeat', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-heartbeat',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-external-worker-heartbeat')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $initialLeaseExpiresAt = $poll->json('task.lease_expires_at');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => 'wrong-worker',
                'workflow_task_attempt' => $attempt,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');

        $heartbeat = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => 'php-worker-heartbeat',
                'workflow_task_attempt' => $attempt,
            ]);

        $heartbeat->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('lease_owner', 'php-worker-heartbeat')
            ->assertJsonPath('renewed', true)
            ->assertJsonPath('task_status', 'leased')
            ->assertJsonPath('reason', null);

        $this->assertIsString($heartbeat->json('lease_expires_at'));
        $this->assertNotSame($initialLeaseExpiresAt, $heartbeat->json('lease_expires_at'));
    }

    public function test_worker_reregistration_with_new_process_identity_releases_leased_workflow_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-worker-process-replaced',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $registration = [
            'worker_id' => 'php-worker-process-replaced',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'process_metrics' => [
                'host' => 'worker-host',
                'process_id' => 101,
                'process_started_at' => '2026-05-18T21:00:00Z',
            ],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-process-replaced',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-process-replaced')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-process-replaced');

        $taskId = (string) $poll->json('task.task_id');
        $staleAttempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', array_replace_recursive($registration, [
                'process_metrics' => [
                    'process_id' => 202,
                    'process_started_at' => '2026-05-18T21:01:00Z',
                ],
            ]))
            ->assertCreated();

        $releasedTask = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Ready, $releasedTask->status);
        $this->assertNull($releasedTask->lease_owner);
        $this->assertNull($releasedTask->lease_expires_at);
        $this->assertSame(StickyExecution::MODE_FORCED_COLD_REPLAY, $releasedTask->sticky_replay_mode);
        $this->assertNotNull($releasedTask->sticky_claimed_at);

        $reclaimed = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-process-replaced',
                'task_queue' => 'external-workflows',
            ]);

        $reclaimed->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'php-worker-process-replaced')
            ->assertJsonPath('task.workflow_task_attempt', 2);

        $freshAttempt = (int) $reclaimed->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-worker-process-replaced',
                'workflow_task_attempt' => $staleAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['late' => 'stale-process']),
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-worker-process-replaced',
                'workflow_task_attempt' => $freshAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['replaced' => true]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');
    }

    public function test_stale_workflow_long_poll_does_not_claim_after_worker_process_replacement(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-stale-workflow-long-poll',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $registration = [
            'worker_id' => 'php-worker-stale-workflow-long-poll',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'process_metrics' => [
                'host' => 'worker-host',
                'process_id' => 1101,
                'process_started_at' => '2026-05-18T23:00:00Z',
            ],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $this->mock(LongPoller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                    ?int $intervalMilliseconds = null,
                    array $wakeChannels = [],
                    ?callable $nextProbeAt = null,
                    bool $reserveWorkerWaitSlot = false,
                    string $waitSlotPool = 'worker',
                ) {
                    WorkerRegistration::query()
                        ->where('worker_id', 'php-worker-stale-workflow-long-poll')
                        ->where('namespace', 'default')
                        ->firstOrFail()
                        ->forceFill([
                            'process_metrics' => [
                                'host' => 'worker-host',
                                'process_id' => 1102,
                                'process_started_at' => '2026-05-18T23:01:00Z',
                            ],
                            'last_heartbeat_at' => now(),
                        ])->save();

                    $result = $probe();

                    $this->assertIsArray($result);
                    $this->assertSame('stale_worker_registration', $result['poll_status'] ?? null);
                    $this->assertTrue($ready($result));

                    return $result;
                });
        });

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-stale-workflow-long-poll',
                'task_queue' => 'external-workflows',
                'timeout_seconds' => 35,
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'stale_worker_registration');

        $task = WorkflowTask::query()
            ->where('workflow_run_id', (string) $start->json('run_id'))
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
    }

    public function test_stale_workflow_task_poll_after_heartbeat_expiry_does_not_claim_ready_task(): void
    {
        Queue::fake();

        config(['server.workers.stale_after_seconds' => 3]);

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $registeredAt = now()->startOfSecond();
        $this->travelTo($registeredAt);

        $this->registerWorker('php-worker-expired-heartbeat', 'external-workflows');
        $this->registerWorker('php-worker-fresh-heartbeat', 'external-workflows');

        $this->travelTo($registeredAt->copy()->addSeconds(5));

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/heartbeat', [
                'worker_id' => 'php-worker-fresh-heartbeat',
            ])
            ->assertOk()
            ->assertJsonPath('acknowledged', true);

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-stale-heartbeat-poll-fence',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $stalePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-expired-heartbeat',
                'task_queue' => 'external-workflows',
            ]);

        $stalePoll->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'stale_worker_registration');

        $task = WorkflowTask::query()
            ->where('workflow_run_id', (string) $start->json('run_id'))
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);

        $freshPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fresh-heartbeat',
                'task_queue' => 'external-workflows',
            ]);

        $freshPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.workflow_id', 'wf-stale-heartbeat-poll-fence')
            ->assertJsonPath('task.lease_owner', 'php-worker-fresh-heartbeat');

        $task->refresh();

        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame('php-worker-fresh-heartbeat', $task->lease_owner);
    }

    public function test_worker_reregistration_without_process_identity_releases_leased_workflow_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-worker-process-unidentified-restart',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $registration = [
            'worker_id' => 'php-worker-process-unidentified',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-process-unidentified',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-process-unidentified-restart')
            ->assertJsonPath('task.workflow_task_attempt', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-process-unidentified');

        $taskId = (string) $poll->json('task.task_id');
        $staleAttempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $releasedTask = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Ready, $releasedTask->status);
        $this->assertNull($releasedTask->lease_owner);
        $this->assertNull($releasedTask->lease_expires_at);

        $reclaimed = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-process-unidentified',
                'task_queue' => 'external-workflows',
            ]);

        $reclaimed->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'php-worker-process-unidentified')
            ->assertJsonPath('task.workflow_task_attempt', 2);

        $freshAttempt = (int) $reclaimed->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-worker-process-unidentified',
                'workflow_task_attempt' => $staleAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['late' => 'unidentified-process']),
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'workflow_task_attempt_mismatch');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-worker-process-unidentified',
                'workflow_task_attempt' => $freshAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['replaced' => true]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');
    }

    public function test_worker_reregistration_with_new_process_identity_releases_leased_activity_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-worker-process-replaced-activity',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $registration = [
            'worker_id' => 'php-worker-process-replaced-activity',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'process_metrics' => [
                'host' => 'worker-host',
                'process_id' => 301,
                'process_started_at' => '2026-05-18T22:00:00Z',
            ],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $workflowPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-process-replaced-activity',
                'task_queue' => 'external-workflows',
            ]);

        $workflowPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-process-replaced-activity')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $workflowTaskId = (string) $workflowPoll->json('task.task_id');
        $workflowAttempt = (int) $workflowPoll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$workflowTaskId}/complete", [
                'lease_owner' => 'php-worker-process-replaced-activity',
                'workflow_task_attempt' => $workflowAttempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec((string) config('workflows.serializer'), ['Ada']),
                        'queue' => 'external-workflows',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-process-replaced-activity',
                'task_queue' => 'external-workflows',
            ]);

        $activityPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-process-replaced-activity')
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.attempt_number', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-process-replaced-activity');

        $activityTaskId = (string) $activityPoll->json('task.task_id');
        $staleActivityAttemptId = (string) $activityPoll->json('task.activity_attempt_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', array_replace_recursive($registration, [
                'process_metrics' => [
                    'process_id' => 302,
                    'process_started_at' => '2026-05-18T22:01:00Z',
                ],
            ]))
            ->assertCreated();

        $releasedTask = WorkflowTask::query()->findOrFail($activityTaskId);
        $expiredAttempt = ActivityAttempt::query()->findOrFail($staleActivityAttemptId);

        $this->assertSame(TaskStatus::Ready, $releasedTask->status);
        $this->assertNull($releasedTask->lease_owner);
        $this->assertNull($releasedTask->lease_expires_at);
        $this->assertSame(ActivityAttemptStatus::Expired, $expiredAttempt->status);
        $this->assertNull($expiredAttempt->lease_expires_at);

        $reclaimed = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-process-replaced-activity',
                'task_queue' => 'external-workflows',
            ]);

        $reclaimed->assertOk()
            ->assertJsonPath('task.task_id', $activityTaskId)
            ->assertJsonPath('task.attempt_number', 2)
            ->assertJsonPath('task.lease_owner', 'php-worker-process-replaced-activity');

        $this->assertNotSame($staleActivityAttemptId, $reclaimed->json('task.activity_attempt_id'));
    }

    public function test_stale_activity_long_poll_does_not_claim_after_worker_process_replacement(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-stale-activity-long-poll',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $registration = [
            'worker_id' => 'php-worker-stale-activity-long-poll',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'process_metrics' => [
                'host' => 'worker-host',
                'process_id' => 1201,
                'process_started_at' => '2026-05-18T23:10:00Z',
            ],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $workflowPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-stale-activity-long-poll',
                'task_queue' => 'external-workflows',
            ]);

        $workflowPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-stale-activity-long-poll')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $workflowTaskId = (string) $workflowPoll->json('task.task_id');
        $workflowAttempt = (int) $workflowPoll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$workflowTaskId}/complete", [
                'lease_owner' => 'php-worker-stale-activity-long-poll',
                'workflow_task_attempt' => $workflowAttempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec((string) config('workflows.serializer'), ['Ada']),
                        'queue' => 'external-workflows',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $this->mock(LongPoller::class, function (MockInterface $mock): void {
            $mock->shouldReceive('until')
                ->once()
                ->andReturnUsing(function (
                    callable $probe,
                    callable $ready,
                    ?int $timeoutSeconds = null,
                    ?int $intervalMilliseconds = null,
                    array $wakeChannels = [],
                    ?callable $nextProbeAt = null,
                    bool $reserveWorkerWaitSlot = false,
                    string $waitSlotPool = 'worker',
                ) {
                    WorkerRegistration::query()
                        ->where('worker_id', 'php-worker-stale-activity-long-poll')
                        ->where('namespace', 'default')
                        ->firstOrFail()
                        ->forceFill([
                            'process_metrics' => [
                                'host' => 'worker-host',
                                'process_id' => 1202,
                                'process_started_at' => '2026-05-18T23:11:00Z',
                            ],
                            'last_heartbeat_at' => now(),
                        ])->save();

                    $result = $probe();

                    $this->assertIsArray($result);
                    $this->assertSame('stale_worker_registration', $result['poll_status'] ?? null);
                    $this->assertTrue($ready($result));

                    return $result;
                });
        });

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-stale-activity-long-poll',
                'task_queue' => 'external-workflows',
                'timeout_seconds' => 35,
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'stale_worker_registration');

        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', (string) $start->json('run_id'))
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $activityTask->status);
        $this->assertNull($activityTask->lease_owner);
        $this->assertNull($activityTask->lease_expires_at);
        $this->assertSame(0, ActivityAttempt::query()
            ->where('workflow_task_id', $activityTask->id)
            ->count());
    }

    public function test_worker_reregistration_without_process_identity_releases_leased_activity_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-worker-process-unidentified-activity',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $registration = [
            'worker_id' => 'php-worker-process-unidentified-activity',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => ['tests.external-greeting-activity'],
        ];

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $workflowPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-process-unidentified-activity',
                'task_queue' => 'external-workflows',
            ]);

        $workflowPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-process-unidentified-activity')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $workflowTaskId = (string) $workflowPoll->json('task.task_id');
        $workflowAttempt = (int) $workflowPoll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$workflowTaskId}/complete", [
                'lease_owner' => 'php-worker-process-unidentified-activity',
                'workflow_task_attempt' => $workflowAttempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec((string) config('workflows.serializer'), ['Ada']),
                        'queue' => 'external-workflows',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-process-unidentified-activity',
                'task_queue' => 'external-workflows',
            ]);

        $activityPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-worker-process-unidentified-activity')
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.attempt_number', 1)
            ->assertJsonPath('task.lease_owner', 'php-worker-process-unidentified-activity');

        $activityTaskId = (string) $activityPoll->json('task.task_id');
        $staleActivityAttemptId = (string) $activityPoll->json('task.activity_attempt_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', $registration)
            ->assertCreated();

        $releasedTask = WorkflowTask::query()->findOrFail($activityTaskId);
        $expiredAttempt = ActivityAttempt::query()->findOrFail($staleActivityAttemptId);

        $this->assertSame(TaskStatus::Ready, $releasedTask->status);
        $this->assertNull($releasedTask->lease_owner);
        $this->assertNull($releasedTask->lease_expires_at);
        $this->assertSame(ActivityAttemptStatus::Expired, $expiredAttempt->status);
        $this->assertNull($expiredAttempt->lease_expires_at);

        $reclaimed = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-process-unidentified-activity',
                'task_queue' => 'external-workflows',
            ]);

        $reclaimed->assertOk()
            ->assertJsonPath('task.task_id', $activityTaskId)
            ->assertJsonPath('task.attempt_number', 2)
            ->assertJsonPath('task.lease_owner', 'php-worker-process-unidentified-activity');

        $this->assertNotSame($staleActivityAttemptId, $reclaimed->json('task.activity_attempt_id'));
    }

    public function test_standalone_timeout_drives_real_claim_expiry_reclaim_and_stale_owner_fencing(): void
    {
        Queue::fake();

        config(['server.lease.workflow_task_timeout' => 1]);
        $this->assertSame(1, WorkflowTaskLeaseConfiguration::apply());
        $this->assertSame(1, config(WorkflowTaskLease::CONFIG_KEY));

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-standalone-workflow-task-lease',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('lease-worker-one', 'external-workflows');
        $this->registerWorker('lease-worker-two', 'external-workflows');

        $claimStartedAt = now();
        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'lease-worker-one',
                'task_queue' => 'external-workflows',
            ]);
        $claimReturnedAt = now();

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-standalone-workflow-task-lease')
            ->assertJsonPath('task.lease_owner', 'lease-worker-one')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $taskId = (string) $firstPoll->json('task.task_id');
        $firstAttempt = (int) $firstPoll->json('task.workflow_task_attempt');
        $returnedExpiry = Carbon::parse((string) $firstPoll->json('task.lease_expires_at'));
        $persistedExpiry = WorkflowTask::query()->findOrFail($taskId)->lease_expires_at;

        $this->assertNotNull($persistedExpiry);
        $this->assertLessThanOrEqual(1, $persistedExpiry->diffInMilliseconds($returnedExpiry));
        $this->assertTrue($returnedExpiry->betweenIncluded(
            $claimStartedAt->copy()->addSecond()->subMilliseconds(10),
            $claimReturnedAt->copy()->addSecond()->addMilliseconds(10),
        ));

        $waitDeadline = microtime(true) + 3;
        while (now()->lessThanOrEqualTo($persistedExpiry) && microtime(true) < $waitDeadline) {
            usleep(25_000);
        }

        $this->assertTrue(now()->greaterThan($persistedExpiry));
        // Cross the next whole-second tick so SQLite's timestamp comparison
        // observes the same expired boundary as microsecond-capable backends.
        usleep(1_100_000);

        $secondPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'lease-worker-two',
                'task_queue' => 'external-workflows',
            ]);

        $secondPoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'lease-worker-two')
            ->assertJsonPath('task.workflow_task_attempt', $firstAttempt + 1);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'lease-worker-one',
                'workflow_task_attempt' => $firstAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['stale' => true]),
                    ],
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('reason', 'lease_owner_mismatch')
            ->assertJsonPath('lease_owner', 'lease-worker-two');

        $this->assertSame((string) $start->json('run_id'), WorkflowTask::query()->findOrFail($taskId)->workflow_run_id);
    }

    public function test_released_eight_second_failover_lease_accepts_completion_before_expiry(): void
    {
        Queue::fake();

        config(['server.lease.workflow_task_timeout' => 8]);
        $this->assertSame(8, WorkflowTaskLeaseConfiguration::apply());
        $this->assertSame(
            8,
            SingleRegionFailoverContract::manifest()['recovery_bounds']['workflow_task_lease_seconds'],
        );

        $claimedAt = Carbon::parse('2026-07-15T20:10:00Z');
        $this->travelTo($claimedAt);
        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-api-node-live-lease',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('api-node-loss-worker', 'external-workflows');
        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'api-node-loss-worker',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseExpiresAt = Carbon::parse((string) $poll->json('task.lease_expires_at'));
        $this->assertTrue($leaseExpiresAt->equalTo($claimedAt->copy()->addSeconds(8)));

        $completionAt = $claimedAt->copy()->addSeconds(7);
        $this->travelTo($completionAt);
        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'api-node-loss-worker',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['survivor' => true]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');

        $this->assertTrue($completionAt->lessThan($leaseExpiresAt));
        $this->assertSame(TaskStatus::Completed, WorkflowTask::query()->findOrFail($taskId)->status);
    }

    public function test_released_eight_second_failover_lease_rejects_stale_completion_with_typed_error(): void
    {
        Queue::fake();

        config(['server.lease.workflow_task_timeout' => 8]);
        $this->assertSame(8, WorkflowTaskLeaseConfiguration::apply());

        $claimedAt = Carbon::parse('2026-07-15T20:20:00Z');
        $this->travelTo($claimedAt);
        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-api-node-expired-lease',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('expired-api-node-loss-worker', 'external-workflows');
        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'expired-api-node-loss-worker',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseExpiresAt = Carbon::parse((string) $poll->json('task.lease_expires_at'));
        $this->assertTrue($leaseExpiresAt->equalTo($claimedAt->copy()->addSeconds(8)));

        $this->travelTo($claimedAt->copy()->addSeconds(9));
        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'expired-api-node-loss-worker',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['stale' => true]),
                    ],
                ],
            ])
            ->assertConflict()
            ->assertJsonPath('reason', 'lease_expired')
            ->assertJsonPath('lease_owner', 'expired-api-node-loss-worker')
            ->assertJsonPath('lease_expires_at', $leaseExpiresAt->toJSON());

        $this->assertNotSame(TaskStatus::Completed, WorkflowTask::query()->findOrFail($taskId)->status);
    }

    public function test_it_proactively_repairs_expired_workflow_task_leases_when_a_new_worker_polls(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-expired-workflow-task-poll-repair',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-expired-poll-lease', 'external-workflows');
        $this->registerWorker('php-worker-recovered-during-poll', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-expired-poll-lease',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $expiredAt = now()->subMinute()->startOfSecond();

        WorkflowTask::query()->findOrFail($taskId)
            ->forceFill([
                'lease_expires_at' => $expiredAt,
            ])->save();

        $recoveredPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-recovered-during-poll',
                'task_queue' => 'external-workflows',
            ]);

        $recoveredPoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'php-worker-recovered-during-poll');

        $recoveredAttempt = (int) $recoveredPoll->json('task.workflow_task_attempt');
        $this->assertGreaterThanOrEqual(1, $recoveredAttempt);

        $repairHistory = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-expired-workflow-task-poll-repair/runs/{$start->json('run_id')}/history");

        $repairHistory->assertOk();

        $this->assertContains(
            'RepairRequested',
            array_column($repairHistory->json('events'), 'event_type'),
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['late' => 'stale-worker']),
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch')
            ->assertJsonPath('lease_owner', 'php-worker-recovered-during-poll');
    }

    public function test_it_requests_package_repair_when_a_workflow_task_lease_expires(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-expired-workflow-task-lease',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-expired-lease', 'external-workflows');
        $this->registerWorker('php-worker-recovered-lease', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-expired-lease',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $expiredAt = now()->subMinute()->startOfSecond();

        WorkflowTask::query()->findOrFail($taskId)
            ->forceFill([
                'lease_expires_at' => $expiredAt,
            ])->save();

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/heartbeat", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_expired')
            ->assertJsonPath('lease_owner', $leaseOwner)
            ->assertJsonPath('task_status', 'leased')
            ->assertJsonPath('lease_expires_at', $expiredAt->toJSON());

        $recoveredPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-recovered-lease',
                'task_queue' => 'external-workflows',
            ]);

        $recoveredPoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'php-worker-recovered-lease');

        $recoveredAttempt = (int) $recoveredPoll->json('task.workflow_task_attempt');
        $this->assertGreaterThanOrEqual(1, $recoveredAttempt);

        $repairHistory = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-expired-workflow-task-lease/runs/{$start->json('run_id')}/history");

        $repairHistory->assertOk();

        $this->assertContains(
            'RepairRequested',
            array_column($repairHistory->json('events'), 'event_type'),
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['late' => 'stale-worker']),
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch')
            ->assertJsonPath('lease_owner', 'php-worker-recovered-lease');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-worker-recovered-lease',
                'workflow_task_attempt' => $recoveredAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['late' => true]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');

        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertSame(TaskStatus::Completed, $task->status);
    }

    public function test_it_recovers_expired_workflow_task_leases_and_completes_successfully(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-recovery-without-mirror-row',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-mirror-absent', 'external-workflows');
        $this->registerWorker('php-worker-recovered-without-mirror', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-mirror-absent',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $expiredAt = now()->subMinute()->startOfSecond();

        // Expire the lease on the package's WorkflowTask.
        WorkflowTask::query()->findOrFail($taskId)
            ->forceFill([
                'lease_expires_at' => $expiredAt,
            ])->save();

        $recoveredPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-recovered-without-mirror',
                'task_queue' => 'external-workflows',
            ]);

        $recoveredPoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'php-worker-recovered-without-mirror');

        $recoveredAttempt = (int) $recoveredPoll->json('task.workflow_task_attempt');
        $this->assertGreaterThanOrEqual(1, $recoveredAttempt);

        $repairHistory = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/wf-recovery-without-mirror-row/runs/{$start->json('run_id')}/history");

        $repairHistory->assertOk();

        $this->assertContains(
            'RepairRequested',
            array_column($repairHistory->json('events'), 'event_type'),
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'php-worker-recovered-without-mirror',
                'workflow_task_attempt' => $recoveredAttempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['recovered' => true]),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'completed');
    }

    public function test_it_schedules_external_activities_from_non_terminal_workflow_task_commands(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-schedules-activity',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-schedule', 'external-workflows');
        $this->registerWorker('php-activity-worker', 'external-activities');
        $this->registerWorker('php-worker-resume', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-schedule',
                'task_queue' => 'external-workflows',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $scheduleActivity = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $firstPoll->json('task.task_id')), [
                'lease_owner' => $firstPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $firstPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'queue' => 'external-activities',
                        'retry_policy' => [
                            'max_attempts' => 4,
                            'backoff_seconds' => [1, 3, 9],
                            'non_retryable_error_types' => ['ValidationError'],
                        ],
                        'start_to_close_timeout' => 30,
                        'schedule_to_start_timeout' => 45,
                        'schedule_to_close_timeout' => 120,
                        'heartbeat_timeout' => 10,
                    ],
                ],
            ]);

        $scheduleActivity->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('run_status', 'waiting');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'waiting');

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker',
                'task_queue' => 'external-activities',
            ]);

        $activityPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.retry_policy.max_attempts', 4)
            ->assertJsonPath('task.retry_policy.backoff_seconds', [1, 3, 9])
            ->assertJsonPath('task.retry_policy.non_retryable_error_types', ['ValidationError'])
            ->assertJsonPath('task.retry_policy.start_to_close_timeout', 30)
            ->assertJsonPath('task.retry_policy.schedule_to_start_timeout', 45)
            ->assertJsonPath('task.retry_policy.schedule_to_close_timeout', 120)
            ->assertJsonPath('task.retry_policy.heartbeat_timeout', 10);

        $deadlines = $activityPoll->json('task.deadlines');
        $this->assertIsArray($deadlines);
        $this->assertArrayHasKey('schedule_to_start', $deadlines);
        $this->assertArrayHasKey('start_to_close', $deadlines);
        $this->assertArrayHasKey('schedule_to_close', $deadlines);
        $this->assertArrayHasKey('heartbeat', $deadlines);

        $activityPoll->assertJsonPath('task.arguments.codec', (string) config('workflows.serializer'));
        $this->assertSame(
            ['Ada'],
            Serializer::unserializeWithCodec(
                (string) $activityPoll->json('task.arguments.codec'),
                (string) $activityPoll->json('task.arguments.blob'),
            ),
        );

        $completeActivity = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/activity-tasks/%s/complete', $activityPoll->json('task.task_id')), [
                'activity_attempt_id' => $activityPoll->json('task.activity_attempt_id'),
                'lease_owner' => $activityPoll->json('task.lease_owner'),
                'result' => 'Hello, Ada!',
            ]);

        $completeActivity->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true);

        $resumePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-resume',
                'task_queue' => 'external-workflows',
            ]);

        $resumePoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $resumeEventTypes = array_column((array) $resumePoll->json('task.history_events'), 'event_type');

        $this->assertContains('ActivityScheduled', $resumeEventTypes);
        $this->assertContains('ActivityStarted', $resumeEventTypes);
        $this->assertContains('ActivityCompleted', $resumeEventTypes);

        $completeWorkflow = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $resumePoll->json('task.task_id')), [
                'lease_owner' => $resumePoll->json('task.lease_owner'),
                'workflow_task_attempt' => $resumePoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec((string) config('workflows.serializer'), [
                            'greeting' => 'Hello, Ada!',
                            'workflow_id' => $workflowId,
                        ]),
                    ],
                ],
            ]);

        $completeWorkflow->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'completed');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('output.greeting', 'Hello, Ada!');
    }

    public function test_it_resumes_external_workflows_after_activity_worker_failures(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-activity-fails',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-schedule-failing-activity', 'external-workflows');
        $this->registerWorker('php-activity-worker-fails-activity', 'external-activities');
        $this->registerWorker('php-worker-resume-after-activity-failure', 'external-workflows');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-schedule-failing-activity',
                'task_queue' => 'external-workflows',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $scheduleActivity = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $firstPoll->json('task.task_id')), [
                'lease_owner' => $firstPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $firstPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'queue' => 'external-activities',
                    ],
                ],
            ]);

        $scheduleActivity->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $this->assertIsString($scheduleActivity->json('created_task_ids.0'));

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker-fails-activity',
                'task_queue' => 'external-activities',
            ]);

        $activityPoll->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.arguments.codec', (string) config('workflows.serializer'));

        $failActivity = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/activity-tasks/%s/fail', $activityPoll->json('task.task_id')), [
                'activity_attempt_id' => $activityPoll->json('task.activity_attempt_id'),
                'lease_owner' => $activityPoll->json('task.lease_owner'),
                'failure' => [
                    'message' => 'Inventory service timed out.',
                    'type' => 'TimeoutException',
                    'class' => 'App\\Activities\\InventoryTimeout',
                    'stack_trace' => 'at activity_worker.py:42',
                    'non_retryable' => true,
                    'details' => [
                        'codec' => 'avro',
                        'blob' => Serializer::serializeWithCodec('avro', [
                            'stage' => 'inventory',
                            'retry_after' => 30,
                        ]),
                    ],
                ],
            ]);

        $failActivity->assertOk()
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true);

        $this->assertIsString($failActivity->json('next_task_id'));

        $resumePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-resume-after-activity-failure',
                'task_queue' => 'external-workflows',
            ]);

        $resumePoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId)
            ->assertJsonPath('task.lease_owner', 'php-worker-resume-after-activity-failure');

        $resumeEvents = collect((array) $resumePoll->json('task.history_events'));
        $activityFailed = $resumeEvents->firstWhere('event_type', 'ActivityFailed');

        $this->assertIsArray($activityFailed);
        $this->assertSame('Inventory service timed out.', $activityFailed['payload']['message'] ?? null);
        $this->assertTrue($activityFailed['payload']['non_retryable'] ?? false);
        $this->assertSame(
            'avro',
            $activityFailed['payload']['exception']['details_payload_codec'] ?? null,
        );
        $this->assertSame(
            'App\\Activities\\InventoryTimeout',
            $activityFailed['payload']['exception_class'] ?? null,
        );

        $recordedFailure = WorkflowFailure::query()
            ->where('workflow_run_id', $runId)
            ->where('source_kind', 'activity_execution')
            ->firstOrFail();

        $this->assertSame('App\\Activities\\InventoryTimeout', $recordedFailure->exception_class);
        $this->assertSame('Inventory service timed out.', $recordedFailure->message);

        $completeWorkflow = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $resumePoll->json('task.task_id')), [
                'lease_owner' => $resumePoll->json('task.lease_owner'),
                'workflow_task_attempt' => $resumePoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'fail_workflow',
                        'message' => 'Activity failure propagated to workflow.',
                        'exception_class' => 'ExternalActivityFailure',
                        'non_retryable' => true,
                    ],
                ],
            ]);

        $completeWorkflow->assertOk()
            ->assertJsonPath('outcome', 'completed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'failed');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('status_bucket', 'failed');

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs/{$runId}/history");

        $history->assertOk();

        $historyEventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('ActivityFailed', $historyEventTypes);
        $this->assertContains('WorkflowFailed', $historyEventTypes);
    }

    public function test_it_starts_timers_from_non_terminal_workflow_task_commands(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-starts-timer',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-timer', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-timer',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $poll->json('task.task_id')), [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'start_timer',
                        'delay_seconds' => 30,
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'timer')
            ->where('status', 'ready')
            ->first();

        $this->assertNotNull($timerTask);
        $this->assertNotNull($timerTask->available_at);

        $history = $this->withHeaders($this->apiHeaders())
            ->getJson(sprintf('/api/workflows/%s/runs/%s/history', $start->json('workflow_id'), $runId));

        $history->assertOk();

        $eventTypes = array_column($history->json('events'), 'event_type');

        $this->assertContains('TimerScheduled', $eventTypes);
    }

    public function test_it_binds_child_workflows_started_by_external_workflow_tasks_to_the_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-starts-child',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Linus'],
            ]);

        $start->assertCreated();

        $parentWorkflowId = (string) $start->json('workflow_id');
        $parentRunId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-parent', 'external-workflows');
        $this->registerWorker(
            'php-worker-child',
            'external-workflows',
            supportedWorkflowTypes: ['tests.external-child-workflow'],
        );

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-parent',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $parentWorkflowId)
            ->assertJsonPath('task.run_id', $parentRunId);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $poll->json('task.task_id')), [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-child-workflow',
                        'queue' => 'external-workflows',
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        $childInstance = WorkflowInstance::query()
            ->where('namespace', 'default')
            ->where('workflow_type', 'tests.external-child-workflow')
            ->first();

        $this->assertNotNull($childInstance);

        $childWorkflowId = (string) $childInstance->id;
        $childRun = WorkflowRun::query()
            ->where('workflow_instance_id', $childWorkflowId)
            ->first();

        $this->assertNotNull($childRun);
        $this->assertSame('default', $childRun->namespace);
        $this->assertSame(
            'default',
            WorkflowTask::query()
                ->where('workflow_run_id', $childRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->value('namespace'),
        );

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$childWorkflowId}")
            ->assertOk()
            ->assertJsonPath('workflow_id', $childWorkflowId)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('workflow_type', 'tests.external-child-workflow')
            ->assertJsonPath('status', 'pending');

        $childPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-child',
                'task_queue' => 'external-workflows',
            ]);

        $childPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $childWorkflowId)
            ->assertJsonPath('task.workflow_type', 'tests.external-child-workflow');
    }

    public function test_it_continues_workflows_as_new_from_external_workflow_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-external-worker-continue-as-new',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $originalRunId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-continue', 'external-workflows');
        $this->registerWorker('php-worker-continued-run', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-continue',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $originalRunId);

        $continueAsNew = $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $poll->json('task.task_id')), [
                'lease_owner' => $poll->json('task.lease_owner'),
                'workflow_task_attempt' => $poll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'continue_as_new',
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada v2'],
                        ),
                    ],
                ],
            ]);

        $continueAsNew->assertOk()
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_id', $originalRunId)
            ->assertJsonPath('run_status', 'completed');

        $runs = $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}/runs");

        $runs->assertOk()
            ->assertJsonCount(2, 'runs')
            ->assertJsonPath('runs.0.run_id', $originalRunId)
            ->assertJsonPath('runs.0.status', 'completed')
            ->assertJsonPath('runs.1.run_number', 2)
            ->assertJsonPath('runs.1.status', 'pending');

        $continuedRunId = (string) $runs->json('runs.1.run_id');

        $this->withHeaders($this->apiHeaders())
            ->getJson("/api/workflows/{$workflowId}")
            ->assertOk()
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('run_id', $continuedRunId)
            ->assertJsonPath('status', 'pending');

        $continuedPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-continued-run',
                'task_queue' => 'external-workflows',
            ]);

        $continuedPoll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $continuedRunId);
    }

    public function test_poll_response_paginates_history_events_when_history_page_size_is_set(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-paginated-history',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $workflowId = (string) $start->json('workflow_id');
        $runId = (string) $start->json('run_id');

        $this->registerWorker('php-worker-paginated', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-paginated',
                'task_queue' => 'external-workflows',
                'history_page_size' => 1,
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.workflow_id', $workflowId)
            ->assertJsonPath('task.run_id', $runId);

        $events = $poll->json('task.history_events');
        $totalEvents = $poll->json('task.total_history_events');
        $historySizeBytes = $poll->json('task.history_size_bytes');
        $historyFanOut = $poll->json('task.history_fan_out');
        $continueAsNewRecommended = $poll->json('task.continue_as_new_recommended');
        $historyBudgetPressure = $poll->json('task.history_budget_pressure');
        $historyBudgetPressureDimensions = $poll->json('task.history_budget_pressure_dimensions');
        $nextToken = $poll->json('task.next_history_page_token');

        $this->assertCount(1, $events);
        $this->assertGreaterThan(1, $totalEvents);
        $this->assertIsInt($historySizeBytes);
        $this->assertIsInt($historyFanOut);
        $this->assertIsBool($continueAsNewRecommended);
        $this->assertIsString($historyBudgetPressure);
        $this->assertIsArray($historyBudgetPressureDimensions);
        $this->assertNotNull($nextToken);

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $historyPage = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'next_history_page_token' => $nextToken,
                'history_page_size' => 100,
            ]);

        $historyPage->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('total_history_events', $totalEvents)
            ->assertJsonPath('history_size_bytes', $historySizeBytes)
            ->assertJsonPath('history_fan_out', $historyFanOut)
            ->assertJsonPath('continue_as_new_recommended', $continueAsNewRecommended)
            ->assertJsonPath('history_budget_pressure', $historyBudgetPressure)
            ->assertJsonPath('history_budget_pressure_dimensions', $historyBudgetPressureDimensions);

        $pageEvents = $historyPage->json('history_events');

        $this->assertNotEmpty($pageEvents);
        $this->assertNull($historyPage->json('next_history_page_token'));

        $allSequences = array_merge(
            array_column($events, 'sequence'),
            array_column($pageEvents, 'sequence'),
        );

        $this->assertSame(
            $allSequences,
            array_unique($allSequences),
            'Pages must not contain duplicate events.',
        );
    }

    public function test_poll_response_returns_complete_history_when_history_page_size_is_not_requested(): void
    {
        Queue::fake();

        config()->set('server.worker_protocol.history_page_size_default', 2);

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-unpaginated-history',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-unpaginated-history', 'external-workflows');
        $this->registerWorker(
            'php-worker-unpaginated-activity',
            'external-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-unpaginated-history',
                'task_queue' => 'external-workflows',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-unpaginated-history');

        $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/workflow-tasks/%s/complete', $firstPoll->json('task.task_id')), [
                'lease_owner' => $firstPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $firstPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec((string) config('workflows.serializer'), ['Ada']),
                        'queue' => 'external-activities',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-worker-unpaginated-activity',
                'task_queue' => 'external-activities',
            ]);

        $activityPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-unpaginated-history')
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity');

        $this->withHeaders($this->workerHeaders())
            ->postJson(sprintf('/api/worker/activity-tasks/%s/complete', $activityPoll->json('task.task_id')), [
                'activity_attempt_id' => $activityPoll->json('task.activity_attempt_id'),
                'lease_owner' => $activityPoll->json('task.lease_owner'),
                'result' => 'Hello, Ada!',
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true);

        $resumePoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-unpaginated-history',
                'task_queue' => 'external-workflows',
            ]);

        $resumePoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-unpaginated-history');

        $events = (array) $resumePoll->json('task.history_events');
        $eventTypes = array_column($events, 'event_type');

        $this->assertGreaterThan(2, count($events));
        $this->assertSame(count($events), $resumePoll->json('task.total_history_events'));
        $this->assertNull($resumePoll->json('task.next_history_page_token'));
        $this->assertContains('ActivityScheduled', $eventTypes);
        $this->assertContains('ActivityStarted', $eventTypes);
        $this->assertContains('ActivityCompleted', $eventTypes);
    }

    public function test_poll_response_includes_all_history_events_when_within_default_page_size(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-no-pagination-needed',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-no-pagination', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-no-pagination',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $totalEvents = $poll->json('task.total_history_events');
        $events = $poll->json('task.history_events');

        $this->assertSame(count($events), $totalEvents);
        $this->assertNull($poll->json('task.next_history_page_token'));
    }

    public function test_workflow_task_history_endpoint_rejects_invalid_page_token(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-invalid-page-token',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-invalid-token', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-invalid-token',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'next_history_page_token' => 'not-valid-base64-sequence',
            ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_page_token');
    }

    public function test_workflow_task_history_endpoint_guards_lease_ownership(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-ownership-guard',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-history-owner', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-history-owner',
                'task_queue' => 'external-workflows',
                'history_page_size' => 1,
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $nextToken = $poll->json('task.next_history_page_token');

        $this->assertNotNull($nextToken);

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/history", [
                'lease_owner' => 'wrong-worker',
                'workflow_task_attempt' => $attempt,
                'next_history_page_token' => $nextToken,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');
    }

    public function test_complete_response_includes_created_task_ids(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-created-task-ids',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-created-tasks', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-created-tasks',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $leaseOwner = (string) $poll->json('task.lease_owner');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => Serializer::serializeWithCodec('avro', ['greeting' => 'Hello, Ada!']),
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true);

        $this->assertIsArray($complete->json('created_task_ids'));
    }

    public function test_server_capabilities_advertise_history_pagination(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $register = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'php-worker-capabilities-check',
                'task_queue' => 'external-workflows',
                'runtime' => 'php',
            ]);

        $register->assertCreated()
            ->assertJsonPath('server_capabilities.history_page_size_default', 500)
            ->assertJsonPath('server_capabilities.history_page_size_max', 1000)
            ->assertJsonPath(
                'server_capabilities.workflow_history_budget',
                WorkerHistoryPayloadContract::manifest(),
            );
    }

    public function test_server_capabilities_advertise_history_compression(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $register = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'php-worker-compression-check',
                'task_queue' => 'external-workflows',
                'runtime' => 'php',
            ]);

        $register->assertCreated()
            ->assertJsonPath('server_capabilities.history_compression.supported_encodings', ['gzip', 'deflate'])
            ->assertJsonPath('server_capabilities.history_compression.compression_threshold', 50);
    }

    public function test_poll_response_compresses_history_when_accept_history_encoding_is_set(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-history-compression',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        // The real workflow only has a few history events (below the 50-event
        // compression threshold), so compression should NOT be applied even
        // when requested. This validates the threshold guard works.
        $this->registerWorker('php-worker-compress', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-compress',
                'task_queue' => 'external-workflows',
                'accept_history_encoding' => 'gzip',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.run_id', $runId);

        // Events below compression threshold should be uncompressed.
        $this->assertNotEmpty($poll->json('task.history_events'));
        $this->assertNull($poll->json('task.history_events_compressed'));
        $this->assertNull($poll->json('task.history_events_encoding'));
    }

    public function test_poll_response_compresses_history_above_threshold_via_mock(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        WorkflowInstance::query()->create([
            'id' => 'wf-compress-mock',
            'workflow_class' => ExternalGreetingWorkflow::class,
            'workflow_type' => 'tests.external-greeting-workflow',
            'namespace' => 'default',
            'run_count' => 0,
        ]);

        $recordedAt = now()->toJSON();
        $events = [];

        // Generate 60 events to exceed the 50-event compression threshold.
        for ($i = 1; $i <= 60; $i++) {
            $events[] = [
                'id' => "evt-{$i}",
                'sequence' => $i,
                'event_type' => 'WorkflowStarted',
                'payload' => [],
                'workflow_task_id' => null,
                'workflow_command_id' => null,
                'recorded_at' => $recordedAt,
            ];
        }

        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use ($events, $recordedAt): void {
            $mock->shouldReceive('poll')
                ->once()
                ->andReturn([[
                    'task_id' => 'wf-task-compress',
                    'workflow_run_id' => 'run-compress',
                    'workflow_instance_id' => 'wf-compress-mock',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'connection' => null,
                    'queue' => 'external-workflows',
                    'compatibility' => null,
                    'available_at' => $recordedAt,
                ]]);

            $mock->shouldReceive('claimStatus')
                ->once()
                ->andReturn([
                    'claimed' => true,
                    'task_id' => 'wf-task-compress',
                    'workflow_run_id' => 'run-compress',
                    'workflow_instance_id' => 'wf-compress-mock',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'payload_codec' => (string) config('workflows.serializer'),
                    'connection' => null,
                    'queue' => 'external-workflows',
                    'compatibility' => null,
                    'lease_owner' => 'php-worker-compress-mock',
                    'lease_expires_at' => now()->addMinutes(5)->toJSON(),
                    'reason' => null,
                    'reason_detail' => null,
                ]);

            $mock->shouldReceive('historyPayloadPaginated')
                ->once()
                ->with('wf-task-compress', 0, WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE)
                ->andReturn([
                    'task_id' => 'wf-task-compress',
                    'workflow_run_id' => 'run-compress',
                    'workflow_instance_id' => 'wf-compress-mock',
                    'namespace' => 'default',
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => ExternalGreetingWorkflow::class,
                    'payload_codec' => (string) config('workflows.serializer'),
                    'arguments' => null,
                    'arguments_envelope' => null,
                    'run_status' => 'pending',
                    'sticky_worker_id' => null,
                    'sticky_until' => null,
                    'sticky_replay_mode' => null,
                    'last_history_sequence' => 73,
                    'total_history_events' => 60,
                    'history_size_bytes' => 8192,
                    'history_fan_out' => 12,
                    'continue_as_new_recommended' => true,
                    'history_budget_pressure' => 'continue_as_new_recommended',
                    'history_budget_pressure_dimensions' => ['fan_out'],
                    'after_sequence' => 0,
                    'page_size' => WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
                    'has_more' => false,
                    'next_after_sequence' => null,
                    'history_events' => $events,
                ]);
        });

        $this->registerWorker('php-worker-compress-mock', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-compress-mock',
                'task_queue' => 'external-workflows',
                'accept_history_encoding' => 'gzip',
            ]);

        $poll->assertOk()
            ->assertJsonPath('task.task_id', 'wf-task-compress');

        // History should be compressed since it exceeds the threshold.
        $this->assertNotNull($poll->json('task.history_events_compressed'));
        $this->assertSame('gzip', $poll->json('task.history_events_encoding'));
        $this->assertSame([], $poll->json('task.history_events'));
        $this->assertSame(60, $poll->json('task.total_history_events'));
        $this->assertSame(8192, $poll->json('task.history_size_bytes'));
        $this->assertSame(12, $poll->json('task.history_fan_out'));
        $this->assertTrue($poll->json('task.continue_as_new_recommended'));
        $this->assertSame(
            'continue_as_new_recommended',
            $poll->json('task.history_budget_pressure'),
        );
        $this->assertSame(['fan_out'], $poll->json('task.history_budget_pressure_dimensions'));
        $this->assertNull($poll->json('task.next_history_page_token'));

        // Verify the compressed payload is decompressible.
        $compressed = base64_decode($poll->json('task.history_events_compressed'), true);
        $this->assertNotFalse($compressed);
        $decompressed = gzdecode($compressed);
        $this->assertNotFalse($decompressed);
        $decoded = json_decode($decompressed, true);
        $this->assertIsArray($decoded);
        $this->assertCount(60, $decoded);
    }

    public function test_unregistered_worker_is_rejected_with_412(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-unregistered',
                'task_queue' => 'external-workflows',
            ])
            ->assertStatus(412)
            ->assertJsonPath('reason', 'worker_not_registered');
    }

    public function test_worker_polling_wrong_task_queue_is_rejected_with_409(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-wrong-queue', 'external-workflows');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-wrong-queue',
                'task_queue' => 'different-queue',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'task_queue_mismatch')
            ->assertJsonPath('registered_task_queue', 'external-workflows')
            ->assertJsonPath('requested_task_queue', 'different-queue');
    }

    public function test_worker_with_supported_workflow_types_only_receives_matching_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-capability-filter',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        // Worker registered for a different workflow type should not receive this task.
        $this->registerWorker(
            'php-worker-wrong-type',
            'external-workflows',
            supportedWorkflowTypes: ['some.other-workflow'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-wrong-type',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        // Worker registered for the matching workflow type should receive the task.
        $this->registerWorker(
            'php-worker-right-type',
            'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-right-type',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-capability-filter')
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow');
    }

    public function test_worker_with_empty_supported_workflow_types_receives_no_workflow_tasks(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-capability-non-workflow-worker',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        // A worker that advertised no workflow types at register time is
        // not a workflow worker — registered capabilities are authoritative
        // for routing, so the server must short-circuit the poll instead of
        // dispatching workflow tasks to a worker that cannot run them.
        $this->registerWorker(
            'php-worker-activity-only',
            'external-workflows',
            supportedWorkflowTypes: [],
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-activity-only',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'no_workflow_capability');
    }

    public function test_workflow_and_activity_workers_on_same_queue_do_not_cross_route(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-shared-queue-routing',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        // Polyglot setup: one worker registers as workflow-only, the other
        // as activity-only, and both share the same task queue. The bug
        // this guards against is the activity-only worker receiving the
        // workflow task and timing out because it has no workflow handler.
        $this->registerWorker(
            'php-workflow-only',
            'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
            supportedActivityTypes: [],
        );

        $this->registerWorker(
            'py-activity-only',
            'external-workflows',
            supportedWorkflowTypes: [],
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        // Activity-only worker polling for workflow tasks must come back
        // empty regardless of poll order, otherwise it could steal the
        // task from the workflow worker.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'py-activity-only',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'no_workflow_capability');

        // Workflow-only worker still receives the workflow task on the
        // shared queue.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-only',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-shared-queue-routing')
            ->assertJsonPath('task.workflow_type', 'tests.external-greeting-workflow');
    }

    public function test_polyglot_unconfigured_workflow_type_routes_to_workflow_only_worker_on_shared_queue(): void
    {
        // The polyglot scenario the smoke covers: a workflow whose type
        // key uses a dotted, language-neutral identifier and is NOT
        // present in the server's workflows.v2.types.workflows map (the
        // workflow class lives in another language, the server only sees
        // the type-key string). The registered-capability filter must
        // still match the worker's supported_workflow_types against the
        // run's stored workflow_type by exact string equality, regardless
        // of whether the type maps to a loadable PHP class on the server.
        // The previous shared-queue routing test only covers a configured
        // workflow type, so this regression — the type-key match working
        // for unconfigured types under the same two-worker shape — needs
        // its own contract.
        Queue::fake();

        // Deliberately do NOT call configureWorkflowTypes(): the polyglot
        // type is unknown to the server's class map.
        $this->createNamespace('default', 'Default namespace');

        $workflowType = 'polyglot.contract.PhpToPythonWorkflow';
        $taskQueue = 'polyglot-contract-shared';

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-polyglot-contract-routing',
                'workflow_type' => $workflowType,
                'task_queue' => $taskQueue,
                'input' => ['polyglot'],
            ]);

        $start->assertCreated();

        // Workflow worker registers with multiple workflow types — the
        // smoke's worker registers exactly one, but the routing contract
        // must work the same when the registry lists more than one
        // workflow type, so cover that here too.
        $this->registerWorker(
            'php-polyglot-workflow',
            $taskQueue,
            supportedWorkflowTypes: [
                $workflowType,
                'polyglot.contract.UnusedSiblingWorkflow',
            ],
            supportedActivityTypes: [],
        );

        // Activity worker registers multiple activity types and zero
        // workflow types — same shape as the polyglot Python activity
        // worker that ships with the sample app's polyglot stack.
        $this->registerWorker(
            'py-polyglot-activity',
            $taskQueue,
            supportedWorkflowTypes: [],
            supportedActivityTypes: [
                'polyglot.contract.reverse',
                'polyglot.contract.tally',
            ],
        );

        // Activity-only worker polling for workflow tasks must come back
        // empty even when the workflow type is unconfigured server-side.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'py-polyglot-activity',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'no_workflow_capability');

        // Workflow-only worker MUST receive the workflow task. This is
        // the regression the polyglot smoke catches: type-key match must
        // run on the run's stored workflow_type column directly, not on
        // a class-resolved canonical key, otherwise unconfigured polyglot
        // types are silently filtered out and the workflow stays pending
        // on a queue where no other worker can claim it.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-polyglot-workflow',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-polyglot-contract-routing')
            ->assertJsonPath('task.workflow_type', $workflowType);
    }

    public function test_polyglot_workflow_worker_receives_task_when_polling_before_activity_worker(): void
    {
        // Reverse poll order coverage for the polyglot two-worker shape:
        // even when the workflow worker is the first to reach the server
        // after both workers are live, the registered-capability filter
        // must still hand the task to the workflow worker. The previous
        // routing test only covers the activity-first poll order, so a
        // regression that broke workflow-first delivery would have slipped
        // through.
        Queue::fake();

        $this->createNamespace('default', 'Default namespace');

        $workflowType = 'polyglot.contract.PhpToPythonWorkflow';
        $taskQueue = 'polyglot-contract-workflow-first';

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-polyglot-workflow-first',
                'workflow_type' => $workflowType,
                'task_queue' => $taskQueue,
                'input' => ['polyglot'],
            ]);

        $start->assertCreated();

        $this->registerWorker(
            'php-polyglot-workflow-first',
            $taskQueue,
            supportedWorkflowTypes: [$workflowType],
            supportedActivityTypes: [],
        );

        $this->registerWorker(
            'py-polyglot-activity-second',
            $taskQueue,
            supportedWorkflowTypes: [],
            supportedActivityTypes: [
                'polyglot.contract.reverse',
                'polyglot.contract.tally',
            ],
        );

        // Workflow worker polls first — must receive the task.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-polyglot-workflow-first',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-polyglot-workflow-first')
            ->assertJsonPath('task.workflow_type', $workflowType);

        // Activity worker polling after the workflow task is leased must
        // still come back empty (and never see the now-leased workflow
        // task). Guards against regressions where the activity-only poll
        // bypasses the capability check after the workflow task changes
        // status.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'py-polyglot-activity-second',
                'task_queue' => $taskQueue,
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'no_workflow_capability');
    }

    public function test_workflow_worker_skips_bridge_returned_task_with_unregistered_workflow_type(): void
    {
        // Defense-in-depth contract: the bridge poll filters by
        // workflow_type at the SQL level, but the server's claim loop
        // must independently re-check the run's stored workflow_type
        // against the worker's registered list before claiming. If the
        // bridge ever returned a task whose workflow_type is not in the
        // worker's supported_workflow_types — because of a stale
        // index, a relaxed predicate, or a future bridge change — the
        // server must still refuse to lease it to that worker. Without
        // this guard, the polyglot Scenario 2 failure mode comes back
        // even when the bridge filter is correct in isolation: any
        // upstream regression that loosens the bridge's predicate
        // would hand a polyglot workflow task to the wrong worker and
        // the run would stall pending.
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-defense-in-depth',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');
        $instanceId = (string) $start->json('workflow_id');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        // Simulate a bridge poll that returns the task even though its
        // workflow_type is not in the requesting worker's registered
        // list — the failure mode the new app-level filter guards
        // against.
        $this->mock(WorkflowTaskBridge::class, function (MockInterface $mock) use ($task, $instanceId, $runId): void {
            $mock->shouldReceive('poll')
                ->andReturn([[
                    'task_id' => $task->id,
                    'workflow_run_id' => $runId,
                    'workflow_instance_id' => $instanceId,
                    'workflow_type' => 'tests.external-greeting-workflow',
                    'workflow_class' => 'tests.external-greeting-workflow',
                    'connection' => null,
                    'queue' => 'external-workflows',
                    'compatibility' => null,
                    'sticky_worker_id' => null,
                    'sticky_until' => null,
                    'available_at' => now()->subSecond()->toJSON(),
                ]]);
        });

        $this->registerWorker(
            'php-worker-strict-types',
            'external-workflows',
            supportedWorkflowTypes: ['some.unrelated-workflow'],
        );

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-strict-types',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);
    }

    public function test_fail_workflow_command_accepts_non_retryable_flag(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-non-retryable',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-nr', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-nr',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'fail_workflow',
                        'message' => 'Non-retryable business error',
                        'exception_class' => 'App\\Exceptions\\BusinessRuleViolation',
                        'non_retryable' => true,
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'failed');
    }

    public function test_fail_workflow_command_works_without_non_retryable_flag(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-default-retryable',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-default', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-default',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'fail_workflow',
                        'message' => 'Something went wrong',
                        'exception_class' => 'RuntimeException',
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'failed');
    }

    public function test_start_child_workflow_command_accepts_parent_close_policy(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-child-with-policy',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-child-policy', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-child-policy',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-child-workflow',
                        'queue' => 'external-workflows',
                        'parent_close_policy' => 'request_cancel',
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');
    }

    public function test_start_child_workflow_command_accepts_retry_policy_and_timeouts(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-child-retry-timeouts',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-child-retry', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-child-retry',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-child-workflow',
                        'queue' => 'external-workflows',
                        'retry_policy' => [
                            'max_attempts' => 3,
                            'backoff_seconds' => [2, 8],
                            'non_retryable_error_types' => ['ValidationError'],
                        ],
                        'execution_timeout_seconds' => 600,
                        'run_timeout_seconds' => 120,
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('run_status', 'waiting');

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $poll->json('task.run_id'))
            ->where('child_workflow_type', 'tests.external-child-workflow')
            ->firstOrFail();

        $this->assertSame([
            'snapshot_version' => 1,
            'max_attempts' => 3,
            'backoff_seconds' => [2, 8],
            'non_retryable_error_types' => ['ValidationError'],
        ], $childCall->retry_policy);
        $this->assertSame([
            'snapshot_version' => 1,
            'execution_timeout_seconds' => 600,
            'run_timeout_seconds' => 120,
        ], $childCall->timeout_policy);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($childCall->resolved_child_run_id);

        $this->assertSame(120, $childRun->run_timeout_seconds);
        $this->assertNotNull($childRun->execution_deadline_at);
        $this->assertNotNull($childRun->run_deadline_at);

        /** @var WorkflowHistoryEvent $scheduled */
        $scheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $poll->json('task.run_id'))
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->firstOrFail();

        $this->assertSame($childCall->retry_policy, $scheduled->payload['retry_policy']);
        $this->assertSame($childCall->timeout_policy, $scheduled->payload['timeout_policy']);
    }

    public function test_start_child_workflow_command_rejects_invalid_parent_close_policy(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-child-bad-policy',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-child-bad-policy', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-child-bad-policy',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-child-workflow',
                        'queue' => 'external-workflows',
                        'parent_close_policy' => 'kill_immediately',
                    ],
                ],
            ]);

        $complete->assertUnprocessable()
            ->assertJsonValidationErrors(['commands.0.parent_close_policy']);
    }

    // ── Workflow task failure reporting ──────────────────────────────

    public function test_fail_workflow_task_succeeds_for_a_leased_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-task-happy',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-happy', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-happy',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->instance(
            WorkflowTaskBridge::class,
            \Mockery::mock(WorkflowTaskBridge::class, static function (MockInterface $mock) {
                $mock->shouldReceive('fail')
                    ->once()
                    ->andReturn([
                        'recorded' => true,
                        'task_id' => 'ignored',
                        'reason' => null,
                    ]);

                $mock->shouldReceive('status')
                    ->andReturnUsing(function (string $taskId) {
                        return app()->make(DefaultWorkflowTaskBridge::class)->status($taskId);
                    });
            }),
        );

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Non-determinism detected: unexpected history event.',
                    'type' => 'NonDeterminismError',
                    'stack_trace' => 'at Replay::apply(Replay.php:42)',
                ],
            ]);

        $fail->assertOk()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('workflow_task_attempt', $attempt)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);
    }

    public function test_retryable_workflow_task_failure_creates_a_next_workflow_task(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-task-retry',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-retry', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-retry',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'worker process restarted before completion',
                    'type' => 'RuntimeError',
                    'reason' => 'worker_process_restarted',
                    'sequence' => 3,
                ],
            ]);

        $fail->assertOk()
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);

        $nextTaskId = $fail->json('next_task_id');

        $this->assertIsString($nextTaskId);
        $this->assertNotSame($taskId, $nextTaskId);

        $failedTask = WorkflowTask::query()->findOrFail($taskId);
        $retryTask = WorkflowTask::query()->findOrFail($nextTaskId);

        $this->assertSame(TaskStatus::Failed, $failedTask->status);
        $this->assertSame(TaskStatus::Ready, $retryTask->status);
        $this->assertSame($attempt, (int) $failedTask->attempt_count);
        $this->assertSame($attempt, (int) $retryTask->attempt_count);
        $this->assertSame('worker_process_restarted', $failedTask->payload['failure_reason'] ?? null);
        $this->assertSame(3, $failedTask->payload['failure_sequence'] ?? null);
        $this->assertArrayNotHasKey('failure_reason', $retryTask->payload);
        $this->assertArrayNotHasKey('failure_sequence', $retryTask->payload);
        $this->assertArrayNotHasKey('failure_type', $retryTask->payload);
        $this->assertSame($taskId, $retryTask->payload['workflow_task_retry_of'] ?? null);

        $retryPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-retry',
                'task_queue' => 'external-workflows',
            ]);

        $retryPoll->assertOk()
            ->assertJsonPath('task.task_id', $nextTaskId)
            ->assertJsonPath('task.workflow_id', 'wf-fail-task-retry')
            ->assertJsonPath('task.workflow_task_attempt', $attempt + 1);

        $retryAttempt = (int) $retryPoll->json('task.workflow_task_attempt');
        $this->assertSame($attempt + 1, $retryAttempt);

        $retryFail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$nextTaskId}/fail", [
                'lease_owner' => 'php-worker-fail-retry',
                'workflow_task_attempt' => $retryAttempt,
                'failure' => [
                    'message' => 'worker process restarted during retry',
                    'type' => 'RuntimeError',
                ],
            ]);

        $retryFail->assertOk()
            ->assertJsonPath('task_id', $nextTaskId)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', true)
            ->assertJsonPath('reason', null);

        $secondRetryTaskId = $retryFail->json('next_task_id');

        $this->assertIsString($secondRetryTaskId);
        $this->assertNotSame($nextTaskId, $secondRetryTaskId);

        $failedRetryTask = WorkflowTask::query()->findOrFail($nextTaskId);
        $secondRetryTask = WorkflowTask::query()->findOrFail($secondRetryTaskId);

        $this->assertSame(TaskStatus::Failed, $failedRetryTask->status);
        $this->assertSame(TaskStatus::Ready, $secondRetryTask->status);
        $this->assertSame($retryAttempt, (int) $failedRetryTask->attempt_count);
        $this->assertSame($retryAttempt, (int) $secondRetryTask->attempt_count);
        $this->assertSame($nextTaskId, $secondRetryTask->payload['workflow_task_retry_of'] ?? null);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/metrics')
            ->assertOk()
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.max_consecutive_failures', 2)
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.failed_task_count', 2)
            ->assertJsonPath('metrics.dw_workflow_task_consecutive_failures.workflow_type_count', 1)
            ->assertJsonPath(
                'metrics.dw_workflow_task_consecutive_failures.by_workflow_type.0.workflow_type',
                'tests.external-greeting-workflow',
            )
            ->assertJsonPath(
                'metrics.dw_workflow_task_consecutive_failures.by_workflow_type.0.max_consecutive_failures',
                2,
            )
            ->assertJsonPath(
                'metrics.dw_workflow_task_consecutive_failures.by_workflow_type.0.failed_task_count',
                2,
            );

        $secondRetryPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-retry',
                'task_queue' => 'external-workflows',
            ]);

        $secondRetryPoll->assertOk()
            ->assertJsonPath('task.task_id', $secondRetryTaskId)
            ->assertJsonPath('task.workflow_id', 'wf-fail-task-retry')
            ->assertJsonPath('task.workflow_task_attempt', $retryAttempt + 1);

        $secondRetryAttempt = (int) $secondRetryPoll->json('task.workflow_task_attempt');
        $this->assertSame($retryAttempt + 1, $secondRetryAttempt);

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$secondRetryTaskId}/complete", [
                'lease_owner' => 'php-worker-fail-retry',
                'workflow_task_attempt' => $secondRetryAttempt,
                'commands' => [
                    [
                        'type' => 'open_condition_wait',
                        'condition_key' => 'approval.ready',
                        'condition_definition_fingerprint' => 'condition-fp-1',
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-fail-task-retry')
            ->assertOk()
            ->assertJsonPath('status', 'waiting');
    }

    public function test_fail_workflow_task_rejects_wrong_lease_owner(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-task-lease-mismatch',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-lease', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-lease',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => 'wrong-owner-id',
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Some failure.',
                ],
            ]);

        $fail->assertStatus(409)
            ->assertJsonPath('reason', 'lease_owner_mismatch');
    }

    public function test_fail_workflow_task_returns_404_for_nonexistent_task(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-fail-404', 'external-workflows');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/nonexistent-task-id/fail', [
                'lease_owner' => 'some-owner',
                'workflow_task_attempt' => 1,
                'failure' => [
                    'message' => 'Task failure.',
                ],
            ]);

        $fail->assertStatus(404)
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function test_fail_workflow_task_validates_required_fields(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-fail-validation', 'external-workflows');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/some-task/fail', []);

        $fail->assertStatus(422)
            ->assertJsonValidationErrors(['lease_owner', 'workflow_task_attempt', 'failure']);
    }

    public function test_fail_workflow_task_validates_failure_message_is_required(): void
    {
        $this->createNamespace('default', 'Default namespace');
        $this->registerWorker('php-worker-fail-msg-validation', 'external-workflows');

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/some-task/fail', [
                'lease_owner' => 'owner',
                'workflow_task_attempt' => 1,
                'failure' => [
                    'type' => 'SomeError',
                ],
            ]);

        $fail->assertStatus(422)
            ->assertJsonValidationErrors(['failure.message']);
    }

    public function test_fail_workflow_task_is_scoped_by_namespace(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');
        $this->createNamespace('isolated', 'Isolated namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-ns-scoped',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-ns', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-ns',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $fail = $this->withHeaders($this->workerHeaders('isolated'))
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Should not reach bridge.',
                ],
            ]);

        $fail->assertStatus(404)
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function test_fail_workflow_task_records_bridge_failure_reason(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes();
        $this->createNamespace('default', 'Default namespace');

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-fail-task-bridge-reason',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $this->registerWorker('php-worker-fail-reason', 'external-workflows');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-fail-reason',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $taskId = (string) $poll->json('task.task_id');
        $attempt = (int) $poll->json('task.workflow_task_attempt');
        $leaseOwner = (string) $poll->json('task.lease_owner');

        $this->instance(
            WorkflowTaskBridge::class,
            \Mockery::mock(WorkflowTaskBridge::class, static function (MockInterface $mock) {
                $mock->shouldReceive('fail')
                    ->once()
                    ->andReturn([
                        'recorded' => false,
                        'task_id' => 'ignored',
                        'reason' => 'task_not_found',
                    ]);

                $mock->shouldReceive('status')
                    ->andReturnUsing(function (string $taskId) {
                        return app()->make(DefaultWorkflowTaskBridge::class)->status($taskId);
                    });
            }),
        );

        $fail = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/fail", [
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $attempt,
                'failure' => [
                    'message' => 'Replay error.',
                ],
            ]);

        $fail->assertStatus(404)
            ->assertJsonPath('task_id', $taskId)
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'task_not_found');
    }

    public function test_cluster_info_advertises_parent_close_policy_and_non_retryable_capabilities(): void
    {
        $this->createNamespace('default', 'Default namespace');

        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('capabilities.parent_close_policy', true)
            ->assertJsonPath('capabilities.non_retryable_failures', true)
            ->assertJsonPath('capabilities.child_workflow_retry_policy', true)
            ->assertJsonPath('capabilities.child_workflow_timeouts', true);
    }

    private function configureWorkflowTypes(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    private function createNamespace(string $name, string $description): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => $description,
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    private function apiHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    /**
     * @param  array<string>|null  $supportedWorkflowTypes
     * @param  array<string>|null  $supportedActivityTypes
     */
    private function registerWorker(
        string $workerId,
        string $taskQueue,
        string $namespace = 'default',
        ?array $supportedWorkflowTypes = null,
        ?array $supportedActivityTypes = null,
        ?string $buildId = null,
        int $maxConcurrentWorkflowTasks = 1,
        int $maxConcurrentActivityTasks = 1,
    ): void {
        // Default to declaring the workflow types this test suite drives so
        // tests that don't care about capability filtering still receive
        // workflow tasks under the registered-capability-authoritative
        // routing rule. Tests asserting the no-capability path pass an
        // explicit [] for supportedWorkflowTypes.
        $supportedWorkflowTypes ??= ['tests.external-greeting-workflow'];
        $supportedActivityTypes ??= ['tests.external-greeting-activity'];

        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => $namespace],
            array_filter([
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'build_id' => $buildId,
                'supported_workflow_types' => $supportedWorkflowTypes,
                'supported_activity_types' => $supportedActivityTypes,
                'max_concurrent_workflow_tasks' => $maxConcurrentWorkflowTasks,
                'max_concurrent_activity_tasks' => $maxConcurrentActivityTasks,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    private function workerHeaders(
        string $namespace = 'default',
        string $protocolVersion = WorkerProtocol::VERSION,
    ): array {
        return [
            'X-Namespace' => $namespace,
            WorkerProtocol::HEADER => $protocolVersion,
        ];
    }
}
