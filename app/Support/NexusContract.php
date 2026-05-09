<?php

namespace App\Support;

/**
 * Platform-level contract describing how a workflow in one namespace makes a
 * durable, retried, observable call into another namespace.
 *
 * The Nexus contract names: the wire surface (routes the durable cross-
 * namespace call rides), the addressing model (endpoint / service /
 * operation triple plus the caller / target namespace identities), the
 * operation modes (sync, async, sync-with-durable-reference), the durable
 * record (`workflow_service_calls` row that is the system of record for
 * every call), the lifecycle and outcome enumeration callers must handle,
 * the retry/durability semantics, the namespace-ACL enforcement points,
 * and the caller-side observability surface (the per-workflow Nexus
 * operation index that lets operators answer "what cross-namespace calls
 * has this workflow made?" without inspecting raw transport logs).
 *
 * Nexus is the parity name for the existing cross-namespace service layer
 * that landed under the workflow Phase TD-061 and the cross-namespace
 * service binding contract. Nothing in this contract introduces a parallel
 * primitive: it freezes the parity-named view of those primitives so SDKs
 * targeting the Temporal Nexus shape have a single, named surface to
 * implement.
 *
 * This is a stable consumer surface: changing the schema, removing
 * lifecycle values / outcomes, or removing routes is a breaking change
 * and requires a `version` bump.
 */
final class NexusContract
{
    public const SCHEMA = 'durable-workflow.v2.nexus.contract';

    public const VERSION = 1;

    public const AUTHORITY_DOCUMENT = 'docs/contracts/nexus.md';

