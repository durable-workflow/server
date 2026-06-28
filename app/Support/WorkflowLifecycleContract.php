<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for first-class workflow lifecycle conformance.
 */
final class WorkflowLifecycleContract
{
    public const SCHEMA = 'durable-workflow.v2.workflow-lifecycle.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.result';

    public const RESULT_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $scenarioRequirements = self::scenarioRequirements();

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'result_schema' => self::RESULT_SCHEMA,
            'result_version' => self::RESULT_VERSION,
            'fixture_category' => 'workflow_lifecycle_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'workflow_lifecycle_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/workflow-lifecycle-scenarios.json',
                'source_path' => 'static/platform-conformance/workflow-lifecycle-scenarios.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'version_requirement' => 'concrete_published_versions_pinned_at_run_time',
                'placeholder_versions_rejected' => true,
                'requires_recognized_published_artifact_sources' => true,
                'requires_source_policy' => true,
                'local_product_source_truthy_values' => [
                    true,
                    1,
                    '1',
                    'true',
                    'yes',
                    'on',
                ],
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<exact published version or digest>',
                    'cli' => 'official dw release install script pinned to its latest release tag',
                    'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'waterline' => 'published Waterline observer artifact matching the release set',
                ],
                'release_artifact_aliases' => [
                    'workflow-php' => ['workflow'],
                    'sdk-python' => ['python'],
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'local_checkout_artifact',
                    'local_source_checkout',
                    'workspace_repo',
                    'branch_source',
                    'local_vendor_tree',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'published_artifact_versions',
                    'artifact_sources',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'runner_blocked',
                    'scenario_results',
                    'lifecycle_cell_outcomes',
                    'findings',
                    'local_product_source_checkouts_used',
                    'source_policy',
                ],
            ],
            'scenario_statuses' => [
                'pass',
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'topology' => [
                'namespace' => 'workflow-lifecycle-conformance',
                'task_queue' => 'workflow-lifecycle-shared',
                'required_public_surfaces' => [
                    'POST /api/workflows',
                    'GET /api/workflows/{workflowId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}',
                    'GET /api/workflows/{workflowId}/runs/{runId}/history',
                    'POST /api/workflows/{workflowId}/runs/{runId}/cancel',
                    'POST /api/workflows/{workflowId}/runs/{runId}/terminate',
                    'CLI workflow start/show/history/cancel/terminate/result',
                    'Waterline workflow run detail and history views',
                ],
                'required_workers' => [
                    'workflow-php',
                    'sdk-python',
                ],
            ],
            'required_scenarios' => array_keys($scenarioRequirements),
            'scenario_requirements' => $scenarioRequirements,
            'coverage_gate' => [
                'passing_outcome' => 'pass',
                'non_passing_outcomes' => [
                    'fail',
                    'unsupported',
                    'not_covered',
                    'runner_blocked',
                    'non_passing',
                ],
                'focused_findings_required_for_non_pass_cells' => true,
                'run_record_provenance_required_for_pass' => true,
                'local_product_source_truthy_values_refuse_pass' => true,
            ],
            'host_runner_contract' => [
                'runner_repository' => 'platform_conformance_host',
                'runner_command' => 'workflow-lifecycle published-artifact runner',
                'must_exercise_published_artifacts' => true,
                'must_name_public_artifact_sources' => true,
                'must_record_lifecycle_cell_outcomes' => true,
                'must_record_source_policy' => true,
                'must_record_findings_even_for_clean_pass' => true,
                'no_local_product_source_checkout_pass_evidence' => true,
            ],
            'result_gate' => WorkflowLifecycleResultGate::spec(),
            'finding_policy' => [
                'missing_run_record_field' => 'link_root_cause_finding_against_conformance_harness',
                'missing_lifecycle_cell_outcome' => 'link_root_cause_finding_against_conformance_harness',
                'local_product_source_checkout_used' => 'link_root_cause_finding_against_conformance_harness',
                'local_product_source_checkouts_used_must_be_false' => 'link_root_cause_finding_against_conformance_harness',
                'missing_artifact_source' => 'link_root_cause_finding_against_conformance_harness',
                'forbidden_artifact_source' => 'link_root_cause_finding_against_conformance_harness',
                'missing_focused_finding_for_non_pass_cell' => 'link_root_cause_finding_against_conformance_harness',
                'product_behavior_failure' => 'link_root_cause_finding_against_server_or_sdk_owner',
                'operator_visibility_gap' => 'link_root_cause_finding_against_waterline_or_server',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function scenarioRequirements(): array
    {
        $sharedFields = [
            'observed_outputs',
            'lifecycle_cell_outcome',
            'artifact_sources',
            'local_product_source_checkouts_used',
            'source_policy',
        ];

        return [
            'continue_as_new_run_chain_visibility' => [
                'title' => 'Continue-as-new run-chain visibility',
                'required_fields' => $sharedFields,
            ],
            'continue_as_new_identity_and_history_continuity' => [
                'title' => 'Continue-as-new identity and history continuity',
                'required_fields' => $sharedFields,
            ],
            'continue_as_new_duplicate_side_effect_prevention' => [
                'title' => 'Continue-as-new duplicate side-effect prevention',
                'required_fields' => $sharedFields,
            ],
            'cancellation_public_surface_terminal_state' => [
                'title' => 'Cancellation public terminal state',
                'required_fields' => $sharedFields,
            ],
            'termination_public_surface_terminal_state' => [
                'title' => 'Termination public terminal state',
                'required_fields' => $sharedFields,
            ],
            'workflow_id_reuse_duplicate_start_policy' => [
                'title' => 'Workflow id reuse and duplicate-start policy',
                'required_fields' => $sharedFields,
            ],
            'workflow_timeout_terminal_state' => [
                'title' => 'Workflow timeout terminal state',
                'required_fields' => $sharedFields,
            ],
            'workflow_retry_backoff_or_refusal' => [
                'title' => 'Workflow retry backoff or typed unsupported refusal',
                'required_fields' => $sharedFields,
            ],
            'php_sdk_lifecycle_surface' => [
                'title' => 'PHP SDK lifecycle surface',
                'required_fields' => $sharedFields,
            ],
            'python_sdk_lifecycle_surface' => [
                'title' => 'Python SDK lifecycle surface',
                'required_fields' => $sharedFields,
            ],
            'operator_diagnostics_surfaces' => [
                'title' => 'CLI, API, history, and Waterline lifecycle diagnostics',
                'required_fields' => $sharedFields,
            ],
        ];
    }
}
