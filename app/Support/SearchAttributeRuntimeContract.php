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

    public const VERSION = 16;

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
            'scenario_manifest' => [
                'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                'category' => 'search_attribute_runtime_contract',
                'public_path' => 'https://durable-workflow.com/platform-conformance/search-attribute-runtime-scenarios.json',
                'source_path' => 'static/platform-conformance/search-attribute-runtime-scenarios.json',
            ],
            'artifact_policy' => [
                'version_source' => 'latest_published_artifacts_at_run_time',
                'version_requirement' => 'concrete_published_versions_pinned_at_run_time',
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
                'install_channels' => [
                    'server' => 'docker image durableworkflow/server:<latest>',
                    'cli' => 'official dw install script pinned to its latest release tag',
                    'sdk-php' => 'Composer package durable-workflow/sdk:<latest>',
                    'workflow-php' => 'Embedded engine package in the exact published server image; never the standalone PHP client or worker',
                    'sdk-python' => 'PyPI package durable-workflow==<latest>',
                    'waterline' => 'published Waterline package or image matching the latest release tag',
                ],
                'forbidden_sources' => [
                    'local_product_source_checkout',
                    'workspace_repo_as_artifact_under_test',
                ],
                'required_run_record_fields' => [
                    'artifact_versions',
                    'run_id',
                    'started_at',
                    'finished_at',
                    'generated_at',
                    'outcome',
                    'runner_blocked',
                    'scenario_results',
                    'findings',
                    'finding_links',
                    'topology',
                    'query_verdicts',
                    'codec_round_trips',
                    'latency_distribution',
                    'load_profile',
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
                    'sdk-php',
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
                    'sdk-php',
                    'sdk-python',
                ],
                'client_paths' => [
                    'cli',
                    'sdk-php',
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
                        'worker' => 'sdk-php',
                        'clients' => ['cli', 'sdk-php'],
                        'scenario' => 'php_worker_start_and_upsert_visibility',
                    ],
                ],
                'cross_language_cells' => [
                    [
                        'writer' => 'sdk-python',
                        'readers' => ['sdk-php', 'cli'],
                        'scenario' => 'python_to_php_codec_round_trip',
                    ],
                    [
                        'writer' => 'sdk-php',
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
                'python_to_php_codec_round_trip' => [
                    'writer' => 'sdk-python',
                    'required_readers' => [
                        'sdk-php',
                        'cli',
                    ],
                    'required_value_types' => [
                        'string',
                        'int',
                        'double',
                        'bool',
                        'datetime',
                        'keyword',
                        'keyword_list',
                    ],
                    'payload_context_fields' => [
                        'encoded_payload',
                        'wire_value_context',
                    ],
                    'required_evidence_fields' => [
                        'written_attributes',
                        'decoded_attributes',
                        'reader_verifications',
                    ],
                ],
                'php_to_python_codec_round_trip' => [
                    'writer' => 'sdk-php',
                    'required_readers' => [
                        'sdk-python',
                        'cli',
                    ],
                    'required_value_types' => [
                        'string',
                        'int',
                        'double',
                        'bool',
                        'datetime',
                        'keyword',
                        'keyword_list',
                    ],
                    'payload_context_fields' => [
                        'encoded_payload',
                        'wire_value_context',
                    ],
                    'required_evidence_fields' => [
                        'written_attributes',
                        'decoded_attributes',
                        'reader_verifications',
                    ],
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
                    'documented_bound_compared_fields' => [
                        'p95_ms',
                        'max_ms',
                    ],
                    'required_evidence_fields' => [
                        'consistency_contract',
                        'observed_bounds',
                        'public_observation_surfaces',
                    ],
                    'required_observed_bound_fields' => [
                        'documented_bound_ms',
                        'p95_ms',
                        'max_ms',
                    ],
                ],
                'load_and_bounded_latency' => [
                    'minimum_workflow_count' => 1000,
                    'required_distribution_fields' => [
                        'p50_ms',
                        'p95_ms',
                        'max_ms',
                    ],
                    'required_query_latency_classes' => [
                        'equality',
                        'range',
                        'bool',
                        'keyword_list',
                    ],
                    'required_query_latency_fields' => [
                        'p50_ms',
                        'p95_ms',
                        'max_ms',
                    ],
                    'required_evidence_fields' => [
                        'consistency_contract',
                        'observed_bounds',
                        'public_observation_surfaces',
                    ],
                    'required_observed_bound_fields' => [
                        'workflow_count',
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
                    'required_rejection_fields' => [
                        'status_code',
                        'response_body',
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
                'cli_query_and_error_surface' => [
                    'required_queries' => [
                        'equality' => 'customer_id = "cust-7"',
                        'range' => 'order_total_cents > 5000 AND order_total_cents <= 10000',
                        'bool' => 'is_vip = true',
                        'or' => 'customer_id = "cust-2" OR customer_id = "cust-8"',
                        'not' => 'priority_tier IN ("gold","platinum") AND NOT is_vip',
                        'keyword_list' => 'tags = "urgent"',
                    ],
                    'required_definition_commands' => [
                        'list',
                        'create',
                        'delete',
                    ],
                    'required_diagnostics' => [
                        'wrong_literal' => 'order_total_cents = "not-a-number"',
                        'injection' => 'customer_id = "x" OR 1=1',
                    ],
                    'command_transcript_required_fields' => [
                        'command',
                        'arguments',
                        'stdout',
                        'stderr',
                        'exit_code',
                    ],
                    'query_count_fields' => [
                        'expected_count',
                        'actual_count',
                    ],
                    'diagnostic_required_fields' => [
                        'command',
                        'arguments',
                        'stdout',
                        'stderr',
                        'exit_code',
                        'error_code',
                        'message',
                    ],
                    'diagnostic_must_not_be_transport_failure' => true,
                    'cli_mismatch_finding_required_fields' => [
                        'command',
                        'arguments',
                        'stdout',
                        'stderr',
                        'exit_code',
                        'artifact_versions',
                        'expected_server_response',
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
                    'codec_round_trips_include_encoded_payload_or_wire_value_context',
                    'codec_round_trips_compare_written_or_wire_values_to_decoded_attributes',
                    'load_latency_reported',
                    'indexing_latency_p95_and_max_compared_to_documented_bound',
                    'load_latency_reported_per_query_class',
                    'latency_and_load_evidence_names_consistency_contract',
                    'latency_and_load_evidence_records_public_observation_surfaces',
                    'latency_and_load_evidence_records_run_id_and_observed_bounds',
                    'or_not_grammar_reported_with_exact_query_counts_and_public_surface',
                    'query_injection_hardening_reported_with_status_and_response_body',
                    'artifact_versions_match_latest_published_set',
                    'run_timestamps_outcome_and_finding_links_are_recorded',
                    'declared_outcome_matches_evaluated_status',
                    'no_local_product_source_artifacts',
                    'runner_blocked_false_for_product_evidence',
                    'findings_linked_for_non_pass_scenarios',
                ],
                'uncovered_required_scenario_outcome' => 'non_passing',
                'smoke_subset_outcome' => 'non_passing',
                'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                'runner_blocked_outcome' => 'non_passing_runner_blocked',
            ],
            'host_runner_contract' => [
                'status' => 'required_for_passing_search_attributes_conformance',
                'runner_repository' => 'server',
                'runner_path' => 'scripts/conformance/search-attributes-published-artifacts.sh',
                'runner_command' => 'scripts/conformance/search-attributes-published-artifacts.sh --result-dir <result-dir>',
                'result_schema' => self::RESULT_SCHEMA,
                'result_files' => [
                    'pins.json',
                    'run-metadata.json',
                    'artifact-install-evidence.json',
                    'sdk-php-search-attributes-shard.json',
                    'waterline-search-attributes-shard.json',
                    'codec-round-trip-shard.json',
                    'search-attributes-result.json',
                    'search-attributes-record.json',
                ],
                'must_execute_against_published_artifacts' => true,
                'must_record_runner_blocked_false_for_product_evidence' => true,
                'must_emit_result_for_every_required_scenario' => true,
                'smoke_summary_only_outcome' => 'non_passing',
                'unexecuted_required_scenario_status' => 'not_covered',
                'coverage_gap_finding_type' => 'conformance_runner_coverage_gap',
                'coverage_gap_owner' => 'conformance_harness',
                'required_execution_scopes' => [
                    'published-artifact-install',
                    'server-python-search-attribute-smoke',
                    'sdk-php-search-attribute-shard',
                    'cli-search-attribute-surface-shard',
                    'waterline-operator-search-attribute-shard',
                    'cross-language-codec-shard',
                    'latency-and-load-shard',
                    'adversarial-query-shard',
                ],
                'runtime_shards' => [
                    'waterline' => [
                        'scope' => 'waterline-operator-search-attribute-shard',
                        'artifact' => 'durable-workflow/waterline',
                        'artisan_command' => 'waterline:search-attributes-conformance',
                        'must_cover_scenarios' => [
                            'waterline_operator_visibility',
                        ],
                        'must_cover_surfaces' => [
                            'workflow_list_search_attribute_filter',
                            'keyword_list_search_attribute_filter',
                            'selected_run_search_attributes',
                            'saved_filter_round_trip',
                            'namespace_scoped_visibility',
                        ],
                        'must_capture_fields' => [
                            'workflow_list_filter.expected_count',
                            'workflow_list_filter.actual_count',
                            'selected_run_detail.expected_search_attributes',
                            'selected_run_detail.actual_search_attributes',
                            'saved_filter_state.stored_filters',
                            'saved_filter_state.retrieved_filters',
                            'namespace_isolation.tenant_a_filter_actual_run_ids',
                            'namespace_isolation.tenant_b_filter_actual_run_ids',
                            'api_captures',
                        ],
                        'fallback_status_when_command_missing' => 'unsupported',
                        'fallback_status_when_surface_missing' => 'not_covered',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                    'codec' => [
                        'scope' => 'cross-language-codec-shard',
                        'artifacts' => [
                            'durable-workflow/sdk',
                            'durable-workflow',
                            'dw',
                        ],
                        'input_environment' => [
                            'DW_SEARCH_ATTRIBUTES_CODEC_SHARD_FILE',
                            'DW_SEARCH_ATTRIBUTES_CODEC_SHARD_JSON',
                        ],
                        'result_file' => 'codec-round-trip-shard.json',
                        'must_cover_scenarios' => [
                            'python_to_php_codec_round_trip',
                            'php_to_python_codec_round_trip',
                        ],
                        'must_capture_fields' => [
                            'python_to_php.written_attributes',
                            'python_to_php.decoded_attributes',
                            'python_to_php.reader_verifications.sdk-php',
                            'python_to_php.reader_verifications.cli',
                            'python_to_php.encoded_payload_or_wire_value_context',
                            'php_to_python.written_attributes',
                            'php_to_python.decoded_attributes',
                            'php_to_python.reader_verifications.sdk-python',
                            'php_to_python.reader_verifications.cli',
                            'php_to_python.encoded_payload_or_wire_value_context',
                        ],
                        'required_value_types' => [
                            'string',
                            'int',
                            'double',
                            'bool',
                            'datetime',
                            'keyword',
                            'keyword_list',
                        ],
                        'fallback_status_when_surface_missing' => 'not_covered',
                        'fallback_finding_type' => 'conformance_runner_coverage_gap',
                    ],
                    'sdk-php' => [
                        'scope' => 'sdk-php-search-attribute-shard',
                        'artifact' => 'durable-workflow/sdk',
                        'runner_path' => 'scripts/conformance/php-sdk-published-artifacts.sh',
                        'runner_command' => 'scripts/conformance/php-sdk-published-artifacts.sh --scope search-attributes --result-dir <result-dir>',
                        'result_file' => 'sdk-php-search-attributes-shard.json',
                        'required_environment' => [
                            'DW_PHP_SDK_VERSION',
                            'DW_SERVER_VERSION',
                            'DW_SERVER_IMAGE',
                            'DW_PHP_SDK_CONFORMANCE_SERVER_URL',
                            'DW_PHP_SDK_CONFORMANCE_NAMESPACE',
                        ],
                        'package_ownership' => [
                            'standalone_connectivity' => 'durable-workflow/sdk',
                            'embedded_engine' => 'durable-workflow/workflow',
                            'workflow_standalone_client_or_worker_loaded' => false,
                        ],
                        'must_cover_scenarios' => [
                            'php_worker_start_and_upsert_visibility',
                            'python_to_php_codec_round_trip',
                            'php_to_python_codec_round_trip',
                        ],
                        'must_capture_fields' => [
                            'workflow_id',
                            'run_id',
                            'worker_runtime',
                            'start_search_attributes',
                            'upserted_search_attributes',
                            'expected_search_attributes',
                            'actual_search_attributes',
                            'typed_values',
                            'visibility_query_match',
                            'query_visibility',
                            'namespace_isolation',
                            'codec_round_trips.python_to_php',
                            'codec_round_trips.php_to_python',
                            'package_ownership',
                        ],
                        'python_writer_fixture_environment' => 'DW_PHP_SDK_SEARCH_ATTRIBUTES_PYTHON_FIXTURE_JSON',
                        'php_writer_handoff_fields' => [
                            'codec_round_trips.php_to_python.namespace',
                            'codec_round_trips.php_to_python.workflow_id',
                            'codec_round_trips.php_to_python.written_attributes',
                            'codec_round_trips.php_to_python.query',
                        ],
                        'bounded_evidence' => [
                            'matched_workflow_ids_max_items' => 20,
                            'retained_diagnostic_excerpt_max_bytes' => 4096,
                        ],
                        'fallback_status_when_command_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                    'cli' => [
                        'scope' => 'cli-search-attribute-surface-shard',
                        'artifact' => 'dw',
                        'must_cover_scenarios' => [
                            'cli_query_and_error_surface',
                        ],
                        'must_capture_fields' => [
                            'workflow_list_queries',
                            'search_attribute_commands',
                            'diagnostics',
                        ],
                        'fallback_status_when_command_missing' => 'unsupported',
                        'fallback_finding_type' => 'unsupported_public_surface',
                    ],
                ],
                'merge_policy' => [
                    'requires_waterline_operator_surface_matrix' => true,
                    'waterline_pass_scenario' => 'waterline_operator_visibility',
                    'waterline_evidence_section' => 'waterline_operator_visibility',
                    'requires_sections' => [
                        'topology',
                        'query_verdicts',
                        'cli_surface',
                        'waterline_operator_visibility',
                        'codec_round_trips',
                        'latency_distribution',
                        'load_profile',
                        'type_safety_errors',
                        'adversarial_queries',
                        'namespace_isolation',
                    ],
                ],
                'routing_policy' => [
                    'waterline_shard_not_invoked' => [
                        'scenario_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'waterline_operator_mismatch' => [
                        'scenario_status' => 'fail',
                        'finding_type' => 'operator_visibility_gap',
                        'owner' => 'waterline',
                    ],
                    'host_environment_failure' => [
                        'scenario_status' => 'runner_blocked',
                        'finding_type' => 'runner_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'sdk_php_shard_not_invoked' => [
                        'scenario_status' => 'not_covered',
                        'finding_type' => 'conformance_runner_coverage_gap',
                        'owner' => 'conformance_harness',
                    ],
                    'sdk_php_product_mismatch' => [
                        'scenario_status' => 'fail',
                        'finding_owner_from_shard' => true,
                        'allowed_owners' => ['sdk-php', 'sdk-php-release', 'server'],
                    ],
                ],
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
                'cli_surface_uncovered_by_runner' => 'link_root_cause_finding_against_conformance_harness',
                'waterline_operator_visibility_uncovered_by_runner' => 'link_root_cause_finding_against_conformance_harness',
                'documentation_gap' => 'link_root_cause_finding_against_docs',
            ],
        ];
    }
}
