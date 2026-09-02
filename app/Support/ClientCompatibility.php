<?php

namespace App\Support;

final class ClientCompatibility
{
    public const SCHEMA = 'durable-workflow.v2.client-compatibility';

    public const VERSION = 2;

    private const STABLE_2_X = '>=2.0.0,<3.0.0';

    private const SUPPORTED_SDK_VERSIONS = [
        'php' => self::STABLE_2_X,
        'python' => self::STABLE_2_X,
        'rust' => self::STABLE_2_X,
        'cli' => self::STABLE_2_X,
    ];

    /**
     * @return array{php: string, python: string, rust: string, cli: string}
     */
    public static function supportedSdkVersions(): array
    {
        return self::SUPPORTED_SDK_VERSIONS;
    }

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
            'skew_refusal_matrix_contract' => [
                'schema' => SkewRefusalMatrixContract::SCHEMA,
                'version' => SkewRefusalMatrixContract::VERSION,
                'cluster_info_path' => 'skew_refusal_matrix_contract',
            ],
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
                    'invocable_carrier_contract' => [
                        'schema' => InvocableCarrierContract::SCHEMA,
                        'version' => InvocableCarrierContract::VERSION,
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
                'sdk-php' => [
                    'supported_versions' => self::SUPPORTED_SDK_VERSIONS['php'],
                    'requires' => [
                        'auth_composition.version',
                        'control_plane.version',
                        'control_plane.request_contract',
                        'worker_protocol.version',
                    ],
                ],
                'cli' => [
                    'supported_versions' => self::SUPPORTED_SDK_VERSIONS['cli'],
                    'requires' => [
                        'auth_composition.version',
                        'control_plane.version',
                        'control_plane.request_contract',
                    ],
                ],
                'sdk-python' => [
                    'supported_versions' => self::SUPPORTED_SDK_VERSIONS['python'],
                    'requires' => [
                        'auth_composition.version',
                        'control_plane.version',
                        'control_plane.request_contract',
                        'worker_protocol.version',
                        'worker_protocol.external_execution_surface_contract',
                        'worker_protocol.external_executor_config_contract',
                        'worker_protocol.invocable_carrier_contract',
                        'worker_protocol.external_task_input_contract',
                        'worker_protocol.external_task_result_contract',
                    ],
                ],
                'sdk-rust' => [
                    'supported_versions' => self::SUPPORTED_SDK_VERSIONS['rust'],
                    'requires' => [
                        'auth_composition.version',
                        'control_plane.version',
                        'control_plane.request_contract',
                        'worker_protocol.version',
                        'worker_protocol.external_execution_surface_contract',
                        'worker_protocol.external_executor_config_contract',
                        'worker_protocol.invocable_carrier_contract',
                        'worker_protocol.external_task_input_contract',
                        'worker_protocol.external_task_result_contract',
                    ],
                ],
            ],
        ];
    }
}
