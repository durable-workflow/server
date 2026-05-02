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

    public const VERSION = 1;

    public const BUNDLE_SCHEMA = 'durable-workflow.v2.history-export';

    public const BUNDLE_SCHEMA_VERSION = 1;

    public const INTEGRITY_REPORT_SCHEMA = 'durable-workflow.v2.history-bundle-verification';

    public const INTEGRITY_REPORT_SCHEMA_VERSION = 1;

    public const REPLAY_DIFF_SCHEMA = 'durable-workflow.v2.replay-diff';

    public const REPLAY_DIFF_SCHEMA_VERSION = 1;

    public const GOLDEN_HISTORY_FIXTURE_SCHEMA = 'durable-workflow.golden-history.v1';

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
                    'codec_schemas.wrapper_fingerprint_mismatch',
                    'codec_schemas.wrapper_schema_drift',
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
        ];
    }
}
