<?php

namespace App\Support;

final class InvocableCarrierContract
{
    public const SCHEMA = 'durable-workflow.v2.invocable-carrier.contract';

    public const VERSION = 1;

    public const CARRIER_TYPE = 'invocable_http';

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'carrier_type' => self::CARRIER_TYPE,
            'scope' => [
                'task_kinds' => ['activity_task'],
                'explicit_non_goals' => [
                    'workflow_task_execution',
                    'workflow_replay',
                    'history_mutation',
                    'generic_webhook_ingress',
                ],
            ],
            'config_binding' => [
                'config_schema' => ExternalExecutorConfigContract::CONFIG_SCHEMA,
                'config_schema_version' => ExternalExecutorConfigContract::CONFIG_VERSION,
                'carrier_type_field' => 'carriers.<name>.type',
                'required_type_value' => self::CARRIER_TYPE,
                'mapping_kind' => 'activity',
                'capability' => 'activity_task',
            ],
            'target_fields' => [
                'url' => [
                    'type' => 'string',
                    'required' => true,
                    'meaning' => 'Absolute HTTPS URL for the activity handler endpoint; loopback HTTP is only for local development.',
                ],
                'method' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'POST',
                    'allowed' => ['POST'],
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'required' => false,
                    'minimum' => 1,
                    'maximum' => 900,
                    'meaning' => 'Transport deadline for one handler attempt; carriers must also respect task.deadlines.',
                ],
            ],
            'request' => [
                'method' => 'POST',
                'content_type' => 'application/vnd.durable-workflow.external-task-input+json',
                'body_schema' => ExternalTaskInputContract::SCHEMA,
                'body_version' => ExternalTaskInputContract::VERSION,
                'idempotency_key_source' => 'task.idempotency_key',
            ],
            'response' => [
                'content_type' => 'application/vnd.durable-workflow.external-task-result+json',
                'body_schema' => ExternalTaskResultContract::SCHEMA,
                'body_version' => ExternalTaskResultContract::VERSION,
                'success_result_path' => 'result.payload',
                'failure_path' => 'failure',
            ],
            'failure_mapping' => [
                'transport_timeout' => 'failure.kind=timeout classification=deadline_exceeded',
                'non_2xx_without_valid_envelope' => 'malformed_output',
                'invalid_json' => 'malformed_output',
                'schema_mismatch' => 'malformed_output',
                'unsupported_payload_reference' => 'unsupported_payload',
            ],
            'auth' => [
                'source' => 'external_executor_config.auth_refs',
                'redaction' => 'tokens_secrets_signatures_never_echoed',
                'determinism' => 'effective auth must be discoverable by redacted config diagnostics before dispatch',
            ],
            'rollout_safety' => [
                'coexistence' => 'poll_and_invocable_carriers_may_share_a_queue_only_when_mappings_are_activity_type_specific',
                'drain_signal' => 'operators must remove or overlay-disable mappings before deleting endpoint credentials',
                'retry_authority' => 'carrier policy may retry transport failures, but durable activity retry policy remains the server/runtime authority',
            ],
        ];
    }
}
