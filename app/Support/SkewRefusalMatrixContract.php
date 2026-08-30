<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for published-artifact protocol-skew refusal.
 *
 * The conformance host owns execution. This manifest gives that host one
 * server-published authority for the full matrix, the allowed outcomes, and
 * the request/response evidence needed to route any failing cell.
 */
final class SkewRefusalMatrixContract
{
    public const SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.skew-refusal-matrix.result';

    public const RESULT_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'result_schema' => self::RESULT_SCHEMA,
            'result_version' => self::RESULT_VERSION,
            'fixture_category' => 'skew_refusal_matrix_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'version' => 1,
                'category' => 'skew_refusal_matrix_contract',
                'suite_schema' => PlatformConformanceSuite::SCHEMA,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'public_path' => 'https://durable-workflow.github.io/platform-conformance/skew-refusal-matrix-scenarios.json',
                'source_path' => 'static/platform-conformance/skew-refusal-matrix-scenarios.json',
            ],
            'required_scenarios' => self::requiredScenarios(),
            'scenario_statuses' => [
                'pass',
                'fail',
                'unsupported',
                'not_covered',
                'runner_blocked',
            ],
            'artifact_policy' => [
                'version_source' => 'exact_current_published_tuple_at_run_time',
                'version_requirement' => 'concrete_exact_current_published_versions_pinned_at_run_time',
                'prerelease_interoperability' => 'exact_current_tuple_only',
                'historical_prerelease_packages_installed' => false,
                'stable_release_policy' => 'semver_after_2.0.0',
                'placeholder_versions_rejected' => true,
                'placeholder_version_examples' => [
                    'latest',
                    'current',
                    'head',
                    'unresolved',
                    'placeholder',
                    '<latest>',
                    '${VERSION}',
                    '{{ version }}',
                ],
                'required_artifacts' => [
                    'server',
                    'cli',
                    'sdk-python',
                    'workflow',
                    'sdk-php',
                    'waterline',
                ],
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<version>',
                    'cli' => 'official dw install script pinned to <version>',
                    'sdk-python' => 'PyPI package durable-workflow==<version>',
                    'workflow' => 'Composer package durable-workflow/workflow:<version>',
                    'sdk-php' => 'exact Composer package durable-workflow/sdk:<version> from Packagist',
                    'waterline' => 'published Waterline package or image matching <version>',
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                    'floating_latest_without_resolved_version',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'started_at',
                    'finished_at',
                    'outcome',
                    'runner_blocked',
                    'surface_results',
                    'pairing_results',
                    'operation_evidence',
                    'request_response_captures',
                    'findings',
                    'finding_links',
                ],
            ],
            'status_taxonomy' => [
                'pass',
                'loud_refuse',
                'mutation_before_refusal',
                'silent_success',
                'silent_failure',
                'corrupt',
                'not_covered',
                'runner_blocked',
            ],
            'pairing_classes' => [
                'compatible' => [
                    'expected_statuses' => ['pass'],
                    'description' => 'The exact current published tuple uses the Server-advertised control-plane and worker protocols and serves work.',
                ],
                'backward_skew' => [
                    'expected_statuses' => ['pass', 'loud_refuse'],
                    'description' => 'The exact current artifacts exercise a Server-advertised older worker protocol and a non-current control-plane shape without installing historical packages.',
                ],
                'forward_skew' => [
                    'expected_statuses' => ['loud_refuse'],
                    'description' => 'The exact current artifacts exercise future protocol shapes that must refuse before mutation, registration, lease, or dropped work.',
                ],
                'outside_window' => [
                    'expected_statuses' => ['loud_refuse'],
                    'description' => 'The exact current artifacts exercise unsupported protocol majors that must refuse before mutation, registration, lease, dropped work, or stale render.',
                ],
            ],
            'protocol_authority' => [
                'source' => 'GET /api/cluster/info',
                'control_plane_version_path' => 'control_plane.version',
                'worker_protocol_version_path' => 'worker_protocol.version',
                'worker_negotiation_path' => 'surface_stability_contract.surface_families.worker_protocol.negotiation',
                'manifest_disagreement_outcome' => 'runner_blocked',
                'scenario_labels_and_expectations_derive_from_authority' => true,
            ],
            'required_surfaces' => [
                'cli' => [
                    'artifact' => 'cli',
                    'component' => 'CLI',
                    'owner' => 'durable-workflow/cli',
                    'pairing_axis' => 'protocol_shape_to_server_protocol_authority',
                    'required_pairing_classes' => self::requiredPairingClasses(),
                    'operation_groups' => [
                        'cluster_info_probe',
                        'workflow_control_plane',
                        'schedule_control_plane',
                    ],
                    'refusal_requirements' => [
                        'names_client_version',
                        'names_server_version',
                        'names_protocol_or_manifest',
                        'explains_compatibility_window',
                        'suggests_upgrade_or_pin_next_step',
                        'uses_documented_exit_code',
                    ],
                ],
                'sdk-python' => [
                    'artifact' => 'sdk-python',
                    'component' => 'Python SDK',
                    'owner' => 'durable-workflow/sdk-python',
                    'pairing_axis' => 'protocol_shape_to_server_protocol_authority',
                    'required_pairing_classes' => self::requiredPairingClasses(),
                    'operation_groups' => [
                        'cluster_info_probe',
                        'workflow_control_plane',
                        'worker_lifecycle',
                        'schedule_control_plane',
                    ],
                    'refusal_requirements' => [
                        'raises_typed_or_documented_exception',
                        'names_client_version',
                        'names_server_version',
                        'names_protocol_or_manifest',
                        'explains_compatibility_window',
                        'suggests_upgrade_or_pin_next_step',
                    ],
                ],
                'sdk-php' => [
                    'artifact' => 'sdk-php',
                    'component' => 'PHP SDK worker',
                    'owner' => 'durable-workflow/sdk',
                    'pairing_axis' => 'protocol_shape_to_server_protocol_authority',
                    'required_pairing_classes' => self::requiredPairingClasses(),
                    'operation_groups' => [
                        'cluster_info_probe',
                        'worker_lifecycle',
                    ],
                    'refusal_requirements' => [
                        'register_refused_or_register_and_serve_only',
                        'names_worker_version',
                        'names_server_version',
                        'names_worker_protocol_version',
                        'explains_compatibility_window',
                        'suggests_upgrade_or_pin_next_step',
                    ],
                ],
                'waterline' => [
                    'artifact' => 'waterline',
                    'component' => 'Waterline',
                    'owner' => 'durable-workflow/waterline',
                    'pairing_axis' => 'protocol_shape_to_server_protocol_authority',
                    'required_pairing_classes' => self::requiredPairingClasses(),
                    'operation_groups' => [
                        'cluster_info_probe',
                        'waterline_render',
                    ],
                    'refusal_requirements' => [
                        'banner_or_render_refused',
                        'names_waterline_version',
                        'names_server_version',
                        'explains_compatibility_window',
                        'suggests_upgrade_or_pin_next_step',
                    ],
                ],
            ],
            'operation_groups' => [
                'cluster_info_probe' => [
                    'requests' => ['GET /api/cluster/info'],
                    'evidence' => [
                        'request',
                        'status',
                        'status_code',
                        'response_body',
                        'client_or_observer_version',
                        'server_version',
                        'protocol_manifest_versions',
                        'compatibility_window',
                        'next_step',
                        'request_response_capture_id',
                    ],
                ],
                'workflow_control_plane' => [
                    'requests' => [
                        'POST /api/workflows',
                        'GET /api/workflows/{workflowId}',
                        'GET /api/workflows/{workflowId}/runs',
                        'GET /api/workflows/{workflowId}/runs/{runId}',
                        'GET /api/workflows/{workflowId}/runs/{runId}/history',
                        'POST /api/workflows/{workflowId}/signal/{signalName}',
                        'POST /api/workflows/{workflowId}/query/{queryName}',
                        'POST /api/workflows/{workflowId}/update/{updateName}',
                        'POST /api/workflows/{workflowId}/runs/{runId}/signal/{signalName}',
                        'POST /api/workflows/{workflowId}/runs/{runId}/query/{queryName}',
                        'POST /api/workflows/{workflowId}/runs/{runId}/update/{updateName}',
                        'POST /api/workflows/{workflowId}/cancel',
                        'POST /api/workflows/{workflowId}/terminate',
                    ],
                    'evidence' => self::wireEvidenceFields(),
                ],
                'worker_lifecycle' => [
                    'requests' => [
                        'POST /api/worker/register',
                        'POST /api/worker/heartbeat',
                        'POST /api/worker/workflow-tasks/poll',
                        'POST /api/worker/workflow-tasks/{task}/complete',
                        'POST /api/worker/workflow-tasks/{task}/fail',
                    ],
                    'evidence' => self::wireEvidenceFields(),
                ],
                'schedule_control_plane' => [
                    'requests' => [
                        'POST /api/schedules',
                        'GET /api/schedules/{id}',
                        'POST /api/schedules/{id}/trigger',
                    ],
                    'evidence' => self::wireEvidenceFields(),
                ],
                'waterline_render' => [
                    'requests' => [
                        'GET /waterline/api/v2/health',
                        'GET /waterline/api/flows/running',
                        'GET /waterline/api/flows/{id}',
                    ],
                    'evidence' => [
                        'request',
                        'response_status',
                        'response_body',
                        'screenshot_or_dom_snapshot',
                        'server_version',
                        'waterline_version',
                        'compatibility_window',
                        'next_step',
                        'status',
                        'waterline_skew_classification',
                        'request_response_capture_id',
                    ],
                ],
            ],
            'worker_skew_classification' => [
                'allowed' => [
                    'register_refused',
                    'register_and_serve',
                    'register_and_drop',
                ],
                'passing' => [
                    'register_refused',
                    'register_and_serve',
                ],
                'blocking' => [
                    'register_and_drop',
                ],
                'register_and_drop_definition' => 'Worker registration appears healthy, but compatible tasks are never served or are silently dropped.',
            ],
            'waterline_skew_classification' => [
                'allowed' => [
                    'banner',
                    'render_refused',
                    'stale_render',
                ],
                'passing' => [
                    'banner',
                    'render_refused',
                ],
                'blocking' => [
                    'stale_render',
                ],
                'stale_render_definition' => 'Waterline renders old or incompatible state without a visible compatibility warning or refusal.',
            ],
            'coverage_gate' => [
                'full_matrix_required' => true,
                'smoke_only_outcome' => 'non_passing_smoke_only',
                'all_required_surfaces_required' => true,
                'all_pairing_classes_required_per_surface' => true,
                'all_operation_groups_required_per_surface' => true,
                'all_advertised_requests_required_per_operation_group' => true,
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
                'uncovered_surface_outcome' => 'non_passing_not_covered',
                'compatible_pairs_must_pass' => true,
                'outside_window_pairs_must_loud_refuse' => true,
                'silent_success_is_blocking' => true,
                'silent_failure_is_blocking' => true,
                'corrupt_is_blocking' => true,
                'mutation_before_refusal_is_blocking' => true,
                'focused_findings_required_for_uncovered_cells' => true,
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_skew_refusal_matrix_conformance',
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/skew-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/skew-published-artifacts.sh --result-dir <result-dir>',
                'result_schema' => self::RESULT_SCHEMA,
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'skew-result.json',
                    'skew-record.json',
                    'request-response-captures.json',
                ],
                'required_scenarios' => self::requiredScenarios(),
                'must_execute_against_published_artifacts' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_emit_result_for_every_required_surface_pairing_operation_group' => true,
                'must_capture_request_response_for_every_skewed_operation' => true,
                'must_compare_pre_and_post_refusal_state_for_mutation_bearing_operations' => true,
                'smoke_summary_only_outcome' => 'non_passing_smoke_only',
                'unexecuted_required_cell_status' => 'not_covered',
                'coverage_gap_finding_type' => 'conformance_runner_coverage_gap',
                'coverage_gap_owner' => 'conformance_harness',
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'cli-skew-surface-shard',
                    'sdk-python-skew-surface-shard',
                    'sdk-php-skew-surface-shard',
                    'waterline-skew-surface-shard',
                    'future-version-boundary-shard',
                    'request-response-evidence-shard',
                ],
                'runtime_shards' => [
                    'cli' => [
                        'scope' => 'cli-skew-surface-shard',
                        'artifact' => 'cli',
                        'owner' => 'durable-workflow/cli',
                        'must_cover_pairing_classes' => self::requiredPairingClasses(),
                        'must_cover_operation_groups' => [
                            'cluster_info_probe',
                            'workflow_control_plane',
                            'schedule_control_plane',
                        ],
                        'fallback_status_when_surface_missing' => 'not_covered',
                        'fallback_finding_type' => 'conformance_runner_coverage_gap',
                    ],
                    'sdk-python' => [
                        'scope' => 'sdk-python-skew-surface-shard',
                        'artifact' => 'sdk-python',
                        'owner' => 'durable-workflow/sdk-python',
                        'must_cover_pairing_classes' => self::requiredPairingClasses(),
                        'must_cover_operation_groups' => [
                            'cluster_info_probe',
                            'workflow_control_plane',
                            'worker_lifecycle',
                            'schedule_control_plane',
                        ],
                        'fallback_status_when_surface_missing' => 'not_covered',
                        'fallback_finding_type' => 'conformance_runner_coverage_gap',
                    ],
                    'sdk-php' => [
                        'scope' => 'sdk-php-skew-surface-shard',
                        'artifact' => 'sdk-php',
                        'owner' => 'durable-workflow/sdk',
                        'must_cover_pairing_classes' => self::requiredPairingClasses(),
                        'must_cover_operation_groups' => [
                            'cluster_info_probe',
                            'worker_lifecycle',
                        ],
                        'must_classify_worker_skew_as' => [
                            'register_refused',
                            'register_and_serve',
                            'register_and_drop',
                        ],
                        'blocking_classification' => 'register_and_drop',
                        'fallback_status_when_surface_missing' => 'not_covered',
                        'fallback_finding_type' => 'conformance_runner_coverage_gap',
                    ],
                    'waterline' => [
                        'scope' => 'waterline-skew-surface-shard',
                        'artifact' => 'waterline',
                        'owner' => 'durable-workflow/waterline',
                        'must_cover_pairing_classes' => self::requiredPairingClasses(),
                        'must_cover_operation_groups' => [
                            'cluster_info_probe',
                            'waterline_render',
                        ],
                        'must_classify_waterline_skew_as' => [
                            'banner',
                            'render_refused',
                            'stale_render',
                        ],
                        'blocking_classification' => 'stale_render',
                        'fallback_status_when_surface_missing' => 'not_covered',
                        'fallback_finding_type' => 'conformance_runner_coverage_gap',
                    ],
                ],
                'routing_policy' => [
                    'missing_required_cell' => [
                        'operation_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                        'requires_acceptance' => true,
                    ],
                    'host_environment_failure' => [
                        'operation_status' => 'runner_blocked',
                        'finding_type' => 'runner_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'silent_success_or_corrupt_state' => [
                        'operation_statuses' => ['silent_success', 'corrupt'],
                        'finding_source' => 'skew_refusal_matrix_contract.finding_policy',
                        'route_to' => 'accepting_side',
                    ],
                    'silent_failure' => [
                        'operation_status' => 'silent_failure',
                        'finding_source' => 'skew_refusal_matrix_contract.finding_policy',
                        'route_to' => 'emitting_side',
                    ],
                    'mutation_before_refusal' => [
                        'operation_status' => 'mutation_before_refusal',
                        'finding_source' => 'skew_refusal_matrix_contract.finding_policy',
                        'route_to' => 'server_protocol_boundary',
                    ],
                    'worker_register_and_drop' => [
                        'worker_skew_classification' => 'register_and_drop',
                        'finding_type' => 'product_gap',
                        'owner' => 'worker_and_server_boundary',
                    ],
                    'waterline_stale_render' => [
                        'waterline_skew_classification' => 'stale_render',
                        'finding_type' => 'product_gap',
                        'owner' => 'durable-workflow/waterline',
                    ],
                ],
            ],
            'result_gate' => SkewRefusalMatrixResultGate::spec(),
            'finding_policy' => [
                'silent_success' => [
                    'severity' => 'blocker',
                    'route_to' => 'accepting_side',
                    'requires_wire_evidence' => true,
                ],
                'silent_failure' => [
                    'severity' => 'blocker',
                    'route_to' => 'emitting_side',
                    'requires_wire_evidence' => true,
                ],
                'corrupt' => [
                    'severity' => 'blocker',
                    'route_to' => 'accepting_side',
                    'requires_wire_evidence' => true,
                ],
                'mutation_before_refusal' => [
                    'severity' => 'blocker',
                    'route_to' => 'server_protocol_boundary',
                    'requires_wire_evidence' => true,
                    'requires_pre_and_post_state_evidence' => true,
                ],
                'register_and_drop' => [
                    'severity' => 'blocker',
                    'route_to' => 'worker_and_server_boundary',
                    'requires_wire_evidence' => true,
                ],
                'stale_render' => [
                    'severity' => 'blocker',
                    'route_to' => 'waterline',
                    'requires_screenshot_or_dom_snapshot' => true,
                ],
                'uncovered_surface' => [
                    'severity' => 'tracking',
                    'route_to' => 'surface_owner',
                    'requires_acceptance' => true,
                ],
                'required_for_non_pass' => [
                    'owning_surface',
                    'artifact_versions',
                    'pairing_class',
                    'operation_group',
                    'observed_behavior',
                    'expected_behavior',
                    'request_response_evidence',
                    'next_acceptance_criterion',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function requiredPairingClasses(): array
    {
        return [
            'compatible',
            'backward_skew',
            'forward_skew',
            'outside_window',
        ];
    }

    /**
     * @return list<string>
     */
    private static function requiredScenarios(): array
    {
        return [
            'published_artifact_install_only',
            'cli_version_pair_matrix',
            'sdk_python_version_pair_matrix',
            'workflow_worker_version_pair_matrix',
            'waterline_version_pair_matrix',
            'future_version_boundary_matrix',
            'request_response_capture_for_skewed_operations',
            'focused_finding_routing',
        ];
    }

    /**
     * @return list<string>
     */
    private static function wireEvidenceFields(): array
    {
        return [
            'request_method',
            'request_path',
            'request_headers',
            'request_body',
            'response_status',
            'response_headers',
            'response_body',
            'client_or_worker_version',
            'server_version',
            'compatibility_window',
            'next_step',
            'status',
            'request_response_capture_id',
        ];
    }
}
