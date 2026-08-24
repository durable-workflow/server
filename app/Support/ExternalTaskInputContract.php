<?php

namespace App\Support;

final class ExternalTaskInputContract
{
    public const SCHEMA = 'durable-workflow.v2.external-task-input.contract';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'unknown_field_policy' => 'ignore_additive_reject_unknown_required',
            'versioning' => [
                'add_optional_fields' => 'minor',
                'add_required_fields' => 'major',
                'rename_or_remove_fields' => 'major',
                'unknown_fields' => 'must_be_ignored_unless_declared_required_by_a_supported_version',
            ],
            'payload_support' => [
                'inline' => 'Handlers receive codec-tagged payload envelopes with codec and blob fields.',
                'external_payload' => 'Handlers resolve opaque external payload references through the authenticated namespace runtime before decode; provider-specific references are unsupported.',
                'unsupported_codec' => 'Handlers that cannot decode payload.codec must fail the task with unsupported_payload_codec.',
            ],
            'scope' => [
                'activity_grade_external_execution' => [
                    'task_kinds' => ['activity_task'],
                    'fixture_keys' => ['activity_task'],
                    'carrier_expectation' => 'External carriers execute activity_task inputs as bounded handler invocations and return the external task result envelope.',
                ],
                'worker_protocol_runtime' => [
                    'task_kinds' => ['workflow_task'],
                    'fixture_keys' => ['workflow_task', 'condition_timeout_history'],
                    'carrier_expectation' => 'Workflow_task inputs are published for SDK/runtime compatibility and worker-protocol drift tests; they are not activity-grade external handler inputs.',
                ],
            ],
            'envelopes' => [
                'workflow_task' => self::workflowTaskEnvelope(),
                'activity_task' => self::activityTaskEnvelope(),
            ],
            'fixtures' => [
                'workflow_task' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-input.workflow-task.v1',
                    self::workflowTaskFixture(),
                ),
                'condition_timeout_history' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-input.condition-timeout-history.v1',
                    self::conditionTimeoutHistoryFixture(),
                ),
                'activity_task' => self::fixtureArtifact(
                    'durable-workflow.v2.external-task-input.activity-task.v1',
                    self::activityTaskFixture(),
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $example
     * @return array<string, mixed>
     */
    private static function fixtureArtifact(string $artifact, array $example): array
    {
        return [
            'artifact' => $artifact,
            'media_type' => 'application/vnd.durable-workflow.external-task-input+json',
            'schema' => 'durable-workflow.v2.external-task-input',
            'version' => self::VERSION,
            'sha256' => hash('sha256', (string) json_encode($example, JSON_UNESCAPED_SLASHES)),
            'example' => $example,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workflowTaskEnvelope(): array
    {
        return [
            'kind' => 'workflow_task',
            'scope' => 'worker_protocol_runtime',
            'external_execution_role' => 'not_activity_grade_handler_input',
            'required_fields' => [
                'schema',
                'version',
                'task',
                'workflow',
                'lease',
                'payloads',
                'history',
                'headers',
            ],
            'task_fields' => [
                'id' => ['source' => 'task.task_id', 'type' => 'string'],
                'kind' => ['constant' => 'workflow_task'],
                'attempt' => ['source' => 'task.workflow_task_attempt', 'type' => 'integer', 'minimum' => 1],
                'task_queue' => ['source' => 'task.task_queue', 'type' => 'string'],
                'handler' => ['source' => 'task.workflow_type', 'type' => 'string', 'nullable' => true],
                'connection' => ['source' => 'task.connection', 'type' => 'string', 'nullable' => true],
                'compatibility' => ['source' => 'task.compatibility', 'type' => 'string', 'nullable' => true],
                'idempotency_key' => ['source' => 'task.task_id + task.workflow_task_attempt', 'type' => 'string'],
            ],
            'workflow_fields' => [
                'id' => ['source' => 'task.workflow_id', 'type' => 'string'],
                'run_id' => ['source' => 'task.run_id', 'type' => 'string'],
                'status' => ['source' => 'task.run_status', 'type' => 'string', 'nullable' => true],
                'resume' => [
                    'source' => 'stable resume context fields from worker poll task',
                    'type' => 'object',
                    'nullable_fields' => [
                        'workflow_wait_kind',
                        'open_wait_id',
                        'resume_source_kind',
                        'resume_source_id',
                        'workflow_update_id',
                        'workflow_signal_id',
                        'signal_name',
                        'signal_wait_id',
                        'workflow_command_id',
                        'activity_execution_id',
                        'activity_attempt_id',
                        'activity_type',
                        'child_call_id',
                        'child_workflow_run_id',
                        'workflow_sequence',
                        'workflow_event_type',
                        'timer_id',
                        'condition_wait_id',
                        'condition_key',
                        'condition_definition_fingerprint',
                    ],
                ],
            ],
            'lease_fields' => [
                'owner' => ['source' => 'task.lease_owner', 'type' => 'string'],
                'expires_at' => ['source' => 'task.lease_expires_at', 'type' => 'string', 'format' => 'date-time'],
                'heartbeat_endpoint' => ['source' => 'worker protocol route', 'type' => 'string'],
            ],
            'payload_fields' => [
                'arguments' => ['source' => 'task.arguments', 'nullable' => true],
                'signal_arguments' => [
                    'source' => 'task.signal_arguments',
                    'nullable' => true,
                    'meaning' => 'Codec-tagged arguments for the accepted signal that resumed this workflow task, when workflow_wait_kind is signal.',
                ],
            ],
            'history_fields' => [
                'events' => ['source' => 'task.history_events', 'type' => 'array'],
                'last_sequence' => ['source' => 'task.last_history_sequence', 'type' => 'integer'],
                'next_page_token' => ['source' => 'task.next_history_page_token', 'type' => 'string', 'nullable' => true],
                'encoding' => ['source' => 'task.history_events_encoding', 'type' => 'string', 'nullable' => true],
            ],
            'history_event_contracts' => [
                'condition_timeout' => [
                    'timer_kind' => 'condition_timeout',
                    'condition_identity_field' => 'condition_wait_id',
                    'timer_identity_field' => 'timer_id',
                    'timer_events' => [
                        'TimerScheduled' => [
                            'required_payload_fields' => [
                                'timer_id',
                                'sequence',
                                'timer_kind',
                                'condition_wait_id',
                            ],
                        ],
                        'TimerFired' => [
                            'required_payload_fields' => [
                                'timer_id',
                                'sequence',
                                'timer_kind',
                                'condition_wait_id',
                            ],
                        ],
                        'TimerCancelled' => [
                            'required_payload_fields' => [
                                'timer_id',
                                'sequence',
                                'timer_kind',
                                'condition_wait_id',
                            ],
                        ],
                    ],
                    'correlation' => [
                        'match_fields' => ['timer_id', 'condition_wait_id'],
                        'condition_and_timer_sequences_may_differ' => true,
                        'event_adjacency_required' => false,
                    ],
                    'replay' => [
                        'advances_ordinary_command_cursor' => false,
                        'legacy_rows_without_explicit_identity' => 'supported_for_replay_reading',
                    ],
                    'conformance' => [
                        'fixture_cluster_info_path' => 'worker_protocol.external_task_input_contract.fixtures.condition_timeout_history.example.history.events',
                        'required_worker_runtimes' => ['sdk-php', 'sdk-python', 'sdk-rust'],
                        'required_interleaved_event_types' => ['UpdateApplied', 'SignalReceived'],
                        'required_timer_event_types' => ['TimerScheduled', 'TimerFired', 'TimerCancelled'],
                        'assertions' => [
                            'timer_rows_are_self_identifying',
                            'timer_sequence_may_differ_from_condition_sequence',
                            'ordinary_activity_timer_cursor_does_not_advance',
                            'workflow_reaches_terminal_state',
                        ],
                    ],
                ],
            ],
            'intentionally_omitted' => [
                'server process identity',
                'database primary keys not exposed as task or run identifiers',
                'transport headers unrelated to durable task handling',
            ],
            'boundary' => [
                'workflow_replay' => 'owned_by_sdk_or_runtime_worker',
                'history_interpretation' => 'owned_by_sdk_or_runtime_worker',
                'external_carrier_use' => 'validate_shape_only_unless_the_carrier_is_a_workflow_runtime',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function activityTaskEnvelope(): array
    {
        return [
            'kind' => 'activity_task',
            'scope' => 'activity_grade_external_execution',
            'external_execution_role' => 'activity_grade_handler_input',
            'required_fields' => [
                'schema',
                'version',
                'task',
                'workflow',
                'lease',
                'payloads',
                'deadlines',
                'headers',
            ],
            'task_fields' => [
                'id' => ['source' => 'task.task_id', 'type' => 'string'],
                'kind' => ['constant' => 'activity_task'],
                'attempt' => ['source' => 'task.attempt_number', 'type' => 'integer', 'minimum' => 1],
                'activity_attempt_id' => ['source' => 'task.activity_attempt_id', 'type' => 'string'],
                'task_queue' => ['source' => 'task.task_queue', 'type' => 'string'],
                'handler' => ['source' => 'task.activity_type', 'type' => 'string'],
                'connection' => ['source' => 'task.connection', 'type' => 'string', 'nullable' => true],
                'external_executor' => [
                    'source' => 'task.external_executor',
                    'type' => 'object',
                    'nullable' => true,
                    'meaning' => 'Resolved config-first handler mapping when DW_EXTERNAL_EXECUTOR_CONFIG_PATH matches this activity task.',
                ],
                'idempotency_key' => ['source' => 'task.activity_attempt_id', 'type' => 'string'],
            ],
            'workflow_fields' => [
                'id' => ['source' => 'task.workflow_id', 'type' => 'string'],
                'run_id' => ['source' => 'task.run_id', 'type' => 'string'],
            ],
            'lease_fields' => [
                'owner' => ['source' => 'task.lease_owner', 'type' => 'string'],
                'expires_at' => ['source' => 'task.lease_expires_at', 'type' => 'string', 'format' => 'date-time'],
                'heartbeat_endpoint' => ['source' => 'worker protocol route', 'type' => 'string'],
            ],
            'payload_fields' => [
                'arguments' => ['source' => 'task.arguments', 'nullable' => true],
            ],
            'deadline_fields' => [
                'schedule_to_start' => ['source' => 'task.deadlines.schedule_to_start', 'nullable' => true],
                'start_to_close' => ['source' => 'task.deadlines.start_to_close', 'nullable' => true],
                'schedule_to_close' => ['source' => 'task.deadlines.schedule_to_close', 'nullable' => true],
                'heartbeat' => ['source' => 'task.deadlines.heartbeat', 'nullable' => true],
            ],
            'intentionally_omitted' => [
                'workflow history events',
                'server process identity',
                'transport headers unrelated to durable task handling',
            ],
            'boundary' => [
                'workflow_replay' => 'not_exposed_to_handler',
                'history_interpretation' => 'not_exposed_to_handler',
                'external_carrier_use' => 'execute_bounded_activity_handler_and_return_external_task_result',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workflowTaskFixture(): array
    {
        return [
            'schema' => 'durable-workflow.v2.external-task-input',
            'version' => self::VERSION,
            'task' => [
                'id' => 'wft_01HV7D2K3X9K2M7YVQ4T0A1B2C',
                'kind' => 'workflow_task',
                'attempt' => 2,
                'task_queue' => 'billing-workflows',
                'handler' => 'billing.invoice.workflow',
                'connection' => null,
                'compatibility' => 'build-2026-04-22',
                'idempotency_key' => 'wft_01HV7D2K3X9K2M7YVQ4T0A1B2C:2',
            ],
            'workflow' => [
                'id' => 'invoice-2026-0001',
                'run_id' => 'run_01HV7D2M5N1HDZ4Z8XBQJY6P9R',
                'status' => 'running',
                'resume' => [
                    'workflow_wait_kind' => 'activity',
                    'open_wait_id' => 'activity:act_01HV7D2N5E4RKDNCQ44B9DHD9W',
                    'resume_source_kind' => 'activity',
                    'resume_source_id' => 'act_01HV7D2N5E4RKDNCQ44B9DHD9W',
                    'workflow_update_id' => null,
                    'workflow_signal_id' => null,
                    'signal_name' => null,
                    'signal_wait_id' => null,
                    'workflow_command_id' => 'cmd_01HV7D2N19FHPK3K0V33DD8TM8',
                    'activity_execution_id' => 'act_01HV7D2N5E4RKDNCQ44B9DHD9W',
                    'activity_attempt_id' => 'attempt_01HV7D2Q8G6HXH5PF1MF4J7P6Z',
                    'activity_type' => 'billing.charge-card',
                    'child_call_id' => null,
                    'child_workflow_run_id' => null,
                    'workflow_sequence' => 42,
                    'workflow_event_type' => 'ActivityCompleted',
                    'timer_id' => null,
                    'condition_wait_id' => null,
                    'condition_key' => null,
                    'condition_definition_fingerprint' => null,
                ],
            ],
            'lease' => [
                'owner' => 'worker-python-a',
                'expires_at' => '2026-04-22T01:05:00.000000Z',
                'heartbeat_endpoint' => '/api/worker/workflow-tasks/wft_01HV7D2K3X9K2M7YVQ4T0A1B2C/heartbeat',
            ],
            'payloads' => [
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => 'BASE64_AVRO_ARGUMENTS',
                ],
                'signal_arguments' => null,
            ],
            'history' => [
                'events' => [
                    [
                        'event_id' => 'evt_01HV7D2M7A72M5JHVR75MB4BF3',
                        'event_type' => 'WorkflowStarted',
                        'sequence' => 1,
                    ],
                ],
                'last_sequence' => 42,
                'next_page_token' => 'eyJhZnRlcl9zZXF1ZW5jZSI6NDJ9',
                'encoding' => null,
            ],
            'headers' => [
                'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function conditionTimeoutHistoryFixture(): array
    {
        $fixture = self::workflowTaskFixture();
        $fixture['lease']['owner'] = 'worker-runtime-a';
        $fixture['workflow']['resume'] = [
            ...$fixture['workflow']['resume'],
            'workflow_wait_kind' => 'condition',
            'open_wait_id' => 'condition:41',
            'resume_source_kind' => 'timer',
            'resume_source_id' => 'timer:42',
            'workflow_command_id' => null,
            'activity_execution_id' => null,
            'activity_attempt_id' => null,
            'activity_type' => null,
            'workflow_sequence' => 42,
            'workflow_event_type' => 'TimerFired',
            'timer_id' => 'timer:42',
            'condition_wait_id' => 'condition:41',
            'condition_key' => 'invoice.approved',
            'condition_definition_fingerprint' => 'sha256:condition-41',
        ];
        $fixture['history'] = [
            'events' => [
                [
                    'event_id' => 'evt_01HV7D2M7A72M5JHVR75MB4BF3',
                    'event_type' => 'WorkflowStarted',
                    'sequence' => 1,
                ],
                [
                    'event_id' => 'evt_condition_opened_41',
                    'event_type' => 'ConditionWaitOpened',
                    'sequence' => 2,
                    'payload' => [
                        'sequence' => 41,
                        'condition_wait_id' => 'condition:41',
                        'condition_key' => 'invoice.approved',
                        'condition_definition_fingerprint' => 'sha256:condition-41',
                        'timeout_seconds' => 30,
                    ],
                ],
                [
                    'event_id' => 'evt_timer_scheduled_42',
                    'event_type' => 'TimerScheduled',
                    'sequence' => 3,
                    'payload' => [
                        'sequence' => 42,
                        'timer_id' => 'timer:42',
                        'delay_seconds' => 30,
                        'timer_kind' => 'condition_timeout',
                        'condition_wait_id' => 'condition:41',
                        'condition_key' => 'invoice.approved',
                        'condition_definition_fingerprint' => 'sha256:condition-41',
                    ],
                ],
                [
                    'event_id' => 'evt_update_applied_41',
                    'event_type' => 'UpdateApplied',
                    'sequence' => 4,
                    'payload' => [
                        'sequence' => 41,
                        'update_id' => 'update:invoice-name',
                        'update_name' => 'rename-invoice',
                        'arguments' => '["August invoice"]',
                    ],
                ],
                [
                    'event_id' => 'evt_signal_received_41',
                    'event_type' => 'SignalReceived',
                    'sequence' => 5,
                    'payload' => [
                        'workflow_sequence' => 41,
                        'signal_name' => 'approval-observed',
                        'value' => '["finance"]',
                    ],
                ],
                [
                    'event_id' => 'evt_timer_fired_42',
                    'event_type' => 'TimerFired',
                    'sequence' => 6,
                    'payload' => [
                        'sequence' => 42,
                        'timer_id' => 'timer:42',
                        'delay_seconds' => 30,
                        'timer_kind' => 'condition_timeout',
                        'condition_wait_id' => 'condition:41',
                        'condition_key' => 'invoice.approved',
                        'condition_definition_fingerprint' => 'sha256:condition-41',
                    ],
                ],
                [
                    'event_id' => 'evt_condition_opened_43',
                    'event_type' => 'ConditionWaitOpened',
                    'sequence' => 7,
                    'payload' => [
                        'sequence' => 43,
                        'condition_wait_id' => 'condition:43',
                        'condition_key' => 'invoice.posted',
                        'condition_definition_fingerprint' => 'sha256:condition-43',
                        'timeout_seconds' => 60,
                    ],
                ],
                [
                    'event_id' => 'evt_timer_scheduled_44',
                    'event_type' => 'TimerScheduled',
                    'sequence' => 8,
                    'payload' => [
                        'sequence' => 44,
                        'timer_id' => 'timer:44',
                        'delay_seconds' => 60,
                        'timer_kind' => 'condition_timeout',
                        'condition_wait_id' => 'condition:43',
                        'condition_key' => 'invoice.posted',
                        'condition_definition_fingerprint' => 'sha256:condition-43',
                    ],
                ],
                [
                    'event_id' => 'evt_condition_satisfied_43',
                    'event_type' => 'ConditionWaitSatisfied',
                    'sequence' => 9,
                    'payload' => [
                        'sequence' => 43,
                        'condition_wait_id' => 'condition:43',
                        'condition_key' => 'invoice.posted',
                        'timer_id' => 'timer:44',
                    ],
                ],
                [
                    'event_id' => 'evt_timer_cancelled_44',
                    'event_type' => 'TimerCancelled',
                    'sequence' => 10,
                    'payload' => [
                        'sequence' => 44,
                        'timer_id' => 'timer:44',
                        'delay_seconds' => 60,
                        'timer_kind' => 'condition_timeout',
                        'condition_wait_id' => 'condition:43',
                        'condition_key' => 'invoice.posted',
                        'condition_definition_fingerprint' => 'sha256:condition-43',
                    ],
                ],
            ],
            'last_sequence' => 10,
            'next_page_token' => 'eyJhZnRlcl9zZXF1ZW5jZSI6MTB9',
            'encoding' => null,
        ];

        return $fixture;
    }

    /**
     * @return array<string, mixed>
     */
    private static function activityTaskFixture(): array
    {
        return [
            'schema' => 'durable-workflow.v2.external-task-input',
            'version' => self::VERSION,
            'task' => [
                'id' => 'acttask_01HV7D3G3G61TAH2YB5RK45XJS',
                'kind' => 'activity_task',
                'attempt' => 1,
                'activity_attempt_id' => 'attempt_01HV7D3KJ1C8WQNNY8MVM8J40X',
                'task_queue' => 'billing-activities',
                'handler' => 'billing.charge-card',
                'connection' => null,
                'idempotency_key' => 'attempt_01HV7D3KJ1C8WQNNY8MVM8J40X',
            ],
            'workflow' => [
                'id' => 'invoice-2026-0001',
                'run_id' => 'run_01HV7D2M5N1HDZ4Z8XBQJY6P9R',
            ],
            'lease' => [
                'owner' => 'worker-python-a',
                'expires_at' => '2026-04-22T01:10:00.000000Z',
                'heartbeat_endpoint' => '/api/worker/activity-tasks/acttask_01HV7D3G3G61TAH2YB5RK45XJS/heartbeat',
            ],
            'payloads' => [
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => 'BASE64_AVRO_ARGUMENTS',
                ],
            ],
            'deadlines' => [
                'schedule_to_start' => '2026-04-22T01:02:00.000000Z',
                'start_to_close' => '2026-04-22T01:07:00.000000Z',
                'schedule_to_close' => '2026-04-22T01:12:00.000000Z',
                'heartbeat' => '2026-04-22T01:04:00.000000Z',
            ],
            'headers' => [
                'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-7a085853722dc6d2-00',
            ],
        ];
    }
}
