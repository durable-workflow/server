<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopologyRouteGatingTest extends TestCase
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

    public function test_scheduler_node_rejects_control_plane_routes_with_a_machine_readable_topology_reason(): void
    {
        config([
            'server.topology.shape' => 'standalone_server',
            'server.topology.process_class' => 'scheduler_node',
        ]);

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/workflows')
            ->assertStatus(503)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('reason', 'topology_role_unavailable')
            ->assertJsonPath('current_shape', 'standalone_server')
            ->assertJsonPath('current_process_class', 'scheduler_node')
            ->assertJsonPath('current_roles.0', 'scheduler')
            ->assertJsonPath('required_roles.0', 'api_ingress')
            ->assertJsonPath('required_roles.1', 'control_plane')
            ->assertJsonPath('missing_roles.0', 'api_ingress')
            ->assertJsonPath('missing_roles.1', 'control_plane');
    }

    public function test_execution_node_rejects_worker_protocol_routes_with_a_machine_readable_topology_reason(): void
    {
        config([
            'server.topology.shape' => 'split_control_execution',
            'server.topology.process_class' => 'execution_node',
        ]);

        $this->withHeaders($this->workerHeaders('worker-token'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'execution-node-register',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertStatus(503)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'topology_role_unavailable')
            ->assertJsonPath('current_shape', 'split_control_execution')
            ->assertJsonPath('current_process_class', 'execution_node')
            ->assertJsonPath('current_roles.0', 'execution_plane')
            ->assertJsonPath('required_roles.0', 'api_ingress')
            ->assertJsonPath('required_roles.1', 'control_plane');
    }

    public function test_control_plane_node_keeps_the_current_http_control_and_worker_surfaces(): void
    {
        config([
            'server.topology.shape' => 'split_control_execution',
            'server.topology.process_class' => 'control_plane_node',
        ]);

        $this->withHeaders($this->controlHeaders('operator-token'))
            ->getJson('/api/workflows')
            ->assertOk();

        $this->withHeaders($this->workerHeaders('worker-token'))
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'split-control-node-worker',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertCreated();
    }

    public function test_topology_gate_runs_before_namespace_resolution_on_hosted_routes(): void
    {
        config([
            'server.topology.shape' => 'standalone_server',
            'server.topology.process_class' => 'scheduler_node',
        ]);

        $this->withHeaders($this->controlHeaders('operator-token', 'ghost-namespace'))
            ->getJson('/api/workflows')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'topology_role_unavailable')
            ->assertJsonMissing(['reason' => 'namespace_not_found']);
    }

    public function test_protocol_version_validation_still_runs_before_topology_gating(): void
    {
        config([
            'server.topology.shape' => 'split_control_execution',
            'server.topology.process_class' => 'execution_node',
        ]);

        $headers = [
            'Authorization' => 'Bearer worker-token',
            'X-Namespace' => 'default',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/worker/register', [
                'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
                'worker_id' => 'missing-version',
                'task_queue' => 'default',
                'runtime' => 'python',
            ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'missing_protocol_version')
            ->assertJsonMissing(['reason' => 'topology_role_unavailable']);
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
