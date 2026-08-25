<?php

namespace Tests\Unit;

use App\Support\ReplayConformanceResultGate;
use App\Support\ReplayVerificationContract;
use PHPUnit\Framework\TestCase;

class ReplayVerificationContractTest extends TestCase
{
    public function test_manifest_publishes_canonical_schema_and_offline_cli_surface(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('durable-workflow.v2.replay-verification.contract', $manifest['schema']);
        $this->assertSame(4, $manifest['version']);

        $this->assertSame('durable-workflow.v2.history-export', $manifest['bundle']['schema']);
        $this->assertSame(2, $manifest['bundle']['schema_version']);

        $this->assertSame('workflow:v2:replay-verify', $manifest['offline_cli']['command']);
        $this->assertSame(0, $manifest['offline_cli']['exit_codes']['ok']);
        $this->assertSame(1, $manifest['offline_cli']['exit_codes']['drifted']);
        $this->assertSame(1, $manifest['offline_cli']['exit_codes']['failed']);
    }

    public function test_manifest_publishes_integrity_and_report_schemas(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('json-recursive-ksort-v1', $manifest['integrity']['canonicalization']);
        $this->assertSame('sha256', $manifest['integrity']['checksum_algorithm']);
        $this->assertContains('hmac-sha256', $manifest['integrity']['signature_algorithms']);

        $this->assertSame(
            'durable-workflow.v2.history-bundle-verification',
            $manifest['integrity_report']['schema'],
        );
        $this->assertSame(1, $manifest['integrity_report']['schema_version']);

        foreach (['ok', 'warning', 'failed'] as $status) {
            $this->assertContains($status, $manifest['integrity_report']['statuses']);
        }

        foreach (['rule', 'severity', 'message', 'path', 'context'] as $field) {
            $this->assertContains($field, $manifest['integrity_report']['finding_fields']);
        }

        foreach ([
            'integrity.checksum_mismatch',
            'integrity.signature_mismatch',
            'history_events.sequence_not_monotonic',
            'payload_manifest.writer_schema_fingerprint_mismatch',
        ] as $rule) {
            $this->assertContains($rule, $manifest['integrity_report']['rules']);
        }
    }

    public function test_manifest_publishes_replay_diff_statuses_and_reasons(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('durable-workflow.v2.replay-diff', $manifest['replay_diff']['schema']);
        $this->assertSame(1, $manifest['replay_diff']['schema_version']);

        foreach (['replayed', 'drifted', 'failed'] as $status) {
            $this->assertContains($status, $manifest['replay_diff']['statuses']);
        }

        foreach (['none', 'shape_mismatch', 'replay_error', 'bundle_invalid'] as $reason) {
            $this->assertContains($reason, $manifest['replay_diff']['reasons']);
        }

        foreach (['workflow_sequence', 'expected_shape', 'recorded_event_types'] as $field) {
            $this->assertContains($field, $manifest['replay_diff']['shape_mismatch_fields']);
        }
    }

    public function test_manifest_publishes_composite_verification_report_schema(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame(
            'durable-workflow.v2.replay-verification.report',
            $manifest['verification_report']['schema'],
        );
        $this->assertSame(1, $manifest['verification_report']['schema_version']);

        foreach (['verdict', 'promotion_decision', 'evidence', 'bundle_path', 'integrity', 'replay_diff'] as $field) {
            $this->assertContains($field, $manifest['verification_report']['fields']);
        }

        foreach (['integrity_checked', 'replay_checked', 'replay_skipped'] as $field) {
            $this->assertContains($field, $manifest['verification_report']['evidence_fields']);
        }

        foreach (['ok', 'warning', 'drifted', 'failed'] as $verdict) {
            $this->assertContains($verdict, $manifest['verification_report']['verdicts']);
        }
    }

    public function test_verdicts_map_to_promotion_decisions(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('safe_to_promote', $manifest['verdicts']['ok']['promotion_decision']);
        $this->assertSame('block_until_compatible', $manifest['verdicts']['drifted']['promotion_decision']);
        $this->assertSame('block_and_investigate', $manifest['verdicts']['failed']['promotion_decision']);
    }

    public function test_manifest_publishes_batch_simulation_cli_surface(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertArrayHasKey('batch_cli', $manifest);
        $this->assertSame('workflow:v2:replay-simulate', $manifest['batch_cli']['command']);
        $this->assertArrayHasKey('--json', $manifest['batch_cli']['inputs']);
        $this->assertSame(0, $manifest['batch_cli']['exit_codes']['ok']);
        $this->assertSame(1, $manifest['batch_cli']['exit_codes']['drifted']);
        $this->assertSame(1, $manifest['batch_cli']['exit_codes']['failed']);
        $this->assertSame(
            'durable-workflow.v2.replay-simulation.report',
            $manifest['batch_cli']['report_schema'],
        );
        $this->assertSame(1, $manifest['batch_cli']['report_schema_version']);
    }

