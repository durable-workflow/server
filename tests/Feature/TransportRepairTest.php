<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;

class TransportRepairTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    // ── Cluster Info Repair Diagnostics ─────────────────────────────

    public function test_cluster_info_includes_task_repair_diagnostics(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonStructure([
                'task_repair' => [
                    'policy' => [
                        'redispatch_after_seconds',
                        'loop_throttle_seconds',
                        'scan_limit',
                        'scan_strategy',
                        'failure_backoff_max_seconds',
                        'failure_backoff_strategy',
                    ],
                    'candidates' => [
                        'existing_task_candidates',
                        'missing_task_candidates',
                        'total_candidates',
                        'scan_limit',
                        'scan_strategy',
                    ],
                ],
            ]);
    }

    public function test_cluster_info_repair_policy_reflects_configuration(): void
    {
        $response = $this->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('task_repair.policy.scan_strategy', 'scope_fair_round_robin')
            ->assertJsonPath('task_repair.policy.failure_backoff_strategy', 'exponential_by_repair_count');

        $this->assertIsInt($response->json('task_repair.policy.redispatch_after_seconds'));
        $this->assertIsInt($response->json('task_repair.policy.scan_limit'));
    }

    public function test_cluster_info_shows_zero_repair_candidates_when_healthy(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('task_repair.candidates.total_candidates', 0)
            ->assertJsonPath('task_repair.candidates.existing_task_candidates', 0)
            ->assertJsonPath('task_repair.candidates.missing_task_candidates', 0)
            ->assertJsonPath('task_repair.candidates.scan_pressure', false);
    }

    // ── System Repair Status ────────────────────────────────────────

    public function test_system_repair_status_returns_policy_and_candidates(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/system/repair')
            ->assertOk()
            ->assertJsonStructure([
                'policy' => [
                    'redispatch_after_seconds',
                    'loop_throttle_seconds',
                    'scan_limit',
                    'scan_strategy',
                    'failure_backoff_max_seconds',
                    'failure_backoff_strategy',
                ],
                'candidates' => [
                    'existing_task_candidates',
                    'missing_task_candidates',
                    'total_candidates',
                    'scan_limit',
                ],
            ]);
    }

    public function test_system_repair_status_requires_authentication(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->getJson('/api/system/repair')
            ->assertUnauthorized();
    }

    // ── System Repair Pass ──────────────────────────────────────────

    public function test_system_repair_pass_returns_report(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/repair/pass')
            ->assertOk()
            ->assertJsonStructure([
                'throttled',
                'selected_existing_task_candidates',
                'selected_missing_task_candidates',
                'selected_total_candidates',
                'repaired_existing_tasks',
                'repaired_missing_tasks',
                'dispatched_tasks',
                'selected_command_contract_candidates',
                'backfilled_command_contracts',
                'command_contract_backfill_unavailable',
                'existing_task_failures',
                'missing_run_failures',
                'command_contract_failures',
            ]);
    }

    public function test_system_repair_pass_with_empty_system_returns_zero_repairs(): void
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/repair/pass');

        $response->assertOk()
            ->assertJsonPath('throttled', false)
            ->assertJsonPath('selected_total_candidates', 0)
            ->assertJsonPath('repaired_existing_tasks', 0)
            ->assertJsonPath('repaired_missing_tasks', 0)
            ->assertJsonPath('dispatched_tasks', 0)
            ->assertJsonPath('selected_command_contract_candidates', 0)
            ->assertJsonPath('backfilled_command_contracts', 0)
            ->assertJsonPath('command_contract_backfill_unavailable', 0);

        $this->assertSame([], $response->json('existing_task_failures'));
        $this->assertSame([], $response->json('missing_run_failures'));
        $this->assertSame([], $response->json('command_contract_failures'));
    }

    public function test_system_repair_pass_accepts_run_id_filter(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/repair/pass', [
                'run_ids' => ['non-existent-run-id'],
            ])
            ->assertOk()
            ->assertJsonPath('selected_total_candidates', 0);
    }

    public function test_system_repair_pass_accepts_instance_id_filter(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/repair/pass', [
                'instance_id' => 'non-existent-instance',
            ])
            ->assertOk()
            ->assertJsonPath('selected_total_candidates', 0);
    }

    public function test_system_repair_pass_recovers_expired_poll_mode_workflow_task_leases(): void
    {
        config(['server.polling.timeout' => 0]);

        $this->configureWorkflowTypes([
            'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
        ]);
        $this->registerWorker(
            workerId: 'repair-worker-1',
            taskQueue: 'repair-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-repair-expired-poll-lease',
                'workflow_type' => 'tests.external-greeting-workflow',
                'task_queue' => 'repair-queue',
                'input' => ['Ada'],
            ]);

        $start->assertCreated();

        $runId = (string) $start->json('run_id');

        $firstPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'repair-worker-1',
                'task_queue' => 'repair-queue',
            ]);

        $firstPoll->assertOk()
            ->assertJsonPath('task.workflow_id', 'wf-repair-expired-poll-lease')
            ->assertJsonPath('task.lease_owner', 'repair-worker-1')
            ->assertJsonPath('task.workflow_task_attempt', 1);

        $taskId = (string) $firstPoll->json('task.task_id');

        WorkflowTask::query()
            ->whereKey($taskId)
            ->update([
                'lease_expires_at' => now()->subSecond(),
            ]);

        $repair = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/repair/pass', [
                'run_ids' => [$runId],
            ]);

        $repair->assertOk()
            ->assertJsonPath('selected_existing_task_candidates', 1)
            ->assertJsonPath('repaired_existing_tasks', 1)
            ->assertJsonPath('dispatched_tasks', 1);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->findOrFail($taskId);

        $this->assertEquals(TaskStatus::Ready, $task->status);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(1, $task->repair_count);
        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);

        $this->registerWorker(
            workerId: 'repair-worker-2',
            taskQueue: 'repair-queue',
            supportedWorkflowTypes: ['tests.external-greeting-workflow'],
        );

        $secondPoll = $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'repair-worker-2',
                'task_queue' => 'repair-queue',
            ]);

        $secondPoll->assertOk()
            ->assertJsonPath('task.task_id', $taskId)
            ->assertJsonPath('task.lease_owner', 'repair-worker-2')
            ->assertJsonPath('task.workflow_task_attempt', 2);
    }

    public function test_system_repair_pass_requires_authentication(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $this->postJson('/api/system/repair/pass')
            ->assertUnauthorized();
    }

    // ── Task Queue Repair Stats ─────────────────────────────────────

    public function test_task_queue_describe_includes_repair_stats(): void
    {
        $this->createNamespace('default');
        $this->registerWorker('worker-1', 'test-queue');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/test-queue')
            ->assertOk()
            ->assertJsonStructure([
                'repair' => [
                    'candidates',
                    'dispatch_failed',
                    'expired_leases',
                    'dispatch_overdue',
                    'needs_attention',
                    'policy' => ['redispatch_after_seconds'],
                ],
            ]);
    }

    public function test_task_queue_repair_stats_show_healthy_when_no_issues(): void
    {
        $this->createNamespace('default');
        $this->registerWorker('worker-1', 'test-queue');

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/task-queues/test-queue')
            ->assertOk()
            ->assertJsonPath('repair.candidates', 0)
            ->assertJsonPath('repair.dispatch_failed', 0)
            ->assertJsonPath('repair.expired_leases', 0)
            ->assertJsonPath('repair.dispatch_overdue', 0)
            ->assertJsonPath('repair.needs_attention', false);
    }
}
