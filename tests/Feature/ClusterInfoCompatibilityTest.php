<?php

namespace Tests\Feature;

use App\Support\ClientCompatibility;
use App\Support\ControlPlaneProtocol;
use App\Support\ControlPlaneRequestContract;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClusterInfoCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cluster_info_is_a_versionless_protocol_discovery_contract(): void
    {
        $response = $this->getJson('/api/cluster/info', [
            'X-Namespace' => 'default',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ]);

        $response->assertOk()
            ->assertHeaderMissing(ControlPlaneProtocol::HEADER)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonStructure([
                'server_id',
                'version',
                'default_namespace',
                'supported_sdk_versions' => [
                    'php',
                    'python',
                    'cli',
                ],
                'capabilities' => [
                    'workflow_tasks',
                    'activity_tasks',
                    'signals',
                    'queries',
                    'updates',
                    'schedules',
                    'history_export',
                    'payload_codec_envelope',
                    'payload_codec_envelope_responses',
                    'payload_codecs',
                    'response_compression',
                ],
                'worker_fleet' => [
                    'namespace',
                    'active_workers',
                    'active_worker_scopes',
                    'queues',
                    'build_ids',
                    'workers',
                ],
                'task_repair' => [
                    'policy',
                    'candidates',
                ],
                'limits' => [
                    'max_payload_bytes',
                    'max_memo_bytes',
                    'max_search_attributes',
                    'max_pending_activities',
                    'max_pending_children',
                ],
                'structural_limits',
                'client_compatibility',
                'control_plane',
                'worker_protocol',
            ])
            ->assertJsonPath('control_plane.version', ControlPlaneProtocol::VERSION)
            ->assertJsonPath('worker_protocol.version', WorkerProtocol::VERSION)
            ->assertJsonPath('client_compatibility.authority', 'protocol_manifests');
    }

    public function test_cluster_info_names_protocol_manifests_as_client_compatibility_authority(): void
    {
        $response = $this->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('client_compatibility.schema', ClientCompatibility::SCHEMA)
            ->assertJsonPath('client_compatibility.version', ClientCompatibility::VERSION)
            ->assertJsonPath('client_compatibility.authority', 'protocol_manifests')
            ->assertJsonPath('client_compatibility.top_level_version_role', 'informational')
            ->assertJsonPath('client_compatibility.fail_closed', true)
            ->assertJsonPath('client_compatibility.required_protocols.control_plane.version', ControlPlaneProtocol::VERSION)
            ->assertJsonPath('client_compatibility.required_protocols.control_plane.header', ControlPlaneProtocol::HEADER)
            ->assertJsonPath(
                'client_compatibility.required_protocols.control_plane.request_contract.schema',
                ControlPlaneRequestContract::SCHEMA,
            )
            ->assertJsonPath(
                'client_compatibility.required_protocols.control_plane.request_contract.version',
                ControlPlaneRequestContract::VERSION,
            )
            ->assertJsonPath('client_compatibility.required_protocols.worker_protocol.version', WorkerProtocol::VERSION)
            ->assertJsonPath('client_compatibility.required_protocols.worker_protocol.header', WorkerProtocol::HEADER)
            ->assertJsonPath('client_compatibility.clients.cli.supported_versions', '0.1.x')
            ->assertJsonPath('client_compatibility.clients.sdk-python.supported_versions', '0.2.x');
    }
}
