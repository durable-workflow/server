<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for the live namespace conformance run.
 *
 * The public platform conformance suite owns the scenario catalog. This
 * server-owned manifest expands it into the result fields, runtime axes,
 * evidence sections, and gate behavior needed to prove namespaces as an
 * isolation boundary without accepting the existing smoke subset as parity.
 */
final class NamespaceRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.namespace-runtime.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.namespace-runtime.result';

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
            'fixture_category' => 'namespace_runtime_contract',
            'platform_conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<latest>',
                    'cli' => 'official dw install script pinned to its latest release tag',
                    'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'waterline' => 'published Waterline package or image matching the latest release tag',
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'scenario_results',
                    'findings',
                    'finding_links',
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
                'required_namespaces' => [
                    'tenant-a',
                    'tenant-b',
                    'shared',
                ],
                'shared_task_queue' => 'iso',
                'search_attribute_key' => 'customer_id',
                'required_workers' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'required_operator_surface' => [
                    'cli_json_client',
                    'waterline_contract_surface',
                ],
            ],
            'required_matrix' => [
                'namespaces' => [
                    'tenant-a',
                    'tenant-b',
                    'shared',
                ],
                'runtimes' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'client_paths' => [
                    'cli',
                    'sdk-python',
                    'workflow-php-sdk',
                ],
                'observer_paths' => [
                    'waterline-list',
                    'waterline-detail',
                    'waterline-operator-api',
                ],
                'worker_isolation_cells' => [
                    [
                        'runtime' => 'workflow-php',
                        'namespace' => 'tenant-a',
                        'task_queue' => 'iso',
                        'scenario' => 'php_worker_task_queue_namespace_isolation',
                    ],
                    [
                        'runtime' => 'workflow-php',
                        'namespace' => 'tenant-b',
                        'task_queue' => 'iso',
                        'scenario' => 'php_worker_task_queue_namespace_isolation',
                    ],
                ],
                'cross_namespace_cells' => [
                    [
                        'from' => 'tenant-a',
                        'to' => 'tenant-b',
                        'surface' => 'workflow-control-plane',
                        'scenario' => 'workflow_cross_namespace_visibility_isolation',
                    ],
                    [
                        'from' => 'tenant-b',
                        'to' => 'tenant-a',
                        'surface' => 'workflow-control-plane',
                        'scenario' => 'workflow_cross_namespace_mutation_isolation',
                    ],
                    [
                        'from' => 'tenant-a',
                        'to' => 'shared',
                        'surface' => 'nexus',
                        'scenario' => 'nexus_explicit_cross_namespace_invocation',
                    ],
                    [
                        'from' => 'tenant-b',
                        'to' => 'shared',
                        'surface' => 'nexus',
                        'scenario' => 'nexus_explicit_cross_namespace_invocation',
                    ],
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'namespace_create_update_describe_and_list',
                'workflow_cross_namespace_visibility_isolation',
                'workflow_cross_namespace_mutation_isolation',
                'php_worker_task_queue_namespace_isolation',
                'cli_namespace_context_and_default_scope',
                'sdk_namespace_selection_parity',
                'search_attribute_schema_and_value_query_isolation',
                'schedule_namespace_isolation',
                'namespace_lifecycle_cleanup_and_recreate',
                'waterline_operator_namespace_visibility',
                'nexus_explicit_cross_namespace_invocation',
                'reserved_namespace_name_refusal',
                'result_record_and_product_finding_routing',
            ],
            'scenario_requirements' => [
                'published_artifact_install_only' => [
                    'evidence' => [
                        'server_image',
                        'cli_release',
                        'workflow_php_package',
                        'sdk_python_package',
                        'waterline_artifact',
                    ],
                ],
                'namespace_create_update_describe_and_list' => [
                    'evidence' => [
                        'created_namespaces',
                        'updated_namespace',
                        'described_namespaces',
                        'listed_namespaces',
                    ],
                ],
                'workflow_cross_namespace_visibility_isolation' => [
                    'evidence' => [
                        'tenant_a_workflow',
                        'tenant_b_workflow',
                        'tenant_a_list_excludes_tenant_b',
                        'tenant_b_describe_tenant_a_denied',
                    ],
                ],
                'workflow_cross_namespace_mutation_isolation' => [
                    'evidence' => [
                        'same_namespace_signal_succeeds',
                        'same_namespace_cancel_succeeds',
                        'cross_namespace_signal_denied',
                        'cross_namespace_cancel_denied',
                    ],
                ],
                'namespace_lifecycle_cleanup_and_recreate' => [
                    'evidence' => [
                        'deleted_namespace',
                        'workflow_cleanup',
                        'schedule_cleanup',
                        'search_attribute_cleanup',
                        'worker_registration_cleanup',
                        'recreate_state_empty',
                        'external_payload_contexts_checked',
                    ],
                    'cross_namespace_safety' => 'tenant-owned payload references must not be resolved or deleted through the wrong namespace storage context',
                ],
                'nexus_explicit_cross_namespace_invocation' => [
                    'evidence' => [
                        'service_endpoint_namespace',
                        'caller_namespaces',
                        'target_namespace',
                        'successful_results',
                        'direct_access_without_nexus_blocked',
                    ],
                ],
                'cli_namespace_context_and_default_scope' => [
                    'evidence' => [
                        'explicit_namespace_json',
                        'explicit_namespace_human_output',
                        'default_scope_behavior',
                    ],
                ],
                'sdk_namespace_selection_parity' => [
                    'evidence' => [
                        'python_client_namespace',
                        'php_client_namespace',
                        'default_namespace_behavior',
                        'cross_namespace_lookup_denied',
                    ],
                ],
                'php_worker_task_queue_namespace_isolation' => [
                    'evidence' => [
                        'tenant_a_worker_registration',
                        'tenant_b_worker_registration',
                        'tenant_a_delivery',
                        'tenant_b_delivery',
                        'cross_delivery_absent',
                    ],
                ],
                'waterline_operator_namespace_visibility' => [
                    'evidence' => [
                        'tenant_a_scoped_views',
                        'tenant_b_scoped_views',
                        'detail_namespace_identity',
                        'unscoped_view_authority',
                        'api_captures',
                    ],
                ],
                'search_attribute_schema_and_value_query_isolation' => [
                    'evidence' => [
                        'schema_isolation',
                        'value_query_isolation',
                        'tenant_a_value',
                        'tenant_b_observed_result',
                    ],
                ],
                'schedule_namespace_isolation' => [
                    'evidence' => [
                        'tenant_a_schedule',
                        'tenant_b_schedule',
                        'tenant_a_list_excludes_tenant_b',
                        'cross_namespace_schedule_mutation_denied',
                    ],
                ],
                'reserved_namespace_name_refusal' => [
                    'evidence' => [
                        'refused_names',
                        'typed_errors',
                        'valid_control_name_accepted',
                        'stored_namespace_names',
                    ],
                ],
                'result_record_and_product_finding_routing' => [
                    'evidence' => [
                        'artifact_versions_recorded',
                        'timestamps_recorded',
                        'outcome_recorded',
                        'finding_links_recorded',
                        'product_finding_routes_checked',
                    ],
                ],
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_namespaces_present',
                    'all_required_runtimes_present',
                    'published_artifact_install_reported',
                    'namespace_crud_behavior_reported',
                    'workflow_visibility_isolation_reported',
                    'workflow_mutation_isolation_reported',
                    'cli_namespace_behavior_reported',
                    'sdk_namespace_selection_reported',
                    'php_worker_behavior_reported',
                    'schedule_namespace_isolation_reported',
                    'waterline_operator_visibility_reported',
                    'search_attribute_value_query_isolation_reported',
                    'namespace_lifecycle_cleanup_reported',
                    'nexus_cross_namespace_behavior_reported',
                    'adversarial_namespace_name_refusal_reported',
                    'result_record_and_product_finding_routing_reported',
                    'required_run_metadata_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'scenario_specific_evidence_reported',
                    'artifact_versions_match_latest_published_set',
                    'no_local_product_source_artifacts',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'result_gate' => NamespaceRuntimeResultGate::spec(),
            'finding_policy' => [
                'cross_namespace_visibility_leak' => 'link_root_cause_finding_against_server',
                'cross_namespace_mutation_leak' => 'link_root_cause_finding_against_server',
                'worker_delivery_cross_namespace' => 'link_root_cause_finding_against_server',
                'namespace_cleanup_gap' => 'link_root_cause_finding_against_server',
                'nexus_dispatch_gap' => 'link_root_cause_finding_against_server',
                'cli_namespace_context_gap' => 'link_root_cause_finding_against_cli',
                'waterline_visibility_gap' => 'link_root_cause_finding_against_waterline',
                'sdk_namespace_parity_gap' => 'link_root_cause_finding_against_sdk_or_runtime',
                'search_attribute_value_leak' => 'link_root_cause_finding_against_server',
                'reserved_name_acceptance' => 'link_root_cause_finding_against_server',
                'unsupported_public_surface' => 'link_root_cause_finding_against_surface_owner',
            ],
        ];
    }
}
