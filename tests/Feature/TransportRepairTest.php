<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class TransportRepairTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

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
                'selected_existing_task_candidates',
                'selected_missing_task_candidates',
                'selected_total_candidates',
                'repaired_existing_tasks',
                'repaired_missing_tasks',
                'dispatched_tasks',
                'existing_task_failures',
                'missing_run_failures',
            ]);
    }

    public function test_system_repair_pass_with_empty_system_returns_zero_repairs(): void
    {
        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/system/repair/pass');

        $response->assertOk()
            ->assertJsonPath('selected_total_candidates', 0)
            ->assertJsonPath('repaired_existing_tasks', 0)
            ->assertJsonPath('repaired_missing_tasks', 0)
            ->assertJsonPath('dispatched_tasks', 0);

        $this->assertSame([], $response->json('existing_task_failures'));
        $this->assertSame([], $response->json('missing_run_failures'));
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
