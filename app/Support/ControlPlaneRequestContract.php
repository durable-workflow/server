<?php

namespace App\Support;

use Workflow\Serializers\CodecRegistry;

final class ControlPlaneRequestContract
{
    public const SCHEMA = 'durable-workflow.v2.control-plane-request.contract';

    public const VERSION = 1;

    /**
     * @return array{
     *     schema: string,
     *     version: int,
     *     operations: array<string, array<string, mixed>>,
     * }
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'operations' => [
                'list' => [
                    'fields' => [
                        'status' => [
                            'canonical_values' => ['running', 'completed', 'failed'],
                            'rejected_aliases' => [
                                'cancelled' => 'failed',
                                'terminated' => 'failed',
                                'pending' => 'running',
                                'waiting' => 'running',
                            ],
                        ],
                    ],
                ],
                'start' => [
                    'fields' => [
                        'duplicate_policy' => [
                            'canonical_values' => ['fail', 'use-existing'],
                            'rejected_aliases' => [
                                'use_existing' => 'use-existing',
                            ],
                        ],
                        'execution_timeout_seconds' => [
                            'type' => 'integer',
                            'min' => 1,
                        ],
                        'run_timeout_seconds' => [
                            'type' => 'integer',
                            'min' => 1,
                        ],
                        'payload_codec' => [
                            'type' => 'string',
                            'canonical_values' => CodecRegistry::names(),
                            'description' => 'Codec used for serializing workflow payloads. Inferred from input envelope when omitted.',
                        ],
                    ],
                    'unsupported_fields' => [
                        'workflow_execution_timeout',
                        'workflow_run_timeout',
                        'workflow_task_timeout',
                        'retry_policy',
                        'idempotency_key',
                        'request_id',
                    ],
                ],
                'update' => [
                    'fields' => [
                        'wait_for' => [
                            'canonical_values' => ['accepted', 'completed'],
                        ],
                    ],
                    'removed_fields' => [
                        'wait_policy' => 'Use wait_for.',
                    ],
                ],
            ],
        ];
    }
}
