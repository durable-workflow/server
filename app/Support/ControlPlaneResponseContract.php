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
     * @var array<string, array{operation_name_field: string|null, required_fields: list<string>, success_fields: list<string>}>
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
        ],
        'history' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id', 'run_id'],
            'success_fields' => ['next_page_token'],
        ],
        'signal' => [
            'operation_name_field' => 'signal_name',
            'required_fields' => ['workflow_id', 'operation_name', 'operation_name_field'],
            'success_fields' => ['outcome'],
        ],
        'query' => [
            'operation_name_field' => 'query_name',
            'required_fields' => ['workflow_id', 'operation_name', 'operation_name_field'],
            'success_fields' => ['result'],
        ],
        'update' => [
            'operation_name_field' => 'update_name',
            'required_fields' => ['workflow_id', 'operation_name', 'operation_name_field'],
            'success_fields' => ['outcome'],
        ],
        'cancel' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['outcome'],
        ],
        'terminate' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['outcome'],
        ],
        'repair' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['outcome'],
        ],
        'archive' => [
            'operation_name_field' => null,
            'required_fields' => ['workflow_id'],
            'success_fields' => ['outcome'],
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
        'outcome',
        'reason',
        'rejection_reason',
        'validation_errors',
        'result',
        'result_envelope',
        'update_id',
        'update_status',
        'wait_for',
        'wait_timed_out',
        'wait_timeout_seconds',
        'blocked_reason',
        'message',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function attach(string $operation, ?string $operationName, array $payload): array
    {
        $definition = self::definition($operation);
        $contract = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'operation' => $operation,
            'workflow_id' => $payload['workflow_id'] ?? null,
            'contract' => [
                'schema' => self::CONTRACT_SCHEMA,
                'version' => self::CONTRACT_VERSION,
                'legacy_field_policy' => self::LEGACY_FIELD_POLICY,
                'legacy_fields' => self::LEGACY_FIELDS,
                'required_fields' => $definition['required_fields'],
                'success_fields' => $definition['success_fields'],
            ],
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
     * @return array{operation_name_field: string|null, required_fields: list<string>, success_fields: list<string>}
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
