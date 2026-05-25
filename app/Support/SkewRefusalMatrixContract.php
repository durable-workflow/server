<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for published-artifact version-skew refusal.
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
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'version_requirement' => 'concrete_published_versions_pinned_at_run_time',
                'placeholder_versions_rejected' => true,
                'required_artifacts' => [
                    'server',
                    'cli',
                    'sdk-python',
                    'workflow',
                    'waterline',
                ],
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<version>',
                    'cli' => 'official dw install script pinned to <version>',
                    'sdk-python' => 'PyPI package durable-workflow==<version>',
                    'workflow' => 'Composer package durable-workflow/workflow:<version>',
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
                    'findings',
                    'finding_links',
                ],
            ],
            'status_taxonomy' => [
                'pass',
                'loud_refuse',
                'silent_success',
                'silent_failure',
                'corrupt',
                'not_covered',
                'runner_blocked',
            ],
            'pairing_classes' => [
                'compatible' => [
                    'expected_statuses' => ['pass'],
                    'description' => 'Both sides are inside the advertised compatibility window and the operation completes.',
                ],
                'backward_skew' => [
                    'expected_statuses' => ['pass', 'loud_refuse'],
                    'description' => 'The client or worker is older than the server. It may interoperate inside the window or refuse before sending an unsupported shape.',
                ],
                'forward_skew' => [
                    'expected_statuses' => ['pass', 'loud_refuse'],
                    'description' => 'The client or worker is newer than the server. It may interoperate inside the window or refuse before unsupported work is accepted.',
                ],
                'outside_window' => [
                    'expected_statuses' => ['loud_refuse'],
                    'description' => 'The pair is outside the advertised compatibility window and must refuse before mutating state or dropping work.',
                ],
            ],
            'required_surfaces' => [
                'cli' => [
                    'artifact' => 'cli',
                    'component' => 'CLI',
                    'owner' => 'durable-workflow/cli',
                    'pairing_axis' => 'client_version_to_server_version',
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
                    'pairing_axis' => 'client_version_to_server_version',
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
                'workflow-worker' => [
                    'artifact' => 'workflow',
                    'component' => 'PHP workflow worker',
                    'owner' => 'durable-workflow/workflow',
                    'pairing_axis' => 'worker_version_to_server_version',
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
                    'pairing_axis' => 'observer_version_to_server_version',
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
                        'status_code',
                        'response_body',
                        'client_or_observer_version',
                        'server_version',
                        'protocol_manifest_versions',
                    ],
                ],
                'workflow_control_plane' => [
                    'requests' => [
                        'POST /api/workflows',
                        'GET /api/workflows/{id}',
                        'GET /api/workflows/{id}/history',
                        'POST /api/workflows/{id}/signals',
                        'POST /api/workflows/{id}/queries',
                        'POST /api/workflows/{id}/updates',
                        'POST /api/workflows/{id}/cancel',
                        'POST /api/workflows/{id}/terminate',
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
                        'classification',
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
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
                'uncovered_surface_outcome' => 'non_passing_not_covered',
                'compatible_pairs_must_pass' => true,
                'outside_window_pairs_must_loud_refuse' => true,
                'silent_success_is_blocking' => true,
                'silent_failure_is_blocking' => true,
                'corrupt_is_blocking' => true,
            ],
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
            'classification',
        ];
    }
}
