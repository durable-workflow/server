<?php

namespace Tests\Unit;

use App\Support\NexusContract;
use PHPUnit\Framework\TestCase;

class NexusContractTest extends TestCase
{
    public function test_manifest_publishes_schema_version_and_authority(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertSame('durable-workflow.v2.nexus.contract', $manifest['schema']);
        $this->assertSame(1, $manifest['version']);
        $this->assertSame('docs/contracts/nexus.md', $manifest['authority_document']);
        $this->assertSame('nexus_contract', $manifest['cluster_info_key']);
        $this->assertSame('nexus', $manifest['capability_flag']);
    }

    public function test_manifest_names_the_temporal_parity_target_and_underlying_contract(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertSame('Nexus', $manifest['parity_target']['name']);
        $this->assertTrue($manifest['parity_target']['replaces_per_pair_integration']);
        $this->assertSame(
            'durable-workflow.v2.service-execution.contract',
            $manifest['underlying_execution_contract'],
        );
    }

    public function test_manifest_lists_the_addressing_fields_callers_use(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertSame('endpoint_name', $manifest['addressing']['endpoint_field']);
        $this->assertSame('service_name', $manifest['addressing']['service_field']);
        $this->assertSame('operation_name', $manifest['addressing']['operation_field']);
        $this->assertSame('caller_namespace', $manifest['addressing']['caller_namespace_field']);
        $this->assertSame('target_namespace', $manifest['addressing']['target_namespace_field']);
        $this->assertSame('caller_workflow_instance_id', $manifest['addressing']['caller_workflow_instance_field']);
        $this->assertSame('caller_workflow_run_id', $manifest['addressing']['caller_workflow_run_field']);
        $this->assertSame('idempotency_key', $manifest['addressing']['idempotency_field']);
        $this->assertSame('service_call_id', $manifest['addressing']['durable_call_id_field']);
    }

    public function test_manifest_publishes_the_wire_surface_routes_sdks_target(): void
    {
        $wire = NexusContract::manifest()['wire_surface'];

        $this->assertStringContainsString(
            '/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/execute',
            $wire['invoke_operation'],
        );
        $this->assertStringContainsString(
            '/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{serviceCallId}',
            $wire['describe_call'],
        );
        $this->assertStringContainsString(
            '/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{serviceCallId}/cancel',
            $wire['cancel_call'],
        );
        $this->assertStringContainsString(
            '/api/workflows/{workflowId}/runs/{runId}/nexus-operations',
            $wire['caller_history'],
        );
    }

    public function test_manifest_locks_the_lifecycle_outcome_and_mode_enumerations(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertSame(['sync', 'async', 'sync_with_durable_reference'], $manifest['operation_modes']);
        $this->assertSame(
            ['pending', 'accepted', 'started', 'completed', 'failed', 'cancelled'],
            $manifest['lifecycle_statuses'],
        );

        foreach ([
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
        ] as $outcome) {
            $this->assertContains($outcome, $manifest['outcomes']);
        }

        foreach ([
            'workflow_run',
            'workflow_update',
            'workflow_signal',
            'workflow_query',
            'activity_execution',
            'invocable_carrier_request',
        ] as $kind) {
            $this->assertContains($kind, $manifest['handler_binding_kinds']);
        }
    }

    public function test_manifest_describes_activity_style_retry_durability(): void
    {
        $retry = NexusContract::manifest()['retry_durability'];

        $this->assertSame('activity_style', $retry['retry_policy_shape']);
        $this->assertSame('durable_record_keyed_by_service_call_id', $retry['caller_recovery']);
        $this->assertSame('caller_replays_with_same_idempotency_key', $retry['idempotent_resume']);
        $this->assertSame(
            'caller_worker_resumes_by_service_call_id_after_restart',
            $retry['crash_recovery'],
        );
    }

    public function test_manifest_freezes_namespace_acl_enforcement_points(): void
    {
        $acl = NexusContract::manifest()['namespace_acl_enforcement'];

        $this->assertSame('authenticated_request_principal', $acl['principal_source']);
        $this->assertSame('App\\Support\\ServiceCallBoundary', $acl['admission_gate']);
        $this->assertSame('rejected_forbidden', $acl['rejection_outcome']);
        $this->assertSame('before_handler_dispatch', $acl['enforcement_phase']);
        $this->assertSame(
            'rejected_forbidden_when_principal_disallows',
            $acl['forging_caller_namespace'],
        );
        $this->assertSame(
            'workflow_service_calls.caller_principal_subject',
            $acl['audit_trail'],
        );
    }

    public function test_manifest_promises_multi_namespace_caller_pattern_without_per_caller_registration(): void
    {
        $pattern = NexusContract::manifest()['multi_namespace_caller_pattern'];

        $this->assertFalse($pattern['per_caller_registration_required']);
        $this->assertTrue($pattern['caller_namespaces_recorded_independently']);
        $this->assertTrue($pattern['fanout_supported']);
    }

    public function test_manifest_caller_history_surface_lists_debug_fields(): void
    {
        $surface = NexusContract::manifest()['caller_history_surface'];

        $this->assertStringContainsString(
            '/api/workflows/{workflowId}/runs/{runId}/nexus-operations',
            $surface['route'],
        );

        foreach ([
            'service_call_id',
            'caller_namespace',
            'target_namespace',
            'endpoint_name',
            'service_name',
            'operation_name',
            'status',
            'outcome',
            'resolved_binding_kind',
            'resolved_target_reference',
            'failure_message',
            'caller_principal_subject',
            'accepted_at',
            'completed_at',
            'failed_at',
        ] as $field) {
            $this->assertContains($field, $surface['response_fields']);
        }
    }

    public function test_manifest_documents_out_of_scope_surfaces(): void
    {
        $manifest = NexusContract::manifest();

        $this->assertArrayHasKey('general_service_mesh', $manifest['out_of_scope']);
        $this->assertArrayHasKey('arbitrary_external_http', $manifest['out_of_scope']);
    }
}