    public function test_manifest_publishes_simulation_report_and_aggregation_rule(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertArrayHasKey('simulation_report', $manifest);
        $this->assertSame(
            'durable-workflow.v2.replay-simulation.report',
            $manifest['simulation_report']['schema'],
        );
        $this->assertSame(1, $manifest['simulation_report']['schema_version']);
        $this->assertSame('strictest_verdict_wins', $manifest['simulation_report']['aggregation_rule']);

        foreach (['verdict', 'promotion_decision', 'evidence', 'summary', 'bundles', 'missing_bundles'] as $field) {
            $this->assertContains($field, $manifest['simulation_report']['fields']);
        }

        foreach (['bundle_count', 'missing_bundle_count', 'integrity_checked_count'] as $field) {
            $this->assertContains($field, $manifest['simulation_report']['evidence_fields']);
        }
    }

    public function test_manifest_publishes_promotion_gate_mapping(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertArrayHasKey('promotion_gate', $manifest);
        $this->assertArrayHasKey('evidence_policy', $manifest['promotion_gate']);

        foreach (['pass', 'review', 'block'] as $status) {
            $this->assertContains($status, $manifest['promotion_gate']['gate_statuses']);
        }

        $this->assertSame('pass', $manifest['promotion_gate']['verdict_to_gate_status']['ok']);
        $this->assertSame('review', $manifest['promotion_gate']['verdict_to_gate_status']['warning']);
        $this->assertSame('block', $manifest['promotion_gate']['verdict_to_gate_status']['drifted']);
        $this->assertSame('block', $manifest['promotion_gate']['verdict_to_gate_status']['failed']);
    }

    public function test_golden_history_pins_required_families_across_runtimes(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('durable-workflow.golden-history.v1', $manifest['golden_history']['fixture_schema']);

        foreach ([
            'activity',
            'saga-compensation',
            'signal-update',
            'version-marker',
            'wait-condition',
        ] as $family) {
            $this->assertContains($family, $manifest['golden_history']['required_families']);
        }

        $this->assertContains('workflow-php', $manifest['golden_history']['official_runtimes']);
        $this->assertContains('sdk-python', $manifest['golden_history']['official_runtimes']);
    }

    public function test_replay_conformance_requires_published_artifacts_and_both_runtimes(): void
    {
        $manifest = ReplayVerificationContract::manifest();
        $conformance = $manifest['replay_conformance'];

        $this->assertSame(ReplayVerificationContract::REPLAY_CONFORMANCE_RESULT_SCHEMA, $conformance['result_schema']);
        $this->assertSame(ReplayVerificationContract::REPLAY_CONFORMANCE_RESULT_VERSION, $conformance['result_version']);
        $this->assertSame('history_replay_bundles', $conformance['fixture_category']);
        $this->assertSame(
            'durable-workflow.v2.platform-conformance.runtime-scenarios',
            $conformance['scenario_manifest']['schema'],
        );
        $this->assertSame('history_replay_bundles', $conformance['scenario_manifest']['category']);
        $this->assertStringContainsString(
            'replay-runtime-scenarios.json',
            $conformance['scenario_manifest']['public_path'],
        );

        $this->assertSame(
            'latest_published_artifacts_at_run_time',
            $conformance['artifact_policy']['version_source'],
        );
        $this->assertSame(
            'concrete_published_versions_pinned_at_run_time',
            $conformance['artifact_policy']['version_requirement'],
        );
        $this->assertTrue($conformance['artifact_policy']['placeholder_versions_rejected']);
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder', '<latest>'] as $example) {
            $this->assertContains($example, $conformance['artifact_policy']['placeholder_version_examples']);
        }
        $this->assertArrayHasKey('server', $conformance['artifact_policy']['install_channels']);
        $this->assertArrayHasKey('sdk-php', $conformance['artifact_policy']['install_channels']);
        $this->assertArrayHasKey('workflow-php', $conformance['artifact_policy']['install_channels']);
        $this->assertArrayHasKey('sdk-python', $conformance['artifact_policy']['install_channels']);
        $this->assertNotContains('waterline', array_keys($conformance['artifact_policy']['install_channels']));

        foreach (['server', 'cli', 'sdk-php', 'workflow-php', 'sdk-python', 'sdk-rust', 'waterline'] as $artifact) {
            $this->assertContains($artifact, $conformance['artifact_policy']['required_artifact_versions']);
        }
        $this->assertContains(
            'local_product_source_checkout',
            $conformance['artifact_policy']['forbidden_sources'],
        );

        $this->assertSame(['sdk-php', 'sdk-python', 'sdk-rust'], $conformance['required_runtimes']);

