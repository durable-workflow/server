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

    public function test_workflow_task_polls_respect_server_side_dispatch_rate_caps(): void
    {
        Queue::fake();

        config([
            'server.admission.workflow_tasks.max_dispatches_per_minute' => 1,
        ]);

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->registerWorker('php-workflow-dispatch-budget', 'external-workflows');

        $firstStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-dispatch-budget-1',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Ada'],
            ]);
        $firstStart->assertCreated();

        $secondStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-dispatch-budget-2',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows',
                'input' => ['Grace'],
            ]);
        $secondStart->assertCreated();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-dispatch-budget',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-workflow-dispatch-budget-1');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-dispatch-budget',
                'task_queue' => 'external-workflows',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-workflows')
            ->assertOk()
            ->assertJsonPath('admission.workflow_tasks.status', 'throttled')
            ->assertJsonPath('admission.workflow_tasks.server_max_active_leases_per_queue', null)
            ->assertJsonPath('admission.workflow_tasks.server_max_dispatches_per_minute', 1)
            ->assertJsonPath('admission.workflow_tasks.server_dispatch_count_this_minute', 1)
            ->assertJsonPath('admission.workflow_tasks.server_remaining_dispatch_capacity', 0)
            ->assertJsonPath('admission.workflow_tasks.server_lock_required', true)
            ->assertJsonPath('admission.workflow_tasks.server_lock_supported', true);
    }

    public function test_workflow_task_polls_respect_namespace_active_lease_caps_across_queues(): void
    {
        Queue::fake();

        config([
            'server.admission.queue_overrides' => [
                'default:*' => [
                    'workflow_tasks' => [
                        'max_active_leases_per_namespace' => 1,
                    ],
                ],
            ],
        ]);

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->registerWorker('php-workflow-namespace-a', 'external-workflows-a');
        $this->registerWorker('php-workflow-namespace-b', 'external-workflows-b');

        $firstStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-namespace-admission-1',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-a',
                'input' => ['Ada'],
            ]);
        $firstStart->assertCreated();

        $secondStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-namespace-admission-2',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-b',
                'input' => ['Grace'],
            ]);
        $secondStart->assertCreated();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-namespace-a',
                'task_queue' => 'external-workflows-a',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-workflow-namespace-admission-1');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-namespace-b',
                'task_queue' => 'external-workflows-b',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-workflows-b')
            ->assertOk()
            ->assertJsonPath('admission.workflow_tasks.status', 'throttled')
            ->assertJsonPath('admission.workflow_tasks.server_budget_source', 'server.admission.queue_overrides')
            ->assertJsonPath('admission.workflow_tasks.server_max_active_leases_per_queue', null)
            ->assertJsonPath('admission.workflow_tasks.server_max_active_leases_per_namespace', 1)
            ->assertJsonPath('admission.workflow_tasks.server_namespace_active_lease_count', 1)
            ->assertJsonPath('admission.workflow_tasks.server_remaining_namespace_active_lease_capacity', 0)
            ->assertJsonPath('admission.workflow_tasks.server_lock_required', true)
            ->assertJsonPath('admission.workflow_tasks.server_lock_supported', true);
    }

    public function test_workflow_task_polls_respect_namespace_dispatch_rate_caps_across_queues(): void
    {
        Queue::fake();

        config([
            'server.admission.workflow_tasks.max_dispatches_per_minute_per_namespace' => 1,
        ]);

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->registerWorker('php-workflow-namespace-dispatch-a', 'external-workflows-a');
        $this->registerWorker('php-workflow-namespace-dispatch-b', 'external-workflows-b');

        $firstStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-namespace-dispatch-1',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-a',
                'input' => ['Ada'],
            ]);
        $firstStart->assertCreated();

        $secondStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-namespace-dispatch-2',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-b',
                'input' => ['Grace'],
            ]);
        $secondStart->assertCreated();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-namespace-dispatch-a',
                'task_queue' => 'external-workflows-a',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-workflow-namespace-dispatch-1');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-namespace-dispatch-b',
                'task_queue' => 'external-workflows-b',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-workflows-b')
            ->assertOk()
            ->assertJsonPath('admission.workflow_tasks.status', 'throttled')
            ->assertJsonPath('admission.workflow_tasks.server_budget_source', 'server.admission.workflow_tasks.max_dispatches_per_minute_per_namespace')
            ->assertJsonPath('admission.workflow_tasks.server_max_dispatches_per_minute', null)
            ->assertJsonPath('admission.workflow_tasks.server_max_dispatches_per_minute_per_namespace', 1)
            ->assertJsonPath('admission.workflow_tasks.server_namespace_dispatch_count_this_minute', 1)
            ->assertJsonPath('admission.workflow_tasks.server_remaining_namespace_dispatch_capacity', 0);
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

    public function test_activity_task_polls_respect_queue_specific_dispatch_rate_caps(): void
    {
        Queue::fake();

        config([
            'server.admission.queue_overrides' => [
                'default:external-activities' => [
                    'activity_tasks' => [
                        'max_dispatches_per_minute' => 1,
                    ],
                ],
            ],
        ]);

        $this->createNamespace('default');
        $this->registerWorker('php-activity-dispatch-budget', 'external-activities');

        $firstWorkflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-dispatch-budget-1');
        $firstStart = $firstWorkflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $firstWorkflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($firstStart->runId());

        $secondWorkflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-dispatch-budget-2');
        $secondStart = $secondWorkflow->start('Grace');
        NamespaceWorkflowScope::bind('default', $secondWorkflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($secondStart->runId());

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-dispatch-budget',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-activity-dispatch-budget-1');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-dispatch-budget',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-activities')
            ->assertOk()
            ->assertJsonPath('admission.activity_tasks.status', 'throttled')
            ->assertJsonPath('admission.activity_tasks.server_budget_source', 'server.admission.queue_overrides')
            ->assertJsonPath('admission.activity_tasks.server_max_dispatches_per_minute', 1)
            ->assertJsonPath('admission.activity_tasks.server_dispatch_count_this_minute', 1)
            ->assertJsonPath('admission.activity_tasks.server_remaining_dispatch_capacity', 0);
    }
}
