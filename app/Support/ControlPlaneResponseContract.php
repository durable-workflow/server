<?php

namespace App\Support;

final class ControlPlaneResponseContract
{
    public const SCHEMA = 'durable-workflow.v2.control-plane-response';

    public const VERSION = 1;

    public const CONTRACT_SCHEMA = 'durable-workflow.v2.control-plane-response.contract';

    public const CONTRACT_VERSION = 1;

    public const LEGACY_FIELD_POLICY = 'reject_non_canonical';

    /**
     * @var array<string, string>
     */
    private const LEGACY_FIELDS = [
        'query' => 'query_name',
        'signal' => 'signal_name',
        'update' => 'update_name',
        'wait_policy' => 'wait_for',
    ];

    /**
     * @var array<string, array{operation_name_field: string|null, required_fields: list<string>, success_fields: list<string>, rejection_fields?: list<string>, rejection_reasons?: list<string>}>
     */
    private const OPERATION_CONTRACTS = [
        'list' => [
            'operation_name_field' => null,
            'required_fields' => [],
            'success_fields' => [],
        ],
        'start' => [
            'operation_name_field' => null,
            'required_fields' => [],
            'success_fields' => ['workflow_id', 'outcome'],
            'rejection_fields' => [
                'workflow_id',
                'command_status',
                'command_source',
                'outcome',
                'reason',
                'rejection_reason',
                'message',
            ],
            'rejection_reasons' => [
                'workflow_id_reserved_in_namespace',
                'task_queue_draining',
                'compatibility_blocked',
            ],
        ],
        'import_waterline_v1' => [
            'operation_name_field' => null,
            'required_fields' => [],
            'success_fields' => ['status', 'identity'],
            'rejection_fields' => ['status', 'reason', 'message', 'remediation', 'identity'],
            'rejection_reasons' => [
                'invalid_source_id',
                'unqualified_v1_identity',
                'v1_identity_mismatch',
                'unsupported_source_engine',
                'missing_v1_workflow_class',
                'unsupported_v1_status',
                'v1_projection_changed',
                'v1_projection_namespace_collision',
                'v1_projection_storage_inconsistent',
                'native_v2_identity_collision',
                'target_write_failed',
            ],
        ],
        'describe' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => [],
        ],
        'list_runs' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => [],
        ],
        'describe_run' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['run_id'],
            'rejection_fields' => ['workflow_id', 'run_id', 'reason', 'message', 'retryable', 'error_id', 'exception'],
            'rejection_reasons' => ['control_plane_internal_error'],
        ],
        'debug_workflow' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['run_id', 'diagnostic_status'],
        ],
        'history' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id', 'run_id'],
            'success_fields' => ['next_page_token'],
            'rejection_fields' => ['workflow_id', 'run_id', 'reason', 'message', 'retryable', 'error_id', 'exception'],
            'rejection_reasons' => ['control_plane_internal_error'],
        ],
        'signal' => [
            'operation_name_field' => 'signal_name',
            'required_fields' => ['workflow_id', 'operation_name', 'operation_name_field'],
            'success_fields' => ['outcome'],
            'rejection_fields' => [
                'workflow_id',
                'run_id',
                'signal_name',
                'command_status',
                'command_source',
                'target_scope',
                'outcome',
                'reason',
                'rejection_reason',
                'rejection_category',
                'message',
                'command_contract_source',
                'command_contract_backfill_needed',
                'command_contract_backfill_available',
                'declared_signals',
                'signal_admission',
                'retryable',
                'error_id',
            ],
            'rejection_reasons' => [
                'instance_not_found',
                'historical_run_command_rejected',
                'unknown_signal',
                'invalid_signal_arguments',
                'run_not_active',
                'instance_not_started',
                'selected_run_not_current',
                'configured_workflow_type_invalid',
                'v1_projection_read_only',
                'backend_lock_pressure',
                'control_plane_internal_error',
            ],
        ],
        'query' => [
            'operation_name_field' => 'query_name',
            'required_fields' => ['workflow_id', 'operation_name', 'operation_name_field'],
            'success_fields' => ['result', 'result_envelope'],
            'rejection_fields' => [
                'workflow_id',
                'run_id',
                'query_name',
                'target_scope',
                'result',
                'reason',
                'message',
                'run_status',
                'is_terminal',
                'blocked_reason',
                'validation_errors',
            ],
            'rejection_reasons' => [
                'instance_not_found',
                'historical_run_command_rejected',
                'run_not_active',
                'query_not_found',
                'rejected_unknown_query',
                'invalid_query_arguments',
                'workflow_definition_unavailable',
                'query_rejected',
                'query_worker_unavailable',
                'query_worker_incompatible',
                'query_task_queue_full',
                'query_task_queue_unavailable',
                'query_task_not_claimed',
                'query_worker_execution_timeout',
                'query_worker_timeout',
                'v1_projection_read_only',
            ],
        ],
        'update' => [
            'operation_name_field' => 'update_name',
            'required_fields' => ['workflow_id', 'operation_name', 'operation_name_field'],
            'success_fields' => ['outcome'],
            'rejection_fields' => [
                'workflow_id',
                'run_id',
                'update_id',
                'update_name',
                'update_status',
                'outcome',
                'reason',
                'rejection_reason',
                'validation_errors',
                'retryable',
                'message',
                'remediation',
            ],
            'rejection_reasons' => [
                'update_validator_rejected',
                'update_validator_contract_missing',
                'update_validation_request_id_required',
                'update_validation_idempotency_conflict',
                'update_validation_capability_unsupported',
                'update_validator_worker_unavailable',
                'update_validator_worker_incompatible',
                'update_validator_worker_lost',
                'update_validation_task_not_claimed',
                'update_validation_execution_timeout',
                'duplicate_update_validation_completion',
                'stale_update_validation_completion',
                'invalid_update_arguments',
                'v1_projection_read_only',
            ],
        ],
        'cancel' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['outcome'],
            'rejection_fields' => ['workflow_id', 'run_id', 'reason', 'message', 'remediation'],
            'rejection_reasons' => ['v1_projection_read_only'],
        ],
        'terminate' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['outcome'],
            'rejection_fields' => ['workflow_id', 'run_id', 'reason', 'message', 'remediation'],
            'rejection_reasons' => ['v1_projection_read_only'],
        ],
        'repair' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['outcome'],
            'rejection_fields' => ['workflow_id', 'run_id', 'reason', 'message', 'remediation'],
            'rejection_reasons' => ['v1_projection_read_only'],
        ],
        'archive' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['outcome'],
            'rejection_fields' => ['workflow_id', 'run_id', 'reason', 'message', 'remediation'],
            'rejection_reasons' => ['v1_projection_read_only'],
        ],
    ];

    /**
     * @var list<string>
     */
    private const PROJECTED_FIELDS = [
        'run_id',
        'workflow_type',
        'namespace',
        'business_key',
        'status',
        'status_bucket',
        'run_status',
        'is_terminal',
        'task_queue',
        'run_number',
        'run_count',
        'workflow_count',
        'is_current_run',
        'compatibility',
        'started_at',
        'closed_at',
        'last_progress_at',
        'wait_kind',
        'wait_reason',
        'next_page_token',
        'command_id',
        'command_status',
        'command_source',
        'target_scope',
        'query_name',
        'outcome',
        'reason',
        'rejection_reason',
        'rejection_category',
        'validation_errors',
        'result',
        'result_envelope',
        'update_id',
        'update_status',
        'principal',
        'wait_for',
        'wait_timed_out',
        'wait_timeout_seconds',
        'blocked_reason',
        'message',
        'remediation',
        'execution_owner',
        'identity',
        'command_contract_source',
        'command_contract_backfill_needed',
        'command_contract_backfill_available',
        'declared_signals',
        'signal_admission',
        'diagnostic_status',
        'retryable',
        'error_id',
        'exception',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function attach(string $operation, ?string $operationName, array $payload): array
    {
        $definition = self::definition($operation);
        $contractDefinition = [
            'schema' => self::CONTRACT_SCHEMA,
            'version' => self::CONTRACT_VERSION,
            'legacy_field_policy' => self::LEGACY_FIELD_POLICY,
            'legacy_fields' => self::LEGACY_FIELDS,
            'required_fields' => $definition['required_fields'],
            'success_fields' => $definition['success_fields'],
        ];

        if (($definition['rejection_fields'] ?? []) !== []) {
            $contractDefinition['rejection_fields'] = $definition['rejection_fields'];
        }

        if (($definition['rejection_reasons'] ?? []) !== []) {
            $contractDefinition['rejection_reasons'] = $definition['rejection_reasons'];
        }

        $contract = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'operation' => $operation,
            'workflow_id' => $payload['workflow_id'] ?? null,
            'contract' => $contractDefinition,
        ];

        $operationNameField = $definition['operation_name_field'];

        if ($operationNameField !== null && is_string($operationName) && $operationName !== '') {
            $contract['operation_name'] = $operationName;
            $contract['operation_name_field'] = $operationNameField;
        }

        foreach (self::PROJECTED_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $contract[$field] = $payload[$field];
            }
        }

        $payload['control_plane'] = $contract;

        return $payload;
    }

    /**
     * @return array{
     *     schema: string,
     *     version: int,
     *     contract: array{
     *         schema: string,
     *         version: int,
     *         legacy_field_policy: string,
     *         legacy_fields: array<string, string>,
     *     },
     *     projected_fields: list<string>,
     *     operations: array<string, array{
     *         operation_name_field: string|null,
     *         required_fields: list<string>,
     *         success_fields: list<string>,
     *         rejection_fields?: list<string>,
     *         rejection_reasons?: list<string>,
     *     }>,
     * }
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'contract' => [
                'schema' => self::CONTRACT_SCHEMA,
                'version' => self::CONTRACT_VERSION,
                'legacy_field_policy' => self::LEGACY_FIELD_POLICY,
                'legacy_fields' => self::LEGACY_FIELDS,
            ],
            'projected_fields' => self::PROJECTED_FIELDS,
            'operations' => self::OPERATION_CONTRACTS,
        ];
    }

    /**
     * @return array{operation_name_field: string|null, required_fields: list<string>, success_fields: list<string>, rejection_fields?: list<string>, rejection_reasons?: list<string>}
     */
    private static function definition(string $operation): array
    {
        return self::OPERATION_CONTRACTS[$operation] ?? [
            'operation_name_field' => null,
            'required_fields' => [],
            'success_fields' => [],
        ];
    }
}
