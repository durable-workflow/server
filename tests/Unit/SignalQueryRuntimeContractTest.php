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
        $this->assertSame(11, SignalQueryRuntimeContract::VERSION);
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
        $this->assertContains(
            'invalid_signal_arguments_context',
            $requirements['malformed_signal_and_query_payloads']['evidence'],
        );
        $this->assertContains(
            'invalid_query_arguments_context',
            $requirements['malformed_signal_and_query_payloads']['evidence'],
        );
        $this->assertContains(
            'post_error_valid_query_result',
            $requirements['malformed_signal_and_query_payloads']['evidence'],
        );
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
        $this->assertSame(12, SignalQueryRuntimeResultGate::VERSION);
        $this->assertSame(SignalQueryRuntimeResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            SignalQueryRuntimeContract::RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertSame(
            'signal_query_runtime_contract.artifact_policy.install_channels',
            $resultGate['required_artifact_versions_source'],
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
        $this->assertSame(['bash', 'python3'], $hostRunner['required_host_commands']);

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
            'signal_query_dedup_contract_uncovered',
            'signal_query_php_worker_mirror_uncovered',
            'signal_query_cross_language_client_matrix_uncovered',
            'signal_query_replay_timing_uncovered',
            'signal_query_completed_run_handling_uncovered',
            'signal_query_unknown_handler_errors_uncovered',
            'signal_query_adversarial_error_shapes_uncovered',
            'signal_query_waterline_observer_comparison_uncovered',
            'runner_blocked": False',
            'signals-queries-result.json',
            'signals-queries-findings.json',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
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

        $this->assertSame('pass', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertContains('signal_query_python_smoke_uncovered', array_column($result['findings'], 'type'));
        $this->assertContains('signal_query_ordered_delivery_uncovered', array_column($result['findings'], 'type'));
    }

    public function test_host_runner_requires_exact_history_signal_order_before_marking_ordered_delivery_pass(): void
    {
        $result = $this->runSignalQueryHostRunner([
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
        $this->assertContains('signal_query_ordered_delivery_uncovered', array_column($result['findings'], 'type'));
    }

    public function test_host_runner_marks_only_complete_smoke_fields_as_covered(): void
    {
        $result = $this->runSignalQueryHostRunner([
            'python_worker_query_task_routing' => true,
            'cli_signal_and_query' => true,
            'sdk_python_signal_and_query' => true,
            'immediate_repeat_query_consistency' => true,
            'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'ten_signal_ordered_delivery_total' => 55,
            'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        ]);

        $this->assertSame('pass', $result['scenario_results']['published_artifact_install_only']['status']);
        $this->assertSame('pass', $result['scenario_results']['python_worker_cli_and_sdk_baseline']['status']);
        $this->assertSame('pass', $result['scenario_results']['ordered_signal_delivery']['status']);
        $this->assertSame('not_covered', $result['scenario_results']['dedup_contract_observation']['status']);
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            $result['scenario_results']['ordered_signal_delivery']['observed_outputs']['history_signal_order'],
        );
        $this->assertContains('signal_query_dedup_contract_uncovered', array_column($result['findings'], 'type'));
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

        $scenarioResults['published_artifact_install_only']['observed_outputs'] = [
            'published_artifact_versions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.58',
                'workflow' => '2.0.0-alpha.161',
                'workflow-php' => '2.0.0-alpha.161',
                'waterline' => '2.0.0-alpha.54',
            ],
            'artifact_sources' => [
                'server' => 'published_docker_image',
                'cli' => 'published_cli_release',
                'sdk-python' => 'published_pypi_package',
                'workflow-php' => 'published_composer_package',
                'waterline' => 'published_waterline_artifact',
            ],
        ];
        $scenarioResults['python_worker_cli_and_sdk_baseline']['observed_outputs'] = [
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
            'unknown_signal' => ['reason' => 'unknown_signal'],
            'missing_workflow_signal' => ['reason' => 'instance_not_found'],
            'missing_workflow_query' => ['reason' => 'instance_not_found'],
            'query_not_found' => ['reason' => 'query_not_found'],
            'rejected_unknown_query' => ['reason' => 'rejected_unknown_query'],
        ];
        $scenarioResults['malformed_signal_and_query_payloads']['observed_outputs'] = [
            'invalid_signal_arguments' => ['reason' => 'invalid_signal_arguments'],
            'invalid_query_arguments' => ['reason' => 'invalid_query_arguments'],
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
            'post_error_valid_query_result' => 8,
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
