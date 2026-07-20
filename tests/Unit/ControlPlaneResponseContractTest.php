<?php

namespace Tests\Unit;

use App\Support\ControlPlaneResponseContract;
use PHPUnit\Framework\TestCase;

class ControlPlaneResponseContractTest extends TestCase
{
    public function test_manifest_publishes_rejection_contract_for_workflow_starts(): void
    {
        $manifest = ControlPlaneResponseContract::manifest();

        $this->assertSame('durable-workflow.v2.control-plane-response', $manifest['schema']);

        $start = $manifest['operations']['start'];

        $this->assertArrayHasKey('rejection_fields', $start);
        $this->assertArrayHasKey('rejection_reasons', $start);

        foreach (['workflow_id', 'command_status', 'command_source', 'outcome', 'reason', 'rejection_reason', 'message'] as $field) {
            $this->assertContains($field, $start['rejection_fields']);
        }

        foreach (['workflow_id_reserved_in_namespace', 'task_queue_draining', 'compatibility_blocked'] as $reason) {
            $this->assertContains($reason, $start['rejection_reasons']);
        }
    }

    public function test_manifest_projects_rejection_fields_for_attached_responses(): void
    {
        $this->assertContains('command_status', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('command_source', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('rejection_reason', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('rejection_category', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('outcome', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('reason', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('message', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('principal', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('retryable', ControlPlaneResponseContract::manifest()['projected_fields']);
        $this->assertContains('error_id', ControlPlaneResponseContract::manifest()['projected_fields']);
    }

    public function test_manifest_publishes_signal_rejection_contract_diagnostics(): void
    {
        $signal = ControlPlaneResponseContract::manifest()['operations']['signal'];

        foreach ([
            'instance_not_found',
            'historical_run_command_rejected',
            'unknown_signal',
            'configured_workflow_type_invalid',
            'backend_lock_pressure',
            'control_plane_internal_error',
        ] as $reason) {
            $this->assertContains($reason, $signal['rejection_reasons']);
        }

        foreach ([
            'run_id',
            'target_scope',
            'rejection_category',
            'command_contract_source',
            'command_contract_backfill_needed',
            'command_contract_backfill_available',
            'declared_signals',
            'signal_admission',
            'retryable',
            'error_id',
        ] as $field) {
            $this->assertContains($field, $signal['rejection_fields']);
            $this->assertContains($field, ControlPlaneResponseContract::manifest()['projected_fields']);
        }
    }

    public function test_manifest_publishes_query_rejection_contract_diagnostics(): void
    {
        $query = ControlPlaneResponseContract::manifest()['operations']['query'];

        foreach ([
            'instance_not_found',
            'historical_run_command_rejected',
            'run_not_active',
            'query_not_found',
            'rejected_unknown_query',
            'invalid_query_arguments',
            'workflow_definition_unavailable',
            'query_worker_unavailable',
            'query_worker_incompatible',
            'query_task_not_claimed',
            'query_worker_execution_timeout',
        ] as $reason) {
            $this->assertContains($reason, $query['rejection_reasons']);
        }

        foreach ([
            'run_id',
            'target_scope',
            'query_name',
            'run_status',
            'blocked_reason',
            'validation_errors',
            'result_envelope',
        ] as $field) {
            $this->assertContains($field, ControlPlaneResponseContract::manifest()['projected_fields']);
        }

        foreach ([
            'run_id',
            'target_scope',
            'query_name',
            'run_status',
            'is_terminal',
            'blocked_reason',
            'validation_errors',
        ] as $field) {
            $this->assertContains($field, $query['rejection_fields']);
        }
    }

    public function test_manifest_publishes_correlated_read_failure_contracts(): void
    {
        $operations = ControlPlaneResponseContract::manifest()['operations'];

        foreach (['describe_run', 'history'] as $operation) {
            $this->assertContains('control_plane_internal_error', $operations[$operation]['rejection_reasons']);

            foreach (['workflow_id', 'run_id', 'reason', 'message', 'retryable', 'error_id', 'exception'] as $field) {
                $this->assertContains($field, $operations[$operation]['rejection_fields']);
            }

            $this->assertContains('exception', ControlPlaneResponseContract::manifest()['projected_fields']);
        }
    }

    public function test_attach_propagates_start_rejection_fields_into_the_control_plane_block(): void
    {
        $payload = ControlPlaneResponseContract::attach('start', null, [
            'workflow_id' => 'wf-rejection-1',
            'command_status' => 'rejected',
            'command_source' => 'control_plane',
            'outcome' => 'rejected_compatibility_blocked',
            'reason' => 'compatibility_blocked',
            'rejection_reason' => 'compatibility_blocked',
            'message' => 'Workflow instance [wf-rejection-1] cannot start.',
        ]);

        $this->assertSame('start', $payload['control_plane']['operation']);
        $this->assertSame('rejected', $payload['control_plane']['command_status']);
        $this->assertSame('control_plane', $payload['control_plane']['command_source']);
        $this->assertSame('compatibility_blocked', $payload['control_plane']['rejection_reason']);
        $this->assertSame('rejected_compatibility_blocked', $payload['control_plane']['outcome']);
        $this->assertContains(
            'compatibility_blocked',
            $payload['control_plane']['contract']['rejection_reasons'],
        );
        $this->assertContains(
            'rejection_reason',
            $payload['control_plane']['contract']['rejection_fields'],
        );
    }

    public function test_attach_projects_query_name_into_the_control_plane_block(): void
    {
        $payload = ControlPlaneResponseContract::attach('query', 'currentState', [
            'workflow_id' => 'wf-query-1',
            'query_name' => 'currentState',
            'result' => ['stage' => 'waiting'],
        ]);

        $this->assertSame('currentState', $payload['control_plane']['operation_name']);
        $this->assertSame('query_name', $payload['control_plane']['operation_name_field']);
        $this->assertSame('currentState', $payload['control_plane']['query_name']);
    }

    public function test_attach_projects_principal_into_the_control_plane_block(): void
    {
        $payload = ControlPlaneResponseContract::attach('update', 'approve', [
            'workflow_id' => 'wf-principal-update',
            'update_name' => 'approve',
            'principal' => [
                'type' => 'auth:token',
                'id' => 'workflow-updates-operator',
                'label' => 'Workflow Updates Operator',
            ],
        ]);

        $this->assertSame('approve', $payload['control_plane']['operation_name']);
        $this->assertSame('auth:token', $payload['control_plane']['principal']['type']);
        $this->assertSame('workflow-updates-operator', $payload['control_plane']['principal']['id']);
    }

    public function test_attach_omits_rejection_metadata_for_operations_without_a_rejection_contract(): void
    {
        $payload = ControlPlaneResponseContract::attach('describe', null, [
            'workflow_id' => 'wf-describe-1',
        ]);

        $this->assertArrayNotHasKey('rejection_fields', $payload['control_plane']['contract']);
        $this->assertArrayNotHasKey('rejection_reasons', $payload['control_plane']['contract']);
    }
}
