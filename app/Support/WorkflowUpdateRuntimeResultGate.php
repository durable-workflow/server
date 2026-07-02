<?php

namespace App\Support;

/**
 * Publishes the gate requirements for workflow update conformance results.
 */
final class WorkflowUpdateRuntimeResultGate
{
    public const SCHEMA = 'durable-workflow.v2.workflow-update-runtime.result-gate';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function spec(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'evaluates_result_schema' => WorkflowUpdateRuntimeContract::RESULT_SCHEMA,
            'scenario_statuses_source' => 'workflow_update_runtime_contract.scenario_statuses',
            'required_scenarios_source' => 'workflow_update_runtime_contract.required_scenarios',
            'required_run_record_fields_source' => 'workflow_update_runtime_contract.artifact_policy.required_run_record_fields',
            'artifact_versions_fields' => [
                'artifact_versions',
                'artifactVersions',
                'published_artifact_versions',
                'publishedArtifactVersions',
            ],
            'artifact_sources_fields' => [
                'artifact_sources',
                'artifactSources',
            ],
            'declared_outcome_fields' => [
                'outcome',
                'status',
                'verdict',
            ],
            'scenario_results_fields' => [
                'scenario_results',
                'scenarioResults',
            ],
            'update_cell_outcomes_fields' => [
                'update_cell_outcomes',
                'updateCellOutcomes',
            ],
            'non_pass_statuses' => [
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'pass_requires' => [
                'every_required_update_cell_has_one_result',
                'every_result_uses_a_published_status',
                'run_timestamps_outcome_runner_blocked_and_findings_are_recorded',
                'published_artifact_versions_are_recorded_and_pinned',
                'artifact_sources_are_recorded_for_required_artifacts',
                'source_policy_is_recorded',
                'local_product_source_checkouts_used_is_explicitly_false',
                'no_local_product_source_artifacts_are_reported',
                'each_pass_scenario_proves_published_artifact_cell_execution',
                'declared_update_contract_is_visible_before_acceptance',
                'accepted_running_waiting_completed_and_failed_update_states_are_observed',
                'duplicate_or_idempotent_requests_have_stable_outcomes',
                'unknown_update_invalid_input_and_terminal_workflow_refusals_are_typed',
                'payload_envelope_round_trip_is_observed_on_control_plane_history_and_operator_surfaces',
                'principal_attribution_is_proven_when_authentication_is_enabled',
                'php_and_python_cells_pass_or_emit_documented_typed_unsupported_evidence',
                'overall_outcome_matches_gate_status',
            ],
            'smoke_subset_outcome' => 'non_passing',
        ];
    }
}
