<?php

namespace Tests\Unit;

use App\Support\NamespaceRuntimeContract;
use App\Support\NamespaceRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class NamespaceRuntimeContractTest extends TestCase
{
    public function test_manifest_requires_published_artifacts_and_run_record_fields(): void
    {
        $manifest = NamespaceRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.namespace-runtime.contract', $manifest['schema']);
        $this->assertSame(NamespaceRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.namespace-runtime.result', $manifest['result_schema']);
        $this->assertSame('namespace_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['scenario_manifest']['suite_schema'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'static/platform-conformance/namespace-runtime-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );

        foreach (['server', 'cli', 'workflow-php', 'sdk-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertContains(
            'local_product_source_checkout',
            $manifest['artifact_policy']['forbidden_sources'],
        );

        foreach ([
            'artifact_versions',
            'started_at',
            'finished_at',
            'generated_at',
            'outcome',
            'scenario_results',
            'findings',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_manifest_names_the_full_namespace_parity_surface(): void
    {
        $manifest = NamespaceRuntimeContract::manifest();

        $this->assertSame([
            'tenant-a',
            'tenant-b',
            'shared',
        ], $manifest['required_matrix']['namespaces']);
        $this->assertSame(['sdk-php', 'sdk-python'], $manifest['required_matrix']['runtimes']);
        $this->assertContains('cli', $manifest['required_matrix']['client_paths']);
        $this->assertContains('waterline-operator-api', $manifest['required_matrix']['observer_paths']);

        $this->assertSame([
            'published_artifact_install_only',
            'namespace_create_update_describe_and_list',
            'workflow_cross_namespace_visibility_isolation',
            'workflow_cross_namespace_mutation_isolation',
            'php_worker_task_queue_namespace_isolation',
            'cli_namespace_context_and_default_scope',
            'sdk_namespace_selection_parity',
            'search_attribute_schema_and_value_query_isolation',
            'schedule_namespace_isolation',
            'namespace_lifecycle_cleanup_and_recreate',
            'waterline_operator_namespace_visibility',
            'nexus_explicit_cross_namespace_invocation',
            'reserved_namespace_name_refusal',
            'result_record_and_product_finding_routing',
        ], $manifest['required_scenarios']);
    }

    public function test_scenario_manifest_source_path_is_published_and_matches_contract(): void
    {
        $manifest = NamespaceRuntimeContract::manifest();
        $scenarioManifestPath = dirname(__DIR__, 2) . '/' . $manifest['scenario_manifest']['source_path'];

        $this->assertFileExists(
            $scenarioManifestPath,
            'cluster info must not advertise a namespace scenario manifest source path that is missing from the release tree',
        );

        $scenarioManifest = json_decode(
            (string) file_get_contents($scenarioManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($manifest['scenario_manifest']['schema'], $scenarioManifest['schema']);
        $this->assertSame($manifest['scenario_manifest']['category'], $scenarioManifest['category']);
        $this->assertSame($manifest['scenario_manifest']['suite_schema'], $scenarioManifest['suite_schema']);
        $this->assertSame($manifest['scenario_manifest']['suite_version'], $scenarioManifest['suite_version']);
        $this->assertSame(PlatformConformanceSuite::VERSION, $scenarioManifest['suite_version']);
        $this->assertSame($manifest['result_schema'], $scenarioManifest['result_schema']);
        $this->assertSame($manifest['result_version'], $scenarioManifest['result_version']);
        $this->assertSame($manifest['scenario_statuses'], $scenarioManifest['result_statuses']);
        $this->assertSame($manifest['required_scenarios'], array_column($scenarioManifest['scenarios'], 'id'));
        $this->assertSame(
            $manifest['host_runner_contract']['runner_path'],
            $scenarioManifest['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['runner_command'],
            $scenarioManifest['host_runner_contract']['runner_command'],
        );

        foreach ($manifest['scenario_requirements'] as $scenarioId => $requirements) {
            $this->assertSame(
                $requirements['evidence'],
                $scenarioManifest['scenario_requirements'][$scenarioId]['required_fields'],
                sprintf('public namespace scenario manifest required fields drifted for %s', $scenarioId),
            );
        }
    }

    public function test_manifest_keeps_smoke_only_coverage_non_passing(): void
    {
        $manifest = NamespaceRuntimeContract::manifest();
        $gate = $manifest['coverage_gate'];

        $this->assertContains('not_covered', $manifest['scenario_statuses']);
        $this->assertSame('non_passing', $gate['uncovered_required_scenario_outcome']);
        $this->assertSame('non_passing', $gate['smoke_subset_outcome']);

        foreach ([
            'all_required_scenarios_reported',
            'all_required_namespaces_present',
            'published_artifact_install_reported',
            'namespace_crud_behavior_reported',
            'workflow_visibility_isolation_reported',
            'workflow_mutation_isolation_reported',
            'cli_namespace_behavior_reported',
            'sdk_namespace_selection_reported',
            'php_worker_behavior_reported',
            'sdk_php_namespace_shard_execution_recorded',
            'schedule_namespace_isolation_reported',
            'waterline_operator_visibility_reported',
            'waterline_namespace_shard_execution_recorded',
            'waterline_operator_surface_verdicts_reported',
            'search_attribute_value_query_isolation_reported',
            'namespace_lifecycle_cleanup_reported',
            'nexus_cross_namespace_behavior_reported',
            'adversarial_namespace_name_refusal_reported',
            'result_record_and_product_finding_routing_reported',
            'findings_linked_for_non_pass_scenarios',
        ] as $requirement) {
            $this->assertContains($requirement, $gate['passing_outcome_requires']);
        }

        $this->assertSame(
            ['tenant_a_worker_registration', 'tenant_b_worker_registration', 'tenant_a_delivery', 'tenant_b_delivery', 'cross_delivery_absent', 'sdk_php_shard_execution'],
            $manifest['scenario_requirements']['php_worker_task_queue_namespace_isolation']['evidence'],
        );
        $this->assertSame(
            ['python_client_namespace', 'php_client_namespace', 'default_namespace_behavior', 'cross_namespace_lookup_denied'],
            $manifest['scenario_requirements']['sdk_namespace_selection_parity']['evidence'],
        );
        $this->assertSame(
            ['tenant_a_schedule', 'tenant_b_schedule', 'tenant_a_list_excludes_tenant_b', 'cross_namespace_schedule_mutation_denied'],
            $manifest['scenario_requirements']['schedule_namespace_isolation']['evidence'],
        );
        $this->assertSame(
            ['tenant_a_scoped_views', 'tenant_b_scoped_views', 'detail_namespace_identity', 'unscoped_view_authority', 'api_captures', 'operator_surface_matrix', 'waterline_shard_execution'],
            $manifest['scenario_requirements']['waterline_operator_namespace_visibility']['evidence'],
        );
        $this->assertSame(
            ['refused_names', 'typed_errors', 'valid_control_name_accepted', 'stored_namespace_names'],
            $manifest['scenario_requirements']['reserved_namespace_name_refusal']['evidence'],
        );
        $this->assertContains(
            'waterline-operator-namespace-shard',
            $manifest['host_runner_contract']['required_execution_scopes'],
        );
        $this->assertSame(
            'scripts/conformance/namespaces-published-artifacts.sh',
            $manifest['host_runner_contract']['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/namespaces-published-artifacts.sh --result-dir <result-dir>',
            $manifest['host_runner_contract']['runner_command'],
        );
        foreach ([
            'pins.json',
            'run-metadata.json',
            'artifact-install-evidence.json',
            'namespaces-result.json',
            'namespaces-record.json',
        ] as $file) {
            $this->assertContains($file, $manifest['host_runner_contract']['result_files']);
        }
        $this->assertSame(
            'php-sdk-published-artifacts',
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['preferred_command'],
        );
        $this->assertSame(
            'scripts/conformance/php-sdk-published-artifacts.sh',
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/php-sdk-published-artifacts.sh --scope namespace --result-dir <result-dir>',
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['runner_command'],
        );
        $this->assertSame(
            'namespace',
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['runner_scope'],
        );
        $this->assertSame(
            'durable-workflow/sdk',
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['artifact'],
        );
        $this->assertSame(
            [
                'namespace_create_update_describe_and_list',
                'sdk_namespace_selection_parity',
                'php_worker_task_queue_namespace_isolation',
            ],
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['must_cover_scenarios'],
        );
        $this->assertSame(
            [
                'explicit_namespace_selection',
                'documented_default_namespace',
                'cross_namespace_not_found',
            ],
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['must_cover_client_behavior'],
        );
        $this->assertSame(
            [
                'same_queue_tenant_a_delivery',
                'same_queue_tenant_b_delivery',
                'cross_namespace_delivery_absent',
            ],
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['must_cover_worker_behavior'],
        );
        $this->assertSame(
            [
                'sdk-php-namespace-selection',
                'sdk-php-worker-registration',
                'same_queue_worker_delivery_isolation',
                'cross_namespace_workflow_lookup_denied',
            ],
            $manifest['host_runner_contract']['runtime_shards']['sdk-php']['must_cover_surfaces'],
        );
        $this->assertSame(
            'waterline:namespace-conformance',
            $manifest['host_runner_contract']['runtime_shards']['waterline']['artisan_command'],
        );
    }

    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = NamespaceRuntimeContract::manifest()['result_gate'];

        $this->assertSame(NamespaceRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(NamespaceRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            NamespaceRuntimeContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertSame(['outcome', 'status', 'verdict'], $resultGate['declared_outcome_fields']);
        $this->assertContains('every_required_scenario_has_one_result', $resultGate['pass_requires']);
        $this->assertContains(
            'cli_php_waterline_nexus_cleanup_and_search_value_sections_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'each_pass_scenario_has_concrete_named_evidence_fields',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'sdk_php_worker_pass_requires_published_shard_execution',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'waterline_operator_pass_requires_published_shard_execution',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'waterline_operator_visibility_has_scoped_surface_verdicts',
            $resultGate['pass_requires'],
        );
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
    }

    public function test_result_gate_rejects_namespace_smoke_subset_even_when_the_smoke_passes(): void
    {
        $evaluation = NamespaceRuntimeResultGate::evaluate([
            'schema' => NamespaceRuntimeContract::RESULT_SCHEMA,
            'started_at' => '2026-05-20T05:00:00Z',
            'finished_at' => '2026-05-20T05:05:00Z',
            'generated_at' => '2026-05-20T05:05:00Z',
            'outcome' => 'pass',
            'artifactVersions' => [
                'server' => '0.2.153',
                'cli' => '0.1.53',
                'sdk-python' => '0.4.64',
                'workflow' => '2.0.0-alpha.166',
                'waterline' => '2.0.0-alpha.57',
            ],
            'namespace_topology' => [
                'namespaces' => ['tenant-a', 'tenant-b', 'shared'],
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-python'],
                'client_paths' => ['cli', 'sdk-python'],
            ],
            'scenario_results' => [
                'published_artifact_install_only' => $this->passScenario('published_artifact_install_only'),
                'namespace_create_update_describe_and_list' => $this->passScenario('namespace_create_update_describe_and_list'),
                'workflow_cross_namespace_visibility_isolation' => $this->passScenario('workflow_cross_namespace_visibility_isolation'),
                'workflow_cross_namespace_mutation_isolation' => $this->passScenario('workflow_cross_namespace_mutation_isolation'),
                'search_attribute_schema_and_value_query_isolation' => $this->passScenario('search_attribute_schema_and_value_query_isolation'),
                'schedule_namespace_isolation' => $this->passScenario('schedule_namespace_isolation'),
            ],
            'findings' => [],
            'finding_links' => [],
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('namespace_lifecycle_cleanup_and_recreate', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeNamespaceResult();
        $result['scenario_results']['nexus_explicit_cross_namespace_invocation']['status'] = 'fail';
        unset($result['scenario_results']['nexus_explicit_cross_namespace_invocation']['linked_findings']);

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('nexus_explicit_cross_namespace_invocation', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_full_surface_sections_for_pass(): void
    {
        $result = $this->completeNamespaceResult();
        unset(
            $result['namespace_lifecycle_cleanup'],
            $result['nexus_cross_namespace'],
            $result['waterline_operator_visibility'],
            $result['search_attribute_value_query_isolation'],
        );

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_scenario_specific_evidence', $failureCodes);
    }

    public function test_result_gate_rejects_generic_outputs_for_remaining_namespace_scenarios(): void
    {
        $result = $this->completeNamespaceResult();
        unset(
            $result['published_artifact_install'],
            $result['namespace_crud_behavior'],
            $result['workflow_visibility_isolation'],
            $result['workflow_mutation_isolation'],
            $result['sdk_namespace_selection'],
            $result['schedule_namespace_isolation'],
            $result['adversarial_namespace_names'],
            $result['result_record_and_product_finding_routing'],
        );

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $missingEvidence = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_scenario_specific_evidence',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        foreach ([
            'published_artifact_install_only',
            'namespace_create_update_describe_and_list',
            'workflow_cross_namespace_visibility_isolation',
            'workflow_cross_namespace_mutation_isolation',
            'sdk_namespace_selection_parity',
            'schedule_namespace_isolation',
            'reserved_namespace_name_refusal',
            'result_record_and_product_finding_routing',
        ] as $scenario) {
            $this->assertContains($scenario, array_column($missingEvidence, 'scenario_id'));
        }
    }

    public function test_result_gate_requires_concrete_fields_for_sdk_schedule_and_reserved_names(): void
    {
        $result = $this->completeNamespaceResult();
        unset(
            $result['php_worker_behavior']['sdk_php_shard_execution'],
            $result['waterline_operator_visibility']['waterline_shard_execution'],
            $result['waterline_operator_visibility']['api_captures'],
            $result['sdk_namespace_selection']['cross_namespace_lookup_denied'],
            $result['schedule_namespace_isolation']['cross_namespace_schedule_mutation_denied'],
            $result['adversarial_namespace_names']['valid_control_name_accepted'],
        );

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $missingEvidence = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_scenario_specific_evidence',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            ['php_worker_task_queue_namespace_isolation', 'sdk_php_shard_execution'],
            array_map(
                static fn (array $failure): array => [$failure['scenario_id'] ?? null, $failure['field'] ?? null],
                $missingEvidence,
            ),
        );
        $this->assertContains(
            ['sdk_namespace_selection_parity', 'cross_namespace_lookup_denied'],
            array_map(
                static fn (array $failure): array => [$failure['scenario_id'] ?? null, $failure['field'] ?? null],
                $missingEvidence,
            ),
        );
        $this->assertContains(
            ['schedule_namespace_isolation', 'cross_namespace_schedule_mutation_denied'],
            array_map(
                static fn (array $failure): array => [$failure['scenario_id'] ?? null, $failure['field'] ?? null],
                $missingEvidence,
            ),
        );
        $this->assertContains(
            ['waterline_operator_namespace_visibility', 'api_captures'],
            array_map(
                static fn (array $failure): array => [$failure['scenario_id'] ?? null, $failure['field'] ?? null],
                $missingEvidence,
            ),
        );
        $this->assertContains(
            ['waterline_operator_namespace_visibility', 'waterline_shard_execution'],
            array_map(
                static fn (array $failure): array => [$failure['scenario_id'] ?? null, $failure['field'] ?? null],
                $missingEvidence,
            ),
        );
        $this->assertContains(
            ['reserved_namespace_name_refusal', 'valid_control_name_accepted'],
            array_map(
                static fn (array $failure): array => [$failure['scenario_id'] ?? null, $failure['field'] ?? null],
                $missingEvidence,
            ),
        );
    }

    public function test_result_gate_rejects_php_worker_pass_without_published_php_shard_execution(): void
    {
        $result = $this->completeNamespaceResult();
        $result['php_worker_behavior'] = [
            'tenant_a_worker_registration' => ['namespace' => 'tenant-a'],
            'tenant_b_worker_registration' => ['namespace' => 'tenant-b'],
            'tenant_a_delivery' => ['worker' => 'tenant-a-worker'],
            'tenant_b_delivery' => ['worker' => 'tenant-b-worker'],
            'cross_delivery_absent' => true,
        ];
        $result['published_artifact_install']['sdk_php_package'] = [
            'version' => '0.1.1',
            'source' => 'packagist_package',
            'status' => 'resolved',
            'shard_command' => 'php-sdk-published-artifacts',
        ];

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_scenario_specific_evidence', $failureCodes);
        $this->assertContains('missing_sdk_php_shard_execution', $failureCodes);
        $this->assertContains('sdk_php_artifact_not_marked_executed', $failureCodes);
        $this->assertContains('missing_sdk_php_artifact_shard_execution_record', $failureCodes);
    }

    public function test_result_gate_rejects_php_worker_pass_with_missing_php_shard_status(): void
    {
        $result = $this->completeNamespaceResult();
        $result['php_worker_behavior']['sdk_php_shard_execution']['status'] = 'missing';
        $result['published_artifact_install']['sdk_php_package']['status'] = 'missing';
        $result['published_artifact_install']['sdk_php_package']['namespace_shard_execution']['status'] = 'missing';

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('sdk_php_shard_not_executed', $failureCodes);
        $this->assertContains('sdk_php_artifact_not_marked_executed', $failureCodes);
        $this->assertContains('sdk_php_artifact_shard_not_executed', $failureCodes);
    }

    public function test_result_gate_rejects_php_worker_pass_with_stale_php_shard_artifact_versions(): void
    {
        $result = $this->completeNamespaceResult();
        $result['php_worker_behavior']['sdk_php_shard_execution']['artifact_versions']['sdk-php'] =
            '0.1.0';
        $result['published_artifact_install']['sdk_php_package']['namespace_shard_execution']['artifact_versions']['sdk-php'] =
            '0.1.0';

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $versionMismatches = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'sdk_php_shard_artifact_version_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame('sdk-php', $versionMismatches[0]['artifact'] ?? null);
        $this->assertSame('0.1.1', $versionMismatches[0]['expected_version'] ?? null);
        $this->assertSame('0.1.0', $versionMismatches[0]['actual_version'] ?? null);
    }

    public function test_result_gate_rejects_php_worker_pass_when_php_shard_does_not_cover_required_scenarios(): void
    {
        $result = $this->completeNamespaceResult();
        $execution = $result['php_worker_behavior']['sdk_php_shard_execution'];
        $execution['covered_scenarios'] = ['php_worker_task_queue_namespace_isolation'];
        $execution['scenario_statuses'] = ['php_worker_task_queue_namespace_isolation' => 'pass'];
        $result['php_worker_behavior']['sdk_php_shard_execution'] = $execution;
        $result['published_artifact_install']['sdk_php_package']['namespace_shard_execution'] = $execution;

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            ['sdk_php_shard_missing_required_scenario', 'namespace_create_update_describe_and_list'],
            array_map(
                static fn (array $failure): array => [
                    $failure['code'] ?? null,
                    $failure['missing_scenario_id'] ?? null,
                ],
                $evaluation['gate_failures'],
            ),
        );
        $this->assertContains(
            ['sdk_php_shard_required_scenario_not_passed', 'sdk_namespace_selection_parity', ''],
            array_map(
                static fn (array $failure): array => [
                    $failure['code'] ?? null,
                    $failure['required_scenario_id'] ?? null,
                    $failure['status'] ?? null,
                ],
                $evaluation['gate_failures'],
            ),
        );
    }

    public function test_result_gate_requires_waterline_operator_surface_verdicts(): void
    {
        $result = $this->completeNamespaceResult();
        unset($result['waterline_operator_visibility']['operator_surface_matrix']);

        $missing = NamespaceRuntimeResultGate::evaluate($result);
        $this->assertSame('non_passing', $missing['status']);
        $this->assertContains(
            'missing_waterline_operator_surface_matrix',
            array_column($missing['gate_failures'], 'code'),
        );

        $result = $this->completeNamespaceResult();
        $result['waterline_operator_visibility']['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['workflow_list_scoped'] = false;
        $failed = NamespaceRuntimeResultGate::evaluate($result);
        $this->assertSame('non_passing', $failed['status']);
        $this->assertContains(
            ['waterline_operator_surface_verdict_failed', 'tenant_a', 'workflow_list_scoped'],
            array_map(
                static fn (array $failure): array => [
                    $failure['code'] ?? null,
                    $failure['tenant'] ?? null,
                    $failure['field'] ?? null,
                ],
                $failed['gate_failures'],
            ),
        );

        foreach ([
            'tenant_a' => ['expected' => 'tenant-a', 'actual' => 'tenant-b'],
            'tenant_b' => ['expected' => 'tenant-b', 'actual' => 'tenant-a'],
        ] as $tenantKey => $namespaces) {
            $result = $this->completeNamespaceResult();
            $result['waterline_operator_visibility']['operator_surface_matrix']['tenant_scoped_surfaces'][$tenantKey]['namespace'] = $namespaces['actual'];
            $mismatchedTenant = NamespaceRuntimeResultGate::evaluate($result);
            $this->assertSame('non_passing', $mismatchedTenant['status']);
            $this->assertContains(
                [
                    'mismatched_waterline_tenant_surface_namespace',
                    $tenantKey,
                    $namespaces['expected'],
                    $namespaces['actual'],
                ],
                array_map(
                    static fn (array $failure): array => [
                        $failure['code'] ?? null,
                        $failure['tenant'] ?? null,
                        $failure['expected_namespace'] ?? null,
                        $failure['actual_namespace'] ?? null,
                    ],
                    $mismatchedTenant['gate_failures'],
                ),
            );
        }

        $result = $this->completeNamespaceResult();
        $result['waterline_operator_visibility']['operator_surface_matrix']['unscoped_authority']['documented_cluster_authority'] = false;
        $unscoped = NamespaceRuntimeResultGate::evaluate($result);
        $this->assertContains(
            ['waterline_unscoped_authority_verdict_failed', 'documented_cluster_authority'],
            array_map(
                static fn (array $failure): array => [
                    $failure['code'] ?? null,
                    $failure['field'] ?? null,
                ],
                $unscoped['gate_failures'],
            ),
        );
    }

    public function test_result_gate_rejects_waterline_pass_without_published_shard_execution(): void
    {
        $result = $this->completeNamespaceResult();
        unset($result['waterline_operator_visibility']['waterline_shard_execution']);

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_scenario_specific_evidence', $failureCodes);
        $this->assertContains('missing_waterline_shard_execution', $failureCodes);
    }

    public function test_result_gate_rejects_waterline_pass_with_stale_shard_artifact_versions(): void
    {
        $result = $this->completeNamespaceResult();
        $result['waterline_operator_visibility']['waterline_shard_execution']['artifact_versions']['waterline'] =
            '2.0.0-alpha.56';

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $versionMismatches = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'waterline_shard_artifact_version_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame('waterline', $versionMismatches[0]['artifact'] ?? null);
        $this->assertSame('2.0.0-alpha.57', $versionMismatches[0]['expected_version'] ?? null);
        $this->assertSame('2.0.0-alpha.56', $versionMismatches[0]['actual_version'] ?? null);
    }

    public function test_result_gate_rejects_placeholder_artifact_versions(): void
    {
        $result = $this->completeNamespaceResult();
        $result['artifactVersions'] = [
            'server' => 'durableworkflow/server:<latest>',
            'cli' => 'latest',
            'sdk-python' => 'durable-workflow==<latest>',
            'workflow' => '2.0.0-alpha.<latest>',
            'sdk-php' => '<latest>',
            'waterline' => '2.0.0-alpha.57',
        ];

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $placeholderFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(
            ['server', 'cli', 'workflow-php', 'sdk-php', 'sdk-python'],
            array_column($placeholderFailures, 'artifact'),
        );
    }

    public function test_result_gate_accepts_contract_declared_non_passing_outcomes(): void
    {
        $coverageGate = NamespaceRuntimeContract::manifest()['coverage_gate'];
        $acceptedOutcomes = [
            $coverageGate['uncovered_required_scenario_outcome'],
            $coverageGate['smoke_subset_outcome'],
            $coverageGate['unsupported_public_surface_outcome'],
            $coverageGate['runner_blocked_outcome'],
        ];

        foreach (array_unique($acceptedOutcomes) as $outcome) {
            $result = $this->completeNamespaceResult();
            $result['outcome'] = $outcome;
            $result['scenario_results']['waterline_operator_namespace_visibility']['status'] =
                $outcome === $coverageGate['runner_blocked_outcome'] ? 'runner_blocked' : 'unsupported';
            $result['scenario_results']['waterline_operator_namespace_visibility']['linked_findings'] = [
                'https://tracker.example/findings/waterline-namespace-visibility',
            ];

            $evaluation = NamespaceRuntimeResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains(
                'invalid_declared_outcome',
                array_column($evaluation['gate_failures'], 'code'),
                'Outcome ' . $outcome . ' must remain valid because coverage_gate advertises it.',
            );
        }
    }

    public function test_result_gate_rejects_complete_pass_with_non_passing_declared_outcome(): void
    {
        $result = $this->completeNamespaceResult();
        $result['outcome'] = 'non_passing';

        $evaluation = NamespaceRuntimeResultGate::evaluate($result);
        $mismatchFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $mismatchFailures);
        $this->assertSame('non_passing', $mismatchFailures[0]['outcome']);
        $this->assertSame('pass', $mismatchFailures[0]['evaluated_status']);
    }

    public function test_result_gate_accepts_a_complete_passing_surface(): void
    {
        $evaluation = NamespaceRuntimeResultGate::evaluate($this->completeNamespaceResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeNamespaceResult(): array
    {
        $scenarioResults = [];
        foreach (NamespaceRuntimeContract::manifest()['required_scenarios'] as $scenario) {
            $scenarioResults[$scenario] = $this->passScenario($scenario);
        }

        return [
            'schema' => NamespaceRuntimeContract::RESULT_SCHEMA,
            'started_at' => '2026-05-20T05:00:00Z',
            'finished_at' => '2026-05-20T05:05:00Z',
            'generated_at' => '2026-05-20T05:05:00Z',
            'outcome' => 'pass',
            'artifactVersions' => [
                'server' => '0.2.153',
                'cli' => '0.1.53',
                'sdk-python' => '0.4.64',
                'workflow' => '2.0.0-alpha.166',
                'sdk-php' => '0.1.1',
                'waterline' => '2.0.0-alpha.57',
            ],
            'artifact_sources' => [
                'server' => 'published_docker_image',
                'cli' => 'published_install_script',
                'workflow' => 'published_composer_package',
                'sdk-php' => 'published_composer_package',
                'sdk-python' => 'published_pypi_package',
                'waterline' => 'published_package',
            ],
            'namespace_topology' => [
                'namespaces' => ['tenant-a', 'tenant-b', 'shared'],
                'task_queues' => ['iso'],
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-php', 'sdk-python'],
                'client_paths' => ['cli', 'sdk-python', 'sdk-php'],
                'observer_paths' => ['waterline-list', 'waterline-detail', 'waterline-operator-api'],
                'worker_isolation_cells' => [
                    [
                        'scenario' => 'php_worker_task_queue_namespace_isolation',
                        'runtime' => 'sdk-php',
                        'namespace' => 'tenant-a',
                        'task_queue' => 'iso',
                    ],
                    [
                        'scenario' => 'php_worker_task_queue_namespace_isolation',
                        'runtime' => 'sdk-php',
                        'namespace' => 'tenant-b',
                        'task_queue' => 'iso',
                    ],
                ],
                'cross_namespace_cells' => [
                    [
                        'scenario' => 'workflow_cross_namespace_visibility_isolation',
                        'from' => 'tenant-a',
                        'to' => 'tenant-b',
                        'surface' => 'workflow-control-plane',
                    ],
                    [
                        'scenario' => 'workflow_cross_namespace_mutation_isolation',
                        'from' => 'tenant-b',
                        'to' => 'tenant-a',
                        'surface' => 'workflow-control-plane',
                    ],
                    [
                        'scenario' => 'nexus_explicit_cross_namespace_invocation',
                        'from' => 'tenant-a',
                        'to' => 'shared',
                        'surface' => 'nexus',
                    ],
                    [
                        'scenario' => 'nexus_explicit_cross_namespace_invocation',
                        'from' => 'tenant-b',
                        'to' => 'shared',
                        'surface' => 'nexus',
                    ],
                ],
            ],
            'published_artifact_install' => [
                'server_image' => 'durableworkflow/server:0.2.153',
                'cli_release' => '0.1.53',
                'sdk_php_package' => [
                    'version' => '0.1.1',
                    'source' => 'packagist_package',
                    'status' => 'executed',
                    'shard_command' => 'php-sdk-published-artifacts',
                    'namespace_shard_execution' => $this->sdkPhpShardExecution(),
                ],
                'sdk_python_package' => 'durable-workflow==0.4.64',
                'waterline_artifact' => '2.0.0-alpha.57',
            ],
            'namespace_crud_behavior' => [
                'created_namespaces' => ['tenant-a', 'tenant-b', 'shared'],
                'updated_namespace' => ['name' => 'tenant-a', 'retention_days' => 7],
                'described_namespaces' => ['tenant-a', 'tenant-b', 'shared'],
                'listed_namespaces' => ['tenant-a', 'tenant-b', 'shared'],
            ],
            'workflow_visibility_isolation' => [
                'tenant_a_workflow' => 'tenant-a-workflow',
                'tenant_b_workflow' => 'tenant-b-workflow',
                'tenant_a_list_excludes_tenant_b' => true,
                'tenant_b_describe_tenant_a_denied' => true,
            ],
            'workflow_mutation_isolation' => [
                'same_namespace_signal_succeeds' => true,
                'same_namespace_cancel_succeeds' => true,
                'cross_namespace_signal_denied' => true,
                'cross_namespace_cancel_denied' => true,
            ],
            'cli_namespace_behavior' => [
                'explicit_namespace_json' => ['namespace' => 'tenant-a'],
                'explicit_namespace_human_output' => 'Namespace: tenant-a',
                'default_scope_behavior' => 'default namespace only',
            ],
            'sdk_namespace_selection' => [
                'python_client_namespace' => 'tenant-a',
                'php_client_namespace' => 'tenant-b',
                'default_namespace_behavior' => 'uses configured default namespace only',
                'cross_namespace_lookup_denied' => true,
            ],
            'php_worker_behavior' => [
                'tenant_a_worker_registration' => ['namespace' => 'tenant-a'],
                'tenant_b_worker_registration' => ['namespace' => 'tenant-b'],
                'tenant_a_delivery' => ['worker' => 'tenant-a-worker'],
                'tenant_b_delivery' => ['worker' => 'tenant-b-worker'],
                'cross_delivery_absent' => true,
                'sdk_php_shard_execution' => $this->sdkPhpShardExecution(),
            ],
            'waterline_operator_visibility' => [
                'tenant_a_scoped_views' => ['tenant-a-run'],
                'tenant_b_scoped_views' => ['tenant-b-run'],
                'detail_namespace_identity' => 'tenant-a',
                'unscoped_view_authority' => 'explicit operator-wide authority required',
                'api_captures' => [
                    'tenant_a_scoped_views' => [
                        'workflow_list' => ['path' => '/api/flows/completed', 'status' => 200],
                    ],
                    'tenant_b_scoped_views' => [
                        'workflow_list' => ['path' => '/api/flows/completed', 'status' => 200],
                    ],
                    'unscoped_view_authority' => [
                        'workflow_list' => ['path' => '/api/flows/completed', 'status' => 200],
                    ],
                ],
                'operator_surface_matrix' => [
                    'tenant_scoped_surfaces' => [
                        'tenant_a' => $this->waterlineTenantSurfaceMatrix('tenant-a'),
                        'tenant_b' => $this->waterlineTenantSurfaceMatrix('tenant-b'),
                    ],
                    'unscoped_authority' => [
                        'documented_cluster_authority' => true,
                        'dashboard_cluster_authority_visible' => true,
                        'workflow_list_cluster_authority' => true,
                        'schedule_list_cluster_authority' => true,
                        'operator_api_cluster_authority' => true,
                    ],
                ],
                'waterline_shard_execution' => $this->waterlineShardExecution(),
            ],
            'search_attribute_value_query_isolation' => [
                'schema_isolation' => true,
                'value_query_isolation' => true,
                'tenant_a_value' => 'customer-a',
                'tenant_b_observed_result' => ['workflow_count' => 0],
            ],
            'schedule_namespace_isolation' => [
                'tenant_a_schedule' => 'tenant-a-nightly',
                'tenant_b_schedule' => 'tenant-b-nightly',
                'tenant_a_list_excludes_tenant_b' => true,
                'cross_namespace_schedule_mutation_denied' => true,
            ],
            'namespace_lifecycle_cleanup' => [
                'deleted_namespace' => 'tenant-a',
                'pre_delete_resources' => [
                    'workflow_ids' => ['tenant-a-run'],
                    'schedule_ids' => ['tenant-a-schedule'],
                    'search_attribute_names' => ['customer_id'],
                    'worker_ids' => ['tenant-a-worker'],
                ],
                'deleted_counts' => [
                    'workflow_runs' => 1,
                    'workflow_schedules' => 1,
                    'search_attribute_definitions' => 1,
                    'workflow_worker_registrations' => 1,
                ],
                'post_delete_refusals' => [
                    'workflow_list' => ['status_code' => 404, 'reason' => 'namespace_not_found'],
                    'schedule_list' => ['status_code' => 404, 'reason' => 'namespace_not_found'],
                    'search_attribute_list' => ['status_code' => 404, 'reason' => 'namespace_not_found'],
                    'worker_list' => ['status_code' => 404, 'reason' => 'namespace_not_found'],
                ],
                'operator_surface_cleanup' => [
                    'namespace_describe_after_delete' => ['status_code' => 404],
                    'deleted_namespace_absent_from_list' => true,
                ],
                'workflow_cleanup' => ['after_delete_refused' => ['status_code' => 404], 'after_recreate' => ['workflows' => []]],
                'schedule_cleanup' => ['after_delete_refused' => ['status_code' => 404], 'after_recreate' => ['schedules' => []]],
                'search_attribute_cleanup' => ['after_delete_refused' => ['status_code' => 404], 'after_recreate' => ['custom_attributes' => []]],
                'worker_registration_cleanup' => ['after_delete_refused' => ['status_code' => 404], 'after_recreate' => ['workers' => []]],
                'retained_resources' => [
                    'tenant_b_workflows_after_delete' => ['workflows' => [['workflow_id' => 'tenant-b-run']]],
                    'tenant_b_schedules_after_delete' => ['schedules' => [['schedule_id' => 'tenant-b-schedule']]],
                    'tenant_b_search_attributes_after_delete' => ['custom_attributes' => []],
                    'tenant_b_workers_after_delete' => ['workers' => [['worker_id' => 'tenant-b-worker']]],
                ],
                'recreate_state_empty' => true,
                'external_payload_contexts_checked' => true,
            ],
            'nexus_cross_namespace' => [
                'service_endpoint_namespace' => 'shared',
                'caller_namespaces' => ['tenant-a', 'tenant-b'],
                'target_namespace' => 'shared',
                'successful_results' => ['tenant-a' => 'ok', 'tenant-b' => 'ok'],
                'direct_access_without_nexus_blocked' => true,
            ],
            'adversarial_namespace_names' => [
                'refused_names' => ['', '../tenant-a', "tenant\nx"],
                'typed_errors' => ['invalid_namespace_name'],
                'valid_control_name_accepted' => 'tenant-control',
                'stored_namespace_names' => ['tenant-a', 'tenant-b', 'shared', 'tenant-control'],
            ],
            'result_record_and_product_finding_routing' => [
                'artifact_versions_recorded' => true,
                'timestamps_recorded' => true,
                'outcome_recorded' => true,
                'finding_links_recorded' => true,
                'product_finding_routes_checked' => [
                    'server',
                    'cli',
                    'workflow-php',
                    'sdk-php',
                    'sdk-python',
                    'waterline',
                ],
            ],
            'findings' => [],
            'finding_links' => [],
            'scenario_results' => $scenarioResults,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function passScenario(string $scenario): array
    {
        return [
            'scenario_id' => $scenario,
            'status' => 'pass',
            'observed_outputs' => [
                'recorded' => true,
                'scenario' => $scenario,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function waterlineTenantSurfaceMatrix(string $namespace): array
    {
        return [
            'namespace' => $namespace,
            'active_namespace_visible' => true,
            'workflow_list_scoped' => true,
            'workflow_detail_scoped' => true,
            'schedule_list_scoped' => true,
            'schedule_detail_scoped' => true,
            'search_attribute_values_scoped' => true,
            'operator_api_scoped' => true,
            'api_captures_scoped' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sdkPhpShardExecution(): array
    {
        return [
            'status' => 'executed',
            'required' => true,
            'shard_command' => 'php-sdk-published-artifacts',
            'scope' => 'sdk-php-namespace-shard',
            'coverage_scope' => 'sdk-php-namespace-shard',
            'artifact' => 'durable-workflow/sdk',
            'artifact_version' => '0.1.1',
            'artifact_versions' => [
                'server' => '0.2.153',
                'cli' => '0.1.53',
                'workflow-php' => '2.0.0-alpha.166',
                'sdk-php' => '0.1.1',
                'sdk-python' => '0.4.64',
                'waterline' => '2.0.0-alpha.57',
            ],
            'required_scenarios' => [
                'namespace_create_update_describe_and_list',
                'sdk_namespace_selection_parity',
                'php_worker_task_queue_namespace_isolation',
            ],
            'covered_scenarios' => [
                'namespace_create_update_describe_and_list',
                'php_worker_task_queue_namespace_isolation',
                'sdk_namespace_selection_parity',
            ],
            'scenario_statuses' => [
                'namespace_create_update_describe_and_list' => 'pass',
                'sdk_namespace_selection_parity' => 'pass',
                'php_worker_task_queue_namespace_isolation' => 'pass',
            ],
            'report_path' => 'artifacts/sdk-php-namespace-shard.json',
            'scenario_status' => 'pass',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function waterlineShardExecution(): array
    {
        return [
            'status' => 'executed',
            'required' => true,
            'shard_command' => 'waterline:namespace-conformance',
            'scope' => 'waterline-operator-namespace-shard',
            'coverage_scope' => 'waterline-operator-namespace-shard',
            'artifact' => 'durable-workflow/waterline',
            'artifact_version' => '2.0.0-alpha.57',
            'artifact_versions' => [
                'server' => '0.2.153',
                'cli' => '0.1.53',
                'workflow-php' => '2.0.0-alpha.166',
                'sdk-php' => '0.1.1',
                'sdk-python' => '0.4.64',
                'waterline' => '2.0.0-alpha.57',
            ],
            'required_scenarios' => [
                'waterline_operator_namespace_visibility',
            ],
            'covered_scenarios' => [
                'waterline_operator_namespace_visibility',
            ],
            'scenario_statuses' => [
                'waterline_operator_namespace_visibility' => 'pass',
            ],
            'report_path' => 'artifacts/waterline-namespace-shard.json',
            'scenario_status' => 'pass',
        ];
    }
}
