<?php

namespace App\Support;

final class ServerTopology
{
    public const SCHEMA = 'durable-workflow.v2.role-topology';

    public const VERSION = 1;

    private const SUPPORTED_SHAPES = [
        'embedded',
        'standalone_server',
        'split_control_execution',
    ];

    private const ROLE_VOCABULARY = [
        'api_ingress',
        'control_plane',
        'matching',
        'history_projection',
        'scheduler',
        'execution_plane',
    ];

    private const CURRENT_SERVER_NODE_ROLES = [
        'api_ingress',
        'control_plane',
        'matching',
        'history_projection',
    ];

    /**
     * @return array{
     *     schema: string,
     *     version: int,
     *     supported_shapes: array<int, string>,
     *     role_vocabulary: array<int, string>,
     *     current_shape: string,
     *     current_roles: array<int, string>,
     *     execution_mode: string
     * }
     */
    public static function info(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'supported_shapes' => self::SUPPORTED_SHAPES,
            'role_vocabulary' => self::ROLE_VOCABULARY,
            'current_shape' => 'standalone_server',
            'current_roles' => self::CURRENT_SERVER_NODE_ROLES,
            'execution_mode' => self::executionMode(),
        ];
    }

    private static function executionMode(): string
    {
        return config('server.mode') === 'embedded'
            ? 'local_queue_worker'
            : 'remote_worker_protocol';
    }
}
