<?php

namespace App\Support;

final class ExternalTaskResultContract
{
    public const SCHEMA = 'durable-workflow.v2.external-task-result.contract';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'unknown_field_policy' => 'ignore_additive_reject_unknown_required',
            'versioning' => [
                'add_optional_fields' => 'minor',
                'add_required_fields' => 'major',
                'rename_or_remove_fields' => 'major',
                'unknown_fields' => 'must_be_ignored_unless_declared_required_by_a_supported_version',
            ],
            'exit_codes' => [
                'success' => 'Exit code 0 can only mean success when the carrier also produced a valid success envelope.',
                'failure' => 'Non-zero exit codes are transport signals. Carriers must map them to a structured failure envelope or malformed_output.',
                'reserved' => [
                    '64' => 'Malformed carrier input or invalid output envelope.',
                    '70' => 'Handler runtime crash before a valid envelope was produced.',
                    '75' => 'Temporary carrier failure that may be retried by carrier policy.',
                    '130' => 'Cancellation signal received before a valid envelope was produced.',
                ],
            ],
            'stderr_policy' => 'logs_only_no_machine_meaning',
            'payload_support' => [
                'result_payload' => 'Success result payloads are codec-tagged. Null result is represented as result: null.',
                'failure_details' => 'Failure details are codec-tagged when present.',
                'unsupported_codec' => 'A handler that cannot decode input payloads must return failure.kind unsupported_payload with failure.classification unsupported_payload_codec.',
                'unsupported_external_storage' => 'A handler that cannot resolve an external storage reference must return failure.kind unsupported_payload with failure.classification unsupported_payload_reference.',
            ],
            'envelopes' => [
                'success' => self::successEnvelope(),
                'failure' => self::failureEnvelope(),
                'malformed_output' => self::malformedOutputEnvelope(),
            ],
            'fixtures' => [
                'success' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.success.v1',
                    self::successFixture(),
                ),
                'failure' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.failure.v1',
                    self::failureFixture(),
                ),
                'malformed_output' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.malformed-output.v1',
                    self::malformedOutputFixture(),
                ),
                'cancellation' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.cancellation.v1',
                    self::cancellationFixture(),
                ),
                'handler_crash' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.handler-crash.v1',
                    self::handlerCrashFixture(),
                ),
                'decode_failure' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.decode-failure.v1',
                    self::decodeFailureFixture(),
                ),
                'unsupported_payload_codec' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.unsupported-payload-codec.v1',
                    self::unsupportedPayloadCodecFixture(),
                ),
                'unsupported_payload_reference' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-result.unsupported-payload-reference.v1',
                    self::unsupportedPayloadReferenceFixture(),
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $example
     * @return array<string, mixed>
     */
    private static function fixtureArtifact(string $artifact, array $example): array
    {
        return [
            'artifact' => $artifact,
            'media_type' => 'application/vnd.durable-workflow.external-task-result+json',
            'schema' => 'durable-workflow.v2.external-task-result',
            'version' => self::VERSION,
            'sha256' => hash('sha256', (string) json_encode($example, JSON_UNESCAPED_SLASHES)),
            'example' => $example,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function successEnvelope(): array
    {
        return [
            'kind' => 'success',
            'required_fields' => [
                'schema',
                'version',
                'outcome',
                'task',
                'result',
                'metadata',
            ],
            'outcome_fields' => [
                'status' => ['constant' => 'succeeded'],
                'recorded' => ['source' => 'worker protocol response.recorded', 'type' => 'boolean'],
            ],
            'task_fields' => [
                'id' => ['source' => 'external task input task.id', 'type' => 'string'],
                'kind' => ['source' => 'external task input task.kind', 'type' => 'string'],
                'attempt' => ['source' => 'external task input task.attempt', 'type' => 'integer', 'minimum' => 1],
                'idempotency_key' => ['source' => 'external task input task.idempotency_key', 'type' => 'string'],
            ],
            'result_fields' => [
                'payload' => ['type' => 'object', 'nullable' => true, 'codec_tagged' => true],
                'metadata' => ['type' => 'object', 'nullable' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failureEnvelope(): array
    {
        return [
            'kind' => 'failure',
            'required_fields' => [
                'schema',
                'version',
                'outcome',
                'task',
                'failure',
                'metadata',
            ],
            'outcome_fields' => [
                'status' => ['constant' => 'failed'],
                'retryable' => ['type' => 'boolean'],
                'recorded' => ['source' => 'worker protocol response.recorded', 'type' => 'boolean'],
            ],
            'task_fields' => [
                'id' => ['source' => 'external task input task.id', 'type' => 'string'],
                'kind' => ['source' => 'external task input task.kind', 'type' => 'string'],
                'attempt' => ['source' => 'external task input task.attempt', 'type' => 'integer', 'minimum' => 1],
                'idempotency_key' => ['source' => 'external task input task.idempotency_key', 'type' => 'string'],
            ],
            'failure_fields' => [
                'kind' => [
                    'type' => 'string',
                    'values' => self::failureKinds(),
                ],
                'classification' => [
                    'type' => 'string',
                    'values' => self::failureClassifications(),
                ],
                'message' => ['type' => 'string'],
                'type' => ['type' => 'string', 'nullable' => true],
                'stack_trace' => ['type' => 'string', 'nullable' => true],
                'timeout_type' => [
                    'type' => 'string',
                    'nullable' => true,
                    'values' => [
                        'schedule_to_start',
                        'start_to_close',
                        'schedule_to_close',
                        'heartbeat',
                        'deadline_exceeded',
                    ],
                ],
                'cancelled' => ['type' => 'boolean'],
                'details' => ['type' => 'object', 'nullable' => true, 'codec_tagged' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function malformedOutputEnvelope(): array
    {
        return [
            'kind' => 'malformed_output',
            'required_fields' => [
                'schema',
                'version',
                'outcome',
                'task',
                'failure',
                'raw_output',
                'metadata',
            ],
            'meaning' => 'The carrier could not parse a valid success or failure envelope from handler output.',
            'retryability' => 'Carrier policy may retry malformed output only when the handler mapping declares it safe.',
            'failure_defaults' => [
                'kind' => 'malformed_output',
                'classification' => 'malformed_output',
                'retryable' => false,
                'cancelled' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function failureKinds(): array
    {
        return [
            'application',
            'timeout',
            'cancellation',
            'malformed_output',
            'handler_crash',
            'decode_failure',
            'unsupported_payload',
        ];
    }

    /**
     * @return list<string>
     */
    private static function failureClassifications(): array
    {
        return [
            'application_error',
            'timeout',
            'cancelled',
            'deadline_exceeded',
            'handler_crash',
            'decode_failure',
            'malformed_output',
            'unsupported_payload_codec',
            'unsupported_payload_reference',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function successFixture(): array
    {
        return [
            'schema' => 'durable-workflow.v2.external-task-result',
            'version' => self::VERSION,
            'outcome' => [
                'status' => 'succeeded',
                'recorded' => true,
            ],
            'task' => self::fixtureTask(),
            'result' => [
                'payload' => [
                    'codec' => 'avro',
                    'blob' => 'BASE64_AVRO_RESULT',
                ],
                'metadata' => [
                    'content_type' => 'application/vnd.durable-workflow.result+json',
                ],
            ],
            'metadata' => self::fixtureMetadata(184),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failureFixture(): array
    {
        return self::failureResultFixture(
            kind: 'timeout',
            classification: 'deadline_exceeded',
            message: 'Deadline exceeded while waiting for billing provider.',
            type: 'ProviderTimeout',
            retryable: true,
            timeoutType: 'deadline_exceeded',
            cancelled: false,
            details: [
                'codec' => 'avro',
                'blob' => 'BASE64_AVRO_FAILURE_DETAILS',
            ],
            durationMs: 5000,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function malformedOutputFixture(): array
    {
        return self::failureResultFixture(
            kind: 'malformed_output',
            classification: 'malformed_output',
            message: 'Handler exited without producing a valid result envelope.',
            type: 'MalformedExternalTaskOutput',
            retryable: false,
            timeoutType: null,
            cancelled: false,
            details: null,
            durationMs: 22,
            recorded: false,
            rawOutput: [
                'stdout_preview' => '{not json',
                'stderr_preview' => 'deprecated flag ignored',
                'exit_code' => 64,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function cancellationFixture(): array
    {
        return self::failureResultFixture(
            kind: 'cancellation',
            classification: 'cancelled',
            message: 'Activity was cancelled before the handler completed.',
            type: 'ActivityCancelled',
            retryable: false,
            timeoutType: null,
            cancelled: true,
            details: null,
            durationMs: 610,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function handlerCrashFixture(): array
    {
        return self::failureResultFixture(
            kind: 'handler_crash',
            classification: 'handler_crash',
            message: 'Handler process exited before producing a valid envelope.',
            type: 'HandlerRuntimeCrash',
            retryable: true,
            timeoutType: null,
            cancelled: false,
            details: null,
            durationMs: 31,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeFailureFixture(): array
    {
        return self::failureResultFixture(
            kind: 'decode_failure',
            classification: 'decode_failure',
            message: 'Carrier could not decode handler output as UTF-8 JSON.',
            type: 'OutputDecodeFailure',
            retryable: false,
            timeoutType: null,
            cancelled: false,
            details: [
                'codec' => 'json/plain',
                'blob' => 'eyJkZXRhaWwiOiJpbnZhbGlkIHV0Zi04In0=',
            ],
            durationMs: 18,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function unsupportedPayloadCodecFixture(): array
    {
        return self::failureResultFixture(
            kind: 'unsupported_payload',
            classification: 'unsupported_payload_codec',
            message: 'Handler does not support the input payload codec.',
            type: 'UnsupportedPayloadCodec',
            retryable: false,
            timeoutType: null,
            cancelled: false,
            details: [
                'codec' => 'json/plain',
                'blob' => 'eyJjb2RlYyI6ImF2cm8ifQ==',
            ],
            durationMs: 9,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function unsupportedPayloadReferenceFixture(): array
    {
        return self::failureResultFixture(
            kind: 'unsupported_payload',
            classification: 'unsupported_payload_reference',
            message: 'Handler cannot resolve the external storage payload reference.',
            type: 'UnsupportedPayloadReference',
            retryable: false,
            timeoutType: null,
            cancelled: false,
            details: [
                'codec' => 'json/plain',
                'blob' => 'eyJwcm92aWRlciI6ImdzIn0=',
            ],
            durationMs: 12,
        );
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, mixed>|null  $rawOutput
     * @return array<string, mixed>
     */
    private static function failureResultFixture(
        string $kind,
        string $classification,
        string $message,
        string $type,
        bool $retryable,
        ?string $timeoutType,
        bool $cancelled,
        ?array $details,
        int $durationMs,
        bool $recorded = true,
        ?array $rawOutput = null,
    ): array {
        $fixture = [
            'schema' => 'durable-workflow.v2.external-task-result',
            'version' => self::VERSION,
            'outcome' => [
                'status' => 'failed',
                'retryable' => $retryable,
                'recorded' => $recorded,
            ],
            'task' => self::fixtureTask(),
            'failure' => [
                'kind' => $kind,
                'classification' => $classification,
                'message' => $message,
                'type' => $type,
                'stack_trace' => null,
                'timeout_type' => $timeoutType,
                'cancelled' => $cancelled,
                'details' => $details,
            ],
        ];

        if ($rawOutput !== null) {
            $fixture['raw_output'] = $rawOutput;
        }

        $fixture['metadata'] = self::fixtureMetadata($durationMs);

        return $fixture;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixtureTask(): array
    {
        return [
            'id' => 'acttask_01HV7D3G3G61TAH2YB5RK45XJS',
            'kind' => 'activity_task',
            'attempt' => 1,
            'idempotency_key' => 'attempt_01HV7D3KJ1C8WQNNY8MVM8J40X',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixtureMetadata(int $durationMs): array
    {
        return [
            'handler' => 'billing.charge-card',
            'carrier' => 'process-carrier',
            'duration_ms' => $durationMs,
        ];
    }
}
