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
                        'payload_codec' => array_filter([
                            'type' => 'string',
                            // Only language-neutral codecs are advertised to
                            // polyglot clients. Engine-specific codecs (e.g.
                            // PHP SerializableClosure) remain supported for
                            // decoding legacy rows and round-tripping
                            // pre-serialized blobs, but they are exposed
                            // separately so non-PHP SDKs do not offer them
                            // as generic start choices.
                            'canonical_values' => CodecRegistry::universal(),
                            'engine_specific_values' => CodecRegistry::engineSpecific() !== []
                                ? CodecRegistry::engineSpecific()
                                : null,
                            'description' => 'Codec used for serializing workflow payloads. Inferred from input envelope when omitted.',
                        ], static fn (mixed $v): bool => $v !== null),
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
                'service_execute' => [
                    'addressing' => 'service endpoint, service, and operation path segments resolve the handler binding',
                    'fields' => [
                        'mode_override' => [
                            'canonical_values' => ['sync', 'async'],
                        ],
                        'wait_for' => [
                            'canonical_values' => ['accepted', 'completed'],
                        ],
                        'target_workflow_instance_id' => [
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Workflow target for signal, update, query, activity, or carrier-backed operations when not fixed by the operation binding.',
                        ],
                        'target_workflow_run_id' => [
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Run target for activity or carrier-backed operations when not fixed by the operation binding.',
                        ],
                        'idempotency_key' => [
                            'type' => 'string',
                            'required' => false,
                            'description' => 'Caller retry key that reuses the durable service-call id for the same resolved operation.',
                        ],
                    ],
                    'durable_response_fields' => [
                        'service_call_id',
                        'status',
                        'outcome',
                        'resolved_binding_kind',
                        'resolved_target_reference',
                        'linked_workflow_instance_id',
                        'linked_workflow_run_id',
                        'linked_workflow_update_id',
                    ],
                ],
            ],
        ];
    }
}