        foreach ([
            'artifact_versions',
            'started_at',
            'finished_at',
            'outcome',
            'scenario_results',
            'findings',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $conformance['artifact_policy']['required_run_record_fields']);
        }
    }

    public function test_replay_conformance_matrix_names_full_replay_surface(): void
    {
        $manifest = ReplayVerificationContract::manifest();
        $conformance = $manifest['replay_conformance'];
        $matrix = $conformance['required_matrix'];

        $this->assertSame('each_required_runtime', $matrix['runtime_scope']);

        foreach ([
            'published_artifact_install_only',
            'python_completed_history_activity_replay',
            'python_completed_history_signal_update_replay',
            'python_completed_history_wait_condition_replay',
            'python_completed_history_version_marker_replay',
            'python_completed_history_saga_compensation_replay',
            'php_completed_history_activity_replay',
            'php_completed_history_signal_update_replay',
            'php_completed_history_wait_condition_replay',
            'php_completed_history_version_marker_replay',
            'php_completed_history_saga_compensation_replay',
            'python_worker_restart_completed_query',
            'python_worker_restart_activity_state',
            'python_worker_restart_signal_update_state',
            'python_worker_restart_wait_condition_state',
            'python_worker_restart_version_marker_state',
            'python_worker_restart_saga_compensation_state',
            'php_worker_restart_completed_query',
            'php_worker_restart_activity_state',
            'php_worker_restart_signal_update_state',
            'php_worker_restart_wait_condition_state',
            'php_worker_restart_version_marker_state',
            'php_worker_restart_saga_compensation_state',
            'python_code_divergence_refusal',
            'php_code_divergence_refusal',
            'server_history_mutation_refusal',
            'malformed_history_refusal',
            'python_in_flight_signal_restart_timing',
            'php_in_flight_signal_restart_timing',
            'rust_side_effect_replay_after_worker_restart',
            'rust_version_marker_replay_after_code_upgrade',
        ] as $scenario) {
            $this->assertContains($scenario, $conformance['required_scenarios']);
        }

        foreach ([
            'activity',
            'signal-update',
            'wait-condition',
            'version-marker',
            'saga-compensation',
        ] as $family) {
            $this->assertContains($family, $matrix['completed_history_families']);
        }

        foreach ([
            'completed_history_query_after_worker_restart',
            'activity_state_query_after_worker_restart',
            'signal_update_state_query_after_worker_restart',
            'wait_condition_state_after_worker_restart',
            'version_marker_state_after_worker_restart',
            'saga_compensation_state_after_worker_restart',
        ] as $scenario) {
            $this->assertContains($scenario, $matrix['restart_scenarios']);
        }

        foreach ([
            'code_divergence_refusal',
            'server_history_mutation_refusal',
            'malformed_history_refusal',
            'in_flight_signal_restart_timing',
        ] as $scenario) {
            $this->assertContains($scenario, $matrix['adversarial_scenarios']);
        }
    }

    public function test_replay_conformance_keeps_uncovered_required_surface_non_passing(): void
    {
        $manifest = ReplayVerificationContract::manifest();
        $conformance = $manifest['replay_conformance'];

        $this->assertSame(
            ['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked'],
            $conformance['scenario_statuses'],
        );
        $this->assertContains('not_covered', $conformance['scenario_statuses']);
        $this->assertSame(
            'non_passing',
            $conformance['coverage_gate']['uncovered_required_scenario_outcome'],
        );
        $this->assertSame(
            'non_passing',
            $conformance['coverage_gate']['smoke_subset_outcome'],
        );
        $this->assertContains(
            'all_required_matrix_cells_pass',
            $conformance['coverage_gate']['passing_outcome_requires'],
        );
        $this->assertContains(
            'all_refusals_are_actionable',
            $conformance['coverage_gate']['passing_outcome_requires'],
        );

        foreach ([
            'nondeterminism',
            'silent_history_mutation_acceptance',
            'unclear_refusal_message',
            'runtime_asymmetry',
            'unsupported_public_surface',
        ] as $findingType) {
            $this->assertArrayHasKey($findingType, $conformance['finding_policy']);
        }
    }

    public function test_replay_conformance_refusals_require_actionable_diagnostics(): void
    {
        $manifest = ReplayVerificationContract::manifest();
        $diagnostics = $manifest['replay_conformance']['diagnostic_requirements'];

        $this->assertSame(
            'non_determinism_error',
            $diagnostics['code_divergence_refusal']['required_outcome'],
        );
        foreach (['workflow_sequence', 'expected_shape', 'recorded_event_types', 'message'] as $field) {
            $this->assertContains($field, $diagnostics['code_divergence_refusal']['required_fields']);
        }

        $this->assertSame(
            'bundle_invalid_or_drifted',
            $diagnostics['server_history_mutation_refusal']['required_outcome'],
        );
        foreach (['integrity.rule', 'integrity.path', 'replay_diff.reason', 'message'] as $field) {
            $this->assertContains($field, $diagnostics['server_history_mutation_refusal']['required_fields']);
        }

        $this->assertSame(
            'same_next_decision_after_replay',
            $diagnostics['in_flight_signal_restart_timing']['required_outcome'],
        );
        foreach (['worker_restart_at', 'signal_sent_at', 'history_reloaded_at', 'replayed_next_decision'] as $field) {
            $this->assertContains($field, $diagnostics['in_flight_signal_restart_timing']['required_fields']);
        }
    }

    public function test_replay_conformance_publishes_host_runner_merge_contract(): void
    {
        $manifest = ReplayVerificationContract::manifest();
        $hostRunner = $manifest['replay_conformance']['host_runner_contract'];

        $this->assertSame('required_for_passing_replay_conformance', $hostRunner['status']);
        $this->assertSame('server', $hostRunner['runner_repository']);
        $this->assertSame(
            'scripts/conformance/replay-published-artifacts.sh',
            $hostRunner['runner_path'],
        );
        $this->assertSame(
            'scripts/conformance/replay-published-artifacts.sh --result-dir <result-dir>',
            $hostRunner['runner_command'],
        );
        $this->assertSame(
            ReplayVerificationContract::REPLAY_CONFORMANCE_RESULT_SCHEMA,
            $hostRunner['result_schema'],
        );
        $portableResult = $hostRunner['portable_result_contract'];
        $this->assertSame(4 * 1024 * 1024, $portableResult['runner_max_bytes']);
        $this->assertSame(3 * 1024 * 1024, $portableResult['projection_target_bytes']);
        $this->assertSame(4 * 1024 * 1024, $portableResult['host_consumer_max_bytes']);
        $this->assertSame('required_scenarios', $portableResult['required_scenario_status_source']);
        $this->assertSame(
            'executed_distribution_identities',
            $portableResult['exact_distribution_identity_field'],
        );
        $this->assertSame(
            [
                'oversized' => 'runner_infrastructure_failure',
                'malformed' => 'runner_infrastructure_failure',
                'incomplete' => 'runner_infrastructure_failure',
            ],
            $portableResult['native_evidence_failure_classification'],
        );
        $this->assertSame('fail_closed_before_projection', $portableResult['product_assertions']);
        $this->assertSame('omitted', $portableResult['sensitive_values']);
        foreach ([
            'schema',
            'started_at',
            'finished_at',
            'outcome',
            'runner_blocked',
            'artifact_versions',
            'executed_distribution_identities',
            'runtime_matrix',
            'scenario_results',
            'findings',
            'finding_links',
        ] as $field) {
            $this->assertContains($field, $portableResult['required_top_level_fields']);
        }
        foreach ([
            'pins.json',
            'run-metadata.json',
            'published-artifact-install.json',
            'python-replay-shard.json',
            'php-replay-shard.json',
            'rust-replay-shard.json',
            'replay-conformance-result.json',
            'replay-conformance-record.json',
        ] as $file) {
            $this->assertContains($file, $hostRunner['result_files']);
        }
        $this->assertTrue($hostRunner['must_execute_against_published_artifacts']);
        $this->assertTrue($hostRunner['must_record_runner_blocked_false_for_product_evidence']);
        $this->assertTrue($hostRunner['must_probe_runtime_published_surfaces']);
        $this->assertTrue($hostRunner['must_emit_result_for_every_required_scenario']);
        $this->assertSame(
            ['sdk-php-runtime-shard', 'sdk-python-runtime-shard', 'sdk-rust-runtime-shard'],
            $hostRunner['must_compose_runtime_shards'],
        );
        $this->assertSame('non_passing', $hostRunner['smoke_summary_only_outcome']);
        $this->assertSame('not_covered', $hostRunner['unexecuted_required_scenario_status']);

        $this->assertSame(
            'scripts/conformance/php-sdk-published-artifacts.sh',
            $hostRunner['runtime_shards']['sdk-php']['preferred_command'],
        );
        $this->assertSame(
            'unsupported',
            $hostRunner['runtime_shards']['sdk-php']['fallback_status_when_command_missing'],
        );
        $this->assertSame(
            'unsupported_public_surface',
            $hostRunner['runtime_shards']['sdk-php']['fallback_finding_type'],
        );
        $this->assertSame(
            'durable-workflow-replay-verify',
            $hostRunner['runtime_shards']['sdk-python']['completed_history_surface'],
        );
        $this->assertSame(
            'live_worker_query_replay',
            $hostRunner['runtime_shards']['sdk-python']['worker_restart_surface'],
        );
        $this->assertSame(
            'durable-workflow-replay-conformance',
            $hostRunner['runtime_shards']['sdk-rust']['completed_history_surface'],
        );

        foreach (['sdk-php-runtime-shard', 'sdk-python-runtime-shard', 'sdk-rust-runtime-shard', 'live-server-replay-smoke'] as $scope) {
            $this->assertContains($scope, $hostRunner['merge_policy']['input_scopes']);
        }
        foreach (['sdk-php', 'sdk-python', 'sdk-rust'] as $runtime) {
            $this->assertContains($runtime, $hostRunner['merge_policy']['requires_required_runtimes']);
        }
        foreach (['completed_history_replay', 'worker_restart_replay', 'adversarial_replay', 'in_flight_timing'] as $section) {
            $this->assertContains($section, $hostRunner['merge_policy']['requires_sections']);
        }
        foreach ([
            'published-artifact-install',
            'sdk-php-runtime-shard',
            'sdk-python-runtime-shard',
            'code-divergence-refusal',
            'server-history-mutation-refusal',
            'in-flight-signal-restart-timing',
        ] as $scope) {
            $this->assertContains($scope, $hostRunner['required_execution_scopes']);
        }
        $this->assertSame(
            'conformance_runner_coverage_gap',
            $hostRunner['routing_policy']['missing_required_scenario']['finding_type'],
        );
        $this->assertSame(
            'runner_gap',
            $hostRunner['routing_policy']['host_environment_failure']['finding_type'],
        );
    }

    public function test_replay_conformance_publishes_an_enforceable_result_gate(): void
    {
        $resultGate = ReplayVerificationContract::manifest()['replay_conformance']['result_gate'];

        $this->assertSame(ReplayConformanceResultGate::SCHEMA, $resultGate['schema']);
        $this->assertSame(ReplayConformanceResultGate::VERSION, $resultGate['version']);
        $this->assertSame(
            ReplayVerificationContract::REPLAY_CONFORMANCE_RESULT_SCHEMA,
            $resultGate['evaluates_result_schema'],
        );
        $this->assertContains('scenario_results', $resultGate['scenario_results_fields']);
        $this->assertContains('artifactVersions', $resultGate['artifact_versions_fields']);
        $this->assertContains('outcome', $resultGate['declared_outcome_fields']);
        $this->assertContains('verdict', $resultGate['declared_outcome_fields']);
        $this->assertSame(
            'replay_verification_contract.replay_conformance.coverage_gate.*_outcome plus pass/fail aliases',
            $resultGate['declared_outcomes_source'],
        );
        $this->assertSame(
            'replay_verification_contract.replay_conformance.artifact_policy.required_run_record_fields',
            $resultGate['required_run_record_fields_source'],
        );
        $this->assertSame(
            'replay_verification_contract.replay_conformance.artifact_policy.required_artifact_versions',
            $resultGate['required_artifact_versions_source'],
        );
        $this->assertTrue($resultGate['artifact_version_policy']['requires_recorded_and_pinned_versions']);
        $this->assertTrue($resultGate['artifact_version_policy']['rejects_placeholder_versions']);
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder', '<latest>'] as $example) {
            $this->assertContains($example, $resultGate['artifact_version_policy']['placeholder_version_examples']);
        }
        $this->assertContains('every_required_scenario_has_one_result', $resultGate['pass_requires']);
        $this->assertContains('required_php_python_and_rust_runtimes_are_reported', $resultGate['pass_requires']);
        $this->assertContains('adversarial_refusals_have_actionable_diagnostics', $resultGate['pass_requires']);
        $this->assertContains('adversarial_refusals_match_required_outcomes', $resultGate['pass_requires']);
        $this->assertContains('in_flight_signal_timing_matches_required_outcome', $resultGate['pass_requires']);
        $this->assertContains('each_non_pass_scenario_has_linked_findings', $resultGate['pass_requires']);
        $this->assertContains('run_record_metadata_is_complete', $resultGate['pass_requires']);
        $this->assertContains('overall_outcome_matches_gate_status', $resultGate['pass_requires']);
        $this->assertContains('published_artifact_versions_are_recorded_and_pinned', $resultGate['pass_requires']);
        $this->assertSame('non_passing', $resultGate['smoke_subset_outcome']);
    }

    public function test_replay_result_gate_rejects_python_smoke_subset_even_when_smoke_passes(): void
    {
        $scenarioResults = [];
        foreach ([
            'published_artifact_install_only',
            'python_completed_history_activity_replay',
            'python_completed_history_signal_update_replay',
            'python_completed_history_wait_condition_replay',
            'python_completed_history_version_marker_replay',
            'python_completed_history_saga_compensation_replay',
            'python_worker_restart_completed_query',
            'python_worker_restart_activity_state',
            'python_worker_restart_signal_update_state',
        ] as $scenario) {
            $scenarioResults[] = [
                'scenario_id' => $scenario,
                'status' => 'pass',
                'observed_outputs' => [
                    'recorded' => true,
                ],
            ];
        }

        $evaluation = ReplayConformanceResultGate::evaluate([
            'schema' => ReplayVerificationContract::REPLAY_CONFORMANCE_RESULT_SCHEMA,
            'artifactVersions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-python' => '0.4.59',
                'sdk-rust' => '0.1.13',
                'workflow' => '2.0.0-alpha.162',
                'waterline' => '2.0.0-alpha.54',
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-python'],
            ],
            'scenario_results' => $scenarioResults,
        ]);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertTrue($evaluation['smoke_subset_detected']);
        $this->assertContains('php_completed_history_activity_replay', $evaluation['missing_scenarios']);
        $this->assertContains(
            'smoke_subset_cannot_pass',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_replay_result_gate_requires_findings_for_non_pass_scenarios(): void
    {
        $result = $this->completeReplayConformanceResult();
        $result['scenario_results']['php_code_divergence_refusal']['status'] = 'fail';
        unset($result['scenario_results']['php_code_divergence_refusal']['linked_findings']);

        $evaluation = ReplayConformanceResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('php_code_divergence_refusal', $evaluation['non_pass_scenarios']);
        $this->assertContains(
            'missing_non_pass_finding',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_replay_result_gate_rejects_duplicate_scenario_results(): void
    {
        $result = $this->completeReplayConformanceResult();
        $result['scenario_results'] = array_values($result['scenario_results']);
        $result['scenario_results'][] = [
            'scenario_id' => 'python_completed_history_activity_replay',
            'status' => 'pass',
            'observed_outputs' => [
                'recorded' => true,
            ],
        ];

        $evaluation = ReplayConformanceResultGate::evaluate($result);
        $duplicateFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'duplicate_scenario_result',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $duplicateFailures);
        $this->assertSame('python_completed_history_activity_replay', $duplicateFailures[0]['scenario_id']);
        $this->assertSame(2, $duplicateFailures[0]['count']);
    }

    public function test_replay_result_gate_rejects_pass_without_replay_evidence(): void
    {
        $result = $this->completeReplayConformanceResult();
        unset($result['scenario_results']['python_completed_history_activity_replay']['observed_outputs']);

        $evaluation = ReplayConformanceResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_pass_replay_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_replay_result_gate_requires_complete_run_record_metadata(): void
    {
        $result = $this->completeReplayConformanceResult();
        unset($result['finished_at'], $result['finding_links']);

        $evaluation = ReplayConformanceResultGate::evaluate($result);
        $runRecordFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_required_run_record_field',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('finished_at', array_column($runRecordFailures, 'field'));
        $this->assertContains('finding_links', array_column($runRecordFailures, 'field'));
    }

    public function test_replay_result_gate_accepts_status_and_verdict_as_outcome_metadata(): void
    {
        foreach (['status', 'verdict'] as $alias) {
            $result = $this->completeReplayConformanceResult();
            unset($result['outcome']);
            $result[$alias] = 'pass';

            $evaluation = ReplayConformanceResultGate::evaluate($result);

            $this->assertSame('pass', $evaluation['status'], $alias);
            $this->assertSame([], $this->missingReplayRunRecordFields($evaluation), $alias);
            $this->assertSame([], $evaluation['gate_failures'], $alias);
        }
    }

    public function test_replay_result_gate_reports_alias_declared_outcome_mismatch_without_missing_outcome(): void
    {
        foreach (['status', 'verdict'] as $alias) {
            foreach (['fail', 'failed', 'error', 'non_passing'] as $outcome) {
                $result = $this->completeReplayConformanceResult();
                unset($result['outcome']);
                $result[$alias] = $outcome;

                $evaluation = ReplayConformanceResultGate::evaluate($result);
                $mismatchFailures = array_values(array_filter(
                    $evaluation['gate_failures'],
                    static fn (array $failure): bool => ($failure['code'] ?? null) === 'declared_outcome_status_mismatch',
                ));
                $case = $alias.':'.$outcome;

                $this->assertSame('non_passing', $evaluation['status'], $case);
                $this->assertNotContains('outcome', $this->missingReplayRunRecordFields($evaluation), $case);
                $this->assertCount(1, $mismatchFailures, $case);
                $this->assertSame($alias, $mismatchFailures[0]['field'], $case);
                $this->assertSame($outcome, $mismatchFailures[0]['outcome'], $case);
                $this->assertSame('non_passing', $mismatchFailures[0]['declared_status'], $case);
                $this->assertSame('pass', $mismatchFailures[0]['evaluated_status'], $case);
            }
        }
    }

    public function test_replay_result_gate_accepts_complete_non_passing_product_findings(): void
    {
        $result = $this->completeReplayConformanceResult();
        $result['outcome'] = 'fail';
        $result['finding_links'] = [
            [
                'scenario_id' => 'php_code_divergence_refusal',
                'url' => 'https://tracker.example.invalid/findings/replay-divergence',
            ],
        ];
        $result['scenario_results']['php_code_divergence_refusal']['status'] = 'fail';
        $result['scenario_results']['php_code_divergence_refusal']['linked_findings'] = [
            'https://tracker.example.invalid/findings/replay-divergence',
        ];

        $evaluation = ReplayConformanceResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(['php_code_divergence_refusal'], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_replay_result_gate_accepts_non_passing_status_and_verdict_with_product_findings(): void
    {
        foreach (['status', 'verdict'] as $alias) {
            $result = $this->completeReplayConformanceResult();
            unset($result['outcome']);
            $result[$alias] = 'fail';
            $result['finding_links'] = [
                [
                    'scenario_id' => 'php_code_divergence_refusal',
                    'url' => 'https://tracker.example.invalid/findings/replay-divergence',
                ],
            ];
            $result['scenario_results']['php_code_divergence_refusal']['status'] = 'fail';
            $result['scenario_results']['php_code_divergence_refusal']['linked_findings'] = [
                'https://tracker.example.invalid/findings/replay-divergence',
            ];

            $evaluation = ReplayConformanceResultGate::evaluate($result);

            $this->assertSame('non_passing', $evaluation['status'], $alias);
            $this->assertSame(['php_code_divergence_refusal'], $evaluation['non_pass_scenarios'], $alias);
            $this->assertSame([], $this->missingReplayRunRecordFields($evaluation), $alias);
            $this->assertSame([], $evaluation['gate_failures'], $alias);
        }
    }

    public function test_replay_result_gate_rejects_declared_pass_when_evidence_is_non_passing(): void
    {
        $result = $this->completeReplayConformanceResult();
        $result['finding_links'] = [
            [
                'scenario_id' => 'php_code_divergence_refusal',
                'url' => 'https://tracker.example.invalid/findings/replay-divergence',
            ],
        ];
        $result['scenario_results']['php_code_divergence_refusal']['status'] = 'fail';
        $result['scenario_results']['php_code_divergence_refusal']['linked_findings'] = [
            'https://tracker.example.invalid/findings/replay-divergence',
        ];

        $evaluation = ReplayConformanceResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'declared_outcome_status_mismatch',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_replay_result_gate_rejects_invalid_declared_outcome(): void
    {
        $result = $this->completeReplayConformanceResult();
        $result['outcome'] = 'fail';
        $result['verdict'] = 'green';

        $evaluation = ReplayConformanceResultGate::evaluate($result);
        $invalidOutcomeFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'invalid_declared_outcome',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $invalidOutcomeFailures);
        $this->assertSame('verdict', $invalidOutcomeFailures[0]['field']);
        $this->assertSame('green', $invalidOutcomeFailures[0]['outcome']);
    }

    public function test_replay_result_gate_requires_current_artifact_tuple_versions(): void
    {
        $result = $this->completeReplayConformanceResult();
        unset($result['artifactVersions']['waterline']);

        $evaluation = ReplayConformanceResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'waterline',
            array_column($evaluation['gate_failures'], 'artifact'),
        );
    }

    public function test_replay_result_gate_rejects_placeholder_artifact_versions(): void
    {
        $result = $this->completeReplayConformanceResult();
        $result['artifactVersions'] = [
            'server' => 'durableworkflow/server:head',
            'cli' => 'durable-workflow-cli==current',
            'sdk-php' => 'durable-workflow/sdk:latest',
            'sdk-python' => 'durable-workflow==unresolved',
            'sdk-rust' => 'durable-workflow@head',
            'workflow' => 'durable-workflow/workflow:placeholder',
            'waterline' => 'durable-workflow/waterline:<latest>',
        ];

        $evaluation = ReplayConformanceResultGate::evaluate($result);
        $placeholderFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(
            ['server', 'cli', 'sdk-php', 'workflow-php', 'sdk-python', 'sdk-rust', 'waterline'],
            array_column($placeholderFailures, 'artifact'),
        );
    }

    public function test_replay_result_gate_rejects_each_advertised_placeholder_word_inside_an_artifact_version(): void
    {
        foreach (['latest', 'current', 'head', 'unresolved', 'placeholder'] as $placeholder) {
            $result = $this->completeReplayConformanceResult();
            $result['artifactVersions']['server'] = 'durableworkflow/server:'.$placeholder;

            $evaluation = ReplayConformanceResultGate::evaluate($result);
            $serverPlaceholderFailures = array_values(array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'placeholder_artifact_version'
                    && ($failure['artifact'] ?? null) === 'server',
            ));

            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertCount(1, $serverPlaceholderFailures);
            $this->assertSame('durableworkflow/server:'.$placeholder, $serverPlaceholderFailures[0]['version']);
        }
    }

    public function test_replay_result_gate_requires_actionable_refusal_diagnostics(): void
    {
        $result = $this->completeReplayConformanceResult();
        unset($result['scenario_results']['server_history_mutation_refusal']['replay_diagnostics']['integrity']['rule']);

        $evaluation = ReplayConformanceResultGate::evaluate($result);
        $diagnosticFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_actionable_refusal_diagnostic',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertCount(1, $diagnosticFailures);
        $this->assertSame('server_history_mutation_refusal', $diagnosticFailures[0]['scenario_id']);
        $this->assertContains('integrity.rule', $diagnosticFailures[0]['missing_fields']);
    }

    public function test_replay_result_gate_requires_refusal_and_timing_outcomes_to_match_the_contract(): void
    {
        $result = $this->completeReplayConformanceResult();
        $result['scenario_results']['php_code_divergence_refusal']['observed_outcome'] = 'stack_trace';
        $result['scenario_results']['server_history_mutation_refusal']['replay_diagnostics']['observed_outcome'] = 'accepted';
        $result['scenario_results']['python_in_flight_signal_restart_timing']['observed_outputs']['observed_outcome'] = 'timed_out';

        $evaluation = ReplayConformanceResultGate::evaluate($result);
        $outcomeFailures = array_values(array_filter(
            $evaluation['gate_failures'],
            static fn (array $failure): bool => ($failure['code'] ?? null) === 'unexpected_required_outcome',
        ));

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame([
            'php_code_divergence_refusal',
            'server_history_mutation_refusal',
            'python_in_flight_signal_restart_timing',
        ], array_column($outcomeFailures, 'scenario_id'));
        $this->assertContains('non_determinism_error', array_column($outcomeFailures, 'expected_outcome'));
        $this->assertContains('bundle_invalid_or_drifted', array_column($outcomeFailures, 'expected_outcome'));
        $this->assertContains('same_next_decision_after_replay', array_column($outcomeFailures, 'expected_outcome'));
    }

    public function test_replay_result_gate_accepts_a_complete_passing_matrix(): void
    {
        $evaluation = ReplayConformanceResultGate::evaluate($this->completeReplayConformanceResult());

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['missing_scenarios']);
        $this->assertSame([], $evaluation['non_pass_scenarios']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @return list<string>
     */
    private function missingReplayRunRecordFields(array $evaluation): array
    {
        $fields = [];
        foreach ($evaluation['gate_failures'] ?? [] as $failure) {
            if (! is_array($failure) || ($failure['code'] ?? null) !== 'missing_required_run_record_field') {
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
     * @return array<string, mixed>
     */
    private function completeReplayConformanceResult(): array
    {
        $scenarioResults = [];
        foreach (ReplayVerificationContract::manifest()['replay_conformance']['required_scenarios'] as $scenario) {
            $scenarioResults[$scenario] = [
                'scenario_id' => $scenario,
                'status' => 'pass',
                'observed_outputs' => [
                    'recorded' => true,
                ],
            ];
        }

        foreach (['python_code_divergence_refusal', 'php_code_divergence_refusal'] as $scenario) {
            $scenarioResults[$scenario]['observed_outcome'] = 'non_determinism_error';
            $scenarioResults[$scenario]['replay_diagnostics'] = [
                'workflow_sequence' => 4,
                'expected_shape' => 'schedule_activity',
                'recorded_event_types' => ['ActivityTaskScheduled'],
                'message' => 'Replay diverged at workflow sequence 4.',
            ];
            unset($scenarioResults[$scenario]['observed_outputs']);
        }

        $scenarioResults['server_history_mutation_refusal']['replay_diagnostics'] = [
            'integrity' => [
                'rule' => 'integrity.checksum_mismatch',
                'path' => '$.history_events[2].payload',
            ],
            'replay_diff' => [
                'reason' => 'bundle_invalid',
            ],
            'observed_outcome' => 'bundle_invalid_or_drifted',
            'message' => 'History bundle integrity check failed before replay.',
        ];
        unset($scenarioResults['server_history_mutation_refusal']['observed_outputs']);

        $scenarioResults['malformed_history_refusal']['replay_diagnostics'] = [
            'integrity' => [
                'rule' => 'history_events.sequence_not_monotonic',
                'path' => '$.history_events[3].sequence',
            ],
            'observed_outcome' => 'bundle_invalid_or_failed',
            'message' => 'History event sequence is not monotonic.',
        ];
        unset($scenarioResults['malformed_history_refusal']['observed_outputs']);

        foreach (['python_in_flight_signal_restart_timing', 'php_in_flight_signal_restart_timing'] as $scenario) {
            $scenarioResults[$scenario]['observed_outputs'] = [
                'worker_restart_at' => '2026-05-19T22:00:00Z',
                'signal_sent_at' => '2026-05-19T22:00:01Z',
                'history_reloaded_at' => '2026-05-19T22:00:02Z',
                'replayed_next_decision' => 'schedule_activity:after-signal',
                'observed_outcome' => 'same_next_decision_after_replay',
            ];
        }

        return [
            'schema' => ReplayVerificationContract::REPLAY_CONFORMANCE_RESULT_SCHEMA,
            'started_at' => '2026-05-19T21:59:59Z',
            'finished_at' => '2026-05-19T22:10:00Z',
            'outcome' => 'pass',
            'artifactVersions' => [
                'server' => '0.2.140',
                'cli' => '0.1.45',
                'sdk-php' => '0.1.1',
                'sdk-python' => '0.4.59',
                'sdk-rust' => '0.1.13',
                'workflow' => '2.0.0-alpha.162',
                'waterline' => '2.0.0-alpha.54',
            ],
            'runtime_matrix' => [
                'runtimes' => ['sdk-php', 'sdk-python', 'sdk-rust'],
            ],
            'scenario_results' => $scenarioResults,
            'findings' => [],
            'finding_links' => [],
        ];
    }
}
