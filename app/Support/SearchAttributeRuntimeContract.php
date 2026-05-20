<?php

namespace App\Support;

use Workflow\V2\Support\PlatformConformanceSuite;

/**
 * Machine-readable contract for the live search-attributes conformance run.
 *
 * Search-attribute smoke coverage proves the Python/server happy path. This
 * manifest names the full parity matrix needed before a published-artifact
 * result can claim that search attributes are an honest operator query
 * surface across runtimes, CLI, Waterline, codecs, and adversarial queries.
 */
final class SearchAttributeRuntimeContract
{
    public const SCHEMA = 'durable-workflow.v2.search-attribute-runtime.contract';

    public const VERSION = 1;

    public const RESULT_SCHEMA = 'durable-workflow.v2.search-attribute-runtime.result';

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
            'fixture_category' => 'search_attribute_runtime_contract',
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
                    'topology',
                    'query_verdicts',
                    'latency_distribution',
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
                'namespaces' => [
                    'primary' => 'sa-test',
                    'isolation_peer' => 'sa-test-b',
                ],
                'schema_keys' => [
                    'customer_id' => 'string',
                    'order_total_cents' => 'int',
                    'discount_ratio' => 'double',
                    'priority_tier' => 'keyword',
                    'is_vip' => 'bool',
                    'created_at' => 'datetime',
                    'tags' => 'keyword_list',
                ],
                'required_workers' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'required_operator_surfaces' => [
                    'cli workflow:list --query',
                    'cli search-attribute:list/create/delete',
                    'waterline workflow list filter',
                    'waterline selected run detail',
                ],
            ],
            'required_matrix' => [
                'runtimes' => [
                    'workflow-php',
                    'sdk-python',
                ],
                'client_paths' => [
                    'cli',
                    'workflow-php-sdk',
                    'sdk-python',
                ],
                'observer_paths' => [
                    'waterline-workflow-list-filter',
                    'waterline-selected-run-detail',
                    'waterline-saved-filter',
                ],
                'runtime_cells' => [
                    [
                        'worker' => 'sdk-python',
                        'clients' => ['cli', 'sdk-python'],
                        'scenario' => 'python_worker_start_and_upsert_visibility',
                    ],
                    [
                        'worker' => 'workflow-php',
                        'clients' => ['cli', 'workflow-php-sdk'],
                        'scenario' => 'php_worker_start_and_upsert_visibility',
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'writer' => 'sdk-python',
                        'readers' => ['workflow-php-sdk', 'cli'],
                        'scenario' => 'python_to_php_codec_round_trip',
                    ],
                    [
                        'writer' => 'workflow-php',
                        'readers' => ['sdk-python', 'cli'],
                        'scenario' => 'php_to_python_codec_round_trip',
                    ],
                ],
                'type_cells' => [
                    'string',
                    'int',
                    'double',
                    'bool',
                    'datetime',
                    'keyword',
                    'keyword_list',
                ],
            ],
            'required_scenarios' => [
                'published_artifact_install_only',
                'schema_definition_and_reserved_name_refusal',
                'python_worker_start_and_upsert_visibility',
                'php_worker_start_and_upsert_visibility',
                'cli_query_and_error_surface',
                'waterline_operator_visibility',
                'python_to_php_codec_round_trip',
                'php_to_python_codec_round_trip',
                'equality_range_bool_query_behavior',
                'or_not_query_grammar',
                'keyword_list_membership',
                'type_safety_wrong_literal',
                'undefined_key_rejection',
                'indexing_latency_distribution',
                'load_and_bounded_latency',
                'namespace_isolation',
                'query_injection_hardening',
            ],
            'scenario_requirements' => [
                'schema_definition_and_reserved_name_refusal' => [
                    'required_types' => [
                        'string',
                        'int',
                        'double',
                        'bool',
                        'datetime',
                        'keyword',
                        'keyword_list',
                    ],
                    'reserved_name_refusals' => [
                        'wf_id',
                        '__internal',
                    ],
                ],
                'equality_range_bool_query_behavior' => [
                    'required_queries' => [
                        'customer_id = "cust-7"',
                        'order_total_cents > 5000 AND order_total_cents <= 10000',
                        'is_vip = true',
                    ],
                    'expected_actual_count_required' => true,
                ],
                'or_not_query_grammar' => [
                    'required_queries' => [
                        'priority_tier IN ("gold","platinum") AND NOT is_vip',
                        'customer_id = "cust-2" OR customer_id = "cust-8"',
                    ],
                    'expected_actual_count_required' => true,
                ],
                'keyword_list_membership' => [
                    'required_query' => 'tags = "urgent"',
                    'list_ordering_must_not_affect_match' => true,
                ],
                'indexing_latency_distribution' => [
                    'sample_count_minimum' => 20,
                    'required_distribution_fields' => [
                        'min_ms',
                        'p50_ms',
                        'p95_ms',
                        'max_ms',
                    ],
                    'documented_bound_required' => true,
                ],
                'load_and_bounded_latency' => [
                    'minimum_workflow_count' => 1000,
                    'required_distribution_fields' => [
                        'p50_ms',
                        'p95_ms',
                        'max_ms',
                    ],
                ],
                'query_injection_hardening' => [
                    'required_rejections' => [
                        'OR 1=1',
                        'embedded SQL comment',
                        'shell metacharacters',
                    ],
                    'partial_execution_allowed' => false,
                ],
                'waterline_operator_visibility' => [
                    'required_surfaces' => [
                        'workflow list search-attribute filter',
                        'selected run search attributes',
                        'saved view filter state',
                    ],
                ],
            ],
            'coverage_gate' => [
                'passing_outcome_requires' => [
                    'all_required_scenarios_reported',
                    'all_required_runtimes_present',
                    'runtime_cells_reported',
                    'cross_language_cells_reported',
                    'cli_surface_reported',
                    'waterline_operator_visibility_reported',
                    'codec_round_trips_reported',
                    'load_latency_reported',
                    'or_not_grammar_reported',
                    'query_injection_hardening_reported',
                    'artifact_versions_match_latest_published_set',
                    'run_timestamps_outcome_and_finding_links_are_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'no_local_product_source_artifacts',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'result_gate' => SearchAttributeRuntimeResultGate::spec(),
            'finding_policy' => [
                'silent_over_return' => 'link_root_cause_finding_against_server',
                'visibility_staleness' => 'link_root_cause_finding_against_server',
                'type_mismatch_coercion' => 'link_root_cause_finding_against_server_query_parser',
                'undefined_key_accepted' => 'link_root_cause_finding_against_server',
                'cross_language_value_drift' => 'link_root_cause_finding_against_codec_or_sdk_owner',
                'cli_error_surface_gap' => 'link_root_cause_finding_against_cli',
                'waterline_observer_mismatch' => 'link_root_cause_finding_against_waterline',
                'query_injection_accepted' => 'link_root_cause_security_finding_against_server',
                'unsupported_public_surface' => 'link_root_cause_finding_against_surface_owner',
                'documentation_gap' => 'link_root_cause_finding_against_docs',
            ],
        ];
    }
}
