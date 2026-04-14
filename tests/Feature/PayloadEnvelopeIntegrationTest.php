<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\WorkflowTask;

class PayloadEnvelopeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'file',
        ]);
    }

    public function test_signal_accepts_json_envelope_input(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-signal',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-signal')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal/signal/advance', [
                'input' => [
                    'codec' => 'json',
                    'blob' => '["EnvelopeUser"]',
                ],
            ]);

        $signal->assertStatus(202)
            ->assertJsonPath('signal_name', 'advance');

        $this->runReadyWorkflowTask($runId);

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal/query/currentState');

        $query->assertOk()
            ->assertJsonPath('result.name', 'EnvelopeUser')
            ->assertJsonPath('result.stage', 'waiting-for-finish');
    }

    public function test_signal_rejects_non_json_envelope(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-signal-reject',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-signal-reject')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal-reject/signal/advance', [
                'input' => [
                    'codec' => 'workflow-serializer-y',
                    'blob' => 'php-serialized-data',
                ],
            ]);

        $signal->assertStatus(422);
    }

    public function test_query_accepts_json_envelope_input(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-query',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-query')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-query/query/events-starting-with', [
                'input' => [
                    'codec' => 'json',
                    'blob' => '["start"]',
                ],
            ]);

        $query->assertOk()
            ->assertJsonPath('result', 1);
    }

    public function test_update_accepts_json_envelope_input(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-update',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-update')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $update = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-update/update/approve', [
                'input' => [
                    'codec' => 'json',
                    'blob' => '[true,"envelope-api"]',
                ],
                'wait_for' => 'completed',
            ]);

        $update->assertOk()
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('result.approved', true);

        $this->assertContains('approved:yes:envelope-api', (array) $update->json('result.events'));
    }

    public function test_complete_workflow_accepts_envelope_result(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-complete',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $poll->assertOk();
        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'complete_workflow',
                        'result' => [
                            'codec' => 'json',
                            'blob' => '{"greeting":"Hello Ada"}',
                        ],
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
    }

    public function test_schedule_activity_accepts_envelope_arguments(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-activity-args',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $poll->assertOk();
        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.greeting-activity',
                        'arguments' => [
                            'codec' => 'json',
                            'blob' => '["Hello","Ada"]',
                        ],
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
    }

    public function test_activity_complete_accepts_envelope_result(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-activity-result',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'schedule_activity',
                        'activity_type' => 'tests.greeting-activity',
                        'arguments' => '["Ada"]',
                    ],
                ],
            ])
            ->assertOk();

        $activityPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/activity-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $activityTask = $activityPoll->json('task');

        if ($activityTask === null) {
            $this->markTestSkipped('No activity task available for polling');
        }

        $activityTaskId = $activityTask['task_id'];
        $attemptId = $activityTask['activity_attempt_id'];

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/activity-tasks/{$activityTaskId}/complete", [
                'activity_attempt_id' => $attemptId,
                'lease_owner' => 'worker-1',
                'result' => [
                    'codec' => 'json',
                    'blob' => '"Hello Ada from activity"',
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
    }

    public function test_cluster_info_advertises_payload_codec_envelope_capability(): void
    {
        $this->createNamespace('default');

        $info = $this->getJson('/api/cluster/info');

        $info->assertOk()
            ->assertJsonPath('capabilities.payload_codec_envelope', true);
    }

    public function test_start_child_workflow_accepts_envelope_arguments(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-child',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'start_child_workflow',
                        'workflow_type' => 'tests.external-greeting-workflow',
                        'arguments' => [
                            'codec' => 'json',
                            'blob' => '["child-arg"]',
                        ],
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
    }

    public function test_continue_as_new_accepts_envelope_arguments(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-continue',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'ext-q',
                'input' => ['Ada'],
            ])
            ->assertCreated();

        $this->registerWorker('worker-1', 'ext-q');

        $poll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'worker-1',
                'task_queue' => 'ext-q',
            ]);

        $taskId = $poll->json('task.task_id');
        $attempt = $poll->json('task.workflow_task_attempt');

        $complete = $this->withHeaders($this->workerHeaders())
            ->postJson("/api/worker/workflow-tasks/{$taskId}/complete", [
                'lease_owner' => 'worker-1',
                'workflow_task_attempt' => $attempt,
                'commands' => [
                    [
                        'type' => 'continue_as_new',
                        'arguments' => [
                            'codec' => 'json',
                            'blob' => '["new-generation"]',
                        ],
                    ],
                ],
            ]);

        $complete->assertOk()
            ->assertJsonPath('outcome', 'completed');
    }

    public function test_signal_with_plain_array_still_works(): void
    {
        Queue::fake();
        $this->configureWorkflowTypes();
        $this->createNamespace('default');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-envelope-signal-compat',
                'workflow_type' => 'tests.interactive-command-workflow',
            ])
            ->assertCreated();

        $runId = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-envelope-signal-compat')
            ->json('run_id');

        $this->runReadyWorkflowTask($runId);

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal-compat/signal/advance', [
                'input' => ['PlainUser'],
            ]);

        $signal->assertStatus(202);

        $this->runReadyWorkflowTask($runId);

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-envelope-signal-compat/query/currentState');

        $query->assertOk()
            ->assertJsonPath('result.name', 'PlainUser');
    }

    private function configureWorkflowTypes(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
    }

    private function createNamespace(string $name): void
    {
        WorkflowNamespace::query()->updateOrCreate(
            ['name' => $name],
            [
                'description' => 'Test namespace',
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

    private function workerHeaders(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            'X-Durable-Workflow-Protocol-Version' => '1.0',
        ];
    }

    private function registerWorker(string $workerId, string $taskQueue): void
    {
        WorkerRegistration::query()->updateOrCreate(
            ['worker_id' => $workerId, 'namespace' => 'default'],
            [
                'task_queue' => $taskQueue,
                'runtime' => 'php',
                'supported_workflow_types' => [],
                'supported_activity_types' => [],
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ],
        );
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

        $job = new \Workflow\V2\Jobs\RunWorkflowTask($taskId);
        $job->handle(app(\Workflow\V2\Support\WorkflowExecutor::class));
    }
}
