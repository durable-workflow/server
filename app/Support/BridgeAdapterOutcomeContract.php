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
        ];
    }
}
