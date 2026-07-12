<?php

namespace Tests\Unit;

use App\Support\SignalQueryRuntimeContract;
use App\Support\SignalQueryRuntimeResultGate;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\PlatformConformanceSuite;

class SignalQueryRuntimeContractTest extends TestCase
{
    public function test_manifest_requires_published_artifacts_and_run_record_fields(): void
    {
        $manifest = SignalQueryRuntimeContract::manifest();

        $this->assertSame('durable-workflow.v2.signal-query-runtime.contract', $manifest['schema']);
        $this->assertSame(35, SignalQueryRuntimeContract::VERSION);
        $this->assertSame(SignalQueryRuntimeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow.v2.signal-query-runtime.result', $manifest['result_schema']);
        $this->assertSame('signal_query_runtime_contract', $manifest['fixture_category']);
        $this->assertSame(
            PlatformConformanceSuite::SCHEMA,
            $manifest['platform_conformance_suite_authority'],
        );
        $this->assertSame(
            'durable-workflow.v2.platform-conformance.runtime-scenarios',
            $manifest['scenario_manifest']['schema'],
        );
        $this->assertSame(
            'signal_query_runtime_contract',
            $manifest['scenario_manifest']['category'],
        );
        $this->assertSame(
            PlatformConformanceSuite::VERSION,
            $manifest['scenario_manifest']['suite_version'],
        );
        $this->assertSame(
            'https://durable-workflow.github.io/platform-conformance/signal-query-runtime-scenarios.json',
            $manifest['scenario_manifest']['public_path'],
        );
        $this->assertSame(
            'static/platform-conformance/signal-query-runtime-scenarios.json',
            $manifest['scenario_manifest']['source_path'],
        );

        $this->assertSame(
            'latest_published_artifacts_at_run_time',
            $manifest['artifact_policy']['version_source'],
        );
        $this->assertSame(
            'concrete_published_versions_pinned_at_run_time',
            $manifest['artifact_policy']['version_requirement'],
        );
        $this->assertTrue($manifest['artifact_policy']['placeholder_versions_rejected']);
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder', '<latest>'] as $example) {
            $this->assertContains($example, $manifest['artifact_policy']['placeholder_version_examples']);
        }

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'sdk-rust', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertSame(
            ['server', 'cli', 'sdk-python', 'sdk-rust'],
            $manifest['artifact_policy']['install_proof_artifacts'],
        );

        $this->assertSame(
            [
                'server' => 'published_docker_image',
                'cli' => 'published_cli_release',
                'workflow-php' => 'published_composer_package',
                'sdk-python' => 'published_pypi_package',
                'sdk-rust' => 'published_crates_io_package',
                'waterline' => 'published_waterline_artifact',
            ],
            $manifest['artifact_policy']['expected_sources'],
        );

        $this->assertContains(
            'local_product_source_checkout',
            $manifest['artifact_policy']['forbidden_sources'],
        );

        foreach ([
            'artifact_versions',
            'started_at',
            'finished_at',
            'outcome',
            'scenario_results',
            'findings',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $manifest['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_manifest_names_the_runtime_client_and_observer_matrix(): void
    {
        $matrix = SignalQueryRuntimeContract::manifest()['required_matrix'];

        $this->assertSame(['workflow-php', 'sdk-python', 'sdk-rust'], $matrix['runtimes']);
        $this->assertContains('cli', $matrix['client_paths']);
        $this->assertContains('workflow-php-sdk', $matrix['client_paths']);
        $this->assertContains('sdk-python', $matrix['client_paths']);
        $this->assertContains('sdk-rust', $matrix['client_paths']);
        $this->assertContains('waterline-selected-run-detail', $matrix['observer_paths']);
        $this->assertContains('waterline-query-action', $matrix['observer_paths']);

        $this->assertContains(
            [
                'worker' => 'sdk-python',
                'clients' => ['workflow-php-sdk', 'cli'],
                'scenario' => 'python_worker_php_facing_and_cli_clients',
            ],
            $matrix['cross_language_cells'],
        );
        $this->assertContains(
            [
                'worker' => 'workflow-php',
                'clients' => ['sdk-python', 'cli'],
                'scenario' => 'php_worker_python_and_cli_clients',
            ],
            $matrix['cross_language_cells'],
        );
    }

    public function test_manifest_requires_artifact_tuple_rust_cells_and_provenance(): void
    {
        $manifest = SignalQueryRuntimeContract::manifest();
        $rustScenarios = [
            'rust_worker_rust_php_python_clients',
            'python_worker_rust_client',
            'php_worker_rust_client',
            'rust_query_error_and_immutability',
            'rust_replayed_instance_state_query_after_cold_restart',
        ];

        foreach ($rustScenarios as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
            $evidence = $manifest['scenario_requirements'][$scenario]['evidence'];
            $this->assertContains('rust_sdk_version', $evidence);
            $this->assertContains('rust_crate_provenance.source', $evidence);
            $this->assertContains('rust_crate_provenance.resolved_version', $evidence);
            $this->assertContains('rust_crate_provenance.checksum', $evidence);
        }

        $rustShard = $manifest['host_runner_contract']['evidence_shards']['rust_published_artifact_matrix'];
        $this->assertSame($rustScenarios, $rustShard['must_cover_scenarios']);
        $this->assertSame('artifactVersions.sdk-rust', $rustShard['crate']['version_source']);
        $this->assertSame('={version}', $rustShard['crate']['cargo_requirement_template']);
        $this->assertTrue($rustShard['crate']['requires_exact_semver']);
        $this->assertSame('crates.io', $rustShard['crate']['source']);
        $this->assertSame(
            'snapshot_derived_transport_state',
            $rustShard['query_state_models']['rust_query_error_and_immutability'],
        );
        $this->assertSame(
            'replayed_workflow_instance_state',
            $rustShard['query_state_models']['rust_replayed_instance_state_query_after_cold_restart'],
        );
        $requirements = $manifest['scenario_requirements'];
        $this->assertSame(
            ['query_payload_decode_failed'],
            $requirements['rust_query_error_and_immutability']['allowed_reasons']['malformed_query_payload'],
        );
        $this->assertContains(
            'terminal_signal.rejection_reason',
            $requirements['rust_query_error_and_immutability']['evidence'],
        );
        $this->assertSame(
            ['rejected_unknown_query'],
            $requirements['rust_replayed_instance_state_query_after_cold_restart']['failed_query_allowed_reasons'],
        );
    }

    public function test_rust_client_scenario_evidence_requirements_match_the_host_runner(): void
    {
        $runnerRequirements = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
print(json.dumps(SCENARIO_REQUIRED_EVIDENCE))
PY);
        $manifestRequirements = SignalQueryRuntimeContract::manifest()['scenario_requirements'];

        foreach (['python_worker_rust_client', 'php_worker_rust_client'] as $scenario) {
            $this->assertSame(
                $manifestRequirements[$scenario]['evidence'],
                $runnerRequirements[$scenario],
                "Evidence requirements differ for {$scenario}.",
            );
        }
    }

    public function test_manifest_keeps_smoke_only_coverage_non_passing(): void
    {
        $manifest = SignalQueryRuntimeContract::manifest();
        $gate = $manifest['coverage_gate'];

        $this->assertContains('not_covered', $manifest['scenario_statuses']);
        $this->assertSame('non_passing', $gate['uncovered_required_scenario_outcome']);
        $this->assertSame('non_passing', $gate['smoke_subset_outcome']);

        foreach ([
            'all_required_scenarios_reported',
            'all_required_runtimes_present',
            'cross_language_cells_reported',
            'replay_timing_reported',
            'terminal_run_behavior_reported',
            'adversarial_errors_typed',
            'waterline_observer_comparison_reported',
            'omitted_required_scenarios_link_findings',
            'findings_linked_for_non_pass_scenarios',
        ] as $requirement) {
            $this->assertContains($requirement, $gate['passing_outcome_requires']);
        }

        foreach ([
            'published_artifact_install_only',
            'python_worker_cli_and_sdk_baseline',
            'php_worker_cli_and_sdk_baseline',
            'python_worker_php_facing_and_cli_clients',
            'php_worker_python_and_cli_clients',
            'rust_worker_rust_php_python_clients',
            'python_worker_rust_client',
            'php_worker_rust_client',
            'rust_query_error_and_immutability',
            'ordered_signal_delivery',
            'dedup_contract_observation',
            'signal_during_replay',
            'query_during_replay',
            'rust_replayed_instance_state_query_after_cold_restart',
            'completed_run_signal_and_query',
            'unknown_signal_and_query_errors',
            'malformed_signal_and_query_payloads',
            'waterline_operator_visibility',
        ] as $scenario) {
            $this->assertContains($scenario, $manifest['required_scenarios']);
        }
    }

    public function test_manifest_requires_actionable_diagnostics_for_replay_adversarial_and_observer_cases(): void
    {
        $requirements = SignalQueryRuntimeContract::manifest()['scenario_requirements'];

        $this->assertSame(
            'signal_applies_after_replay_consistent_point',
            $requirements['signal_during_replay']['required_behavior'],
        );
        foreach (['signal_api_sample', 'signal_status_code', 'signal_applied_at'] as $field) {
            $this->assertContains($field, $requirements['signal_during_replay']['evidence']);
        }
        $this->assertContains(
            'signal_sent_at < replay_completed_at',
            $requirements['signal_during_replay']['timestamp_order'],
        );
        $this->assertSame(
            'query_waits_for_replay_consistency',
            $requirements['query_during_replay']['required_behavior'],
        );
        foreach (['query_api_sample', 'query_status_code', 'query_poll_started_at', 'query_handler_invoked_at'] as $field) {
            $this->assertContains($field, $requirements['query_during_replay']['evidence']);
        }
        $this->assertContains(
            'query_poll_started_at < replay_completed_at',
            $requirements['query_during_replay']['timestamp_order'],
        );
        $this->assertContains(
            'replay_completed_at <= query_handler_invoked_at',
            $requirements['query_during_replay']['timestamp_order'],
        );
        $this->assertContains(
            'invalid_signal_arguments',
            $requirements['malformed_signal_and_query_payloads']['required_errors'],
        );
        $this->assertContains(
            'invalid_query_arguments',
            $requirements['malformed_signal_and_query_payloads']['required_errors'],
        );
        $this->assertContains(
            'missing_workflow_signal',
            $requirements['unknown_signal_and_query_errors']['required_errors'],
        );
        $this->assertContains(
            'missing_workflow_query',
            $requirements['unknown_signal_and_query_errors']['required_errors'],
        );
        foreach ([
            'known_query_after_unknown_errors',
        ] as $field) {
            $this->assertContains($field, $requirements['unknown_signal_and_query_errors']['evidence']);
        }
        foreach ([
            'cli_unknown_signal_sample',
            'cli_unknown_query_sample',
            'cli_missing_workflow_signal_sample',
            'cli_missing_workflow_query_sample',
            'sdk_python_unknown_signal_sample',
            'sdk_python_unknown_query_sample',
            'sdk_python_missing_workflow_signal_sample',
            'sdk_python_missing_workflow_query_sample',
        ] as $field) {
            $this->assertContains(
                $field,
                $requirements['unknown_signal_and_query_errors']['optional_public_client_error_samples'],
            );
        }
        foreach ([
            'invalid_signal_arguments.status_code',
            'invalid_signal_arguments.reason',
            'invalid_query_arguments.status_code',
            'invalid_query_arguments.reason',
            'invalid_signal_arguments_context',
            'invalid_query_arguments_context',
            'signal_handler_invocation_count_after_invalid_payload',
            'query_state_mutation_count_after_invalid_payload',
            'post_error_valid_query_result',
            'cli_invalid_signal_arguments_sample',
            'cli_invalid_query_arguments_sample',
            'sdk_python_invalid_signal_arguments_sample',
            'sdk_python_invalid_query_arguments_sample',
        ] as $field) {
            $this->assertContains($field, $requirements['malformed_signal_and_query_payloads']['evidence']);
        }
        $this->assertContains(
            'public_query_surfaces',
            $requirements['completed_run_signal_and_query']['evidence'],
        );
        foreach ([
            'completed_at',
            'terminal_status',
            'signal_api_sample',
            'signal_error.status_code',
            'signal_error.reason',
            'signal_error.rejection_reason',
            'query_api_sample',
            'query_result_or_error.status_code',
            'query_result_or_error.outcome',
            'terminal_state_before_operations.history_event_count',
            'terminal_state_after_operations.history_event_count',
            'terminal_result_changed_after_operations',
            'terminal_history_changed_after_operations',
        ] as $field) {
            $this->assertContains($field, $requirements['completed_run_signal_and_query']['evidence']);
        }
        $this->assertContains(
            'run_status_after_operations',
            $requirements['completed_run_signal_and_query']['evidence'],
        );
        foreach ([
            'artifact_versions',
            'artifact_sources',
            'captured_at',
            'api_captures.selected_run_detail',
            'api_captures.selected_run_query_action',
            'comparison.run_status_matches_public_clients',
            'comparison.counter_state_matches_public_clients',
            'comparison.server_observation',
            'comparison.cli_observation',
            'comparison.sdk_observation',
        ] as $surface) {
            $this->assertContains($surface, $requirements['waterline_operator_visibility']['required_surfaces']);
        }
        $this->assertSame(
            'query_results_not_materialized_in_selected_run_detail',
            $requirements['waterline_operator_visibility']['allowed_live_query_detail_limitation'],
        );

        $findingPolicy = SignalQueryRuntimeContract::manifest()['finding_policy'];
        $this->assertSame('link_root_cause_finding_against_server', $findingPolicy['ordering_drift']);
        $this->assertSame('link_root_cause_finding_against_waterline', $findingPolicy['observer_mismatch']);
        $this->assertSame(
            'link_root_cause_finding_against_surface_owner',
            $findingPolicy['unsupported_public_surface'],
        );
    }

    public function test_manifest_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = SignalQueryRuntimeContract::manifest()['result_gate'];

        $this->assertSame(SignalQueryRuntimeResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(28, SignalQueryRuntimeResultGate::VERSION);
        $this->assertSame(SignalQueryRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            SignalQueryRuntimeContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertSame(
            'signal_query_runtime_contract.artifact_policy.install_channels',
            $resultGate['required_artifact_versions_source'],
        );
        $this->assertSame(
            'signal_query_runtime_contract.artifact_policy.expected_sources',
            $resultGate['required_artifact_sources_source'],
        );
        $this->assertTrue($resultGate['artifact_version_policy']['requires_recorded_and_pinned_versions']);
        $this->assertTrue($resultGate['artifact_version_policy']['rejects_placeholder_versions']);
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder', '<latest>'] as $example) {
            $this->assertContains($example, $resultGate['artifact_version_policy']['placeholder_version_examples']);
        }
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertContains('published_artifact_versions', $resultGate['artifact_versions_fields']);
        $this->assertSame(['outcome', 'status', 'verdict'], $resultGate['declared_outcome_fields']);
        $this->assertSame(
            'signal_query_runtime_contract.coverage_gate.*_outcome',
            $resultGate['declared_outcomes_source'],
        );
        $this->assertContains('every_required_scenario_has_one_result', $resultGate['pass_requires']);
        $this->assertContains('same_language_and_cross_language_cells_are_reported', $resultGate['pass_requires']);
        $this->assertContains('each_pass_scenario_includes_required_evidence', $resultGate['pass_requires']);
        $this->assertContains('replay_timing_timestamps_are_ordered', $resultGate['pass_requires']);
        $this->assertContains('terminal_run_status_codes_and_reasons_are_typed', $resultGate['pass_requires']);
        $this->assertContains(
            'terminal_run_result_and_history_are_unchanged_after_operations',
            $resultGate['pass_requires'],
        );
        $this->assertContains('each_non_pass_scenario_has_linked_findings', $resultGate['pass_requires']);
        $this->assertContains('omitted_required_scenarios_link_findings', $resultGate['pass_requires']);
        $this->assertContains('run_timestamps_outcome_and_finding_links_are_recorded', $resultGate['pass_requires']);
        $this->assertContains('overall_outcome_matches_gate_status', $resultGate['pass_requires']);
        $this->assertContains(
            'published_artifact_versions_are_recorded_and_pinned',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_sources_match_expected_channels',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'published_artifact_install_only_includes_per_artifact_install_proof',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'python_worker_baseline_identifies_a_published_python_sdk_worker',
            $resultGate['pass_requires'],
        );
        $this->assertContains(
            'scenario_artifact_versions_match_run_tuple',
            $resultGate['pass_requires'],
        );
        $this->assertNotContains('published_artifact_versions_are_recorded', $resultGate['pass_requires']);
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
    }

    public function test_result_gate_rejects_php_python_only_coverage_when_rust_is_in_the_tuple(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['scenario_results']['rust_worker_rust_php_python_clients']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_worker_rust_php_python_clients', $evaluation['missing_scenarios']);
    }

    public function test_result_gate_rejects_probe_package_version_as_rust_sdk_provenance(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['rust_worker_rust_php_python_clients']['observed_outputs']
            ['rust_sdk_version'] = '0.0.0';
        $result['scenario_results']['rust_worker_rust_php_python_clients']['observed_outputs']
            ['rust_worker_registration']['sdk_version'] = 'signals-queries-published-probe/0.0.0';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_sdk_version_mismatch', $failureCodes);
        $this->assertContains('rust_worker_registration_sdk_version_mismatch', $failureCodes);
    }

    public function test_result_gate_requires_official_crates_io_provenance_for_rust_and_avro(): void
    {
        $result = $this->completeSignalQueryResult();
        $outputs = &$result['scenario_results']['rust_worker_rust_php_python_clients']['observed_outputs'];
        $outputs['rust_crate_provenance']['source'] = 'registry+https://packages.example.test/index';
        $outputs['apache_avro_provenance']['source'] = 'registry+https://packages.example.test/index';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_crate_registry_provenance_mismatch', $failureCodes);
        $this->assertContains('rust_valid_avro_round_trip_not_proved', $failureCodes);
    }

    public function test_result_gate_rejects_replay_query_command_mutation(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['rust_replayed_instance_state_query_after_cold_restart']
            ['observed_outputs']['immutability_checkpoints']['cold_restarted']
            ['after_successful_and_failed_queries']['workflow_command_count'] = 2;

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_replay_query_history_or_commands_changed', $failureCodes);
    }

    public function test_result_gate_rejects_undocumented_rust_stable_outcome_reason(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['rust_query_error_and_immutability']['observed_outputs']
            ['malformed_query_payload']['reason'] = 'server_error';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_stable_outcome_reason_mismatch', $failureCodes);
    }

    public function test_result_gate_requires_rust_terminal_signal_rejection_reason(): void
    {
        foreach (['missing', 'changed'] as $mutation) {
            $result = $this->completeSignalQueryResult();
            $terminalSignal = &$result['scenario_results']['rust_query_error_and_immutability']
                ['observed_outputs']['terminal_signal'];

            if ($mutation === 'missing') {
                unset($terminalSignal['rejection_reason']);
            } else {
                $terminalSignal['rejection_reason'] = 'accepted';
            }

            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
            $terminalFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['scenario_id'] ?? null)
                    === 'rust_query_error_and_immutability'
                    && in_array(($failure['code'] ?? null), [
                        'missing_required_pass_evidence',
                        'rust_stable_outcome_reason_mismatch',
                    ], true)
                    && in_array(($failure['evidence_key'] ?? $failure['field'] ?? null), [
                        'terminal_signal.rejection_reason',
                    ], true),
            ));

            $this->assertSame('non_passing', $evaluation['status'], $mutation);
            $this->assertNotEmpty($terminalFailures, $mutation);
        }
    }

    public function test_result_gate_recomputes_completed_replay_failed_query_immutability(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['rust_replayed_instance_state_query_after_cold_restart']
            ['observed_outputs']['immutability_checkpoints']['completed']
            ['answer_after_failed_query'] = 6;

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $failureCodes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('rust_replay_failed_query_changed_later_answer', $failureCodes);
    }

