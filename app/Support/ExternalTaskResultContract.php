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
                'success' => 'tests/Fixtures/contracts/external-task-result/success.v1.json',
                'failure' => 'tests/Fixtures/contracts/external-task-result/failure.v1.json',
                'malformed_output' => 'tests/Fixtures/contracts/external-task-result/malformed-output.v1.json',
            ],
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
}
