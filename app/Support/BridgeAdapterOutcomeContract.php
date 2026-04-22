<?php

namespace App\Support;

final class BridgeAdapterOutcomeContract
{
    public const SCHEMA = 'durable-workflow.v2.bridge-adapter-outcome.contract';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'boundary' => [
                'role' => 'bounded_ingress_or_handoff',
                'not_a_workflow_runtime' => true,
                'allowed_actions' => [
                    'start_workflow',
                    'signal_workflow',
                    'update_workflow',
                    'handoff_external_task',
                ],
                'forbidden_responsibilities' => [
                    'workflow_replay',
                    'event_history_interpretation',
                    'workflow_state_transition_authority',
                ],
            ],
            'patterns' => [
                'webhook_receiver' => [
                    'description' => 'Accept an authenticated external HTTP event and map it to a bounded workflow command.',
                    'allowed_actions' => ['start_workflow', 'signal_workflow', 'update_workflow'],
                ],
                'eventbridge_handoff' => [
                    'description' => 'Accept a cloud event and hand it to a workflow command with a deterministic idempotency key.',
                    'allowed_actions' => ['start_workflow', 'signal_workflow', 'update_workflow'],
                ],
                'queue_backed_adapter' => [
                    'description' => 'Lease a bounded queue message and hand it to an activity-grade external task target.',
                    'allowed_actions' => ['handoff_external_task'],
                ],
            ],
            'idempotency' => [
                'required' => true,
                'key_sources' => [
                    'explicit_idempotency_key',
                    'provider_event_id',
                    'dedupe_header',
                    'adapter_generated_stable_hash',
                ],
                'duplicate_policy' => [
                    'start_workflow' => ['reject_duplicate', 'use_existing'],
                    'signal_workflow' => ['record_duplicate_without_redelivery'],
                    'update_workflow' => ['record_duplicate_without_redelivery'],
                    'handoff_external_task' => ['record_duplicate_without_redelivery'],
                ],
            ],
            'visibility' => [
                'outcome_fields' => [
                    'schema',
                    'version',
                    'adapter',
                    'action',
                    'accepted',
                    'outcome',
                    'reason',
                    'idempotency_key',
                    'target',
                    'correlation',
                ],
                'redaction' => [
                    'never_echo' => ['authorization', 'signature', 'token', 'secret', 'raw_payload'],
                    'safe_references' => ['credential_ref', 'profile', 'namespace', 'target'],
                ],
            ],
            'outcomes' => [
                'accepted' => [
                    'http_status' => 202,
                    'retriable' => false,
                    'terminal' => false,
                    'meaning' => 'The bridge accepted the event for durable command handling or bounded handoff.',
                ],
                'duplicate' => [
                    'http_status' => 200,
                    'retriable' => false,
                    'terminal' => true,
                    'meaning' => 'The bridge recognized a previously accepted idempotency key.',
                ],
                'rejected' => [
                    'http_status' => 422,
                    'retriable' => false,
                    'terminal' => true,
                    'meaning' => 'The bridge rejected a well-formed event for a named product reason.',
                ],
                'unauthorized' => [
                    'http_status' => 401,
                    'retriable' => false,
                    'terminal' => true,
                    'meaning' => 'The bridge could not authenticate or authorize the event.',
                ],
            ],
            'rejection_reasons' => [
                'unknown_target',
                'auth_failed',
                'malformed_payload',
                'duplicate_start',
                'unsupported_routing',
                'unsupported_action',
                'payload_too_large',
                'adapter_unavailable',
            ],
            'examples' => [
                'operator_webhook_to_signal' => [
                    'pattern' => 'webhook_receiver',
                    'action' => 'signal_workflow',
                    'idempotency_key' => 'provider_event_id',
                    'target' => ['workflow_id', 'signal_name'],
                ],
                'integration_queue_to_external_task' => [
                    'pattern' => 'queue_backed_adapter',
                    'action' => 'handoff_external_task',
                    'idempotency_key' => 'queue_message_id',
                    'target' => ['task_queue', 'handler'],
                ],
            ],
            'reference_journeys' => [
                'incident_webhook_signals_workflow' => [
                    'pattern' => 'webhook_receiver',
                    'operator_story' => 'An incident tool posts one provider event that signals an existing remediation workflow exactly once.',
                    'request' => [
                        'method' => 'POST',
                        'path_template' => '/api/bridge-adapters/webhook/{adapter}',
                        'adapter_example' => 'pagerduty',
                        'action' => 'signal_workflow',
                        'idempotency_key_source' => 'provider_event_id',
                        'target' => [
                            'workflow_id' => 'required existing workflow id',
                            'signal_name' => 'required signal name',
                        ],
                        'input' => 'optional JSON array or object carried through the configured payload envelope',
                    ],
                    'expected_outcomes' => [
                        'first_delivery' => [
                            'http_status' => 202,
                            'outcome' => 'accepted',
                            'reason' => null,
                        ],
                        'redelivery' => [
                            'http_status' => 200,
                            'outcome' => 'duplicate',
                            'control_plane_outcome' => 'deduped_existing_command',
                        ],
                        'missing_workflow' => [
                            'http_status' => 422,
                            'outcome' => 'rejected',
                            'reason' => 'unknown_target',
                        ],
                    ],
                    'visibility' => [
                        'redacted_target_fields' => ['workflow_id', 'signal_name'],
                        'command_context_metadata' => ['adapter', 'action', 'idempotency_key', 'request_id', 'signal_name'],
                    ],
                ],
                'commerce_event_starts_workflow' => [
                    'pattern' => 'webhook_receiver',
                    'operator_story' => 'A commerce integration receives an order event and starts one durable workflow keyed by the provider event.',
                    'request' => [
                        'method' => 'POST',
                        'path_template' => '/api/bridge-adapters/webhook/{adapter}',
                        'adapter_example' => 'stripe',
                        'action' => 'start_workflow',
                        'idempotency_key_source' => 'provider_event_id',
                        'target' => [
                            'workflow_type' => 'required configured workflow type',
                            'workflow_id' => 'optional explicit workflow id',
                            'task_queue' => 'optional task queue override',
                            'business_key' => 'optional user-visible dedupe or lookup key',
                            'duplicate_policy' => ['reject_duplicate', 'use_existing'],
                        ],
                    ],
                    'expected_outcomes' => [
                        'first_delivery' => [
                            'http_status' => 202,
                            'outcome' => 'accepted',
                            'control_plane_outcome' => 'started_new',
                        ],
                        'redelivery' => [
                            'http_status' => 200,
                            'outcome' => 'duplicate',
                            'reason' => 'duplicate_start',
                            'control_plane_outcome' => 'returned_existing_active',
                        ],
                        'unconfigured_workflow_type' => [
                            'http_status' => 422,
                            'outcome' => 'rejected',
                            'reason' => 'unknown_target',
                        ],
                    ],
                    'visibility' => [
                        'redacted_target_fields' => ['workflow_id', 'workflow_type', 'task_queue', 'business_key'],
                        'command_context_metadata' => ['adapter', 'action', 'idempotency_key'],
                    ],
                ],
            ],
        ];
    }
}
