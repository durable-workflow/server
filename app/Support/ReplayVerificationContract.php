<?php

namespace App\Support;

/**
 * Platform-level contract describing how operators and CI runners verify
 * exported workflow histories offline.
 *
 * The contract names: the bundle envelope this server emits, the offline
 * CLI Artisan command that runtimes ship, the integrity surface (canonical
 * hashing and signature support), the structural rules the verifier
 * enforces, and the replay-diff reasons / verdicts that decide whether a
 * promotion or rollout should proceed.
 *
 * This is a stable consumer surface: changing the schema or removing
 * verdicts/reasons is a breaking change and requires a `version` bump.
 *
 * The schema strings published here are the canonical values produced by
 * the workflow runtime. They are inlined rather than referenced from the
 * workflow package so the server can publish the contract independently
 * of the runtime's release cadence.
 */
final class ReplayVerificationContract
{
    public const SCHEMA = 'durable-workflow.v2.replay-verification.contract';

    public const VERSION = 4;

    public const BUNDLE_SCHEMA = 'durable-workflow.v2.history-export';

    public const BUNDLE_SCHEMA_VERSION = 2;

    public const INTEGRITY_REPORT_SCHEMA = 'durable-workflow.v2.history-bundle-verification';

    public const INTEGRITY_REPORT_SCHEMA_VERSION = 1;

    public const REPLAY_DIFF_SCHEMA = 'durable-workflow.v2.replay-diff';

    public const REPLAY_DIFF_SCHEMA_VERSION = 1;

    public const VERIFICATION_REPORT_SCHEMA = 'durable-workflow.v2.replay-verification.report';

    public const VERIFICATION_REPORT_SCHEMA_VERSION = 1;

    public const SIMULATION_REPORT_SCHEMA = 'durable-workflow.v2.replay-simulation.report';

    public const SIMULATION_REPORT_SCHEMA_VERSION = 1;

    public const GOLDEN_HISTORY_FIXTURE_SCHEMA = 'durable-workflow.golden-history.v1';

    public const REPLAY_CONFORMANCE_RESULT_SCHEMA = 'durable-workflow.v2.replay-conformance.result';

