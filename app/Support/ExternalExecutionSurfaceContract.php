<?php

namespace App\Support;

final class ExternalExecutionSurfaceContract
{
    public const SCHEMA = 'durable-workflow.v2.external-execution-surface.contract';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'product_boundary' => [
                'name' => 'activity_grade_external_execution',
                'definition' => 'Durable, bounded, external task execution for operator, platform, and integration automation.',
                'primary_wedge' => 'operator_platform_integration',
                'secondary_wedge' => 'agent_or_script_driven_handlers',
                'contract_first' => true,
                'carrier_second' => true,
            ],
            'runtime_boundary' => [
                'external_handlers_may' => [
                    'execute one leased workflow or activity task',
                    'heartbeat lease progress through the worker protocol',
                    'return a structured success or failure envelope',
                    'start, signal, update, or hand off bounded work through bridge adapters',
                ],
                'external_handlers_must_not' => [
                    'interpret workflow replay semantics',
                    'own ContinueAsNew behavior',
                    'apply signal, update, or query ordering rules outside the server/runtime contract',
                    'mutate event history directly',
                    'act as an unbounded workflow runtime',
                ],
            ],
            'mvp' => [
                'contract' => 'external_task_input_and_result_envelopes',
                'carrier_requirement' => 'at_least_one_supported_carrier',
                'journeys' => [
                    'operator_runs_bounded_maintenance_activity',
                    'integration_bridge_hands_off_event_to_workflow_ingress',
                ],
            ],
            'contract_seams' => [
                'input_envelope' => [
                    'schema' => ExternalTaskInputContract::SCHEMA,
                    'version' => ExternalTaskInputContract::VERSION,
                    'status' => 'published',
                    'cluster_info_path' => 'worker_protocol.external_task_input_contract',
                ],
                'result_envelope' => [
                    'schema' => ExternalTaskResultContract::SCHEMA,
                    'version' => ExternalTaskResultContract::VERSION,
                    'status' => 'published',
                    'cluster_info_path' => 'worker_protocol.external_task_result_contract',
                ],
                'auth_profile_tls_composition' => [
                    'status' => 'planned',
                    'required_outcome' => 'deterministic credential, profile, environment, and TLS precedence for external carriers',
                ],
                'handler_mappings' => [
                    'schema' => ExternalExecutorConfigContract::CONTRACT_SCHEMA,
                    'version' => ExternalExecutorConfigContract::CONTRACT_VERSION,
                    'status' => 'published',
                    'cluster_info_path' => 'worker_protocol.external_executor_config_contract',
                    'required_outcome' => 'configuration-first mapping from task kind, queue, and handler name to an external carrier invocation',
                ],
                'invocable_http_carrier' => [
                    'schema' => InvocableCarrierContract::SCHEMA,
                    'version' => InvocableCarrierContract::VERSION,
                    'status' => 'published',
                    'cluster_info_path' => 'worker_protocol.invocable_carrier_contract',
                    'required_outcome' => 'activity-only HTTP invocation contract with stable request, response, failure, auth, and rollout boundaries',
                ],
                'bridge_adapters' => [
                    'status' => 'planned',
                    'required_outcome' => 'bounded ingress and handoff adapters with explicit duplicate, auth, malformed payload, and routing outcomes',
                ],
                'payload_external_storage' => [
                    'status' => 'planned',
                    'required_outcome' => 'codec-tagged external payload references with fail-closed unsupported-provider behavior',
                ],
                'admission_and_rollout_safety' => [
                    'status' => 'partially_published',
                    'required_outcome' => 'bounded task admission, drain, retry, and rollback controls visible to operators',
                ],
            ],
            'carrier_neutrality' => [
                'valid_carriers' => [
                    'poll_based_cli_or_daemon',
                    'http_handler_invocation',
                    'queue_backed_worker',
                    'serverless_invocation',
                ],
                'carrier_requirements' => [
                    'must_emit_declared_input_schema',
                    'must_accept_declared_result_schema',
                    'must_preserve_task_id_attempt_and_idempotency_key',
                    'must_map transport failures to structured failure or malformed_output outcomes',
                    'must keep auth, TLS, profile, and environment resolution deterministic',
                ],
            ],
            'non_goals' => [
                'shell_as_workflow_language',
                'agents_as_primary_product_frame',
                'external_handlers_as_replay_runtimes',
                'generic_message_broker_platform',
            ],
        ];
    }
}
