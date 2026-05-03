<?php

namespace Tests\Feature;

use App\Support\AuthCompositionContract;
use App\Support\ClientCompatibility;
use App\Support\ControlPlaneProtocol;
use App\Support\ControlPlaneRequestContract;
use App\Support\CoordinationHealthContract;
use App\Support\ServerTopology;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\PlatformProtocolSpecs;
use Workflow\V2\Support\SurfaceStabilityContract;
use Workflow\V2\Support\WorkerProtocolVersion;

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
                    'bridge_adapter_outcome_contract',
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
                'topology' => [
                    'schema',
                    'version',
                    'supported_shapes',
                    'role_vocabulary',
                    'current_shape',
                    'current_process_class',
                    'current_roles',
                    'execution_mode',
                    'matching_role' => [
                        'queue_wake_enabled',
                        'shape',
                        'wake_owner',
                        'task_dispatch_mode',
                        'partition_primitives',
                        'backpressure_model',
                    ],
                    'role_catalog',
                    'shape_assignments',
                    'authority_boundaries',
                    'authority_surfaces',
                    'failure_domains',
                    'scaling_boundaries',
                    'supported_topologies',
                    'migration_path',
                ],
                'coordination_health' => [
                    'schema',
                    'version',
                    'namespace_scope',
                    'status',
                    'http_status',
                    'generated_at',
                    'categories',
                    'warning_checks',
                    'error_checks',
                    'checks',
                    'routing_drains' => [
                        'queues_with_drains',
                        'draining_build_id_count',
                        'active_worker_count',
                        'draining_worker_count',
                        'stale_worker_count',
                        'queues',
                    ],
                ],
                'client_compatibility',
                'surface_stability_contract' => [
                    'schema',
                    'version',
                    'authority_url',
                    'stability_levels',
                    'release_rules',
                    'field_visibility_rule',
                    'surface_families',
                    'release_check',
                ],
                'platform_protocol_specs' => [
                    'schema',
                    'version',
                    'authority_url',
                    'formats',
                    'owner_repos',
                    'status_levels',
                    'evolution_rules',
                    'specs',
                    'release_check',
                ],
                'platform_conformance_suite' => [
                    'schema',
                    'version',
                    'authority_doc',
                    'surface_stability_authority',
                    'result_schema',
                    'result_version',
                    'conformance_levels',
                    'targets',
                    'fixture_catalog',
                    'pass_fail_rules',
                    'harness_contract',
                    'release_gates',
                ],
                'auth_composition_contract',
                'control_plane',
                'worker_protocol',
                'bridge_adapter_outcome_contract',
            ])
            ->assertJsonPath('topology.schema', ServerTopology::SCHEMA)
            ->assertJsonPath('topology.version', ServerTopology::VERSION)
            ->assertJsonPath('topology.matching_role.shape', 'in_worker')
            ->assertJsonPath('topology.matching_role.task_dispatch_mode', 'poll')
            ->assertJsonPath('topology.matching_role.partition_primitives.2', 'compatibility')
            ->assertJsonPath('topology.matching_role.backpressure_model', 'lease_ownership')
            ->assertJsonPath('coordination_health.schema', CoordinationHealthContract::SCHEMA)
            ->assertJsonPath('coordination_health.version', CoordinationHealthContract::VERSION)
            ->assertJsonPath('coordination_health.namespace_scope', 'all_namespaces')
            ->assertJsonPath(
                'topology.failure_domains.matching_down.effect',
                'claim_falls_back_to_direct_ready_task_discovery',
            )
            ->assertJsonPath('control_plane.version', ControlPlaneProtocol::VERSION)
            ->assertJsonPath('worker_protocol.version', WorkerProtocol::VERSION)
            ->assertJsonPath('client_compatibility.authority', 'protocol_manifests')
            ->assertJsonPath('surface_stability_contract.schema', SurfaceStabilityContract::SCHEMA)
            ->assertJsonPath('surface_stability_contract.version', SurfaceStabilityContract::VERSION)
            ->assertJsonPath(
                'surface_stability_contract.authority_url',
                SurfaceStabilityContract::AUTHORITY_URL,
            )
            ->assertJsonPath('platform_protocol_specs.schema', PlatformProtocolSpecs::SCHEMA)
            ->assertJsonPath('platform_protocol_specs.version', PlatformProtocolSpecs::VERSION)
            ->assertJsonPath(
                'platform_protocol_specs.authority_url',
                PlatformProtocolSpecs::AUTHORITY_URL,
            )
            ->assertJsonPath('platform_conformance_suite.schema', PlatformConformanceSuite::SCHEMA)
            ->assertJsonPath('platform_conformance_suite.version', PlatformConformanceSuite::VERSION)
            ->assertJsonPath(
                'platform_conformance_suite.surface_stability_authority',
                SurfaceStabilityContract::SCHEMA,
            );
    }

    public function test_cluster_info_publishes_the_canonical_surface_stability_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();

        $this->assertSame(
            SurfaceStabilityContract::manifest(),
            $response->json('surface_stability_contract'),
            'cluster info must re-export the workflow package surface-stability manifest verbatim',
        );

        $families = $response->json('surface_stability_contract.surface_families');
        $this->assertIsArray($families);
        foreach ([
            'server_api',
            'worker_protocol',
            'cli_json',
            'waterline_api',
            'mcp_discovery_results',
            'official_sdks',
            'history_event_wire_formats',
            'cluster_info_manifests',
        ] as $expectedFamily) {
            $this->assertArrayHasKey(
                $expectedFamily,
                $families,
                "surface_stability_contract.surface_families must include $expectedFamily",
            );
            $this->assertContains(
                $families[$expectedFamily]['stability_level'],
                SurfaceStabilityContract::stabilityLevelValues(),
                "$expectedFamily stability_level must be one of " .
                    implode(', ', SurfaceStabilityContract::stabilityLevelValues()),
            );
        }

        $this->assertSame(
            'frozen',
            $families['history_event_wire_formats']['stability_level'],
            'history-event wire formats are frozen for the workflow lifetime',
        );
    }

    public function test_cluster_info_publishes_the_canonical_platform_protocol_specs_catalog(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();

        $this->assertSame(
            PlatformProtocolSpecs::manifest(),
            $response->json('platform_protocol_specs'),
            'cluster info must re-export the workflow package platform-protocol-specs catalog verbatim',
        );

        $specs = $response->json('platform_protocol_specs.specs');
        $this->assertIsArray($specs);

        $expectedDeliverableSpecs = [
            'control_plane_api',
            'worker_protocol_api',
            'worker_protocol_stream',
            'history_event_payloads',
            'history_export_bundle',
            'replay_bundle',
            'waterline_read_api',
            'waterline_diagnostic_objects',
            'repair_actionability_objects',
            'mcp_discovery',
            'mcp_tool_results',
            'cluster_info_envelope',
        ];
        foreach ($expectedDeliverableSpecs as $expectedSpec) {
            $this->assertArrayHasKey(
                $expectedSpec,
                $specs,
                "platform_protocol_specs.specs must include $expectedSpec to cover the deliverable surface set",
            );
            $this->assertContains(
                $specs[$expectedSpec]['format'],
                PlatformProtocolSpecs::formatValues(),
                "$expectedSpec format must be one of " . implode(', ', PlatformProtocolSpecs::formatValues()),
            );
            $this->assertContains(
                $specs[$expectedSpec]['owner_repo'],
                PlatformProtocolSpecs::ownerRepoValues(),
                "$expectedSpec owner_repo must be one of " . implode(', ', PlatformProtocolSpecs::ownerRepoValues()),
            );
            $this->assertContains(
                $specs[$expectedSpec]['status'],
                PlatformProtocolSpecs::statusValues(),
                "$expectedSpec status must be one of " . implode(', ', PlatformProtocolSpecs::statusValues()),
            );
        }

        $surfaceFamilies = $response->json('surface_stability_contract.surface_families');
        $this->assertIsArray($surfaceFamilies);
        foreach ($specs as $name => $spec) {
            $this->assertArrayHasKey(
                $spec['surface_family'],
                $surfaceFamilies,
                "platform_protocol_specs entry $name references unknown surface_family {$spec['surface_family']}",
            );
        }
    }

    public function test_cluster_info_publishes_the_canonical_platform_conformance_suite(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();

        $this->assertSame(
            PlatformConformanceSuite::manifest(),
            $response->json('platform_conformance_suite'),
            'cluster info must re-export the workflow package platform-conformance-suite manifest verbatim',
        );

        $manifest = $response->json('platform_conformance_suite');
        $this->assertIsArray($manifest);

        $expectedTargets = [
            'standalone_server',
            'official_sdk',
            'worker_protocol_implementation',
            'cli_json_client',
            'waterline_contract_surface',
            'repair_actionability_surface',
            'mcp_discovery_surface',
        ];
        foreach ($expectedTargets as $target) {
            $this->assertArrayHasKey(
                $target,
                $manifest['targets'],
                "platform_conformance_suite.targets must include $target",
            );
        }

        $surfaceFamilies = $response->json('surface_stability_contract.surface_families');
        $this->assertIsArray($surfaceFamilies);
        foreach ($manifest['targets'] as $name => $target) {
            foreach ($target['required_surface_families'] as $family) {
                $this->assertArrayHasKey(
                    $family,
                    $surfaceFamilies,
                    "platform_conformance_suite target $name references unknown surface_family $family",
                );
            }
            foreach ($target['required_fixture_categories'] as $category) {
                $this->assertArrayHasKey(
                    $category,
                    $manifest['fixture_catalog'],
                    "platform_conformance_suite target $name references unknown fixture category $category",
                );
            }
        }

        $this->assertContains(
            PlatformConformanceSuite::CONFORMANCE_LEVEL_NONCONFORMING,
            $manifest['conformance_levels'],
            'the conformance level set must include `nonconforming` so the harness exit code is meaningful',
        );

        $this->assertTrue(
            $manifest['release_gates']['enforcement']['block_on_nonconforming'],
            'a nonconforming harness result must block first-party releases',
        );

        $this->assertArrayHasKey(
            'durable-workflow/server',
            $manifest['release_gates']['gates'],
            'the standalone server must be enumerated in the release gate set',
        );
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
            ->assertJsonPath(
                'client_compatibility.required_protocols.auth_composition.schema',
                AuthCompositionContract::SCHEMA,
            )
            ->assertJsonPath(
                'client_compatibility.required_protocols.auth_composition.version',
                AuthCompositionContract::VERSION,
            )
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
            ->assertJsonPath(
                'client_compatibility.required_protocols.worker_protocol.external_execution_surface_contract.version',
                1,
            )
            ->assertJsonPath(
                'client_compatibility.required_protocols.worker_protocol.external_task_result_contract.version',
                1,
            )
            ->assertJsonPath('client_compatibility.clients.cli.supported_versions', '>=0.1,<1.0')
            ->assertJsonPath('client_compatibility.clients.sdk-python.supported_versions', '>=0.2,<1.0');

        $this->assertSame(
            $response->json('supported_sdk_versions.cli'),
            $response->json('client_compatibility.clients.cli.supported_versions'),
        );
        $this->assertSame(
            $response->json('supported_sdk_versions.python'),
            $response->json('client_compatibility.clients.sdk-python.supported_versions'),
        );

        $this->assertContains(
            'auth_composition.version',
            $response->json('client_compatibility.clients.cli.requires'),
        );
        $this->assertContains(
            'auth_composition.version',
            $response->json('client_compatibility.clients.sdk-python.requires'),
        );
    }

    public function test_worker_protocol_manifest_is_sourced_from_the_package_contract(): void
    {
        $expectedCommands = array_values(array_merge(
            WorkerProtocolVersion::terminalCommandTypes(),
            WorkerProtocolVersion::nonTerminalCommandTypes(),
        ));

        $response = $this->getJson('/api/cluster/info')->assertOk();

        $this->assertSame(WorkerProtocolVersion::VERSION, WorkerProtocol::VERSION);
        $this->assertSame($expectedCommands, WorkerProtocol::supportedWorkflowTaskCommands());
        $this->assertSame(WorkerProtocolVersion::VERSION, $response->json('worker_protocol.version'));
        $this->assertSame(
            $expectedCommands,
            $response->json('worker_protocol.server_capabilities.supported_workflow_task_commands'),
        );
        $this->assertSame(
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            $response->json('worker_protocol.server_capabilities.history_page_size_default'),
        );
        $this->assertSame(
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            $response->json('worker_protocol.server_capabilities.history_page_size_max'),
        );
        $this->assertTrue($response->json('worker_protocol.server_capabilities.query_tasks'));
        $this->assertSame(
            WorkerProtocolVersion::supportedHistoryEncodings(),
            $response->json('worker_protocol.server_capabilities.history_compression.supported_encodings'),
        );
    }
}
