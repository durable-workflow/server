<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;

class WorkerSessionProtocolTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.polling.timeout' => 0,
            'server.workers.stale_after_seconds' => 60,
        ]);

        $this->createNamespace('default');
    }

    public function test_worker_protocol_manages_session_lifecycle_and_visibility(): void
    {
        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-1',
            taskQueue: 'gpu-activities',
            capabilities: ['gpu:nvidia-l4'],
            maxConcurrentWorkerSessions: 2,
        );

        $create = $this->postJson('/api/worker/sessions', [
            'worker_id' => 'gpu-worker-1',
            'session_id' => 'gpu-render',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4'],
            'lease_seconds' => 120,
            'ttl_seconds' => 600,
            'max_concurrent_activities' => 1,
        ], $this->workerHeaders());

        $create->assertCreated()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('session.session_id', 'gpu-render')
            ->assertJsonPath('session.lease_owner', 'gpu-worker-1')
            ->assertJsonPath('server_capabilities.worker_session_verbs', ['create', 'heartbeat', 'close']);

        $heartbeat = $this->postJson('/api/worker/sessions/gpu-render/heartbeat', [
            'worker_id' => 'gpu-worker-1',
            'lease_seconds' => 180,
        ], $this->workerHeaders());

        $heartbeat->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'heartbeat_recorded')
            ->assertJsonPath('session.session_id', 'gpu-render');

        $visibility = $this->getJson('/api/worker-sessions', $this->apiHeaders());

        $visibility->assertOk()
            ->assertJsonPath('metrics.active', 1)
            ->assertJsonPath('sessions.0.session_id', 'gpu-render')
            ->assertJsonPath('sessions.0.status', 'active');

        $close = $this->deleteJson('/api/worker/sessions/gpu-render', [
            'worker_id' => 'gpu-worker-1',
        ], $this->workerHeaders());

        $close->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'closed')
            ->assertJsonPath('session.status', 'closed');

        $this->getJson('/api/worker-sessions/gpu-render', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('session.status', 'closed');
    }

    public function test_activity_poll_admits_worker_session_only_to_capable_holder(): void
    {
        Queue::fake();

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-worker-session-activity',
            'workflow_type' => 'tests.external-greeting-workflow',
            'task_queue' => 'external-workflows',
            'input' => ['Ada'],
        ], $this->apiHeaders());

        $start->assertCreated();

        $this->registerWorkerThroughProtocol(
            workerId: 'workflow-worker',
            taskQueue: 'external-workflows',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $workflowPoll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'workflow-worker',
            'task_queue' => 'external-workflows',
        ], $this->workerHeaders());

        $workflowPoll->assertOk();

        $scheduleActivity = $this->postJson(
            sprintf('/api/worker/workflow-tasks/%s/complete', $workflowPoll->json('task.task_id')),
            [
                'lease_owner' => $workflowPoll->json('task.lease_owner'),
                'workflow_task_attempt' => $workflowPoll->json('task.workflow_task_attempt'),
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.external-greeting-activity',
                        'arguments' => Serializer::serializeWithCodec(
                            (string) config('workflows.serializer'),
                            ['Ada'],
                        ),
                        'worker_session' => [
                            'session_id' => 'gpu-render-sequence',
                            'queue' => 'gpu-activities',
                            'requirements' => ['gpu:nvidia-l4'],
                            'lease_seconds' => 120,
                            'ttl_seconds' => 600,
                            'max_concurrent_activities' => 1,
                        ],
                    ],
                ],
            ],
            $this->workerHeaders(),
        );

        $scheduleActivity->assertOk()
            ->assertJsonPath('run_status', 'waiting');

        $this->registerWorkerThroughProtocol(
            workerId: 'cpu-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
        );

        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'cpu-worker',
            'task_queue' => 'gpu-activities',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('task', null);

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        $activityPoll = $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-worker',
            'task_queue' => 'gpu-activities',
        ], $this->workerHeaders());

        $activityPoll->assertOk()
            ->assertJsonPath('poll_status', 'leased')
            ->assertJsonPath('task.activity_type', 'tests.external-greeting-activity')
            ->assertJsonPath('task.task_queue', 'gpu-activities')
            ->assertJsonPath('task.worker_session.session_id', 'gpu-render-sequence')
            ->assertJsonPath('task.worker_session.lease_owner', 'gpu-worker');

        $this->getJson('/api/worker-sessions/gpu-render-sequence', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('session.status', 'active')
            ->assertJsonPath('session.lease_owner', 'gpu-worker')
            ->assertJsonPath('session.active_activity_count', 1);

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-2',
            taskQueue: 'gpu-activities',
            supportedActivityTypes: ['tests.external-greeting-activity'],
            capabilities: ['gpu:nvidia-l4'],
        );

        $this->postJson('/api/worker/activity-tasks/poll', [
            'worker_id' => 'gpu-worker-2',
            'task_queue' => 'gpu-activities',
        ], $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('poll_status', 'empty')
            ->assertJsonPath('task', null);
    }

    public function test_stale_session_holder_is_marked_orphaned_and_can_be_reacquired(): void
    {
        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-stale',
            taskQueue: 'gpu-activities',
            capabilities: ['gpu:nvidia-l4'],
        );

        $this->postJson('/api/worker/sessions', [
            'worker_id' => 'gpu-worker-stale',
            'session_id' => 'gpu-orphan',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4'],
            'lease_seconds' => 120,
            'ttl_seconds' => 600,
        ], $this->workerHeaders())->assertCreated();

        WorkerRegistration::query()
            ->where('worker_id', 'gpu-worker-stale')
            ->update(['last_heartbeat_at' => now()->subMinutes(5)]);

        $this->getJson('/api/worker-sessions/gpu-orphan', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('session.status', 'orphaned')
            ->assertJsonPath('session.failure_reason', 'worker_heartbeat_stale');

        $this->registerWorkerThroughProtocol(
            workerId: 'gpu-worker-reacquire',
            taskQueue: 'gpu-activities',
            capabilities: ['gpu:nvidia-l4'],
        );

        $reacquire = $this->postJson('/api/worker/sessions', [
            'worker_id' => 'gpu-worker-reacquire',
            'session_id' => 'gpu-orphan',
            'queue' => 'gpu-activities',
            'requirements' => ['gpu:nvidia-l4'],
            'lease_seconds' => 120,
            'ttl_seconds' => 600,
        ], $this->workerHeaders());

        $reacquire->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('outcome', 'reacquired')
            ->assertJsonPath('session.status', 'active')
            ->assertJsonPath('session.lease_owner', 'gpu-worker-reacquire');
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  list<string>  $supportedActivityTypes
     * @param  list<string>  $capabilities
     */
    private function registerWorkerThroughProtocol(
        string $workerId,
        string $taskQueue,
        array $supportedWorkflowTypes = [],
        array $supportedActivityTypes = [],
        array $capabilities = [],
        int $maxConcurrentWorkerSessions = 10,
    ): void {
        $this->postJson('/api/worker/register', [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => 'php',
            'supported_workflow_types' => $supportedWorkflowTypes,
            'supported_activity_types' => $supportedActivityTypes,
            'capabilities' => $capabilities,
            'max_concurrent_worker_sessions' => $maxConcurrentWorkerSessions,
        ], $this->workerHeaders())->assertCreated();
    }
}
