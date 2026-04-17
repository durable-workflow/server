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
