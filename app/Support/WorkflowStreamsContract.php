<?php

namespace App\Support;

/**
 * Platform-level contract describing the durable workflow-streams surface:
 * a workflow run produces ordered items into named, run-scoped streams,
 * and external subscribers read those items with at-least-once delivery
 * and durable offsets that survive worker restart and migration.
 *
 * This is the parity-named view of the Replay 2026 "Workflow Streams"
 * capability. The underlying durability primitive is the existing
 * workflow signal/update record + an append-only per-stream item table:
 * an emit becomes a durable row keyed by (run, stream, offset). A
 * reconnecting consumer resumes by passing the offset it last saw.
 *
 * The contract names: the wire surface (the routes the producer and
 * subscriber ride), the addressing model (run + stream name), the
 * lifecycle states a stream walks through, the ordering and
 * idempotency guarantees, the backpressure semantics for slow
 * consumers and bursty producers, the observability surface (last
 * delivered offset, pending count, lifecycle), and the SDK
 * implementation notes.
 *
 * This is a stable consumer surface. Removing routes, lifecycle
 * states, or fields is a breaking change and requires a `version`
 * bump.
 */
final class WorkflowStreamsContract
{
    public const SCHEMA = 'durable-workflow.v2.workflow-streams.contract';

    public const VERSION = 1;

