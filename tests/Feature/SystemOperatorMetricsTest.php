<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class SystemOperatorMetricsTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->createNamespace('other');
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();

        parent::tearDown();
    }

    public function test_operator_metrics_returns_full_snapshot_with_rollout_safety_keys(): void
    {
        $response = $this->getJson(
            '/api/system/operator-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonStructure([
                'namespace',
                'operator_metrics' => [
                    'generated_at',
                    'runs' => ['repair_needed', 'claim_failed', 'compatibility_blocked'],
                    'tasks' => [
                        'ready',
                        'ready_due',
                        'delayed',
                        'leased',
                        'dispatch_failed',
                        'claim_failed',
                        'dispatch_overdue',
                        'lease_expired',
                        'unhealthy',
                    ],
                    'backlog' => [
                        'runnable_tasks',
                        'delayed_tasks',
                        'leased_tasks',
                        'unhealthy_tasks',
                        'repair_needed_runs',
                        'claim_failed_runs',
                        'compatibility_blocked_runs',
                    ],
                    'repair' => [
                        'missing_task_candidates',
                        'selected_missing_task_candidates',
                        'oldest_missing_run_started_at',
                        'max_missing_run_age_ms',
                    ],
                    'workers' => [
                        'required_compatibility',
                        'active_workers',
                        'active_worker_scopes',
                        'active_workers_supporting_required',
                        'fleet',
                    ],
                    'backend' => ['supported', 'issues'],
                    'structural_limits',
                    'repair_policy' => [
                        'redispatch_after_seconds',
                        'loop_throttle_seconds',
                        'scan_limit',
                        'failure_backoff_max_seconds',
                    ],
                ],
            ]);

        $this->assertIsArray($response->json('operator_metrics.workers.fleet'));
    }

    public function test_operator_metrics_fleet_entries_carry_full_per_scope_shape(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');

        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        config()->set('workflows.v2.compatibility.namespace', 'default');

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'worker-a');
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'imports', 'worker-b');

        $response = $this->getJson(
            '/api/system/operator-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertOk();

        $fleet = $response->json('operator_metrics.workers.fleet');
        $this->assertIsArray($fleet);
        $this->assertCount(2, $fleet);

        $byWorker = [];
        foreach ($fleet as $entry) {
            $byWorker[$entry['worker_id']] = $entry;
        }

        $this->assertArrayHasKey('worker-a', $byWorker);
        $this->assertArrayHasKey('worker-b', $byWorker);

        $workerA = $byWorker['worker-a'];
        $this->assertSame(['build-a'], $workerA['supported']);
        $this->assertTrue($workerA['supports_required']);
        $this->assertSame('redis', $workerA['connection']);
        $this->assertSame('default', $workerA['queue']);
        $this->assertSame('default', $workerA['namespace']);
        $this->assertArrayHasKey('recorded_at', $workerA);
        $this->assertArrayHasKey('expires_at', $workerA);
        $this->assertArrayHasKey('source', $workerA);
        $this->assertArrayHasKey('host', $workerA);
        $this->assertArrayHasKey('process_id', $workerA);

        $workerB = $byWorker['worker-b'];
        $this->assertSame(['build-b'], $workerB['supported']);
        $this->assertFalse($workerB['supports_required']);
        $this->assertSame('imports', $workerB['queue']);

        $this->assertSame(2, $response->json('operator_metrics.workers.active_workers'));
        $this->assertSame(2, $response->json('operator_metrics.workers.active_worker_scopes'));
        $this->assertSame(1, $response->json('operator_metrics.workers.active_workers_supporting_required'));
        $this->assertSame('build-a', $response->json('operator_metrics.workers.required_compatibility'));
    }

    public function test_operator_metrics_scopes_to_namespace_header(): void
    {
        $response = $this->getJson(
            '/api/system/operator-metrics',
            $this->controlPlaneHeadersWithWorkerProtocol('other'),
        );

        $response->assertOk()
            ->assertJsonPath('namespace', 'other')
            ->assertJsonPath('operator_metrics.runs.total', 0);
    }

    public function test_operator_metrics_requires_control_plane_version_header(): void
    {
        $this->getJson('/api/system/operator-metrics', [
            'X-Namespace' => 'default',
        ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'missing_control_plane_version');
    }
}
