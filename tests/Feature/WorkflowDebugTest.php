<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Models\WorkflowFailure;

class WorkflowDebugTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.await-approval-workflow' => AwaitApprovalWorkflow::class,
        ]);
    }

    public function test_it_aggregates_a_one_shot_workflow_debug_diagnostic(): void
    {
        $this->registerWorker(
            'debug-worker',
            'debug-queue',
            supportedWorkflowTypes: ['tests.await-approval-workflow'],
        );

        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-queue',
            'business_key' => 'debug-case',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $poll = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'debug-worker',
            'task_queue' => 'debug-queue',
        ], $this->workerHeaders());

        $poll->assertOk();
        $taskId = (string) $poll->json('task.task_id');

        WorkflowFailure::query()->create([
            'workflow_run_id' => $runId,
            'source_kind' => 'workflow_task',
            'source_id' => $taskId,
            'propagation_kind' => 'workflow',
            'failure_category' => FailureCategory::TaskFailure->value,
            'non_retryable' => false,
            'handled' => false,
            'exception_class' => 'RuntimeException',
            'message' => 'Replay failed in debug test.',
            'file' => __FILE__,
            'line' => 55,
        ]);

        $debug = $this->getJson('/api/workflows/wf-debug/debug', $this->controlPlaneHeadersWithWorkerProtocol());

        $debug->assertOk()
            ->assertJsonPath('workflow_id', 'wf-debug')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('diagnostic_status', 'pending_work')
            ->assertJsonPath('execution.status', 'pending')
            ->assertJsonPath('execution.task_queue', 'debug-queue')
            ->assertJsonPath('pending_workflow_tasks.0.task_id', $taskId)
            ->assertJsonPath('pending_workflow_tasks.0.status', 'leased')
            ->assertJsonPath('pending_workflow_tasks.0.lease_owner', 'debug-worker')
            ->assertJsonPath('task_queue.name', 'debug-queue')
            ->assertJsonPath('task_queue.stats.workflow_tasks.leased_count', 1)
            ->assertJsonPath('task_queue.pollers.0.worker_id', 'debug-worker')
            ->assertJsonPath('recent_failures.0.exception_class', 'RuntimeException')
            ->assertJsonPath('recent_failures.0.message', 'Replay failed in debug test.')
            ->assertJsonPath('control_plane.operation', 'debug_workflow')
            ->assertJsonPath('control_plane.workflow_id', 'wf-debug')
            ->assertJsonStructure([
                'generated_at',
                'execution' => [
                    'last_event' => [
                        'sequence',
                        'event_type',
                        'timestamp',
                    ],
                ],
                'pending_workflow_tasks',
                'pending_activities',
                'task_queue' => [
                    'stats',
                    'current_leases',
                ],
                'compatibility' => [
                    'run',
                    'task_queue_pollers',
                    'namespace_worker_fleet',
                ],
                'findings',
            ]);
    }

    public function test_it_can_debug_a_specific_run(): void
    {
        $start = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-debug-run',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'debug-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $start->assertCreated();
        $runId = (string) $start->json('run_id');

        $debug = $this->getJson(
            "/api/workflows/wf-debug-run/runs/{$runId}/debug",
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $debug->assertOk()
            ->assertJsonPath('workflow_id', 'wf-debug-run')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('control_plane.operation', 'debug_workflow')
            ->assertJsonPath('control_plane.run_id', $runId);
    }
}
