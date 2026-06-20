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
        $this->assertSame(19, SignalQueryRuntimeContract::VERSION);
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

        foreach (['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'] as $artifact) {
            $this->assertArrayHasKey($artifact, $manifest['artifact_policy']['install_channels']);
        }

        $this->assertSame(
            ['server', 'cli', 'sdk-python'],
            $manifest['artifact_policy']['install_proof_artifacts'],
        );

        $this->assertSame(
            [
                'server' => 'published_docker_image',
                'cli' => 'published_cli_release',
                'workflow-php' => 'published_composer_package',
                'sdk-python' => 'published_pypi_package',
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

        $this->assertSame(['workflow-php', 'sdk-python'], $matrix['runtimes']);
        $this->assertContains('cli', $matrix['client_paths']);
        $this->assertContains('workflow-php-sdk', $matrix['client_paths']);
        $this->assertContains('sdk-python', $matrix['client_paths']);
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
            'ordered_signal_delivery',
            'dedup_contract_observation',
            'signal_during_replay',
            'query_during_replay',
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
        foreach (['query_api_sample', 'query_status_code', 'query_handler_invoked_at'] as $field) {
            $this->assertContains($field, $requirements['query_during_replay']['evidence']);
        }
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
            'signal_api_sample',
            'signal_error.status_code',
            'signal_error.reason',
            'signal_error.rejection_reason',
            'query_api_sample',
            'query_result_or_error.status_code',
            'query_result_or_error.outcome',
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
        $this->assertSame(18, SignalQueryRuntimeResultGate::VERSION);
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
                'waterline' => 'published_waterline_artifact',
            ],
            $hostRunner['evidence_shards']['published_artifact_install']['expected_artifact_sources'],
        );
        $this->assertSame(
            ['server', 'cli', 'sdk-python'],
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
                'unknown_signal',
                'missing_workflow_signal',
                'missing_workflow_query',
                'query_not_found',
                'rejected_unknown_query',
                'known_query_after_unknown_errors',
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
                'cli_signal_and_query',
                'sdk_python_signal_and_query',
                'immediate_repeat_query_consistency',
            ],
            $hostRunner['evidence_shards']['python_worker_cli_and_sdk_smoke']['current_evidence_fields'],
        );
        $this->assertSame(
            [
                'rapid_increment_inputs',
                'ten_signal_ordered_delivery_total',
                'history_signal_order',
            ],
            $hostRunner['evidence_shards']['ordered_signal_delivery']['current_evidence_fields'],
        );
        $this->assertSame(
            [
                'php_worker_query_task_routing',
                'cli_signal_and_query',
                'workflow_php_signal_and_query',
                'immediate_repeat_query_consistency',
            ],
            $hostRunner['evidence_shards']['php_worker_mirror']['required_evidence_fields'],
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
                'run_status_after_operations',
            ],
            $hostRunner['evidence_shards']['completed_run_handling']['required_evidence_fields'],
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
            'signal_query_cross_language_client_matrix_uncovered',
            'signal_query_replay_timing_uncovered',
            'signal_query_completed_run_handling_uncovered',
            'signal_query_unknown_handler_errors_uncovered',
            'signal_query_unknown_handler_errors_current_evidence_missing',
            'signal_query_adversarial_error_shapes_uncovered',
            'signal_query_waterline_observer_comparison_uncovered',
            '"runner_blocked": runner_blocked',
            'server_readiness_topology',
            'runner_blocker',
            'DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS',
            'DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE',
            'run_baseline_probe(result_dir)',
            'baseline_scenario_result(',
            'run_python_sdk_baseline(',
            'Worker(',
            'workflow_task_history_events(',
            'increment_signal_observations_from_task(',
            'optional public client sample failed',
            'unknown-handler baseline probe failed',
            'ordered delivery baseline probe failed',
            'dedup baseline probe failed',
            'known_query_after_unknown_result',
            '"signal_amounts"',
            '"not_claimed_as_pass"',
            'signals-queries-result.json',
            'signals-queries-findings.json',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
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
            '"status": install_status',
            '"status": python_sdk_status',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $this->assertStringNotContainsString('"published_server_endpoint"', $source);
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
            'cli_signal_and_query' => true,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
            'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'ten_signal_ordered_delivery_total' => 55,
            'history_signal_order' => [1, 2, 3, 5, 4, 6, 7, 8, 9, 10],
        ]);

        $this->assertSame('pass', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertContains(
            'signal_query_ordered_delivery_current_evidence_missing',
            array_column($result['findings'], 'type'),
        );
    }

    public function test_host_runner_marks_only_complete_smoke_fields_as_covered(): void
    {
        $result = $this->runSignalQueryHostRunner([
            'worker_runtime' => 'sdk-python',
            'python_worker_artifact_source' => 'published_pypi_package',
            'python_worker_sdk_version' => '0.4.84',
            'python_worker_query_task_routing' => true,
            'cli_signal_and_query' => true,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
            'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'ten_signal_ordered_delivery_total' => 55,
            'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        ]);

        $this->assertSame('not_covered', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('pass', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('pass', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['dedup_contract_observation']['status']);
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            $result['scenario_results']['ordered_signal_delivery']['observed_outputs']['history_signal_order'],
        );
        $this->assertContains('signal_query_published_artifact_install_uncovered', array_column($result['findings'], 'type'));
        $this->assertContains(
            'signal_query_dedup_contract_current_evidence_missing',
            array_column($result['findings'], 'type'),
        );
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
        $this->assertSame(
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
                'rapid_increment_inputs',
                'queried_total',
                'history_signal_order',
            ],
            'dedup_contract_observation' => [
                'client_side_key_support',
                'documented_contract',
                'handler_observation_count',
            ],
            'unknown_signal_and_query_errors' => [
                'unknown_signal',
                'missing_workflow_signal',
                'missing_workflow_query',
                'query_not_found',
                'rejected_unknown_query',
                'known_query_after_unknown_errors',
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
                'ordered_signal_delivery' => $ordered,
                'dedup_contract_observation' => $dedup,
                'unknown_signal_and_query_errors' => $unknown,
            ],
        ]);
        $orderedFindings = $this->findingsForScenario($result, 'ordered_signal_delivery');
        $dedupFindings = $this->findingsForScenario($result, 'dedup_contract_observation');
        $unknownFindings = $this->findingsForScenario($result, 'unknown_signal_and_query_errors');

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
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
        ];
        $installEvidence = [
            'local_product_source_checkouts_used' => false,
            'artifacts' => array_values(array_filter(
                $this->installEvidenceForVersions($versions, $sources)['artifacts'],
                static fn (array $artifact): bool => in_array(
                    $artifact['artifact'],
                    ['server', 'cli', 'sdk-python'],
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
            ['server', 'cli', 'sdk-python'],
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
        $this->assertContains('signal_query_php_worker_mirror_uncovered', array_column($result['findings'], 'type'));
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

        $this->assertSame('not_covered', $result['scenario_results']['php_worker_cli_and_sdk_baseline']['status']);
        $this->assertContains('signal_query_php_worker_mirror_uncovered', array_column($result['findings'], 'type'));
    }

    public function test_host_runner_rejects_imported_query_replay_evidence_before_consistent_state(): void
    {
        $evidence = $this->completeSignalQueryResultForCurrentHostRunner();
        $evidence['scenario_results']['query_during_replay']['observed_outputs']['query_handler_invoked_at'] =
            '2026-05-20T00:00:01Z';

        $result = $this->runSignalQueryHostRunner($evidence);

        $this->assertSame('not_covered', $result['scenario_results']['query_during_replay']['status']);
        $this->assertContains('signal_query_replay_timing_uncovered', array_column($result['findings'], 'type'));
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
                'DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.187',
                'DW_WATERLINE_VERSION=2.0.0-alpha.69',
                'DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE=0',
                'DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE=0',
                'DW_SIGNALS_QUERIES_SMOKE_EVIDENCE=' . escapeshellarg($smokePath),
                escapeshellarg($root . '/scripts/conformance/signals-queries-published-artifacts.sh'),
                '--result-dir',
                escapeshellarg($resultDir),
            ]);

            $output = [];
            $exitCode = 0;
            exec($command . ' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            $resultPath = $resultDir . '/signals-queries-result.json';
            $this->assertFileExists($resultPath);

            return json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
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
                'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.187',
                'DW_WATERLINE_VERSION' => '2.0.0-alpha.69',
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
            ['python3', '-c', $script],
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
        foreach (['server', 'cli', 'sdk-python', 'workflow-php', 'waterline'] as $artifact) {
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
            'workflow-php' => 'published_composer_package',
            'waterline' => 'published_waterline_artifact',
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

        $result['artifactVersions'] = $versions;
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

        return $result;
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
            'workflow' => '2.0.0-alpha.161',
            'workflow-php' => '2.0.0-alpha.161',
            'waterline' => '2.0.0-alpha.54',
        ];
        $artifactSources = [
            'server' => 'published_docker_image',
            'cli' => 'published_cli_release',
            'sdk-python' => 'published_pypi_package',
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
            'cli_signal_and_query' => true,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
        ];
        $scenarioResults['php_worker_cli_and_sdk_baseline']['observed_outputs'] = [
            'php_worker_query_task_routing' => true,
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
        $scenarioResults['ordered_signal_delivery']['observed_outputs'] = [
            'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'queried_total' => 55,
            'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        ];
        $scenarioResults['dedup_contract_observation']['observed_outputs'] = [
            'client_side_key_support' => false,
            'documented_contract' => 'no signal deduplication key is documented',
            'handler_observation_count' => 2,
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
            'replay_completed_at' => '2026-05-20T00:00:02Z',
            'query_handler_invoked_at' => '2026-05-20T00:00:03Z',
            'query_completed_at' => '2026-05-20T00:00:04Z',
            'query_answer' => 8,
            'expected_answer' => 8,
        ];
        $scenarioResults['completed_run_signal_and_query']['observed_outputs'] = [
            'completed_run_id' => 'run-completed-1',
            'completed_at' => '2026-05-20T00:01:00Z',
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
            'run_status_after_operations' => 'completed',
        ];
        $scenarioResults['unknown_signal_and_query_errors']['observed_outputs'] = [
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
                'workflow-php' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'artifact_sources' => [
                'server' => 'docker_image',
                'cli' => 'official_install_script',
                'sdk-python' => 'pypi_package',
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
                'workflow' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php', 'sdk-python'],
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