    public const REPLAY_CONFORMANCE_RESULT_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'bundle' => [
                'schema' => self::BUNDLE_SCHEMA,
                'schema_version' => self::BUNDLE_SCHEMA_VERSION,
                'export_endpoint' => 'GET /api/namespaces/{namespace}/workflows/{workflowId}/runs/{runId}/history/export',
                'export_command' => 'workflow:v2:history-export',
            ],
            'offline_cli' => [
                'command' => 'workflow:v2:replay-verify',
                'inputs' => [
                    'bundle' => 'Path to a history-export JSON bundle.',
                    '--signing-key' => 'HMAC verification key; falls back to workflows.v2.history_export.signing_key.',
                    '--skip-replay' => 'Verify integrity only — do not replay against current code.',
                    '--strict-warnings' => 'Treat structural warnings as failures.',
                    '--json' => 'Emit the report as a single JSON document on stdout.',
                    '--output' => 'Write the JSON report to a file.',
                ],
                'exit_codes' => [
                    'ok' => 0,
                    'warning' => 0,
                    'warning_strict' => 1,
                    'drifted' => 1,
                    'failed' => 1,
                ],
            ],
            'batch_cli' => [
                'command' => 'workflow:v2:replay-simulate',
                'description' => 'Replay-verify every bundle in a directory and emit one CI/agent-friendly promotion verdict.',
                'inputs' => [
                    'directory' => 'Directory of workflow:v2:history-export bundles to verify together.',
                    '--signing-key' => 'HMAC verification key; falls back to workflows.v2.history_export.signing_key.',
                    '--skip-replay' => 'Verify integrity only — do not replay against current code.',
                    '--strict-warnings' => 'Treat structural warnings as per-bundle failures and block promotion.',
                    '--json' => 'Emit the report as a single JSON document on stdout.',
                    '--output' => 'Write the JSON simulation report to a file.',
                ],
                'exit_codes' => [
                    'ok' => 0,
                    'warning' => 0,
                    'drifted' => 1,
                    'failed' => 1,
                ],
                'report_schema' => self::SIMULATION_REPORT_SCHEMA,
                'report_schema_version' => self::SIMULATION_REPORT_SCHEMA_VERSION,
            ],
            'integrity' => [
                'canonicalization' => 'json-recursive-ksort-v1',
                'checksum_algorithm' => 'sha256',
                'signature_algorithms' => ['hmac-sha256'],
                'signing_key_config' => 'workflows.v2.history_export.signing_key',
                'signing_key_id_config' => 'workflows.v2.history_export.signing_key_id',
            ],
            'integrity_report' => [
                'schema' => self::INTEGRITY_REPORT_SCHEMA,
                'schema_version' => self::INTEGRITY_REPORT_SCHEMA_VERSION,
                'statuses' => ['ok', 'warning', 'failed'],
                'severities' => ['info', 'warning', 'error'],
                'finding_fields' => [
                    'rule',
                    'severity',
                    'message',
                    'path',
                    'context',
                ],
                'rules' => [
                    'bundle.schema_missing',
                    'bundle.schema_unexpected',
                    'bundle.schema_version_missing',
                    'bundle.schema_version_unsupported',
                    'bundle.exported_at_missing',
                    'bundle.section_missing',
                    'bundle.section_invalid',
                    'bundle.unparseable',
                    'workflow.run_id_missing',
                    'workflow.instance_id_missing',
                    'workflow.workflow_type_missing',
                    'workflow.last_history_sequence_stale',
                    'history_events.entry_invalid',
                    'history_events.sequence_missing',
                    'history_events.sequence_not_monotonic',
                    'history_events.type_missing',
                    'history_events.id_duplicate',
                    'commands.id_missing',
                    'commands.history_event_missing',
                    'payload_manifest.codec_missing',
                    'payload_manifest.payload_missing',
                    'payload_manifest.avro_framing_missing',
                    'payload_manifest.writer_schema_fingerprint_mismatch',
                    'codec_schemas.value_schema_missing',
                    'codec_schemas.value_schema_drift',
                    'redaction.empty_paths',
                    'integrity.missing',
                    'integrity.canonicalization_unsupported',
                    'integrity.canonicalization_failed',
                    'integrity.checksum_algorithm_unsupported',
                    'integrity.checksum_missing',
                    'integrity.checksum_mismatch',
                    'integrity.signature_algorithm_unsupported',
                    'integrity.signature_missing',
                    'integrity.signature_mismatch',
                    'integrity.signature_key_unavailable',
                ],
            ],
            'replay_diff' => [
                'schema' => self::REPLAY_DIFF_SCHEMA,
                'schema_version' => self::REPLAY_DIFF_SCHEMA_VERSION,
                'statuses' => ['replayed', 'drifted', 'failed'],
                'reasons' => [
                    'none',
                    'shape_mismatch',
                    'replay_error',
                    'bundle_invalid',
                ],
                'shape_mismatch_fields' => [
                    'workflow_sequence',
                    'expected_shape',
                    'recorded_event_types',
                ],
            ],
            'verification_report' => [
                'schema' => self::VERIFICATION_REPORT_SCHEMA,
                'schema_version' => self::VERIFICATION_REPORT_SCHEMA_VERSION,
                'fields' => [
                    'verdict',
                    'promotion_decision',
                    'evidence',
                    'bundle_path',
                    'integrity',
                    'replay_diff',
                ],
                'evidence_fields' => [
                    'integrity_checked',
                    'integrity_status',
                    'integrity_finding_count',
                    'replay_checked',
                    'replay_status',
                    'replay_skipped',
                    'strict_warnings',
                ],
                'verdicts' => ['ok', 'warning', 'drifted', 'failed'],
            ],
            'simulation_report' => [
                'schema' => self::SIMULATION_REPORT_SCHEMA,
                'schema_version' => self::SIMULATION_REPORT_SCHEMA_VERSION,
                'description' => 'Aggregate report emitted by workflow:v2:replay-simulate; every per-bundle entry carries its own verdict + promotion_decision and the top-level verdict reduces to the strictest entry.',
                'fields' => [
                    'verdict',
                    'promotion_decision',
                    'evidence',
                    'summary',
                    'bundles',
                    'missing_bundles',
                ],
                'evidence_fields' => [
                    'bundle_count',
                    'missing_bundle_count',
                    'integrity_checked_count',
                    'replay_checked_count',
                    'replay_skipped',
                    'strict_warnings',
                ],
                'aggregation_rule' => 'strictest_verdict_wins',
                'verdicts' => ['ok', 'warning', 'drifted', 'failed'],
            ],
            'promotion_gate' => [
                'description' => 'Server-side helper App\\Support\\ReplayPromotionGate consumes either a verify or simulation report and returns a normalized gate decision (pass / review / block).',
                'gate_statuses' => ['pass', 'review', 'block'],
                'evidence_policy' => 'Known v1 verify and simulation reports must include the evidence block; a clean verdict with missing, incomplete, or intentionally skipped replay evidence is downgraded before promotion.',
                'verdict_to_gate_status' => [
                    'ok' => 'pass',
                    'warning' => 'review',
                    'drifted' => 'block',
                    'failed' => 'block',
                ],
            ],
            'verdicts' => [
                'ok' => [
                    'meaning' => 'Bundle integrity holds and current code replays the recorded history without drift.',
                    'promotion_decision' => 'safe_to_promote',
                ],
                'warning' => [
                    'meaning' => 'Structural advisories that do not block replay; review before broad rollout.',
                    'promotion_decision' => 'review_before_promote',
                ],
                'drifted' => [
                    'meaning' => 'Current code yields a different workflow step shape than the recorded history.',
                    'promotion_decision' => 'block_until_compatible',
                ],
                'failed' => [
                    'meaning' => 'Bundle integrity does not hold or replay raised an unexpected error.',
                    'promotion_decision' => 'block_and_investigate',
                ],
            ],
            'golden_history' => [
                'fixture_schema' => self::GOLDEN_HISTORY_FIXTURE_SCHEMA,
                'required_families' => [
                    'activity',
                    'saga-compensation',
                    'signal-update',
                    'version-marker',
                    'wait-condition',
                ],
                'official_runtimes' => [
                    'workflow-php',
                    'sdk-python',
                ],
            ],
            'replay_conformance' => [
                'result_schema' => self::REPLAY_CONFORMANCE_RESULT_SCHEMA,
                'result_version' => self::REPLAY_CONFORMANCE_RESULT_VERSION,
                'fixture_category' => 'history_replay_bundles',
                'scenario_manifest' => [
                    'schema' => 'durable-workflow.v2.platform-conformance.runtime-scenarios',
                    'category' => 'history_replay_bundles',
                    'public_path' => 'https://durable-workflow.github.io/platform-conformance/replay-runtime-scenarios.json',
                    'source_repository' => 'durable-workflow.github.io',
                    'source_path' => 'static/platform-conformance/replay-runtime-scenarios.json',
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
                        'workflow-php' => 'Composer package durable-workflow/workflow:2.0.0-alpha.<latest>',
                        'sdk-python' => 'PyPI package durable-workflow==<latest>',
                        'sdk-rust' => 'crates.io package durable-workflow@<latest>',
                    ],
                    'required_artifact_versions' => [
                        'server',
                        'cli',
                        'sdk-php',
                        'workflow-php',
                        'sdk-python',
                        'sdk-rust',
                        'waterline',
                    ],
                    'forbidden_sources' => [
                        'local_product_source_checkout',
                        'workspace_repo_as_artifact_under_test',
                    ],
                    'required_run_record_fields' => [
                        'artifact_versions',
                        'started_at',
                        'finished_at',
                        'outcome',
                        'scenario_results',
                        'findings',
                        'finding_links',
                    ],
                ],
                'required_runtimes' => [
                    'sdk-php',
                    'sdk-python',
                    'sdk-rust',
                ],
                'scenario_statuses' => [
                    'pass',
                    'fail',
                    'unsupported',
                    'not_covered',
                    'runner_blocked',
                ],
                'required_scenarios' => self::replayRequiredScenarios(),
                'required_matrix' => [
                    'runtime_scope' => 'each_required_runtime',
                    'completed_history_families' => [
                        'activity',
                        'signal-update',
                        'wait-condition',
                        'version-marker',
                        'saga-compensation',
                    ],
                    'restart_scenarios' => [
                        'completed_history_query_after_worker_restart',
                        'activity_state_query_after_worker_restart',
                        'signal_update_state_query_after_worker_restart',
                        'wait_condition_state_after_worker_restart',
                        'version_marker_state_after_worker_restart',
                        'saga_compensation_state_after_worker_restart',
                    ],
                    'adversarial_scenarios' => [
                        'code_divergence_refusal',
                        'server_history_mutation_refusal',
                        'malformed_history_refusal',
                        'in_flight_signal_restart_timing',
                    ],
                    'runtime_specific_scenarios' => [
                        'sdk-rust' => [
                            'side_effect_replay_after_worker_restart',
                            'version_marker_replay_after_code_upgrade',
                        ],
                    ],
                ],
                'host_runner_contract' => [
                    'status' => 'required_for_passing_replay_conformance',
                    'runner_repository' => 'server',
                    'runner_path' => 'scripts/conformance/replay-published-artifacts.sh',
                    'runner_command' => 'scripts/conformance/replay-published-artifacts.sh --result-dir <result-dir>',
                    'result_schema' => self::REPLAY_CONFORMANCE_RESULT_SCHEMA,
                    'portable_result_contract' => [
                        'schema' => 'durable-workflow.v1.portable-native-evidence',
                        'runner_max_bytes' => 4 * 1024 * 1024,
                        'projection_target_bytes' => 3 * 1024 * 1024,
                        'host_consumer_max_bytes' => 4 * 1024 * 1024,
                        'required_top_level_fields' => [
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
                        ],
                        'required_scenario_status_source' => 'required_scenarios',
                        'exact_distribution_identity_field' => 'executed_distribution_identities',
                        'native_evidence_failure_classification' => [
                            'oversized' => 'runner_infrastructure_failure',
                            'malformed' => 'runner_infrastructure_failure',
                            'incomplete' => 'runner_infrastructure_failure',
                        ],
                        'product_assertions' => 'fail_closed_before_projection',
                        'sensitive_values' => 'omitted',
                        'unbounded_payloads' => 'sha256_summary_without_payload_bytes',
                    ],
                    'result_files' => [
                        'pins.json',
                        'run-metadata.json',
                        'published-artifact-install.json',
                        'python-replay-shard.json',
                        'php-replay-shard.json',
                        'rust-replay-shard.json',
                        'replay-conformance-result.json',
                        'replay-conformance-record.json',
                    ],
                    'must_execute_against_published_artifacts' => true,
                    'must_record_runner_blocked_false_for_product_evidence' => true,
                    'must_probe_runtime_published_surfaces' => true,
                    'must_emit_result_for_every_required_scenario' => true,
                    'must_compose_runtime_shards' => [
                        'sdk-php-runtime-shard',
                        'sdk-python-runtime-shard',
                        'sdk-rust-runtime-shard',
                    ],
                    'smoke_summary_only_outcome' => 'non_passing',
                    'unexecuted_required_scenario_status' => 'not_covered',
                    'runtime_shards' => [
                        'sdk-php' => [
                            'scope' => 'sdk-php-runtime-shard',
                            'preferred_command' => 'scripts/conformance/php-sdk-published-artifacts.sh',
                            'fallback_status_when_command_missing' => 'unsupported',
                            'fallback_finding_type' => 'unsupported_public_surface',
                        ],
                        'sdk-python' => [
                            'scope' => 'sdk-python-runtime-shard',
                            'completed_history_surface' => 'durable-workflow-replay-verify',
                            'worker_restart_surface' => 'live_worker_query_replay',
                            'fallback_status_when_surface_missing' => 'unsupported',
                            'fallback_finding_type' => 'unsupported_public_surface',
                        ],
                        'sdk-rust' => [
                            'scope' => 'sdk-rust-runtime-shard',
                            'completed_history_surface' => 'durable-workflow-replay-conformance',
                            'worker_restart_surface' => 'side_effect_and_version_marker_cold_replay',
                            'fallback_status_when_surface_missing' => 'unsupported',
                            'fallback_finding_type' => 'unsupported_public_surface',
                        ],
                    ],
                    'merge_policy' => [
                        'input_scopes' => [
                            'sdk-php-runtime-shard',
                            'sdk-python-runtime-shard',
                            'sdk-rust-runtime-shard',
                            'live-server-replay-smoke',
                        ],
                        'output_schema' => self::REPLAY_CONFORMANCE_RESULT_SCHEMA,
                        'requires_required_runtimes' => [
                            'sdk-php',
                            'sdk-python',
                            'sdk-rust',
                        ],
                        'requires_sections' => [
                            'completed_history_replay',
                            'worker_restart_replay',
                            'adversarial_replay',
                            'in_flight_timing',
                        ],
                    ],
                    'required_execution_scopes' => [
                        'published-artifact-install',
                        'sdk-php-runtime-shard',
                        'sdk-python-runtime-shard',
                        'sdk-rust-runtime-shard',
                        'completed-history-replay',
                        'worker-restart-replay',
                        'code-divergence-refusal',
                        'server-history-mutation-refusal',
                        'malformed-history-refusal',
                        'in-flight-signal-restart-timing',
                    ],
                    'routing_policy' => [
                        'missing_required_scenario' => [
                            'scenario_status' => 'not_covered',
                            'finding_type' => 'conformance_runner_coverage_gap',
                            'owner' => 'conformance_harness',
                        ],
                        'host_environment_failure' => [
                            'scenario_status' => 'runner_blocked',
                            'finding_type' => 'runner_gap',
                            'owner' => 'conformance_harness',
                        ],
                        'unsupported_public_surface' => [
                            'scenario_status' => 'unsupported',
                            'finding_source' => 'replay_conformance.finding_policy',
                        ],
                        'product_behavior_failure' => [
                            'scenario_status' => 'fail',
                            'finding_source' => 'replay_conformance.finding_policy',
                        ],
                    ],
                ],
                'diagnostic_requirements' => [
                    'code_divergence_refusal' => [
                        'required_outcome' => 'non_determinism_error',
                        'required_fields' => [
                            'workflow_sequence',
                            'expected_shape',
                            'recorded_event_types',
                            'message',
                        ],
                    ],
                    'server_history_mutation_refusal' => [
                        'required_outcome' => 'bundle_invalid_or_drifted',
                        'required_fields' => [
                            'integrity.rule',
                            'integrity.path',
                            'replay_diff.reason',
                            'message',
                        ],
                    ],
                    'malformed_history_refusal' => [
                        'required_outcome' => 'bundle_invalid_or_failed',
                        'required_fields' => [
                            'integrity.rule',
                            'integrity.path',
                            'message',
                        ],
                    ],
                    'in_flight_signal_restart_timing' => [
                        'required_outcome' => 'same_next_decision_after_replay',
                        'required_fields' => [
                            'worker_restart_at',
                            'signal_sent_at',
                            'history_reloaded_at',
                            'replayed_next_decision',
                        ],
                    ],
                ],
                'coverage_gate' => [
                    'passing_outcome_requires' => [
                        'all_required_runtimes_present',
                        'all_required_matrix_cells_pass',
                        'all_refusals_are_actionable',
                        'artifact_versions_match_latest_published_set',
                        'no_local_product_source_artifacts',
                    ],
                    'uncovered_required_scenario_outcome' => 'non_passing',
                    'smoke_subset_outcome' => 'non_passing',
                    'unsupported_public_surface_outcome' => 'non_passing_with_root_cause_finding',
                    'runner_blocked_outcome' => 'non_passing_runner_blocked',
                ],
                'result_gate' => ReplayConformanceResultGate::spec(),
                'finding_policy' => [
                    'nondeterminism' => 'link_root_cause_finding_against_owning_runtime_or_sdk',
                    'silent_history_mutation_acceptance' => 'link_root_cause_finding_against_server',
                    'unclear_refusal_message' => 'link_root_cause_finding_against_emitting_surface',
                    'runtime_asymmetry' => 'link_root_cause_finding_against_asymmetric_runtime',
                    'unsupported_public_surface' => 'link_root_cause_finding_against_surface_owner',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function replayRequiredScenarios(): array
    {
        return [
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
        ];
    }
}
