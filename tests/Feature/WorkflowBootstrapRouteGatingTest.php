<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class WorkflowBootstrapRouteGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [
                'worker' => 'worker-token',
                'operator' => 'operator-token',
                'admin' => 'admin-token',
            ],
            'server.auth.backward_compatible' => true,
        ]);

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_days' => 30,
                'status' => 'active',
            ],
        );
    }

    public function test_pending_rollout_safety_migrations_block_control_plane_routes(): void
    {
        $this->blockWorkflowBootstrap();

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/workflows')
            ->assertStatus(503)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('reason', 'workflow_v2_blocked')
            ->assertJsonPath('blocked_by.0', 'migrations')
            ->assertJsonPath(
                'remediation',
                'Restore database connectivity and run server-bootstrap to migrate workflow and configured queue storage before serving workflow v2 traffic.',
            );
    }

    public function test_pending_rollout_safety_migrations_block_worker_protocol_routes(): void
    {
        $this->blockWorkflowBootstrap();

        $this->withHeaders($this->workerHeaders('worker-token'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'bootstrap-blocked-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'build_id' => 'build-a',
            ])
            ->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'workflow_v2_blocked')
            ->assertJsonPath('blocked_by.0', 'migrations');
    }

    public function test_missing_database_queue_storage_blocks_runtime_routes(): void
    {
        Schema::drop('jobs');

        $this->withHeaders($this->workerHeaders('worker-token'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'queue-bootstrap-blocked-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'build_id' => 'build-a',
            ])
            ->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'workflow_v2_blocked')
            ->assertJsonPath('blocked_by.0', 'queue')
            ->assertJsonPath(
                'remediation',
                'Restore database connectivity and run server-bootstrap to migrate workflow and configured queue storage before serving workflow v2 traffic.',
            );
    }

    public function test_bootstrap_gate_runs_before_namespace_resolution_on_hosted_routes(): void
    {
        $this->blockWorkflowBootstrap();

        $this->withHeaders($this->controlHeaders('operator-token', 'ghost-namespace'))
            ->getJson('/api/workflows')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'workflow_v2_blocked')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);
    }

    public function test_pending_rollout_safety_migrations_block_schedule_mutation_routes(): void
    {
        $this->createSchedule('bootstrap-blocked-schedule');
        $this->blockWorkflowBootstrap();

        foreach ($this->blockedScheduleMutations() as [$method, $path, $body]) {
            $response = $this->sendControlPlaneJson($method, $path, $body);

            $response->assertStatus(503)
                ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
                ->assertJsonPath('reason', 'workflow_v2_blocked')
                ->assertJsonPath('blocked_by.0', 'migrations');
        }
    }

    public function test_schedule_read_routes_remain_available_when_bootstrap_is_blocked(): void
    {
        $this->createSchedule('bootstrap-readable-schedule');
        $this->blockWorkflowBootstrap();

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonPath('schedules.0.schedule_id', 'bootstrap-readable-schedule');

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/schedules/bootstrap-readable-schedule')
            ->assertOk()
            ->assertJsonPath('schedule_id', 'bootstrap-readable-schedule');
    }

    public function test_bootstrap_gate_runs_before_namespace_resolution_on_schedule_mutation_routes(): void
    {
        $this->blockWorkflowBootstrap();

        $response = $this->sendControlPlaneJson('POST', '/api/schedules', [
            'schedule_id' => 'ghost-namespace-schedule',
            'spec' => ['cron_expressions' => ['0 * * * *']],
            'action' => ['workflow_type' => 'GhostNamespaceWorkflow'],
        ], 'ghost-namespace');

        $response->assertStatus(503)
            ->assertJsonPath('reason', 'workflow_v2_blocked')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);
    }

    public function test_worker_registration_can_recover_fail_mode_compatibility_health(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);
        config()->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::clear();

        $this->withHeaders($this->workerHeaders('worker-token'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'build-a-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
                'build_id' => 'build-a',
            ])
            ->assertCreated()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('worker_id', 'build-a-worker')
            ->assertJsonPath('registered', true);

        WorkerCompatibilityFleet::clear();
    }

    private function blockWorkflowBootstrap(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations')
            ->delete();
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function blockedScheduleMutations(): array
    {
        return [
            [
                'POST',
                '/api/schedules',
                [
                    'schedule_id' => 'bootstrap-created-schedule',
                    'spec' => ['cron_expressions' => ['0 * * * *']],
                    'action' => ['workflow_type' => 'BootstrapWorkflow'],
                ],
            ],
            [
                'PUT',
                '/api/schedules/bootstrap-blocked-schedule',
                ['note' => 'Blocked update'],
            ],
            [
                'DELETE',
                '/api/schedules/bootstrap-blocked-schedule',
                [],
            ],
            [
                'POST',
                '/api/schedules/bootstrap-blocked-schedule/pause',
                ['note' => 'Blocked pause'],
            ],
            [
                'POST',
                '/api/schedules/bootstrap-blocked-schedule/resume',
                ['note' => 'Blocked resume'],
            ],
            [
                'POST',
                '/api/schedules/bootstrap-blocked-schedule/trigger',
                [],
            ],
            [
                'POST',
                '/api/schedules/bootstrap-blocked-schedule/backfill',
                [
                    'start_time' => '2026-04-18T10:00:00Z',
                    'end_time' => '2026-04-18T11:00:00Z',
                ],
            ],
        ];
    }

    private function createSchedule(string $scheduleId): WorkflowSchedule
    {
        return WorkflowSchedule::query()->create([
            'schedule_id' => $scheduleId,
            'namespace' => 'default',
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'BootstrapWorkflow'],
            'overlap_policy' => 'skip',
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function workerHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function controlHeaders(string $token, string $namespace = 'default'): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Namespace' => $namespace,
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sendControlPlaneJson(
        string $method,
        string $path,
        array $body = [],
        string $namespace = 'default',
    ): TestResponse {
        return $this->json($method, $path, $body, $this->controlHeaders('operator-token', $namespace));
    }
}