    public const UNDERLYING_EXECUTION_CONTRACT = 'durable-workflow.v2.service-execution.contract';

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
                'name' => 'Nexus',
                'description' => 'Durable workflow-to-workflow and service-to-service calls across namespace boundaries with activity-style retry, durability, and observability semantics.',
                'replaces_per_pair_integration' => true,
            ],
            'underlying_execution_contract' => self::UNDERLYING_EXECUTION_CONTRACT,
            'cluster_info_key' => 'nexus_contract',
            'capability_flag' => 'nexus',
            'addressing' => [
                'endpoint_field' => 'endpoint_name',
                'service_field' => 'service_name',
                'operation_field' => 'operation_name',
                'caller_namespace_field' => 'caller_namespace',
                'target_namespace_field' => 'target_namespace',
                'caller_workflow_instance_field' => 'caller_workflow_instance_id',
                'caller_workflow_run_field' => 'caller_workflow_run_id',
                'idempotency_field' => 'idempotency_key',
                'durable_call_id_field' => 'service_call_id',
                'unknown_caller_namespace_policy' => 'fallback_to_request_namespace',
            ],
            'wire_surface' => [
                'register_endpoint' => 'POST /api/service-endpoints',
                'register_service' => 'POST /api/service-endpoints/{endpoint}/services',
                'register_operation' => 'POST /api/service-endpoints/{endpoint}/services/{service}/operations',
                'invoke_operation' => 'POST /api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/execute',
                'describe_call' => 'GET /api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{serviceCallId}',
                'cancel_call' => 'POST /api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{serviceCallId}/cancel',
                'caller_history' => 'GET /api/workflows/{workflowId}/runs/{runId}/nexus-operations',
            ],
            'operation_modes' => ['sync', 'async', 'sync_with_durable_reference'],
            'lifecycle_statuses' => ['pending', 'accepted', 'started', 'completed', 'failed', 'cancelled'],
            'outcomes' => [
                'accepted',
                'completed',
                'cancelled',
                'timed_out',
                'rejected_not_found',
                'rejected_forbidden',
                'rejected_throttled',
                'rejected_concurrency_limited',
                'rejected_circuit_open',
                'degraded',
                'handler_failed',
            ],
            'handler_binding_kinds' => [
                'workflow_run',
                'workflow_update',
                'workflow_signal',
                'workflow_query',
                'activity_execution',
                'invocable_carrier_request',
            ],
            'retry_durability' => [
                'caller_recovery' => 'durable_record_keyed_by_service_call_id',
                'idempotent_resume' => 'caller_replays_with_same_idempotency_key',
                'retry_policy_source' => 'operation.retry_policy snapped onto the call row at admission',
                'retry_policy_shape' => 'activity_style',
                'crash_recovery' => 'caller_worker_resumes_by_service_call_id_after_restart',
                'history_recording' => 'every_admission_is_durable_before_handler_dispatch',
            ],
            'namespace_acl_enforcement' => [
                'principal_source' => 'authenticated_request_principal',
                'caller_namespace_source' => 'request_or_principal_resolved_namespace',
                'forging_caller_namespace' => 'rejected_forbidden_when_principal_disallows',
                'admission_gate' => 'App\\Support\\ServiceCallBoundary',
                'audit_trail' => 'workflow_service_calls.caller_principal_subject',
                'rejection_outcome' => 'rejected_forbidden',
                'enforcement_phase' => 'before_handler_dispatch',
            ],
            'multi_namespace_caller_pattern' => [
                'per_caller_registration_required' => false,
                'caller_namespaces_recorded_independently' => true,
                'fanout_supported' => true,
                'description' => 'A single Nexus service in target namespace B may be called by workflows in any caller namespace A1..An without per-caller registration. Each call records its own caller_namespace; the boundary gate evaluates each caller independently.',
            ],
            'caller_history_surface' => [
                'route' => 'GET /api/workflows/{workflowId}/runs/{runId}/nexus-operations',
                'description' => 'Caller-indexed view of every Nexus call this workflow run scheduled. Each row carries the durable service-call id, the resolved binding, the lifecycle status, the outcome, the linked target reference, and the caller principal that admitted the call. Operators debugging a failed run answer "what cross-namespace calls did this workflow make and how did each one settle?" from this single surface.',
                'response_fields' => [
                    'service_call_id',
                    'caller_workflow_instance_id',
                    'caller_workflow_run_id',
                    'caller_namespace',
                    'target_namespace',
                    'endpoint_name',
                    'service_name',
                    'operation_name',
                    'operation_mode',
                    'status',
                    'outcome',
                    'outcome_category',
                    'outcome_reason',
                    'outcome_message',
                    'resolved_binding_kind',
                    'resolved_target_reference',
                    'linked_workflow_instance_id',
                    'linked_workflow_run_id',
                    'linked_workflow_update_id',
                    'idempotency_key',
                    'failure_message',
                    'caller_principal_subject',
                    'caller_principal_method',
                    'accepted_at',
                    'started_at',
                    'completed_at',
                    'failed_at',
                    'cancelled_at',
                ],
            ],
            'sdk_implementation_notes' => [
                'declare_operation' => 'SDKs MUST address operations through the endpoint / service / operation triple; the underlying handler binding is an internal resolution detail and MUST NOT be assumed by callers.',
                'operation_mode_default' => 'async',
                'sync_block_handling' => 'A sync caller still records a durable service-call id; the SDK MUST surface that id alongside the inline result so observers can recover the same call after the fact.',
                'idempotency_handling' => 'When the caller supplies an idempotency_key matching an existing in-flight or terminal call in the same target namespace + operation, the server MUST return the existing call rather than admitting a new one.',
                'retry_handling' => 'SDKs MUST treat the recorded retry_policy on the call row as authoritative; client-side retry logic MUST not duplicate calls when the server has already accepted them.',
                'observability_handling' => 'SDKs MUST expose the durable service-call id to user code so the caller workflow can record the id in its own observability surface (logs, search attributes, memos).',
            ],
            'out_of_scope' => [
                'general_service_mesh' => 'Nexus is durable cross-namespace calls within a durable-workflow cluster; it is not a generalized service mesh, sidecar fabric, or cross-cluster routing layer.',
                'arbitrary_external_http' => 'Nexus does not invoke arbitrary external HTTP endpoints; outbound HTTP belongs in the existing invocable-carrier surface.',
            ],
        ];
    }
}
