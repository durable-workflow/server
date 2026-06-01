<?php

namespace Tests\Unit;

use App\Support\SearchAttributeRuntimeContract;
use App\Support\SearchAttributeRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class SearchAttributeRuntimeContractTest extends TestCase
{
    public function test_manifest_requires_published_artifacts_and_run_record_fields(): void
    {
        $manifest = SearchAttributeRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.search-attribute-runtime.contract', $manifest['schema']);
        $this->assertSame(4, SearchAttributeRuntimeContract::VERSION);
        $this->assertSame(SearchAttributeRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.search-attribute-runtime.result', $manifest['result_schema']);
        $this->assertSame('search_attribute_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'search_attribute_runtime_contract',
                'public_path' => 'https://durable-workflow.com/platform-conformance/search-attribute-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/search-attribute-runtime-scenarios.json',
            ],
            $manifest['scenario_manifest'],
        );
        $this->assertSame(
            'concrete_published_versions_pinned_at_run_time',
            $manifest['artifact_policy']['version_requirement'],
        );
        $this->assertTrue($manifest['artifact_policy']['placeholder_versions_rejected']);
        foreach (['latest', 'current', 'head', '<latest>', '${VERSION}', '{{ version }}'] as $example) {
            $this->assertContains($example, $manifest['artifact_policy']['placeholder_version_examples']);
        }

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
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
            'topology',
            'query_verdicts',
            'codec_round_trips',
            'latency_distribution',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_manifest_names_full_search_attribute_parity_matrix(): void
    {
        $manifest = SearchAttributeRuntimeContract::manifest();
        $matrix = $manifest['required_matrix'];

        $this->assertSame(['workflow-php', 'sdk-python'], $matrix['runtimes']);
        $this->assertContains('cli', $matrix['client_paths']);
        $this->assertContains('workflow-php-sdk', $matrix['client_paths']);
        $this->assertContains('sdk-python', $matrix['client_paths']);
        $this->assertContains('waterline-workflow-list-filter', $matrix['observer_paths']);
        $this->assertContains('keyword_list', $matrix['type_cells']);
        $this->assertSame(
            ['encoded_payload', 'wire_value_context'],
            $manifest['scenario_requirements']['python_to_php_codec_round_trip']['payload_context_fields'],
        );
        $this->assertSame(
            ['string', 'int', 'double', 'bool', 'datetime', 'keyword', 'keyword_list'],
            $manifest['scenario_requirements']['php_to_python_codec_round_trip']['required_value_types'],
        );

        $this->assertContains(
            [
                'worker' => 'workflow-php',
                'clients' => ['cli', 'workflow-php-sdk'],
                'scenario' => 'php_worker_start_and_upsert_visibility',
            ],
            $matrix['runtime_cells'],
        );
        $this->assertContains(
            [
                'writer' => 'sdk-python',
                'readers' => ['workflow-php-sdk', 'cli'],
                'scenario' => 'python_to_php_codec_round_trip',
            ],
            $matrix['cross_language_cells'],
        );
    }

    public function test_manifest_keeps_smoke_only_coverage_non_passing(): void
    {
        $manifest = SearchAttributeRuntimeContract::manifest();
        $gate = $manifest['coverage_gate'];

        $this->assertContains('not_covered', $manifest['scenario_statuses']);
        $this->assertSame('non_passing', $gate['uncovered_required_scenario_outcome']);
        $this->assertSame('non_passing', $gate['smoke_subset_outcome']);

        foreach ([
            'php_worker_start_and_upsert_visibility',
            'cli_query_and_error_surface',
            'waterline_operator_visibility',
            'python_to_php_codec_round_trip',
            'php_to_python_codec_round_trip',
            'or_not_query_grammar',
            'indexing_latency_distribution',
            'load_and_bounded_latency',
            'query_injection_hardening',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
        }

        foreach ([
            'all_required_scenarios_reported',
            'all_required_runtimes_present',
            'cross_language_cells_reported',
            'cli_surface_reported',
            'waterline_operator_visibility_reported',
            'codec_round_trips_reported',
            'codec_round_trips_include_encoded_payload_or_wire_value_context',
            'load_latency_reported',
            'or_not_grammar_reported',
            'query_injection_hardening_reported',
            'findings_linked_for_non_pass_scenarios',
        ] as $requirement) {
            $this->assertContains($requirement, $gate['passing_outcome_requires']);
        }
    }

    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = SearchAttributeRuntimeContract::manifest()['result_gate'];

        $this->assertSame(SearchAttributeRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(3, SearchAttributeRuntimeResultGate::VERSION);
        $this->assertSame(SearchAttributeRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            SearchAttributeRuntimeContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertContains('published_artifact_versions', $resultGate['artifact_versions_fields']);
        $this->assertSame(['outcome', 'status', 'verdict'], $resultGate['declared_outcome_fields']);
        $this->assertContains('runtime_and_cross_language_cells_are_reported', $resultGate['pass_requires']);
        $this->assertContains(
            'cli_waterline_codec_load_grammar_and_injection_sections_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'codec_round_trips_include_encoded_payload_or_wire_value_context',
            $resultGate['pass_requires'],
        );
        $this->assertContains('query_verdict_expected_and_actual_counts_match', $resultGate['pass_requires']);
        $this->assertContains(
            'query_injection_required_rejection_probes_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains('overall_outcome_matches_gate_status', $resultGate['pass_requires']);
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
    }

    public function test_result_gate_rejects_python_smoke_subset_even_when_the_smoke_passes(): void
    {
        $evaluation = SearchAttributeRuntimeResultGate::evaluate([
            'schema' => SearchAttributeRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.154',
                'cli' => '0.1.53',
                'sdk-python' => '0.4.65',
                'workflow' => '2.0.0-alpha.166',
                'waterline' => '2.0.0-alpha.57',
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-python'],
                'runtime_cells' => [
                    [
                        'scenario' => 'python_worker_start_and_upsert_visibility',
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                    ],
                ],
            ],
            'scenario_results' => [
                [
                    'scenario_id' => 'python_worker_start_and_upsert_visibility',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'workflow_count' => 10,
                    ],
                ],
            ],
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('php_worker_start_and_upsert_visibility', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['scenario_results']['waterline_operator_visibility']['status'] = 'fail';
        unset($result['scenario_results']['waterline_operator_visibility']['linked_findings']);

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('waterline_operator_visibility', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_latency_distribution_fields(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset($result['latency_distribution']['p95_ms']);

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_latency_distribution_field',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_generic_pass_outputs_for_required_scenario_evidence(): void
    {
        $result = $this->completeSearchAttributeResult();
        foreach ([
            'published_artifact_install_only',
            'schema_definition_and_reserved_name_refusal',
            'cli_query_and_error_surface',
            'python_to_php_codec_round_trip',
            'php_to_python_codec_round_trip',
            'type_safety_wrong_literal',
            'undefined_key_rejection',
            'namespace_isolation',
        ] as $scenario) {
            $result['scenario_results'][$scenario]['observed_outputs'] = [
                'recorded' => true,
            ];
        }

        $result['artifact_sources'] = [];
        $result['topology']['schema_keys'] = [];
        $result['topology']['reserved_name_refusals'] = [];
        $result['cli_surface'] = [];
        $result['codec_round_trips'] = [];
        $result['type_safety_errors'] = [];
        $result['namespace_isolation'] = [];

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_published_artifact_install_source', $failureCodes);
        $this->assertContains('missing_schema_type_evidence', $failureCodes);
        $this->assertContains('missing_reserved_name_refusal_evidence', $failureCodes);
        $this->assertContains('missing_cli_surface_evidence', $failureCodes);
        $this->assertContains('missing_codec_round_trip_field', $failureCodes);
        $this->assertContains('missing_type_safety_error_evidence', $failureCodes);
        $this->assertContains('missing_namespace_isolation_field', $failureCodes);
    }

    public function test_result_gate_accepts_wire_value_context_for_codec_round_trips(): void
    {
        $result = $this->completeSearchAttributeResult();

        unset($result['codec_round_trips']['python_to_php']['encoded_payload']);
        unset($result['codec_round_trips']['php_to_python']['encoded_payload']);

        $result['codec_round_trips']['python_to_php']['wire_value_context'] = [
            'writer' => 'sdk-python',
            'storage_surface' => 'workflow_search_attributes',
            'wire_values' => [
                'customer_id' => ['value_string' => 'cust-7'],
                'order_total_cents' => ['value_int' => 9250],
                'discount_ratio' => ['value_double' => 0.125],
                'priority_tier' => ['value_keyword' => 'gold'],
                'is_vip' => ['value_bool' => true],
                'created_at' => ['value_datetime' => '2026-05-20T12:00:00Z'],
                'tags' => ['value_keyword_list' => ['urgent', 'renewal']],
            ],
        ];
        $result['codec_round_trips']['php_to_python']['wire_value_context'] = [
            'writer' => 'workflow-php',
            'storage_surface' => 'workflow_search_attributes',
            'wire_values' => [
                'customer_id' => ['value_string' => 'cust-7'],
                'order_total_cents' => ['value_int' => 9250],
                'discount_ratio' => ['value_double' => 0.125],
                'priority_tier' => ['value_keyword' => 'gold'],
                'is_vip' => ['value_bool' => true],
                'created_at' => ['value_datetime' => '2026-05-20T12:00:00Z'],
                'tags' => ['value_keyword_list' => ['urgent', 'renewal']],
            ],
        ];

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $evaluation['status']);
        $this->assertEmpty($evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_codec_round_trip_type_drift(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['codec_round_trips']['python_to_php']['decoded_attributes']['order_total_cents'] = '9250';

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'codec_decoded_attribute_type_mismatch',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_query_count_mismatches(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['query_verdicts']['or']['actual_count'] = 99;

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('query_count_mismatch', $failureCodes);
    }

    public function test_result_gate_requires_contract_injection_rejection_probes(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['adversarial_queries']['rejections'] = ['OR 1=1'];

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_required_injection_rejection_probe', $failureCodes);
        $this->assertContains(
            'shell metacharacters',
            array_column($evaluation['gate_failures'], 'probe'),
        );
    }

    public function test_result_gate_accepts_a_complete_passing_matrix(): void
    {
        $evaluation = SearchAttributeRuntimeResultGate::evaluate($this->completeSearchAttributeResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSearchAttributeResult(): array
    {
        $artifactSources = [
            'server' => 'published_docker_image',
            'cli' => 'official_install_script',
            'sdk-python' => 'pypi_release',
            'workflow' => 'composer_release',
            'waterline' => 'published_waterline_release',
        ];
        $schemaDefinitions = [
            'customer_id' => 'string',
            'order_total_cents' => 'int',
            'discount_ratio' => 'double',
            'priority_tier' => 'keyword',
            'is_vip' => 'bool',
            'created_at' => 'datetime',
            'tags' => 'keyword_list',
        ];
        $decodedAttributes = [
            'customer_id' => 'cust-7',
            'order_total_cents' => 7500,
            'discount_ratio' => 0.15,
            'priority_tier' => 'gold',
            'is_vip' => true,
            'created_at' => '2026-05-20T12:00:00Z',
            'tags' => ['urgent', 'renewal'],
        ];
        $scenarioResults = [];
        foreach (SearchAttributeRuntimeContract::manifest()['required_scenarios'] as $scenario) {
            $scenarioResults[$scenario] = [
                'scenario_id' => $scenario,
                'status' => 'pass',
                'observed_outputs' => [
                    'recorded' => true,
                ],
            ];
        }
        $scenarioResults['published_artifact_install_only']['observed_outputs']['artifact_sources'] = $artifactSources;
        $scenarioResults['schema_definition_and_reserved_name_refusal']['observed_outputs'] += [
            'schema_definitions' => $schemaDefinitions,
            'reserved_name_refusals' => [
                ['name' => 'wf_id', 'error_code' => 'reserved_search_attribute_name'],
                ['name' => '__internal', 'error_code' => 'reserved_search_attribute_name'],
            ],
        ];
        $scenarioResults['python_worker_start_and_upsert_visibility']['observed_outputs'] += [
            'workflow_id' => 'sa-python-1',
            'worker_runtime' => 'sdk-python',
            'start_search_attributes' => ['customer_id' => 'cust-7'],
            'upserted_search_attributes' => ['priority_tier' => 'gold'],
            'visibility_query_match' => true,
        ];
        $scenarioResults['php_worker_start_and_upsert_visibility']['observed_outputs'] += [
            'workflow_id' => 'sa-php-1',
            'worker_runtime' => 'workflow-php',
            'start_search_attributes' => ['customer_id' => 'cust-8'],
            'upserted_search_attributes' => ['priority_tier' => 'platinum'],
            'visibility_query_match' => true,
        ];
        $scenarioResults['cli_query_and_error_surface']['observed_outputs'] += [
            'workflow_list_query' => ['query' => 'customer_id = "cust-7"', 'actual_count' => 1],
            'search_attribute_list' => ['customer_id', 'order_total_cents'],
            'search_attribute_create' => ['name' => 'priority_tier', 'type' => 'keyword'],
            'search_attribute_delete' => ['name' => 'priority_tier_tmp', 'status' => 'deleted'],
            'typed_error_observed' => true,
        ];
        $scenarioResults['python_to_php_codec_round_trip']['observed_outputs']['python_to_php'] = [
            'encoded_payload' => 'base64:python-payload',
            'decoded_attributes' => $decodedAttributes,
            'reader_verifications' => [
                'workflow-php-sdk' => true,
                'cli' => true,
            ],
        ];
        $scenarioResults['php_to_python_codec_round_trip']['observed_outputs']['php_to_python'] = [
            'encoded_payload' => 'base64:php-payload',
            'decoded_attributes' => $decodedAttributes,
            'reader_verifications' => [
                'sdk-python' => true,
                'cli' => true,
            ],
        ];
        $scenarioResults['namespace_isolation']['observed_outputs'] += [
            'primary_namespace' => 'sa-test',
            'peer_namespace' => 'sa-test-b',
            'primary_query_count' => 1,
            'peer_query_count' => 0,
            'cross_namespace_leak_detected' => false,
        ];

        return [
            'schema' => SearchAttributeRuntimeContract::RESULT_SCHEMA,
            'outcome' => 'pass',
            'started_at' => '2026-05-20T12:00:00Z',
            'finished_at' => '2026-05-20T12:05:00Z',
            'generated_at' => '2026-05-20T12:05:01Z',
            'artifactVersions' => [
                'server' => '0.2.154',
                'cli' => '0.1.53',
                'sdk-python' => '0.4.65',
                'workflow' => '2.0.0-alpha.166',
                'waterline' => '2.0.0-alpha.57',
            ],
            'artifact_sources' => $artifactSources,
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python'],
                'runtime_cells' => [
                    [
                        'scenario' => 'python_worker_start_and_upsert_visibility',
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                    ],
                    [
                        'scenario' => 'php_worker_start_and_upsert_visibility',
                        'worker' => 'workflow-php',
                        'clients' => ['cli', 'workflow-php-sdk'],
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'scenario' => 'python_to_php_codec_round_trip',
                        'writer' => 'sdk-python',
                        'readers' => ['workflow-php-sdk', 'cli'],
                    ],
                    [
                        'scenario' => 'php_to_python_codec_round_trip',
                        'writer' => 'workflow-php',
                        'readers' => ['sdk-python', 'cli'],
                    ],
                ],
            ],
            'topology' => [
                'namespaces' => ['sa-test', 'sa-test-b'],
                'schema_keys' => $schemaDefinitions,
                'reserved_name_refusals' => [
                    ['name' => 'wf_id', 'error_code' => 'reserved_search_attribute_name'],
                    ['name' => '__internal', 'error_code' => 'reserved_search_attribute_name'],
                ],
            ],
            'query_verdicts' => [
                'equality' => ['expected_count' => 1, 'actual_count' => 1],
                'range' => ['expected_count' => 4, 'actual_count' => 4],
                'bool' => ['expected_count' => 5, 'actual_count' => 5],
                'or' => ['expected_count' => 2, 'actual_count' => 2],
                'not' => ['expected_count' => 3, 'actual_count' => 3],
                'keyword_list' => ['expected_count' => 3, 'actual_count' => 3],
            ],
            'type_safety_errors' => [
                'wrong_literal' => [
                    'error_code' => 'invalid_search_attribute_literal',
                    'message' => 'order_total_cents expects an integer literal.',
                    'accepted' => false,
                ],
                'undefined_key' => [
                    'error_code' => 'unknown_search_attribute',
                    'message' => 'unknown_attr is not defined in this namespace.',
                    'accepted' => false,
                ],
            ],
            'latency_distribution' => [
                'sample_count' => 20,
                'min_ms' => 8,
                'p50_ms' => 12,
                'p95_ms' => 40,
                'max_ms' => 48,
                'documented_bound_ms' => 5000,
            ],
            'load_profile' => [
                'workflow_count' => 1000,
                'p50_ms' => 14,
                'p95_ms' => 45,
                'max_ms' => 80,
            ],
            'waterline_operator_visibility' => [
                'workflow_list_filter' => true,
                'selected_run_detail' => true,
                'saved_filter_state' => true,
            ],
            'cli_surface' => [
                'workflow_list_query' => ['query' => 'customer_id = "cust-7"', 'actual_count' => 1],
                'search_attribute_list' => ['customer_id', 'order_total_cents', 'discount_ratio'],
                'search_attribute_create' => ['name' => 'priority_tier_tmp', 'type' => 'keyword'],
                'search_attribute_delete' => ['name' => 'priority_tier_tmp', 'status' => 'deleted'],
                'typed_error_observed' => true,
            ],
            'codec_round_trips' => [
                'python_to_php' => [
                    'encoded_payload' => 'base64:python-payload',
                    'decoded_attributes' => $decodedAttributes,
                    'reader_verifications' => [
                        'workflow-php-sdk' => true,
                        'cli' => true,
                    ],
                ],
                'php_to_python' => [
                    'encoded_payload' => 'base64:php-payload',
                    'decoded_attributes' => $decodedAttributes,
                    'reader_verifications' => [
                        'sdk-python' => true,
                        'cli' => true,
                    ],
                ],
            ],
            'namespace_isolation' => [
                'primary_namespace' => 'sa-test',
                'peer_namespace' => 'sa-test-b',
                'primary_query_count' => 1,
                'peer_query_count' => 0,
                'cross_namespace_leak_detected' => false,
            ],
            'adversarial_queries' => [
                'injection_rejected' => true,
                'rejections' => [
                    'customer_id = "x" OR 1=1',
                    'customer_id = "x" -- embedded SQL comment',
                    'customer_id = "x"; rm -rf /',
                ],
                'partial_execution_observed' => false,
            ],
            'findings' => [],
            'finding_links' => [],
            'scenario_results' => $scenarioResults,
        ];
    }
}