    public function test_manifest_publishes_host_runner_contract_for_split_out_evidence(): void
    {
        $hostRunner = SignalQueryRuntimeContract::manifest()['host_runner_contract'];

        $this->assertSame(
            'required_for_passing_signal_query_conformance',
            $hostRunner['status'],
        );
        $this->assertSame(
            'scripts/conformance/signals-queries-published-artifacts.sh',
            $hostRunner['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/signals-queries-published-artifacts.sh --result-dir <result-dir>',
            $hostRunner['runner_command'],
        );
        $this->assertTrue($hostRunner['must_execute_against_published_artifacts']);
        $this->assertTrue($hostRunner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertTrue($hostRunner['must_emit_focused_findings_for_uncovered_cells']);
        $this->assertSame(['bash', 'python3', 'docker', 'sh'], $hostRunner['required_host_commands']);
        $this->assertContains(
            'DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE',
            $hostRunner['adversarial_probe_overrides'],
        );
        $this->assertContains(
            'DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE',
            $hostRunner['adversarial_probe_overrides'],
        );
        $this->assertContains(
            'DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS',
            $hostRunner['adversarial_probe_overrides'],
        );
        $this->assertContains('DW_SIGNALS_QUERIES_CLI_BIN', $hostRunner['adversarial_probe_overrides']);
        $this->assertContains('DW_SIGNALS_QUERIES_PYTHON', $hostRunner['adversarial_probe_overrides']);

        foreach ($hostRunner['required_execution_scopes'] as $scope) {
            $this->assertContains($scope, $hostRunner['required_execution_scopes']);
            $this->assertArrayHasKey($scope, $hostRunner['evidence_shards']);
            $this->assertArrayHasKey('finding_type_when_missing', $hostRunner['evidence_shards'][$scope]);
        }

        $this->assertNotContains('ordered_delivery_and_dedup', $hostRunner['required_execution_scopes']);
        $this->assertContains('ordered_signal_delivery', $hostRunner['required_execution_scopes']);
        $this->assertContains('dedup_contract_observation', $hostRunner['required_execution_scopes']);
        $this->assertNotContains('adversarial_error_shapes', $hostRunner['required_execution_scopes']);
        $this->assertContains('unknown_handler_errors', $hostRunner['required_execution_scopes']);
        $this->assertContains('malformed_payload_errors', $hostRunner['required_execution_scopes']);
        $this->assertSame(
            [],
            $hostRunner['baseline_probe_not_claimed_as_pass'],
        );

        $this->assertTrue($hostRunner['evidence_shards']['published_artifact_install']['baseline_probe_claims_pass']);
        $this->assertSame(
            'published_artifact_install_probe',
            $hostRunner['evidence_shards']['published_artifact_install']['pass_claim_source'],
        );
        $this->assertSame(
            [
                'server' => 'published_docker_image',
                'cli' => 'published_cli_release',
                'workflow-php' => 'published_composer_package',
                'sdk-python' => 'published_pypi_package',
                'sdk-rust' => 'published_crates_io_package',
                'waterline' => 'published_waterline_artifact',
            ],
            $hostRunner['evidence_shards']['published_artifact_install']['expected_artifact_sources'],
        );
        $this->assertSame(
            ['server', 'cli', 'sdk-python', 'sdk-rust'],
            $hostRunner['evidence_shards']['published_artifact_install']['install_proof_artifacts'],
        );
        $this->assertContains(
            'artifact_install_evidence',
            $hostRunner['evidence_shards']['published_artifact_install']['current_evidence_fields'],
        );
        $this->assertTrue($hostRunner['evidence_shards']['python_worker_cli_and_sdk_smoke']['baseline_probe_claims_pass']);
        $this->assertSame(
            'published_python_sdk_worker_baseline_probe',
            $hostRunner['evidence_shards']['python_worker_cli_and_sdk_smoke']['pass_claim_source'],
        );

        $this->assertSame(
            ['dedup_contract_observation'],
            $hostRunner['evidence_shards']['dedup_contract_observation']['must_cover_scenarios'],
        );
        $this->assertSame(
            'signal_query_dedup_contract_uncovered',
            $hostRunner['evidence_shards']['dedup_contract_observation']['finding_type_when_missing'],
        );
        $this->assertSame(
            'signal_query_unknown_handler_errors_uncovered',
            $hostRunner['evidence_shards']['unknown_handler_errors']['finding_type_when_missing'],
        );
        $this->assertSame(
            [
                'workflow_id',
                'run_id',
                'worker_id',
                'task_queue',
                'unknown_signal',
                'missing_workflow_signal',
                'missing_workflow_query',
                'query_not_found',
                'rejected_unknown_query',
                'known_query_after_unknown_errors',
                'known_query_after_unknown_expected',
                'known_query_after_unknown_result',
                'post_error_query_responder',
                'history_and_commands_before_rejected_requests.history_event_count',
                'history_and_commands_before_rejected_requests.workflow_command_count',
                'history_and_commands_after_recovery_query.history_event_count',
                'history_and_commands_after_recovery_query.workflow_command_count',
                'history_and_commands_after_all_requests.history_event_count',
                'history_and_commands_after_all_requests.workflow_command_count',
                'rejected_requests_and_recovery_appended_no_history',
                'rejected_requests_and_recovery_emitted_no_workflow_commands',
            ],
            $hostRunner['evidence_shards']['unknown_handler_errors']['required_evidence_fields'],
        );
        $this->assertSame(
            [
                'cli_unknown_signal_sample',
                'cli_unknown_query_sample',
                'cli_missing_workflow_signal_sample',
                'cli_missing_workflow_query_sample',
                'sdk_python_unknown_signal_sample',
                'sdk_python_unknown_query_sample',
                'sdk_python_missing_workflow_signal_sample',
                'sdk_python_missing_workflow_query_sample',
            ],
            $hostRunner['evidence_shards']['unknown_handler_errors']['optional_evidence_fields'],
        );
        $this->assertSame(
            [
                'worker_runtime',
                'python_worker_artifact_source',
                'python_worker_sdk_version',
                'python_worker_query_task_routing',
                'routed_current_query_task',
                'cli_signal_and_query',
                'sdk_python_signal_and_query',
                'immediate_repeat_query_consistency',
            ],
            $hostRunner['evidence_shards']['python_worker_cli_and_sdk_smoke']['current_evidence_fields'],
        );
        $this->assertSame(
            'signal_query_python_baseline_failed',
            $hostRunner['evidence_shards']['python_worker_cli_and_sdk_smoke'][
                'finding_type_when_product_behavior_fails'
            ],
        );
        $this->assertSame(
            'signal_query_python_routed_current_query_evidence_missing',
            $hostRunner['evidence_shards']['python_worker_cli_and_sdk_smoke'][
                'finding_type_when_routed_current_query_missing'
            ],
        );
        $this->assertSame(
            [
                'workflow_id',
                'run_id',
                'rapid_increment_inputs',
                'accepted_signal_inputs',
                'accepted_signal_total',
                'queried_total',
                'history_signal_order',
                'final_run_status',
                'ordered_query_responder',
            ],
            $hostRunner['evidence_shards']['ordered_signal_delivery']['current_evidence_fields'],
        );
        $this->assertSame(
            [
                'worker_runtime',
                'workflow_php_artifact_source',
                'workflow_php_sdk_version',
                'php_worker_query_task_routing',
                'routed_current_query_task',
                'cli_signal_and_query',
                'workflow_php_signal_and_query',
                'immediate_repeat_query_consistency',
            ],
            $hostRunner['evidence_shards']['php_worker_mirror']['required_evidence_fields'],
        );
        $this->assertSame(
            'signal_query_php_worker_mirror_failed',
            $hostRunner['evidence_shards']['php_worker_mirror'][
                'finding_type_when_product_behavior_fails'
            ],
        );
        $this->assertSame(
            [
                'probe_error',
                'worker_registration',
                'workflow_php_start',
                'initial_query',
                'cli_signal',
                'cli_query',
                'workflow_php_signal',
                'workflow_php_query',
                'repeat_query',
            ],
            $hostRunner['evidence_shards']['php_worker_mirror']['retained_failure_diagnostics'][
                'actual_payload_fields'
            ],
        );
        $this->assertSame(
            'behavior_failure_diagnostics.php_worker_cli_and_sdk_baseline.current_behavior_failures[].actual',
            $hostRunner['evidence_shards']['php_worker_mirror']['retained_failure_diagnostics'][
                'record_field'
            ],
        );
        $this->assertSame(
            [
                'php_client_signal_and_query',
                'sdk_python_signal_and_query',
                'cli_signal_and_query',
                'cross_language_query_consistency',
                'wire_envelope_compatibility',
            ],
            $hostRunner['evidence_shards']['cross_language_client_matrix']['required_evidence_fields'],
        );
        $this->assertSame(
            [
                'signal_api_sample',
                'signal_status_code',
                'worker_restart_at',
                'signal_sent_at',
                'replay_completed_at',
                'signal_applied_at',
                'query_api_sample',
                'query_status_code',
                'query_sent_at',
                'query_poll_started_at',
                'query_handler_invoked_at',
                'query_completed_at',
                'query_answer',
                'expected_answer',
            ],
            $hostRunner['evidence_shards']['replay_timing']['required_evidence_fields'],
        );
        $this->assertSame(
            [
                'completed_run_id',
                'completed_at',
                'terminal_status',
                'signal_api_sample',
                'signal_error.status_code',
                'signal_error.reason',
                'signal_error.rejection_reason',
                'query_api_sample',
                'query_result_or_error.status_code',
                'query_result_or_error.outcome',
                'signal_error',
                'query_result_or_error',
                'public_query_surfaces',
                'terminal_state_before_operations.history_event_count',
                'terminal_state_after_operations.history_event_count',
                'terminal_result_changed_after_operations',
                'terminal_history_changed_after_operations',
                'run_status_after_operations',
            ],
            $hostRunner['evidence_shards']['completed_run_handling']['required_evidence_fields'],
        );
        $this->assertSame(
            'signal_query_completed_run_handling_failed',
            $hostRunner['evidence_shards']['completed_run_handling']['finding_type_when_product_behavior_fails'],
        );
        $this->assertSame(
            'signal_query_completed_run_probe_unavailable',
            $hostRunner['evidence_shards']['completed_run_handling']['finding_type_when_probe_unavailable'],
        );
        $this->assertSame(
            [
                'invalid_signal_arguments',
                'invalid_query_arguments',
                'invalid_signal_arguments.status_code',
                'invalid_signal_arguments.reason',
                'invalid_query_arguments.status_code',
                'invalid_query_arguments.reason',
                'invalid_signal_arguments_context',
                'invalid_query_arguments_context',
                'signal_handler_invocation_count_after_invalid_payload',
                'query_state_mutation_count_after_invalid_payload',
                'post_error_valid_query_result',
                'cli_invalid_signal_arguments_sample',
                'cli_invalid_query_arguments_sample',
                'sdk_python_invalid_signal_arguments_sample',
                'sdk_python_invalid_query_arguments_sample',
            ],
            $hostRunner['evidence_shards']['malformed_payload_errors']['required_evidence_fields'],
        );
        $this->assertSame(
            [
                'artifact_versions',
                'artifact_sources',
                'captured_at',
                'observer_state.selected_run',
                'observer_state.signals',
                'observer_state.queries',
                'observer_state.paths.selected_run_query_template',
                'api_paths.selected_run_detail',
                'api_paths.selected_run_query_action',
                'dashboard_json_envelopes.selected_run_detail',
                'api_captures.selected_run_detail',
                'api_captures.selected_run_query_action',
                'comparison.run_status_matches_public_clients',
                'comparison.counter_state_matches_public_clients',
                'comparison.server_observation',
                'comparison.cli_observation',
                'comparison.sdk_observation',
            ],
            $hostRunner['evidence_shards']['waterline_observer_comparison']['required_evidence_fields'],
        );

        $this->assertSame(
            'conformance_runner_coverage_gap',
            $hostRunner['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertContains(
            'signals-queries-result.json',
            $hostRunner['result_files'],
        );
        $this->assertContains(
            'signals-queries-findings.json',
            $hostRunner['result_files'],
        );
    }

    public function test_host_runner_script_names_every_remaining_parity_split_out(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );

        foreach ([
            'signal_query_published_artifact_install_uncovered',
            'signal_query_python_smoke_uncovered',
            'signal_query_ordered_delivery_uncovered',
            'signal_query_ordered_delivery_current_evidence_missing',
            'signal_query_dedup_contract_uncovered',
            'signal_query_dedup_contract_current_evidence_missing',
            'signal_query_php_worker_mirror_uncovered',
            'signal_query_php_worker_mirror_failed',
            'signal_query_php_worker_mirror_current_evidence_missing',
            'signal_query_cross_language_client_matrix_uncovered',
            'signal_query_replay_timing_uncovered',
            'signal_query_completed_run_handling_uncovered',
            'signal_query_completed_run_handling_failed',
            'signal_query_completed_run_probe_unavailable',
            'signal_query_unknown_handler_errors_uncovered',
            'signal_query_unknown_handler_errors_current_evidence_missing',
            'signal_query_adversarial_error_shapes_uncovered',
            'signal_query_waterline_observer_comparison_uncovered',
            'signal_query_python_baseline_failed',
            '"runner_blocked": runner_blocked',
            'server_readiness_topology',
            'runner_blocker',
            'DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS',
            'DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE',
            'DW_SIGNALS_QUERIES_RUN_WATERLINE_OBSERVER_PROBE',
            'run_baseline_probe(result_dir)',
            'run_waterline_observer_probe(result_dir, smoke_evidence)',
            'waterline:signals-queries-conformance',
            'published_waterline_artifact_http_kernel',
            'WATERLINE_WORKFLOW_DB_HOST',
            'published_server_compose_workflow_storage',
            'waterline_workflow_storage_unavailable',
            'waterline_query_responder_inputs(',
            'durable-workflow/waterline:{waterline_version}',
            'baseline_scenario_result(',
            'run_python_sdk_baseline(',
            'Worker(',
            'workflow_task_history_events(',
            'increment_signal_observations_from_task(',
            'optional public client sample failed',
            'unknown-handler baseline probe failed',
            'ordered delivery baseline probe failed',
            'body={"input": [amount], "request_id": f"{ordered_workflow_id}-{amount}"}',
            'body={"input": [7], "request_id": duplicate_request_id}',
            'timeout=remaining + 5.0',
            '"command_contract_source"',
            '"signal_admission"',
            '"documented_contract_source"',
            '"duplicate_signal_payload_shape": "positional input array"',
            'dedup baseline probe failed',
            'known_query_after_unknown_result',
            '"signal_amounts"',
            '"not_claimed_as_pass"',
            'signals-queries-result.json',
            'signals-queries-findings.json',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $this->assertStringContainsString(
            'base_url = env_text("DW_SIGNALS_QUERIES_SERVER_URL") or env_text("DURABLE_WORKFLOW_SERVER_URL")',
            $source,
        );
        $this->assertStringNotContainsString(
            'for evidence_key in ("server_base_url", "server_url", "base_url")',
            $source,
        );

        foreach ([
            'build_waterline_observer_' . 'captures(',
            'waterline-selected-run-' . 'detail.json',
            'waterline-selected-run-' . 'query.json',
            '--selected-run-detail-capture=' . '/app/conformance',
            '--selected-run-query-capture=' . '/app/conformance',
        ] as $forbiddenNeedle) {
            $this->assertStringNotContainsString($forbiddenNeedle, $source);
        }
    }

    public function test_host_runner_keeps_bind_mounts_host_owned_and_registers_cleanup_before_startup(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );

        foreach ([
            'f"{os.getuid()}:{os.getgid()}"',
            '*docker_host_user_options()',
            'register_compose_project(project, compose, env, log_file)',
            'cleanup_commands_deterministically(cleanup_commands)',
            'docker_project_resources("container", project, log_file)',
            'docker_project_resources("volume", project, log_file)',
            'docker_project_resources("network", project, log_file)',
            'signal.signal(signal.SIGTERM, terminate_after_cleanup)',
            'cleanup_labeled_docker_runs(log_file)',
            'remove_scratch_root(run_root)',
            'remove_scratch_root(waterline_root)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $registration = strpos($source, 'register_compose_project(project, compose, env, log_file)');
        $startup = strpos($source, 'up = run_command(commands[1]');
        $this->assertNotFalse($registration);
        $this->assertNotFalse($startup);
        $this->assertLessThan($startup, $registration);
        $this->assertStringNotContainsString('shutil.rmtree(run_root, ignore_errors=True)', $source);
    }

    public function test_cleanup_regression_terminates_a_populated_isolated_run_and_checks_exact_resources(): void
    {
        $script = dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-cleanup-regression.sh';
        $source = (string) file_get_contents($script);

        $this->assertFileIsExecutable($script);
        foreach ([
            'DW_SIGNALS_QUERIES_CLEANUP_TERMINATION_READY_FILE="$ready_file"',
            '"$run_root/workflow-php/vendor"',
            '"$run_root/sdk-rust/target"',
            '"$result_dir/waterline-signals-queries-observer/vendor"',
            'kill -TERM "$runner_pid"',
            'docker "$kind" "${list_args[@]}"',
            'docker container ls -a -q --filter "label=$resource_label"',
            '! -uid "$(id -u)"',
            'rm -rf "$scratch"',
            '"runtime_resources_remaining":0',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    public function test_every_bind_mounted_runtime_command_maps_the_invoking_user(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
artifact_versions = {
    "server": "0.2.634",
    "workflow-php": "2.0.0-alpha.213",
}
project = Path("/tmp/signals-queries-host-ownership-contract")
commands = {
    "php": php_docker_command(project, ["install"]),
    "rust": rust_probe_docker_command(project, ["cargo", "build"]),
    "waterline": docker_run_for_project(project, ["php", "artisan", "list"]),
}
identity = f"{os.getuid()}:{os.getgid()}"
print(json.dumps({
    name: {
        "user": command[command.index("--user") + 1],
        "identity": identity,
        "mount": docker_volume_spec(project) in command,
    }
    for name, command in commands.items()
}, sort_keys=True))
PY);

        foreach (['php', 'rust', 'waterline'] as $runtime) {
            $this->assertSame($result[$runtime]['identity'], $result[$runtime]['user'], $runtime);
            $this->assertTrue($result[$runtime]['mount'], $runtime);
        }
    }

    public function test_host_runner_executes_the_exact_published_rust_matrix(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );
        $probe = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-rust-probe.rs',
        );

        foreach ([
            'rust_worker_rust_php_python_clients',
            'python_worker_rust_client',
            'php_worker_rust_client',
            'rust_query_error_and_immutability',
            'rust_replayed_instance_state_query_after_cold_restart',
        ] as $scenario) {
            $this->assertStringContainsString($scenario, $runner);
        }

        $this->assertStringContainsString('durable-workflow = "={version}"', $runner);
        $this->assertStringContainsString(
            'version = rust_crate_version_from_artifact_tuple(artifact_versions)',
            $runner,
        );
        $this->assertStringNotContainsString('version != "0.1.2"', $runner);
        $this->assertStringContainsString('Cargo.lock', $runner);
        $this->assertStringContainsString('registry_checksum', $runner);
        $this->assertStringContainsString('apache_avro_provenance', $runner);
        $this->assertStringContainsString('history_and_commands_before_first_successful_query', $runner);
        $this->assertStringContainsString('valid_avro_signal_and_query', $runner);
        $this->assertStringContainsString('completed_after_failure = wait_for_query_result(', $runner);
        $this->assertStringContainsString('answer_after_failed_query', $runner);
        $this->assertStringContainsString(
            '"X-Durable-Workflow-Protocol-Version": "2.0"',
            $runner,
        );
        $this->assertStringNotContainsString(
            '"X-Durable-Workflow-Protocol-Version": "1.7"',
            $runner,
        );
        $this->assertStringContainsString('register_replayed_workflow', $probe);
        $this->assertStringContainsString('register_replayed_query', $probe);
        $this->assertStringContainsString('DEFAULT_CODEC', $probe);
        $this->assertStringContainsString('SDK_VERSION', $probe);
        $this->assertStringContainsString('record["reason"] = reason.clone();', $probe);
        $this->assertStringContainsString(
            'record["rejection_reason"] = rejection_reason.clone();',
            $probe,
        );
        $this->assertStringContainsString('with preserve_rust_matrix_cell(', $runner);
        $this->assertStringContainsString('atomic_write_json(checkpoint_path', $runner);
        $this->assertStringContainsString('checkpoint_baseline_cells()', $runner);
        $this->assertStringContainsString('return partial_evidence, {', $runner);
        $this->assertStringContainsString('"route_and_lease_diagnostics": diagnostics', $runner);
        $this->assertStringContainsString('"partial_observed_outputs": partial_outputs', $runner);
        $this->assertStringContainsString('def record_phase(', $runner);
    }

    public function test_host_runner_uses_the_exact_rust_version_from_the_artifact_tuple(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
versions = {"sdk-rust": "0.1.3"}
accepted = rust_crate_version_from_artifact_tuple(versions)
errors = []
for rejected in ("0.1", "latest", "=0.1.3", ""):
    try:
        rust_crate_version_from_artifact_tuple({"sdk-rust": rejected})
    except RustCrateArtifactError as exc:
        errors.append(probe_error_payload(exc))
print(json.dumps({"accepted": accepted, "errors": errors}, sort_keys=True))
PY);

        $this->assertSame('0.1.3', $result['accepted']);
        $this->assertCount(4, $result['errors']);
        foreach ($result['errors'] as $error) {
            $this->assertSame('rust_crate_version_not_exact', $error['code']);
            $this->assertSame('sdk-rust', $error['artifact']);
            $this->assertSame('durable-workflow', $error['package']);
            $this->assertSame('validate_artifact_tuple', $error['phase']);
            $this->assertSame('published_crates_io_package', $error['source']);
        }
    }

    public function test_rust_matrix_preserves_completed_cells_and_continues_after_a_later_failure(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
scenarios = {}
descriptor = {}
checkpoints = []
log_file = Path(tempfile.gettempdir()) / "signals-queries-rust-cell-test.log"
base_outputs = {
    "rust_sdk_version": "0.1.3",
    "rust_crate_provenance": {
        "source": "crates.io",
        "resolved_version": "0.1.3",
        "checksum": "sha256:test",
    },
    "apache_avro_provenance": {
        "name": "apache-avro",
        "resolved_version": "0.21.0",
        "source": "crates.io",
        "checksum": "sha256:avro-test",
    },
}
partial_outputs = {
    "rust_worker_rust_php_python_clients": dict(base_outputs),
    "rust_query_error_and_immutability": {
        **base_outputs,
        "answer_before_failures": 8,
        "history_and_commands_before_first_successful_query": {
            "history_event_count": 6,
            "workflow_command_count": 2,
        },
        "history_and_commands_after_successful_queries": {
            "history_event_count": 6,
            "workflow_command_count": 2,
        },
        "completed_probe_phases": ["successful_query_immutability_observed"],
    },
    "python_worker_rust_client": dict(base_outputs),
    "rust_replayed_instance_state_query_after_cold_restart": {
        **base_outputs,
        "worker_process_identities": [
            {"worker_id": "rust-replay-initial", "process_id": "container:initial"},
            {"worker_id": "rust-replay-fresh", "process_id": "container:fresh"},
        ],
        "running_query_results": {
            "sdk_rust": 5,
            "workflow_php_sdk": 5,
            "sdk_python": 5,
        },
        "immutability_checkpoints": {
            "running": {
                "before_first_successful_query": {
                    "history_event_count": 7,
                    "workflow_command_count": 2,
                },
                "answer_before_failed_query": 5,
                "failed_query": {"reason": "query_not_found"},
                "answer_after_failed_query": 5,
                "after_successful_and_failed_queries": {
                    "history_event_count": 7,
                    "workflow_command_count": 2,
                },
            },
            "cold_restarted": {
                "before_first_successful_query": {
                    "history_event_count": 7,
                    "workflow_command_count": 2,
                },
            },
        },
        "completed_probe_phases": [
            "running_query_immutability_observed",
            "cold_restart_history_before_queries_observed",
            "fresh_worker_registered",
        ],
    },
}

def checkpoint():
    checkpoints.append({key: value["status"] for key, value in scenarios.items()})

with preserve_rust_matrix_cell(
    scenarios=scenarios,
    descriptor=descriptor,
    scenario_ids=(
        "rust_worker_rust_php_python_clients",
        "rust_query_error_and_immutability",
    ),
    base_outputs=base_outputs,
    partial_outputs=partial_outputs,
    failure_diagnostics=lambda: {
        "probe_phase": "rust_snapshot_errors",
        "task_queue": "rust-snapshot",
        "worker_process_identities": [{"worker_id": "rust-worker", "process_id": "container:42"}],
        "last_client_samples": {"sdk_rust": {"reason": "query_worker_timeout"}},
    },
    checkpoint=checkpoint,
    log_file=log_file,
):
    scenarios["rust_worker_rust_php_python_clients"] = {
        "scenario_id": "rust_worker_rust_php_python_clients",
        "status": "pass",
        "observed_outputs": base_outputs,
    }
    raise RuntimeError("late immutability query timed out")

with preserve_rust_matrix_cell(
    scenarios=scenarios,
    descriptor=descriptor,
    scenario_ids=("python_worker_rust_client",),
    base_outputs=base_outputs,
    partial_outputs=partial_outputs,
    failure_diagnostics=lambda: {},
    checkpoint=checkpoint,
    log_file=log_file,
):
    scenarios["python_worker_rust_client"] = {
        "scenario_id": "python_worker_rust_client",
        "status": "pass",
        "observed_outputs": base_outputs,
    }

with preserve_rust_matrix_cell(
    scenarios=scenarios,
    descriptor=descriptor,
    scenario_ids=("rust_replayed_instance_state_query_after_cold_restart",),
    base_outputs=base_outputs,
    partial_outputs=partial_outputs,
    failure_diagnostics=lambda: {
        "probe_phase": "rust_replayed_instance_state_cold_restart",
        "task_queue": "rust-replay",
        "last_client_samples": {"sdk_rust": {"reason": "query_worker_timeout"}},
    },
    checkpoint=checkpoint,
    log_file=log_file,
):
    raise RuntimeError("cold-restarted query did not return within 60s")

print(json.dumps({
    "scenarios": scenarios,
    "descriptor": descriptor,
    "checkpoints": checkpoints,
}, sort_keys=True))
PY);

        $this->assertSame('pass', $result['scenarios']['rust_worker_rust_php_python_clients']['status']);
        $failed = $result['scenarios']['rust_query_error_and_immutability'];
        $this->assertSame('fail', $failed['status']);
        $this->assertSame('late immutability query timed out', $failed['observed_outputs']['probe_error']['message']);
        $this->assertSame('0.21.0', $failed['observed_outputs']['apache_avro_provenance']['resolved_version']);
        $this->assertSame(8, $failed['observed_outputs']['answer_before_failures']);
        $this->assertSame(
            6,
            $failed['observed_outputs']['history_and_commands_after_successful_queries']['history_event_count'],
        );
        $this->assertSame(
            ['successful_query_immutability_observed'],
            $failed['observed_outputs']['completed_probe_phases'],
        );
        $this->assertSame(
            'query_worker_timeout',
            $failed['observed_outputs']['route_and_lease_diagnostics']['last_client_samples']['sdk_rust']['reason'],
        );
        $this->assertSame(
            'signal_query_rust_error_immutability_failed',
            $failed['linked_findings'][0]['type'],
        );
        $this->assertSame('pass', $result['scenarios']['python_worker_rust_client']['status']);
        $this->assertSame('pass', $result['descriptor']['cell_verdicts']['rust_worker_rust_php_python_clients']['status']);
        $this->assertSame('fail', $result['descriptor']['cell_verdicts']['rust_query_error_and_immutability']['status']);
        $this->assertSame('pass', $result['descriptor']['cell_verdicts']['python_worker_rust_client']['status']);
        $replayFailure = $result['scenarios']['rust_replayed_instance_state_query_after_cold_restart'];
        $this->assertSame('fail', $replayFailure['status']);
        $this->assertSame(
            5,
            $replayFailure['observed_outputs']['running_query_results']['sdk_rust'],
        );
        $this->assertSame(
            7,
            $replayFailure['observed_outputs']['immutability_checkpoints']['running'][
                'after_successful_and_failed_queries'
            ]['history_event_count'],
        );
        $this->assertSame(
            'container:fresh',
            $replayFailure['observed_outputs']['worker_process_identities'][1]['process_id'],
        );
        $this->assertSame(
            '0.21.0',
            $replayFailure['observed_outputs']['apache_avro_provenance']['resolved_version'],
        );
        $this->assertSame(
            'rust_replayed_instance_state_cold_restart',
            $replayFailure['observed_outputs']['route_and_lease_diagnostics']['probe_phase'],
        );
        $this->assertCount(3, $result['checkpoints']);
        $this->assertSame('pass', $result['checkpoints'][0]['rust_worker_rust_php_python_clients']);
        $this->assertSame('fail', $result['checkpoints'][0]['rust_query_error_and_immutability']);
    }

    public function test_production_rust_matrix_checkpoints_worker_clients_before_terminal_signal_failure(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
artifact_versions = {"sdk-rust": "0.1.3"}
events = []
completed_workflows = set()
run_root = Path(tempfile.mkdtemp(prefix="dw-rust-matrix-terminal-signal-test."))
log_file = run_root / "probe.log"

rust_provenance = {
    "source": "crates.io",
    "resolved_version": "0.1.3",
    "checksum": "sha256:durable-workflow-test",
}
avro_provenance = {
    "name": "apache-avro",
    "source": "crates.io",
    "resolved_version": "0.21.0",
    "checksum": "sha256:apache-avro-test",
}

class FakeProcess:
    pid = 4242

    def poll(self):
        return None


def fake_prepare_rust_probe(run_root, log_file):
    project_dir = run_root / "sdk-rust"
    project_dir.mkdir(parents=True, exist_ok=True)
    return project_dir, rust_provenance, {
        "package": avro_provenance,
        "install_entry": {
            "artifact": "sdk-rust",
            "status": "pass",
            "version": "0.1.3",
            "source": "published_crates_io_package",
        },
    }


def fake_probe_artifact_versions():
    return {
        "server": "0.2.631",
        "sdk-rust": "0.1.3",
        "sdk-python": "0.4.98",
        "workflow-php": "2.0.0-alpha.264",
    }


def fake_start_rust_probe_worker(
    project_dir, base_url, token, namespace, task_queue,
    worker_id, mode, container_name, log_file,
):
    events.append(f"start:{mode}:{worker_id}")
    return {
        "process_id": f"container:{worker_id}",
        "registration": {"worker_id": worker_id, "sdk_version": "0.1.3"},
    }


def fake_stop_rust_probe_worker(container_name, log_file):
    events.append(f"stop:{container_name}")


def fake_start_python_sdk_counter_worker(**kwargs):
    events.append("start:python-worker-rust-client")
    return FakeProcess()


def fake_stop_python_sdk_counter_worker(process, log_file):
    events.append("stop:python-worker-rust-client")


def fake_wait_for_worker_registered(**kwargs):
    return {"worker_id": kwargs["worker_id"], "sdk_version": "0.4.98"}


def fake_wait_for_docker_worker_registered(**kwargs):
    return {"worker_id": kwargs["worker_id"], "sdk_version": "2.0.0-alpha.264"}


def query_value(workflow_id):
    if "rust-snapshot" in workflow_id:
        return 8
    if "python-rust-client" in workflow_id or "php-rust-client" in workflow_id:
        return 10
    return 5


def fake_rust_client_sample(
    project_dir, base_url, token, namespace, task_queue,
    operation, workflow_id, name, args, log_file,
):
    if operation == "start":
        return {"ok": True, "result": {"run_id": f"run-{workflow_id}"}}
    if operation == "signal":
        if workflow_id in completed_workflows:
            events.append("snapshot-terminal-signal-failed")
            raise TimeoutError("terminal signal operation timed out")
        return {"ok": True, "result": {"accepted": True}}
    if "missing-" in workflow_id:
        return {"ok": False, "reason": "workflow_not_found"}
    if "unavailable-" in workflow_id:
        return {"ok": False, "reason": "query_handler_unavailable"}
    if name in {"unknown", "does-not-exist"}:
        return {"ok": False, "reason": "query_not_found"}
    return {
        "ok": True,
        "result": query_value(workflow_id),
        "default_codec": "avro",
        "payload_codec": "avro",
    }


def fake_query_all_published_clients(*, workflow_id, expected, diagnostic_samples=None, **kwargs):
    sample = {
        "ok": True,
        "result": expected,
        "default_codec": "avro",
        "payload_codec": "avro",
    }
    samples = {
        "sdk_rust": dict(sample),
        "workflow_php_sdk": dict(sample),
        "sdk_python": dict(sample),
    }
    if diagnostic_samples is not None:
        diagnostic_samples.update(samples)
    return {
        "sdk_rust": expected,
        "workflow_php_sdk": expected,
        "sdk_python": expected,
        "samples": samples,
    }


def fake_wait_for_history_signals(*args, **kwargs):
    return {"history_event_count": 6, "workflow_command_count": 2}


def fake_workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id=None):
    return {
        "workflow_id": workflow_id,
        "run_id": run_id,
        "status": "completed" if workflow_id in completed_workflows else "running",
        "history_event_count": 6,
        "workflow_command_count": 2,
    }


def fake_wait_for_terminal_snapshot(base_url, token, namespace, workflow_id, run_id):
    completed_workflows.add(workflow_id)
    return fake_workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)


def fake_http_json(base_url, path, **kwargs):
    if "/query/current" in path:
        return {"status_code": 400, "body": {"reason": "malformed_payload"}}
    return {"status_code": 200, "body": {"worker_id": "diagnostic-worker"}}


def fake_run_command(command, **kwargs):
    if "php-counter-worker.php" in command:
        events.append("start:php-worker-rust-client")
    return subprocess.CompletedProcess(
        command,
        0,
        stdout="container:test-process\n",
        stderr="",
    )


globals().update({
    "prepare_rust_probe": fake_prepare_rust_probe,
    "probe_artifact_versions": fake_probe_artifact_versions,
    "start_rust_probe_worker": fake_start_rust_probe_worker,
    "stop_rust_probe_worker": fake_stop_rust_probe_worker,
    "start_python_sdk_counter_worker": fake_start_python_sdk_counter_worker,
    "stop_python_sdk_counter_worker": fake_stop_python_sdk_counter_worker,
    "wait_for_worker_registered": fake_wait_for_worker_registered,
    "wait_for_docker_worker_registered": fake_wait_for_docker_worker_registered,
    "rust_client_sample": fake_rust_client_sample,
    "query_all_published_clients": fake_query_all_published_clients,
    "wait_for_history_signals": fake_wait_for_history_signals,
    "workflow_public_snapshot": fake_workflow_public_snapshot,
    "wait_for_terminal_snapshot": fake_wait_for_terminal_snapshot,
    "incompatible_query_protocol_sample": lambda *args: {"reason": "incompatible_query_protocol"},
    "http_json": fake_http_json,
    "run_command": fake_run_command,
    "php_docker_command": lambda *args, **kwargs: ["php-counter-worker.php"],
})

evidence, install, descriptor = run_rust_matrix_probe(
    base_url="http://server.test",
    token="token",
    namespace="default",
    python_bin=sys.executable,
    workflow_php_project=run_root / "workflow-php",
    run_root=run_root,
    log_file=log_file,
)
checkpoint = json.loads((run_root / "signals-queries-rust-cell-results.json").read_text())

print(json.dumps({
    "events": events,
    "scenario_results": evidence["scenario_results"],
    "checkpoint_results": checkpoint["scenario_results"],
    "cell_verdicts": descriptor["cell_verdicts"],
}, sort_keys=True))
PY);

        $scenarioResults = $result['scenario_results'];
        $this->assertSame('pass', $scenarioResults['rust_worker_rust_php_python_clients']['status']);
        $this->assertSame(
            ['running' => 8, 'completed' => 8],
            $scenarioResults['rust_worker_rust_php_python_clients']['observed_outputs']['rust_query_results'],
        );

        $terminalFailure = $scenarioResults['rust_query_error_and_immutability'];
        $this->assertSame('fail', $terminalFailure['status']);
        $this->assertSame(
            'terminal signal operation timed out',
            $terminalFailure['observed_outputs']['probe_error']['message'],
        );
        $this->assertSame(
            'rust_snapshot_terminal_signal',
            $terminalFailure['observed_outputs']['route_and_lease_diagnostics']['probe_phase'],
        );

        foreach ([
            'python_worker_rust_client',
            'php_worker_rust_client',
            'rust_replayed_instance_state_query_after_cold_restart',
        ] as $siblingScenario) {
            $this->assertSame('pass', $scenarioResults[$siblingScenario]['status'], $siblingScenario);
            $this->assertSame('pass', $result['cell_verdicts'][$siblingScenario]['status'], $siblingScenario);
        }

        $this->assertContains('snapshot-terminal-signal-failed', $result['events']);
        $this->assertContains('start:python-worker-rust-client', $result['events']);
        $this->assertContains('start:php-worker-rust-client', $result['events']);
        $this->assertSame(
            'pass',
            $result['checkpoint_results']['rust_worker_rust_php_python_clients']['status'],
        );
        $this->assertSame(
            'fail',
            $result['checkpoint_results']['rust_query_error_and_immutability']['status'],
        );
    }

    public function test_host_runner_reports_crates_io_resolution_and_build_failures_as_machine_readable_evidence(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
errors = []
for code, phase, command, stderr in (
    (
        "rust_crate_resolution_failed",
        "cargo_generate_lockfile",
        ["cargo", "generate-lockfile"],
        "no matching package",
    ),
    (
        "rust_crate_build_failed",
        "cargo_build_locked_release",
        ["cargo", "build", "--locked", "--release"],
        "incompatible API",
    ),
):
    exc = RustCrateArtifactError(
        code,
        stderr,
        version="0.1.404",
        phase=phase,
        command_result=subprocess.CompletedProcess(
            args=command,
            returncode=101,
            stdout="",
            stderr=stderr,
        ),
    )
    errors.append(probe_error_payload(exc))
print(json.dumps(errors, sort_keys=True))
PY);

