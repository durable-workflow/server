<?php

namespace Tests\Feature;

use App\Support\NamespaceWorkflowScope;
use App\Support\ServerPollingCache;
use App\Support\TaskQueueAdmission;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\WorkflowStub;

class TaskQueueAdmissionTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_unlimited_admission_still_dispatches_when_the_polling_cache_is_unavailable(): void
    {
        config([
            'server.admission.workflow_tasks.max_active_leases_per_queue' => null,
            'server.admission.workflow_tasks.max_active_leases_per_namespace' => null,
            'server.admission.workflow_tasks.max_dispatches_per_minute' => null,
            'server.admission.workflow_tasks.max_dispatches_per_minute_per_namespace' => null,
            'server.admission.queue_overrides' => [],
        ]);

        $admission = new TaskQueueAdmission($this->unavailablePollingCache());
        $callbackCalled = false;

        $result = $admission->withLeaseAdmission(
            'default',
            'unlimited-queue',
            TaskQueueAdmission::WORKFLOW_TASKS,
            function () use (&$callbackCalled): string {
                $callbackCalled = true;

                return 'leased';
            },
        );

        $this->assertTrue($callbackCalled);
        $this->assertSame('leased', $result);
    }

    public function test_configured_admission_fails_closed_when_the_polling_cache_is_unavailable(): void
    {
        config([
            'server.admission.workflow_tasks.max_dispatches_per_minute_per_namespace' => 10,
            'server.admission.queue_overrides' => [],
        ]);

        $admission = new TaskQueueAdmission($this->unavailablePollingCache());
        $callbackCalled = false;

        $result = $admission->withLeaseAdmission(
            'tenant-a',
            'shared-queue',
            TaskQueueAdmission::WORKFLOW_TASKS,
            function () use (&$callbackCalled): string {
                $callbackCalled = true;

                return 'leased';
            },
        );

        $this->assertFalse($callbackCalled);
        $this->assertNull($result);
        $this->assertSame(
            'unavailable',
            $admission->budget('tenant-a', 'shared-queue', TaskQueueAdmission::WORKFLOW_TASKS)['status'],
        );
    }

    public function test_configured_admission_does_not_hide_task_claim_failures(): void
    {
        config([
            'server.admission.workflow_tasks.max_dispatches_per_minute_per_namespace' => 10,
            'server.admission.queue_overrides' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('task claim failed');

        app(TaskQueueAdmission::class)->withLeaseAdmission(
            'tenant-a',
            'shared-queue',
            TaskQueueAdmission::WORKFLOW_TASKS,
            static fn (): never => throw new \RuntimeException('task claim failed'),
        );
    }

    public function test_unlimited_admission_budget_does_not_query_the_growing_task_table(): void
    {
        config([
            'server.admission.workflow_tasks.max_active_leases_per_queue' => null,
            'server.admission.workflow_tasks.max_active_leases_per_namespace' => null,
            'server.admission.workflow_tasks.max_dispatches_per_minute' => null,
            'server.admission.workflow_tasks.max_dispatches_per_minute_per_namespace' => null,
            'server.admission.queue_overrides' => [],
        ]);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $budget = app(TaskQueueAdmission::class)->budget(
            'default',
            'unrelated-worker-queue',
            TaskQueueAdmission::WORKFLOW_TASKS,
        );

        $this->assertSame('unlimited', $budget['status']);
        $this->assertSame(0, $budget['active_lease_count']);
        $this->assertSame(0, $budget['namespace_active_lease_count']);
        $this->assertFalse(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'workflow_tasks'),
            ),
            "Unlimited admission must not scan workflow_tasks.\nQueries:\n".implode("\n", $queries),
        );
    }

    public function test_dispatch_only_admission_budget_does_not_count_active_task_leases(): void
    {
        config([
            'server.admission.workflow_tasks.max_active_leases_per_queue' => null,
            'server.admission.workflow_tasks.max_active_leases_per_namespace' => null,
            'server.admission.workflow_tasks.max_dispatches_per_minute' => 100,
            'server.admission.workflow_tasks.max_dispatches_per_minute_per_namespace' => null,
            'server.admission.queue_overrides' => [],
        ]);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $budget = app(TaskQueueAdmission::class)->budget(
            'default',
            'unrelated-worker-queue',
            TaskQueueAdmission::WORKFLOW_TASKS,
        );

        $this->assertSame(0, $budget['active_lease_count']);
        $this->assertSame(0, $budget['namespace_active_lease_count']);
        $this->assertFalse(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'workflow_tasks'),
            ),
            "Dispatch-only admission must not count workflow task leases.\nQueries:\n".implode("\n", $queries),
        );
    }

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

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-admission',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'workflow-admission-poll-1',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-workflow-admission-1');

        $workflowTaskId = (string) $firstPoll->json('task.task_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-admission',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'workflow-admission-poll-1',
            ])
            ->assertOk()
            ->assertJsonPath('task.task_id', $workflowTaskId)
            ->assertJsonPath('task.workflow_id', 'wf-workflow-admission-1');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-admission',
                'task_queue' => 'external-workflows',
                'poll_request_id' => 'workflow-admission-poll-2',
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'throttled');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-workflows')
            ->assertOk()
            ->assertJsonPath('admission.workflow_tasks.status', 'throttled')
            ->assertJsonPath('admission.workflow_tasks.server_max_active_leases_per_queue', 1)
            ->assertJsonPath('admission.workflow_tasks.server_active_lease_count', 1)
            ->assertJsonPath('admission.workflow_tasks.server_remaining_active_lease_capacity', 0)
            ->assertJsonPath('admission.workflow_tasks.server_max_dispatches_per_minute', null)
            ->assertJsonPath('admission.workflow_tasks.server_dispatch_count_this_minute', 0)
            ->assertJsonPath('admission.workflow_tasks.server_remaining_dispatch_capacity', null)
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

    public function test_workflow_task_polls_respect_budget_group_dispatch_rate_caps_across_queues(): void
    {
        Queue::fake();

        config([
            'server.admission.queue_overrides' => [
                'default:external-workflows-a' => [
                    'workflow_tasks' => [
                        'dispatch_budget_group' => 'downstream-openai',
                        'max_dispatches_per_minute_per_budget_group' => 1,
                    ],
                ],
                'default:external-workflows-b' => [
                    'workflow_tasks' => [
                        'dispatch_budget_group' => 'downstream-openai',
                        'max_dispatches_per_minute_per_budget_group' => 1,
                    ],
                ],
            ],
        ]);

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->registerWorker('php-workflow-budget-group-a', 'external-workflows-a');
        $this->registerWorker('php-workflow-budget-group-b', 'external-workflows-b');

        $firstStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-budget-group-1',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-a',
                'input' => ['Ada'],
            ]);
        $firstStart->assertCreated();

        $secondStart = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-workflow-budget-group-2',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'external-workflows-b',
                'input' => ['Grace'],
            ]);
        $secondStart->assertCreated();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-budget-group-a',
                'task_queue' => 'external-workflows-a',
            ])
            ->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-workflow-budget-group-1');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'php-workflow-budget-group-b',
                'task_queue' => 'external-workflows-b',
            ])
            ->assertOk()
            ->assertJsonPath('task', null);

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-workflows-b')
            ->assertOk()
            ->assertJsonPath('admission.workflow_tasks.status', 'throttled')
            ->assertJsonPath('admission.workflow_tasks.server_budget_source', 'server.admission.queue_overrides')
            ->assertJsonPath('admission.workflow_tasks.server_dispatch_budget_group', 'downstream-openai')
            ->assertJsonPath('admission.workflow_tasks.server_max_dispatches_per_minute_per_budget_group', 1)
            ->assertJsonPath('admission.workflow_tasks.server_budget_group_dispatch_count_this_minute', 1)
            ->assertJsonPath('admission.workflow_tasks.server_remaining_budget_group_dispatch_capacity', 0)
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
        $this->registerWorker('php-activity-admission-other', 'external-activities');

        $firstWorkflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-admission-1');
        $firstStart = $firstWorkflow->start('Ada');
        NamespaceWorkflowScope::bind('default', $firstWorkflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($firstStart->runId());

        $secondWorkflow = WorkflowStub::make(ExternalGreetingWorkflow::class, 'wf-activity-admission-2');
        $secondStart = $secondWorkflow->start('Grace');
        NamespaceWorkflowScope::bind('default', $secondWorkflow->id(), ExternalGreetingWorkflow::class);
        $this->runReadyWorkflowTask($secondStart->runId());

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-admission',
                'task_queue' => 'external-activities',
                'poll_request_id' => 'activity-admission-poll-1',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-activity-admission-1');

        $activityTaskId = (string) $firstPoll->json('task.task_id');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-admission',
                'task_queue' => 'external-activities',
                'poll_request_id' => 'activity-admission-poll-1',
            ])
            ->assertOk()
            ->assertJsonPath('task.task_id', $activityTaskId)
            ->assertJsonPath('task.lease_owner', 'php-activity-admission');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-admission',
                'task_queue' => 'external-activities',
                'poll_request_id' => 'activity-admission-poll-2',
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'throttled');

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'php-activity-admission-other',
                'task_queue' => 'external-activities',
            ])
            ->assertOk()
            ->assertJsonPath('task', null)
            ->assertJsonPath('poll_status', 'throttled');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/external-activities')
            ->assertOk()
            ->assertJsonPath('admission.activity_tasks.status', 'throttled')
            ->assertJsonPath('admission.activity_tasks.server_budget_source', 'server.admission.queue_overrides')
            ->assertJsonPath('admission.activity_tasks.server_max_active_leases_per_queue', 1)
            ->assertJsonPath('admission.activity_tasks.server_active_lease_count', 1)
            ->assertJsonPath('admission.activity_tasks.server_remaining_active_lease_capacity', 0)
            ->assertJsonPath('admission.activity_tasks.server_max_dispatches_per_minute', null)
            ->assertJsonPath('admission.activity_tasks.server_dispatch_count_this_minute', 0)
            ->assertJsonPath('admission.activity_tasks.server_remaining_dispatch_capacity', null);
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
        $this->registerWorker('php-activity-dispatch-budget-other', 'external-activities');

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
                'worker_id' => 'php-activity-dispatch-budget-other',
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

    private function unavailablePollingCache(): ServerPollingCache
    {
        $repository = \Mockery::mock(CacheRepository::class);
        $repository->shouldReceive('get')
            ->with('server:polling-cache:availability-probe')
            ->andThrow(new \RuntimeException('cache unavailable'));

        $factory = \Mockery::mock(CacheFactory::class);
        $factory->shouldReceive('store')->andReturn($repository);

        return new ServerPollingCache($factory, new Filesystem);
    }
}
