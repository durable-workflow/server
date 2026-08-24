<?php

namespace App\Support;

final class MessageStreamsContract
{
    public const INTERNAL_SIGNAL = '__durable_workflow_message_stream';

    public const MESSAGE_SCHEMA = 'durable-workflow.v2.message-stream.message';

    public const CURSOR_SCHEMA = 'durable-workflow.v2.message-stream.cursor';

    public const CAPABILITY = 'message_streams';

    public const MAX_BATCH_SIZE = 100;

    public const MINIMUM_WORKER_PROTOCOL_VERSION = '1.15';

    public static function isRuntimeReservedSignal(string $name): bool
    {
        return $name === self::INTERNAL_SIGNAL;
    }

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        return [
            'schema' => 'durable-workflow.v2.message-streams.contract',
            'version' => 1,
            'capability_flag' => self::CAPABILITY,
            'minimum_worker_protocol_version' => self::MINIMUM_WORKER_PROTOCOL_VERSION,
            'scope' => 'workflow_instance',
            'transport' => [
                'append_path' => '/api/workflows/{workflow_id}/message-streams/{stream_name}/messages',
                'diagnostics_path' => '/api/workflows/{workflow_id}/message-streams',
                'internal_signal' => self::INTERNAL_SIGNAL,
                'message_schema' => self::MESSAGE_SCHEMA,
                'cursor_schema' => self::CURSOR_SCHEMA,
                'payload_envelope' => 'inline_avro_at_the_server_to_worker_delivery_boundary',
                'durable_payload' => 'exact_inline_avro_bytes_or_the_verified_external_storage_reference',
                'payload_visibility' => 'payload_is_present_only_in_durable_signal_history_and_is_omitted_from_stream_diagnostics',
            ],
            'identity' => [
                'producer_key' => 'message_id',
                'order_key' => 'position',
                'first_position' => 1,
                'duplicate_same_payload' => 'return_existing_position_without_redelivery',
                'duplicate_different_payload' => 'reject_message_identity_conflict',
            ],
            'cursor' => [
                'ownership' => 'server',
                'worker_completion_field' => 'message_stream_cursors',
                'monotonic' => true,
                'advancement' => 'highest_contiguous_consumed_position',
                'history_event' => 'MessageCursorAdvanced',
                'continue_as_new' => 'a_payload_free_cursor_checkpoint_and_unconsumed_messages_are_delivered_to_the_current_run',
            ],
            'waiting' => [
                'worker_completion_field' => 'message_stream_waits',
                'durable_wakeup' => 'runtime_reserved_signal_task_resume',
                'pending_wait_is_diagnostic' => true,
            ],
            'batch' => [
                'minimum' => 1,
                'maximum' => self::MAX_BATCH_SIZE,
                'waits_for' => 'first_message',
                'returns' => 'up_to_maximum_currently_available_messages',
            ],
            'replay' => [
                'ordering_source' => 'server_assigned_position',
                'deduplication_source' => 'message_id_and_instance_cursor',
                'worker_replacement' => 'history_replay_reconstructs_pending_messages_and_cursor_acknowledgement_is_idempotent',
                'server_restart' => 'stream_items_cursor_and_wait_state_are_database_durable',
                'external_payloads' => 'references_remain_reference_backed_at_rest_and_are_resolved_for_worker_delivery_then_removed_with_namespace_retention',
            ],
            'sdk_handoffs' => [
                'php' => 'WorkflowContext::messageStream(string)->receive(int)',
                'python' => 'WorkflowContext.message_stream(str).receive(max_items)',
                'rust' => 'WorkflowContext::message_stream(name).receive(max_items).await',
            ],
            'conformance' => [
                'runtimes' => ['php', 'python', 'rust'],
                'targets' => ['server', 'cloud'],
                'required_scenarios' => [
                    'repeated_messages_are_observed_in_position_order_exactly_once',
                    'duplicate_delivery_preserves_one_logical_message',
                    'worker_is_replaced_mid_stream',
                    'continue_as_new_retains_cursor_and_pending_messages',
                    'server_restart_retains_cursor_wait_and_order',
                ],
                'artifacts' => 'published',
            ],
        ];
    }
}