        $this->assertSame('rust_crate_resolution_failed', $result[0]['code']);
        $this->assertSame('cargo_generate_lockfile', $result[0]['phase']);
        $this->assertSame('no matching package', $result[0]['command']['stderr']);
        $this->assertSame('rust_crate_build_failed', $result[1]['code']);
        $this->assertSame('cargo_build_locked_release', $result[1]['phase']);
        $this->assertSame('incompatible API', $result[1]['command']['stderr']);
        foreach ($result as $error) {
            $this->assertSame('0.1.404', $error['requested_version']);
            $this->assertSame(101, $error['command']['exit_code']);
        }
    }

    public function test_host_runner_rejects_missing_or_changed_rust_terminal_rejection_reason(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
def complete_rust_error_outputs():
    observed = {}
    for evidence_key in SCENARIO_REQUIRED_EVIDENCE["rust_query_error_and_immutability"]:
        current = observed
        segments = evidence_key.split(".")
        for segment in segments[:-1]:
            current = current.setdefault(segment, {})
        current[segments[-1]] = True
    observed["terminal_signal"] = {
        "reason": "run_not_active",
        "rejection_reason": "run_not_active",
    }
    return observed


def runner_verdict(observed):
    status = (
        "pass"
        if has_required_evidence("rust_query_error_and_immutability", observed)
        else "fail"
    )
    scenario_results = {
        "rust_query_error_and_immutability": {"status": status},
    }
    findings = [] if status == "pass" else ["rust_query_error_and_immutability"]
    outcome = (
        "pass"
        if not findings and all(item["status"] == "pass" for item in scenario_results.values())
        else "non_passing"
    )
    return {"scenario_status": status, "outcome": outcome}


complete = complete_rust_error_outputs()
missing = complete_rust_error_outputs()
del missing["terminal_signal"]["rejection_reason"]
changed = complete_rust_error_outputs()
changed["terminal_signal"]["rejection_reason"] = "accepted"

print(json.dumps({
    "complete": runner_verdict(complete),
    "missing": runner_verdict(missing),
    "changed": runner_verdict(changed),
}, sort_keys=True))
PY);

        $this->assertSame(
            ['scenario_status' => 'pass', 'outcome' => 'pass'],
            $result['complete'],
        );
        foreach (['missing', 'changed'] as $mutation) {
            $this->assertSame('fail', $result[$mutation]['scenario_status'], $mutation);
            $this->assertSame('non_passing', $result[$mutation]['outcome'], $mutation);
        }
    }

    public function test_waterline_observer_scaffolds_laravel_app_before_runtime_environment_is_applied(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );
        $anchor = 'log_file = result_dir / "waterline-signals-queries-observer.log"';
        $anchorPosition = strpos($source, $anchor);
        $start = $anchorPosition === false ? false : strpos($source, "\n    create = run_command(", $anchorPosition);
        $end = $start === false ? false : strpos($source, "\n    if create.returncode != 0:", $start);

        if ($start === false || $end === false) {
            $this->fail('Unable to extract Waterline observer create-project command from host runner.');
        }

        $body = substr($source, $start + 1, $end - $start - 1);

        $this->assertStringContainsString('include_app_env: bool = True', $source);
        $this->assertStringContainsString('["composer", "create-project", "laravel/laravel"', $body);
        $this->assertStringContainsString('include_app_env=False', $body);
        $this->assertStringContainsString('image=waterline_php_docker_image()', $body);
        $this->assertStringNotContainsString('extra_env=waterline_env', $body);
    }

    public function test_waterline_observer_uses_mysql_capable_published_server_image(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );
        $anchor = 'def waterline_php_docker_image() -> str:';
        $anchorPosition = strpos($source, $anchor);
        $end = $anchorPosition === false ? false : strpos($source, "\n\ndef docker_volume_spec", $anchorPosition);

        if ($anchorPosition === false || $end === false) {
            $this->fail('Unable to extract Waterline observer Docker image helper from host runner.');
        }

        $body = substr($source, $anchorPosition, $end - $anchorPosition);

        $this->assertStringContainsString('DW_SIGNALS_QUERIES_WATERLINE_PHP_DOCKER_IMAGE', $body);
        $this->assertStringContainsString('durableworkflow/server:{server_version}', $body);
        $this->assertStringContainsString('return workflow_php_docker_image()', $body);
        $this->assertStringContainsString('"runtime_image": waterline_php_docker_image()', $source);
        $this->assertGreaterThanOrEqual(5, substr_count($source, 'image=waterline_php_docker_image()'));
        $this->assertGreaterThanOrEqual(5, substr_count($source, 'entrypoint=""'));
    }

    public function test_adversarial_probe_does_not_reuse_prior_probe_server_urls(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );
        $start = strpos($source, "\ndef run_adversarial_probe(");
        $end = $start === false ? false : strpos($source, "\n\ndef merge_probe_evidence(", $start);

        if ($start === false || $end === false) {
            $this->fail('Unable to extract adversarial probe from host runner.');
        }

        $body = substr($source, $start + 1, $end - $start - 1);

        $this->assertStringContainsString(
            'base_url = env_text("DW_SIGNALS_QUERIES_SERVER_URL") or env_text("DURABLE_WORKFLOW_SERVER_URL")',
            $body,
        );
        $this->assertStringContainsString('start_published_server(run_root, log_file)', $body);
        $this->assertStringNotContainsString('evidence_lookup(current_evidence', $body);
        $this->assertStringNotContainsString('"server_base_url", "server_url", "base_url"', $body);
    }

    public function test_host_runner_records_configured_baseline_overrides_as_non_published_sources(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );

        foreach ([
            '"configured_server_endpoint"',
            '"configured_server_image"',
            '"configured_cli_binary"',
            '"configured_python_environment"',
            '"docker_compose_configured_image_override"',
            'SERVER_PATCH_TAG_RE = re.compile(r"^\d+\.\d+\.\d+$")',
            'PUBLISHED_SERVER_IMAGE_REPOSITORIES',
            'published_server_image_install_proven(image, version)',
            'server_image_not_proved_reason(image, version)',
            'DW_SERVER_IMAGE must use an exact patch semver tag or an image digest',
            'DW_SERVER_VERSION {version!r} does not match DW_SERVER_IMAGE tag {tag!r}',
            '"durableworkflow_server_exact_tag_or_digest"',
            'status="not_proved"',
            'installed_from_public_artifact=False',
            'install_status = "pass" if install_outputs_cover_required_artifacts(install_outputs) else "not_covered"',
            'install_status == "pass"',
            'and has_required_evidence("python_worker_cli_and_sdk_baseline", python_sdk_outputs)',
            'and has_required_evidence("php_worker_cli_and_sdk_baseline", workflow_php_outputs)',
            '"status": install_status',
            '"status": python_sdk_status',
            '"status": workflow_php_status',
            'DW_SIGNALS_QUERIES_RUN_PHP_BASELINE_PROBE',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $this->assertStringNotContainsString('"published_server_endpoint"', $source);
    }

    public function test_host_runner_generates_published_php_worker_project_for_mirror_baseline(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
artifact_versions = {"workflow-php": "2.0.0-alpha.213"}
project = Path(tempfile.mkdtemp(prefix="dw-php-project-test."))
try:
    write_workflow_php_project(project)
    composer = json.loads((project / "composer.json").read_text(encoding="utf-8"))
    worker = (project / "php-counter-worker.php").read_text(encoding="utf-8")
    client = (project / "php-workflow-client.php").read_text(encoding="utf-8")
    print(json.dumps({
        "localhost_url": docker_host_base_url("http://127.0.0.1:8080"),
        "remote_url": docker_host_base_url("https://server.example.test:8443/base"),
        "package": composer["require"]["durable-workflow/workflow"],
        "worker_type": "conformance.counter.php" in worker,
        "worker_contracts": "workflowCommandContracts" in worker,
        "worker_query_executor": "WorkflowQueryTaskExecutor::CAPABILITY" in worker,
        "worker_uses_combined_tick": "$standaloneWorker->tick(" in worker,
        "worker_polls_queries_directly": "$standaloneWorker->processOneQueryTask(" in worker,
        "worker_polls_workflows_directly": "$standaloneWorker->processOneWorkflowTask(" in worker,
        "worker_retains_query_evidence": "sq_record_standalone_query_task($tickResult" in worker,
        "client_uses_workflow_client": "new WorkflowClient" in client,
    }, sort_keys=True))
finally:
    shutil.rmtree(project, ignore_errors=True)
PY);

        $this->assertSame('http://host.docker.internal:8080', $result['localhost_url']);
        $this->assertSame('https://server.example.test:8443/base', $result['remote_url']);
        $this->assertSame('2.0.0-alpha.213', $result['package']);
        $this->assertTrue($result['worker_type']);
        $this->assertTrue($result['worker_contracts']);
        $this->assertTrue($result['worker_query_executor']);
        $this->assertTrue($result['worker_uses_combined_tick']);
        $this->assertFalse($result['worker_polls_queries_directly']);
        $this->assertFalse($result['worker_polls_workflows_directly']);
        $this->assertTrue($result['worker_retains_query_evidence']);
        $this->assertTrue($result['client_uses_workflow_client']);
    }

    public function test_host_runner_records_configured_server_image_overrides_as_non_published_install_evidence(): void
    {
        $entries = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
artifact_versions = {"server": "0.2.224"}

os.environ.pop("DW_SERVER_IMAGE", None)
default_entry = server_install_entry([["docker", "compose"]])

os.environ["DW_SERVER_IMAGE"] = "durableworkflow/server:0.2.224"
exact_entry = server_install_entry([["docker", "compose"]])

os.environ["DW_SERVER_IMAGE"] = "durableworkflow/server@sha256:" + ("a" * 64)
digest_entry = server_install_entry([["docker", "compose"]])

os.environ["DW_SERVER_IMAGE"] = "durableworkflow/server:latest@sha256:" + ("b" * 64)
digest_with_rolling_tag_entry = server_install_entry([["docker", "compose"]])

os.environ["DW_SERVER_IMAGE"] = "localhost:5000/durableworkflow/server:0.2.224"
local_entry = server_install_entry([["docker", "compose"]])

os.environ["DW_SERVER_IMAGE"] = "durableworkflow/server:0.2.999"
mismatched_entry = server_install_entry([["docker", "compose"]])

os.environ["DW_SERVER_IMAGE"] = "durableworkflow/server:latest"
rolling_entry = server_install_entry([["docker", "compose"]])

print(json.dumps({
    "default": default_entry,
    "exact": exact_entry,
    "digest": digest_entry,
    "digest_with_rolling_tag": digest_with_rolling_tag_entry,
    "local": local_entry,
    "mismatched": mismatched_entry,
    "rolling": rolling_entry,
}, sort_keys=True))
PY);

        foreach (['default', 'exact', 'digest', 'digest_with_rolling_tag'] as $case) {
            $this->assertSame('pass', $entries[$case]['status'], $case);
            $this->assertSame('published_docker_image', $entries[$case]['source'], $case);
            $this->assertTrue($entries[$case]['installed_from_public_artifact'], $case);
        }

        foreach (['local', 'mismatched', 'rolling'] as $case) {
            $this->assertSame('not_proved', $entries[$case]['status'], $case);
            $this->assertSame('configured_server_image', $entries[$case]['source'], $case);
            $this->assertSame('docker_compose_configured_image_override', $entries[$case]['install_method'], $case);
            $this->assertFalse($entries[$case]['installed_from_public_artifact'], $case);
        }

        $this->assertSame(
            'DW_SERVER_IMAGE is not a durableworkflow/server published image reference',
            $entries['local']['not_proved_reason'],
        );
        $this->assertSame(
            "DW_SERVER_VERSION '0.2.224' does not match DW_SERVER_IMAGE tag '0.2.999'",
            $entries['mismatched']['not_proved_reason'],
        );
        $this->assertSame(
            'DW_SERVER_IMAGE must use an exact patch semver tag or an image digest',
            $entries['rolling']['not_proved_reason'],
        );
    }

    public function test_host_runner_extracts_batched_signal_observations_from_task_history_pages(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
def fake_http_json(base_url, path, **kwargs):
    return {
        "status_code": 200,
        "body": {
            "history_events": [
                {
                    "event_type": "SignalReceived",
                    "payload": {
                        "signal_name": "increment",
                        "signal_id": "sig-2",
                        "workflow_sequence": 2,
                        "arguments": {"payload": {"amount": 2}},
                    },
                },
                {
                    "event_type": "SignalReceived",
                    "payload": {
                        "signal_name": "increment",
                        "signal_id": "sig-3",
                        "workflow_sequence": 3,
                        "arguments": {"payload": {"amount": 3}},
                    },
                },
            ],
            "next_history_page_token": None,
        },
    }

globals()["http_json"] = fake_http_json
task = {
    "task_id": "task-1",
    "lease_owner": "worker-1",
    "workflow_task_attempt": 1,
    "history_events": [
        {"event_type": "WorkflowStarted", "payload": {}},
        {
            "event_type": "SignalReceived",
            "payload": {
                "signal_name": "increment",
                "signal_id": "sig-1",
                "workflow_sequence": 1,
                "arguments": {"payload": {"amount": 1}},
            },
        },
    ],
    "next_history_page_token": "page-2",
}
observations, events = increment_signal_observations_from_task("http://unused", "token", "default", task)
print(json.dumps({
    "amounts": [item["signal_amount"] for item in observations],
    "keys": [signal_observation_key(item) for item in observations],
    "event_types": [event["event_type"] for event in events],
}, sort_keys=True))
PY);

        $this->assertSame([1, 2, 3], $result['amounts']);
        $this->assertSame(['signal:sig-1', 'signal:sig-2', 'signal:sig-3'], $result['keys']);
        $this->assertSame(
            ['WorkflowStarted', 'SignalReceived', 'SignalReceived', 'SignalReceived'],
            $result['event_types'],
        );
    }

    public function test_host_runner_extracts_cross_language_signal_amounts_from_official_avro_envelopes(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
import avro.io
import avro.schema

def official_avro_generic_json(value):
    buffer = io.BytesIO()
    writer = avro.io.DatumWriter(avro.schema.parse(AVRO_GENERIC_WRAPPER_SCHEMA_JSON))
    writer.write(
        {
            "json": json.dumps(value, separators=(",", ":")),
            "version": 1,
        },
        avro.io.BinaryEncoder(buffer),
    )
    return base64.b64encode(b"\x00" + buffer.getvalue()).decode("ascii")

events = [
    {
        "event_type": "SignalReceived",
        "payload": {
            "signal_name": "increment",
            "signal_id": "sig-avro-php",
            "arguments": {
                "codec": "avro",
                "blob": official_avro_generic_json({"amount": 6}),
            },
        },
    },
    {
        "event_type": "SignalReceived",
        "payload": {
            "signal_name": "increment",
            "signal_id": "sig-avro-python",
            "arguments": {
                "codec": "avro",
                "blob": official_avro_generic_json([7]),
            },
        },
    },
    {
        "event_type": "SignalReceived",
        "payload": {
            "signal_name": "increment",
            "signal_id": "sig-avro-rust",
            "arguments": {
                "codec": "avro",
                "blob": official_avro_generic_json({"n": "8"}),
            },
        },
    },
]

print(json.dumps({
    "amounts": increment_signal_amounts_from_history_events(events),
    "json_fallback_amounts": [
        amount_from_arguments(decode_json_blob(json.dumps({"amount": 9}))),
        amount_from_arguments(decode_json_blob(
            base64.b64encode(json.dumps([10]).encode("utf-8")).decode("ascii")
        )),
    ],
    "malformed": [
        decode_json_blob("not json or base64"),
        decode_json_blob(base64.b64encode(b"\x00\xff").decode("ascii")),
    ],
}, sort_keys=True))
PY);

        $this->assertSame([6, 7, 8], $result['amounts']);
        $this->assertSame([9, 10], $result['json_fallback_amounts']);
        $this->assertSame([null, null], $result['malformed']);
    }

    public function test_host_runner_uses_the_official_python_avro_runtime(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );

        foreach ([
            'import avro.io',
            'import avro.schema',
            'avro.io.BinaryDecoder(',
            'avro.io.DatumReader(',
            'avro.schema.parse(AVRO_GENERIC_WRAPPER_SCHEMA_JSON)',
            'add_python_sdk_avro_dependency(',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $this->assertStringNotContainsString('def decode_' . 'avro_long(', $source);
    }

    public function test_host_runner_public_snapshot_reads_control_plane_history_events(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
paths = []

def fake_http_json(base_url, path, **kwargs):
    paths.append(path)
    if path == api_path("workflows", "wf-terminal", "runs", "run-terminal"):
        return {
            "status_code": 200,
            "body": {
                "run_id": "run-terminal",
                "status": "completed",
                "result": {"counter": 0},
            },
        }
    if path == api_path("workflows", "wf-terminal", "runs", "run-terminal", "history") + "?page_size=1000":
        return {
            "status_code": 200,
            "body": {
                "events": [
                    {"event_type": "WorkflowStarted", "payload": {}},
                    {"event_type": "WorkflowCompleted", "payload": {}},
                ],
                "next_page_token": None,
            },
        }
    raise AssertionError(f"unexpected path {path}")

globals()["http_json"] = fake_http_json
snapshot = workflow_public_snapshot(
    "http://unused",
    "token",
    "default",
    "wf-terminal",
    "run-terminal",
)

print(json.dumps({
    "paths": paths,
    "snapshot": snapshot,
}, sort_keys=True))
PY);

        $this->assertSame(
            [
                '/api/workflows/wf-terminal/runs/run-terminal',
                '/api/workflows/wf-terminal/runs/run-terminal/history?page_size=1000',
            ],
            $result['paths'],
        );
        $this->assertSame(2, $result['snapshot']['history_event_count']);
        $this->assertSame(
            ['WorkflowStarted', 'WorkflowCompleted'],
            $result['snapshot']['history_event_types'],
        );
    }

    public function test_host_runner_public_snapshot_falls_back_to_legacy_history_events(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
current = public_history_events({
    "events": [
        {"event_type": "WorkflowStarted"},
        {"event_type": "WorkflowCompleted"},
    ],
})
legacy = public_history_events({
    "history_events": [
        {"event_type": "WorkflowStarted"},
    ],
})
missing = public_history_events({"items": []})

print(json.dumps({
    "current_count": len(current) if current is not None else None,
    "legacy_count": len(legacy) if legacy is not None else None,
    "missing": missing,
}, sort_keys=True))
PY);

        $this->assertSame(2, $result['current_count']);
        $this->assertSame(1, $result['legacy_count']);
        $this->assertNull($result['missing']);
    }

    public function test_host_runner_only_compares_observed_history_counts(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
print(json.dumps({
    "same": observed_count_changed(
        {"history_event_count": 2},
        {"history_event_count": 2},
        "history_event_count",
    ),
    "changed": observed_count_changed(
        {"history_event_count": 2},
        {"history_event_count": 3},
        "history_event_count",
    ),
    "missing_after": observed_count_changed(
        {"history_event_count": 2},
        {},
        "history_event_count",
    ),
    "bool_value": observed_count_changed(
        {"history_event_count": False},
        {"history_event_count": 2},
        "history_event_count",
    ),
}, sort_keys=True))
PY);

        $this->assertFalse($result['same']);
        $this->assertTrue($result['changed']);
        $this->assertNull($result['missing_after']);
        $this->assertNull($result['bool_value']);
    }

    public function test_host_runner_collects_ordered_signal_evidence_from_one_batched_task(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
poll_calls = []
completed_conditions = []

def fake_poll_workflow_task(base_url, token, namespace, worker_id, task_queue, timeout=45.0):
    poll_calls.append(timeout)
    return {
        "status_code": 200,
        "body": {
            "task": {
                "task_id": "ordered-task-1",
                "lease_owner": "worker-1",
                "workflow_task_attempt": 1,
                "history_events": [
                    {
                        "event_type": "SignalReceived",
                        "payload": {
                            "signal_name": "increment",
                            "signal_id": f"ordered-{amount}",
                            "workflow_sequence": amount,
                            "arguments": {"payload": {"amount": amount}},
                        },
                    }
                    for amount in range(1, 11)
                ],
            },
        },
    }

def fake_complete_open_wait(base_url, token, namespace, task, condition_key):
    completed_conditions.append(condition_key)
    return {"status_code": 200, "body": {}}

globals()["poll_workflow_task"] = fake_poll_workflow_task
globals()["complete_open_wait"] = fake_complete_open_wait

seen = set()
amounts = []
tasks = []
collect_increment_signal_observations(
    "http://unused",
    "token",
    "default",
    "worker-1",
    "queue-1",
    seen,
    amounts,
    tasks,
    "ordered-after",
    "ordered",
    10,
    Path("/tmp/signals-queries-test.log"),
)

print(json.dumps({
    "amounts": amounts,
    "poll_count": len(poll_calls),
    "completed_conditions": completed_conditions,
    "task_signal_amounts": tasks[0]["signal_amounts"],
}, sort_keys=True))
PY);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $result['amounts']);
        $this->assertSame(1, $result['poll_count']);
        $this->assertSame(['ordered-after-10'], $result['completed_conditions']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $result['task_signal_amounts']);
    }

    public function test_host_runner_query_responder_waits_for_query_task_after_empty_poll(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
poll_paths = []
complete_bodies = []
heartbeat_bodies = []
sleep_intervals = []
original_sleep = time.sleep
time.sleep = lambda seconds: sleep_intervals.append(seconds)

try:
    def fake_http_json(base_url, path, **kwargs):
        if path.endswith("/worker/heartbeat"):
            heartbeat_bodies.append(kwargs.get("body"))
            return {"status_code": 200, "body": {"acknowledged": True}}

        if path.endswith("/worker/query-tasks/poll"):
            poll_paths.append(path)
            if len(poll_paths) == 1:
                return {"status_code": 200, "body": {"task": None, "poll_status": "empty"}}
            return {
                "status_code": 200,
                "body": {
                    "task": {
                        "query_task_id": "query-task-1",
                        "query_task_attempt": 1,
                        "lease_owner": "leased-worker",
                    },
                },
            }

        if path.endswith("/worker/query-tasks/query-task-1/complete"):
            complete_bodies.append(kwargs.get("body"))
            return {"status_code": 200, "body": {"outcome": "completed"}}

        raise AssertionError(f"unexpected path {path}")

    globals()["http_json"] = fake_http_json
    holder = {}
    answer_next_query_task(
        "http://unused",
        "token",
        "default",
        "polling-worker",
        "queue-1",
        55,
        Path("/tmp/signals-queries-query-test.log"),
        holder,
        poll_timeout=2,
    )
finally:
    time.sleep = original_sleep

print(json.dumps({
    "poll_count": len(poll_paths),
    "heartbeat_count": len(heartbeat_bodies),
    "complete_bodies": complete_bodies,
    "empty_poll_count": len(holder.get("empty_polls", [])),
    "error": holder.get("error"),
    "completed_at_present": "query_completed_at" in holder,
}, sort_keys=True))
PY);

        $this->assertSame(2, $result['heartbeat_count']);
        $this->assertSame(2, $result['poll_count']);
        $this->assertSame(1, $result['empty_poll_count']);
        $this->assertNull($result['error']);
        $this->assertTrue($result['completed_at_present']);
        $this->assertSame('leased-worker', $result['complete_bodies'][0]['lease_owner']);
        $this->assertSame(55, $result['complete_bodies'][0]['result']);
    }

    public function test_host_runner_heartbeat_guard_keeps_synthetic_worker_eligible(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
heartbeat_calls = []

def fake_heartbeat_worker(base_url, token, namespace, worker_id):
    heartbeat_calls.append(worker_id)
    return {
        "status_code": 200,
        "body": {
            "worker_id": worker_id,
            "acknowledged": True,
            "heartbeat_interval_seconds": 10,
            "stale_after_seconds": 30,
        },
    }

globals()["heartbeat_worker"] = fake_heartbeat_worker
guard = WorkerHeartbeatGuard(
    "http://unused",
    "token",
    "default",
    "ordered-worker",
    Path("/tmp/signals-queries-heartbeat-guard-test.log"),
    interval_seconds=0.01,
)
guard.start()
eligible = guard.wait_until_eligible(timeout=1)
deadline = time.time() + 1
while len(heartbeat_calls) < 3 and time.time() < deadline:
    time.sleep(0.01)
before_stop = guard.snapshot()
guard.stop()
count_after_stop = len(heartbeat_calls)
time.sleep(0.03)
after_stop = guard.snapshot()

print(json.dumps({
    "eligible": eligible,
    "heartbeat_count": count_after_stop,
    "count_stable_after_stop": len(heartbeat_calls) == count_after_stop,
    "success_count": before_stop["success_count"],
    "latest_success": before_stop["latest_success"],
    "stopped_at": after_stop["stopped_at"],
}, sort_keys=True))
PY);

        $this->assertTrue($result['eligible']);
        $this->assertGreaterThanOrEqual(3, $result['heartbeat_count']);
        $this->assertSame($result['heartbeat_count'], $result['success_count']);
        $this->assertTrue($result['count_stable_after_stop']);
        $this->assertTrue($result['latest_success']['acknowledged']);
        $this->assertSame(30, $result['latest_success']['stale_after_seconds']);
        $this->assertNotNull($result['stopped_at']);
    }

    public function test_host_runner_query_responder_records_claim_time_worker_eligibility(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
ready = threading.Event()
events = []

def fake_http_json(base_url, path, **kwargs):
    if path.endswith("/worker/heartbeat"):
        events.append("heartbeat")
        return {"status_code": 200, "body": {"acknowledged": True}}

    if path.endswith("/worker/query-tasks/poll"):
        events.append("poll")
        assert ready.is_set()
        return {
            "status_code": 200,
            "body": {
                "task": {
                    "query_task_id": "ordered-query-task-1",
                    "query_task_attempt": 1,
                    "workflow_id": "wf-ordered",
                    "run_id": "run-ordered",
                    "query_name": "state",
                    "task_queue": "ordered-queue",
                    "lease_owner": "ordered-worker",
                },
            },
        }

    if path.endswith("/workers/ordered-worker"):
        events.append("eligibility")
        return {
            "status_code": 200,
            "body": {
                "worker_id": "ordered-worker",
                "task_queue": "ordered-queue",
                "status": "active",
                "capabilities": ["query_tasks"],
                "last_heartbeat_at": "2026-07-12T12:00:00Z",
                "stale_after_seconds": 30,
            },
        }

    if path.endswith("/worker/query-tasks/ordered-query-task-1/complete"):
        events.append("complete")
        return {"status_code": 200, "body": {"outcome": "completed"}}

    raise AssertionError(f"unexpected path {path}")

globals()["http_json"] = fake_http_json
holder = {}
answer_next_query_task(
    "http://unused",
    "token",
    "default",
    "ordered-worker",
    "ordered-queue",
    55,
    Path("/tmp/signals-queries-query-eligibility-test.log"),
    holder,
    poll_timeout=2,
    ready_event=ready,
    capture_claim_eligibility=True,
)

print(json.dumps({
    "events": events,
    "ready": ready.is_set(),
    "heartbeat_acknowledged_at": holder.get("heartbeat_acknowledged_at"),
    "query_task": holder.get("query_task"),
    "eligibility": holder.get("worker_eligibility_when_claimed"),
    "complete": holder.get("complete"),
    "error": holder.get("error"),
}, sort_keys=True))
PY);

        $this->assertNull($result['error']);
        $this->assertTrue($result['ready']);
        $this->assertSame(['heartbeat', 'poll', 'eligibility', 'complete'], $result['events']);
        $this->assertNotNull($result['heartbeat_acknowledged_at']);
        $this->assertSame('ordered-worker', $result['query_task']['lease_owner']);
        $this->assertTrue($result['eligibility']['eligible']);
        $this->assertSame('active', $result['eligibility']['status']);
        $this->assertSame(200, $result['complete']['status_code']);
    }

    public function test_host_runner_rejects_ordered_delivery_when_responder_was_not_eligible_at_claim(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
observed = {
    "workflow_id": "wf-ordered",
    "run_id": "run-ordered",
    "rapid_increment_inputs": list(range(1, 11)),
    "accepted_signal_inputs": list(range(1, 11)),
    "accepted_signal_total": 55,
    "queried_total": 55,
    "history_signal_order": list(range(1, 11)),
    "final_run_status": "waiting",
}
missing_rejected = ordered_delivery_observations_agree(observed)
missing_evidence = ordered_delivery_missing_current_evidence(observed)
observed["ordered_query_responder"] = {
    "worker_id": "ordered-worker",
    "task_queue": "ordered-queue",
    "query_claimed_at": "2026-07-12T12:00:01Z",
    "eligible_when_claimed": False,
    "claim_eligibility": {
        "eligible": True,
        "worker_id": "ordered-worker",
        "task_queue": "ordered-queue",
        "status": "active",
        "capabilities": ["query_tasks"],
        "last_heartbeat_at": "2026-07-12T12:00:00Z",
    },
    "claimed_query_task": {
        "query_task_id": "ordered-query-task-1",
        "workflow_id": "wf-ordered",
        "run_id": "run-ordered",
        "query_name": "state",
        "task_queue": "ordered-queue",
        "lease_owner": "ordered-worker",
    },
    "query_task_completion": {"status_code": 200},
}
ineligible_rejected = ordered_delivery_observations_agree(observed)
observed["ordered_query_responder"]["eligible_when_claimed"] = True
accepted = ordered_delivery_observations_agree(observed)

print(json.dumps({
    "missing_rejected": missing_rejected,
    "missing_evidence": missing_evidence,
    "ineligible_rejected": ineligible_rejected,
    "accepted": accepted,
}, sort_keys=True))
PY);

        $this->assertFalse($result['missing_rejected']);
        $this->assertContains('ordered_query_responder', $result['missing_evidence']);
        $this->assertFalse($result['ineligible_rejected']);
        $this->assertTrue($result['accepted']);
    }

    public function test_host_runner_rejects_ordered_responder_claim_for_different_query_identity(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
observed = {
    "workflow_id": "wf-ordered",
    "run_id": "run-ordered",
    "rapid_increment_inputs": list(range(1, 11)),
    "accepted_signal_inputs": list(range(1, 11)),
    "accepted_signal_total": 55,
    "queried_total": 55,
    "history_signal_order": list(range(1, 11)),
    "final_run_status": "waiting",
    "ordered_query_responder": {
        "worker_id": "ordered-worker",
        "task_queue": "ordered-queue",
        "query_claimed_at": "2026-07-12T12:00:01Z",
        "eligible_when_claimed": True,
        "claim_eligibility": {
            "eligible": True,
            "worker_id": "ordered-worker",
            "task_queue": "ordered-queue",
            "status": "active",
            "capabilities": ["query_tasks"],
            "last_heartbeat_at": "2026-07-12T12:00:00Z",
        },
        "claimed_query_task": {
            "query_task_id": "ordered-query-task-1",
            "workflow_id": "wf-ordered",
            "run_id": "run-ordered",
            "query_name": "state",
            "task_queue": "ordered-queue",
            "lease_owner": "ordered-worker",
        },
        "query_task_completion": {"status_code": 200},
    },
}
matching_claim_accepted = ordered_delivery_observations_agree(observed)
mismatches = {}
for field, unrelated_value in {
    "workflow_id": "wf-unrelated",
    "run_id": "run-unrelated",
    "query_name": "unrelated-query",
}.items():
    claimed = observed["ordered_query_responder"]["claimed_query_task"]
    expected_value = claimed[field]
    claimed[field] = unrelated_value
    mismatches[field] = ordered_delivery_observations_agree(observed)
    claimed[field] = expected_value

print(json.dumps({
    "matching_claim_accepted": matching_claim_accepted,
    "mismatches": mismatches,
}, sort_keys=True))
PY);

        $this->assertTrue($result['matching_claim_accepted']);
        $this->assertSame(
            [
                'query_name' => false,
                'run_id' => false,
                'workflow_id' => false,
            ],
            $result['mismatches'],
        );
    }

    public function test_host_runner_requires_exact_post_error_recovery_claim_and_immutable_history(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
observed = {
    "workflow_id": "wf-adversarial",
    "run_id": "run-adversarial",
    "worker_id": "adversarial-worker",
    "task_queue": "adversarial-queue",
    "unknown_signal": {"status_code": 404, "reason": "unknown_signal"},
    "missing_workflow_signal": {"status_code": 404, "reason": "instance_not_found"},
    "missing_workflow_query": {"status_code": 404, "reason": "instance_not_found"},
    "query_not_found": {"status_code": 404, "reason": "query_not_found"},
    "rejected_unknown_query": {"status_code": 404, "reason": "query_not_found"},
    "known_query_after_unknown_errors": {"status_code": 200, "body": {"result": 0}},
    "known_query_after_unknown_expected": 0,
    "known_query_after_unknown_result": 0,
    "history_and_commands_before_rejected_requests": {
        "history_event_count": 2,
        "workflow_command_count": 1,
    },
    "history_and_commands_after_recovery_query": {
        "history_event_count": 2,
        "workflow_command_count": 1,
    },
    "history_and_commands_after_all_requests": {
        "history_event_count": 2,
        "workflow_command_count": 1,
    },
    "rejected_requests_and_recovery_appended_no_history": True,
    "rejected_requests_and_recovery_emitted_no_workflow_commands": True,
    "post_error_query_responder": {
        "worker_id": "adversarial-worker",
        "task_queue": "adversarial-queue",
        "heartbeat_before_poll": {"status_code": 200},
        "heartbeat_acknowledged_at": "2026-07-12T12:00:00Z",
        "query_poll_ready_at": "2026-07-12T12:00:00Z",
        "query_claimed_at": "2026-07-12T12:00:01Z",
        "eligible_when_claimed": True,
        "claim_eligibility": {
            "eligible": True,
            "worker_id": "adversarial-worker",
            "task_queue": "adversarial-queue",
            "status": "active",
            "capabilities": ["query_tasks"],
            "last_heartbeat_at": "2026-07-12T12:00:00Z",
        },
        "claimed_query_task": {
            "query_task_id": "query-task-1",
            "workflow_id": "wf-adversarial",
            "run_id": "run-adversarial",
            "query_name": "state",
            "task_queue": "adversarial-queue",
            "lease_owner": "adversarial-worker",
        },
        "query_task_completion": {"status_code": 200},
        "responder_error": None,
        "responder_timed_out": False,
    },
}

accepted = has_required_evidence("unknown_signal_and_query_errors", observed)
rejections = {}
responder = observed["post_error_query_responder"]
for field, bad_value in {
    "responder_timed_out": True,
    "eligible_when_claimed": False,
}.items():
    original = responder[field]
    responder[field] = bad_value
    rejections[field] = has_required_evidence("unknown_signal_and_query_errors", observed)
    responder[field] = original

claimed = responder["claimed_query_task"]
for field, bad_value in {
    "workflow_id": "wf-unrelated",
    "run_id": "run-unrelated",
    "query_name": "unrelated",
    "task_queue": "unrelated-queue",
    "lease_owner": "unrelated-worker",
}.items():
    original = claimed[field]
    claimed[field] = bad_value
    rejections[f"claim.{field}"] = has_required_evidence("unknown_signal_and_query_errors", observed)
    claimed[field] = original

observed["history_and_commands_after_recovery_query"]["history_event_count"] = 3
history_mutation_rejected = has_required_evidence("unknown_signal_and_query_errors", observed)

print(json.dumps({
    "accepted": accepted,
    "rejections": rejections,
    "history_mutation_rejected": history_mutation_rejected,
}, sort_keys=True))
PY);

        $this->assertTrue($result['accepted']);
        $this->assertNotEmpty($result['rejections']);
        foreach ($result['rejections'] as $accepted) {
            $this->assertFalse($accepted);
        }
        $this->assertFalse($result['history_mutation_rejected']);
    }

    public function test_adversarial_recovery_query_is_synchronized_directly_after_server_errors(): void
    {
        $body = file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );
        $this->assertIsString($body);

        $adversarialProbe = strpos($body, 'def run_adversarial_probe(');
        $unknownQuery = strpos($body, 'missing_workflow_query = http_json(', $adversarialProbe);
        $recoveryReady = strpos($body, 'if not responder_ready.wait(timeout=15):', $unknownQuery);
        $recoveryQuery = strpos($body, 'post_error_query = http_json(', $recoveryReady);
        $optionalClient = strpos($body, 'cli_invalid_signal = cli_json_sample(', $recoveryQuery);

        $this->assertIsInt($adversarialProbe);
        $this->assertIsInt($unknownQuery);
        $this->assertIsInt($recoveryReady);
        $this->assertIsInt($recoveryQuery);
        $this->assertIsInt($optionalClient);
        $this->assertLessThan($unknownQuery, $adversarialProbe);
        $this->assertLessThan($recoveryReady, $unknownQuery);
        $this->assertLessThan($recoveryQuery, $recoveryReady);
        $this->assertLessThan($optionalClient, $recoveryQuery);
        $this->assertStringContainsString(
            'initial_task = task_from_poll(initial_poll, "adversarial initial state")',
            $body,
        );
        $this->assertStringContainsString('"capture_claim_eligibility": True', $body);
        $this->assertStringContainsString(
            'history_and_commands_after_recovery_query = workflow_public_snapshot(',
            $body,
        );
    }

    public function test_host_runner_query_responder_processes_pending_workflow_task_before_query_task(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
heartbeat_bodies = []
query_poll_bodies = []
workflow_poll_bodies = []
workflow_complete_bodies = []
query_complete_bodies = []

def fake_http_json(base_url, path, **kwargs):
    if path.endswith("/worker/heartbeat"):
        heartbeat_bodies.append(kwargs.get("body"))
        return {"status_code": 200, "body": {"acknowledged": True}}

    if path.endswith("/worker/query-tasks/poll"):
        query_poll_bodies.append(kwargs.get("body"))
        if len(query_poll_bodies) == 1:
            return {"status_code": 200, "body": {"task": None, "poll_status": "workflow_task_pending"}}
        return {
            "status_code": 200,
            "body": {
                "task": {
                    "query_task_id": "query-task-1",
                    "query_task_attempt": 1,
                    "lease_owner": "leased-worker",
                },
            },
        }

    if path.endswith("/worker/workflow-tasks/poll"):
        workflow_poll_bodies.append(kwargs.get("body"))
        return {
            "status_code": 200,
            "body": {
                "task": {
                    "task_id": "workflow-task-1",
                    "workflow_task_attempt": 1,
                    "lease_owner": "leased-worker",
                },
            },
        }

    if path.endswith("/worker/workflow-tasks/workflow-task-1/complete"):
        workflow_complete_bodies.append(kwargs.get("body"))
        return {"status_code": 200, "body": {"outcome": "completed"}}

    if path.endswith("/worker/query-tasks/query-task-1/complete"):
        query_complete_bodies.append(kwargs.get("body"))
        return {"status_code": 200, "body": {"outcome": "completed"}}

    raise AssertionError(f"unexpected path {path}")

globals()["http_json"] = fake_http_json
holder = {}
answer_next_query_task(
    "http://unused",
    "token",
    "default",
    "polling-worker",
    "queue-1",
    55,
    Path("/tmp/signals-queries-query-workflow-pending-test.log"),
    holder,
    workflow_condition_key="baseline-open",
    poll_timeout=5,
)

print(json.dumps({
    "heartbeat_count": len(heartbeat_bodies),
    "query_poll_count": len(query_poll_bodies),
    "workflow_poll_count": len(workflow_poll_bodies),
    "workflow_complete_bodies": workflow_complete_bodies,
    "query_complete_bodies": query_complete_bodies,
    "workflow_task_pending_count": holder.get("workflow_task_pending_count"),
    "error": holder.get("error"),
}, sort_keys=True))
PY);

        $this->assertNull($result['error']);
        $this->assertSame(3, $result['heartbeat_count']);
        $this->assertSame(2, $result['query_poll_count']);
        $this->assertSame(1, $result['workflow_poll_count']);
        $this->assertSame(1, $result['workflow_task_pending_count']);
        $this->assertSame(
            'open_condition_wait',
            $result['workflow_complete_bodies'][0]['commands'][0]['type'],
        );
        $this->assertSame(
            'baseline-open',
            $result['workflow_complete_bodies'][0]['commands'][0]['condition_key'],
        );
        $this->assertSame(55, $result['query_complete_bodies'][0]['result']);
    }

    public function test_host_runner_query_responder_uses_bounded_claim_polls(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
heartbeat_bodies = []
poll_bodies = []
poll_timeouts = []
complete_bodies = []

def fake_http_json(base_url, path, **kwargs):
    if path.endswith("/worker/heartbeat"):
        heartbeat_bodies.append(kwargs.get("body"))
        return {"status_code": 200, "body": {"acknowledged": True}}

    if path.endswith("/worker/query-tasks/poll"):
        poll_bodies.append(kwargs.get("body"))
        poll_timeouts.append(kwargs.get("timeout"))
        return {
            "status_code": 200,
            "body": {
                "task": {
                    "query_task_id": "query-task-1",
                    "query_task_attempt": 1,
                    "lease_owner": "leased-worker",
                },
            },
        }

    if path.endswith("/worker/query-tasks/query-task-1/complete"):
        complete_bodies.append(kwargs.get("body"))
        return {"status_code": 200, "body": {"outcome": "completed"}}

    raise AssertionError(f"unexpected path {path}")

globals()["http_json"] = fake_http_json
holder = {}
answer_next_query_task(
    "http://unused",
    "token",
    "default",
    "polling-worker",
    "queue-1",
    55,
    Path("/tmp/signals-queries-query-timeout-test.log"),
    holder,
    poll_timeout=12,
)

print(json.dumps({
    "heartbeat_bodies": heartbeat_bodies,
    "poll_bodies": poll_bodies,
    "poll_timeouts": poll_timeouts,
    "complete_bodies": complete_bodies,
    "error": holder.get("error"),
}, sort_keys=True))
PY);

        $this->assertNull($result['error']);
        $this->assertSame('polling-worker', $result['heartbeat_bodies'][0]['worker_id']);
        $this->assertSame(2, $result['poll_bodies'][0]['timeout_seconds']);
        $this->assertLessThanOrEqual(7.0, $result['poll_timeouts'][0]);
        $this->assertSame(55, $result['complete_bodies'][0]['result']);
    }

    public function test_host_runner_query_responder_retries_a_timed_out_claim_poll(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
poll_bodies = []
poll_timeouts = []

def fake_http_json(base_url, path, **kwargs):
    if path.endswith("/worker/heartbeat"):
        return {"status_code": 200, "body": {"acknowledged": True}}

    if path.endswith("/worker/query-tasks/poll"):
        poll_bodies.append(kwargs.get("body"))
        poll_timeouts.append(kwargs.get("timeout"))
        if len(poll_bodies) == 1:
            raise TimeoutError("delayed claim poll")
        return {
            "status_code": 200,
            "body": {
                "task": {
                    "query_task_id": "query-task-retried-1",
                    "query_task_attempt": 1,
                    "lease_owner": "leased-worker",
                },
            },
        }

    if path.endswith("/worker/query-tasks/query-task-retried-1/complete"):
        return {"status_code": 200, "body": {"outcome": "completed"}}

    raise AssertionError(f"unexpected path {path}")

globals()["http_json"] = fake_http_json
holder = {}
answer_next_query_task(
    "http://unused",
    "token",
    "default",
    "polling-worker",
    "queue-1",
    55,
    Path("/tmp/signals-queries-query-retry-test.log"),
    holder,
    poll_timeout=12,
)

print(json.dumps({
    "poll_bodies": poll_bodies,
    "poll_timeouts": poll_timeouts,
    "poll_attempt_count": holder.get("query_poll_attempt_count"),
    "poll_transport_errors": holder.get("poll_transport_errors"),
    "query_task_id": holder.get("query_task", {}).get("query_task_id"),
    "error": holder.get("error"),
}, sort_keys=True))
PY);

        $this->assertNull($result['error']);
        $this->assertSame(2, $result['poll_attempt_count']);
        $this->assertCount(2, $result['poll_bodies']);
        $this->assertCount(1, $result['poll_transport_errors']);
        $this->assertSame(1, $result['poll_transport_errors'][0]['attempt']);
        $this->assertSame('TimeoutError: delayed claim poll', $result['poll_transport_errors'][0]['error']);
        $this->assertSame('query-task-retried-1', $result['query_task_id']);
        foreach ($result['poll_bodies'] as $body) {
            $this->assertSame(2, $body['timeout_seconds']);
        }
        foreach ($result['poll_timeouts'] as $timeout) {
            $this->assertLessThanOrEqual(7.0, $timeout);
        }
    }

    public function test_host_runner_query_responder_can_answer_from_query_task_history(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
complete_bodies = []
heartbeat_bodies = []

def fake_http_json(base_url, path, **kwargs):
    if path.endswith("/worker/heartbeat"):
        heartbeat_bodies.append(kwargs.get("body"))
        return {"status_code": 200, "body": {"acknowledged": True}}

    if path.endswith("/worker/query-tasks/poll"):
        return {
            "status_code": 200,
            "body": {
                "task": {
                    "query_task_id": "ordered-query-task-1",
                    "query_task_attempt": 1,
                    "lease_owner": "leased-worker",
                    "history_events": [
                        {
                            "event_type": "SignalReceived",
                            "payload": {
                                "signal_name": "increment",
                                "signal_id": "ordered-signal-2",
                                "workflow_sequence": 2,
                                "arguments": {"payload": {"amount": 2}},
                            },
                        },
                        {
                            "event_type": "SignalReceived",
                            "payload": {
                                "signal_name": "increment",
                                "signal_id": "ordered-signal-4",
                                "workflow_sequence": 4,
                                "arguments": {"payload": {"amount": 4}},
                            },
                        },
                    ],
                },
            },
        }

    if path.endswith("/worker/query-tasks/ordered-query-task-1/complete"):
        complete_bodies.append(kwargs.get("body"))
        return {"status_code": 200, "body": {"outcome": "completed"}}

    raise AssertionError(f"unexpected path {path}")

def result_from_history(task, holder):
    order = increment_signal_amounts_from_history_events(task.get("history_events"))
    holder["computed_order"] = order
    return sum(order)

globals()["http_json"] = fake_http_json
holder = {}
answer_next_query_task(
    "http://unused",
    "token",
    "default",
    "polling-worker",
    "queue-1",
    result_from_history,
    Path("/tmp/signals-queries-query-history-test.log"),
    holder,
    poll_timeout=2,
)

print(json.dumps({
    "heartbeat_count": len(heartbeat_bodies),
    "complete_bodies": complete_bodies,
    "computed_order": holder.get("computed_order"),
    "history_signal_order": holder.get("history_signal_order"),
    "result": holder.get("result"),
    "error": holder.get("error"),
}, sort_keys=True))
PY);

        $this->assertNull($result['error']);
        $this->assertSame(1, $result['heartbeat_count']);
        $this->assertSame([2, 4], $result['computed_order']);
        $this->assertSame([2, 4], $result['history_signal_order']);
        $this->assertSame(6, $result['result']);
        $this->assertSame(6, $result['complete_bodies'][0]['result']);
    }

    public function test_host_runner_accepts_single_observed_duplicate_signal_when_no_second_task_arrives(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
poll_calls = []
completed_conditions = []

def fake_poll_workflow_task(base_url, token, namespace, worker_id, task_queue, timeout=45.0):
    poll_calls.append(timeout)
    if len(poll_calls) == 1:
        return {
            "status_code": 200,
            "body": {
                "task": {
                    "task_id": "dedup-task-1",
                    "lease_owner": "worker-1",
                    "workflow_task_attempt": 1,
                    "history_events": [
                        {
                            "event_type": "SignalReceived",
                            "payload": {
                                "signal_name": "increment",
                                "signal_id": "dedup-1",
                                "workflow_sequence": 1,
                                "arguments": {"payload": {"amount": 7}},
                            },
                        },
                    ],
                },
            },
        }
    return {"status_code": 204, "body": {}}

def fake_complete_open_wait(base_url, token, namespace, task, condition_key):
    completed_conditions.append(condition_key)
    return {"status_code": 200, "body": {}}

globals()["poll_workflow_task"] = fake_poll_workflow_task
globals()["complete_open_wait"] = fake_complete_open_wait

seen = set()
amounts = []
tasks = []
collect_increment_signal_observations(
    "http://unused",
    "token",
    "default",
    "worker-1",
    "queue-1",
    seen,
    amounts,
    tasks,
    "dedup-after",
    "duplicate",
    2,
    Path("/tmp/signals-queries-test.log"),
    poll_timeout=5,
    allow_exhausted_after_observation=True,
)

print(json.dumps({
    "amounts": amounts,
    "poll_count": len(poll_calls),
    "completed_conditions": completed_conditions,
    "task_signal_amounts": tasks[0]["signal_amounts"],
}, sort_keys=True))
PY);

        $this->assertSame([7], $result['amounts']);
        $this->assertSame(2, $result['poll_count']);
        $this->assertSame(['dedup-after-1'], $result['completed_conditions']);
        $this->assertSame([7], $result['task_signal_amounts']);
    }

    public function test_host_runner_requires_exact_smoke_fields_before_marking_smoke_scenarios_pass(): void
    {
        $result = $this->runSignalQueryHostRunner([
            'sdk-python' => true,
            'python_worker_query_task_routing' => true,
            'cli_signal_and_query' => false,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => false,
            'ten_signal_ordered_delivery_total' => 55,
        ]);

        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertContains('signal_query_published_artifact_install_uncovered', array_column($result['findings'], 'type'));
        $this->assertContains('signal_query_python_smoke_uncovered', array_column($result['findings'], 'type'));
        $this->assertContains(
            'signal_query_ordered_delivery_current_evidence_missing',
            array_column($result['findings'], 'type'),
        );
    }

    public function test_host_runner_requires_exact_history_signal_order_before_marking_ordered_delivery_pass(): void
    {
        $result = $this->runSignalQueryHostRunner([
            'worker_runtime' => 'sdk-python',
            'python_worker_artifact_source' => 'published_pypi_package',
            'python_worker_sdk_version' => '0.4.84',
            'python_worker_query_task_routing' => true,
            'routed_current_query_task' => $this->routedCurrentQueryTaskEvidence(),
            'cli_signal_and_query' => true,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
            'workflow_id' => 'wf-ordered',
            'run_id' => 'run-ordered',
            'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'accepted_signal_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'accepted_signal_total' => 55,
            'queried_total' => 55,
            'history_signal_order' => [1, 2, 3, 5, 4, 6, 7, 8, 9, 10],
            'final_run_status' => 'waiting',
            'ordered_query_responder' => $this->orderedQueryResponderEvidence(),
        ]);

        $this->assertSame('pass', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('fail', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertSame(
            [1, 2, 3, 5, 4, 6, 7, 8, 9, 10],
            $result['scenario_results']['ordered_signal_delivery']['observed_outputs']['history_signal_order'],
        );
        $orderedFindings = $this->findingsForScenario($result, 'ordered_signal_delivery');
        $this->assertSame('signal_query_ordered_delivery_failed', $orderedFindings[0]['type'] ?? null);
        $this->assertSame(
            'unexpected_ordered_signal_history_order',
            $orderedFindings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
        $this->assertSame(
            55,
            $orderedFindings[0]['current_evidence']['ordered_delivery_observed_outputs']['accepted_signal_total'] ?? null,
        );
    }

    public function test_host_runner_marks_only_complete_smoke_fields_as_covered(): void
    {
        $run = $this->runSignalQueryHostRunnerArtifacts([
            'worker_runtime' => 'sdk-python',
            'python_worker_artifact_source' => 'published_pypi_package',
            'python_worker_sdk_version' => '0.4.84',
            'python_worker_query_task_routing' => true,
            'routed_current_query_task' => $this->routedCurrentQueryTaskEvidence(),
            'cli_signal_and_query' => true,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
            'workflow_id' => 'wf-ordered',
            'run_id' => 'run-ordered',
            'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'accepted_signal_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'accepted_signal_total' => 55,
            'queried_total' => 55,
            'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'final_run_status' => 'waiting',
            'ordered_query_responder' => $this->orderedQueryResponderEvidence(),
        ]);
        $result = $run['result'];
        $record = $run['record'];

        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('pass', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('pass', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['dedup_contract_observation']['status']);
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            $result['scenario_results']['ordered_signal_delivery']['observed_outputs']['history_signal_order'],
        );
        $this->assertEquals(
            [
                'workflow_id' => 'wf-ordered',
                'run_id' => 'run-ordered',
                'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                'accepted_signal_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                'accepted_signal_total' => 55,
                'queried_total' => 55,
                'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                'final_run_status' => 'waiting',
                'ordered_query_responder' => $this->orderedQueryResponderEvidence(),
            ],
            $record['ordered_signal_delivery_evidence'] ?? null,
        );
        $this->assertContains('signal_query_published_artifact_install_uncovered', array_column($result['findings'], 'type'));
        $this->assertContains(
            'signal_query_dedup_contract_current_evidence_missing',
            array_column($result['findings'], 'type'),
        );
    }

    public function test_host_runner_requires_routed_current_query_task_for_python_baseline(): void
    {
        $result = $this->runSignalQueryHostRunner([
            'worker_runtime' => 'sdk-python',
            'python_worker_artifact_source' => 'published_pypi_package',
            'python_worker_sdk_version' => '0.4.84',
            'python_worker_query_task_routing' => true,
            'cli_signal_and_query' => true,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
        ]);

        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);

        $pythonFindings = $this->findingsForScenario($result, 'python_worker_cli_and_sdk_baseline');
        $this->assertCount(1, $pythonFindings);
        $this->assertSame(
            'signal_query_python_routed_current_query_evidence_missing',
            $pythonFindings[0]['type'] ?? null,
        );
        $this->assertSame(
            ['routed_current_query_task'],
            $pythonFindings[0]['current_evidence']['missing_current_evidence'] ?? null,
        );
    }

    public function test_host_runner_routes_missing_python_route_proof_even_when_later_public_samples_are_absent(): void
    {
        $result = $this->runSignalQueryHostRunner([
            'worker_runtime' => 'sdk-python',
            'python_worker_artifact_source' => 'published_pypi_package',
            'python_worker_sdk_version' => '0.4.84',
            'python_worker_query_task_routing' => true,
        ]);

        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);

        $pythonFindings = $this->findingsForScenario($result, 'python_worker_cli_and_sdk_baseline');
        $this->assertCount(1, $pythonFindings);
        $this->assertSame(
            'signal_query_python_routed_current_query_evidence_missing',
            $pythonFindings[0]['type'] ?? null,
        );
        $this->assertSame(
            ['routed_current_query_task'],
            $pythonFindings[0]['current_evidence']['missing_current_evidence'] ?? null,
        );
    }

    public function test_host_runner_preserves_python_baseline_candidate_when_route_proof_is_missing(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
versions = {
    "server": "0.2.224",
    "cli": "0.1.74",
    "sdk-python": "0.4.84",
    "workflow": "2.0.0-alpha.187",
    "workflow-php": "2.0.0-alpha.187",
    "waterline": "2.0.0-alpha.69",
}
sources = {
    "server": "published_docker_image",
    "cli": "published_cli_release",
    "sdk-python": "published_pypi_package",
    "workflow-php": "published_composer_package",
    "waterline": "published_waterline_artifact",
}
globals()["artifact_versions"] = versions

query_calls = {"current": 0}
stopped = {"value": False}

class FakeProcess:
    pass

def fake_start_python_sdk_counter_worker(**kwargs):
    return FakeProcess()

def fake_stop_python_sdk_counter_worker(process, log_file):
    stopped["value"] = True

def fake_wait_for_worker_registered(**kwargs):
    return {"worker_id": kwargs["worker_id"], "capabilities": ["query_tasks"]}

def fake_http_json(base_url, path, **kwargs):
    if path.endswith("/workflows"):
        return {"status_code": 200, "body": {"run_id": "run-python-baseline"}}
    raise AssertionError(f"unexpected path {path}")

def fake_cli_json_sample(cli_bin, base_url, token, namespace, args, log_file):
    if args[0] == "workflow:signal":
        return {"ok": True}
    if args[0] == "workflow:query" and args[2] == "state":
        return {"ok": True, "result": 0}
    if args[0] == "workflow:query" and args[2] == "current":
        query_calls["current"] += 1
        return {"ok": True, "result": 3 if query_calls["current"] == 1 else 8}
    raise AssertionError(f"unexpected cli args {args}")

def fake_sdk_success_sample(python_bin, base_url, token, namespace, workflow_id, operation, name, log_file, args=None):
    if operation == "signal":
        return {"ok": True}
    if operation == "query":
        return {"ok": True, "result": 8}
    raise AssertionError(f"unexpected sdk operation {operation}")

def fake_wait_for_routed_current_query_task(**kwargs):
    raise RuntimeError("route evidence not recorded")

def fake_python_sdk_distribution_version(python_bin, log_file):
    return versions["sdk-python"]

globals()["start_python_sdk_counter_worker"] = fake_start_python_sdk_counter_worker
globals()["stop_python_sdk_counter_worker"] = fake_stop_python_sdk_counter_worker
globals()["wait_for_worker_registered"] = fake_wait_for_worker_registered
globals()["http_json"] = fake_http_json
globals()["cli_json_sample"] = fake_cli_json_sample
globals()["sdk_success_sample"] = fake_sdk_success_sample
globals()["wait_for_routed_current_query_task"] = fake_wait_for_routed_current_query_task
globals()["python_sdk_distribution_version"] = fake_python_sdk_distribution_version

run_root = Path(tempfile.mkdtemp(prefix="dw-signals-python-route-test."))
outputs, descriptor = run_python_sdk_baseline(
    base_url="http://server.test",
    token="token",
    namespace="default",
    cli_bin="/tmp/dw",
    python_bin=sys.executable,
    versions=versions,
    sources=sources,
    run_root=run_root,
    log_file=run_root / "probe.log",
)
scenario = baseline_scenario_result("python_worker_cli_and_sdk_baseline", outputs)

print(json.dumps({
    "status": scenario["status"],
    "missing": missing_current_evidence_for("python_worker_cli_and_sdk_baseline", outputs),
    "has_routed_task": "routed_current_query_task" in outputs,
    "route_error_type": outputs["routed_current_query_task_error"]["type"],
    "descriptor_error_type": descriptor["routed_current_query_task_error"]["type"],
    "cli_signal_and_query": outputs["cli_signal_and_query"],
    "sdk_python_signal_and_query": outputs["sdk_python_signal_and_query"],
    "immediate_repeat_query_consistency": outputs["immediate_repeat_query_consistency"],
    "query_task_routing": outputs["python_worker_query_task_routing"],
    "stopped": stopped["value"],
}, sort_keys=True))
PY);

        $this->assertSame('not_covered', $result['status']);
        $this->assertSame(['routed_current_query_task'], $result['missing']);
        $this->assertFalse($result['has_routed_task']);
        $this->assertSame('RuntimeError', $result['route_error_type']);
        $this->assertSame('RuntimeError', $result['descriptor_error_type']);
        $this->assertTrue($result['cli_signal_and_query']);
        $this->assertTrue($result['sdk_python_signal_and_query']);
        $this->assertTrue($result['immediate_repeat_query_consistency']);
        $this->assertTrue($result['query_task_routing']);
        $this->assertTrue($result['stopped']);
    }

    public function test_python_baseline_keeps_partial_candidate_when_later_public_probe_fails(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
versions = {
    "server": "0.2.224",
    "cli": "0.1.74",
    "sdk-python": "0.4.84",
    "workflow": "2.0.0-alpha.187",
    "workflow-php": "2.0.0-alpha.187",
    "waterline": "2.0.0-alpha.69",
}
sources = {
    "server": "published_docker_image",
    "cli": "published_cli_release",
    "sdk-python": "published_pypi_package",
    "workflow-php": "published_composer_package",
    "waterline": "published_waterline_artifact",
}
globals()["artifact_versions"] = versions

query_calls = {"current": 0}
stopped = {"value": False}

class FakeProcess:
    pass

def fake_start_python_sdk_counter_worker(**kwargs):
    return FakeProcess()

def fake_stop_python_sdk_counter_worker(process, log_file):
    stopped["value"] = True

def fake_wait_for_worker_registered(**kwargs):
    return {"worker_id": kwargs["worker_id"], "capabilities": ["query_tasks"]}

def fake_http_json(base_url, path, **kwargs):
    if path.endswith("/workflows"):
        return {"status_code": 200, "body": {"run_id": "run-python-baseline"}}
    raise AssertionError(f"unexpected path {path}")

def fake_cli_json_sample(cli_bin, base_url, token, namespace, args, log_file):
    if args[0] == "workflow:signal":
        return {"ok": True}
    if args[0] == "workflow:query" and args[2] == "state":
        return {"ok": True, "result": 0}
    if args[0] == "workflow:query" and args[2] == "current":
        query_calls["current"] += 1
        return {"ok": True, "result": 3 if query_calls["current"] == 1 else 8}
    raise AssertionError(f"unexpected cli args {args}")

def fake_sdk_success_sample(python_bin, base_url, token, namespace, workflow_id, operation, name, log_file, args=None):
    if operation == "signal":
        return {"ok": True}
    raise RuntimeError("SDK query did not complete")

def fake_wait_for_routed_current_query_task(**kwargs):
    raise RuntimeError("route evidence not recorded")

def fake_python_sdk_distribution_version(python_bin, log_file):
    return versions["sdk-python"]

globals()["start_python_sdk_counter_worker"] = fake_start_python_sdk_counter_worker
globals()["stop_python_sdk_counter_worker"] = fake_stop_python_sdk_counter_worker
globals()["wait_for_worker_registered"] = fake_wait_for_worker_registered
globals()["http_json"] = fake_http_json
globals()["cli_json_sample"] = fake_cli_json_sample
globals()["sdk_success_sample"] = fake_sdk_success_sample
globals()["wait_for_routed_current_query_task"] = fake_wait_for_routed_current_query_task
globals()["python_sdk_distribution_version"] = fake_python_sdk_distribution_version

run_root = Path(tempfile.mkdtemp(prefix="dw-signals-python-partial-test."))
outputs, descriptor = run_python_sdk_baseline(
    base_url="http://server.test",
    token="token",
    namespace="default",
    cli_bin="/tmp/dw",
    python_bin=sys.executable,
    versions=versions,
    sources=sources,
    run_root=run_root,
    log_file=run_root / "probe.log",
)
scenario = baseline_scenario_result("python_worker_cli_and_sdk_baseline", outputs)

print(json.dumps({
    "status": scenario["status"],
    "missing": missing_current_evidence_for("python_worker_cli_and_sdk_baseline", outputs),
    "behavior_failures": current_behavior_failures_for("python_worker_cli_and_sdk_baseline", outputs),
    "worker_runtime": outputs["worker_runtime"],
    "python_worker_artifact_source": outputs["python_worker_artifact_source"],
    "python_worker_sdk_version": outputs["python_worker_sdk_version"],
    "query_task_routing": outputs["python_worker_query_task_routing"],
    "cli_signal_and_query": outputs["cli_signal_and_query"],
    "sdk_python_signal_and_query": outputs["sdk_python_signal_and_query"],
    "sdk_error_type": outputs["sdk_python_signal_and_query_error"]["type"],
    "has_routed_task": "routed_current_query_task" in outputs,
    "route_error_type": outputs["routed_current_query_task_error"]["type"],
    "probe_error_type": outputs["probe_error"]["type"],
    "descriptor_error": descriptor["error"],
    "stopped": stopped["value"],
}, sort_keys=True))
PY);

        $this->assertSame('fail', $result['status']);
        $this->assertContains('routed_current_query_task', $result['missing']);
        $this->assertSame(
            'python_sdk_signal_query_mismatch',
            $result['behavior_failures'][0]['code'] ?? null,
        );
        $this->assertSame('sdk-python', $result['worker_runtime']);
        $this->assertSame('published_pypi_package', $result['python_worker_artifact_source']);
        $this->assertSame('0.4.84', $result['python_worker_sdk_version']);
        $this->assertTrue($result['query_task_routing']);
        $this->assertTrue($result['cli_signal_and_query']);
        $this->assertFalse($result['sdk_python_signal_and_query']);
        $this->assertSame('RuntimeError', $result['sdk_error_type']);
        $this->assertFalse($result['has_routed_task']);
        $this->assertSame('RuntimeError', $result['route_error_type']);
        $this->assertSame('RuntimeError', $result['probe_error_type']);
        $this->assertStringContainsString('SDK query did not complete', $result['descriptor_error']);
        $this->assertTrue($result['stopped']);
    }

    public function test_python_baseline_reads_nested_sdk_query_result_samples(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
samples = {
    "cli_direct": {
        "ok": True,
        "result": 3,
    },
    "sdk_wrapped_control_plane": {
        "client": "sdk-python",
        "operation": "query",
        "operation_name": "current",
        "ok": True,
        "result": {
            "success": True,
            "workflow_id": "wf-sq-python-sdk",
            "run_id": "run-python-baseline",
            "query_name": "current",
            "target_scope": "instance",
            "result": 8,
        },
    },
    "worker_routed_envelope": {
        "ok": True,
        "result": None,
        "result_envelope": {
            "codec": "json",
            "blob": "8",
        },
    },
}

print(json.dumps({
    key: sample_result_value(sample)
    for key, sample in samples.items()
}, sort_keys=True))
PY);

        $this->assertSame(3, $result['cli_direct']);
        $this->assertSame(8, $result['sdk_wrapped_control_plane']);
        $this->assertSame(8, $result['worker_routed_envelope']);
    }

    public function test_php_start_stdout_parser_accepts_warning_prefixed_nested_run_id(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
stdout = """PHP Warning:  Module "sockets" is already loaded in Unknown on line 0
{"client":"workflow-php","operation":"start","operation_name":"start","ok":true,"result":{"success":true,"workflow_id":"wf-warning-prefix","run_id":"run-warning-prefix"}}
"""
sample = json_sample_from_stdout(stdout)
readout = sample_readout(sample)

print(json.dumps({
    "ok": public_sample_ok(sample),
    "run_id": workflow_start_run_id(sample),
    "result_run_id": sample_result_value(sample)["run_id"],
    "raw_stdout": sample.get("raw_stdout"),
    "readout_raw_stdout": readout.get("raw_stdout") if readout else None,
    "readout_result_run_id": readout["result"]["run_id"] if readout else None,
}, sort_keys=True))
PY);

        $this->assertTrue($result['ok']);
        $this->assertSame('run-warning-prefix', $result['run_id']);
        $this->assertSame('run-warning-prefix', $result['result_run_id']);
        $this->assertStringContainsString('PHP Warning:', $result['raw_stdout']);
        $this->assertStringContainsString('PHP Warning:', $result['readout_raw_stdout']);
        $this->assertSame('run-warning-prefix', $result['readout_result_run_id']);
    }

    public function test_php_baseline_accepts_nested_workflow_start_run_id_and_continues_probe(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
versions = {
    "server": "0.2.485",
    "cli": "0.1.82",
    "sdk-python": "0.4.90",
    "workflow": "2.0.0-alpha.250",
    "workflow-php": "2.0.0-alpha.250",
    "waterline": "2.0.0-alpha.111",
}
sources = {
    "server": "published_docker_image",
    "cli": "published_cli_release",
    "sdk-python": "published_pypi_package",
    "workflow-php": "published_composer_package",
    "waterline": "published_waterline_artifact",
}
globals()["artifact_versions"] = versions

php_calls = []
cli_calls = []
commands = []

def fake_php_docker_command(project_dir, args, name=None, detach=False):
    command = ["php-docker"] + list(args)
    if name is not None:
        command.append(f"name={name}")
    if detach:
        command.append("detach=true")
    return command

def fake_run_command(command, *, log_file, env=None, cwd=None, timeout=120.0):
    commands.append(command)
    return subprocess.CompletedProcess(command, 0, stdout="container-php\n", stderr="")

def fake_wait_for_docker_worker_registered(**kwargs):
    return {
        "worker_id": kwargs["worker_id"],
        "task_queue": kwargs["container_name"].replace("dw-sq-php-", "signals-queries-workflow-php-"),
        "capabilities": ["workflow_tasks", "query_tasks"],
    }

def fake_php_workflow_client_sample(
    workflow_php_project,
    base_url,
    token,
    namespace,
    operation,
    workflow_type,
    workflow_id,
    task_queue,
    name,
    log_file,
    args=None,
):
    php_calls.append({"operation": operation, "name": name, "args": args})
    if operation == "start":
        return {
            "ok": True,
            "status_code": 201,
            "result": {
                "success": True,
                "workflow_id": workflow_id,
                "start_result": {
                    "workflow_id": workflow_id,
                    "run_id": "run-php-baseline",
                },
            },
        }
    if operation == "signal":
        return {"ok": True, "status_code": 202, "result": {"accepted": True}}
    if operation == "query":
        return {
            "ok": True,
            "status_code": 200,
            "result": {
                "success": True,
                "workflow_id": workflow_id,
                "run_id": "run-php-baseline",
                "query_name": name,
                "result": 8,
            },
        }
    raise AssertionError(f"unexpected PHP operation {operation}")

def fake_cli_json_sample(cli_bin, base_url, token, namespace, args, log_file):
    cli_calls.append(args)
    if args[0] == "workflow:signal":
        return {"ok": True, "status_code": 202}
    if args[0] == "workflow:query" and args[2] == "state":
        return {"ok": True, "status_code": 200, "result": 0}
    if args[0] == "workflow:query" and args[2] == "current":
        return {"ok": True, "status_code": 200, "result": 3}
    raise AssertionError(f"unexpected CLI args {args}")

def fake_wait_for_php_query_route_evidence(query_route_evidence_path, workflow_id, worker_id):
	    return {
	        "status": "pass",
	        "worker_runtime": "workflow-php",
	        "worker_id": worker_id,
	        "task_queue": "signals-queries-workflow-php-test",
	        "query_task_id": "query-task-php-current",
	        "query_task_attempt": 1,
	        "workflow_id": workflow_id,
	        "run_id": "run-php-baseline",
	        "workflow_type": "conformance.counter.php",
	        "query_name": "current",
	        "lease_owner": worker_id,
	        "server_route": "worker_query_task_poll",
	        "completion_route": "worker_query_task_complete",
	    }

globals()["php_docker_command"] = fake_php_docker_command
globals()["run_command"] = fake_run_command
globals()["wait_for_docker_worker_registered"] = fake_wait_for_docker_worker_registered
globals()["php_workflow_client_sample"] = fake_php_workflow_client_sample
globals()["cli_json_sample"] = fake_cli_json_sample
globals()["wait_for_php_query_route_evidence"] = fake_wait_for_php_query_route_evidence

run_root = Path(tempfile.mkdtemp(prefix="dw-signals-php-nested-start-test."))
outputs, descriptor = run_workflow_php_baseline(
    base_url="http://server.test",
    token="token",
    namespace="default",
    cli_bin="/tmp/dw",
    workflow_php_project=run_root / "workflow-php",
    versions=versions,
    sources=sources,
    install_entry={"status": "pass", "source": "published_composer_package"},
    run_root=run_root,
    log_file=run_root / "probe.log",
)
scenario = baseline_scenario_result("php_worker_cli_and_sdk_baseline", outputs)

print(json.dumps({
    "status": scenario["status"],
    "run_id": outputs["run_id"],
    "descriptor_run_id": descriptor["run_id"],
    "query_task_routing": outputs["php_worker_query_task_routing"],
    "cli_signal_and_query": outputs["cli_signal_and_query"],
    "workflow_php_signal_and_query": outputs["workflow_php_signal_and_query"],
    "immediate_repeat_query_consistency": outputs["immediate_repeat_query_consistency"],
    "initial_query": sample_result_value(outputs["initial_query_sample"]),
    "cli_query": sample_result_value(outputs["cli_query_sample"]),
    "php_query": sample_result_value(outputs["workflow_php_query_sample"]),
    "repeat_query": sample_result_value(outputs["repeat_query_sample"]),
    "php_operations": [call["operation"] for call in php_calls],
    "cli_operations": [call[0] for call in cli_calls],
    "has_probe_error": "probe_error" in outputs,
}, sort_keys=True))
PY);

        $this->assertSame('pass', $result['status']);
        $this->assertSame('run-php-baseline', $result['run_id']);
        $this->assertSame('run-php-baseline', $result['descriptor_run_id']);
        $this->assertTrue($result['query_task_routing']);
        $this->assertTrue($result['cli_signal_and_query']);
        $this->assertTrue($result['workflow_php_signal_and_query']);
        $this->assertTrue($result['immediate_repeat_query_consistency']);
        $this->assertSame(0, $result['initial_query']);
        $this->assertSame(3, $result['cli_query']);
        $this->assertSame(8, $result['php_query']);
        $this->assertSame(8, $result['repeat_query']);
        $this->assertSame(['start', 'signal', 'query', 'query'], $result['php_operations']);
        $this->assertSame(
            ['workflow:query', 'workflow:signal', 'workflow:query'],
            $result['cli_operations'],
        );
        $this->assertFalse($result['has_probe_error']);
    }

    public function test_php_rust_query_probe_grades_every_observed_answer(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
prefix_samples = [
    {"ok": True, "result": 4},
    {"ok": True, "result": 10},
    {"ok": True, "result": 10},
]
impossible_samples = [
    {"ok": True, "result": 14},
    {"ok": True, "result": 10},
    {"ok": True, "result": 10},
]
prefix_values = integer_query_observations(prefix_samples)
impossible_values = integer_query_observations(impossible_samples)

print(json.dumps({
    "prefix_values": prefix_values,
    "prefix_consistent": increment_query_observations_are_prefix_consistent(prefix_values, [4, 6]),
    "prefix_rollback_free": increment_query_observations_are_rollback_free(prefix_values),
    "impossible_values": impossible_values,
    "impossible_prefix_consistent": increment_query_observations_are_prefix_consistent(
        impossible_values,
        [4, 6],
    ),
    "impossible_rollback_free": increment_query_observations_are_rollback_free(impossible_values),
}, sort_keys=True))
PY);

        $this->assertSame([4, 10, 10], $result['prefix_values']);
        $this->assertTrue($result['prefix_consistent']);
        $this->assertTrue($result['prefix_rollback_free']);
        $this->assertSame([14, 10, 10], $result['impossible_values']);
        $this->assertFalse($result['impossible_prefix_consistent']);
        $this->assertFalse($result['impossible_rollback_free']);
    }

    public function test_baseline_probe_external_worker_contract_declares_current_query(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
contract = command_contract()

print(json.dumps({
    "queries": contract["queries"],
    "query_contract_names": [
        query_contract["name"]
        for query_contract in contract["query_contracts"]
    ],
}, sort_keys=True))
PY);

        $this->assertContains('current', $result['queries']);
        $this->assertContains('current', $result['query_contract_names']);
    }

    public function test_host_runner_rejects_package_labels_as_python_worker_runtime(): void
    {
        foreach (['python-sdk', 'durable-workflow-python'] as $runtime) {
            $result = $this->runSignalQueryHostRunner([
                'worker_runtime' => $runtime,
                'python_worker_artifact_source' => 'published_pypi_package',
                'python_worker_sdk_version' => '0.4.84',
                'python_worker_query_task_routing' => true,
                'cli_signal_and_query' => true,
                'sdk_python_signal_and_query' => true,
                'immediate_repeat_query_consistency' => true,
            ]);

            $this->assertSame(
                'not_covered',
                $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status'],
                $runtime,
            );
            $this->assertContains(
                'signal_query_python_smoke_uncovered',
                array_column($result['findings'], 'type'),
                $runtime,
            );
            $this->assertNotContains(
                'signal_query_python_routed_current_query_evidence_missing',
                array_column($result['findings'], 'type'),
                $runtime,
            );
        }
    }

    public function test_host_runner_imports_complete_matrix_evidence_as_passing_conformance(): void
    {
        $result = $this->runSignalQueryHostRunner($this->completeSignalQueryResultForCurrentHostRunner());
        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame([], $result['findings']);
        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_host_runner_does_not_promote_probe_only_adversarial_evidence_to_install_pass(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $probeEvidence = [
            'artifact_versions' => $this->currentHostRunnerArtifactVersions(),
            'scenario_results' => [
                'unknown_signal_and_query_errors' => $complete['scenario_results']['unknown_signal_and_query_errors'],
                'malformed_signal_and_query_payloads' => $complete['scenario_results']['malformed_signal_and_query_payloads'],
            ],
        ];

        $result = $this->runSignalQueryHostRunner($probeEvidence);

        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('pass', $result['scenario_results']['unknown_signal_and_query_errors']['status']);
        $this->assertSame('pass', $result['scenario_results']['malformed_signal_and_query_payloads']['status']);
        $this->assertContains('signal_query_published_artifact_install_uncovered', array_column($result['findings'], 'type'));
    }

    public function test_host_runner_preserves_focused_baseline_cells_when_install_source_is_not_proved(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $versions = $this->currentHostRunnerArtifactVersions();
        $badSources = [
            'server' => 'published',
            'cli' => 'published_cli_release',
            'sdk-python' => 'published_pypi_package',
            'sdk-rust' => 'published_crates_io_package',
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];
        $evidence = [
            'artifact_versions' => $versions,
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'scenario_id' => 'published_artifact_install_only',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'published_artifact_versions' => $versions,
                        'artifact_sources' => $badSources,
                    ],
                ],
                'ordered_signal_delivery' => $complete['scenario_results']['ordered_signal_delivery'],
                'dedup_contract_observation' => $complete['scenario_results']['dedup_contract_observation'],
                'unknown_signal_and_query_errors' => $complete['scenario_results']['unknown_signal_and_query_errors'],
            ],
        ];

        $result = $this->runSignalQueryHostRunner($evidence);
        $findingTypes = array_column($result['findings'], 'type');

        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('pass', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertSame('pass', $result['scenario_results']['dedup_contract_observation']['status']);
        $this->assertSame('pass', $result['scenario_results']['unknown_signal_and_query_errors']['status']);
        $this->assertContains('signal_query_published_artifact_install_uncovered', $findingTypes);
        $this->assertContains('signal_query_python_smoke_uncovered', $findingTypes);
        $this->assertNotContains('signal_query_ordered_delivery_uncovered', $findingTypes);
        $this->assertNotContains('signal_query_dedup_contract_uncovered', $findingTypes);
        $this->assertNotContains('signal_query_unknown_handler_errors_uncovered', $findingTypes);
    }

    public function test_host_runner_promotes_current_probe_baseline_cells_when_external_smoke_tuple_is_stale(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $versions = $this->currentHostRunnerArtifactVersions();
        $sources = $this->expectedHostRunnerArtifactSources();
        $staleVersions = [
            'server' => '0.2.140',
            'cli' => '0.1.45',
            'sdk-python' => '0.4.58',
            'workflow' => '2.0.0-alpha.161',
            'workflow-php' => '2.0.0-alpha.161',
            'waterline' => '2.0.0-alpha.54',
        ];
        $evidence = [
            'artifactVersions' => $staleVersions,
            'scenario_results' => [
                'ordered_signal_delivery' => $complete['scenario_results']['ordered_signal_delivery'],
                'dedup_contract_observation' => $complete['scenario_results']['dedup_contract_observation'],
                'unknown_signal_and_query_errors' => $complete['scenario_results']['unknown_signal_and_query_errors'],
            ],
        ];

        foreach (array_keys($evidence['scenario_results']) as $scenario) {
            $evidence['scenario_results'][$scenario]['observed_outputs']['published_artifact_versions'] = $versions;
            $evidence['scenario_results'][$scenario]['observed_outputs']['artifact_sources'] = $sources;
        }

        $result = $this->runSignalQueryHostRunner($evidence);
        $findingTypes = array_column($result['findings'], 'type');

        $this->assertSame('pass', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertSame('pass', $result['scenario_results']['dedup_contract_observation']['status']);
        $this->assertSame('pass', $result['scenario_results']['unknown_signal_and_query_errors']['status']);
        $this->assertEquals(
            $versions,
            $result['scenario_results']['ordered_signal_delivery']['observed_outputs']['published_artifact_versions'],
        );
        $this->assertNotContains('signal_query_ordered_delivery_uncovered', $findingTypes);
        $this->assertNotContains('signal_query_dedup_contract_uncovered', $findingTypes);
        $this->assertNotContains('signal_query_unknown_handler_errors_uncovered', $findingTypes);
    }

    public function test_host_runner_names_missing_current_baseline_evidence_when_external_smoke_tuple_is_stale(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $versions = $this->currentHostRunnerArtifactVersions();
        $sources = $this->expectedHostRunnerArtifactSources();
        $evidence = [
            'artifactVersions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.58',
                'workflow' => '2.0.0-alpha.161',
                'workflow-php' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'scenario_results' => [
                'ordered_signal_delivery' => $complete['scenario_results']['ordered_signal_delivery'],
            ],
        ];
        $evidence['scenario_results']['ordered_signal_delivery']['observed_outputs']['published_artifact_versions'] =
            $versions;
        $evidence['scenario_results']['ordered_signal_delivery']['observed_outputs']['artifact_sources'] = $sources;
        unset($evidence['scenario_results']['ordered_signal_delivery']['observed_outputs']['history_signal_order']);

        $result = $this->runSignalQueryHostRunner($evidence);
        $orderedFindings = array_values(array_filter(
            $result['findings'],
            static fn (array $finding): bool => ($finding['scenario_id'] ?? null) === 'ordered_signal_delivery',
        ));

        $this->assertSame('not_covered', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertNotEmpty($orderedFindings);
        $this->assertContains(
            'history_signal_order',
            $orderedFindings[0]['current_evidence']['missing_current_evidence'] ?? [],
        );
        $this->assertStringContainsString('history_signal_order', $orderedFindings[0]['title'] ?? '');
    }

    public function test_host_runner_names_missing_current_baseline_evidence_without_current_candidates(): void
    {
        $result = $this->runSignalQueryHostRunner([
            'artifact_versions' => $this->currentHostRunnerArtifactVersions(),
        ]);

        $expectedMissing = [
            'ordered_signal_delivery' => [
                'workflow_id',
                'run_id',
                'rapid_increment_inputs',
                'accepted_signal_inputs',
                'accepted_signal_total',
                'queried_total',
                'history_signal_order',
                'final_run_status',
                'ordered_query_responder',
            ],
            'dedup_contract_observation' => [
                'client_side_key_support',
                'documented_contract',
                'handler_observation_count',
            ],
            'unknown_signal_and_query_errors' => [
                'workflow_id',
                'run_id',
                'worker_id',
                'task_queue',
                'unknown_signal',
                'missing_workflow_signal',
                'missing_workflow_query',
                'query_not_found',
                'rejected_unknown_query',
                'known_query_after_unknown_errors',
                'known_query_after_unknown_expected',
                'known_query_after_unknown_result',
                'post_error_query_responder',
                'history_and_commands_before_rejected_requests.history_event_count',
                'history_and_commands_before_rejected_requests.workflow_command_count',
                'history_and_commands_after_recovery_query.history_event_count',
                'history_and_commands_after_recovery_query.workflow_command_count',
                'history_and_commands_after_all_requests.history_event_count',
                'history_and_commands_after_all_requests.workflow_command_count',
                'rejected_requests_and_recovery_appended_no_history',
                'rejected_requests_and_recovery_emitted_no_workflow_commands',
            ],
        ];
        $expectedTypes = [
            'ordered_signal_delivery' => 'signal_query_ordered_delivery_current_evidence_missing',
            'dedup_contract_observation' => 'signal_query_dedup_contract_current_evidence_missing',
            'unknown_signal_and_query_errors' => 'signal_query_unknown_handler_errors_current_evidence_missing',
        ];

        foreach ($expectedMissing as $scenarioId => $missingFields) {
            $this->assertSame('not_covered', $result['scenario_results'][$scenarioId]['status']);
            $findings = $this->findingsForScenario($result, $scenarioId);
            $this->assertNotEmpty($findings);
            $this->assertSame($expectedTypes[$scenarioId], $findings[0]['type'] ?? null);
            $this->assertSame(
                $missingFields,
                $findings[0]['current_evidence']['missing_current_evidence'] ?? null,
            );
            $this->assertFalse($findings[0]['current_evidence']['current_evidence_candidate_present'] ?? true);
            $this->assertSame(
                'missing',
                $findings[0]['current_evidence']['current_evidence_candidate_status'] ?? null,
            );
            foreach ($missingFields as $field) {
                $this->assertStringContainsString($field, $findings[0]['title'] ?? '');
            }
        }
    }

    public function test_host_runner_routes_readiness_topology_separately_from_current_evidence_missing(): void
    {
        $run = $this->runSignalQueryHostRunnerWithEnvironment([
            'DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE' => '0',
            'DW_SIGNALS_QUERIES_SERVER_URL' => 'http://127.0.0.1:9',
            'DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS' => '0.1',
        ]);
        $result = $run['result'];
        $record = $run['record'];
        $metadata = $run['metadata'];

        $this->assertTrue($result['runner_blocked']);
        $this->assertTrue($record['runnerBlocked']);
        $this->assertSame('non_passing_runner_blocked', $result['outcome']);
        $this->assertSame('non_passing_runner_blocked', $record['outcome']);
        $this->assertSame('server_readiness_topology', $result['runner_blocker']['kind'] ?? null);
        $this->assertSame('server_readiness_topology', $record['runner_blocker']['kind'] ?? null);
        $this->assertSame('server_readiness_topology', $metadata['runner_blocker']['kind'] ?? null);
        $this->assertSame('http://127.0.0.1:9', $result['runner_blocker']['effective_host_endpoint'] ?? null);
        $this->assertArrayHasKey('last_readiness_error', $result['runner_blocker']);
        $this->assertArrayHasKey('ready_url', $result['runner_blocker']);

        foreach ([
            'ordered_signal_delivery',
            'dedup_contract_observation',
            'unknown_signal_and_query_errors',
        ] as $scenarioId) {
            $this->assertSame('runner_blocked', $result['scenario_results'][$scenarioId]['status']);
            $findings = $this->findingsForScenario($result, $scenarioId);

            $this->assertNotEmpty($findings);
            $this->assertSame(
                'signal_query_'.$scenarioId.'_server_readiness_topology',
                $findings[0]['type'] ?? null,
            );
            $this->assertSame('server_readiness_topology', $findings[0]['blocker_kind'] ?? null);
            $this->assertArrayHasKey(
                'server_readiness_topology',
                $findings[0]['current_evidence'] ?? [],
            );
            $this->assertArrayNotHasKey(
                'missing_current_evidence',
                $findings[0]['current_evidence'] ?? [],
            );
            $this->assertStringNotContainsString('missing current evidence', $findings[0]['title'] ?? '');
        }

        $findingTypes = array_column($result['findings'], 'type');
        $this->assertNotContains('signal_query_ordered_delivery_current_evidence_missing', $findingTypes);
        $this->assertNotContains('signal_query_dedup_contract_current_evidence_missing', $findingTypes);
        $this->assertNotContains('signal_query_unknown_handler_errors_current_evidence_missing', $findingTypes);
    }

    public function test_host_runner_routes_observed_current_baseline_behavior_failures_as_product_findings(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $versions = $this->currentHostRunnerArtifactVersions();
        $sources = $this->expectedHostRunnerArtifactSources();
        $python = $complete['scenario_results']['python_worker_cli_and_sdk_baseline'];
        $python['observed_outputs']['published_artifact_versions'] = $versions;
        $python['observed_outputs']['artifact_sources'] = $sources;
        $python['observed_outputs']['sdk_python_signal_and_query'] = false;
        $python['observed_outputs']['sdk_python_signal_sample'] = [
            'ok' => true,
            'client' => 'sdk-python',
            'operation' => 'signal',
            'operation_name' => 'increment',
        ];
        $python['observed_outputs']['sdk_python_query_sample'] = [
            'ok' => true,
            'client' => 'sdk-python',
            'operation' => 'query',
            'operation_name' => 'current',
            'result' => 7,
        ];
        $python['observed_outputs']['sdk_python_signal_and_query_error'] = [
            'type' => 'RuntimeError',
            'message' => 'Python SDK query current returned 7, expected 8',
        ];
        $php = $complete['scenario_results']['php_worker_cli_and_sdk_baseline'];
        $php['observed_outputs']['published_artifact_versions'] = $versions;
        $php['observed_outputs']['artifact_sources'] = $sources;
        $php['observed_outputs']['workflow_php_signal_and_query'] = false;
        $php['observed_outputs']['workflow_php_signal_sample'] = [
            'ok' => true,
            'client' => 'workflow-php',
            'operation' => 'signal',
            'operation_name' => 'increment',
        ];
        $php['observed_outputs']['workflow_php_query_sample'] = [
            'ok' => true,
            'client' => 'workflow-php',
            'operation' => 'query',
            'operation_name' => 'current',
            'result' => 7,
        ];
        $php['observed_outputs']['workflow_php_signal_and_query_error'] = [
            'type' => 'RuntimeError',
            'message' => 'PHP SDK query current returned 7, expected 8',
        ];
        $ordered = $complete['scenario_results']['ordered_signal_delivery'];
        $ordered['observed_outputs']['published_artifact_versions'] = $versions;
        $ordered['observed_outputs']['artifact_sources'] = $sources;
        $ordered['observed_outputs']['queried_total'] = 54;
        $dedup = $complete['scenario_results']['dedup_contract_observation'];
        $dedup['observed_outputs']['published_artifact_versions'] = $versions;
        $dedup['observed_outputs']['artifact_sources'] = $sources;
        $dedup['observed_outputs']['handler_observation_count'] = 0;
        $unknown = $complete['scenario_results']['unknown_signal_and_query_errors'];
        $unknown['observed_outputs']['published_artifact_versions'] = $versions;
        $unknown['observed_outputs']['artifact_sources'] = $sources;
        $unknown['observed_outputs']['unknown_signal']['status_code'] = 500;

        $result = $this->runSignalQueryHostRunner([
            'artifact_versions' => $versions,
            'scenario_results' => [
                'python_worker_cli_and_sdk_baseline' => $python,
                'php_worker_cli_and_sdk_baseline' => $php,
                'ordered_signal_delivery' => $ordered,
                'dedup_contract_observation' => $dedup,
                'unknown_signal_and_query_errors' => $unknown,
            ],
        ]);
        $pythonFindings = $this->findingsForScenario($result, 'python_worker_cli_and_sdk_baseline');
        $phpFindings = $this->findingsForScenario($result, 'php_worker_cli_and_sdk_baseline');
        $orderedFindings = $this->findingsForScenario($result, 'ordered_signal_delivery');
        $dedupFindings = $this->findingsForScenario($result, 'dedup_contract_observation');
        $unknownFindings = $this->findingsForScenario($result, 'unknown_signal_and_query_errors');

        $this->assertSame('fail', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertNotEmpty($pythonFindings);
        $this->assertSame('signal_query_python_baseline_failed', $pythonFindings[0]['type'] ?? null);
        $this->assertSame(
            'python_sdk_signal_query_mismatch',
            $pythonFindings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
        $this->assertArrayNotHasKey(
            'missing_current_evidence',
            $pythonFindings[0]['current_evidence'] ?? [],
        );
        $this->assertStringNotContainsString('missing current evidence', $pythonFindings[0]['title'] ?? '');
        $this->assertSame('fail', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertNotEmpty($phpFindings);
        $this->assertSame('signal_query_php_worker_mirror_failed', $phpFindings[0]['type'] ?? null);
        $this->assertSame(
            'php_sdk_signal_query_mismatch',
            $phpFindings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
        $this->assertArrayNotHasKey(
            'missing_current_evidence',
            $phpFindings[0]['current_evidence'] ?? [],
        );
        $this->assertStringNotContainsString('missing current evidence', $phpFindings[0]['title'] ?? '');
        $this->assertSame('fail', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertNotEmpty($orderedFindings);
        $this->assertSame('signal_query_ordered_delivery_failed', $orderedFindings[0]['type'] ?? null);
        $this->assertSame(
            'unexpected_ordered_signal_total',
            $orderedFindings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
        $this->assertSame(
            54,
            $orderedFindings[0]['current_evidence']['current_behavior_failures'][0]['actual'] ?? null,
        );
        $this->assertSame('fail', $result['scenario_results']['dedup_contract_observation']['status']);
        $this->assertNotEmpty($dedupFindings);
        $this->assertSame('signal_query_dedup_contract_failed', $dedupFindings[0]['type'] ?? null);
        $this->assertSame(
            'duplicate_signal_not_observed',
            $dedupFindings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
        $this->assertSame('fail', $result['scenario_results']['unknown_signal_and_query_errors']['status']);
        $this->assertNotEmpty($unknownFindings);
        $this->assertSame('signal_query_unknown_handler_errors_failed', $unknownFindings[0]['type'] ?? null);
        $this->assertSame(
            'unexpected_unknown_handler_status_code',
            $unknownFindings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
    }

    public function test_host_runner_keeps_ordered_delivery_behavior_findings_focused_when_evidence_is_partial(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $versions = $this->currentHostRunnerArtifactVersions();
        $sources = $this->expectedHostRunnerArtifactSources();
        $ordered = $complete['scenario_results']['ordered_signal_delivery'];
        $ordered['observed_outputs']['published_artifact_versions'] = $versions;
        $ordered['observed_outputs']['artifact_sources'] = $sources;
        $ordered['observed_outputs']['history_signal_order'] = [1, 2, 3];
        unset($ordered['observed_outputs']['queried_total']);

        $result = $this->runSignalQueryHostRunner([
            'artifact_versions' => $versions,
            'scenario_results' => [
                'ordered_signal_delivery' => $ordered,
            ],
        ]);
        $orderedFindings = $this->findingsForScenario($result, 'ordered_signal_delivery');

        $this->assertSame('fail', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertNotEmpty($orderedFindings);
        $this->assertSame('signal_query_ordered_delivery_failed', $orderedFindings[0]['type'] ?? null);
        $this->assertSame(
            'unexpected_ordered_signal_history_order',
            $orderedFindings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
        $this->assertArrayNotHasKey(
            'missing_current_evidence',
            $orderedFindings[0]['current_evidence'] ?? [],
        );
        $this->assertStringNotContainsString('missing current evidence', $orderedFindings[0]['title'] ?? '');
    }

    public function test_host_runner_routes_known_query_after_unknown_result_drift_as_product_finding(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $versions = $this->currentHostRunnerArtifactVersions();
        $sources = $this->expectedHostRunnerArtifactSources();
        $unknown = $complete['scenario_results']['unknown_signal_and_query_errors'];
        $unknown['observed_outputs']['published_artifact_versions'] = $versions;
        $unknown['observed_outputs']['artifact_sources'] = $sources;
        $unknown['observed_outputs']['known_query_after_unknown_expected'] = 0;
        $unknown['observed_outputs']['known_query_after_unknown_result'] = 1;

        $result = $this->runSignalQueryHostRunner([
            'artifact_versions' => $versions,
            'scenario_results' => [
                'unknown_signal_and_query_errors' => $unknown,
            ],
        ]);
        $unknownFindings = $this->findingsForScenario($result, 'unknown_signal_and_query_errors');

        $this->assertSame('fail', $result['scenario_results']['unknown_signal_and_query_errors']['status']);
        $this->assertNotEmpty($unknownFindings);
        $this->assertSame('signal_query_unknown_handler_errors_failed', $unknownFindings[0]['type'] ?? null);
        $this->assertSame(
            'unexpected_known_query_after_unknown_result',
            $unknownFindings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
    }

    public function test_host_runner_accepts_server_unknown_handler_evidence_without_optional_client_samples(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $unknown = $complete['scenario_results']['unknown_signal_and_query_errors'];
        foreach ([
            'cli_unknown_signal_sample',
            'cli_unknown_query_sample',
            'cli_missing_workflow_signal_sample',
            'cli_missing_workflow_query_sample',
            'sdk_python_unknown_signal_sample',
            'sdk_python_unknown_query_sample',
            'sdk_python_missing_workflow_signal_sample',
            'sdk_python_missing_workflow_query_sample',
        ] as $field) {
            unset($unknown['observed_outputs'][$field]);
        }

        $result = $this->runSignalQueryHostRunner([
            'artifact_versions' => $this->currentHostRunnerArtifactVersions(),
            'scenario_results' => [
                'unknown_signal_and_query_errors' => $unknown,
            ],
        ]);
        $findingTypes = array_column($result['findings'], 'type');

        $this->assertSame('pass', $result['scenario_results']['unknown_signal_and_query_errors']['status']);
        $this->assertNotContains('signal_query_unknown_handler_errors_uncovered', $findingTypes);
    }

    public function test_probe_merge_preserves_sources_only_external_install_evidence(): void
    {
        $complete = $this->completeSignalQueryResultForCurrentHostRunner();
        $sources = [
            'server' => 'published_docker_image',
            'cli' => 'published_cli_release',
            'sdk-python' => 'published_pypi_package',
            'sdk-rust' => 'published_crates_io_package',
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];
        $externalEvidence = [
            'artifact_sources' => $sources,
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'scenario_id' => 'published_artifact_install_only',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'artifact_sources' => $sources,
                    ],
                ],
            ],
        ];
        $probeEvidence = [
            'artifact_versions' => $this->currentHostRunnerArtifactVersions(),
            'scenario_results' => [
                'unknown_signal_and_query_errors' => $complete['scenario_results']['unknown_signal_and_query_errors'],
                'malformed_signal_and_query_payloads' => $complete['scenario_results']['malformed_signal_and_query_payloads'],
            ],
        ];

        $result = $this->runProbeEvidenceMerge($externalEvidence, $probeEvidence);

        $this->assertSame($externalEvidence, $result['base']);
        $this->assertArrayHasKey('artifact_versions', $result['merged']);
        $this->assertArrayHasKey('unknown_signal_and_query_errors', $result['merged']['scenario_results']);
        $this->assertArrayHasKey('malformed_signal_and_query_payloads', $result['merged']['scenario_results']);
        $this->assertArrayNotHasKey('artifact_versions', $result['base']);
        $this->assertArrayNotHasKey(
            'published_artifact_versions',
            $result['base']['scenario_results']['published_artifact_install_only']['observed_outputs'],
        );
        $this->assertArrayNotHasKey('unknown_signal_and_query_errors', $result['base']['scenario_results']);
        $this->assertArrayNotHasKey('malformed_signal_and_query_payloads', $result['base']['scenario_results']);
    }

    public function test_host_runner_rejects_flat_explicit_install_evidence_without_install_proof(): void
    {
        $result = $this->runSignalQueryHostRunner([
            'published_artifact_versions' => $this->currentHostRunnerArtifactVersions(),
            'artifact_sources' => [
                'server' => 'published_docker_image',
                'cli' => 'published_cli_release',
                'sdk-python' => 'published_pypi_package',
                'sdk-rust' => 'published_crates_io_package',
                'workflow-php' => 'published_composer_package',
                'waterline' => 'published_waterline_artifact',
            ],
        ]);

        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertContains('signal_query_published_artifact_install_uncovered', array_column($result['findings'], 'type'));
    }

    public function test_host_runner_accepts_structured_explicit_install_evidence(): void
    {
        $versions = $this->currentHostRunnerArtifactVersions();
        $sources = [
            'server' => 'published_docker_image',
            'cli' => 'published_cli_release',
            'sdk-python' => 'published_pypi_package',
            'sdk-rust' => 'published_crates_io_package',
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];

        $result = $this->runSignalQueryHostRunner([
            'published_artifact_versions' => $versions,
            'artifact_sources' => $sources,
            'artifact_install_evidence' => $this->installEvidenceForVersions($versions, $sources),
        ]);

        $this->assertSame('pass', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame(
            'published_composer_package',
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['workflow-php'],
        );
    }

    public function test_host_runner_accepts_focused_source_free_install_proof_artifacts(): void
    {
        $versions = $this->currentHostRunnerArtifactVersions();
        $sources = [
            'server' => 'published_docker_image',
            'cli' => 'published_cli_release',
            'sdk-python' => 'published_pypi_package',
            'sdk-rust' => 'published_crates_io_package',
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];
        $installEvidence = [
            'local_product_source_checkouts_used' => false,
            'artifacts' => array_values(array_filter(
                $this->installEvidenceForVersions($versions, $sources)['artifacts'],
                static fn (array $artifact): bool => in_array(
                    $artifact['artifact'],
                    ['server', 'cli', 'sdk-python', 'sdk-rust'],
                    true,
                ),
            )),
        ];

        $result = $this->runSignalQueryHostRunner([
            'published_artifact_versions' => $versions,
            'artifact_sources' => $sources,
            'artifact_install_evidence' => $installEvidence,
        ]);

        $this->assertSame('pass', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame(
            ['server', 'cli', 'sdk-python', 'sdk-rust'],
            array_column(
                $result['scenario_results']['published_artifact_install_only']['observed_outputs']
                    ['artifact_install_evidence']['artifacts'],
                'artifact',
            ),
        );
    }

    public function test_host_runner_rejects_generic_or_mismatched_published_install_sources(): void
    {
        foreach ([
            'generic' => ['server' => 'published'],
            'mismatched' => ['sdk-python' => 'published_cli_release'],
        ] as $case => $sourceOverrides) {
            $versions = $this->currentHostRunnerArtifactVersions();
            $sources = array_replace(
                [
                    'server' => 'published_docker_image',
                    'cli' => 'published_cli_release',
                    'sdk-python' => 'published_pypi_package',
                    'sdk-rust' => 'published_crates_io_package',
                    'workflow-php' => 'published_composer_package',
                    'waterline' => 'published_waterline_artifact',
                ],
                $sourceOverrides,
            );

            $result = $this->runSignalQueryHostRunner([
                'published_artifact_versions' => $versions,
                'artifact_sources' => $sources,
                'artifact_install_evidence' => $this->installEvidenceForVersions($versions, $sources),
            ]);

            $this->assertSame(
                'not_covered',
                $result['scenario_results']['published_artifact_install_only']['status'],
                $case,
            );
            $this->assertContains(
                'signal_query_published_artifact_install_uncovered',
                array_column($result['findings'], 'type'),
                $case,
            );
        }
    }

    public function test_host_runner_rejects_configured_override_install_and_python_baseline_sources(): void
    {
        $versions = $this->currentHostRunnerArtifactVersions();
        $sources = [
            'server' => 'configured_server_endpoint',
            'cli' => 'configured_cli_binary',
            'sdk-python' => 'configured_python_environment',
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];
        $installEvidence = [
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'status' => 'not_proved',
                    'version' => $versions['server'],
                    'source' => 'configured_server_endpoint',
                    'installed_from_public_artifact' => false,
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'cli',
                    'status' => 'not_proved',
                    'version' => $versions['cli'],
                    'source' => 'configured_cli_binary',
                    'installed_from_public_artifact' => false,
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'sdk-python',
                    'status' => 'not_proved',
                    'version' => $versions['sdk-python'],
                    'source' => 'configured_python_environment',
                    'installed_from_public_artifact' => false,
                    'local_product_source_checkouts_used' => false,
                ],
            ],
        ];

        $result = $this->runSignalQueryHostRunner([
            'artifact_versions' => $versions,
            'scenario_results' => [
                'published_artifact_install_only' => [
                    'scenario_id' => 'published_artifact_install_only',
                    'status' => 'not_covered',
                    'observed_outputs' => [
                        'published_artifact_versions' => $versions,
                        'artifact_sources' => $sources,
                        'artifact_install_evidence' => $installEvidence,
                        'local_product_source_checkouts_used' => false,
                    ],
                ],
                'python_worker_cli_and_sdk_baseline' => [
                    'scenario_id' => 'python_worker_cli_and_sdk_baseline',
                    'status' => 'not_covered',
                    'observed_outputs' => [
                        'worker_runtime' => 'sdk-python',
                        'python_worker_artifact_source' => 'configured_python_environment',
                        'python_worker_sdk_version' => $versions['sdk-python'],
                        'python_worker_query_task_routing' => true,
                        'cli_signal_and_query' => true,
                        'sdk_python_signal_and_query' => true,
                        'immediate_repeat_query_consistency' => true,
                    ],
                ],
            ],
        ]);

        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertContains('signal_query_published_artifact_install_uncovered', array_column($result['findings'], 'type'));
        $this->assertContains('signal_query_python_smoke_uncovered', array_column($result['findings'], 'type'));
    }

    public function test_host_runner_imports_fractional_second_replay_timing_evidence_as_passing_conformance(): void
    {
        $result = $this->runSignalQueryHostRunner(
            $this->withFractionalSecondReplayTiming($this->completeSignalQueryResultForCurrentHostRunner()),
        );
        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('pass', $result['outcome']);
        $this->assertSame(
            '2026-05-20T00:00:01.900000Z',
            $result['scenario_results']['query_during_replay']['observed_outputs']['query_completed_at'],
        );
        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_host_runner_timestamp_helper_preserves_subsecond_precision(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
value = now()
print(json.dumps({
    "timestamp": value,
    "has_fractional_seconds": bool(re.search(r"\.\d{6}Z$", value)),
}, sort_keys=True))
PY);

        $this->assertTrue($result['has_fractional_seconds']);
        $this->assertMatchesRegularExpression('/\.\d{6}Z$/', $result['timestamp']);
    }

    public function test_replay_terminal_probe_polls_query_before_and_answers_after_replay_barrier(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
events = []
replay_query_started_before_barrier = []
replay_query_poll_started_before_barrier = []
replay_query_ready = threading.Event()
replay_completed = threading.Event()
terminal_query_ready = threading.Event()
timestamp_index = 0

def now() -> str:
    global timestamp_index
    timestamp_index += 1
    return f"2026-05-20T00:00:00.{timestamp_index:06d}Z"

def http_json(base_url, path, *, method="GET", body=None, token, namespace, worker=False, timeout=30.0):
    if path == api_path("worker", "register"):
        return {"status_code": 200, "body": {}}
    if method == "POST" and path == api_path("workflows"):
        workflow_id = body["workflow_id"]
        return {"status_code": 200, "body": {"run_id": f"run-{workflow_id}"}}
    if "/query/state" in path:
        if "wf-sq-terminal" in path:
            terminal_query_ready.wait(5)
            return {"status_code": 200, "body": {"result": {"counter": 0, "status": "completed"}}}
        replay_query_started_before_barrier.append("replay_complete" not in events)
        replay_query_ready.wait(5)
        return {"status_code": 200, "body": {"result": 0}}
    if "/signal/increment" in path:
        if "wf-sq-terminal" in path:
            return {
                "status_code": 409,
                "body": {
                    "reason": "run_not_active",
                    "rejection_reason": "run_not_active",
                    "message": "Workflow run is completed.",
                },
            }
        return {"status_code": 202, "body": {"outcome": "signal_received"}}
    raise RuntimeError(f"unexpected HTTP call {method} {path}")

poll_tasks = [
    {"task_id": "replay-task", "lease_owner": "worker", "workflow_task_attempt": 1},
    {
        "task_id": "signal-task",
        "lease_owner": "worker",
        "workflow_task_attempt": 1,
        "signal_name": "increment",
        "history_events": [
            {
                "event_type": "SignalReceived",
                "payload": {"signal_name": "increment", "arguments": {"decoded": {"amount": 5}}},
            }
        ],
    },
    {"task_id": "terminal-task", "lease_owner": "worker", "workflow_task_attempt": 1},
]

def poll_workflow_task(base_url, token, namespace, worker_id, task_queue, timeout=45.0):
    return {"status_code": 200, "body": {"task": poll_tasks.pop(0)}}

def complete_workflow_task(base_url, token, namespace, task, commands, timeout=30.0):
    if task["task_id"] == "replay-task":
        events.append("replay_complete")
        replay_completed.set()
    if task["task_id"] == "signal-task":
        events.append("signal_applied")
    if task["task_id"] == "terminal-task":
        events.append("terminal_complete")
    return {"status_code": 200, "body": {}}

def answer_next_query_task(base_url, token, namespace, worker_id, task_queue, result, log_file, holder, poll_timeout=45.0):
    holder["query_poll_started_at"] = now()
    if isinstance(result, dict):
        events.append("terminal_query_answer_started")
        event = terminal_query_ready
        query_task_id = "terminal-query-task"
    else:
        replay_query_poll_started_before_barrier.append("replay_complete" not in events)
        events.append("replay_query_poll_started")
        replay_completed.wait(5)
        events.append("replay_query_answer_started")
        event = replay_query_ready
        query_task_id = "replay-query-task"
    holder["poll"] = {"status_code": 200}
    holder["query_handler_invoked_at"] = now()
    holder["query_task"] = {"query_task_id": query_task_id, "query_task_attempt": 1}
    holder["result"] = result
    holder["complete"] = {"status_code": 200}
    holder["query_completed_at"] = now()
    event.set()

def run_status(base_url, token, namespace, workflow_id):
    return "completed"

def workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id=None):
    return {
        "status_code": 200,
        "workflow_id": workflow_id,
        "run_id": run_id,
        "status": "completed",
        "result": {"counter": 0, "status": "completed"},
        "history_event_count": 2,
        "history_event_types": ["WorkflowExecutionStarted", "WorkflowExecutionCompleted"],
    }

evidence, descriptor = run_replay_terminal_probe(
    "http://server.test",
    "token",
    "default",
    "worker",
    "queue",
    "conformance.counter",
    {
        "server": "0.2.549",
        "cli": "0.1.86",
        "sdk-python": "0.4.93",
        "workflow-php": "2.0.0-alpha.244",
        "waterline": "2.0.0-alpha.116",
    },
    {
        "server": "published_docker_image",
        "cli": "published_cli_release",
        "sdk-python": "published_pypi_package",
        "workflow-php": "published_composer_package",
        "waterline": "published_waterline_artifact",
    },
    Path("/tmp/replay-terminal-probe.log"),
)

print(json.dumps({
    "descriptor": descriptor,
    "events": events,
    "evidence": evidence,
    "replay_query_poll_started_before_barrier": replay_query_poll_started_before_barrier,
    "replay_query_started_before_barrier": replay_query_started_before_barrier,
}, sort_keys=True))
PY);

        $this->assertTrue($result['replay_query_started_before_barrier'][0]);
        $this->assertTrue($result['replay_query_poll_started_before_barrier'][0]);
        $this->assertLessThan(
            array_search('replay_complete', $result['events'], true),
            array_search('replay_query_poll_started', $result['events'], true),
        );
        $this->assertLessThan(
            array_search('replay_query_answer_started', $result['events'], true),
            array_search('replay_complete', $result['events'], true),
        );

        foreach ([
            'signal_during_replay',
            'query_during_replay',
            'completed_run_signal_and_query',
        ] as $scenarioId) {
            $this->assertSame('pass', $result['evidence']['scenario_results'][$scenarioId]['status']);
        }

        $queryOutputs = $result['evidence']['scenario_results']['query_during_replay']['observed_outputs'];
        $this->assertSame(0, $queryOutputs['query_answer']);
        $this->assertSame($queryOutputs['expected_answer'], $queryOutputs['query_answer']);
        $this->assertLessThan(
            $queryOutputs['replay_completed_at'],
            $queryOutputs['query_poll_started_at'],
        );
        $this->assertGreaterThanOrEqual(
            $queryOutputs['replay_completed_at'],
            $queryOutputs['query_handler_invoked_at'],
        );

        $terminalOutputs = $result['evidence']['scenario_results']['completed_run_signal_and_query']['observed_outputs'];
        $this->assertSame('run_not_active', $terminalOutputs['signal_error']['reason']);
        $this->assertSame(200, $terminalOutputs['query_result_or_error']['status_code']);
        $this->assertSame('completed', $terminalOutputs['run_status_after_operations']);
    }

    public function test_replay_terminal_probe_reports_focused_completed_run_failure_after_replay_evidence(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
events = []
replay_query_ready = threading.Event()
replay_completed = threading.Event()
timestamp_index = 0

def now() -> str:
    global timestamp_index
    timestamp_index += 1
    return f"2026-05-20T00:00:00.{timestamp_index:06d}Z"

def http_json(base_url, path, *, method="GET", body=None, token, namespace, worker=False, timeout=30.0):
    if path == api_path("worker", "register"):
        return {"status_code": 200, "body": {}}
    if method == "POST" and path == api_path("workflows"):
        workflow_id = body["workflow_id"]
        return {"status_code": 200, "body": {"run_id": f"run-{workflow_id}"}}
    if "/query/state" in path:
        replay_query_ready.wait(5)
        return {"status_code": 200, "body": {"result": 0}}
    if "/signal/increment" in path:
        return {"status_code": 202, "body": {"outcome": "signal_received"}}
    raise RuntimeError(f"unexpected HTTP call {method} {path}")

poll_tasks = [
    {"task_id": "replay-task", "lease_owner": "worker", "workflow_task_attempt": 1},
    {
        "task_id": "signal-task",
        "lease_owner": "worker",
        "workflow_task_attempt": 1,
        "signal_name": "increment",
        "history_events": [
            {
                "event_type": "SignalReceived",
                "payload": {"signal_name": "increment", "arguments": {"decoded": {"amount": 5}}},
            }
        ],
    },
    {"task_id": "terminal-task", "lease_owner": "worker", "workflow_task_attempt": 1},
]

def poll_workflow_task(base_url, token, namespace, worker_id, task_queue, timeout=45.0):
    return {"status_code": 200, "body": {"task": poll_tasks.pop(0)}}

def complete_workflow_task(base_url, token, namespace, task, commands, timeout=30.0):
    if task["task_id"] == "replay-task":
        events.append("replay_complete")
        replay_completed.set()
        return {"status_code": 200, "body": {}}
    if task["task_id"] == "signal-task":
        events.append("signal_applied")
        return {"status_code": 200, "body": {}}
    if task["task_id"] == "terminal-task":
        events.append("terminal_complete_failed")
        return {
            "status_code": 409,
            "body": {
                "reason": "terminal_completion_rejected",
                "message": "Terminal probe completion was rejected.",
            },
        }
    raise RuntimeError(f"unexpected task {task}")

def answer_next_query_task(base_url, token, namespace, worker_id, task_queue, result, log_file, holder, poll_timeout=45.0):
    holder["query_poll_started_at"] = now()
    events.append("replay_query_poll_started")
    replay_completed.wait(5)
    events.append("replay_query_answer_started")
    holder["poll"] = {"status_code": 200}
    holder["query_handler_invoked_at"] = now()
    holder["query_task"] = {"query_task_id": "replay-query-task", "query_task_attempt": 1}
    holder["result"] = result
    holder["complete"] = {"status_code": 200}
    holder["query_completed_at"] = now()
    replay_query_ready.set()

evidence, descriptor = run_replay_terminal_probe(
    "http://server.test",
    "token",
    "default",
    "worker",
    "queue",
    "conformance.counter",
    {
        "server": "0.2.549",
        "cli": "0.1.86",
        "sdk-python": "0.4.93",
        "workflow-php": "2.0.0-alpha.244",
        "waterline": "2.0.0-alpha.116",
    },
    {
        "server": "published_docker_image",
        "cli": "published_cli_release",
        "sdk-python": "published_pypi_package",
        "workflow-php": "published_composer_package",
        "waterline": "published_waterline_artifact",
    },
    Path("/tmp/replay-terminal-probe-failure.log"),
)

print(json.dumps({
    "descriptor": descriptor,
    "events": events,
    "evidence": evidence,
}, sort_keys=True))
PY);

        $this->assertSame('pass', $result['evidence']['scenario_results']['signal_during_replay']['status']);
        $this->assertSame('pass', $result['evidence']['scenario_results']['query_during_replay']['status']);
        $completed = $result['evidence']['scenario_results']['completed_run_signal_and_query'];

        $this->assertSame('fail', $completed['status']);
        $this->assertSame(
            'signal_query_completed_run_handling_failed',
            $completed['linked_findings'][0]['type'],
        );
        $this->assertNotSame(
            'signal_query_completed_run_handling_uncovered',
            $completed['linked_findings'][0]['type'],
        );
        $this->assertSame(
            'terminal_task_complete',
            $completed['linked_findings'][0]['current_evidence']['probe_phase'],
        );
        $this->assertSame(
            'terminal_completion_rejected',
            $completed['observed_outputs']['terminal_complete_response']['reason'],
        );
        $this->assertContains(
            'completed_run_signal_and_query',
            $result['descriptor']['generated_scenarios'],
        );
    }

    public function test_replay_terminal_probe_keeps_product_replay_query_failure_out_of_runner_blocked(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
events = []
replay_completed = threading.Event()
timestamp_index = 0

def now() -> str:
    global timestamp_index
    timestamp_index += 1
    return f"2026-05-20T00:00:00.{timestamp_index:06d}Z"

def http_json(base_url, path, *, method="GET", body=None, token, namespace, worker=False, timeout=30.0):
    if path == api_path("worker", "register"):
        return {"status_code": 200, "body": {}}
    if method == "POST" and path == api_path("workflows"):
        workflow_id = body["workflow_id"]
        return {"status_code": 200, "body": {"run_id": f"run-{workflow_id}"}}
    if "/query/state" in path:
        replay_completed.wait(5)
        return {
            "status_code": 504,
            "body": {
                "reason": "query_task_timeout",
                "message": "Query did not receive a worker response.",
            },
        }
    if "/signal/increment" in path:
        return {"status_code": 202, "body": {"outcome": "signal_received"}}
    raise RuntimeError(f"unexpected HTTP call {method} {path}")

def poll_workflow_task(base_url, token, namespace, worker_id, task_queue, timeout=45.0):
    return {
        "status_code": 200,
        "body": {
            "task": {
                "task_id": "replay-task",
                "lease_owner": "worker",
                "workflow_task_attempt": 1,
            },
        },
    }

def complete_workflow_task(base_url, token, namespace, task, commands, timeout=30.0):
    events.append("replay_complete")
    replay_completed.set()
    return {"status_code": 200, "body": {}}

def answer_next_query_task(base_url, token, namespace, worker_id, task_queue, result, log_file, holder, poll_timeout=45.0):
    holder["query_poll_started_at"] = now()
    events.append("replay_query_poll_started")
    replay_completed.wait(5)
    holder["poll"] = {"status_code": 200, "body": {"task": None, "poll_status": "empty"}}
    holder["empty_polls"] = [holder["poll"]]
    holder["error"] = "query task poll returned no task before timeout"

evidence, descriptor = run_replay_terminal_probe(
    "http://server.test",
    "token",
    "default",
    "worker",
    "queue",
    "conformance.counter",
    {
        "server": "0.2.562",
        "cli": "0.1.86",
        "sdk-python": "0.4.93",
        "workflow-php": "2.0.0-alpha.245",
        "waterline": "2.0.0-alpha.119",
    },
    {
        "server": "published_docker_image",
        "cli": "published_cli_release",
        "sdk-python": "published_pypi_package",
        "workflow-php": "published_composer_package",
        "waterline": "published_waterline_artifact",
    },
    Path("/tmp/replay-terminal-probe-query-failure.log"),
)

print(json.dumps({
    "descriptor": descriptor,
    "events": events,
    "evidence": evidence,
}, sort_keys=True))
PY);

        $scenarioResults = $result['evidence']['scenario_results'];

        foreach ($scenarioResults as $scenarioResult) {
            $this->assertNotSame('runner_blocked', $scenarioResult['status']);
        }

        $this->assertSame('fail', $scenarioResults['query_during_replay']['status']);
        $this->assertSame(
            'signal_query_query_during_replay_timing_failed',
            $scenarioResults['query_during_replay']['linked_findings'][0]['type'],
        );

        $completed = $scenarioResults['completed_run_signal_and_query'];
        $this->assertSame('fail', $completed['status']);
        $this->assertSame(
            'signal_query_completed_run_handling_failed',
            $completed['linked_findings'][0]['type'],
        );
        $this->assertArrayNotHasKey('blocker_kind', $completed['linked_findings'][0]);
        $this->assertSame(
            'query_during_replay_worker_response',
            $completed['linked_findings'][0]['current_evidence']['probe_phase'],
        );
        $this->assertSame(
            'query_during_replay_worker_response',
            $result['descriptor']['completed_run_probe']['probe_phase'],
        );
    }

    public function test_completed_run_runner_rejects_terminal_result_or_history_mutation(): void
    {
        $result = $this->runSignalQueryRunnerPythonSnippet(<<<'PY'
base = {
    "completed_run_id": "run-completed-1",
    "completed_at": "2026-05-20T00:01:00Z",
    "terminal_status": "completed",
    "signal_api_sample": {"method": "POST", "path": "/api/workflows/wf/signal/increment"},
    "signal_error": {
        "status_code": 409,
        "reason": "run_not_active",
        "rejection_reason": "run_not_active",
    },
    "query_api_sample": {"method": "POST", "path": "/api/workflows/wf/query/current"},
    "query_result_or_error": {
        "status_code": 200,
        "outcome": "completed_query_replayed_final_state",
        "result": {"current": 8},
    },
    "public_query_surfaces": ["control-plane-api", "worker-query-task-protocol"],
    "terminal_state_before_operations": {
        "status": "completed",
        "result": {"current": 8},
        "history_event_count": 2,
    },
    "terminal_state_after_operations": {
        "status": "completed",
        "result": {"current": 8},
        "history_event_count": 2,
    },
    "terminal_result_changed_after_operations": False,
    "terminal_history_changed_after_operations": False,
    "run_status_after_operations": "completed",
}

result_changed = json.loads(json.dumps(base))
result_changed["terminal_result_changed_after_operations"] = True
result_changed["terminal_state_after_operations"]["result"] = {"current": 9}

history_changed = json.loads(json.dumps(base))
history_changed["terminal_history_changed_after_operations"] = True
history_changed["terminal_state_after_operations"]["history_event_count"] = 3

print(json.dumps({
    "result_changed": completed_run_scenario_result(result_changed),
    "history_changed": completed_run_scenario_result(history_changed),
}, sort_keys=True))
PY);

        foreach (['result_changed', 'history_changed'] as $case) {
            $this->assertSame('fail', $result[$case]['status']);
            $this->assertSame(
                'signal_query_completed_run_handling_failed',
                $result[$case]['linked_findings'][0]['type'],
            );
            $this->assertNotSame(
                'signal_query_completed_run_handling_uncovered',
                $result[$case]['linked_findings'][0]['type'],
            );
        }
    }

    public function test_host_runner_rejects_imported_matrix_evidence_with_mismatched_artifact_versions(): void
    {
        $result = $this->runSignalQueryHostRunner($this->completeSignalQueryResult());
        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertContains('signal_query_published_artifact_install_uncovered', array_column($result['findings'], 'type'));
        $this->assertSame('non_passing', $evaluation['status']);
    }

    public function test_host_runner_does_not_pass_imported_matrix_cell_with_missing_required_evidence(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        unset($evidence['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['workflow_php_signal_and_query']);

        $result = $this->runSignalQueryHostRunner($evidence);

        $this->assertSame('not_covered', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertContains(
            'signal_query_php_worker_mirror_current_evidence_missing',
            array_column($result['findings'], 'type'),
        );
        $this->assertSame('non_passing', $result['outcome']);
    }

    public function test_host_runner_does_not_pass_malformed_payload_evidence_without_public_samples(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        unset(
            $evidence['scenario_results']['malformed_signal_and_query_payloads']['observed_outputs']
                ['cli_invalid_signal_arguments_sample']
        );

        $result = $this->runSignalQueryHostRunner($evidence);

        $this->assertSame('not_covered', $result['scenario_results']['malformed_signal_and_query_payloads']['status']);
        $this->assertContains(
            'signal_query_adversarial_error_shapes_uncovered',
            array_column($result['findings'], 'type'),
        );
        $this->assertSame('non_passing', $result['outcome']);
    }

    public function test_host_runner_does_not_satisfy_python_baseline_with_sibling_matrix_evidence(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        unset($evidence['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs']['cli_signal_and_query']);

        $this->assertTrue(
            $evidence['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['cli_signal_and_query'],
        );
        $this->assertTrue(
            $evidence['scenario_results']['python_worker_php_facing_and_cli_clients']['observed_outputs']['cli_signal_and_query'],
        );

        $result = $this->runSignalQueryHostRunner($evidence);

        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertContains('signal_query_python_smoke_uncovered', array_column($result['findings'], 'type'));
        $this->assertSame('non_passing', $result['outcome']);
    }

    public function test_host_runner_does_not_satisfy_python_baseline_with_external_worker_identity(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $evidence['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs']['worker_runtime'] =
            'external-http';

        $result = $this->runSignalQueryHostRunner($evidence);

        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertContains('signal_query_python_smoke_uncovered', array_column($result['findings'], 'type'));
        $this->assertSame('non_passing', $result['outcome']);
    }

    public function test_host_runner_does_not_satisfy_php_baseline_with_external_worker_identity(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $evidence['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['worker_runtime'] =
            'external-http';

        $result = $this->runSignalQueryHostRunner($evidence);

        $this->assertSame('not_covered', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertContains(
            'signal_query_php_worker_mirror_current_evidence_missing',
            array_column($result['findings'], 'type'),
        );
        $this->assertSame('non_passing', $result['outcome']);
    }

    public function test_host_runner_does_not_satisfy_php_baseline_with_stale_composer_package(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $evidence['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs'][
            'workflow_php_sdk_version'
        ] = '2.0.0-alpha.1';

        $result = $this->runSignalQueryHostRunner($evidence);
        $findings = $this->findingsForScenario($result, 'php_worker_cli_and_sdk_baseline');

        $this->assertSame('fail', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertNotEmpty($findings);
        $this->assertSame('signal_query_php_worker_mirror_failed', $findings[0]['type'] ?? null);
        $this->assertSame(
            'workflow_php_sdk_version_mismatch',
            $findings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
        $this->assertArrayNotHasKey(
            'missing_current_evidence',
            $findings[0]['current_evidence'] ?? [],
        );
        $this->assertSame('non_passing', $result['outcome']);
    }

    public function test_host_runner_rejects_imported_install_evidence_with_forbidden_scenario_sources(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $evidence['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            'local_product_source_checkout';

        $result = $this->runSignalQueryHostRunner($evidence);
        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertContains('signal_query_published_artifact_install_uncovered', array_column($result['findings'], 'type'));
        $this->assertSame('non_passing', $result['outcome']);
        $this->assertSame('non_passing', $evaluation['status']);
    }

    public function test_host_runner_does_not_pass_imported_matrix_cell_with_false_boolean_evidence(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $evidence['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['workflow_php_signal_and_query'] = false;

        $result = $this->runSignalQueryHostRunner($evidence);

        $this->assertSame('fail', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertContains('signal_query_php_worker_mirror_failed', array_column($result['findings'], 'type'));
    }

    public function test_host_runner_routes_php_mirror_probe_error_as_product_finding(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        unset($evidence['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['php_worker_query_task_routing']);
        $evidence['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['probe_error'] = [
            'type' => 'RuntimeError',
            'message' => 'PHP worker did not record routed current query evidence',
        ];

        $result = $this->runSignalQueryHostRunner($evidence);
        $findings = $this->findingsForScenario($result, 'php_worker_cli_and_sdk_baseline');

        $this->assertSame('fail', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertNotEmpty($findings);
        $this->assertSame('signal_query_php_worker_mirror_failed', $findings[0]['type'] ?? null);
        $this->assertSame(
            'php_worker_mirror_probe_failed',
            $findings[0]['current_evidence']['current_behavior_failures'][0]['code'] ?? null,
        );
        $this->assertArrayNotHasKey(
            'missing_current_evidence',
            $findings[0]['current_evidence'] ?? [],
        );
    }

    public function test_host_runner_retains_php_mirror_probe_diagnostics_in_result_and_record(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $outputs = &$evidence['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs'];
        unset($outputs['php_worker_query_task_routing']);
        $outputs['probe_error'] = [
            'type' => 'RuntimeError',
            'message' => 'PHP worker did not record routed current query evidence',
        ];
        $outputs['worker_registration'] = [
            'worker_id' => 'signals-queries-workflow-php-worker',
            'task_queue' => 'signals-queries-workflow-php',
            'capabilities' => ['query_tasks'],
        ];
        $outputs['workflow_php_start_sample'] = [
            'ok' => true,
            'status_code' => 201,
            'result' => ['run_id' => 'run-php-baseline'],
        ];
        $outputs['initial_query_sample'] = [
            'ok' => true,
            'status_code' => 200,
            'result' => 0,
        ];
        $outputs['cli_signal_sample'] = [
            'ok' => true,
            'status_code' => 202,
        ];
        $outputs['cli_query_sample'] = [
            'ok' => true,
            'status_code' => 200,
            'result' => 3,
        ];
        $outputs['workflow_php_signal_sample'] = [
            'ok' => true,
            'status_code' => 202,
        ];
        $outputs['workflow_php_query_sample'] = [
            'ok' => true,
            'status_code' => 200,
            'result' => 8,
        ];
        $outputs['repeat_query_sample'] = [
            'ok' => true,
            'status_code' => 200,
            'result' => 8,
        ];
        unset($outputs);

        $artifacts = $this->runSignalQueryHostRunnerArtifacts($evidence);
        $result = $artifacts['result'];
        $record = $artifacts['record'];

        $this->assertSame('fail', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('non_passing', $record['outcome']);

        $resultActual = $result['behavior_failure_diagnostics']['php_worker_cli_and_sdk_baseline'][
            'current_behavior_failures'
        ][0]['actual'] ?? null;
        $recordActual = $record['behavior_failure_diagnostics']['php_worker_cli_and_sdk_baseline'][
            'current_behavior_failures'
        ][0]['actual'] ?? null;

        $this->assertSame($resultActual, $recordActual);
        $this->assertSame(
            'PHP worker did not record routed current query evidence',
            $recordActual['probe_error']['message'] ?? null,
        );
        $this->assertSame(
            ['query_tasks'],
            $recordActual['worker_registration']['capabilities'] ?? null,
        );
        $this->assertSame(0, $recordActual['initial_query']['result'] ?? null);
        $this->assertSame(3, $recordActual['cli_query']['result'] ?? null);
        $this->assertSame(8, $recordActual['workflow_php_query']['result'] ?? null);
        $this->assertSame(8, $recordActual['repeat_query']['result'] ?? null);

        foreach ([
            'probe_error',
            'worker_registration',
            'workflow_php_start',
            'initial_query',
            'cli_signal',
            'cli_query',
            'workflow_php_signal',
            'workflow_php_query',
            'repeat_query',
        ] as $field) {
            $this->assertArrayHasKey($field, $recordActual);
        }
    }

    public function test_host_runner_retains_php_mirror_probe_diagnostics_from_compact_finding(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $phpScenario = &$evidence['scenario_results']['php_worker_cli_and_sdk_baseline'];
        $phpScenario['status'] = 'fail';
        $phpScenario['linked_findings'] = [
            [
                'id' => 'signal_query_php_worker_mirror_failed',
                'type' => 'signal_query_php_worker_mirror_failed',
                'scenario_id' => 'php_worker_cli_and_sdk_baseline',
                'title' => 'Signals/queries PHP worker mirror failed',
            ],
        ];
        $outputs = &$phpScenario['observed_outputs'];
        unset($outputs['php_worker_query_task_routing']);
        $outputs['probe_error'] = [
            'type' => 'RuntimeError',
            'message' => 'PHP worker did not record routed current query evidence',
        ];
        $outputs['worker_registration'] = [
            'worker_id' => 'signals-queries-workflow-php-worker',
            'task_queue' => 'signals-queries-workflow-php',
            'capabilities' => ['query_tasks'],
        ];
        $outputs['workflow_php_start_sample'] = [
            'ok' => true,
            'status_code' => 201,
            'result' => ['run_id' => 'run-php-baseline'],
        ];
        $outputs['initial_query_sample'] = [
            'ok' => true,
            'status_code' => 200,
            'result' => 0,
        ];
        $outputs['cli_signal_sample'] = [
            'ok' => true,
            'status_code' => 202,
        ];
        $outputs['cli_query_sample'] = [
            'ok' => true,
            'status_code' => 200,
            'result' => 3,
        ];
        $outputs['workflow_php_signal_sample'] = [
            'ok' => true,
            'status_code' => 202,
        ];
        $outputs['workflow_php_query_sample'] = [
            'ok' => true,
            'status_code' => 200,
            'result' => 8,
        ];
        $outputs['repeat_query_sample'] = [
            'ok' => true,
            'status_code' => 200,
            'result' => 8,
        ];
        unset($outputs, $phpScenario);

        $artifacts = $this->runSignalQueryHostRunnerArtifacts($evidence);
        $result = $artifacts['result'];
        $record = $artifacts['record'];
        $stdout = $artifacts['stdout'];
        $findings = $this->findingsForScenario($result, 'php_worker_cli_and_sdk_baseline');

        $this->assertSame('fail', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('non_passing', $record['outcome']);
        $this->assertNotEmpty($findings);
        $this->assertArrayNotHasKey('current_evidence', $findings[0]);

        $recordActual = $record['behavior_failure_diagnostics']['php_worker_cli_and_sdk_baseline'][
            'current_behavior_failures'
        ][0]['actual'] ?? null;
        $stdoutActual = $stdout['behavior_failure_diagnostics']['php_worker_cli_and_sdk_baseline'][
            'current_behavior_failures'
        ][0]['actual'] ?? null;

        $this->assertSame($recordActual, $stdoutActual);
        $this->assertSame(
            'PHP worker did not record routed current query evidence',
            $recordActual['probe_error']['message'] ?? null,
        );
        $this->assertSame(
            ['query_tasks'],
            $recordActual['worker_registration']['capabilities'] ?? null,
        );
        $this->assertSame(0, $recordActual['initial_query']['result'] ?? null);
        $this->assertSame(3, $recordActual['cli_query']['result'] ?? null);
        $this->assertSame(8, $recordActual['workflow_php_query']['result'] ?? null);
        $this->assertSame(8, $recordActual['repeat_query']['result'] ?? null);

        foreach ([
            'probe_error',
            'worker_registration',
            'workflow_php_start',
            'initial_query',
            'cli_signal',
            'cli_query',
            'workflow_php_signal',
            'workflow_php_query',
            'repeat_query',
        ] as $field) {
            $this->assertArrayHasKey($field, $recordActual);
            $this->assertArrayHasKey($field, $stdoutActual);
        }
    }

    public function test_host_runner_rejects_imported_query_replay_evidence_before_consistent_state(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $evidence['scenario_results']['query_during_replay']['observed_outputs']['query_handler_invoked_at'] =
            '2026-05-20T00:00:01Z';

        $result = $this->runSignalQueryHostRunner($evidence);

        $this->assertSame('fail', $result['scenario_results']['query_during_replay']['status']);
        $this->assertContains(
            'signal_query_query_during_replay_timing_failed',
            array_column($result['findings'], 'type'),
        );
    }

    public function test_result_gate_rejects_python_smoke_subset_even_when_the_smoke_passes(): void
    {
        $evaluation = SignalQueryRuntimeResultGate::evaluate([
            'schema' => SignalQueryRuntimeContract::RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.58',
                'workflow' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-python'],
                'same_language_cells' => [
                    [
                        'scenario' => 'python_worker_cli_and_sdk_baseline',
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                    ],
                ],
            ],
            'scenario_results' => [
                [
                    'scenario_id' => 'python_worker_cli_and_sdk_baseline',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'query_result' => 3,
                    ],
                ],
            ],
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('php_worker_cli_and_sdk_baseline', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['malformed_signal_and_query_payloads']['status'] = 'fail';
        unset($result['scenario_results']['malformed_signal_and_query_payloads']['linked_findings']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('malformed_signal_and_query_payloads', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_findings_for_omitted_required_scenarios(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['scenario_results']['php_worker_cli_and_sdk_baseline']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $missingScenarioFindingFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_scenario_finding',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('php_worker_cli_and_sdk_baseline', $evaluation['missing_scenarios']);
        $this->assertCount(1, $missingScenarioFindingFailures);
        $this->assertSame(
            'php_worker_cli_and_sdk_baseline',
            $missingScenarioFindingFailures[0]['scenario_id'],
        );

        $result['finding_links'] = [
            'php_worker_cli_and_sdk_baseline' => [
                'https://tracker.example/findings/php-worker-signal-query-baseline',
            ],
        ];

        $evaluationWithFinding = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluationWithFinding['status']);
        $this->assertNotContains(
            'missing_required_scenario_finding',
            array_column($evaluationWithFinding['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_duplicate_scenario_results(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results'] = array_values($result['scenario_results']);
        $result['scenario_results'][] = [
            'scenario_id' => 'python_worker_cli_and_sdk_baseline',
            'status' => 'pass',
            'observed_outputs' => [
                'query_result' => 4,
            ],
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $duplicateFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'duplicate_scenario_result',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $duplicateFailures);
        $this->assertSame('python_worker_cli_and_sdk_baseline', $duplicateFailures[0]['scenario_id']);
        $this->assertSame(2, $duplicateFailures[0]['count']);
        $this->assertSame(
            ['python_worker_cli_and_sdk_baseline' => 2],
            $evaluation['duplicate_scenarios'],
        );
    }

    public function test_result_gate_requires_run_metadata_for_a_passing_result(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['started_at'], $result['finished_at'], $result['outcome'], $result['findings'], $result['finding_links']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $missingFields = $this->missingRunRecordFields($evaluation);

        $this->assertSame('non_passing', $evaluation['status']);
        foreach (['started_at', 'finished_at', 'outcome', 'findings', 'finding_links'] as $field) {
            $this->assertContains($field, $missingFields);
        }
    }

    public function test_result_gate_accepts_contract_declared_non_passing_outcomes(): void
    {
        $coverageGate = SignalQueryRuntimeContract::manifest()['coverage_gate'];
        $acceptedOutcomes = [
            $coverageGate['uncovered_required_scenario_outcome'],
            $coverageGate['smoke_subset_outcome'],
            $coverageGate['unsupported_public_surface_outcome'],
            $coverageGate['runner_blocked_outcome'],
        ];

        foreach (array_unique($acceptedOutcomes) as $outcome) {
            $result = $this->completeSignalQueryResult();
            $result['outcome'] = $outcome;
            $result['scenario_results']['malformed_signal_and_query_payloads']['status'] =
                $outcome === $coverageGate['runner_blocked_outcome'] ? 'runner_blocked' : 'unsupported';
            $result['scenario_results']['malformed_signal_and_query_payloads']['linked_findings'] = [
                'https://tracker.example/findings/malformed-signal-query-payloads',
            ];

            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertNotContains(
                'invalid_declared_outcome',
                array_column($evaluation['gate_failures'], 'code'),
                'Outcome ' . $outcome . ' must remain valid because coverage_gate advertises it.',
            );
        }
    }

    public function test_result_gate_rejects_unknown_declared_outcome(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['outcome'] = 'product_gap';
        $result['scenario_results']['malformed_signal_and_query_payloads']['status'] = 'fail';
        $result['scenario_results']['malformed_signal_and_query_payloads']['linked_findings'] = [
            'https://tracker.example/findings/malformed-signal-query-payloads',
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'invalid_declared_outcome',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_unknown_declared_outcome_aliases(): void
    {
        foreach (['outcome', 'status', 'verdict'] as $field) {
            $result = $this->completeSignalQueryResult();
            unset($result['outcome'], $result['status'], $result['verdict']);
            $result[$field] = 'smoke_pass';

            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
            $invalidOutcomeFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'invalid_declared_outcome',
            ));

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertCount(1, $invalidOutcomeFailures);
            $this->assertSame($field, $invalidOutcomeFailures[0]['field']);
            $this->assertSame('smoke_pass', $invalidOutcomeFailures[0]['outcome']);
        }
    }

    public function test_result_gate_rejects_undocumented_pass_alias_declared_outcomes(): void
    {
        foreach (['passed', 'ok'] as $outcome) {
            $result = $this->completeSignalQueryResult();
            $result['outcome'] = $outcome;

            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
            $invalidOutcomeFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'invalid_declared_outcome',
            ));

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertCount(1, $invalidOutcomeFailures);
            $this->assertSame($outcome, $invalidOutcomeFailures[0]['outcome']);
        }
    }

    public function test_result_gate_rejects_placeholder_artifact_versions_embedded_in_install_channel_strings(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['artifactVersions'] = [
            'server' => 'durableworkflow/server:head',
            'cli' => 'durable-workflow-cli==current',
            'sdk-python' => 'durable-workflow==unresolved',
            'workflow' => 'durable-workflow/workflow:placeholder',
            'waterline' => 'durable-workflow/waterline:<latest>',
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $placeholderFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(
            ['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'],
            array_column($placeholderFailures, 'artifact'),
        );
    }

    public function test_result_gate_rejects_each_advertised_placeholder_word_inside_an_artifact_version(): void
    {
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder'] as $placeholder) {
            $result = $this->completeSignalQueryResult();
            $result['artifactVersions']['server'] = 'durableworkflow/server:' . $placeholder;

            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
            $serverPlaceholderFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version'
                    && ($failure['artifact'] ?? null) === 'server',
            ));

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertCount(1, $serverPlaceholderFailures);
            $this->assertSame('durableworkflow/server:' . $placeholder, $serverPlaceholderFailures[0]['version']);
        }
    }

    public function test_result_gate_rejects_complete_pass_with_non_passing_declared_outcome(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['outcome'] = 'non_passing';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $mismatchFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $mismatchFailures);
        $this->assertSame('non_passing', $mismatchFailures[0]['outcome']);
        $this->assertSame('non_passing', $mismatchFailures[0]['declared_status']);
        $this->assertSame('pass', $mismatchFailures[0]['evaluated_status']);
    }

    public function test_result_gate_rejects_non_passing_evidence_with_pass_declared_outcome(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['malformed_signal_and_query_payloads']['status'] = 'fail';
        $result['scenario_results']['malformed_signal_and_query_payloads']['linked_findings'] = [
            'https://tracker.example/findings/malformed-signal-query-payloads',
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $mismatchFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $mismatchFailures);
        $this->assertSame('pass', $mismatchFailures[0]['outcome']);
        $this->assertSame('pass', $mismatchFailures[0]['declared_status']);
        $this->assertSame('non_passing', $mismatchFailures[0]['evaluated_status']);
    }

    public function test_result_gate_rejects_empty_pass_output_arrays(): void
    {
        $emptyObservedOutputs = $this->completeSignalQueryResult();
        $emptyObservedOutputs['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs'] = [];

        $emptyRuntimeMatrix = $this->completeSignalQueryResult();
        unset($emptyRuntimeMatrix['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs']);
        $emptyRuntimeMatrix['scenario_results']['python_worker_cli_and_sdk_baseline']['runtime_matrix'] = [];

        foreach ([$emptyObservedOutputs, $emptyRuntimeMatrix] as $result) {
            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                'missing_pass_observed_outputs',
                array_column($evaluation['gate_failures'], 'code'),
            );
        }
    }

    public function test_result_gate_requires_declared_evidence_for_pass_scenarios(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['workflow_php_signal_and_query']);
        unset($result['scenario_results']['query_during_replay']['observed_outputs']['expected_answer']);
        unset($result['replay_timing']['query_during_replay']['expected_answer']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_required_pass_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );

        $missingEvidence = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_pass_evidence',
        ));

        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'php_worker_cli_and_sdk_baseline',
                'evidence_key' => 'workflow_php_signal_and_query',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'query_during_replay',
                'evidence_key' => 'expected_answer',
            ],
            $missingEvidence,
        );
    }

    public function test_result_gate_rejects_false_boolean_evidence_for_pass_matrix_scenarios(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['workflow_php_signal_and_query'] = false;

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'php_worker_cli_and_sdk_baseline',
                'evidence_key' => 'workflow_php_signal_and_query',
            ],
            $evaluation['gate_failures'],
        );
    }

    public function test_result_gate_does_not_satisfy_evidence_from_another_scenario_section(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['scenario_results']['query_during_replay']['observed_outputs']['worker_restart_at']);
        unset($result['replay_timing']['query_during_replay']['worker_restart_at']);

        $this->assertArrayHasKey(
            'worker_restart_at',
            $result['replay_timing']['signal_during_replay'],
        );

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $missingEvidence = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_pass_evidence',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'query_during_replay',
                'evidence_key' => 'worker_restart_at',
            ],
            $missingEvidence,
        );
    }

    public function test_result_gate_requires_declared_error_and_observer_surface_evidence(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['scenario_results']['completed_run_signal_and_query']['observed_outputs']['run_status_after_operations']);
        unset($result['terminal_run_behavior']['completed_run_signal_and_query']['run_status_after_operations']);
        unset(
            $result['scenario_results']['completed_run_signal_and_query']['observed_outputs']
                ['terminal_state_after_operations']['history_event_count']
        );
        unset(
            $result['terminal_run_behavior']['completed_run_signal_and_query']
                ['terminal_state_after_operations']['history_event_count']
        );
        unset($result['scenario_results']['unknown_signal_and_query_errors']['observed_outputs']['missing_workflow_query']);
        unset($result['adversarial_errors']['unknown_signal_and_query_errors']['missing_workflow_query']);
        unset($result['scenario_results']['malformed_signal_and_query_payloads']['observed_outputs']['invalid_query_arguments']);
        unset($result['adversarial_errors']['malformed_signal_and_query_payloads']['invalid_query_arguments']);
        unset(
            $result['scenario_results']['malformed_signal_and_query_payloads']['observed_outputs']
                ['invalid_query_arguments_context']
        );
        unset(
            $result['adversarial_errors']['malformed_signal_and_query_payloads']
                ['invalid_query_arguments_context']
        );
        unset(
            $result['scenario_results']['malformed_signal_and_query_payloads']['observed_outputs']
                ['signal_handler_invocation_count_after_invalid_payload']
        );
        unset(
            $result['adversarial_errors']['malformed_signal_and_query_payloads']
                ['signal_handler_invocation_count_after_invalid_payload']
        );
        unset(
            $result['scenario_results']['malformed_signal_and_query_payloads']['observed_outputs']
                ['sdk_python_invalid_query_arguments_sample']
        );
        unset(
            $result['adversarial_errors']['malformed_signal_and_query_payloads']
                ['sdk_python_invalid_query_arguments_sample']
        );
        unset(
            $result['scenario_results']['waterline_operator_visibility']['observed_outputs']
                ['observer_state']['paths']['selected_run_query_template']
        );
        unset(
            $result['waterline_observer_comparison']['waterline_operator_visibility']
                ['observer_state']['paths']['selected_run_query_template']
        );
        unset(
            $result['scenario_results']['waterline_operator_visibility']['observed_outputs']
                ['comparison']['sdk_observation']
        );
        unset(
            $result['waterline_observer_comparison']['waterline_operator_visibility']
                ['comparison']['sdk_observation']
        );
        unset(
            $result['scenario_results']['waterline_operator_visibility']['observed_outputs']
                ['api_captures']['selected_run_query_action']
        );
        unset(
            $result['waterline_observer_comparison']['waterline_operator_visibility']
                ['api_captures']['selected_run_query_action']
        );
        $result['scenario_results']['waterline_operator_visibility']['observed_outputs']
            ['comparison']['counter_state_matches_public_clients'] = false;
        $result['waterline_observer_comparison']['waterline_operator_visibility']
            ['comparison']['counter_state_matches_public_clients'] = false;

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $missingEvidence = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_pass_evidence',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'completed_run_signal_and_query',
                'evidence_key' => 'run_status_after_operations',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'completed_run_signal_and_query',
                'evidence_key' => 'terminal_state_after_operations.history_event_count',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'unknown_signal_and_query_errors',
                'evidence_key' => 'missing_workflow_query',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'malformed_signal_and_query_payloads',
                'evidence_key' => 'invalid_query_arguments',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'malformed_signal_and_query_payloads',
                'evidence_key' => 'invalid_query_arguments_context',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'malformed_signal_and_query_payloads',
                'evidence_key' => 'signal_handler_invocation_count_after_invalid_payload',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'malformed_signal_and_query_payloads',
                'evidence_key' => 'sdk_python_invalid_query_arguments_sample',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'waterline_operator_visibility',
                'evidence_key' => 'observer_state.paths.selected_run_query_template',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'waterline_operator_visibility',
                'evidence_key' => 'comparison.sdk_observation',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'waterline_operator_visibility',
                'evidence_key' => 'api_captures.selected_run_query_action',
            ],
            $missingEvidence,
        );
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'waterline_operator_visibility',
                'evidence_key' => 'comparison.counter_state_matches_public_clients',
            ],
            $missingEvidence,
        );
    }

    public function test_result_gate_rejects_wrong_ordered_delivery_total(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['ordered_signal_delivery']['observed_outputs']['queried_total'] = 54;

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unexpected_ordered_signal_total',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_wrong_ordered_delivery_accepted_total(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['ordered_signal_delivery']['observed_outputs']['accepted_signal_total'] = 54;

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unexpected_ordered_signal_accepted_total',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_wrong_ordered_delivery_history_order(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['ordered_signal_delivery']['observed_outputs']['history_signal_order'] = [
            1, 2, 3, 5, 4, 6, 7, 8, 9, 10,
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unexpected_ordered_signal_history_order',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_reports_accepted_signal_sequence_mismatch_before_total_or_order_drift(): void
    {
        $result = $this->completeSignalQueryResult();
        $observed = &$result['scenario_results']['ordered_signal_delivery']['observed_outputs'];
        $observed['accepted_signal_inputs'] = [1, 2, 3];
        $observed['accepted_signal_total'] = 6;
        $observed['queried_total'] = 6;
        $observed['history_signal_order'] = [1, 2, 3];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('unexpected_ordered_signal_acceptance', $codes);
        $this->assertNotContains('unexpected_ordered_signal_total', $codes);
        $this->assertNotContains('unexpected_ordered_signal_history_order', $codes);
    }

    public function test_result_gate_requires_ordered_delivery_sequences_to_be_integer_lists(): void
    {
        $result = $this->completeSignalQueryResult();
        $observed = &$result['scenario_results']['ordered_signal_delivery']['observed_outputs'];
        $observed['accepted_signal_inputs'] = ['1', 2, 3];
        $observed['history_signal_order'] = [
            'first' => 1,
            'second' => 2,
            'third' => 3,
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $sequenceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null)
                === 'invalid_ordered_signal_sequence_evidence',
        ));
        $codes = array_column($evaluation['gate_failures'], 'code');

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(2, $sequenceFailures);
        $this->assertSame(
            ['accepted_signal_inputs', 'history_signal_order'],
            array_column($sequenceFailures, 'evidence_key'),
        );
        $this->assertNotContains('unexpected_ordered_signal_total', $codes);
        $this->assertNotContains('unexpected_ordered_signal_history_order', $codes);
    }

    public function test_result_gate_rejects_malformed_payloads_without_documented_status_reason_or_integrity(): void
    {
        $result = $this->completeSignalQueryResult();
        $observed = &$result['scenario_results']['malformed_signal_and_query_payloads']['observed_outputs'];
        $section = &$result['adversarial_errors']['malformed_signal_and_query_payloads'];

        $observed['invalid_signal_arguments']['status_code'] = 500;
        $section['invalid_signal_arguments']['status_code'] = 500;
        $observed['invalid_query_arguments']['reason'] = 'server_error';
        $section['invalid_query_arguments']['reason'] = 'server_error';
        $observed['query_state_mutation_count_after_invalid_payload'] = 1;
        $section['query_state_mutation_count_after_invalid_payload'] = 1;
        unset($observed, $section);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unexpected_status_code',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'unexpected_malformed_payload_reason',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'malformed_payload_side_effect_observed',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_unknown_handler_errors_without_public_typed_shapes(): void
    {
        $result = $this->completeSignalQueryResult();
        $observed = &$result['scenario_results']['unknown_signal_and_query_errors']['observed_outputs'];
        $section = &$result['adversarial_errors']['unknown_signal_and_query_errors'];

        $observed['cli_unknown_signal_sample']['status_code'] = 500;
        $section['cli_unknown_signal_sample']['status_code'] = 500;
        $observed['sdk_python_unknown_query_sample']['reason'] = 'server_error';
        $section['sdk_python_unknown_query_sample']['reason'] = 'server_error';
        $observed['sdk_python_missing_workflow_query_sample']['exception'] = 'ServerError';
        $section['sdk_python_missing_workflow_query_sample']['exception'] = 'ServerError';
        unset($observed, $section);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unexpected_status_code',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'unexpected_unknown_handler_reason',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'unexpected_unknown_handler_sdk_exception',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_signal_applied_before_replay_completed(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['signal_during_replay']['observed_outputs']['signal_applied_at'] =
            '2026-05-20T00:00:01Z';
        $result['replay_timing']['signal_during_replay']['signal_applied_at'] =
            '2026-05-20T00:00:01Z';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'invalid_signal_replay_timing_order',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_query_handler_invoked_before_replay_completed(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['query_during_replay']['observed_outputs']['query_handler_invoked_at'] =
            '2026-05-20T00:00:01Z';
        $result['replay_timing']['query_during_replay']['query_handler_invoked_at'] =
            '2026-05-20T00:00:01Z';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'invalid_query_replay_timing_order',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_query_poll_started_after_replay_completed(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['query_during_replay']['observed_outputs']['query_poll_started_at'] =
            '2026-05-20T00:00:05Z';
        $result['replay_timing']['query_during_replay']['query_poll_started_at'] =
            '2026-05-20T00:00:05Z';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'invalid_query_replay_timing_order',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_fractional_second_replay_timing_order(): void
    {
        $evaluation = SignalQueryRuntimeResultGate::evaluate(
            $this->withFractionalSecondReplayTiming($this->completeSignalQueryResult()),
        );

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_completed_run_signal_without_typed_terminal_reason(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['completed_run_signal_and_query']['observed_outputs']['signal_error'] = [
            'status_code' => 202,
            'reason' => 'accepted',
            'rejection_reason' => 'accepted',
        ];
        $result['terminal_run_behavior']['completed_run_signal_and_query']['signal_error'] = [
            'status_code' => 202,
            'reason' => 'accepted',
            'rejection_reason' => 'accepted',
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unexpected_status_code',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'unexpected_terminal_signal_reason',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_completed_run_terminal_result_or_history_mutation(): void
    {
        foreach ([
            'terminal_result_changed_after_operations' => static function (array &$outputs): void {
                $outputs['terminal_result_changed_after_operations'] = true;
                $outputs['terminal_state_after_operations']['result'] = ['current' => 9];
            },
            'terminal_history_changed_after_operations' => static function (array &$outputs): void {
                $outputs['terminal_history_changed_after_operations'] = true;
                $outputs['terminal_state_after_operations']['history_event_count'] = 3;
            },
        ] as $expectedCode => $mutate) {
            $result = $this->completeSignalQueryResult();
            $mutate($result['scenario_results']['completed_run_signal_and_query']['observed_outputs']);
            $mutate($result['terminal_run_behavior']['completed_run_signal_and_query']);

            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains($expectedCode, array_column($evaluation['gate_failures'], 'code'));
        }

        $sectionOnly = $this->completeSignalQueryResult();
        $sectionOnly['terminal_run_behavior']['completed_run_signal_and_query'][
            'terminal_result_changed_after_operations'
        ] = true;

        $evaluation = SignalQueryRuntimeResultGate::evaluate($sectionOnly);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'terminal_result_changed_after_operations',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_accepts_a_complete_passing_matrix(): void
    {
        $evaluation = SignalQueryRuntimeResultGate::evaluate($this->completeSignalQueryResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_rejects_pass_when_scenario_artifact_versions_do_not_match_run_tuple(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['artifactVersions'] = $this->currentHostRunnerArtifactVersions();

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'scenario_artifact_version_mismatch',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_section_artifact_versions_that_do_not_match_run_tuple(): void
    {
        $result = $this->completeSignalQueryResultForCurrentHostRunner();
        $result['replay_timing']['artifactVersions'] = [
            'server' => '0.2.140',
            'cli' => '0.1.74',
            'sdk-python' => '0.4.84',
            'workflow' => '2.0.0-alpha.187',
            'waterline' => '2.0.0-alpha.69',
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $sectionTupleFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'scenario_artifact_version_mismatch'
                && ($failure['field'] ?? null) === 'artifactVersions'
                && ($failure['path'] ?? null) === '$.replay_timing.artifactVersions'
                && ($failure['artifact'] ?? null) === 'server',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($sectionTupleFailures);
    }

    public function test_result_gate_rejects_forbidden_sources_reported_in_scenario_outputs(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources']['server'] =
            'local_product_source_checkout';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $forbiddenSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source'
                && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                && ($failure['field'] ?? null) === 'artifact_sources'
                && ($failure['artifact'] ?? null) === 'server',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($forbiddenSourceFailures);
    }

    public function test_result_gate_rejects_install_pass_with_generic_or_mismatched_published_sources(): void
    {
        foreach ([
            'generic' => ['server', 'published', 'published_docker_image'],
            'mismatched' => ['sdk-python', 'published_cli_release', 'published_pypi_package'],
        ] as $case => [$artifact, $actualSource, $expectedSource]) {
            $result = $this->completeSignalQueryResult();
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources'][$artifact] =
                $actualSource;
            foreach (
                $result['scenario_results']['published_artifact_install_only']['observed_outputs'][
                    'artifact_install_evidence'
                ]['artifacts'] as &$installArtifact
            ) {
                if (($installArtifact['artifact'] ?? null) === $artifact) {
                    $installArtifact['source'] = $actualSource;
                }
            }
            unset($installArtifact);

            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
            $sourceFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'unexpected_published_artifact_source'
                    && ($failure['scenario_id'] ?? null) === 'published_artifact_install_only'
                    && ($failure['artifact'] ?? null) === $artifact
                    && ($failure['actual_source'] ?? null) === $actualSource
                    && ($failure['expected_source'] ?? null) === $expectedSource,
            ));

            $this->assertSame('non_passing', $evaluation['status'], $case);
            $this->assertNotEmpty($sourceFailures, $case);
            $this->assertContains(
                'invalid_published_artifact_install_evidence_source',
                array_column($evaluation['gate_failures'], 'code'),
                $case,
            );
        }
    }

    public function test_result_gate_allows_non_passing_install_observation_with_configured_sources(): void
    {
        $result = $this->completeSignalQueryResult();
        $versions = $result['scenario_results']['published_artifact_install_only']['observed_outputs'][
            'published_artifact_versions'
        ];
        $sources = [
            'server' => 'configured_server_endpoint',
            'cli' => 'configured_cli_binary',
            'sdk-python' => 'configured_python_environment',
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];
        $findingId = 'signal_query_published_artifact_install_uncovered';

        $result['outcome'] = 'non_passing';
        $result['scenario_results']['published_artifact_install_only'] = [
            'scenario_id' => 'published_artifact_install_only',
            'status' => 'not_covered',
            'linked_findings' => [$findingId],
            'observed_outputs' => [
                'published_artifact_versions' => $versions,
                'artifact_sources' => $sources,
                'artifact_install_evidence' => [
                    'local_product_source_checkouts_used' => false,
                    'artifacts' => [
                        [
                            'artifact' => 'server',
                            'status' => 'not_proved',
                            'version' => $versions['server'],
                            'source' => 'configured_server_endpoint',
                            'local_product_source_checkouts_used' => false,
                        ],
                        [
                            'artifact' => 'cli',
                            'status' => 'not_proved',
                            'version' => $versions['cli'],
                            'source' => 'configured_cli_binary',
                            'local_product_source_checkouts_used' => false,
                        ],
                        [
                            'artifact' => 'sdk-python',
                            'status' => 'not_proved',
                            'version' => $versions['sdk-python'],
                            'source' => 'configured_python_environment',
                            'local_product_source_checkouts_used' => false,
                        ],
                    ],
                ],
                'local_product_source_checkouts_used' => false,
            ],
        ];
        $result['findings'] = [
            [
                'id' => $findingId,
                'type' => $findingId,
                'scenario_id' => 'published_artifact_install_only',
            ],
        ];

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotContains(
            'unexpected_published_artifact_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertNotContains(
            'invalid_published_artifact_install_evidence_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_install_pass_without_per_artifact_install_evidence(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_install_evidence']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_published_artifact_install_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_python_baseline_pass_with_external_worker_identity(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs']['worker_runtime'] =
            'external-http';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'python_worker_baseline_runtime_not_sdk_python',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_package_labels_as_python_worker_runtime(): void
    {
        foreach (['python-sdk', 'durable-workflow-python'] as $runtime) {
            $result = $this->completeSignalQueryResult();
            $result['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs']['worker_runtime'] =
                $runtime;

            $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status'], $runtime);
            $this->assertContains(
                'python_worker_baseline_runtime_not_sdk_python',
                array_column($evaluation['gate_failures'], 'code'),
                $runtime,
            );
        }
    }

    public function test_result_gate_rejects_python_baseline_pass_with_generic_worker_source(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs'][
            'python_worker_artifact_source'
        ] = 'published';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'python_worker_baseline_source_not_published_sdk',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_php_baseline_pass_with_external_worker_identity(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['worker_runtime'] =
            'external-http';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'php_worker_baseline_runtime_not_workflow_php',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_php_baseline_pass_with_generic_worker_source(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs'][
            'workflow_php_artifact_source'
        ] = 'published';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'php_worker_baseline_source_not_published_workflow_php',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_php_baseline_pass_with_stale_composer_package(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs'][
            'workflow_php_sdk_version'
        ] = '2.0.0-alpha.1';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'php_worker_baseline_sdk_version_mismatch',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_python_baseline_pass_without_routed_current_query_task(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs']['routed_current_query_task']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'missing_required_pass_evidence',
                'scenario_id' => 'python_worker_cli_and_sdk_baseline',
                'evidence_key' => 'routed_current_query_task',
            ],
            $evaluation['gate_failures'],
        );
        $this->assertContains(
            'python_worker_baseline_current_query_not_routed',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_python_baseline_pass_with_local_current_query_observation(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs'][
            'routed_current_query_task'
        ] = $this->routedCurrentQueryTaskEvidence([
            'public_query_surface' => 'local_handler',
            'server_route' => 'local_method_call',
            'query_task_id' => '',
        ]);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'python_worker_baseline_current_query_not_routed',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_python_baseline_pass_with_failed_routed_current_query_task(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs'][
            'routed_current_query_task'
        ] = $this->routedCurrentQueryTaskEvidence([
            'status' => 'fail',
        ]);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'python_worker_baseline_current_query_not_routed',
                'scenario_id' => 'python_worker_cli_and_sdk_baseline',
                'field' => 'routed_current_query_task.status',
                'expected' => ['pass', 'completed'],
                'actual' => 'fail',
            ],
            $evaluation['gate_failures'],
        );
    }

    public function test_result_gate_rejects_php_baseline_pass_without_routed_current_query_task(): void
    {
        $result = $this->completeSignalQueryResult();
        unset($result['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs']['routed_current_query_task']);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'php_worker_baseline_current_query_not_routed',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_php_baseline_pass_with_python_routed_current_query_task(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs'][
            'routed_current_query_task'
        ] = $this->routedCurrentQueryTaskEvidence([
            'workflow_id' => 'wf-php-baseline',
            'run_id' => 'run-php-baseline',
            'task_queue' => 'signals-queries-workflow-php',
            'worker_id' => 'signals-queries-workflow-php-worker',
            'lease_owner' => 'signals-queries-workflow-php-worker',
        ]);

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            [
                'code' => 'php_worker_baseline_current_query_not_routed',
                'scenario_id' => 'php_worker_cli_and_sdk_baseline',
                'field' => 'routed_current_query_task.worker_runtime',
                'expected' => 'workflow-php',
                'actual' => 'sdk-python',
            ],
            $evaluation['gate_failures'],
        );
    }

    public function test_result_gate_rejects_forbidden_sources_reported_in_section_evidence(): void
    {
        $result = $this->completeSignalQueryResult();
        $result['adversarial_errors']['artifact_sources']['server'] = 'workspace_repo_as_artifact_under_test';
        $result['waterline_observer_comparison']['waterline_operator_visibility']['artifact_sources']['waterline'] =
            'local_product_source_checkout';

        $evaluation = SignalQueryRuntimeResultGate::evaluate($result);
        $sectionSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source'
                && ($failure['field'] ?? null) === 'artifact_sources'
                && ($failure['path'] ?? null) === '$.adversarial_errors.artifact_sources'
                && ($failure['artifact'] ?? null) === 'server',
        ));
        $scenarioSectionSourceFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'forbidden_artifact_source'
                && ($failure['scenario_id'] ?? null) === 'waterline_operator_visibility'
                && ($failure['field'] ?? null) === 'artifact_sources'
                && ($failure['path'] ?? null) === '$.waterline_observer_comparison.waterline_operator_visibility.artifact_sources'
                && ($failure['artifact'] ?? null) === 'waterline',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertNotEmpty($sectionSourceFailures);
        $this->assertNotEmpty($scenarioSectionSourceFailures);
    }

    /**
     * @param array<string, mixed> $evaluation
     *
     * @return list<string>
     */
    private function missingRunRecordFields(array $evaluation): array
    {
        $fields = [];
        foreach ($evaluation['gate_failures'] ?? [] as $failure) {
            if (! is_array($failure) || ($failure['code'] ?? null) !== 'missing_run_record_field') {
                continue;
            }

            $field = $failure['field'] ?? null;
            if (is_string($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $smokeEvidence
     *
     * @return array<string, mixed>
     */
    private function runSignalQueryHostRunner(array $smokeEvidence): array
    {
        return $this->runSignalQueryHostRunnerArtifacts($smokeEvidence)['result'];
    }

    /**
     * @param array<string, mixed> $smokeEvidence
     *
     * @return array{result: array<string, mixed>, record: array<string, mixed>, stdout: array<string, mixed>}
     */
    private function runSignalQueryHostRunnerArtifacts(array $smokeEvidence): array
    {
        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-signals-queries-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir);

        try {
            $smokePath = $resultDir . '/smoke.json';
            file_put_contents($smokePath, json_encode($smokeEvidence, JSON_THROW_ON_ERROR));

            $command = implode(' ', [
                'DW_SERVER_VERSION=0.2.224',
                'DW_CLI_VERSION=0.1.74',
                'DW_PYTHON_SDK_VERSION=0.4.84',
                'DW_RUST_SDK_VERSION=0.1.2',
                'DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.187',
                'DW_WATERLINE_VERSION=2.0.0-alpha.69',
                'DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE=0',
                'DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE=0',
                'DW_SIGNALS_QUERIES_RUN_WATERLINE_OBSERVER_PROBE=0',
                'DW_SIGNALS_QUERIES_RUN_RUST_MATRIX_PROBE=0',
                'DW_SIGNALS_QUERIES_SMOKE_EVIDENCE=' . escapeshellarg($smokePath),
                escapeshellarg($root . '/scripts/conformance/signals-queries-published-artifacts.sh'),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command . ' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            $stdoutRecord = null;
            foreach (array_reverse($output) as $line) {
                $decoded = json_decode($line, true);
                if (
                    json_last_error() === JSON_ERROR_NONE
                    && is_array($decoded)
                    && array_key_exists('outcome', $decoded)
                ) {
                    $stdoutRecord = $decoded;
                    break;
                }
            }
            $this->assertIsArray($stdoutRecord, implode("\n", $output));

            $resultPath = $resultDir . '/signals-queries-result.json';
            $this->assertFileExists($resultPath);

            $recordPath = $resultDir . '/signals-queries-record.json';
            $this->assertFileExists($recordPath);

            return [
                'result' => json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR),
                'record' => json_decode((string) file_get_contents($recordPath), true, 512, JSON_THROW_ON_ERROR),
                'stdout' => $stdoutRecord,
            ];
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{result: array<string, mixed>, record: array<string, mixed>, metadata: array<string, mixed>}
     */
    private function runSignalQueryHostRunnerWithEnvironment(array $environment): array
    {
        $root = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir() . '/dw-signals-queries-test-' . bin2hex(random_bytes(6));
        mkdir($resultDir);

        try {
            $assignments = [
                'DW_SERVER_VERSION' => '0.2.224',
                'DW_CLI_VERSION' => '0.1.74',
                'DW_PYTHON_SDK_VERSION' => '0.4.84',
                'DW_RUST_SDK_VERSION' => '0.1.2',
                'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.187',
                'DW_WATERLINE_VERSION' => '2.0.0-alpha.69',
                'DW_SIGNALS_QUERIES_RUN_WATERLINE_OBSERVER_PROBE' => '0',
                'DW_SIGNALS_QUERIES_RUN_RUST_MATRIX_PROBE' => '0',
            ];
            foreach ($environment as $key => $value) {
                $assignments[$key] = $value;
            }

            $command = implode(' ', array_map(
                static fn (string $key, string $value): string => $key . '=' . escapeshellarg($value),
                array_keys($assignments),
                array_values($assignments),
            ));
            $command .= ' ' . implode(' ', [
                escapeshellarg($root . '/scripts/conformance/signals-queries-published-artifacts.sh'),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command . ' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            return [
                'result' => json_decode(
                    (string) file_get_contents($resultDir . '/signals-queries-result.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
                'record' => json_decode(
                    (string) file_get_contents($resultDir . '/signals-queries-record.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
                'metadata' => json_decode(
                    (string) file_get_contents($resultDir . '/run-metadata.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                ),
            ];
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<int, array<string, mixed>>
     */
    private function findingsForScenario(array $result, string $scenarioId): array
    {
        return array_values(array_filter(
            $result['findings'] ?? [],
            static fn (array $finding): bool => ($finding['scenario_id'] ?? null) === $scenarioId,
        ));
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

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $probe
     *
     * @return array<string, mixed>
     */
    private function runProbeEvidenceMerge(array $base, array $probe): array
    {
        $payload = json_encode(
            [
                'base' => $base,
                'probe' => $probe,
            ],
            JSON_THROW_ON_ERROR,
        );
        $process = proc_open(
            ['python3', '-c', $this->probeEvidenceMergeScript()],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            $this->fail('Unable to start python3 for probe evidence merge test.');
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, (string) $stderr);

        return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    private function probeEvidenceMergeScript(): string
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );
        $start = strpos($source, "\ndef merge_probe_evidence(");
        $end = $start === false ? false : strpos($source, "\n\nMISSING = object()", $start);

        if ($start === false || $end === false) {
            $this->fail('Unable to extract merge_probe_evidence from host runner.');
        }

        $function = substr($source, $start + 1, $end - $start - 1);

        return implode("\n", [
            'from __future__ import annotations',
            'import json',
            'import sys',
            'from typing import Any',
            $function,
            'payload = json.loads(sys.stdin.read())',
            'base = payload["base"]',
            'merged = merge_probe_evidence(base, payload["probe"])',
            'print(json.dumps({"base": base, "merged": merged}))',
            '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runSignalQueryRunnerPythonSnippet(string $snippet): array
    {
        $script = $this->signalQueryRunnerPythonDefinitions() . "\n" . $snippet;
        $process = proc_open(
            ['python3', '-'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            $this->fail('Unable to start python3 for signal/query runner snippet test.');
        }

        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, (string) $stderr);

        return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    private function signalQueryRunnerPythonDefinitions(): string
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/scripts/conformance/signals-queries-published-artifacts.sh',
        );
        $marker = "python3 - <<'PY'\n";
        $start = strpos($source, $marker);
        $end = $start === false ? false : strpos($source, "\nresult_dir = Path(os.environ[\"RESULT_DIR\"])", $start);

        if ($start === false || $end === false) {
            $this->fail('Unable to extract Python definitions from signal/query host runner.');
        }

        return substr($source, $start + strlen($marker), $end - $start - strlen($marker));
    }

    /**
     * @param array<string, string> $versions
     * @param array<string, string> $sources
     *
     * @return array<string, mixed>
     */
    private function installEvidenceForVersions(array $versions, array $sources): array
    {
        $artifacts = [];
        foreach (['server', 'cli', 'sdk-python', 'sdk-rust', 'workflow-php', 'waterline'] as $artifact) {
            $version = $versions[$artifact] ?? ($artifact === 'workflow-php' ? ($versions['workflow'] ?? '') : '');
            $artifacts[] = [
                'artifact' => $artifact,
                'status' => 'pass',
                'version' => $version,
                'source' => $sources[$artifact],
                'local_product_source_checkouts_used' => false,
            ];
        }

        return [
            'local_product_source_checkouts_used' => false,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function currentHostRunnerArtifactVersions(): array
    {
        return [
            'server' => '0.2.224',
            'cli' => '0.1.74',
            'sdk-python' => '0.4.84',
            'sdk-rust' => '0.1.2',
            'workflow' => '2.0.0-alpha.187',
            'workflow-php' => '2.0.0-alpha.187',
            'waterline' => '2.0.0-alpha.69',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function expectedHostRunnerArtifactSources(): array
    {
        return [
            'server' => 'published_docker_image',
            'cli' => 'published_cli_release',
            'sdk-python' => 'published_pypi_package',
            'sdk-rust' => 'published_crates_io_package',
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function routedCurrentQueryTaskEvidence(array $overrides = []): array
    {
        return array_replace([
            'schema' => 'durable-workflow.v2.signal-query-runtime.routed-current-query-task',
            'status' => 'pass',
            'worker_runtime' => 'sdk-python',
            'public_query_surface' => 'cli',
            'server_route' => 'worker_query_task_poll',
            'completion_route' => 'worker_query_task_complete',
            'observed_via' => 'sdk-python worker query task interceptor',
            'query_task_id' => 'query-task-current-1',
            'query_task_attempt' => 1,
            'workflow_id' => 'wf-python-baseline',
            'run_id' => 'run-python-baseline',
            'workflow_type' => 'conformance.counter',
            'task_queue' => 'signals-queries-python-sdk',
            'worker_id' => 'signals-queries-python-sdk-worker',
            'lease_owner' => 'signals-queries-python-sdk-worker',
            'query_name' => 'current',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderedQueryResponderEvidence(): array
    {
        return [
            'worker_id' => 'ordered-worker',
            'task_queue' => 'ordered-queue',
            'query_claimed_at' => '2026-07-12T12:00:01Z',
            'eligible_when_claimed' => true,
            'claim_eligibility' => [
                'eligible' => true,
                'worker_id' => 'ordered-worker',
                'task_queue' => 'ordered-queue',
                'status' => 'active',
                'capabilities' => ['query_tasks'],
                'last_heartbeat_at' => '2026-07-12T12:00:00Z',
            ],
            'claimed_query_task' => [
                'query_task_id' => 'ordered-query-task-1',
                'workflow_id' => 'wf-ordered',
                'run_id' => 'run-ordered',
                'query_name' => 'state',
                'task_queue' => 'ordered-queue',
                'lease_owner' => 'ordered-worker',
            ],
            'query_task_completion' => [
                'status_code' => 200,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postErrorQueryResponderEvidence(): array
    {
        return [
            'worker_id' => 'unknown-worker',
            'task_queue' => 'unknown-queue',
            'heartbeat_before_poll' => [
                'status_code' => 200,
            ],
            'heartbeat_acknowledged_at' => '2026-07-12T12:00:00Z',
            'query_poll_ready_at' => '2026-07-12T12:00:00Z',
            'query_claimed_at' => '2026-07-12T12:00:01Z',
            'eligible_when_claimed' => true,
            'claim_eligibility' => [
                'eligible' => true,
                'worker_id' => 'unknown-worker',
                'task_queue' => 'unknown-queue',
                'status' => 'active',
                'capabilities' => ['query_tasks'],
                'last_heartbeat_at' => '2026-07-12T12:00:00Z',
            ],
            'claimed_query_task' => [
                'query_task_id' => 'post-error-query-task-1',
                'workflow_id' => 'wf-unknown',
                'run_id' => 'run-unknown',
                'query_name' => 'state',
                'task_queue' => 'unknown-queue',
                'lease_owner' => 'unknown-worker',
            ],
            'query_task_completion' => [
                'status_code' => 200,
            ],
            'responder_error' => null,
            'responder_timed_out' => false,
        ];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function withFractionalSecondReplayTiming(array $result): array
    {
        $updates = [
            'signal_during_replay' => [
                'worker_restart_at' => '2026-05-20T00:00:01.100000Z',
                'signal_sent_at' => '2026-05-20T00:00:01.200000Z',
                'replay_completed_at' => '2026-05-20T00:00:01.700000Z',
                'signal_applied_at' => '2026-05-20T00:00:01.800000Z',
            ],
            'query_during_replay' => [
                'worker_restart_at' => '2026-05-20T00:00:01.100000Z',
                'query_sent_at' => '2026-05-20T00:00:01.250000Z',
                'query_poll_started_at' => '2026-05-20T00:00:01.300000Z',
                'replay_completed_at' => '2026-05-20T00:00:01.700000Z',
                'query_handler_invoked_at' => '2026-05-20T00:00:01.750000Z',
                'query_completed_at' => '2026-05-20T00:00:01.900000Z',
            ],
        ];

        foreach ($updates as $scenarioId => $values) {
            foreach ($values as $key => $value) {
                $result['scenario_results'][$scenarioId]['observed_outputs'][$key] = $value;
                $result['replay_timing'][$scenarioId][$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSignalQueryResultForCurrentHostRunner(): array
    {
        $result = $this->completeSignalQueryResult();
        $versions = $this->currentHostRunnerArtifactVersions();

        $this->replaceDeclaredArtifactVersions($result, $versions);
        $result['artifactVersions'] = $versions;
        $result['artifact_versions'] = $versions;
        $result['scenario_results']['published_artifact_install_only']['observed_outputs'][
            'published_artifact_versions'
        ] = $versions;
        $result['scenario_results']['published_artifact_install_only']['observed_outputs'][
            'artifact_install_evidence'
        ] = $this->installEvidenceForVersions(
            $versions,
            $result['scenario_results']['published_artifact_install_only']['observed_outputs']['artifact_sources'],
        );
        $result['scenario_results']['python_worker_cli_and_sdk_baseline']['observed_outputs'][
            'python_worker_sdk_version'
        ] = $versions['sdk-python'];
        $result['scenario_results']['php_worker_cli_and_sdk_baseline']['observed_outputs'][
            'workflow_php_sdk_version'
        ] = $versions['workflow-php'];

        return $result;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, string> $versions
     */
    private function replaceDeclaredArtifactVersions(array &$value, array $versions): void
    {
        foreach (['artifact_versions', 'artifactVersions', 'published_artifact_versions', 'publishedArtifactVersions'] as $field) {
            if (isset($value[$field]) && is_array($value[$field])) {
                $value[$field] = $versions;
            }
        }

        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->replaceDeclaredArtifactVersions($child, $versions);
            }
        }
        unset($child);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeSignalQueryResult(): array
    {
        $scenarioResults = [];
        foreach (SignalQueryRuntimeContract::manifest()['required_scenarios'] as $scenario) {
            $scenarioResults[$scenario] = [
                'scenario_id' => $scenario,
                'status' => 'pass',
                'observed_outputs' => [
                    'recorded' => true,
                ],
            ];
        }

        $publishedVersions = [
            'server' => '0.2.140',
            'cli' => '0.1.45',
            'sdk-python' => '0.4.58',
            'sdk-rust' => '0.1.2',
            'workflow' => '2.0.0-alpha.161',
            'workflow-php' => '2.0.0-alpha.161',
            'waterline' => '2.0.0-alpha.54',
        ];
        $artifactSources = [
            'server' => 'published_docker_image',
            'cli' => 'published_cli_release',
            'sdk-python' => 'published_pypi_package',
            'sdk-rust' => 'published_crates_io_package',
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];
        $scenarioResults['published_artifact_install_only']['observed_outputs'] = [
            'published_artifact_versions' => $publishedVersions,
            'artifact_sources' => $artifactSources,
            'artifact_install_evidence' => $this->installEvidenceForVersions($publishedVersions, $artifactSources),
        ];
        $scenarioResults['python_worker_cli_and_sdk_baseline']['observed_outputs'] = [
            'worker_runtime' => 'sdk-python',
            'python_worker_artifact_source' => 'published_pypi_package',
            'python_worker_sdk_version' => '0.4.58',
            'python_worker_query_task_routing' => true,
            'routed_current_query_task' => $this->routedCurrentQueryTaskEvidence([
                'workflow_id' => 'wf-python-baseline',
                'run_id' => 'run-python-baseline',
                'task_queue' => 'signals-queries-python-sdk',
                'worker_id' => 'signals-queries-python-sdk-worker',
                'lease_owner' => 'signals-queries-python-sdk-worker',
            ]),
            'cli_signal_and_query' => true,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
            'workflow_id' => 'wf-python-baseline',
            'run_id' => 'run-python-baseline',
            'task_queue' => 'signals-queries-python-sdk',
            'worker_id' => 'signals-queries-python-sdk-worker',
        ];
        $scenarioResults['php_worker_cli_and_sdk_baseline']['observed_outputs'] = [
            'worker_runtime' => 'workflow-php',
            'workflow_php_artifact_source' => 'published_composer_package',
            'workflow_php_sdk_version' => '2.0.0-alpha.161',
            'php_worker_query_task_routing' => true,
            'routed_current_query_task' => $this->routedCurrentQueryTaskEvidence([
                'worker_runtime' => 'workflow-php',
                'workflow_id' => 'wf-php-baseline',
                'run_id' => 'run-php-baseline',
                'task_queue' => 'signals-queries-workflow-php',
                'worker_id' => 'signals-queries-workflow-php-worker',
                'lease_owner' => 'signals-queries-workflow-php-worker',
                'observed_via' => 'workflow-php worker query task executor',
            ]),
            'cli_signal_and_query' => true,
            'workflow_php_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
        ];
        $scenarioResults['python_worker_php_facing_and_cli_clients']['observed_outputs'] = [
            'php_client_signal_and_query' => true,
            'cli_signal_and_query' => true,
            'cross_language_query_consistency' => true,
            'wire_envelope_compatibility' => true,
        ];
        $scenarioResults['php_worker_python_and_cli_clients']['observed_outputs'] = [
            'sdk_python_signal_and_query' => true,
            'cli_signal_and_query' => true,
            'cross_language_query_consistency' => true,
            'wire_envelope_compatibility' => true,
        ];
        $rustProvenance = [
            'package' => 'durable-workflow',
            'resolved_version' => '0.1.2',
            'source' => 'registry+https://github.com/rust-lang/crates.io-index',
            'checksum' => str_repeat('a', 64),
        ];
        $rustIdentity = [
            'rust_sdk_version' => '0.1.2',
            'rust_crate_provenance' => $rustProvenance,
        ];
        $scenarioResults['rust_worker_rust_php_python_clients']['observed_outputs'] = [
            ...$rustIdentity,
            'worker_runtime' => 'sdk-rust',
            'rust_worker_registration' => ['sdk_version' => 'durable-workflow-rust/0.1.2'],
            'apache_avro_provenance' => [
                'package' => 'apache-avro',
                'resolved_version' => '0.21.0',
                'source' => 'registry+https://github.com/rust-lang/crates.io-index',
                'checksum' => str_repeat('b', 64),
            ],
            'query_state_model' => 'snapshot_derived_transport_state',
            'ordered_signal_values' => [3, 5],
            'rust_query_results' => ['running' => 8, 'completed' => 8],
            'workflow_php_query_results' => ['running' => 8, 'completed' => 8],
            'sdk_python_query_results' => ['running' => 8, 'completed' => 8],
            'valid_avro_signal_and_query' => [
                'default_codec' => 'avro',
                'payload_codec' => 'avro',
                'observed_value' => 8,
            ],
            'repeat_query_consistency' => true,
        ];
        $scenarioResults['python_worker_rust_client']['observed_outputs'] = [
            ...$rustIdentity,
            'worker_runtime' => 'sdk-python',
            'ordered_signal_values' => [4, 6],
            'default_codec' => 'avro',
            'payload_codec' => 'avro',
            'rust_query_results' => [10, 10],
            'repeat_query_consistency' => true,
        ];
        $scenarioResults['php_worker_rust_client']['observed_outputs'] = [
            ...$rustIdentity,
            'worker_runtime' => 'workflow-php',
            'ordered_signal_values' => [4, 6],
            'default_codec' => 'avro',
            'payload_codec' => 'avro',
            'rust_query_results' => [10, 10],
            'rust_query_observed_values' => [4, 10, 10],
            'prefix_consistent_query_results' => true,
            'query_result_rollback_free' => true,
            'repeat_query_consistency' => true,
        ];
        $scenarioResults['rust_query_error_and_immutability']['observed_outputs'] = [
            ...$rustIdentity,
            'query_state_model' => 'snapshot_derived_transport_state',
            'unknown_query' => ['reason' => 'rejected_unknown_query'],
            'malformed_query_payload' => ['reason' => 'query_payload_decode_failed'],
            'unavailable_query_handler' => ['reason' => 'query_handler_unavailable'],
            'incompatible_query_protocol' => ['reason' => 'unsupported_protocol_version'],
            'missing_workflow' => ['reason' => 'instance_not_found'],
            'terminal_signal' => [
                'reason' => 'run_not_active',
                'rejection_reason' => 'run_not_active',
            ],
            'history_and_commands_before_first_successful_query' => [
                'history_event_count' => 6,
                'workflow_command_count' => 1,
            ],
            'history_and_commands_after_successful_queries' => [
                'history_event_count' => 6,
                'workflow_command_count' => 1,
            ],
            'history_and_commands_after_failure_queries' => [
                'history_event_count' => 6,
                'workflow_command_count' => 1,
            ],
            'successful_queries_appended_no_history' => true,
            'successful_queries_emitted_no_workflow_commands' => true,
            'failed_queries_appended_no_history' => true,
            'failed_queries_emitted_no_workflow_commands' => true,
            'answer_before_failures' => 8,
            'answer_after_failures' => 8,
            'failed_query_did_not_change_later_answer' => true,
        ];
        $scenarioResults['rust_replayed_instance_state_query_after_cold_restart']['observed_outputs'] = [
            ...$rustIdentity,
            'worker_runtime' => 'sdk-rust',
            'query_state_model' => 'replayed_workflow_instance_state',
            'initial_worker_process_id' => '101',
            'cold_restart' => [
                'fresh_worker_process_id' => '202',
                'durable_history_restored' => true,
            ],
            'running_query_results' => ['sdk_rust' => 5, 'workflow_php_sdk' => 5, 'sdk_python' => 5],
            'restored_query_results' => ['sdk_rust' => 5, 'workflow_php_sdk' => 5, 'sdk_python' => 5],
            'completed_query_results' => ['sdk_rust' => 5, 'workflow_php_sdk' => 5, 'sdk_python' => 5],
            'immutability_checkpoints' => [
                'running' => [
                    'before_first_successful_query' => ['history_event_count' => 6, 'workflow_command_count' => 1],
                    'answer_before_failed_query' => 5,
                    'failed_query' => ['reason' => 'rejected_unknown_query'],
                    'answer_after_failed_query' => 5,
                    'after_successful_and_failed_queries' => ['history_event_count' => 6, 'workflow_command_count' => 1],
                ],
                'cold_restarted' => [
                    'before_first_successful_query' => ['history_event_count' => 6, 'workflow_command_count' => 1],
                    'answer_before_failed_query' => 5,
                    'failed_query' => ['reason' => 'rejected_unknown_query'],
                    'answer_after_failed_query' => 5,
                    'after_successful_and_failed_queries' => ['history_event_count' => 6, 'workflow_command_count' => 1],
                ],
                'completed' => [
                    'before_first_successful_query' => ['history_event_count' => 9, 'workflow_command_count' => 2],
                    'answer_before_failed_query' => 5,
                    'failed_query' => ['reason' => 'rejected_unknown_query'],
                    'answer_after_failed_query' => 5,
                    'after_successful_and_failed_queries' => ['history_event_count' => 9, 'workflow_command_count' => 2],
                ],
            ],
            'successful_and_failed_queries_appended_no_history' => true,
            'successful_and_failed_queries_emitted_no_workflow_commands' => true,
            'failed_query_did_not_change_later_answer' => true,
        ];
        $scenarioResults['ordered_signal_delivery']['observed_outputs'] = [
            'workflow_id' => 'wf-ordered',
            'run_id' => 'run-ordered',
            'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'accepted_signal_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'accepted_signal_total' => 55,
            'queried_total' => 55,
            'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'final_run_status' => 'waiting',
            'ordered_query_responder' => $this->orderedQueryResponderEvidence(),
        ];
        $scenarioResults['dedup_contract_observation']['observed_outputs'] = [
            'client_side_key_support' => false,
            'documented_contract' => 'SignalQueryRuntimeContract dedup_contract_observation: '
                .'no public signal idempotency key is documented; repeated accepted control-plane '
                .'signal calls are delivered independently',
            'documented_contract_source' => 'SignalQueryRuntimeContract manifest scenario dedup_contract_observation',
            'handler_observation_count' => 2,
            'duplicate_signal_contract' => 'public control-plane repeated signal behavior',
            'duplicate_signal_payload_shape' => 'positional input array',
        ];
        $scenarioResults['signal_during_replay']['observed_outputs'] = [
            'signal_api_sample' => [
                'method' => 'POST',
                'path' => '/api/workflows/wf-replay-timing/signal/increment',
                'body' => ['input' => [3]],
            ],
            'signal_status_code' => 202,
            'worker_restart_at' => '2026-05-20T00:00:00Z',
            'signal_sent_at' => '2026-05-20T00:00:01Z',
            'replay_completed_at' => '2026-05-20T00:00:02Z',
            'signal_applied_at' => '2026-05-20T00:00:03Z',
        ];
        $scenarioResults['query_during_replay']['observed_outputs'] = [
            'query_api_sample' => [
                'method' => 'POST',
                'path' => '/api/workflows/wf-replay-timing/query/current',
                'body' => ['input' => []],
            ],
            'query_status_code' => 200,
            'worker_restart_at' => '2026-05-20T00:00:00Z',
            'query_sent_at' => '2026-05-20T00:00:01Z',
            'query_poll_started_at' => '2026-05-20T00:00:01.500000Z',
            'replay_completed_at' => '2026-05-20T00:00:02Z',
            'query_handler_invoked_at' => '2026-05-20T00:00:03Z',
            'query_completed_at' => '2026-05-20T00:00:04Z',
            'query_answer' => 8,
            'expected_answer' => 8,
        ];
        $scenarioResults['completed_run_signal_and_query']['observed_outputs'] = [
            'completed_run_id' => 'run-completed-1',
            'completed_at' => '2026-05-20T00:01:00Z',
            'terminal_status' => 'completed',
            'signal_api_sample' => [
                'method' => 'POST',
                'path' => '/api/workflows/wf-completed/signal/increment',
                'body' => ['input' => [1]],
            ],
            'signal_error' => [
                'status_code' => 409,
                'reason' => 'run_not_active',
                'rejection_reason' => 'run_not_active',
            ],
            'query_api_sample' => [
                'method' => 'POST',
                'path' => '/api/workflows/wf-completed/query/current',
                'body' => ['input' => []],
            ],
            'query_result_or_error' => [
                'status_code' => 200,
                'outcome' => 'completed_query_replayed_final_state',
                'current' => 8,
            ],
            'public_query_surfaces' => ['cli', 'sdk-python', 'workflow-php-sdk'],
            'terminal_state_before_operations' => [
                'status_code' => 200,
                'workflow_id' => 'wf-completed',
                'run_id' => 'run-completed-1',
                'status' => 'completed',
                'result' => ['current' => 8],
                'history_event_count' => 2,
                'history_event_types' => ['WorkflowStarted', 'WorkflowCompleted'],
            ],
            'terminal_state_after_operations' => [
                'status_code' => 200,
                'workflow_id' => 'wf-completed',
                'run_id' => 'run-completed-1',
                'status' => 'completed',
                'result' => ['current' => 8],
                'history_event_count' => 2,
                'history_event_types' => ['WorkflowStarted', 'WorkflowCompleted'],
            ],
            'terminal_result_changed_after_operations' => false,
            'terminal_history_changed_after_operations' => false,
            'run_status_after_operations' => 'completed',
        ];
        $scenarioResults['unknown_signal_and_query_errors']['observed_outputs'] = [
            'workflow_id' => 'wf-unknown',
            'run_id' => 'run-unknown',
            'worker_id' => 'unknown-worker',
            'task_queue' => 'unknown-queue',
            'unknown_signal' => ['status_code' => 404, 'reason' => 'unknown_signal'],
            'missing_workflow_signal' => ['status_code' => 404, 'reason' => 'instance_not_found'],
            'missing_workflow_query' => ['status_code' => 404, 'reason' => 'instance_not_found'],
            'query_not_found' => ['status_code' => 404, 'reason' => 'query_not_found'],
            'rejected_unknown_query' => ['status_code' => 404, 'reason' => 'rejected_unknown_query'],
            'cli_unknown_signal_sample' => [
                'command' => 'dw workflow:signal wf-unknown missing --output=json',
                'exit_code' => 2,
                'status_code' => 404,
                'reason' => 'unknown_signal',
            ],
            'cli_unknown_query_sample' => [
                'command' => 'dw workflow:query wf-unknown missing --output=json',
                'exit_code' => 2,
                'status_code' => 404,
                'reason' => 'query_not_found',
            ],
            'cli_missing_workflow_signal_sample' => [
                'command' => 'dw workflow:signal wf-missing increment --output=json',
                'exit_code' => 2,
                'status_code' => 404,
                'reason' => 'instance_not_found',
            ],
            'cli_missing_workflow_query_sample' => [
                'command' => 'dw workflow:query wf-missing current --output=json',
                'exit_code' => 2,
                'status_code' => 404,
                'reason' => 'instance_not_found',
            ],
            'sdk_python_unknown_signal_sample' => [
                'client' => 'sdk-python',
                'exception' => 'SignalFailed',
                'status_code' => 404,
                'reason' => 'unknown_signal',
            ],
            'sdk_python_unknown_query_sample' => [
                'client' => 'sdk-python',
                'exception' => 'QueryFailed',
                'status_code' => 404,
                'reason' => 'query_not_found',
            ],
            'sdk_python_missing_workflow_signal_sample' => [
                'client' => 'sdk-python',
                'exception' => 'WorkflowNotFound',
                'reason' => 'instance_not_found',
            ],
            'sdk_python_missing_workflow_query_sample' => [
                'client' => 'sdk-python',
                'exception' => 'WorkflowNotFound',
                'reason' => 'instance_not_found',
            ],
            'known_query_after_unknown_errors' => [
                'status_code' => 200,
                'body' => ['result' => 8],
            ],
            'known_query_after_unknown_expected' => 8,
            'known_query_after_unknown_result' => 8,
            'post_error_query_responder' => $this->postErrorQueryResponderEvidence(),
            'history_and_commands_before_rejected_requests' => [
                'history_event_count' => 2,
                'workflow_command_count' => 1,
            ],
            'history_and_commands_after_recovery_query' => [
                'history_event_count' => 2,
                'workflow_command_count' => 1,
            ],
            'history_and_commands_after_all_requests' => [
                'history_event_count' => 2,
                'workflow_command_count' => 1,
            ],
            'rejected_requests_and_recovery_appended_no_history' => true,
            'rejected_requests_and_recovery_emitted_no_workflow_commands' => true,
        ];
        $scenarioResults['malformed_signal_and_query_payloads']['observed_outputs'] = [
            'invalid_signal_arguments' => [
                'status_code' => 422,
                'reason' => 'invalid_signal_arguments',
            ],
            'invalid_query_arguments' => [
                'status_code' => 422,
                'reason' => 'invalid_query_arguments',
            ],
            'invalid_signal_arguments_context' => [
                'workflow_id' => 'wf-invalid-signal-payload',
                'signal_name' => 'advance',
                'field' => 'input.0',
            ],
            'invalid_query_arguments_context' => [
                'workflow_id' => 'wf-invalid-query-payload',
                'query_name' => 'current',
                'field' => 'input.0',
            ],
            'signal_handler_invocation_count_after_invalid_payload' => 0,
            'query_state_mutation_count_after_invalid_payload' => 0,
            'post_error_valid_query_result' => 8,
            'cli_invalid_signal_arguments_sample' => [
                'command' => 'dw workflow:signal wf-invalid-signal-payload increment --input=["bad"] --output=json',
                'exit_code' => 2,
                'status_code' => 422,
                'reason' => 'invalid_signal_arguments',
            ],
            'cli_invalid_query_arguments_sample' => [
                'command' => 'dw workflow:query wf-invalid-query-payload current --input=["bad"] --output=json',
                'exit_code' => 2,
                'status_code' => 422,
                'reason' => 'invalid_query_arguments',
            ],
            'sdk_python_invalid_signal_arguments_sample' => [
                'client' => 'sdk-python',
                'exception' => 'SignalFailed',
                'status_code' => 422,
                'reason' => 'invalid_signal_arguments',
            ],
            'sdk_python_invalid_query_arguments_sample' => [
                'client' => 'sdk-python',
                'exception' => 'QueryFailed',
                'status_code' => 422,
                'reason' => 'invalid_query_arguments',
            ],
        ];
        $scenarioResults['waterline_operator_visibility']['observed_outputs'] = [
            'artifact_versions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.58',
                'sdk-rust' => '0.1.2',
                'workflow-php' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'artifact_sources' => [
                'server' => 'docker_image',
                'cli' => 'official_install_script',
                'sdk-python' => 'pypi_package',
                'sdk-rust' => 'published_crates_io_package',
                'workflow-php' => 'packagist_package',
                'waterline' => 'packagist_package',
            ],
            'captured_at' => '2026-05-20T00:04:00Z',
            'observer_state' => [
                'selected_run' => ['run_id' => 'run-1'],
                'signals' => ['count' => 1],
                'queries' => ['targets' => ['current']],
                'paths' => [
                    'selected_run_query_template' => '/waterline/api/instances/wf-1/runs/run-1/queries/{query}',
                ],
            ],
            'api_paths' => [
                'selected_run_detail' => '/waterline/api/instances/wf-1/runs/run-1',
                'selected_run_query_action' => '/waterline/api/instances/wf-1/runs/run-1/queries/current',
            ],
            'dashboard_json_envelopes' => [
                'selected_run_detail' => [
                    'method' => 'GET',
                    'path' => '/waterline/api/instances/wf-1/runs/run-1',
                    'status' => 200,
                ],
            ],
            'api_captures' => [
                'selected_run_detail' => [
                    'method' => 'GET',
                    'path' => '/waterline/api/instances/wf-1/runs/run-1',
                    'status' => 200,
                ],
                'selected_run_query_action' => [
                    'method' => 'POST',
                    'path' => '/waterline/api/instances/wf-1/runs/run-1/queries/current',
                    'status' => 200,
                    'request_json' => ['arguments' => []],
                ],
            ],
            'comparison' => [
                'run_status_matches_public_clients' => true,
                'counter_state_matches_public_clients' => true,
                'server_observation' => ['run_id' => 'run-1', 'counter' => 8],
                'cli_observation' => ['run_id' => 'run-1', 'counter' => 8],
                'sdk_observation' => ['run_id' => 'run-1', 'counter' => 8],
            ],
        ];

        return [
            'schema' => SignalQueryRuntimeContract::RESULT_SCHEMA,
            'started_at' => '2026-05-20T00:00:00Z',
            'finished_at' => '2026-05-20T00:05:00Z',
            'outcome' => 'pass',
            'artifactVersions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.58',
                'sdk-rust' => '0.1.2',
                'workflow' => '2.0.0-alpha.161',
                'workflow-php' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python', 'sdk-rust'],
                'same_language_cells' => [
                    [
                        'scenario' => 'python_worker_cli_and_sdk_baseline',
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                    ],
                    [
                        'scenario' => 'php_worker_cli_and_sdk_baseline',
                        'worker' => 'workflow-php',
                        'clients' => ['cli', 'workflow-php-sdk'],
                    ],
                    [
                        'scenario' => 'rust_worker_rust_php_python_clients',
                        'worker' => 'sdk-rust',
                        'clients' => ['sdk-rust', 'workflow-php-sdk', 'sdk-python'],
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'scenario' => 'python_worker_php_facing_and_cli_clients',
                        'worker' => 'sdk-python',
                        'clients' => ['workflow-php-sdk', 'cli'],
                    ],
                    [
                        'scenario' => 'php_worker_python_and_cli_clients',
                        'worker' => 'workflow-php',
                        'clients' => ['sdk-python', 'cli'],
                    ],
                    [
                        'scenario' => 'python_worker_rust_client',
                        'worker' => 'sdk-python',
                        'clients' => ['sdk-rust'],
                    ],
                    [
                        'scenario' => 'php_worker_rust_client',
                        'worker' => 'workflow-php',
                        'clients' => ['sdk-rust'],
                    ],
                ],
            ],
            'replay_timing' => [
                'signal_during_replay' => $scenarioResults['signal_during_replay']['observed_outputs'],
                'query_during_replay' => $scenarioResults['query_during_replay']['observed_outputs'],
            ],
            'terminal_run_behavior' => [
                'completed_run_signal_and_query' => $scenarioResults['completed_run_signal_and_query']['observed_outputs'],
            ],
            'adversarial_errors' => [
                'unknown_signal_and_query_errors' => $scenarioResults['unknown_signal_and_query_errors']['observed_outputs'],
                'malformed_signal_and_query_payloads' => $scenarioResults['malformed_signal_and_query_payloads']['observed_outputs'],
            ],
            'waterline_observer_comparison' => [
                'waterline_operator_visibility' => $scenarioResults['waterline_operator_visibility']['observed_outputs'],
            ],
            'findings' => [],
            'finding_links' => [],
            'scenario_results' => $scenarioResults,
        ];
    }
}
