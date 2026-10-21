<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\WorkflowTaskCompletionValidation;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class WorkflowTaskCommandFieldRetentionTest extends TestCase
{
    public function test_http_validation_preserves_the_package_grammar_for_every_command_type(): void
    {
        $commands = $this->commands();
        $types = array_merge(WorkerProtocolVersion::terminalCommandTypes(), WorkerProtocolVersion::nonTerminalCommandTypes());
        $covered = array_column($commands, 'type');
        sort($types);
        sort($covered);
        $this->assertSame($types, $covered, 'Every package command needs a field-retention example.');

        foreach ($commands as $command) {
            $normalized = WorkflowCommandNormalizer::normalize([$command]);
            $validated = Validator::make([
                'lease_owner' => 'original-worker', 'workflow_task_attempt' => 1,
                'commands' => [$command],
            ], WorkflowTaskCompletionValidation::rules())->validate();
            $this->assertSame($normalized, WorkflowCommandNormalizer::normalize($validated['commands']), $command['type']);
        }
    }

    public function test_unknown_fields_stay_out_and_server_principal_fields_are_prohibited(): void
    {
        $input = [
            'lease_owner' => 'original-worker', 'workflow_task_attempt' => 1,
            'commands' => [['type' => 'complete_workflow', 'result' => null, 'unknown' => 'discard']],
        ];
        $validated = Validator::make($input, WorkflowTaskCompletionValidation::rules())->validate();
        $this->assertArrayNotHasKey('unknown', $validated['commands'][0]);
        foreach (['principal_subject', 'principal_method', 'principal_roles', 'principal_tenant', 'principal_claims'] as $field) {
            $forged = $input;
            $forged['commands'][0][$field] = 'forged';
            $this->assertTrue(Validator::make($forged, WorkflowTaskCompletionValidation::rules())->fails(), $field);
        }
    }

    private function commands(): array
    {
        $payload = Serializer::serialize([9007199254740993, null, 'λ']);
        $retry = ['max_attempts' => 2, 'backoff_seconds' => [1], 'non_retryable_error_types' => ['InvalidInput']];

        return [
            ['type' => 'complete_workflow', 'result' => $payload],
            ['type' => 'fail_workflow', 'message' => 'original failure', 'exception_class' => 'OriginalFailure',
                'non_retryable' => true, 'failed_step_sequence' => 1, 'failed_activity_execution_id' => 'original-activity',
                'exception' => ['type' => 'OriginalFailure', 'message' => 'original failure']],
            ['type' => 'continue_as_new', 'workflow_type' => 'next-type', 'queue' => 'next-queue', 'arguments' => $payload],
            ['type' => 'cancel_selection_operation', 'selection_group_id' => 'select-calls:1:2',
                'member_key' => '0', 'member_index' => 0, 'member_base_sequence' => 1, 'member_size' => 1,
                'operation_kind' => 'activity', 'operation_identity' => 'original-activity'],
            ['type' => 'schedule_activity', 'activity_type' => 'activity', 'arguments' => $payload,
                'connection' => 'database', 'queue' => 'activities', 'retry_policy' => $retry,
                'start_to_close_timeout' => 10, 'schedule_to_start_timeout' => 5,
                'schedule_to_close_timeout' => 30, 'heartbeat_timeout' => 2],
            ['type' => 'start_timer', 'delay_seconds' => 0],
            ['type' => 'start_child_workflow', 'workflow_type' => 'child', 'arguments' => $payload,
                'connection' => 'database', 'queue' => 'children', 'parent_close_policy' => 'abandon',
                'cancellation_policy' => 'abandon', 'execution_timeout_seconds' => 30,
                'run_timeout_seconds' => 10, 'retry_policy' => $retry],
            ['type' => 'start_service_operation', 'endpoint_name' => 'endpoint', 'service_name' => 'service',
                'operation_name' => 'operation', 'request_payload' => $payload, 'namespace' => 'target',
                'caller_namespace' => 'source', 'service_call_id' => 'call-id', 'idempotency_key' => 'key',
                'mode_override' => 'async', 'wait_for' => 'completed', 'wait_timeout_seconds' => 0,
                'target_workflow_instance_id' => 'target-workflow', 'target_workflow_run_id' => 'target-run',
                'connection' => 'database', 'queue' => 'target-queue', 'business_key' => 'business-key',
                'labels' => ['label'], 'memo' => ['key' => 'value'], 'search_attributes' => ['State' => 'active'],
                'duplicate_start_policy' => 'return_existing_active', 'metadata' => ['key' => 'value'],
                'request_payload_reference' => 'reference-id'],
            ['type' => 'complete_update', 'update_id' => 'update-id', 'result' => $payload],
            ['type' => 'fail_update', 'update_id' => 'update-id', 'message' => 'failure',
                'exception_type' => 'UpdateFailure', 'non_retryable' => true],
            ['type' => 'record_side_effect', 'result' => $payload],
            ['type' => 'record_local_activity', 'activity_type' => 'local', 'outcome' => 'completed',
                'arguments' => $payload, 'result' => $payload, 'retry_policy' => $retry,
                'start_to_close_timeout' => 10, 'schedule_to_close_timeout' => 30, 'heartbeat_timeout' => 2,
                'attempts' => [['attempt_id' => 'local-attempt', 'attempt_number' => 1, 'outcome' => 'completed',
                    'duration_ms' => 3, 'heartbeats' => [['elapsed_ms' => 1, 'details' => ['step' => 'started']]]]]],
            ['type' => 'record_version_marker', 'change_id' => 'change', 'version' => 0, 'min_supported' => -1, 'max_supported' => 1],
            ['type' => 'upsert_memo', 'entries' => MemoPayload::mapEnvelope(['key' => 'λ', 'delete' => null])],
            ['type' => 'upsert_search_attributes', 'attributes' => ['State' => 'active'], 'attribute_types' => ['State' => 'keyword']],
            ['type' => 'open_condition_wait', 'condition_key' => 'condition', 'timeout_seconds' => 0,
                'condition_definition_fingerprint' => 'fingerprint', 'condition_wait_occurrence_id' => 'occurrence'],
            ['type' => 'open_signal_wait', 'signal_name' => 'signal', 'timeout_seconds' => 0],
        ];
    }
}
