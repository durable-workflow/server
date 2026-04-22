<?php

namespace App\Support;

final class ClientCompatibility
{
    public const SCHEMA = 'durable-workflow.v2.client-compatibility';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authority' => 'protocol_manifests',
            'top_level_version_role' => 'informational',
            'fail_closed' => true,
            'required_protocols' => [
                'auth_composition' => [
                    'schema' => AuthCompositionContract::SCHEMA,
                    'version' => AuthCompositionContract::VERSION,
                ],
                'control_plane' => [
                    'version' => ControlPlaneProtocol::VERSION,
                    'header' => ControlPlaneProtocol::HEADER,
                    'request_contract' => [
                        'schema' => ControlPlaneRequestContract::SCHEMA,
                        'version' => ControlPlaneRequestContract::VERSION,
                    ],
                ],
                'worker_protocol' => [
                    'version' => (string) config('server.worker_protocol.version', WorkerProtocol::VERSION),
                    'header' => WorkerProtocol::HEADER,
                    'external_execution_surface_contract' => [
                        'schema' => ExternalExecutionSurfaceContract::SCHEMA,
                        'version' => ExternalExecutionSurfaceContract::VERSION,
                    ],
                    'external_executor_config_contract' => [
                        'schema' => ExternalExecutorConfigContract::CONTRACT_SCHEMA,
                        'version' => ExternalExecutorConfigContract::CONTRACT_VERSION,
                    ],
                    'external_task_input_contract' => [
                        'schema' => ExternalTaskInputContract::SCHEMA,
                        'version' => ExternalTaskInputContract::VERSION,
                    ],
                    'external_task_result_contract' => [
                        'schema' => ExternalTaskResultContract::SCHEMA,
                        'version' => ExternalTaskResultContract::VERSION,
                    ],
                ],
            ],
            'clients' => [
                'cli' => [
                    'supported_versions' => '0.1.x',
                    'requires' => [
                        'auth_composition.version',
                        'control_plane.version',
                        'control_plane.request_contract',
                    ],
                ],
                'sdk-python' => [
                    'supported_versions' => '0.2.x',
                    'requires' => [
                        'auth_composition.version',
                        'control_plane.version',
                        'control_plane.request_contract',
                        'worker_protocol.version',
                        'worker_protocol.external_execution_surface_contract',
                        'worker_protocol.external_executor_config_contract',
                        'worker_protocol.external_task_input_contract',
                        'worker_protocol.external_task_result_contract',
                    ],
                ],
            ],
        ];
    }
}
