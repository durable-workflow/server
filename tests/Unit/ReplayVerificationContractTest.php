<?php

namespace Tests\Unit;

use App\Support\ReplayVerificationContract;
use PHPUnit\Framework\TestCase;

class ReplayVerificationContractTest extends TestCase
{
    public function test_manifest_publishes_canonical_schema_and_offline_cli_surface(): void
    {
        $manifest = ReplayVerificationContract::manifest();

        $this->assertSame('durable-workflow.v2.replay-verification.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);

        $this->assertSame('durable-workflow.v2.history-export', $manifest['bundle']['schema']);
        $this->assertSame(1, $manifest['bundle']['schema_version']);

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

        $this->assertSame(
            'latest_published_artifacts_at_run_time',
            $conformance['artifact_policy']['version_source'],
        );
        $this->assertArrayHasKey('server', $conformance['artifact_policy']['install_channels']);
        $this->assertArrayHasKey('workflow-php', $conformance['artifact_policy']['install_channels']);
        $this->assertArrayHasKey('sdk-python', $conformance['artifact_policy']['install_channels']);
        $this->assertContains(
            'local_product_source_checkout',
            $conformance['artifact_policy']['forbidden_sources'],
        );

        $this->assertSame(['workflow-php', 'sdk-python'], $conformance['required_runtimes']);

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
        $matrix = $manifest['replay_conformance']['required_matrix'];

        $this->assertSame('each_required_runtime', $matrix['runtime_scope']);

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

        $this->assertContains('not_covered', $conformance['scenario_statuses']);
        $this->assertSame(
            'non_passing',
            $conformance['coverage_gate']['uncovered_required_scenario_outcome'],
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
    }
}
