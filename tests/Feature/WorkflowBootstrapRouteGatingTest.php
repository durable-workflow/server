<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
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
                'Restore database connectivity and migrate the workflow tables before relying on workflow v2 rollout-safety health.',
            );
    }

    public function test_pending_rollout_safety_migrations_block_worker_protocol_routes(): void
    {
        $this->blockWorkflowBootstrap();

        $this->withHeaders($this->workerHeaders('worker-token'))
            ->postJson('/api/worker/register', [
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

    public function test_bootstrap_gate_runs_before_namespace_resolution_on_hosted_routes(): void
    {
        $this->blockWorkflowBootstrap();

        $this->withHeaders($this->controlHeaders('operator-token', 'ghost-namespace'))
            ->getJson('/api/workflows')
            ->assertStatus(503)
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
        \Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration', '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations')
            ->delete();
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
}
