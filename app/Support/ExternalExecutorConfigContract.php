<?php

namespace App\Support;

final class ExternalExecutorConfigContract
{
    public const CONTRACT_SCHEMA = 'durable-workflow.v2.external-executor-config.contract';

    public const CONTRACT_VERSION = 1;

    public const CONFIG_SCHEMA = 'durable-workflow.external-executor.config';

    public const CONFIG_VERSION = 1;

    public const JSON_SCHEMA_ID = 'https://durable-workflow.com/schemas/cli/config/external-executor.schema.json';

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::CONTRACT_SCHEMA,
            'version' => self::CONTRACT_VERSION,
            'config_schema' => [
                'schema' => self::CONFIG_SCHEMA,
                'version' => self::CONFIG_VERSION,
                'json_schema_id' => self::JSON_SCHEMA_ID,
                'schema_source' => 'dw schema:show external-executor-config',
            ],
            'steady_state_surface' => 'config_file',
            'override_precedence' => [
                'flags',
                'environment_overlay',
                'config_file',
                'schema_defaults',
            ],
            'server_runtime' => [
                'config_path_env' => 'DW_EXTERNAL_EXECUTOR_CONFIG_PATH',
                'overlay_env' => 'DW_EXTERNAL_EXECUTOR_CONFIG_OVERLAY',
                'cluster_info_path' => 'worker_protocol.external_executor_config_contract.runtime',
                'execution_status' => 'validation_and_discovery_only',
            ],
            'validation' => [
                'fail_closed' => true,
                'named_errors' => self::namedErrors(),
            ],
            'redaction' => [
                'path_disclosure' => 'basename_and_sha256_only',
                'never_echo' => ['token', 'secret', 'authorization', 'signature', 'raw_environment_values'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function runtime(): array
    {
        $path = self::configuredPath();

        if ($path === null) {
            return [
                'configured' => false,
                'status' => 'not_configured',
                'source' => null,
                'overlay' => self::configuredOverlay(),
                'summary' => self::emptySummary(),
                'errors' => [],
            ];
        }

        $source = self::sourceInfo($path);

        if (! is_file($path) || ! is_readable($path)) {
            return [
                'configured' => true,
                'status' => 'invalid',
                'source' => $source,
                'overlay' => self::configuredOverlay(),
                'summary' => self::emptySummary(),
                'errors' => [
                    self::error('unreadable_config', 'Configured external executor config file is not readable.'),
                ],
            ];
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return [
                'configured' => true,
                'status' => 'invalid',
                'source' => $source,
                'overlay' => self::configuredOverlay(),
                'summary' => self::emptySummary(),
                'errors' => [
                    self::error('unreadable_config', 'Configured external executor config file could not be read.'),
                ],
            ];
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [
                'configured' => true,
                'status' => 'invalid',
                'source' => $source,
                'overlay' => self::configuredOverlay(),
                'summary' => self::emptySummary(),
                'errors' => [
                    self::error('invalid_json', 'Configured external executor config is not valid JSON.', [
                        'detail' => $exception->getMessage(),
                    ]),
                ],
            ];
        }

        if (! is_array($document)) {
            return [
                'configured' => true,
                'status' => 'invalid',
                'source' => $source,
                'overlay' => self::configuredOverlay(),
                'summary' => self::emptySummary(),
                'errors' => [
                    self::error('invalid_schema', 'Configured external executor config must be a JSON object.'),
                ],
            ];
        }

        [$effective, $overlayError] = self::applyOverlay($document);
        $errors = self::validate($effective);
        if ($overlayError !== null) {
            array_unshift($errors, $overlayError);
        }

        return [
            'configured' => true,
            'status' => $errors === [] ? 'valid' : 'invalid',
            'source' => $source,
            'overlay' => self::configuredOverlay(),
            'summary' => self::summary($effective),
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    public static function namedErrors(): array
    {
        return [
            'unreadable_config',
            'invalid_json',
            'invalid_schema',
            'unsupported_version',
            'unknown_overlay',
            'unknown_carrier',
            'unknown_auth_ref',
            'unknown_handler',
            'duplicate_mapping_name',
            'invalid_queue_binding',
            'missing_handler_target',
            'unsupported_carrier_capability',
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private static function applyOverlay(array $document): array
    {
        $overlay = self::configuredOverlay();
        if ($overlay === null) {
            return [$document, null];
        }

        $overlays = $document['overlays'] ?? [];
        if (! is_array($overlays) || ! is_array($overlays[$overlay] ?? null)) {
            return [
                $document,
                self::error('unknown_overlay', 'Configured external executor overlay does not exist.', [
                    'overlay' => $overlay,
                ]),
            ];
        }

        /** @var array<string, mixed> $patch */
        $patch = $overlays[$overlay];

        if (isset($patch['defaults']) && is_array($patch['defaults'])) {
            $document['defaults'] = array_replace(
                is_array($document['defaults'] ?? null) ? $document['defaults'] : [],
                $patch['defaults'],
            );
        }

        if (isset($patch['carriers']) && is_array($patch['carriers'])) {
            $document['carriers'] = array_replace_recursive(
                is_array($document['carriers'] ?? null) ? $document['carriers'] : [],
                $patch['carriers'],
            );
        }

        if (isset($patch['mappings']) && is_array($patch['mappings'])) {
            $document['mappings'] = $patch['mappings'];
        }

        return [$document, null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function validate(array $document): array
    {
        $errors = [];

        if (($document['schema'] ?? null) !== self::CONFIG_SCHEMA) {
            $errors[] = self::error('invalid_schema', 'External executor config schema does not match the supported schema.', [
                'expected' => self::CONFIG_SCHEMA,
            ]);
        }

        if (($document['version'] ?? null) !== self::CONFIG_VERSION) {
            $errors[] = self::error('unsupported_version', 'External executor config version is not supported.', [
                'expected' => self::CONFIG_VERSION,
            ]);
        }

        $carriers = is_array($document['carriers'] ?? null) ? $document['carriers'] : [];
        if ($carriers === []) {
            $errors[] = self::error('invalid_schema', 'External executor config must declare at least one carrier.');
        }

        $mappings = is_array($document['mappings'] ?? null) ? $document['mappings'] : [];
        if ($mappings === []) {
            $errors[] = self::error('invalid_schema', 'External executor config must declare at least one mapping.');
        }

        $authRefs = is_array($document['auth_refs'] ?? null) ? $document['auth_refs'] : [];
        $defaults = is_array($document['defaults'] ?? null) ? $document['defaults'] : [];
        $seenNames = [];

        foreach ($mappings as $index => $mapping) {
            if (! is_array($mapping)) {
                $errors[] = self::error('invalid_schema', 'External executor mapping must be an object.', [
                    'mapping_index' => $index,
                ]);

                continue;
            }

            $name = self::stringValue($mapping['name'] ?? null);
            if ($name === null) {
                $errors[] = self::error('invalid_schema', 'External executor mapping must declare a non-empty name.', [
                    'mapping_index' => $index,
                ]);
            } elseif (isset($seenNames[$name])) {
                $errors[] = self::error('duplicate_mapping_name', 'External executor mapping names must be unique.', [
                    'mapping' => $name,
                ]);
            } else {
                $seenNames[$name] = true;
            }

            $carrierName = self::stringValue($mapping['carrier'] ?? null);
            $carrier = null;
            if ($carrierName === null || ! is_array($carriers[$carrierName] ?? null)) {
                $errors[] = self::error('unknown_carrier', 'External executor mapping references an unknown carrier.', [
                    'mapping' => $name,
                    'carrier' => $carrierName,
                ]);
            } else {
                $carrier = $carriers[$carrierName];
            }

            $authRef = self::stringValue($mapping['auth_ref'] ?? $defaults['auth_ref'] ?? null);
            if ($authRef !== null && ! is_array($authRefs[$authRef] ?? null)) {
                $errors[] = self::error('unknown_auth_ref', 'External executor mapping references an unknown auth_ref.', [
                    'mapping' => $name,
                    'auth_ref' => $authRef,
                ]);
            }

            if (self::stringValue($mapping['handler'] ?? null) === null) {
                $errors[] = self::error('missing_handler_target', 'External executor mapping must declare a handler target.', [
                    'mapping' => $name,
                ]);
            }

            $kind = self::stringValue($mapping['kind'] ?? null);
            if ($kind === 'activity' && self::stringValue($mapping['task_queue'] ?? $defaults['task_queue'] ?? null) === null) {
                $errors[] = self::error('invalid_queue_binding', 'Activity mappings must declare task_queue or inherit defaults.task_queue.', [
                    'mapping' => $name,
                ]);
            }

            if (! self::hasRequiredTarget($mapping, $kind)) {
                $errors[] = self::error('missing_handler_target', 'External executor mapping is missing the target field required for its kind.', [
                    'mapping' => $name,
                    'kind' => $kind,
                ]);
            }

            if ($carrier !== null && ! self::carrierSupports($carrier, $kind)) {
                $errors[] = self::error('unsupported_carrier_capability', 'External executor carrier does not advertise the mapping capability.', [
                    'mapping' => $name,
                    'carrier' => $carrierName,
                    'kind' => $kind,
                ]);
            }
        }

        return $errors;
    }

    private static function hasRequiredTarget(array $mapping, ?string $kind): bool
    {
        return match ($kind) {
            'activity' => self::stringValue($mapping['activity_type'] ?? null) !== null,
            'workflow_start' => self::stringValue($mapping['workflow_type'] ?? null) !== null,
            'workflow_signal' => self::stringValue($mapping['workflow_type'] ?? null) !== null
                && self::stringValue($mapping['signal_name'] ?? null) !== null,
            'workflow_update' => self::stringValue($mapping['workflow_type'] ?? null) !== null
                && self::stringValue($mapping['update_name'] ?? null) !== null,
            'webhook_ingress' => self::stringValue($mapping['idempotency_key'] ?? null) !== null,
            default => false,
        };
    }

    private static function carrierSupports(array $carrier, ?string $kind): bool
    {
        $capability = match ($kind) {
            'activity' => 'activity_task',
            'workflow_start' => 'workflow_start',
            'workflow_signal' => 'workflow_signal',
            'workflow_update' => 'workflow_update',
            'webhook_ingress' => 'webhook_ingress',
            default => null,
        };

        if ($capability === null) {
            return false;
        }

        $capabilities = $carrier['capabilities'] ?? null;
        if (! is_array($capabilities)) {
            return true;
        }

        return in_array($capability, $capabilities, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(array $document): array
    {
        $carriers = is_array($document['carriers'] ?? null) ? $document['carriers'] : [];
        $mappings = is_array($document['mappings'] ?? null) ? $document['mappings'] : [];

        $mappingKinds = [];
        foreach ($mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $kind = self::stringValue($mapping['kind'] ?? null);
            if ($kind === null) {
                continue;
            }

            $mappingKinds[$kind] = ($mappingKinds[$kind] ?? 0) + 1;
        }

        ksort($mappingKinds);

        return [
            'carrier_count' => count($carriers),
            'mapping_count' => count($mappings),
            'mapping_kinds' => $mappingKinds,
        ];
    }

    /**
     * @return array{carrier_count: int, mapping_count: int, mapping_kinds: array<string, int>}
     */
    private static function emptySummary(): array
    {
        return [
            'carrier_count' => 0,
            'mapping_count' => 0,
            'mapping_kinds' => [],
        ];
    }

    /**
     * @return array{type: string, basename: string, sha256: string}
     */
    private static function sourceInfo(string $path): array
    {
        return [
            'type' => 'file',
            'basename' => basename($path),
            'sha256' => hash('sha256', $path),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function error(string $code, string $message, array $context = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'context' => self::redactContext($context),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function redactContext(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            if (preg_match('/token|secret|authorization|signature/i', (string) $key) === 1) {
                $redacted[$key] = 'redacted';

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private static function configuredPath(): ?string
    {
        $path = config('server.external_executor.config_path');

        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);

        return $path === '' ? null : $path;
    }

    private static function configuredOverlay(): ?string
    {
        $overlay = config('server.external_executor.overlay');

        if (! is_string($overlay)) {
            return null;
        }

        $overlay = trim($overlay);

        return $overlay === '' ? null : $overlay;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
