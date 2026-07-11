<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class LegacyV1Projection
{
    private const OPAQUE_PAYLOAD_REASON = 'legacy_php_serialization_not_portable';

    private const OPAQUE_PAYLOAD_REMEDIATION = 'Treat the preserved value as opaque; export decoded JSON from the source application if portable payload access is required.';

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function import(string $sourceId, array $source, ?string $namespace, bool $dryRun = false): array
    {
        $normalized = self::normalize($sourceId, $source, $namespace);

        if (($normalized['valid'] ?? false) !== true) {
            return self::report($normalized, 'rejected');
        }

        $identity = $normalized['identity'];
        $workflowId = $identity['standalone']['workflow_id'];
        $runId = $identity['standalone']['run_id'];
        $dedupeKey = $normalized['dedupe_key'];

        /** @var WorkflowRun|null $existingRun */
        $existingRun = WorkflowRun::query()->find($runId);
        $existingInstance = WorkflowInstance::query()->find($workflowId);

        if ($existingRun instanceof WorkflowRun || $existingInstance instanceof WorkflowInstance) {
            $sameProjection = $existingRun instanceof WorkflowRun
                && $existingInstance instanceof WorkflowInstance
                && $existingRun->workflow_instance_id === $workflowId
                && $existingRun->import_source === LegacyV1ProjectionContract::IMPORT_SOURCE
                && hash_equals((string) $existingRun->import_id, $normalized['import_id'])
                && hash_equals((string) $existingRun->import_dedupe_key, $dedupeKey);

            if (
                $sameProjection
                && $existingRun->namespace === $normalized['namespace']
                && $existingInstance->namespace === $normalized['namespace']
            ) {
                return self::report($normalized, 'already_projected', [
                    'imported_at' => self::timestamp($existingRun->imported_at)?->toJSON(),
                ]);
            }

            if ($sameProjection) {
                $instanceNamespace = self::string($existingInstance->namespace);
                $runNamespace = self::string($existingRun->namespace);
                $storedNamespace = $instanceNamespace !== null && $instanceNamespace === $runNamespace
                    ? $instanceNamespace
                    : null;
                $existingIdentity = $normalized['identity'];
                $existingIdentity['standalone']['namespace'] = $storedNamespace;

                return self::report($normalized, 'rejected', [
                    'identity' => $existingIdentity,
                    'requested_namespace' => $normalized['namespace'],
                    'reason' => $storedNamespace === null
                        ? 'v1_projection_storage_inconsistent'
                        : 'v1_projection_namespace_collision',
                    'message' => $storedNamespace === null
                        ? 'The existing v1 projection has inconsistent instance and run namespaces.'
                        : 'This v1 identity is already projected in a different standalone namespace.',
                    'remediation' => $storedNamespace === null
                        ? 'Repair or remove the inconsistent projection rows before repeating the import.'
                        : sprintf('Repeat the operation in the existing %s namespace, or choose a different stable source_id for an independent projection.', $storedNamespace),
                ]);
            }

            return self::report($normalized, 'rejected', [
                'reason' => $existingRun?->import_source === LegacyV1ProjectionContract::IMPORT_SOURCE
                    ? 'v1_projection_changed'
                    : 'native_v2_identity_collision',
                'message' => $existingRun?->import_source === LegacyV1ProjectionContract::IMPORT_SOURCE
                    ? 'This v1 identity was already projected from different source state.'
                    : 'The mapped standalone identity is already owned by a native or unrelated v2 execution.',
                'remediation' => $existingRun?->import_source === LegacyV1ProjectionContract::IMPORT_SOURCE
                    ? 'Inspect the existing projection and resolve it before importing a different snapshot of the same v1 identity.'
                    : 'Choose a different stable source_id; the server will not overwrite the colliding v2 execution.',
            ]);
        }

        if ($dryRun) {
            return self::report($normalized, 'dry_run');
        }

        $importedAt = now();

        try {
            DB::transaction(static function () use ($normalized, $workflowId, $runId, $dedupeKey, $importedAt): void {
                if (
                    WorkflowInstance::query()->lockForUpdate()->whereKey($workflowId)->exists()
                    || WorkflowRun::query()->lockForUpdate()->whereKey($runId)->exists()
                ) {
                    throw new \RuntimeException('mapped_identity_claimed');
                }

                $source = $normalized['source'];
                $events = $normalized['events'];
                $status = $normalized['status'];
                $closedAt = $normalized['closed_at'];
                $historySize = array_sum(array_map(
                    static fn (array $event): int => strlen((string) json_encode($event, JSON_UNESCAPED_SLASHES)),
                    $events,
                ));

                WorkflowInstance::query()->create([
                    'id' => $workflowId,
                    'workflow_class' => $source['class'],
                    'workflow_type' => $source['class'],
                    'namespace' => $normalized['namespace'],
                    'visibility_labels' => [],
                    'memo' => [],
                    'current_run_id' => $runId,
                    'run_count' => 1,
                    'last_message_sequence' => 0,
                    'started_at' => $normalized['started_at'],
                    'created_at' => $normalized['started_at'] ?? $importedAt,
                    'updated_at' => $importedAt,
                ]);

                WorkflowRun::query()->create([
                    'id' => $runId,
                    'workflow_instance_id' => $workflowId,
                    'run_number' => 1,
                    'workflow_class' => $source['class'],
                    'workflow_type' => $source['class'],
                    'namespace' => $normalized['namespace'],
                    'visibility_labels' => [],
                    'status' => $status,
                    'closed_reason' => $normalized['closed_reason'],
                    'payload_codec' => null,
                    'arguments' => null,
                    'output' => null,
                    'connection' => $source['connection'],
                    'queue' => $source['queue'],
                    'last_history_sequence' => count($events),
                    'last_command_sequence' => 0,
                    'message_cursor_position' => 0,
                    'started_at' => $normalized['started_at'],
                    'closed_at' => $closedAt,
                    'last_progress_at' => $normalized['last_progress_at'],
                    'import_source' => LegacyV1ProjectionContract::IMPORT_SOURCE,
                    'import_id' => $normalized['import_id'],
                    'import_dedupe_key' => $dedupeKey,
                    'import_contract_version' => LegacyV1ProjectionContract::VERSION,
                    'imported_at' => $importedAt,
                    'created_at' => $normalized['started_at'] ?? $importedAt,
                    'updated_at' => $importedAt,
                ]);

                foreach ($events as $event) {
                    WorkflowHistoryEvent::query()->create([
                        'id' => $event['id'],
                        'workflow_run_id' => $runId,
                        'sequence' => $event['sequence'],
                        'event_type' => $event['event_type'],
                        'payload' => $event['payload'],
                        'recorded_at' => $event['recorded_at'],
                        'created_at' => $event['recorded_at'] ?? $importedAt,
                        'updated_at' => $event['recorded_at'] ?? $importedAt,
                    ]);
                }

                WorkflowRunSummary::query()->create([
                    'id' => $runId,
                    'workflow_instance_id' => $workflowId,
                    'run_number' => 1,
                    'is_current_run' => true,
                    'engine_source' => LegacyV1ProjectionContract::ENGINE_SOURCE,
                    'class' => $source['class'],
                    'workflow_type' => $source['class'],
                    'namespace' => $normalized['namespace'],
                    'visibility_labels' => [],
                    'status' => $status,
                    'status_bucket' => $normalized['status_bucket'],
                    'closed_reason' => $normalized['closed_reason'],
                    'connection' => $source['connection'],
                    'queue' => $source['queue'],
                    'started_at' => $normalized['started_at'],
                    'sort_timestamp' => $normalized['last_progress_at'] ?? $normalized['started_at'] ?? $importedAt,
                    'closed_at' => $closedAt,
                    'liveness_state' => $normalized['status_bucket'] === 'running' ? 'external_v1' : null,
                    'liveness_reason' => $normalized['status_bucket'] === 'running'
                        ? 'Execution remains owned by the source v1 runtime; this standalone row is read-only.'
                        : null,
                    'repair_blocked_reason' => 'v1_projection_read_only',
                    'repair_attention' => false,
                    'task_problem' => false,
                    'exception_count' => count($source['exceptions']),
                    'history_event_count' => count($events),
                    'history_size_bytes' => $historySize,
                    'continue_as_new_recommended' => false,
                    'created_at' => $normalized['started_at'] ?? $importedAt,
                    'updated_at' => $importedAt,
                ]);
            });
        } catch (Throwable $throwable) {
            $collision = $throwable->getMessage() === 'mapped_identity_claimed';

            return self::report($normalized, 'rejected', [
                'reason' => $collision ? 'native_v2_identity_collision' : 'target_write_failed',
                'message' => $collision
                    ? 'The mapped standalone identity was claimed while the projection was being written.'
                    : 'The standalone server could not persist the v1 projection transaction.',
                'remediation' => $collision
                    ? 'Retry with a different stable source_id; no existing execution was overwritten.'
                    : 'Verify server storage readiness, then repeat the same idempotent operation.',
            ]);
        }

        return self::report($normalized, 'projected', [
            'imported_at' => $importedAt->toJSON(),
        ]);
    }

    public static function isProjectedRun(?WorkflowRun $run): bool
    {
        return $run instanceof WorkflowRun
            && $run->import_source === LegacyV1ProjectionContract::IMPORT_SOURCE;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function metadataForRun(WorkflowRun $run): ?array
    {
        if (! self::isProjectedRun($run)) {
            return null;
        }

        /** @var WorkflowHistoryEvent|null $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->first();
        $payload = is_array($started?->payload) ? $started->payload : [];
        $migration = is_array($payload['migration_projection'] ?? null)
            ? $payload['migration_projection']
            : [];

        return [
            'origin' => $migration['origin'] ?? null,
            'identity' => $migration['identity'] ?? null,
            'task_queue_context' => $migration['task_queue_context'] ?? null,
            'unsupported_fields' => is_array($migration['unsupported_fields'] ?? null)
                ? array_values($migration['unsupported_fields'])
                : [],
            'projection' => [
                'schema' => LegacyV1ProjectionContract::SCHEMA,
                'version' => LegacyV1ProjectionContract::VERSION,
                'read_only' => true,
                'execution_owner' => 'v1',
                'imported_at' => self::timestamp($run->imported_at)?->toJSON(),
                'dedupe_key' => $run->import_dedupe_key,
            ],
        ];
    }

    /**
     * Add provenance to a history bundle and refresh its checksum so the
     * exported document remains self-consistent.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    public static function decorateHistoryExport(array $bundle, WorkflowRun $run): array
    {
        $metadata = self::metadataForRun($run);

        if ($metadata === null) {
            return $bundle;
        }

        $bundle['migration_projection'] = $metadata;
        $integrity = is_array($bundle['integrity'] ?? null) ? $bundle['integrity'] : [];
        unset($bundle['integrity']);
        $canonical = json_encode(
            self::canonicalize($bundle),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
        $integrity['canonicalization'] = 'json-recursive-ksort-v1';
        $integrity['checksum_algorithm'] = 'sha256';
        $integrity['checksum'] = hash('sha256', $canonical);

        $signingKey = config('workflows.v2.history_export.signing_key');
        $signingKey = is_string($signingKey) ? trim($signingKey) : '';
        if ($signingKey !== '') {
            $integrity['signature_algorithm'] = 'hmac-sha256';
            $integrity['signature'] = hash_hmac('sha256', $canonical, $signingKey);
        } else {
            $integrity['signature_algorithm'] = null;
            $integrity['signature'] = null;
        }

        $bundle['integrity'] = $integrity;

        return $bundle;
    }

    /**
     * @return array{workflow_id: string, run_id: string, import_id: string}
     */
    public static function mappedIdentity(string $sourceId, string $qualifiedId): array
    {
        $key = trim($sourceId)."\0".trim($qualifiedId);
        $sourceSlug = Str::of($sourceId)
            ->lower()
            ->replaceMatches('/[^a-z0-9._-]+/', '-')
            ->trim('-')
            ->limit(32, '')
            ->toString();
        $sourceSlug = $sourceSlug === '' ? 'source' : $sourceSlug;

        return [
            'workflow_id' => 'v1:'.$sourceSlug.':'.substr(hash('sha256', 'workflow'."\0".$key), 0, 24),
            'run_id' => substr(hash('sha256', 'run'."\0".$key), 0, 26),
            'import_id' => hash('sha256', 'import'."\0".$key),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function normalize(string $sourceId, array $source, ?string $namespace): array
    {
        $sourceId = trim($sourceId);
        $namespace = is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : 'default';
        $qualifiedId = self::string($source['operator_id'] ?? $source['id'] ?? null);
        $legacyId = is_scalar($source['legacy_id'] ?? null) ? (string) $source['legacy_id'] : null;
        $class = self::string($source['class'] ?? null);
        $sourceStatus = strtolower(self::string($source['status'] ?? null) ?? '');
        $status = self::mapStatus($sourceStatus);
        $errors = [];

        if ($sourceId === '' || preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $sourceId) !== 1) {
            $errors[] = self::error('invalid_source_id', 'source_id must be 1-64 stable URL-safe characters.', 'Use a deployment-stable identifier such as legacy-prod-us-east.');
        }
        if ($qualifiedId === null || preg_match('/^v1:.+$/', $qualifiedId) !== 1) {
            $errors[] = self::error('unqualified_v1_identity', 'The Waterline workflow must carry operator_id or id in v1:<workflow-id> form.', 'Export the detail from the Waterline hybrid migration API.');
        }
        if ($legacyId !== null && $qualifiedId !== null && $qualifiedId !== 'v1:'.$legacyId) {
            $errors[] = self::error('v1_identity_mismatch', 'legacy_id does not match the qualified Waterline identity.', 'Re-export the workflow detail without editing its identity fields.');
        }
        if (($source['engine_source'] ?? null) !== 'v1') {
            $errors[] = self::error('unsupported_source_engine', 'Only Waterline engine_source=v1 projections are accepted.', 'Use embedded-v2 import for v2-origin history bundles.');
        }
        if ($class === null) {
            $errors[] = self::error('missing_v1_workflow_class', 'The Waterline projection does not identify the workflow class.', 'Export the complete v1 workflow detail response.');
        }
        if ($status === null) {
            $errors[] = self::error('unsupported_v1_status', 'The v1 workflow status cannot be represented by the standalone v2 visibility model.', 'Complete or normalize the workflow on v1, then export it again.');
        }

        if ($errors !== []) {
            return [
                'valid' => false,
                'errors' => $errors,
                'unsupported_fields' => [],
                'namespace' => $namespace,
            ];
        }

        $mapped = self::mappedIdentity($sourceId, $qualifiedId);
        $source['class'] = $class;
        $source['connection'] = self::string($source['connection'] ?? null);
        $source['queue'] = self::string($source['queue'] ?? null);
        $source['logs'] = self::rows($source['logs'] ?? null);
        $source['signals'] = self::rows($source['signals'] ?? null);
        $source['exceptions'] = self::rows($source['exceptions'] ?? null);
        $unsupported = self::unsupportedFields($source, $sourceStatus);
        $identity = [
            'waterline' => [
                'source_id' => $sourceId,
                'qualified_workflow_id' => $qualifiedId,
                'legacy_workflow_id' => $legacyId ?? substr($qualifiedId, 3),
            ],
            'standalone' => [
                'namespace' => $namespace,
                'workflow_id' => $mapped['workflow_id'],
                'run_id' => $mapped['run_id'],
            ],
            'relationship' => 'deterministic_source_qualified_projection',
            'collision_policy' => 'reject_without_overwrite',
        ];
        $origin = [
            'engine_source' => 'v1',
            'engine_version' => self::string($source['engine_version'] ?? null) ?? '1.x',
            'execution_engine' => self::string($source['execution_engine'] ?? null) ?? 'finish-on-v1',
        ];
        $taskQueue = [
            'connection' => $source['connection'],
            'name' => $source['queue'],
            'execution_owner' => 'v1',
            'standalone_dispatch' => 'disabled',
        ];
        $startedAt = self::timestamp($source['created_at'] ?? null);
        $lastProgressAt = self::timestamp($source['updated_at'] ?? null) ?? $startedAt;
        $terminal = in_array($status, ['completed', 'failed', 'cancelled', 'terminated'], true);
        $closedAt = $terminal ? $lastProgressAt : null;
        $normalizedForHash = [
            'origin' => $origin,
            'identity' => $identity['waterline'],
            'class' => $class,
            'status' => $sourceStatus,
            'connection' => $source['connection'],
            'queue' => $source['queue'],
            'arguments' => $source['arguments'] ?? null,
            'output' => $source['output'] ?? null,
            'logs' => $source['logs'],
            'signals' => $source['signals'],
            'exceptions' => $source['exceptions'],
            'created_at' => self::timestampString($startedAt),
            'updated_at' => self::timestampString($lastProgressAt),
        ];
        $dedupeKey = hash('sha256', json_encode(self::canonicalize($normalizedForHash), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $migration = [
            'origin' => $origin,
            'identity' => $identity,
            'task_queue_context' => $taskQueue,
            'unsupported_fields' => $unsupported,
        ];

        return [
            'valid' => true,
            'errors' => [],
            'namespace' => $namespace,
            'source' => $source,
            'source_status' => $sourceStatus,
            'status' => $status,
            'status_bucket' => self::statusBucket($status),
            'closed_reason' => $terminal ? $sourceStatus : null,
            'started_at' => $startedAt,
            'last_progress_at' => $lastProgressAt,
            'closed_at' => $closedAt,
            'identity' => $identity,
            'import_id' => $mapped['import_id'],
            'dedupe_key' => $dedupeKey,
            'unsupported_fields' => $unsupported,
            'events' => self::events($source, $migration, $status, $startedAt, $lastProgressAt, $mapped['run_id']),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $migration
     * @return list<array<string, mixed>>
     */
    private static function events(array $source, array $migration, string $status, ?Carbon $startedAt, ?Carbon $lastProgressAt, string $runId): array
    {
        $events = [[
            'event_type' => 'WorkflowStarted',
            'recorded_at' => $startedAt,
            'boundary' => 0,
            'order' => 0,
            'payload' => [
                'migration_projection' => $migration,
                'legacy_payloads' => [
                    'arguments' => self::opaquePayload($source['arguments'] ?? null),
                ],
            ],
        ]];

        foreach ($source['logs'] as $index => $log) {
            $events[] = [
                'event_type' => 'ActivityCompleted',
                'recorded_at' => self::timestamp($log['created_at'] ?? $log['now'] ?? null),
                'boundary' => 1,
                'order' => 100 + $index,
                'payload' => [
                    'migration_projection' => [
                        'source_record' => 'workflow_log',
                        'legacy_id' => $log['id'] ?? null,
                        'legacy_index' => $log['index'] ?? null,
                    ],
                    'activity' => [
                        'class' => $log['class'] ?? null,
                        'logical_time' => $log['now'] ?? null,
                        'result' => self::opaquePayload($log['result'] ?? null),
                    ],
                ],
            ];
        }

        foreach ($source['signals'] as $index => $signal) {
            $events[] = [
                'event_type' => 'SignalReceived',
                'recorded_at' => self::timestamp($signal['created_at'] ?? $signal['received_at'] ?? null),
                'boundary' => 1,
                'order' => 200 + $index,
                'payload' => [
                    'migration_projection' => [
                        'source_record' => 'workflow_signal',
                        'legacy_id' => $signal['id'] ?? null,
                    ],
                    'signal_name' => $signal['name'] ?? $signal['method'] ?? null,
                    'arguments' => self::opaquePayload($signal['arguments'] ?? null),
                ],
            ];
        }

        foreach ($source['exceptions'] as $index => $exception) {
            $events[] = [
                'event_type' => 'ActivityFailed',
                'recorded_at' => self::timestamp($exception['created_at'] ?? null),
                'boundary' => 1,
                'order' => 300 + $index,
                'payload' => [
                    'migration_projection' => [
                        'source_record' => 'workflow_exception',
                        'legacy_id' => $exception['id'] ?? null,
                    ],
                    'exception' => [
                        'class' => $exception['class'] ?? null,
                        'value' => self::opaquePayload($exception['exception'] ?? null),
                    ],
                ],
            ];
        }

        $terminalType = match ($status) {
            'completed' => 'WorkflowCompleted',
            'failed' => 'WorkflowFailed',
            'cancelled' => 'WorkflowCancelled',
            'terminated' => 'WorkflowTerminated',
            default => null,
        };
        if ($terminalType !== null) {
            $events[] = [
                'event_type' => $terminalType,
                'recorded_at' => $lastProgressAt,
                'boundary' => 2,
                'order' => PHP_INT_MAX,
                'payload' => [
                    'migration_projection' => [
                        'source_status' => $source['status'] ?? null,
                        'execution_owner' => 'v1',
                    ],
                    'legacy_payloads' => [
                        'output' => self::opaquePayload($source['output'] ?? null),
                    ],
                ],
            ];
        }

        usort($events, static function (array $left, array $right): int {
            $leftTime = $left['recorded_at'] instanceof Carbon ? $left['recorded_at']->getTimestamp() : PHP_INT_MIN;
            $rightTime = $right['recorded_at'] instanceof Carbon ? $right['recorded_at']->getTimestamp() : PHP_INT_MIN;

            return [$left['boundary'], $leftTime, $left['order']] <=> [$right['boundary'], $rightTime, $right['order']];
        });

        foreach ($events as $index => &$event) {
            $sequence = $index + 1;
            $event['sequence'] = $sequence;
            $event['id'] = substr(hash('sha256', $runId."\0".$sequence."\0".$event['event_type']), 0, 26);
            unset($event['boundary'], $event['order']);
        }
        unset($event);

        return $events;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<array{field: string, reason: string, remediation: string}>
     */
    private static function unsupportedFields(array $source, string $status): array
    {
        $unsupported = [[
            'field' => 'runtime.replay',
            'reason' => 'v1_history_not_replayable_as_v2',
            'remediation' => 'Retain the v1 application storage for v1 replay; use the projected typed history for operator inspection only.',
        ], [
            'field' => 'timers',
            'reason' => 'legacy_timer_state_not_exposed_by_waterline',
            'remediation' => 'Inspect timers in the source application and allow open executions to finish on v1.',
        ]];

        foreach (['arguments', 'output'] as $field) {
            if (array_key_exists($field, $source) && $source[$field] !== null) {
                $unsupported[] = [
                    'field' => 'payloads.'.$field,
                    'reason' => self::OPAQUE_PAYLOAD_REASON,
                    'remediation' => self::OPAQUE_PAYLOAD_REMEDIATION,
                ];
            }
        }

        foreach ([
            'payloads.activity_results' => [$source['logs'], 'result'],
            'payloads.signal_arguments' => [$source['signals'], 'arguments'],
            'payloads.exception_values' => [$source['exceptions'], 'exception'],
        ] as $field => [$rows, $valueField]) {
            if (self::rowsContainValue($rows, $valueField)) {
                $unsupported[] = [
                    'field' => $field,
                    'reason' => self::OPAQUE_PAYLOAD_REASON,
                    'remediation' => self::OPAQUE_PAYLOAD_REMEDIATION,
                ];
            }
        }

        if (! in_array($status, ['completed', 'continued', 'failed', 'cancelled', 'terminated'], true)) {
            $unsupported[] = [
                'field' => 'runtime.execution',
                'reason' => 'finish_on_v1_execution_not_transferable',
                'remediation' => 'Keep the v1 worker running until completion; the standalone projection is deliberately read-only and never dispatches work.',
            ];
        }

        return $unsupported;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function rowsContainValue(array $rows, string $field): bool
    {
        foreach ($rows as $row) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function opaquePayload(mixed $value): array
    {
        return [
            'available' => $value !== null,
            'encoding' => 'php-serialize',
            'value' => is_scalar($value) ? (string) $value : null,
            'portable' => false,
            'unsupported_reason' => self::OPAQUE_PAYLOAD_REASON,
            'remediation' => self::OPAQUE_PAYLOAD_REMEDIATION,
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function report(array $normalized, string $status, array $extra = []): array
    {
        $errors = is_array($normalized['errors'] ?? null) ? array_values($normalized['errors']) : [];
        $rejection = [];
        if ($status === 'rejected' && is_array($errors[0] ?? null)) {
            $rejection = array_intersect_key($errors[0], array_flip(['reason', 'message', 'remediation']));
        }

        return [
            'schema' => LegacyV1ProjectionContract::REPORT_SCHEMA,
            'schema_version' => LegacyV1ProjectionContract::VERSION,
            'status' => $status,
            'projection_only' => true,
            'identity' => $normalized['identity'] ?? null,
            'dedupe_key' => $normalized['dedupe_key'] ?? null,
            'unsupported_fields' => $normalized['unsupported_fields'] ?? [],
            'errors' => $errors,
            ...$rejection,
            ...$extra,
        ];
    }

    /** @return array{reason: string, message: string, remediation: string} */
    private static function error(string $reason, string $message, string $remediation): array
    {
        return compact('reason', 'message', 'remediation');
    }

    private static function mapStatus(string $status): ?string
    {
        return match ($status) {
            'created', 'pending' => 'pending',
            'running' => 'running',
            'waiting' => 'waiting',
            'completed', 'continued' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'terminated' => 'terminated',
            default => null,
        };
    }

    private static function statusBucket(string $status): string
    {
        return match ($status) {
            'completed' => 'completed',
            'failed', 'cancelled', 'terminated' => 'failed',
            default => 'running',
        };
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private static function timestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function timestampString(?Carbon $value): ?string
    {
        return $value?->toJSON();
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalize($item);
        }
        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}