    public const AUTHORITY_DOCUMENT = 'docs/contracts/workflow-streams.md';

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authority_document' => self::AUTHORITY_DOCUMENT,
            'parity_target' => [
                'name' => 'Workflow Streams',
                'description' => 'Durable streaming out of a running Workflow execution. An external consumer subscribes to a named output stream, resumes by offset without loss, and handles possible at-least-once redelivery after reconnect.',
                'underlying_primitives' => ['workflow_command', 'record_side_effect', 'durable_stream_item'],
            ],
            'cluster_info_key' => 'workflow_streams_contract',
            'capability_flag' => 'workflow_streams',
            'addressing' => [
                'workflow_id_field' => 'workflow_id',
                'workflow_run_id_field' => 'workflow_run_id',
                'stream_name_field' => 'stream_name',
                'stream_name_max_length' => 191,
                'stream_name_pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$',
                'item_offset_field' => 'offset',
                'idempotency_field' => 'idempotency_key',
                'origin_field' => 'origin',
                'origin_reference_field' => 'origin_reference',
            ],
            'wire_surface' => [
                'list_streams' => 'GET /api/workflows/{workflowId}/runs/{runId}/streams',
                'describe_stream' => 'GET /api/workflows/{workflowId}/runs/{runId}/streams/{streamName}',
                'subscribe' => 'GET /api/workflows/{workflowId}/runs/{runId}/streams/{streamName}/items',
                'append' => 'POST /api/workflows/{workflowId}/runs/{runId}/streams/{streamName}/items',
                'close' => 'POST /api/workflows/{workflowId}/runs/{runId}/streams/{streamName}/close',
            ],
            'lifecycle_statuses' => [
                'open',
                'closed',
                'errored',
            ],
            'item_origin_kinds' => [
                'workflow_command',
                'signal_handler',
                'update_handler',
                'external',
            ],
            'ordering_guarantees' => [
                'per_stream_order' => 'monotonically_increasing_offset',
                'cross_stream_order' => 'undefined',
                'append_atomicity' => 'each_append_request_is_a_single_transaction_with_ordered_offsets',
                'duplicate_handling' => 'idempotency_key_dedupes_within_stream',
                'first_offset' => 0,
            ],
            'durability_guarantees' => [
                'persistence' => 'every_appended_item_is_persisted_before_the_response_returns',
                'restart_survival' => 'subscriber_reconnects_with_from_offset_to_resume_without_loss',
                'at_least_once_to_consumer' => 'consumer_must_track_its_own_offset_and_be_idempotent_on_replay',
                'at_most_once_to_durable_record' => 'idempotency_key_collapses_retried_appends_to_one_offset',
            ],
            'workflow_authoring' => [
                'command_boundary' => 'record_side_effect.workflow_stream',
                'transactionality' => 'stream_mutation_and_side_effect_history_commit_together',
                'idempotency_derivation' => 'workflow_command_id_plus_command_ordinal_plus_item_index',
                'replay_outcome' => 'recorded_side_effect_consumption_skips_stream_mutation',
            ],
            'message_stream_relationship' => [
                'shared_concepts' => ['stream_name', 'ordered_offset', 'lifecycle', 'pending_items', 'error'],
                'service_mode_direction' => 'workflow_output_only',
                'embedded_direction' => 'workflow_inbox_and_outbox',
                'service_mode_inbound_workflow_messaging' => false,
                'service_mode_continue_as_new_cursor_transfer' => false,
                'offset_origins' => [
                    'service_workflow_stream' => 0,
                    'embedded_message_stream' => 1,
                ],
            ],
            'first_party_sdk_support' => [
                'php' => [
                    'operations' => ['list', 'describe', 'subscribe', 'append', 'close', 'error'],
                    'workflow_authoring' => true,
                    'external_payload_references' => 'opaque-reference',
                ],
                'python' => [
                    'operations' => ['list', 'describe', 'subscribe', 'append', 'close', 'error'],
                    'workflow_authoring' => true,
                    'external_payload_references' => 'opaque-reference',
                ],
                'rust' => [
                    'operations' => ['list', 'describe', 'subscribe', 'append', 'close', 'error'],
                    'workflow_authoring' => true,
                    'external_payload_references' => 'opaque-reference',
                ],
            ],
            'backpressure_semantics' => [
                'slow_consumer' => 'producer_is_unaffected_until_pending_items_exceeds_max_pending_items_per_stream',
                'producer_throttle_outcome' => 'append_returns_429_with_reason_stream_full_when_pending_items_threshold_exceeded',
                'consumer_long_poll' => 'subscribe_with_wait_seconds_blocks_up_to_the_requested_window_for_new_items',
                'wait_seconds_max' => 60,
                'wait_seconds_default' => 0,
                'max_items_per_subscribe_max' => 500,
                'max_items_per_subscribe_default' => 100,
                'max_items_per_append_max' => 500,
                'max_pending_items_per_stream_default' => 10000,
            ],
            'retention' => [
                'open_stream' => 'kept_until_close',
                'closed_stream_default_retention_seconds' => 3600,
                'errored_stream_default_retention_seconds' => 3600,
                'retention_override_field' => 'retention_seconds',
            ],
            'observability_surface' => [
                'list_streams_route' => 'GET /api/workflows/{workflowId}/runs/{runId}/streams',
                'fields' => [
                    'stream_name',
                    'status',
                    'last_offset',
                    'total_items',
                    'pending_items',
                    'opened_at',
                    'last_appended_at',
                    'closed_at',
                    'error_reason',
                ],
                'history_event_emitted' => false,
            ],
            'rejections' => [
                'unknown_run' => 'instance_not_found',
                'closed_stream_append' => 'stream_closed',
                'errored_stream_append' => 'stream_errored',
                'pending_items_exceeded' => 'stream_full',
                'invalid_stream_name' => 'invalid_stream_name',
                'invalid_offset' => 'invalid_offset',
                'item_too_large' => 'payload_too_large',
            ],
            'sdk_implementation_notes' => [
                'producer_should' => 'append from inside a workflow command boundary so the produced offsets are part of the run\'s durable side-effects, not best-effort side channels',
                'consumer_should' => 'process idempotently and persist next_offset after the page effects are durable; a crash before checkpoint may redeliver the page',
                'idempotency_key_should' => 'be the producer\'s deterministic identifier for the logical item (e.g. the workflow command id plus a within-batch index) so retries collapse to one durable offset rather than emitting twice',
                'close_semantics' => 'a closed stream rejects further appends with stream_closed; consumers continue to read the persisted history up to retention',
                'large_payloads' => 'payloads larger than the namespace inline limit are uploaded through the authenticated runtime external-payload transport and carried in payload_reference as an opaque runtime reference object',
            ],
            'out_of_scope' => [
                'generalized_pubsub' => 'streams are scoped to a single workflow run; cross-run fan-in or cross-cluster fan-out belong in a separate transport, not this surface',
                'broker_integration' => 'the durable record is the queue; integration with external streaming systems (Kafka, NATS, SSE proxy, etc.) is implemented by adapters that subscribe to this surface',
                'tail_compaction' => 'the v1 surface does not implement log compaction; consumers that only care about the latest item should call describe_stream and use last_offset',
            ],
        ];
    }
}
