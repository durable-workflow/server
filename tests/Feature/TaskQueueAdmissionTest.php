<?php

namespace Tests\Feature;

use App\Support\NamespaceWorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;

class TaskQueueAdmissionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_workflow_task_polls_respect_server_side_active_lease_caps(): void
    {
        Queue::fake();

        config([
            'server.admission.workflow_tasks.max_active_leases_per_queue' => 1,
        ]);

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->registerWorker('php-workflow-admission', 'external-workflows');

        $firstStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-admission-1',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);
        $firstStart->assertCreated();

        $secondStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-admission-2',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);
        $secondStart->assertCreated();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-admission',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-workflow-admission-1');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-admission',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-workflows')
            ->assertOk()
            ->assertJsonPath('admission.workflow_tasks.status', 'throttled')
            ->assertJsonPath('admission.workflow_tasks.server_max_active_leases_per_queue', 1)
            ->assertJsonPath('admission.workflow_tasks.server_active_lease_count', 1)
            ->assertJsonPath('admission.workflow_tasks.server_remaining_active_lease_capacity', 0)
            ->assertJsonPath('admission.workflow_tasks.server_lock_required', true)
            ->assertJsonPath('admission.workflow_tasks.server_lock_supported', true);
    }

    public function test_activity_task_polls_respect_queue_specific_active_lease_caps(): void
    {
        Queue::fake();

        config([
            'server.admission.queue_overrides' => [
                'default:external-activities' => [
                    'activity_tasks' => [
                        'max_active_leases' => 1,
                    ],
                ],
            ],
        ]);

        $this->createNamespace('default');
        $this->registerWorker('php-activity-admission', 'external-activities');

        $firstWorkflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-admission-1');
        $firstStart = $firstWorkflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $firstWorkflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($firstStart->runId());

        $secondWorkflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-admission-2');
        $secondStart = $secondWorkflow->start('Grace');
        NamespaceWorkflowScope::bind('default', $secondWorkflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($secondStart->runId());

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-admission',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-activity-admission-1');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-admission',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-activities')
            ->assertOk()
            ->assertJsonPath('admission.activity_tasks.status', 'throttled')
            ->assertJsonPath('admission.activity_tasks.server_budget_source', 'server.admission.queue_overrides')
            ->assertJsonPath('admission.activity_tasks.server_max_active_leases_per_queue', 1)
            ->assertJsonPath('admission.activity_tasks.server_active_lease_count', 1)
            ->assertJsonPath('admission.activity_tasks.server_remaining_active_lease_capacity', 0);
    }
}
