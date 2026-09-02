<?php

namespace Tests\Feature;

use App\Support\AuthCompositionContract;
use App\Support\ChildWorkflowRuntimeContract;
use App\Support\ClientCompatibility;
use App\Support\ControlPlaneProtocol;
use App\Support\ControlPlaneRequestContract;
use App\Support\CoordinationHealthContract;
use App\Support\HeartbeatRuntimeContract;
use App\Support\MigrationRuntimeContract;
use App\Support\NamespaceRuntimeContract;
use App\Support\PayloadCodecContract;
use App\Support\PrereleaseReadinessContract;
use App\Support\PrereleaseReadinessResultGate;
use App\Support\PrincipalAttributionContract;
use App\Support\PrincipalAttributionResultGate;
use App\Support\SagaRuntimeContract;
use App\Support\SearchAttributeRuntimeContract;
use App\Support\ServerTopology;
use App\Support\SignalQueryRuntimeContract;
use App\Support\SkewRefusalMatrixContract;
use App\Support\TimerRuntimeContract;
use App\Support\WorkerProtocol;
use App\Support\WorkerVersioningRuntimeContract;
use App\Support\WorkflowLifecycleContract;
use App\Support\WorkflowUpdateRuntimeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\PlatformProtocolSpecs;
use Workflow\V2\Support\SdkNeutralityContract;
use Workflow\V2\Support\SurfaceStabilityContract;
use Workflow\V2\Support\WorkerHistoryPayloadContract;
use Workflow\V2\Support\WorkerProtocolVersion;

class ClusterInfoCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_cluster_info_keeps_compatibility_preflight_bounded(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('discovery.mode', 'compatibility')
            ->assertJsonPath('discovery.diagnostics_query', 'include=diagnostics')
            ->assertJsonPath('discovery.diagnostics_path', '/api/cluster/info?include=diagnostics')
            ->assertJsonPath('discovery.operator_metrics_path', '/api/system/operator-metrics')
            ->assertJsonPath('discovery.task_repair_path', '/api/system/repair')
            ->assertJsonPath('control_plane.version', ControlPlaneProtocol::VERSION)
            ->assertJsonPath(
                'control_plane.request_contract.schema',
                ControlPlaneRequestContract::SCHEMA,
            )
            ->assertJsonPath('worker_protocol.version', WorkerProtocol::VERSION)
            ->assertJsonPath('client_compatibility.authority', 'protocol_manifests')
            ->assertJsonPath('capabilities.workflow_tasks', true)
            ->assertJsonPath('platform_protocol_specs.schema', PlatformProtocolSpecs::SCHEMA)
            ->assertJsonPath('surface_stability_contract.version', 4)
            ->assertJsonPath('activity_runtime_contract.version', 1)
            ->assertJsonPath('topology.schema', ServerTopology::SCHEMA)
            ->assertJsonMissingPath('worker_fleet')
            ->assertJsonMissingPath('operator_metrics')
            ->assertJsonMissingPath('task_repair')
            ->assertJsonMissingPath('coordination_health');
    }

    public function test_cluster_info_does_not_advertise_managed_runtime_targets(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();
        $serialized = json_encode($response->json(), JSON_THROW_ON_ERROR);

        foreach ([
            'hosted_control_plane_'.'contract',
            'runtime_target_'.'id',
            'runtime_target_'.'base_url',
            'worker_connectivity_'.'modes',
        ] as $removedSurface) {
            $response->assertJsonMissingPath($removedSurface);
            $this->assertStringNotContainsString($removedSurface, $serialized);
        }
    }

    public function test_cluster_info_is_a_versionless_protocol_discovery_contract(): void
    {
        $response = $this->getJson('/api/cluster/info?include=diagnostics', [
            'X-Namespace' => 'default',
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ]);

        $response->assertOk()
            ->assertJsonPath('discovery.mode', 'diagnostics')
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
                    'rust',
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
                    'heartbeat_runtime_contract',
                    'timer_runtime_contract',
                    'skew_refusal_matrix_contract',
                    'worker_versioning_runtime_contract',
                    'migration_runtime_contract',
                    'workflow_lifecycle_contract',
                    'prerelease_readiness_contract',
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
                    'kernel_invariants',
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
                'signal_query_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'artifact_policy',
                    'scenario_statuses',
                    'topology',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'result_gate',
                    'finding_policy',
                ],
                'search_attribute_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'artifact_policy',
                    'scenario_statuses',
                    'topology',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'result_gate',
                    'finding_policy',
                ],
                'child_workflow_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'artifact_policy',
                    'scenario_statuses',
                    'topology',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'saga_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'scenario_manifest',
                    'artifact_policy',
                    'scenario_statuses',
                    'topology',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'heartbeat_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'scenario_manifest',
                    'artifact_policy',
                    'scenario_statuses',
                    'heartbeat_contract',
                    'topology',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'timer_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'scenario_manifest',
                    'artifact_policy',
                    'scenario_statuses',
                    'timer_semantics',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'principal_attribution_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'scenario_manifest',
                    'principal_shape',
                    'worker_terminal_event_policy',
                    'artifact_policy',
                    'scenario_statuses',
                    'required_scenarios',
                    'scenario_requirements',
                    'spoofing_guards',
                    'coverage_gate',
                    'result_gate',
                    'host_runner_contract',
                    'finding_policy',
                ],
                'skew_refusal_matrix_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'artifact_policy',
                    'status_taxonomy',
                    'pairing_classes',
                    'required_surfaces',
                    'operation_groups',
                    'worker_skew_classification',
                    'waterline_skew_classification',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'worker_versioning_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'artifact_policy',
                    'scenario_statuses',
                    'topology',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'migration_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'scenario_manifest',
                    'artifact_policy',
                    'scenario_statuses',
                    'topology',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'advisory_evidence',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'workflow_lifecycle_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'scenario_manifest',
                    'artifact_policy',
                    'scenario_statuses',
                    'topology',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'prerelease_readiness_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'scenario_manifest',
                    'artifact_policy',
                    'scenario_statuses',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'namespace_runtime_contract' => [
                    'schema',
                    'version',
                    'result_schema',
                    'result_version',
                    'fixture_category',
                    'platform_conformance_suite_authority',
                    'scenario_manifest',
                    'artifact_policy',
                    'scenario_statuses',
                    'topology',
                    'required_matrix',
                    'required_scenarios',
                    'scenario_requirements',
                    'coverage_gate',
                    'host_runner_contract',
                    'result_gate',
                    'finding_policy',
                ],
                'auth_composition_contract',
                'sdk_neutrality_contract' => [
                    'schema',
                    'version',
                    'authority_doc',
                    'surface_stability_authority',
                    'protocol_specs_authority',
                    'conformance_suite_authority',
                    'scope',
                    'sdk_breadth_policy',
                    'neutrality_rules',
                    'audit_checklist',
                    'audit_scope_surface_families',
                    'release_gates',
                ],
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
            ->assertJsonPath(
                'client_compatibility.skew_refusal_matrix_contract.schema',
                SkewRefusalMatrixContract::SCHEMA,
            )
            ->assertJsonPath(
                'client_compatibility.skew_refusal_matrix_contract.cluster_info_path',
                'skew_refusal_matrix_contract',
            )
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
            )
            ->assertJsonPath('signal_query_runtime_contract.schema', SignalQueryRuntimeContract::SCHEMA)
            ->assertJsonPath('signal_query_runtime_contract.version', SignalQueryRuntimeContract::VERSION)
            ->assertJsonPath(
                'signal_query_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('search_attribute_runtime_contract.schema', SearchAttributeRuntimeContract::SCHEMA)
            ->assertJsonPath('search_attribute_runtime_contract.version', SearchAttributeRuntimeContract::VERSION)
            ->assertJsonPath(
                'search_attribute_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('child_workflow_runtime_contract.schema', ChildWorkflowRuntimeContract::SCHEMA)
            ->assertJsonPath('child_workflow_runtime_contract.version', ChildWorkflowRuntimeContract::VERSION)
            ->assertJsonPath(
                'child_workflow_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('saga_runtime_contract.schema', SagaRuntimeContract::SCHEMA)
            ->assertJsonPath('saga_runtime_contract.version', SagaRuntimeContract::VERSION)
            ->assertJsonPath(
                'saga_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('heartbeat_runtime_contract.schema', HeartbeatRuntimeContract::SCHEMA)
            ->assertJsonPath('heartbeat_runtime_contract.version', HeartbeatRuntimeContract::VERSION)
            ->assertJsonPath(
                'heartbeat_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('timer_runtime_contract.schema', TimerRuntimeContract::SCHEMA)
            ->assertJsonPath('timer_runtime_contract.version', TimerRuntimeContract::VERSION)
            ->assertJsonPath(
                'timer_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('principal_attribution_contract.schema', PrincipalAttributionContract::SCHEMA)
            ->assertJsonPath('principal_attribution_contract.version', PrincipalAttributionContract::VERSION)
            ->assertJsonPath(
                'principal_attribution_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath(
                'principal_attribution_contract.result_gate.schema',
                PrincipalAttributionResultGate::SCHEMA,
            )
            ->assertJsonPath('skew_refusal_matrix_contract.schema', SkewRefusalMatrixContract::SCHEMA)
            ->assertJsonPath('skew_refusal_matrix_contract.version', SkewRefusalMatrixContract::VERSION)
            ->assertJsonPath(
                'skew_refusal_matrix_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('worker_versioning_runtime_contract.schema', WorkerVersioningRuntimeContract::SCHEMA)
            ->assertJsonPath('worker_versioning_runtime_contract.version', WorkerVersioningRuntimeContract::VERSION)
            ->assertJsonPath(
                'worker_versioning_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('migration_runtime_contract.schema', MigrationRuntimeContract::SCHEMA)
            ->assertJsonPath('migration_runtime_contract.version', MigrationRuntimeContract::VERSION)
            ->assertJsonPath(
                'migration_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('workflow_lifecycle_contract.schema', WorkflowLifecycleContract::SCHEMA)
            ->assertJsonPath('workflow_lifecycle_contract.version', WorkflowLifecycleContract::VERSION)
            ->assertJsonPath(
                'workflow_lifecycle_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('workflow_update_runtime_contract.schema', WorkflowUpdateRuntimeContract::SCHEMA)
            ->assertJsonPath('workflow_update_runtime_contract.version', WorkflowUpdateRuntimeContract::VERSION)
            ->assertJsonPath(
                'workflow_update_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath('prerelease_readiness_contract.schema', PrereleaseReadinessContract::SCHEMA)
            ->assertJsonPath('prerelease_readiness_contract.version', PrereleaseReadinessContract::VERSION)
            ->assertJsonPath(
                'prerelease_readiness_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath(
                'prerelease_readiness_contract.result_gate.schema',
                PrereleaseReadinessResultGate::SCHEMA,
            )
            ->assertJsonPath(
                'prerelease_readiness_contract.scenario_manifest.source_path',
                'static/platform-conformance/prerelease-readiness-scenarios.json',
            )
            ->assertJsonPath('namespace_runtime_contract.schema', NamespaceRuntimeContract::SCHEMA)
            ->assertJsonPath('namespace_runtime_contract.version', NamespaceRuntimeContract::VERSION)
            ->assertJsonPath(
                'namespace_runtime_contract.platform_conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            )
            ->assertJsonPath(
                'namespace_runtime_contract.scenario_manifest.source_path',
                'static/platform-conformance/namespace-runtime-scenarios.json',
            )
            ->assertJsonPath(
                'namespace_runtime_contract.scenario_manifest.suite_version',
                PlatformConformanceSuite::VERSION,
            )
            ->assertJsonPath('sdk_neutrality_contract.schema', SdkNeutralityContract::SCHEMA)
            ->assertJsonPath('sdk_neutrality_contract.version', SdkNeutralityContract::VERSION)
            ->assertJsonPath(
                'sdk_neutrality_contract.surface_stability_authority',
                SurfaceStabilityContract::SCHEMA,
            )
            ->assertJsonPath(
                'sdk_neutrality_contract.protocol_specs_authority',
                PlatformProtocolSpecs::SCHEMA,
            )
            ->assertJsonPath(
                'sdk_neutrality_contract.conformance_suite_authority',
                PlatformConformanceSuite::SCHEMA,
            );
    }

    public function test_cluster_info_publishes_the_canonical_sdk_neutrality_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();

        $this->assertSame(
            SdkNeutralityContract::manifest(),
            $response->json('sdk_neutrality_contract'),
            'cluster info must re-export the workflow package SDK neutrality manifest verbatim',
        );

        $rules = $response->json('sdk_neutrality_contract.neutrality_rules');
        $this->assertIsArray($rules);
        foreach ([
            'protocol_neutrality',
            'codec_neutrality',
            'error_shape_neutrality',
            'type_identity_neutrality',
            'replay_fixture_neutrality',
            'discovery_neutrality',
            'documentation_neutrality',
        ] as $expectedRule) {
            $this->assertArrayHasKey(
                $expectedRule,
                $rules,
                "sdk_neutrality_contract.neutrality_rules must include $expectedRule",
            );
        }

        $policy = $response->json('sdk_neutrality_contract.sdk_breadth_policy');
        $this->assertSame(
            SdkNeutralityContract::POSTURE_PRIORITY,
            $policy['first_party']['php_sdk']['posture'],
        );
        $this->assertSame(
            SdkNeutralityContract::POSTURE_PRIORITY,
            $policy['first_party']['python_sdk']['posture'],
            'python SDK is the highest-value non-PHP first-party priority',
        );
        foreach (['typescript_sdk', 'go_sdk', 'java_sdk', 'dotnet_sdk'] as $demandDriven) {
            $this->assertArrayHasKey(
                $demandDriven,
                $policy['demand_driven'],
                "sdk_breadth_policy.demand_driven must include $demandDriven",
            );
            $this->assertSame(
                SdkNeutralityContract::POSTURE_DEMAND_DRIVEN,
                $policy['demand_driven'][$demandDriven]['posture'],
            );
        }
    }

    public function test_cluster_info_publishes_the_canonical_surface_stability_contract(): void
    {
        $response = $this->getJson('/api/cluster/info')->assertOk();

        $response
            ->assertJsonPath('surface_stability_contract.version', 4)
            ->assertJsonPath(
                'surface_stability_contract.surface_families.official_sdks.package_compatibility.rust_sdk.package',
                'durable-workflow',
            )
            ->assertJsonPath(
                'surface_stability_contract.surface_families.official_sdks.package_compatibility.rust_sdk.worker_protocol_version',
                '1.2',
            )
            ->assertJsonPath(
                'surface_stability_contract.surface_families.worker_protocol.negotiation.default_advertised_version',
                WorkerProtocol::VERSION,
            )
            ->assertJsonPath(
                'surface_stability_contract.surface_families.worker_protocol.negotiation.request_header_rule',
                'same_major_and_minor_less_than_or_equal_to_advertised',
            );

        $acceptedVersions = $response->json(
            'surface_stability_contract.surface_families.worker_protocol.negotiation.accepted_request_versions_by_default',
        );
        $this->assertIsArray($acceptedVersions);
        $this->assertContains('1.2', $acceptedVersions);
        $this->assertContains(WorkerProtocol::VERSION, $acceptedVersions);

        $expectedContract = SurfaceStabilityContract::manifest();
        $expectedContract['surface_families']['worker_protocol']['negotiation'] = WorkerProtocol::negotiation();
        $this->assertSame($expectedContract, $response->json('surface_stability_contract'));

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
                "$expectedFamily stability_level must be one of ".
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
        $response
            ->assertJsonPath('platform_protocol_specs.schema', PlatformProtocolSpecs::SCHEMA)
            ->assertJsonPath('platform_protocol_specs.version', PlatformProtocolSpecs::VERSION)
            ->assertJsonPath('platform_protocol_specs.catalog_url', PlatformProtocolSpecs::CATALOG_URL)
            ->assertJsonPath('platform_protocol_specs.authority_url', PlatformProtocolSpecs::AUTHORITY_URL);

        $specs = $response->json('platform_protocol_specs.specs');
        $this->assertIsArray($specs);

        $expectedDeliverableSpecs = [
            'control_plane_api',
            'worker_protocol_api',
            'worker_protocol_stream',
            'worker_sessions_runtime',
            'local_activity_runtime',
            'history_event_payloads',
            'history_export_bundle',
            'replay_bundle',
            'waterline_read_api',
            'waterline_diagnostic_objects',
            'repair_actionability_objects',
            'cli_json_envelopes',
            'mcp_discovery',
            'mcp_tool_results',
            'cluster_info_envelope',
            'invocable_carrier_execution',
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
                "$expectedSpec format must be one of ".implode(', ', PlatformProtocolSpecs::formatValues()),
            );
            $this->assertContains(
                $specs[$expectedSpec]['owner_repo'],
                PlatformProtocolSpecs::ownerRepoValues(),
                "$expectedSpec owner_repo must be one of ".implode(', ', PlatformProtocolSpecs::ownerRepoValues()),
            );
            $this->assertContains(
                $specs[$expectedSpec]['status'],
                PlatformProtocolSpecs::statusValues(),
                "$expectedSpec status must be one of ".implode(', ', PlatformProtocolSpecs::statusValues()),
            );
            $this->assertStringStartsWith(
                'https://durable-workflow.github.io/platform-protocol-specs/',
                $specs[$expectedSpec]['spec_url'],
                "$expectedSpec must expose a direct public specification URL",
            );
        }

        $catalogJson = json_encode($response->json('platform_protocol_specs'), JSON_THROW_ON_ERROR);
        foreach ([
            '"spec_path"',
            '"owner_symbol"',
            '"implementation_symbol"',
            '"conformance_test"',
            '"conformance_path"',
            '"schema_authority"',
            '"version_authority"',
            'docs/',
            'tests/',
            'scripts/',
            'static/',
            '::',
            '\\\\',
        ] as $repositoryLocalReference) {
            $this->assertStringNotContainsString(
                $repositoryLocalReference,
                $catalogJson,
                "cluster info protocol catalog must not expose {$repositoryLocalReference}",
            );
        }

        $expectedBreakingChangeReleaseByRule = [
            'additive_minor_breaking_major' => 'major',
            'parallel_primitive_only' => 'parallel_primitive_only',
            'experimental_any_release' => 'experimental_any_release',
        ];
        $surfaceFamilies = $response->json('surface_stability_contract.surface_families');
        $this->assertIsArray($surfaceFamilies);
        foreach ($specs as $name => $spec) {
            $this->assertArrayHasKey(
                $spec['surface_family'],
                $surfaceFamilies,
                "platform_protocol_specs entry $name references unknown surface_family {$spec['surface_family']}",
            );
            $this->assertArrayHasKey(
                $spec['evolution_rule'],
                $expectedBreakingChangeReleaseByRule,
                "platform_protocol_specs entry $name uses an unknown evolution_rule",
            );
            $this->assertSame(
                $expectedBreakingChangeReleaseByRule[$spec['evolution_rule']],
                $spec['breaking_change_release'],
                "platform_protocol_specs entry $name breaking_change_release must match its evolution_rule",
            );
            $this->assertIsArray(
                $spec['object_families'] ?? null,
                "platform_protocol_specs entry $name must declare object_families",
            );
            $this->assertNotSame(
                [],
                $spec['object_families'],
                "platform_protocol_specs entry $name must declare at least one object family",
            );

            $seenFamilies = [];
            foreach ($spec['object_families'] as $family) {
                $this->assertIsArray(
                    $family,
                    "platform_protocol_specs entry $name object family must be an object",
                );
                foreach (['name', 'owner_repo'] as $field) {
                    $this->assertArrayHasKey(
                        $field,
                        $family,
                        "platform_protocol_specs entry $name object family must declare $field",
                    );
                    $this->assertIsString(
                        $family[$field],
                        "platform_protocol_specs entry $name object family $field must be a string",
                    );
                    $this->assertNotSame(
                        '',
                        $family[$field],
                        "platform_protocol_specs entry $name object family $field must be non-empty",
                    );
                }
                $this->assertContains(
                    $family['owner_repo'],
                    PlatformProtocolSpecs::ownerRepoValues(),
                    "platform_protocol_specs entry $name object family {$family['name']} owner_repo must be known",
                );
                $this->assertNotContains(
                    $family['name'],
                    $seenFamilies,
                    "platform_protocol_specs entry $name object family {$family['name']} must not be duplicated",
                );
                $seenFamilies[] = $family['name'];
            }
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
                'client_compatibility.skew_refusal_matrix_contract.version',
                SkewRefusalMatrixContract::VERSION,
            )
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
            ->assertJsonPath('client_compatibility.clients.cli.supported_versions', '>=2.0.0,<3.0.0')
            ->assertJsonPath('client_compatibility.clients.sdk-php.supported_versions', '>=2.0.0,<3.0.0')
            ->assertJsonPath('client_compatibility.clients.sdk-python.supported_versions', '>=2.0.0,<3.0.0')
            ->assertJsonPath('client_compatibility.clients.sdk-rust.supported_versions', '>=2.0.0,<3.0.0');

        foreach ($response->json('supported_sdk_versions') as $supportedVersions) {
            $this->assertStringNotContainsString(
                '-rc.',
                $supportedVersions,
                'stable Server compatibility must not retain prerelease package ranges',
            );
        }

        $this->assertSame(
            $response->json('supported_sdk_versions.cli'),
            $response->json('client_compatibility.clients.cli.supported_versions'),
        );
        $this->assertSame(
            $response->json('supported_sdk_versions.python'),
            $response->json('client_compatibility.clients.sdk-python.supported_versions'),
        );
        $this->assertSame(
            $response->json('supported_sdk_versions.php'),
            $response->json('client_compatibility.clients.sdk-php.supported_versions'),
        );
        $this->assertSame(
            $response->json('supported_sdk_versions.rust'),
            $response->json('client_compatibility.clients.sdk-rust.supported_versions'),
        );

        $this->assertContains(
            'auth_composition.version',
            $response->json('client_compatibility.clients.cli.requires'),
        );
        $this->assertContains(
            'auth_composition.version',
            $response->json('client_compatibility.clients.sdk-python.requires'),
        );
        $this->assertContains(
            'worker_protocol.version',
            $response->json('client_compatibility.clients.sdk-rust.requires'),
        );
    }

    public function test_worker_protocol_manifest_uses_server_protocol_and_package_wire_helpers(): void
    {
        $expectedCommands = array_values(array_merge(
            WorkerProtocolVersion::terminalCommandTypes(),
            WorkerProtocolVersion::nonTerminalCommandTypes(),
        ));
        $response = $this->getJson('/api/cluster/info')->assertOk();

        $this->assertSame(WorkerProtocolVersion::VERSION, WorkerProtocol::VERSION);
        $this->assertSame(WorkerProtocol::VERSION, (string) config('server.worker_protocol.version'));
        $this->assertSame($expectedCommands, WorkerProtocol::supportedWorkflowTaskCommands());
        $this->assertContains('cancel_selection_operation', WorkerProtocol::supportedWorkflowTaskCommands());
        $this->assertSame(WorkerProtocol::VERSION, $response->json('worker_protocol.version'));
        $this->assertSame(
            $expectedCommands,
            $response->json('worker_protocol.server_capabilities.supported_workflow_task_commands'),
        );
        $this->assertTrue($response->json('worker_protocol.server_capabilities.workflow_memo_updates.supported'));
        $this->assertSame(
            WorkerProtocolVersion::upsertMemoCommandShape()['history'],
            $response->json('worker_protocol.server_capabilities.workflow_memo_updates.history'),
        );
        $this->assertTrue($response->json('worker_protocol.server_capabilities.message_streams.supported'));
        $this->assertSame(
            '1.15',
            $response->json('worker_protocol.server_capabilities.message_streams.minimum_worker_protocol_version'),
        );
        $this->assertTrue($response->json('worker_protocol.server_capabilities.typed_search_attributes.supported'));
        $this->assertSame(
            '1.16',
            $response->json('worker_protocol.server_capabilities.typed_search_attributes.minimum_worker_protocol_version'),
        );
        $this->assertSame(
            '1.17',
            $response->json('worker_protocol.server_capabilities.condition_wait_occurrence_identity.minimum_worker_protocol_version'),
        );
        $this->assertSame(
            'condition_wait_occurrence_id',
            $response->json('worker_protocol.server_capabilities.condition_wait_occurrence_identity.command_field'),
        );
        $this->assertSame(
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            $response->json('worker_protocol.server_capabilities.history_page_size_default'),
        );
        $this->assertSame(
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            $response->json('worker_protocol.server_capabilities.history_page_size_max'),
        );
        $this->assertSame(
            WorkerHistoryPayloadContract::manifest(),
            $response->json('worker_protocol.server_capabilities.workflow_history_budget'),
        );
        $this->assertTrue($response->json('worker_protocol.server_capabilities.query_tasks'));
        $this->assertTrue($response->json('worker_protocol.server_capabilities.query_task_poll_request_idempotency'));
        $this->assertSame(
            WorkerProtocolVersion::supportedHistoryEncodings(),
            $response->json('worker_protocol.server_capabilities.history_compression.supported_encodings'),
        );
        $this->assertSame(
            [
                'cpu_percent',
                'memory_bytes',
                'process_uptime_seconds',
                'process_id',
                'host',
                'process_started_at',
            ],
            $response->json('worker_protocol.server_capabilities.worker_status.fields.process_metrics'),
        );
    }

    public function test_cluster_info_advertises_worker_protocol_1_1_feature_floor_without_worker_sessions(): void
    {
        $protocolVersion = '1.1';

        $this->assertTrue(
            version_compare($protocolVersion, WorkerProtocol::workerSessionMinimumProtocolVersion(), '<'),
            'This test assumes worker sessions are gated above the protocol 1.1 feature floor.',
        );

        config(['server.worker_protocol.version' => $protocolVersion]);

        $response = $this->getJson('/api/cluster/info')->assertOk();

        $response->assertJsonPath('worker_protocol.version', $protocolVersion)
            ->assertJsonPath('client_compatibility.required_protocols.worker_protocol.version', $protocolVersion)
            ->assertJsonPath('capabilities.payload_codecs', PayloadCodecContract::universal())
            ->assertJsonPath('capabilities.worker_sessions', false)
            ->assertJsonPath('worker_protocol.server_capabilities.query_tasks', true)
            ->assertJsonPath('worker_protocol.server_capabilities.query_task_poll_request_idempotency', true)
            ->assertJsonPath(
                'worker_protocol.server_capabilities.local_activities.schema',
                'durable-workflow.v2.local-activity.contract',
            )
            ->assertJsonPath('worker_protocol.server_capabilities.worker_session_verbs', [])
            ->assertJsonPath('worker_protocol.server_capabilities.worker_sessions.supported', false)
            ->assertJsonPath(
                'worker_protocol.server_capabilities.worker_sessions.minimum_protocol_version',
                WorkerProtocol::workerSessionMinimumProtocolVersion(),
            )
            ->assertJsonPath(
                'worker_protocol.server_capabilities.worker_sessions.unavailable_reason',
                'worker_protocol_version_below_worker_session_minimum',
            );
    }
}
