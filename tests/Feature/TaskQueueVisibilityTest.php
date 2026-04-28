<?php

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

class TaskQueueVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_workflow_backlog_stale_pollers_and_expired_workflow_leases(): void
    {
        Queue::fake();

        config([
            'server.workers.stale_after_seconds' => 60,
            'server.query_tasks.max_pending_per_queue' => 1,
        ]);

        $this->createNamespace('default', 'Default namespace');
        $this->configureWorkflowTypes();

        WorkerRegistration::query()->create([
            'worker_id' => 'php-worker-active',
            'namespace' => 'default',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'sdk_version' => '1.2.3',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 10,
            'max_concurrent_activity_tasks' => 0,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        WorkerRegistration::query()->create([
            'worker_id' => 'php-worker-stale',
            'namespace' => 'default',
            'task_queue' => 'external-workflows',
            'runtime' => 'php',
            'sdk_version' => '1.2.3',
            'supported_workflow_types' => ['tests.external-greeting-workflow'],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 10,
            'max_concurrent_activity_tasks' => 0,
            'last_heartbeat_at' => now()->subMinutes(5),
            'status' => 'active',
        ]);

        $leasedStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-task-queue-leased',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);

        $leasedStart->assertCreated();

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-worker-active',
                'task_queue' => 'external-workflows',
            ]);

        $poll->assertOk();

        $leasedTaskId = (string) $poll->json('task.task_id');
        $expiredAt = now()->subMinute()->startOfSecond();

        WorkflowTask::query()->findOrFail($leasedTaskId)
            ->forceFill([
                'lease_expires_at' => $expiredAt,
            ])->save();

        $readyStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-task-queue-ready',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);

        $readyStart->assertCreated();
        $run = WorkflowRun::query()->findOrFail((string) $readyStart->json('run_id'));

        app(WorkflowQueryTaskBroker::class)->enqueue('default', $run, 'status', [
            'codec' => CodecRegistry::defaultCodec(),
            'blob' => 'null',
        ]);

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-workflows');

        $describe->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('name', 'external-workflows')
            ->assertJsonPath('stats.approximate_backlog_count', 1)
            ->assertJsonPath('stats.workflow_tasks.ready_count', 1)
            ->assertJsonPath('stats.workflow_tasks.leased_count', 1)
            ->assertJsonPath('stats.workflow_tasks.expired_lease_count', 1)
            ->assertJsonPath('stats.activity_tasks.ready_count', 0)
            ->assertJsonPath('stats.pollers.active_count', 1)
            ->assertJsonPath('stats.pollers.stale_count', 1)
            ->assertJsonPath('pollers.0.worker_id', 'php-worker-active')
            ->assertJsonPath('pollers.0.status', 'active')
            ->assertJsonPath('pollers.0.is_stale', false)
            ->assertJsonPath('pollers.1.worker_id', 'php-worker-stale')
            ->assertJsonPath('pollers.1.status', 'stale')
            ->assertJsonPath('pollers.1.is_stale', true)
            ->assertJsonPath('current_leases.0.task_id', $leasedTaskId)
            ->assertJsonPath('current_leases.0.task_type', 'workflow')
            ->assertJsonPath('current_leases.0.workflow_id', 'wf-task-queue-leased')
            ->assertJsonPath('current_leases.0.lease_owner', 'php-worker-active')
            ->assertJsonPath('current_leases.0.is_expired', true)
            ->assertJsonPath('current_leases.0.workflow_task_attempt', 1)
            ->assertJsonPath('stats.oldest_ready_task.workflow_id', 'wf-task-queue-ready')
            ->assertJsonPath('admission.workflow_tasks.budget_source', 'worker_registration.max_concurrent_workflow_tasks')
            ->assertJsonPath('admission.workflow_tasks.active_worker_count', 1)
            ->assertJsonPath('admission.workflow_tasks.configured_slot_count', 10)
            ->assertJsonPath('admission.workflow_tasks.leased_count', 1)
            ->assertJsonPath('admission.workflow_tasks.ready_count', 1)
            ->assertJsonPath('admission.workflow_tasks.available_slot_count', 9)
            ->assertJsonPath('admission.workflow_tasks.status', 'accepting')
            ->assertJsonPath('admission.activity_tasks.budget_source', 'worker_registration.max_concurrent_activity_tasks')
            ->assertJsonPath('admission.activity_tasks.active_worker_count', 1)
            ->assertJsonPath('admission.activity_tasks.configured_slot_count', 0)
            ->assertJsonPath('admission.activity_tasks.status', 'no_slots')
            ->assertJsonPath('admission.query_tasks.budget_source', 'server.query_tasks.max_pending_per_queue')
            ->assertJsonPath('admission.query_tasks.max_pending_per_queue', 1)
            ->assertJsonPath('admission.query_tasks.approximate_pending_count', 1)
            ->assertJsonPath('admission.query_tasks.remaining_pending_capacity', 0)
            ->assertJsonPath('admission.query_tasks.lock_required', true)
            ->assertJsonPath('admission.query_tasks.lock_supported', true)
            ->assertJsonPath('admission.query_tasks.status', 'full');

        $list = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues');

        $list->assertOk()
            ->assertJsonPath('task_queues.0.name', 'external-workflows')
            ->assertJsonPath('task_queues.0.admission.workflow_tasks.configured_slot_count', 10)
            ->assertJsonPath('task_queues.0.admission.query_tasks.status', 'full')
            ->assertJsonMissingPath('task_queues.0.pollers');
    }

    public function test_it_reports_activity_backlog_and_current_activity_attempt_leases(): void
    {
        Queue::fake();

        $this->createNamespace('default', 'Default namespace');

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );

        WorkerRegistration::query()->create([
            'worker_id' => 'php-activity-worker',
            'namespace' => 'default',
            'task_queue' => 'external-activities',
            'runtime' => 'php',
            'sdk_version' => '1.2.3',
            'supported_workflow_types' => [],
            'supported_activity_types' => ['tests.external-greeting-activity'],
            'max_concurrent_workflow_tasks' => 0,
            'max_concurrent_activity_tasks' => 10,
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        $readyWorkflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-ready');
        $readyStart = $readyWorkflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $readyWorkflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($readyStart->runId());

        $leasedWorkflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-leased');
        $leasedStart = $leasedWorkflow->start('Grace');
        NamespaceWorkflowScope::bind('default', $leasedWorkflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($leasedStart->runId());

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-worker',
                'task_queue' => 'external-activities',
            ]);

        $poll->assertOk();

        $activityTaskId = (string) $poll->json('task.task_id');
        $attemptId = (string) $poll->json('task.activity_attempt_id');

        $describe = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-activities');

        $describe->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('stats.approximate_backlog_count', 1)
            ->assertJsonPath('stats.workflow_tasks.ready_count', 0)
            ->assertJsonPath('stats.activity_tasks.ready_count', 1)
            ->assertJsonPath('stats.activity_tasks.leased_count', 1)
            ->assertJsonPath('stats.activity_tasks.expired_lease_count', 0)
            ->assertJsonPath('pollers.0.worker_id', 'php-activity-worker')
            ->assertJsonPath('current_leases.0.task_id', $activityTaskId)
            ->assertJsonPath('current_leases.0.task_type', 'activity')
            ->assertJsonPath('current_leases.0.workflow_id', $readyWorkflow->id())
            ->assertJsonPath('current_leases.0.activity_attempt_id', $attemptId)
            ->assertJsonPath('current_leases.0.attempt_number', 1)
            ->assertJsonPath('current_leases.0.is_expired', false);
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

    /**
     * @return array<string, string>
     */
    private function apiHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $taskId = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('available_at')
            ->value('id');

        $this->assertIsString($taskId);

        $job = new RunWorkflowTask($taskId);
        $job->handle(app(WorkflowExecutor::class));
    }
}
