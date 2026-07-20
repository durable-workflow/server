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
        $this->assertSame(16, SearchAttributeRuntimeContract::VERSION);
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

        foreach (['server', 'cli', 'sdk-php', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertContains(
            'local_product_source_checkout',
            $manifest['artifact_policy']['forbidden_sources'],
        );

        foreach ([
            'artifact_versions',
            'run_id',
            'started_at',
            'finished_at',
            'generated_at',
            'outcome',
            'runner_blocked',
            'scenario_results',
            'findings',
            'finding_links',
            'topology',
            'query_verdicts',
            'codec_round_trips',
            'latency_distribution',
            'load_profile',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_manifest_names_full_search_attribute_parity_matrix(): void
    {
        $manifest = SearchAttributeRuntimeContract::manifest();
        $matrix = $manifest['required_matrix'];

        $this->assertSame(['sdk-php', 'sdk-python'], $matrix['runtimes']);
        $this->assertContains('cli', $matrix['client_paths']);
        $this->assertContains('sdk-php', $matrix['client_paths']);
        $this->assertContains('sdk-python', $matrix['client_paths']);
        $this->assertContains('waterline-workflow-list-filter', $matrix['observer_paths']);
        $this->assertContains('keyword_list', $matrix['type_cells']);
        $this->assertSame(
            ['encoded_payload', 'wire_value_context'],
            $manifest['scenario_requirements']['python_to_php_codec_round_trip']['payload_context_fields'],
        );
        $this->assertSame(
            ['written_attributes', 'decoded_attributes', 'reader_verifications'],
            $manifest['scenario_requirements']['python_to_php_codec_round_trip']['required_evidence_fields'],
        );
        $this->assertSame(
            ['string', 'int', 'double', 'bool', 'datetime', 'keyword', 'keyword_list'],
            $manifest['scenario_requirements']['php_to_python_codec_round_trip']['required_value_types'],
        );
        $this->assertSame(
            ['p95_ms', 'max_ms'],
            $manifest['scenario_requirements']['indexing_latency_distribution']['documented_bound_compared_fields'],
        );
        $this->assertSame(
            ['consistency_contract', 'observed_bounds', 'public_observation_surfaces'],
            $manifest['scenario_requirements']['indexing_latency_distribution']['required_evidence_fields'],
        );
        $this->assertSame(
            ['documented_bound_ms', 'p95_ms', 'max_ms'],
            $manifest['scenario_requirements']['indexing_latency_distribution']['required_observed_bound_fields'],
        );
        $this->assertSame(
            ['equality', 'range', 'bool', 'keyword_list'],
            $manifest['scenario_requirements']['load_and_bounded_latency']['required_query_latency_classes'],
        );
        $this->assertSame(
            ['p50_ms', 'p95_ms', 'max_ms'],
            $manifest['scenario_requirements']['load_and_bounded_latency']['required_query_latency_fields'],
        );
        $this->assertSame(
            ['consistency_contract', 'observed_bounds', 'public_observation_surfaces'],
            $manifest['scenario_requirements']['load_and_bounded_latency']['required_evidence_fields'],
        );
        $this->assertSame(
            ['workflow_count', 'p50_ms', 'p95_ms', 'max_ms'],
            $manifest['scenario_requirements']['load_and_bounded_latency']['required_observed_bound_fields'],
        );

        $this->assertContains(
            [
                'worker' => 'sdk-php',
                'clients' => ['cli', 'sdk-php'],
                'scenario' => 'php_worker_start_and_upsert_visibility',
            ],
            $matrix['runtime_cells'],
        );
        $this->assertContains(
            [
                'writer' => 'sdk-python',
                'readers' => ['sdk-php', 'cli'],
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
            'codec_round_trips_compare_written_or_wire_values_to_decoded_attributes',
            'load_latency_reported',
            'indexing_latency_p95_and_max_compared_to_documented_bound',
            'load_latency_reported_per_query_class',
            'latency_and_load_evidence_names_consistency_contract',
            'latency_and_load_evidence_records_public_observation_surfaces',
            'latency_and_load_evidence_records_run_id_and_observed_bounds',
            'or_not_grammar_reported_with_exact_query_counts_and_public_surface',
            'query_injection_hardening_reported_with_status_and_response_body',
            'runner_blocked_false_for_product_evidence',
            'findings_linked_for_non_pass_scenarios',
        ] as $requirement) {
            $this->assertContains($requirement, $gate['passing_outcome_requires']);
        }
    }

    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = SearchAttributeRuntimeContract::manifest()['result_gate'];

        $this->assertSame(SearchAttributeRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(13, SearchAttributeRuntimeResultGate::VERSION);
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
        $this->assertContains(
            'codec_round_trips_compare_written_or_wire_values_to_decoded_attributes',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'query_verdict_exact_query_expected_and_actual_counts_match',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'or_not_query_verdicts_include_public_surface_and_command_arguments',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'query_injection_required_rejection_probes_status_and_response_are_reported',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'waterline_operator_visibility_includes_operator_surface_matrix',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'indexing_latency_p95_and_max_do_not_exceed_documented_bound',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'load_latency_reported_for_equality_range_bool_and_keyword_list_filters',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'latency_and_load_evidence_names_consistency_contract',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'latency_and_load_evidence_records_public_observation_surfaces',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'latency_and_load_evidence_records_run_id_and_observed_bounds',
            $resultGate['pass_requires'],
        );
        $this->assertContains('runner_blocked_false_for_product_evidence', $resultGate['pass_requires']);
        $this->assertContains('overall_outcome_matches_gate_status', $resultGate['pass_requires']);
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
    }

    public function test_manifest_publishes_source_free_host_runner_handoff(): void
    {
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];

        $this->assertSame('required_for_passing_search_attributes_conformance', $runner['status']);
        $this->assertSame('server', $runner['runner_repository']);
        $this->assertSame(
            'scripts/conformance/search-attributes-published-artifacts.sh',
            $runner['runner_path'],
        );
        $runnerPath = dirname(__DIR__, 2).'/'.$runner['runner_path'];
        $this->assertFileExists(
            $runnerPath,
            'cluster info must not advertise a search-attributes host runner path that is missing from the release tree',
        );
        $this->assertTrue(is_executable($runnerPath), 'the advertised search-attributes host runner must be executable');
        $this->assertContains('sdk-php-search-attributes-shard.json', $runner['result_files']);
        $this->assertContains('waterline-search-attributes-shard.json', $runner['result_files']);
        $this->assertContains('codec-round-trip-shard.json', $runner['result_files']);
        $this->assertTrue($runner['must_execute_against_published_artifacts']);
        $this->assertTrue($runner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertContains('waterline-operator-search-attribute-shard', $runner['required_execution_scopes']);
        $this->assertContains('cross-language-codec-shard', $runner['required_execution_scopes']);

        $waterline = $runner['runtime_shards']['waterline'];
        $this->assertSame('durable-workflow/waterline', $waterline['artifact']);
        $this->assertSame('waterline:search-attributes-conformance', $waterline['artisan_command']);
        $this->assertContains('waterline_operator_visibility', $waterline['must_cover_scenarios']);
        $this->assertContains('workflow_list_filter.expected_count', $waterline['must_capture_fields']);
        $this->assertContains('workflow_list_filter.actual_count', $waterline['must_capture_fields']);
        $this->assertContains('selected_run_detail.actual_search_attributes', $waterline['must_capture_fields']);
        $this->assertContains('saved_filter_state.retrieved_filters', $waterline['must_capture_fields']);
        $this->assertContains('namespace_isolation.tenant_b_filter_actual_run_ids', $waterline['must_capture_fields']);
        $this->assertSame(
            'conformance_harness',
            $runner['routing_policy']['waterline_shard_not_invoked']['owner'],
        );
        $this->assertSame(
            'waterline',
            $runner['routing_policy']['waterline_operator_mismatch']['owner'],
        );

        $codec = $runner['runtime_shards']['codec'];
        $this->assertSame('cross-language-codec-shard', $codec['scope']);
        $this->assertContains('DW_SEARCH_ATTRIBUTES_CODEC_SHARD_FILE', $codec['input_environment']);
        $this->assertSame('codec-round-trip-shard.json', $codec['result_file']);
        $this->assertContains('python_to_php_codec_round_trip', $codec['must_cover_scenarios']);
        $this->assertContains('php_to_python_codec_round_trip', $codec['must_cover_scenarios']);
        $this->assertContains('python_to_php.reader_verifications.sdk-php', $codec['must_capture_fields']);
        $this->assertContains('php_to_python.reader_verifications.sdk-python', $codec['must_capture_fields']);
        $this->assertContains('keyword_list', $codec['required_value_types']);

        $sdkPhp = $runner['runtime_shards']['sdk-php'];
        $this->assertSame('durable-workflow/sdk', $sdkPhp['artifact']);
        $this->assertSame(
            'scripts/conformance/php-sdk-published-artifacts.sh --scope search-attributes --result-dir <result-dir>',
            $sdkPhp['runner_command'],
        );
        $this->assertSame('sdk-php-search-attributes-shard.json', $sdkPhp['result_file']);
        $this->assertSame('durable-workflow/sdk', $sdkPhp['package_ownership']['standalone_connectivity']);
        $this->assertFalse($sdkPhp['package_ownership']['workflow_standalone_client_or_worker_loaded']);
        $this->assertContains('typed_values', $sdkPhp['must_capture_fields']);
        $this->assertContains('query_visibility', $sdkPhp['must_capture_fields']);
        $this->assertContains('namespace_isolation', $sdkPhp['must_capture_fields']);
        $this->assertContains('codec_round_trips.python_to_php', $sdkPhp['must_capture_fields']);
        $this->assertContains('codec_round_trips.php_to_python', $sdkPhp['must_capture_fields']);
        $this->assertSame(20, $sdkPhp['bounded_evidence']['matched_workflow_ids_max_items']);
        $this->assertSame(
            'conformance_harness',
            $runner['routing_policy']['sdk_php_shard_not_invoked']['owner'],
        );
    }

    public function test_published_artifact_runner_writes_gate_consumable_runner_blocked_record(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);

        try {
            $command = implode(' ', [
                'DW_SERVER_VERSION=0.2.224',
                'DW_CLI_VERSION=0.1.74',
                'DW_PYTHON_SDK_VERSION=0.4.84',
                'DW_PHP_SDK_VERSION=0.1.1',
                'DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.187',
                'DW_WATERLINE_VERSION=2.0.0-alpha.69',
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            foreach ($runner['result_files'] as $file) {
                $this->assertFileExists($resultDir.'/'.$file);
            }

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $waterlineShard = json_decode(
                (string) file_get_contents($resultDir.'/waterline-search-attributes-shard.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $codecShard = json_decode(
                (string) file_get_contents($resultDir.'/codec-round-trip-shard.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing_runner_blocked', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('non_passing_runner_blocked', $record['outcome']);
            $this->assertTrue($record['runnerBlocked']);
            $this->assertSame('runner_blocked', $waterlineShard['status']);
            $this->assertSame('runner_blocked', $codecShard['status']);

            $scenarioResults = array_column($result['scenario_results'], null, 'scenario_id');
            foreach (SearchAttributeRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                $this->assertArrayHasKey($scenarioId, $scenarioResults);
                $this->assertSame('runner_blocked', $scenarioResults[$scenarioId]['status']);
                $this->assertNotEmpty($scenarioResults[$scenarioId]['linked_findings']);
            }

            foreach (['python_to_php_codec_round_trip', 'php_to_python_codec_round_trip'] as $scenarioId) {
                $this->assertSame(
                    'cross-language-codec-shard',
                    $scenarioResults[$scenarioId]['observed_outputs']['required_execution_scope'],
                );
                $this->assertSame(
                    'cross-language-codec-shard',
                    $scenarioResults[$scenarioId]['linked_findings'][0]['required_execution_scope'],
                );
            }

            $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
            $failureCodes = array_column($evaluation['gate_failures'], 'code');

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains('missing_run_record_field', $failureCodes);
            $this->assertNotContains('missing_non_pass_finding', $failureCodes);
            $this->assertNotContains('invalid_declared_outcome', $failureCodes);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_writes_missing_sdk_php_runner_as_unsupported_surface(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $missingCommandReason = 'The public server image does not expose php-sdk-published-artifacts.sh.';

        try {
            $command = implode(' ', [
                'DW_SERVER_VERSION=0.2.224',
                'DW_CLI_VERSION=0.1.74',
                'DW_PYTHON_SDK_VERSION=0.4.84',
                'DW_PHP_SDK_VERSION=0.1.1',
                'DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.187',
                'DW_WATERLINE_VERSION=2.0.0-alpha.69',
                'DW_SEARCH_ATTRIBUTES_BLOCKED_REASON='.escapeshellarg($missingCommandReason),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            $sdkPhpShard = json_decode(
                (string) file_get_contents($resultDir.'/sdk-php-search-attributes-shard.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $scenario = $sdkPhpShard['scenario_results']['php_worker_start_and_upsert_visibility'];
            $finding = $scenario['linked_findings'][0];

            $this->assertSame('unsupported', $sdkPhpShard['status']);
            $this->assertFalse($sdkPhpShard['runner_blocked']);
            $this->assertSame('unsupported', $scenario['status']);
            $this->assertTrue($scenario['observed_outputs']['sdk_conformance_runner_missing']);
            $this->assertSame('scripts/conformance/php-sdk-published-artifacts.sh', $finding['diagnostic']['command']);
            $this->assertSame('unsupported_public_surface', $finding['finding_type']);
            $this->assertSame('sdk-php', $finding['owner']);
            $this->assertSame('sdk-php', $finding['owning_surface']);
            $this->assertSame('sdk-php-search-attribute-shard', $finding['required_execution_scope']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_accepts_focused_codec_shard_as_product_evidence(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $codecShardFile = $resultDir.'/codec-shard.json';

        try {
            file_put_contents(
                $codecShardFile,
                json_encode($this->completeCodecRoundTripShard(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $command = implode(' ', [
                'DW_SERVER_VERSION=0.2.398',
                'DW_CLI_VERSION=0.1.80',
                'DW_PYTHON_SDK_VERSION=0.4.88',
                'DW_PHP_SDK_VERSION=0.1.1',
                'DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.203',
                'DW_WATERLINE_VERSION=2.0.0-alpha.87',
                'DW_SEARCH_ATTRIBUTES_CODEC_SHARD_FILE='.escapeshellarg($codecShardFile),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $codecShard = json_decode(
                (string) file_get_contents($resultDir.'/codec-round-trip-shard.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('pass', $codecShard['status']);

            $scenarioResults = array_column($result['scenario_results'], null, 'scenario_id');
            $this->assertSame('pass', $scenarioResults['python_to_php_codec_round_trip']['status']);
            $this->assertSame('pass', $scenarioResults['php_to_python_codec_round_trip']['status']);
            $this->assertSame('not_covered', $scenarioResults['waterline_operator_visibility']['status']);
            $this->assertNotEmpty($scenarioResults['waterline_operator_visibility']['linked_findings']);
            $this->assertArrayNotHasKey('python_to_php_codec_round_trip', $result['finding_links']);
            $this->assertArrayNotHasKey('php_to_python_codec_round_trip', $result['finding_links']);

            $this->assertSame(
                ['urgent', 'renewal'],
                $result['codec_round_trips']['python_to_php']['decoded_attributes']['tags'],
            );
            $this->assertTrue(
                $result['codec_round_trips']['python_to_php']['reader_verifications']['sdk-php'],
            );
            $this->assertTrue(
                $result['codec_round_trips']['php_to_python']['reader_verifications']['sdk-python'],
            );

            $codecFailureCodes = array_column(
                array_values(array_filter(
                    $result['result_gate']['gate_failures'],
                    static fn (array $failure): bool => in_array(
                        $failure['scenario_id'] ?? null,
                        ['python_to_php_codec_round_trip', 'php_to_python_codec_round_trip'],
                        true,
                    ),
                )),
                'code',
            );
            $this->assertSame([], $codecFailureCodes);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_consumes_runtime_and_codec_cells_from_sdk_php_shard(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $sdkPhpShardFile = $resultDir.'/sdk-php-focused.json';
        $sdkPhpShard = $this->completeCodecRoundTripShard();
        $sdkPhpShard['schema'] = 'durable-workflow.v2.search-attribute-runtime.sdk-php-shard';
        $sdkPhpShard['package_ownership'] = [
            'standalone_connectivity' => 'durable-workflow/sdk',
            'embedded_engine' => 'durable-workflow/workflow',
            'workflow_standalone_client_or_worker_loaded' => false,
        ];
        $sdkPhpShard['scenario_results']['php_worker_start_and_upsert_visibility'] = [
            'scenario_id' => 'php_worker_start_and_upsert_visibility',
            'status' => 'pass',
            'observed_outputs' => [
                'workflow_id' => 'php-sdk-search-1',
                'worker_runtime' => 'sdk-php',
                'start_search_attributes' => ['customer_id' => 'cust-7'],
                'upserted_search_attributes' => ['priority_tier' => 'gold'],
                'visibility_query_match' => true,
            ],
            'linked_findings' => [],
        ];

        try {
            file_put_contents(
                $sdkPhpShardFile,
                json_encode($sdkPhpShard, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );
            $command = implode(' ', [
                'DW_SERVER_VERSION=0.2.693',
                'DW_CLI_VERSION=0.1.93',
                'DW_PYTHON_SDK_VERSION=0.4.103',
                'DW_PHP_SDK_VERSION=0.1.13',
                'DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.291',
                'DW_WATERLINE_VERSION=2.0.0-alpha.137',
                'DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_FILE='.escapeshellarg($sdkPhpShardFile),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);
            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));
            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $writtenShard = json_decode(
                (string) file_get_contents($resultDir.'/sdk-php-search-attributes-shard.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');

            $this->assertSame('pass', $scenarios['php_worker_start_and_upsert_visibility']['status']);
            $this->assertSame('pass', $scenarios['python_to_php_codec_round_trip']['status']);
            $this->assertSame('pass', $scenarios['php_to_python_codec_round_trip']['status']);
            $this->assertSame(
                'durable-workflow/sdk',
                $writtenShard['package_ownership']['standalone_connectivity'],
            );
            $this->assertFalse(
                $writtenShard['package_ownership']['workflow_standalone_client_or_worker_loaded'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_rejects_incomplete_supplied_pass_result(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);

        try {
            $command = implode(' ', [
                'DW_SEARCH_ATTRIBUTES_RESULT_JSON='.escapeshellarg(json_encode(['outcome' => 'pass'], JSON_THROW_ON_ERROR)),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('non_passing', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame('non_passing', $result['result_gate']['status']);
            $this->assertSame('non_passing', $record['resultGate']['status']);
            $this->assertContains(
                'placeholder_artifact_version',
                array_column($result['result_gate']['gate_failures'], 'code'),
            );

            $scenarioResults = array_column($result['scenario_results'], null, 'scenario_id');
            foreach (SearchAttributeRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                $this->assertArrayHasKey($scenarioId, $scenarioResults);
                $this->assertSame('not_covered', $scenarioResults[$scenarioId]['status']);
                $this->assertNotEmpty($scenarioResults[$scenarioId]['linked_findings']);
            }
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_accepts_gate_complete_supplied_pass_result(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $resultFile = $resultDir.'/supplied-search-attributes-pass.json';

        try {
            file_put_contents(
                $resultFile,
                json_encode($this->completeSearchAttributeResult(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $command = implode(' ', [
                'DW_SEARCH_ATTRIBUTES_RESULT_FILE='.escapeshellarg($resultFile),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-record.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('pass', $result['outcome']);
            $this->assertSame('pass', $record['outcome']);
            $this->assertSame('search-attributes-20260520T120000Z', $result['run_id']);
            $this->assertSame('search-attributes-20260520T120000Z', $record['runId']);
            $this->assertSame('pass', $result['result_gate']['status']);
            $this->assertSame('pass', $record['resultGate']['status']);
            $this->assertSame([], $result['result_gate']['gate_failures']);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_rejects_runner_blocked_supplied_pass_result(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $resultFile = $resultDir.'/supplied-search-attributes-runner-blocked-pass.json';

        try {
            $supplied = $this->completeSearchAttributeResult();
            $supplied['runner_blocked'] = true;

            file_put_contents(
                $resultFile,
                json_encode($supplied, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $command = implode(' ', [
                'DW_SEARCH_ATTRIBUTES_RESULT_FILE='.escapeshellarg($resultFile),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertTrue($result['runner_blocked']);
            $this->assertSame('non_passing', $result['result_gate']['status']);
            $this->assertContains(
                'runner_blocked_result_is_not_product_evidence',
                array_column($result['result_gate']['gate_failures'], 'code'),
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_rejects_duplicate_supplied_scenario_results(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $resultFile = $resultDir.'/supplied-search-attributes-duplicate.json';

        try {
            $supplied = $this->completeSearchAttributeResult();
            $supplied['scenario_results'] = array_values($supplied['scenario_results']);
            $supplied['scenario_results'][] = $supplied['scenario_results'][0];

            file_put_contents(
                $resultFile,
                json_encode($supplied, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $command = implode(' ', [
                'DW_SEARCH_ATTRIBUTES_RESULT_FILE='.escapeshellarg($resultFile),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('non_passing', $result['result_gate']['status']);
            $this->assertSame(
                2,
                $result['result_gate']['duplicate_scenarios']['published_artifact_install_only'] ?? null,
            );
            $this->assertContains(
                'duplicate_scenario_result',
                array_column($result['result_gate']['gate_failures'], 'code'),
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_rejects_shallow_waterline_operator_visibility_evidence(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $resultFile = $resultDir.'/supplied-search-attributes-shallow-waterline.json';

        try {
            $supplied = $this->completeSearchAttributeResult();
            $supplied['waterline_operator_visibility'] = [
                'workflow_list_filter' => true,
                'selected_run_detail' => true,
                'saved_filter_state' => true,
            ];

            file_put_contents(
                $resultFile,
                json_encode($supplied, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $command = implode(' ', [
                'DW_SEARCH_ATTRIBUTES_RESULT_FILE='.escapeshellarg($resultFile),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $missingFields = array_column(
                array_values(array_filter(
                    $result['result_gate']['gate_failures'],
                    static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_waterline_operator_visibility_field',
                )),
                'field',
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('non_passing', $result['result_gate']['status']);
            $this->assertContains('workflow_list_filter.expected_count', $missingFields);
            $this->assertContains('selected_run_detail.actual_search_attributes', $missingFields);
            $this->assertContains('saved_filter_state.retrieved_filters', $missingFields);
            $this->assertContains('namespace_isolation.tenant_b_filter_actual_run_ids', $missingFields);
            $this->assertContains('api_captures', $missingFields);
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_published_artifact_runner_rejects_complete_waterline_visibility_without_surface_matrix(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $resultFile = $resultDir.'/supplied-search-attributes-missing-waterline-matrix.json';

        try {
            $supplied = $this->completeSearchAttributeResult();
            unset($supplied['waterline_operator_visibility']['operator_surface_matrix']);
            unset($supplied['scenario_results']['waterline_operator_visibility']['observed_outputs']['operator_surface_matrix']);

            file_put_contents(
                $resultFile,
                json_encode($supplied, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $command = implode(' ', [
                'DW_SEARCH_ATTRIBUTES_RESULT_FILE='.escapeshellarg($resultFile),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('non_passing', $result['result_gate']['status']);
            $this->assertContains(
                'missing_waterline_operator_surface_matrix',
                array_column($result['result_gate']['gate_failures'], 'code'),
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_manifest_names_focused_cli_surface_evidence_requirements(): void
    {
        $requirements = SearchAttributeRuntimeContract::manifest()['scenario_requirements']['cli_query_and_error_surface'];

        $this->assertSame(
            [
                'equality',
                'range',
                'bool',
                'or',
                'not',
                'keyword_list',
            ],
            array_keys($requirements['required_queries']),
        );
        $this->assertSame(['list', 'create', 'delete'], $requirements['required_definition_commands']);
        $this->assertArrayHasKey('wrong_literal', $requirements['required_diagnostics']);
        $this->assertArrayHasKey('injection', $requirements['required_diagnostics']);
        foreach (['command', 'arguments', 'stdout', 'stderr', 'exit_code'] as $field) {
            $this->assertContains($field, $requirements['command_transcript_required_fields']);
        }
        foreach (['error_code', 'message'] as $field) {
            $this->assertContains($field, $requirements['diagnostic_required_fields']);
        }
        $this->assertTrue($requirements['diagnostic_must_not_be_transport_failure']);
    }

    public function test_result_gate_rejects_python_smoke_subset_even_when_the_smoke_passes(): void
    {
        $evaluation = SearchAttributeRuntimeResultGate::evaluate([
            'schema' => SearchAttributeRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.154',
                'cli' => '0.1.53',
                'sdk-python' => '0.4.65',
                'sdk-php' => '0.1.1',
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

    public function test_result_gate_rejects_latency_distribution_above_documented_bound(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['latency_distribution']['documented_bound_ms'] = 5000;
        $result['latency_distribution']['p95_ms'] = 5500;
        $result['latency_distribution']['max_ms'] = 6200;

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'latency_distribution_exceeds_documented_bound',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertSame(
            ['p95_ms', 'max_ms'],
            array_column(
                array_values(array_filter(
                    $evaluation['gate_failures'],
                    static fn (array $failure): bool => ($failure['code'] ?? null) === 'latency_distribution_exceeds_documented_bound',
                )),
                'field',
            ),
        );
    }

    public function test_result_gate_requires_load_latency_per_query_class(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset($result['load_profile']['query_latencies']['range']);
        unset($result['load_profile']['query_latencies']['keyword_list']['p95_ms']);

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_load_query_latency_class',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'missing_load_query_latency_field',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_latency_and_load_contract_evidence(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset($result['run_id']);
        unset($result['latency_distribution']['consistency_contract']);
        unset($result['latency_distribution']['observed_bounds']['max_ms']);
        unset($result['latency_distribution']['public_observation_surfaces']);
        unset($result['load_profile']['consistency_contract']);
        unset($result['load_profile']['observed_bounds']);
        $result['load_profile']['public_observation_surfaces'] = ['ok'];

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_run_record_field', $failureCodes);
        $this->assertContains('missing_latency_consistency_contract', $failureCodes);
        $this->assertContains('missing_latency_observed_bound_field', $failureCodes);
        $this->assertContains('missing_latency_public_observation_surfaces', $failureCodes);
        $this->assertContains('missing_load_consistency_contract', $failureCodes);
        $this->assertContains('missing_load_observed_bounds', $failureCodes);
        $this->assertContains('missing_load_public_observation_surfaces', $failureCodes);
        $this->assertContains(
            'run_id',
            array_column(
                array_filter(
                    $evaluation['gate_failures'],
                    static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_field',
                ),
                'field',
            ),
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
        $this->assertContains('missing_cli_query_evidence', $failureCodes);
        $this->assertContains('missing_cli_definition_command_evidence', $failureCodes);
        $this->assertContains('missing_cli_diagnostic_evidence', $failureCodes);
        $this->assertContains('missing_codec_round_trip_field', $failureCodes);
        $this->assertContains('missing_type_safety_error_evidence', $failureCodes);
        $this->assertContains('missing_namespace_isolation_field', $failureCodes);
    }

    public function test_result_gate_accepts_wire_value_context_for_codec_round_trips(): void
    {
        $result = $this->completeSearchAttributeResult();

        unset($result['codec_round_trips']['python_to_php']['encoded_payload']);
        unset($result['codec_round_trips']['php_to_python']['encoded_payload']);
        unset($result['codec_round_trips']['python_to_php']['written_attributes']);
        unset($result['codec_round_trips']['php_to_python']['written_attributes']);

        $result['codec_round_trips']['python_to_php']['wire_value_context'] = [
            'writer' => 'sdk-python',
            'storage_surface' => 'workflow_search_attributes',
            'wire_values' => [
                'customer_id' => ['value_string' => 'cust-7'],
                'order_total_cents' => ['value_int' => 7500],
                'discount_ratio' => ['value_double' => 0.15],
                'priority_tier' => ['value_keyword' => 'gold'],
                'is_vip' => ['value_bool' => true],
                'created_at' => ['value_datetime' => '2026-05-20T12:00:00Z'],
                'tags' => ['value_keyword_list' => ['urgent', 'renewal']],
            ],
        ];
        $result['codec_round_trips']['php_to_python']['wire_value_context'] = [
            'writer' => 'sdk-php',
            'storage_surface' => 'workflow_search_attributes',
            'wire_values' => [
                'customer_id' => ['value_string' => 'cust-7'],
                'order_total_cents' => ['value_int' => 7500],
                'discount_ratio' => ['value_double' => 0.15],
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

    public function test_result_gate_rejects_codec_round_trip_value_drift(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['codec_round_trips']['php_to_python']['decoded_attributes']['tags'] = ['renewal', 'urgent'];

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'codec_decoded_attribute_value_mismatch',
            array_column($evaluation['gate_failures'], 'code'),
        );
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

    public function test_result_gate_rejects_incomplete_cli_query_and_diagnostic_evidence(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset($result['cli_surface']['workflow_list_queries']['keyword_list']);
        unset($result['cli_surface']['search_attribute_commands']['delete']['stderr']);
        $result['cli_surface']['diagnostics']['wrong_literal']['exit_code'] = 0;
        $result['cli_surface']['diagnostics']['injection']['error_code'] = 'transport_failure';

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_cli_query_evidence', $failureCodes);
        $this->assertContains('missing_cli_transcript_field', $failureCodes);
        $this->assertContains('cli_diagnostic_command_succeeded', $failureCodes);
        $this->assertContains('cli_diagnostic_collapsed_to_transport_failure', $failureCodes);
    }

    public function test_result_gate_rejects_shallow_waterline_operator_visibility_evidence(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['waterline_operator_visibility'] = [
            'workflow_list_filter' => true,
            'selected_run_detail' => true,
            'saved_filter_state' => true,
        ];

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $missingFields = array_column(
            array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_waterline_operator_visibility_field',
            )),
            'field',
        );

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('workflow_list_filter.expected_count', $missingFields);
        $this->assertContains('workflow_list_filter.actual_count', $missingFields);
        $this->assertContains('selected_run_detail.expected_search_attributes', $missingFields);
        $this->assertContains('selected_run_detail.actual_search_attributes', $missingFields);
        $this->assertContains('saved_filter_state.stored_filters', $missingFields);
        $this->assertContains('saved_filter_state.retrieved_filters', $missingFields);
        $this->assertContains('namespace_isolation.tenant_a_filter_actual_run_ids', $missingFields);
        $this->assertContains('namespace_isolation.tenant_b_filter_actual_run_ids', $missingFields);
        $this->assertContains('api_captures', $missingFields);
    }

    public function test_result_gate_requires_waterline_operator_surface_matrix(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset($result['waterline_operator_visibility']['operator_surface_matrix']);
        unset($result['scenario_results']['waterline_operator_visibility']['observed_outputs']['operator_surface_matrix']);

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('missing_waterline_operator_surface_matrix', $failureCodes);
    }

    public function test_result_gate_requires_explicit_runner_blocked_false_for_product_evidence(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset($result['runner_blocked']);

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('runner_blocked', $this->missingRunRecordFields($evaluation));

        $result = $this->completeSearchAttributeResult();
        $result['runner_blocked'] = 'false';

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('runner_blocked', $this->missingRunRecordFields($evaluation));

        $result = $this->completeSearchAttributeResult();
        $result['runner_blocked'] = true;

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_unproved_waterline_operator_surface_matrix_entries(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['waterline_operator_visibility']['operator_surface_matrix']['saved_filter_round_trip'] = false;
        unset($result['waterline_operator_visibility']['operator_surface_matrix']['namespace_scoped_visibility']);

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $surfaceFailureFields = array_column(
            array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'waterline_operator_surface_not_proved',
            )),
            'field',
        );

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('operator_surface_matrix.saved_filter_round_trip', $surfaceFailureFields);
        $this->assertContains('operator_surface_matrix.namespace_scoped_visibility', $surfaceFailureFields);
    }

    public function test_result_gate_rejects_keyed_cli_query_entry_with_mismatched_contract_query(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['cli_surface']['workflow_list_queries']['equality'] = $this->cliTranscript(
            ['workflows', 'list', '--query', 'customer_id = "cust-8"'],
            '{"workflows":[{"workflow_id":"sa-python-8"}]}',
            query: 'customer_id = "cust-8"',
            expectedCount: 1,
            actualCount: 1,
        );

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $matchingFailures = array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_cli_query_evidence'
                && ($failure['query_class'] ?? null) === 'equality'
                && ($failure['query'] ?? null) === 'customer_id = "cust-7"',
        );

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($matchingFailures);
    }

    public function test_result_gate_rejects_keyed_cli_diagnostic_entry_with_mismatched_contract_probe(): void
    {
        $result = $this->completeSearchAttributeResult();
        $result['cli_surface']['diagnostics']['wrong_literal'] = $this->cliTranscript(
            ['workflows', 'list', '--query', 'customer_id = "x" OR 1=1'],
            '',
            stderr: 'Server error: query parser rejected unsupported literal.',
            exitCode: 2,
        ) + [
            'error_code' => 'invalid_visibility_query',
            'message' => 'query parser rejected unsupported literal.',
        ];

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $matchingFailures = array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_cli_diagnostic_evidence'
                && ($failure['diagnostic'] ?? null) === 'wrong_literal'
                && ($failure['probe'] ?? null) === 'order_total_cents = "not-a-number"',
        );

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($matchingFailures);
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

    public function test_result_gate_requires_exact_query_text_on_query_verdicts(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset($result['query_verdicts']['or']['query']);

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $matchingFailures = array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_query_verdict_query'
                && ($failure['query_class'] ?? null) === 'or'
                && ($failure['query'] ?? null) === 'customer_id = "cust-2" OR customer_id = "cust-8"',
        );

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($matchingFailures);
    }

    public function test_result_gate_requires_public_surface_evidence_on_or_not_query_verdicts(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset($result['query_verdicts']['or']['public_surface'], $result['query_verdicts']['or']['arguments']);
        unset($result['query_verdicts']['not']['public_surface'], $result['query_verdicts']['not']['arguments']);

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $matchingFailures = array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_query_public_surface',
        );

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(
            [
                ['or', 'public_surface'],
                ['or', 'arguments'],
                ['not', 'public_surface'],
                ['not', 'arguments'],
            ],
            array_values(array_map(
                static fn (array $failure): array => [$failure['query_class'], $failure['field']],
                $matchingFailures,
            )),
        );
    }

    public function test_result_gate_requires_injection_rejection_status_and_response_body(): void
    {
        $result = $this->completeSearchAttributeResult();
        unset(
            $result['adversarial_queries']['rejections']['tautology']['status_code'],
            $result['adversarial_queries']['rejections']['tautology']['response_body'],
        );

        $evaluation = SearchAttributeRuntimeResultGate::evaluate($result);
        $matchingFailures = array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_injection_rejection_field'
                && ($failure['probe'] ?? null) === 'OR 1=1',
        );

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(['status_code', 'response_body'], array_values(array_column($matchingFailures, 'field')));
    }

    public function test_published_artifact_runner_rejects_missing_or_not_public_surface_evidence(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise the search-attributes runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = SearchAttributeRuntimeContract::manifest()['host_runner_contract'];
        $resultDir = sys_get_temp_dir().'/dw-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir);
        $resultFile = $resultDir.'/supplied-search-attributes-missing-query-public-surface.json';

        try {
            $supplied = $this->completeSearchAttributeResult();
            unset($supplied['query_verdicts']['or']['public_surface'], $supplied['query_verdicts']['or']['arguments']);

            file_put_contents(
                $resultFile,
                json_encode($supplied, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
            );

            $command = implode(' ', [
                'DW_SEARCH_ATTRIBUTES_RESULT_FILE='.escapeshellarg($resultFile),
                escapeshellarg($repoRoot.'/'.$runner['runner_path']),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(1, $exitCode, implode("\n", $output));

            $result = json_decode(
                (string) file_get_contents($resultDir.'/search-attributes-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('non_passing', $result['result_gate']['status']);
            $this->assertContains(
                'missing_query_public_surface',
                array_column($result['result_gate']['gate_failures'], 'code'),
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
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
     * @param  array<string, mixed>  $evaluation
     * @return list<string>
     */
    private function missingRunRecordFields(array $evaluation): array
    {
        return array_values(array_filter(array_map(
            static fn (array $failure): string => ($failure['code'] ?? null) === 'missing_run_record_field'
                ? (string) ($failure['field'] ?? '')
                : '',
            $evaluation['gate_failures'] ?? [],
        )));
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function cliTranscript(
        array $arguments,
        string $stdout,
        string $stderr = '',
        int $exitCode = 0,
        ?string $query = null,
        ?int $expectedCount = null,
        ?int $actualCount = null,
    ): array {
        $entry = [
            'command' => 'dw',
            'arguments' => $arguments,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];

        if ($query !== null) {
            $entry['query'] = $query;
        }

        if ($expectedCount !== null) {
            $entry['expected_count'] = $expectedCount;
        }

        if ($actualCount !== null) {
            $entry['actual_count'] = $actualCount;
        }

        return $entry;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeCodecRoundTripShard(): array
    {
        $decodedAttributes = [
            'customer_id' => 'cust-7',
            'order_total_cents' => 7500,
            'discount_ratio' => 0.15,
            'priority_tier' => 'gold',
            'is_vip' => true,
            'created_at' => '2026-05-20T12:00:00Z',
            'tags' => ['urgent', 'renewal'],
        ];

        return [
            'schema' => 'durable-workflow.v2.search-attribute-runtime.codec-shard',
            'status' => 'pass',
            'codec_round_trips' => [
                'python_to_php' => [
                    'wire_value_context' => [
                        'storage_surface' => 'workflow_search_attributes',
                        'wire_values' => [
                            'customer_id' => ['type' => 'string', 'value_string' => 'cust-7'],
                            'order_total_cents' => ['type' => 'int', 'value_int' => 7500],
                            'discount_ratio' => ['type' => 'double', 'value_double' => 0.15],
                            'priority_tier' => ['type' => 'keyword', 'value_keyword' => 'gold'],
                            'is_vip' => ['type' => 'bool', 'value_bool' => true],
                            'created_at' => ['type' => 'datetime', 'value_datetime' => '2026-05-20T12:00:00Z'],
                            'tags' => ['type' => 'keyword_list', 'value_keyword_list' => ['urgent', 'renewal']],
                        ],
                    ],
                    'decoded_attributes' => $decodedAttributes,
                    'reader_verifications' => [
                        'sdk-php' => true,
                        'cli' => true,
                    ],
                ],
                'php_to_python' => [
                    'encoded_payload' => 'json:php-search-attributes-fixture',
                    'written_attributes' => $decodedAttributes,
                    'decoded_attributes' => $decodedAttributes,
                    'reader_verifications' => [
                        'sdk-python' => true,
                        'cli' => true,
                    ],
                ],
            ],
        ];
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
            'sdk-php' => 'composer_release',
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
        $cliQueries = [
            'equality' => $this->cliTranscript(
                ['workflows', 'list', '--query', 'customer_id = "cust-7"'],
                '{"workflows":[{"workflow_id":"sa-python-7"}]}',
                query: 'customer_id = "cust-7"',
                expectedCount: 1,
                actualCount: 1,
            ),
            'range' => $this->cliTranscript(
                ['workflows', 'list', '--query', 'order_total_cents > 5000 AND order_total_cents <= 10000'],
                '{"workflows":[{"workflow_id":"sa-python-4"},{"workflow_id":"sa-python-5"},{"workflow_id":"sa-python-6"},{"workflow_id":"sa-python-7"}]}',
                query: 'order_total_cents > 5000 AND order_total_cents <= 10000',
                expectedCount: 4,
                actualCount: 4,
            ),
            'bool' => $this->cliTranscript(
                ['workflows', 'list', '--query', 'is_vip = true'],
                '{"workflows":[{"workflow_id":"sa-python-1"},{"workflow_id":"sa-python-3"},{"workflow_id":"sa-python-5"},{"workflow_id":"sa-python-7"},{"workflow_id":"sa-python-9"}]}',
                query: 'is_vip = true',
                expectedCount: 5,
                actualCount: 5,
            ),
            'or' => $this->cliTranscript(
                ['workflows', 'list', '--query', 'customer_id = "cust-2" OR customer_id = "cust-8"'],
                '{"workflows":[{"workflow_id":"sa-python-2"},{"workflow_id":"sa-python-8"}]}',
                query: 'customer_id = "cust-2" OR customer_id = "cust-8"',
                expectedCount: 2,
                actualCount: 2,
            ),
            'not' => $this->cliTranscript(
                ['workflows', 'list', '--query', 'priority_tier IN ("gold","platinum") AND NOT is_vip'],
                '{"workflows":[{"workflow_id":"sa-python-2"},{"workflow_id":"sa-python-4"},{"workflow_id":"sa-python-6"}]}',
                query: 'priority_tier IN ("gold","platinum") AND NOT is_vip',
                expectedCount: 3,
                actualCount: 3,
            ),
            'keyword_list' => $this->cliTranscript(
                ['workflows', 'list', '--query', 'tags = "urgent"'],
                '{"workflows":[{"workflow_id":"sa-python-1"},{"workflow_id":"sa-python-4"},{"workflow_id":"sa-python-8"}]}',
                query: 'tags = "urgent"',
                expectedCount: 3,
                actualCount: 3,
            ),
        ];
        $cliDefinitionCommands = [
            'list' => $this->cliTranscript(
                ['search-attributes', 'list'],
                '{"custom_attributes":{"customer_id":"string","order_total_cents":"int","priority_tier":"keyword"}}',
            ),
            'create' => $this->cliTranscript(
                ['search-attributes', 'create', 'priority_tier_tmp', 'keyword'],
                '{"name":"priority_tier_tmp","type":"keyword"}',
            ),
            'delete' => $this->cliTranscript(
                ['search-attributes', 'delete', 'priority_tier_tmp'],
                '{"name":"priority_tier_tmp","deleted":true}',
            ),
        ];
        $cliDiagnostics = [
            'wrong_literal' => $this->cliTranscript(
                ['workflows', 'list', '--query', 'order_total_cents = "not-a-number"'],
                '',
                stderr: 'Server error: order_total_cents expects an integer literal.',
                exitCode: 2,
            ) + [
                'error_code' => 'invalid_search_attribute_literal',
                'message' => 'order_total_cents expects an integer literal.',
            ],
            'injection' => $this->cliTranscript(
                ['workflows', 'list', '--query', 'customer_id = "x" OR 1=1'],
                '',
                stderr: 'Server error: query parser rejected unsupported literal.',
                exitCode: 2,
            ) + [
                'error_code' => 'invalid_visibility_query',
                'message' => 'query parser rejected unsupported literal.',
            ],
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
            'worker_runtime' => 'sdk-php',
            'start_search_attributes' => ['customer_id' => 'cust-8'],
            'upserted_search_attributes' => ['priority_tier' => 'platinum'],
            'visibility_query_match' => true,
        ];
        $scenarioResults['cli_query_and_error_surface']['observed_outputs'] += [
            'workflow_list_queries' => $cliQueries,
            'search_attribute_commands' => $cliDefinitionCommands,
            'diagnostics' => $cliDiagnostics,
        ];
        $scenarioResults['python_to_php_codec_round_trip']['observed_outputs']['python_to_php'] = [
            'encoded_payload' => 'base64:python-payload',
            'written_attributes' => $decodedAttributes,
            'decoded_attributes' => $decodedAttributes,
            'reader_verifications' => [
                'sdk-php' => true,
                'cli' => true,
            ],
        ];
        $scenarioResults['php_to_python_codec_round_trip']['observed_outputs']['php_to_python'] = [
            'encoded_payload' => 'base64:php-payload',
            'written_attributes' => $decodedAttributes,
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
        $waterlineRunIds = [
            'tenant-a-primary',
            'tenant-a-secondary',
        ];
        $waterlineForeignRunIds = [
            'tenant-b-foreign',
        ];
        $waterlineSavedFilter = [
            'search_attributes' => [
                'customer_id' => 'cust-7',
            ],
        ];
        $waterlineVisibility = [
            'namespaces' => [
                'a' => 'sa-test',
                'b' => 'sa-test-b',
            ],
            'workflow_list_filter' => [
                'path' => '/api/flows/running?search_attributes[customer_id]=cust-7',
                'status' => 200,
                'filter' => $waterlineSavedFilter['search_attributes'],
                'expected_count' => 2,
                'actual_count' => 2,
                'expected_run_ids' => $waterlineRunIds,
                'actual_run_ids' => $waterlineRunIds,
                'matched' => true,
                'visibility_filter_echo' => $waterlineSavedFilter['search_attributes'],
                'operator_scope' => ['namespace' => 'sa-test'],
                'foreign_run_absent' => true,
            ],
            'keyword_list_filter' => [
                'path' => '/api/flows/running?search_attributes[tags]=urgent',
                'status' => 200,
                'filter' => ['tags' => 'urgent'],
                'expected_count' => 1,
                'actual_count' => 1,
                'expected_run_ids' => ['tenant-a-primary'],
                'actual_run_ids' => ['tenant-a-primary'],
                'matched' => true,
                'visibility_filter_echo' => ['tags' => 'urgent'],
                'operator_scope' => ['namespace' => 'sa-test'],
            ],
            'selected_run_detail' => [
                'path' => '/api/flows/tenant-a-primary',
                'status' => 200,
                'run_id' => 'tenant-a-primary',
                'namespace' => 'sa-test',
                'expected_search_attributes' => $decodedAttributes,
                'actual_search_attributes' => $decodedAttributes,
                'expected_attributes_visible' => true,
                'operator_scope' => ['namespace' => 'sa-test'],
            ],
            'saved_filter_state' => [
                'saved_view_id' => 'saved-filter-1',
                'stored_filters' => $waterlineSavedFilter,
                'retrieved_filters' => $waterlineSavedFilter,
                'listed_filters' => $waterlineSavedFilter,
                'saved_view_show_status' => 200,
                'saved_view_list_status' => 200,
                'applied_list_status' => 200,
                'filter_preserved_on_retrieval' => true,
                'filter_preserved_on_list_retrieval' => true,
                'applied_expected_count' => 2,
                'applied_actual_count' => 2,
                'applied_expected_run_ids' => $waterlineRunIds,
                'applied_actual_run_ids' => $waterlineRunIds,
                'applied_filter_matched' => true,
                'applied_filter_echo' => $waterlineSavedFilter['search_attributes'],
                'operator_scope' => ['namespace' => 'sa-test'],
            ],
            'namespace_isolation' => [
                'tenant_a_namespace' => 'sa-test',
                'tenant_b_namespace' => 'sa-test-b',
                'tenant_a_filter_expected_run_ids' => $waterlineRunIds,
                'tenant_a_filter_actual_run_ids' => $waterlineRunIds,
                'tenant_b_filter_expected_run_ids' => $waterlineForeignRunIds,
                'tenant_b_filter_actual_run_ids' => $waterlineForeignRunIds,
                'tenant_a_excludes_tenant_b' => true,
                'tenant_b_excludes_tenant_a' => true,
                'tenant_b_filter_matched' => true,
                'tenant_a_operator_scope' => ['namespace' => 'sa-test'],
                'tenant_b_operator_scope' => ['namespace' => 'sa-test-b'],
            ],
            'operator_surface_matrix' => [
                'workflow_list_search_attribute_filter' => true,
                'keyword_list_search_attribute_filter' => true,
                'selected_run_search_attributes' => true,
                'saved_filter_round_trip' => true,
                'namespace_scoped_visibility' => true,
            ],
            'api_captures' => [
                'workflow_list_customer_filter' => [
                    'status' => 200,
                    'path' => '/api/flows/running?search_attributes[customer_id]=cust-7',
                    'json' => ['data' => [['run_id' => 'tenant-a-primary'], ['run_id' => 'tenant-a-secondary']]],
                ],
                'workflow_list_keyword_list_filter' => [
                    'status' => 200,
                    'path' => '/api/flows/running?search_attributes[tags]=urgent',
                    'json' => ['data' => [['run_id' => 'tenant-a-primary']]],
                ],
                'selected_run_detail' => [
                    'status' => 200,
                    'path' => '/api/flows/tenant-a-primary',
                    'json' => ['run_id' => 'tenant-a-primary', 'search_attributes' => $decodedAttributes],
                ],
                'saved_view_show' => [
                    'status' => 200,
                    'path' => '/api/saved-views/saved-filter-1',
                    'json' => ['filters' => $waterlineSavedFilter],
                ],
                'saved_view_list' => [
                    'status' => 200,
                    'path' => '/api/saved-views',
                    'json' => ['data' => [['id' => 'saved-filter-1', 'filters' => $waterlineSavedFilter]]],
                ],
                'saved_view_applied_workflow_list' => [
                    'status' => 200,
                    'path' => '/api/flows/running?saved_view=saved-filter-1',
                    'json' => ['data' => [['run_id' => 'tenant-a-primary'], ['run_id' => 'tenant-a-secondary']]],
                ],
                'foreign_namespace_workflow_list' => [
                    'status' => 200,
                    'path' => '/api/flows/running?search_attributes[customer_id]=cust-7',
                    'json' => ['data' => [['run_id' => 'tenant-b-foreign']]],
                ],
            ],
        ];
        $scenarioResults['waterline_operator_visibility']['observed_outputs'] = $waterlineVisibility;

        return [
            'schema' => SearchAttributeRuntimeContract::RESULT_SCHEMA,
            'outcome' => 'pass',
            'runner_blocked' => false,
            'run_id' => 'search-attributes-20260520T120000Z',
            'started_at' => '2026-05-20T12:00:00Z',
            'finished_at' => '2026-05-20T12:05:00Z',
            'generated_at' => '2026-05-20T12:05:01Z',
            'artifactVersions' => [
                'server' => '0.2.154',
                'cli' => '0.1.53',
                'sdk-python' => '0.4.65',
                'sdk-php' => '0.1.1',
                'workflow' => '2.0.0-alpha.166',
                'waterline' => '2.0.0-alpha.57',
            ],
            'artifact_sources' => $artifactSources,
            'runtime_matrix' => [
                'runtimes' => ['sdk-php', 'sdk-python'],
                'runtime_cells' => [
                    [
                        'scenario' => 'python_worker_start_and_upsert_visibility',
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                    ],
                    [
                        'scenario' => 'php_worker_start_and_upsert_visibility',
                        'worker' => 'sdk-php',
                        'clients' => ['cli', 'sdk-php'],
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'scenario' => 'python_to_php_codec_round_trip',
                        'writer' => 'sdk-python',
                        'readers' => ['sdk-php', 'cli'],
                    ],
                    [
                        'scenario' => 'php_to_python_codec_round_trip',
                        'writer' => 'sdk-php',
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
                'equality' => [
                    'query' => 'customer_id = "cust-7"',
                    'expected_count' => 1,
                    'actual_count' => 1,
                ],
                'range' => [
                    'query' => 'order_total_cents > 5000 AND order_total_cents <= 10000',
                    'expected_count' => 4,
                    'actual_count' => 4,
                ],
                'bool' => [
                    'query' => 'is_vip = true',
                    'expected_count' => 5,
                    'actual_count' => 5,
                ],
                'or' => [
                    'query' => 'customer_id = "cust-2" OR customer_id = "cust-8"',
                    'public_surface' => 'dw workflows list --query',
                    'command' => 'dw',
                    'arguments' => ['workflows', 'list', '--query', 'customer_id = "cust-2" OR customer_id = "cust-8"'],
                    'expected_count' => 2,
                    'actual_count' => 2,
                ],
                'not' => [
                    'query' => 'priority_tier IN ("gold","platinum") AND NOT is_vip',
                    'public_surface' => 'dw workflows list --query',
                    'command' => 'dw',
                    'arguments' => ['workflows', 'list', '--query', 'priority_tier IN ("gold","platinum") AND NOT is_vip'],
                    'expected_count' => 3,
                    'actual_count' => 3,
                ],
                'keyword_list' => [
                    'query' => 'tags = "urgent"',
                    'expected_count' => 3,
                    'actual_count' => 3,
                ],
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
                'consistency_contract' => 'A search attribute written by workflow code is queryable through public list/filter APIs within the documented five-second consistency window.',
                'public_observation_surfaces' => [
                    'dw workflows list --query',
                    'sdk-python workflow list query',
                ],
                'sample_count' => 20,
                'min_ms' => 8,
                'p50_ms' => 12,
                'p95_ms' => 40,
                'max_ms' => 48,
                'documented_bound_ms' => 5000,
                'observed_bounds' => [
                    'documented_bound_ms' => 5000,
                    'p95_ms' => 40,
                    'max_ms' => 48,
                ],
            ],
            'load_profile' => [
                'consistency_contract' => 'Under the load profile, public search-attribute filters continue to return only indexed matching workflows within the documented consistency window.',
                'public_observation_surfaces' => [
                    'dw workflows list --query',
                    'server workflow visibility list API',
                ],
                'workflow_count' => 1000,
                'p50_ms' => 14,
                'p95_ms' => 45,
                'max_ms' => 80,
                'observed_bounds' => [
                    'workflow_count' => 1000,
                    'p50_ms' => 14,
                    'p95_ms' => 45,
                    'max_ms' => 80,
                ],
                'query_latencies' => [
                    'equality' => [
                        'query' => 'customer_id = "cust-7"',
                        'p50_ms' => 12,
                        'p95_ms' => 35,
                        'max_ms' => 51,
                    ],
                    'range' => [
                        'query' => 'order_total_cents > 5000 AND order_total_cents <= 10000',
                        'p50_ms' => 16,
                        'p95_ms' => 44,
                        'max_ms' => 72,
                    ],
                    'bool' => [
                        'query' => 'is_vip = true',
                        'p50_ms' => 10,
                        'p95_ms' => 31,
                        'max_ms' => 49,
                    ],
                    'keyword_list' => [
                        'query' => 'tags = "urgent"',
                        'p50_ms' => 18,
                        'p95_ms' => 45,
                        'max_ms' => 80,
                    ],
                ],
            ],
            'waterline_operator_visibility' => $waterlineVisibility,
            'cli_surface' => [
                'workflow_list_queries' => $cliQueries,
                'search_attribute_commands' => $cliDefinitionCommands,
                'diagnostics' => $cliDiagnostics,
            ],
            'codec_round_trips' => [
                'python_to_php' => [
                    'encoded_payload' => 'base64:python-payload',
                    'written_attributes' => $decodedAttributes,
                    'decoded_attributes' => $decodedAttributes,
                    'reader_verifications' => [
                        'sdk-php' => true,
                        'cli' => true,
                    ],
                ],
                'php_to_python' => [
                    'encoded_payload' => 'base64:php-payload',
                    'written_attributes' => $decodedAttributes,
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
                    'tautology' => [
                        'query' => 'customer_id = "x" OR 1=1',
                        'status_code' => 422,
                        'response_body' => [
                            'errors' => [
                                'query' => ['Visibility query predicates must use: Field = literal.'],
                            ],
                        ],
                    ],
                    'sql_comment' => [
                        'query' => 'customer_id = "x" -- embedded SQL comment',
                        'status_code' => 422,
                        'response_body' => [
                            'errors' => [
                                'query' => ['Visibility query literal ["x" -- embedded SQL comment] is not valid.'],
                            ],
                        ],
                    ],
                    'shell_metacharacters' => [
                        'query' => 'customer_id = "x"; rm -rf /',
                        'status_code' => 422,
                        'response_body' => [
                            'errors' => [
                                'query' => ['Visibility query literal ["x"; rm -rf /] is not valid.'],
                            ],
                        ],
                    ],
                ],
                'partial_execution_observed' => false,
            ],
            'findings' => [],
            'finding_links' => [],
            'scenario_results' => $scenarioResults,
        ];
    }
}
