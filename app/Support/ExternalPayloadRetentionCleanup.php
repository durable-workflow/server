<?php

namespace App\Support;

use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowInboundStreamItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

class ExternalPayloadRetentionCleanup
{
    /** @param list<string> $uris
     * @return array<string, bool>
     */
    public function retainedReferences(array $uris): array
    {
        return $this->scanRetainedReferences($uris, fn (array $source) => $this->retainedQueryForRunCleanup($source['table']));
    }

    /**
     * @return array{found: int, deleted: int, blocked: bool, reason: string|null}
     */
    public function deleteForRun(string $namespace, string $runId, array $releasedInboundStreamItemIds = []): array
    {
        return $this->deleteForRuns($namespace, [$runId], $releasedInboundStreamItemIds);
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<int>  $releasedInboundStreamItemIds
     * @return array{found: int, deleted: int, blocked: bool, reason: string|null}
     */
    public function deleteForRuns(
        string $namespace,
        array $runIds,
        array $releasedInboundStreamItemIds = [],
    ): array {
        $runIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $runId): string => (string) $runId, $runIds),
            static fn (string $runId): bool => $runId !== '',
        )));
        $releasedInboundStreamItemIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $itemId): int => (int) $itemId, $releasedInboundStreamItemIds),
            static fn (int $itemId): bool => $itemId > 0,
        )));
        $reason = DetachedActivityRetentionHold::forRuns($namespace, $runIds);
        if ($reason !== null) {
            return ['found' => 0, 'deleted' => 0, 'blocked' => true, 'reason' => $reason];
        }
        $references = $this->referencesForRuns($namespace, $runIds);
        $this->collectPayloadColumn(
            WorkflowInboundStreamItem::query()->whereIn('id', $releasedInboundStreamItemIds),
            'payload_blob',
            $references,
        );

        return $this->deleteReferences(
            $namespace,
            $references,
            fn (string $uri): bool => $this->isReferencedByRetainedRun(
                $uri,
                $runIds,
                $releasedInboundStreamItemIds,
            ),
        );
    }

    /**
     * The external payloads of durable stream items the stream retention
     * pass is about to delete (Server #774), except any another retained row
     * still references. The items themselves count as released.
     *
     * @param  list<int>  $itemIds
     * @return array{found: int, deleted: int, blocked: bool, reason: string|null}
     */
    public function deleteForDurableStreamItems(string $namespace, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $itemId): int => (int) $itemId, $itemIds),
            static fn (int $itemId): bool => $itemId > 0,
        )));
        $references = [];

        if ($itemIds !== []) {
            $items = WorkflowDurableStreamItem::query()
                ->where('namespace', $namespace)
                ->whereIn('id', $itemIds);
            $this->collectPayloadColumn(clone $items, 'payload', $references);
            $this->collectReferenceTableColumn(clone $items, 'workflow_durable_stream_items', 'payload_reference', $references);
        }

        return $this->deleteReferences(
            $namespace,
            array_values(array_unique($references)),
            fn (string $uri): bool => $this->isReferencedByRetainedRun($uri, [], [], $itemIds),
        );
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<string>  $instanceIds
     * @return array{found: int, deleted: int, blocked: bool, reason: string|null}
     */
    public function deleteForNamespaceCleanup(string $namespace, array $runIds, array $instanceIds): array
    {
        $runIds = $this->normalizedStrings($runIds);
        $instanceIds = $this->normalizedStrings($instanceIds);

        $namespace = strtolower($namespace);
        $references = $this->referencesForNamespaceCleanup($namespace, $runIds, $instanceIds);
        $candidateUris = array_fill_keys(array_column($references, 'uri'), true);
        $retainedUris = $this->retainedReferencesForNamespaceCleanup(
            $namespace,
            $runIds,
            $instanceIds,
            $candidateUris,
        );

        return $this->deleteReferencesForOwningNamespaces(
            $references,
            static fn (string $uri): bool => isset($retainedUris[$uri]),
        );
    }

    /**
     * @param  list<array{namespace: string, uri: string}>  $references
     * @return array{found: int, deleted: int, blocked: bool, reason: string|null}
     */
    private function deleteReferencesForOwningNamespaces(array $references, callable $isRetained): array
    {
        $references = $this->uniqueOwnedReferences($references);

        if ($references === []) {
            return [
                'found' => 0,
                'deleted' => 0,
                'blocked' => false,
                'reason' => null,
            ];
        }

        $deletable = array_values(array_filter(
            $references,
            static fn (array $reference): bool => ! $isRetained($reference['uri']),
        ));

        if ($deletable === []) {
            return [
                'found' => count($references),
                'deleted' => 0,
                'blocked' => false,
                'reason' => null,
            ];
        }

        $drivers = [];

        foreach ($deletable as $reference) {
            $namespace = $reference['namespace'];

            if (array_key_exists($namespace, $drivers)) {
                continue;
            }

            $driver = app(NamespaceExternalPayloadStorage::class)->driverFor($namespace);

            if ($driver === null) {
                return [
                    'found' => count($references),
                    'deleted' => 0,
                    'blocked' => true,
                    'reason' => 'external_payload_storage_driver_unavailable',
                ];
            }

            $drivers[$namespace] = $driver;
        }

        $deleted = 0;
        $lockOrder = array_flip(RuntimeExternalPayloadObjectLock::inLockOrder(array_column($deletable, 'uri')));
        usort($deletable, static fn (array $left, array $right): int => [$lockOrder[$left['uri']], $left['namespace']] <=> [$lockOrder[$right['uri']], $right['namespace']]);

        foreach ($deletable as $reference) {
            try {
                $driver = $drivers[$reference['namespace']];
                $uri = $reference['uri'];
                if (! $driver instanceof RuntimeTrackedExternalPayloadStorage) {
                    if (app(RuntimeExternalPayloadRegistry::class)->deleteUriUnlessReferenced(
                        $reference['namespace'], $uri, $driver, static fn (): bool => $isRetained($uri),
                    )) {
                        $deleted++;
                    }
                } elseif ($driver->deleteUnlessReferenced($uri, static fn (): bool => $isRetained($uri))) {
                    $deleted++;
                }
            } catch (ExternalPayloadStorageUnavailable $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'Unable to delete external payload reference during retention cleanup.',
                    previous: $e,
                );
            }
        }

        return [
            'found' => count($references),
            'deleted' => $deleted,
            'blocked' => false,
            'reason' => null,
        ];
    }

    /**
     * @param  list<string>  $references
     * @return array{found: int, deleted: int, blocked: bool, reason: string|null}
     */
    private function deleteReferences(string $namespace, array $references, callable $isRetained): array
    {
        $references = array_values(array_unique($references));

        if ($references === []) {
            return [
                'found' => 0,
                'deleted' => 0,
                'blocked' => false,
                'reason' => null,
            ];
        }

        $deletable = array_values(array_filter(
            $references,
            static fn (string $uri): bool => ! $isRetained($uri),
        ));

        if ($deletable === []) {
            return [
                'found' => count($references),
                'deleted' => 0,
                'blocked' => false,
                'reason' => null,
            ];
        }

        $driver = app(NamespaceExternalPayloadStorage::class)->driverFor($namespace);

        if ($driver === null) {
            return [
                'found' => count($references),
                'deleted' => 0,
                'blocked' => true,
                'reason' => 'external_payload_storage_driver_unavailable',
            ];
        }

        $deleted = 0;

        // The retained-reference check is asked again under each object's
        // lock, where writers claim, so a reference committed after the scan
        // above keeps its object. Objects are locked in one canonical order
        // because an enclosing transaction holds every lock until it commits.
        foreach (RuntimeExternalPayloadObjectLock::inLockOrder($deletable) as $uri) {
            try {
                if (! $driver instanceof RuntimeTrackedExternalPayloadStorage) {
                    if (app(RuntimeExternalPayloadRegistry::class)->deleteUriUnlessReferenced(
                        $namespace, $uri, $driver, static fn (): bool => $isRetained($uri),
                    )) {
                        $deleted++;
                    }
                } elseif ($driver->deleteUnlessReferenced($uri, static fn (): bool => $isRetained($uri))) {
                    $deleted++;
                }
            } catch (ExternalPayloadStorageUnavailable $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'Unable to delete external payload reference during retention cleanup.',
                    previous: $e,
                );
            }
        }

        return [
            'found' => count($references),
            'deleted' => $deleted,
            'blocked' => false,
            'reason' => null,
        ];
    }

    /**
     * @param  list<string>  $runIds
     * @return list<string>
     */
    private function referencesForRuns(string $namespace, array $runIds): array
    {
        $uris = [];

        foreach ($runIds as $runId) {
            foreach ($this->referencesForRun($namespace, $runId) as $uri) {
                $uris[] = $uri;
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<string>  $instanceIds
     * @return list<array{namespace: string, uri: string}>
     */
    private function referencesForNamespaceCleanup(string $namespace, array $runIds, array $instanceIds): array
    {
        $references = [];

        foreach ($this->referenceSources($namespace, $runIds, $instanceIds) as $source) {
            $query = $this->queryForScope($source['table'], $source['scope']);

            if ($query === null) {
                continue;
            }

            $columns = array_fill_keys(Schema::getColumnListing($source['table']), true);
            $payloadColumns = array_values(array_filter($source['payload_columns'], static fn (string $column): bool => isset($columns[$column])));
            $referenceColumns = array_values(array_filter($source['reference_columns'], static fn (string $column): bool => isset($columns[$column])));
            $ownerColumn = $this->ownerNamespaceColumn($source['table'], $source['owner_namespace_column']);
            $select = array_unique(array_filter(array_merge($payloadColumns, $referenceColumns, [$ownerColumn])));
            foreach ($query->select($select)->cursor() as $row) {
                $uris = [];
                foreach ($payloadColumns as $column) {
                    $this->collectReferences($row->{$column}, $uris);
                }
                foreach ($referenceColumns as $column) {
                    if ($this->isDirectPayloadReference($row->{$column})) {
                        $uris[] = $row->{$column};
                    }
                }
                foreach ($uris as $uri) {
                    $references[] = [
                        'namespace' => $this->ownerNamespaceForRow($row, $namespace, $ownerColumn),
                        'uri' => $uri,
                    ];
                }
            }
        }

        return $this->uniqueOwnedReferences($references);
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<string>  $instanceIds
     * @param  array<string, bool>  $candidateUris
     * @return array<string, bool>
     */
    private function retainedReferencesForNamespaceCleanup(
        string $namespace,
        array $runIds,
        array $instanceIds,
        array $candidateUris,
    ): array {
        if ($candidateUris === []) {
            return [];
        }

        return $this->scanRetainedReferences(array_keys($candidateUris), function (array $source) {
            $query = $this->retainedQueryForScope($source['table'], $source['scope']);
            if ($source['table'] === 'workflow_runs') {
                $query->whereNull('details_pruned_at');
            }

            return $query;
        }, $this->referenceSources($namespace, $runIds, $instanceIds));
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<string>  $instanceIds
     * @return list<array{table: string, scope: array<string, list<string>>, payload_columns: list<string>, reference_columns: list<string>, owner_namespace_column: string|null}>
     */
    private function referenceSources(string $namespace, array $runIds, array $instanceIds): array
    {
        $runOrInstanceScope = $this->runOrInstanceScope($runIds, $instanceIds);

        return [
            $this->namespaceSource('workflow_instances', ['memo', 'visibility_labels'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_runs', ['arguments', 'output', 'memo', 'search_attributes', 'visibility_labels'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_run_summaries', ['visibility_labels'], [], [
                'id' => $runIds,
                'workflow_instance_id' => $instanceIds,
            ]),
            $this->namespaceSource('activity_executions', ['arguments', 'result', 'exception', 'activity_options', 'retry_policy'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_commands', ['payload', 'context'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_history_events', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_memos', ['value'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_tasks', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_messages', ['metadata'], ['payload_reference'], [
                'workflow_instance_id' => $instanceIds,
                'workflow_run_id' => $runIds,
            ]),
            $this->namespaceSource('workflow_child_calls', ['arguments', 'metadata', 'retry_policy', 'timeout_policy'], [
                'result_payload_reference',
            ], [
                'parent_workflow_instance_id' => $instanceIds,
                'parent_workflow_run_id' => $runIds,
            ]),
            $this->namespaceSource('workflow_service_calls', $this->serviceCallPayloadColumns(), $this->serviceCallReferenceColumns(), [
                'namespace' => [$namespace],
                'target_namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_service_call_dispatches', ['dispatch_options'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_service_operations', [
                'handler_binding', 'deadline_policy', 'idempotency_policy', 'cancellation_policy',
                'retry_policy', 'boundary_policy', 'metadata',
            ], [], ['namespace' => [$namespace]]),
            $this->namespaceSource('workflow_services', ['boundary_policy', 'metadata'], [], ['namespace' => [$namespace]]),
            $this->namespaceSource('workflow_service_endpoints', ['boundary_policy', 'metadata'], [], ['namespace' => [$namespace]]),
            $this->namespaceSource('workflow_update_validation_tasks', ['arguments', 'command_context', 'validation_errors'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_schedule_history_events', ['payload'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_schedules', $this->schedulePayloadColumns(), [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_durable_streams', ['metadata'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_durable_stream_items', ['payload'], ['payload_reference'], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_inbound_stream_items', ['payload_blob'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_run_timer_entries', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_run_waits', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_run_lineage_entries', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_signal_records', ['arguments', 'validation_errors'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_run_timeline_entries', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_updates', ['arguments', 'result', 'validation_errors'], [], [
                'workflow_instance_id' => $instanceIds,
                'workflow_run_id' => $runIds,
            ]),
            $this->namespaceSource('workflow_search_attributes', ['value_string', 'value_keyword', 'value_keyword_list'], [], $runOrInstanceScope),
        ];
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<string>  $instanceIds
     * @return array<string, list<string>>
     */
    private function runOrInstanceScope(array $runIds, array $instanceIds): array
    {
        return [
            'workflow_run_id' => $runIds,
            'workflow_instance_id' => $instanceIds,
        ];
    }

    /**
     * @param  list<string>  $payloadColumns
     * @param  list<string>  $referenceColumns
     * @param  array<string, list<string>>  $scope
     * @return array{table: string, scope: array<string, list<string>>, payload_columns: list<string>, reference_columns: list<string>, owner_namespace_column: string|null}
     */
    private function namespaceSource(
        string $table,
        array $payloadColumns,
        array $referenceColumns,
        array $scope,
        ?string $ownerNamespaceColumn = 'namespace',
    ): array {
        return [
            'table' => $table,
            'scope' => $scope,
            'payload_columns' => $payloadColumns,
            'reference_columns' => $referenceColumns,
            'owner_namespace_column' => $ownerNamespaceColumn,
        ];
    }

    /**
     * @return list<string>
     */
    private function referencesForRun(string $namespace, string $runId): array
    {
        $uris = [];
        foreach ($this->referenceSources('', [], []) as $source) {
            $scope = array_fill_keys($this->runCleanupColumns($source['table']), [$runId]);
            $query = $this->queryForScope($source['table'], $scope);
            if ($query === null) {
                continue;
            }
            foreach ($source['payload_columns'] as $column) {
                $this->collectPayloadTableColumn(clone $query, $source['table'], $column, $uris);
            }
            foreach ($source['reference_columns'] as $column) {
                $this->collectReferenceTableColumn(clone $query, $source['table'], $column, $uris);
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @param  list<string>  $deletedRunIds
     */
    private function isReferencedByRetainedRun(
        string $uri,
        array $deletedRunIds,
        array $releasedInboundStreamItemIds,
        array $releasedDurableStreamItemIds = [],
    ): bool {
        return isset($this->scanRetainedReferences([$uri], fn (array $source) => $this->retainedQueryForRunCleanup(
            $source['table'], $deletedRunIds, $releasedInboundStreamItemIds, $releasedDurableStreamItemIds,
        ))[$uri]);
    }

    /**
     * Use the same inventory for pre-prune fencing, post-commit retry and namespace
     * cleanup. Rows without a run (catalog, instance, schedule and dispatch state)
     * own their references too. A missing summary cannot revoke that ownership.
     *
     * @param  list<string>  $uris
     * @return array<string, bool>
     */
    private function scanRetainedReferences(array $uris, callable $queryForSource, ?array $sources = null): array
    {
        $candidates = array_fill_keys($uris, true);
        $retained = [];
        if ($candidates === []) {
            return [];
        }
        foreach ($sources ?? $this->referenceSources('', [], []) as $source) {
            $table = $source['table'];
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = array_fill_keys(Schema::getColumnListing($table), true);
            $payloadColumns = array_values(array_filter($source['payload_columns'], static fn (string $column): bool => isset($columns[$column])));
            $referenceColumns = array_values(array_filter($source['reference_columns'], static fn (string $column): bool => isset($columns[$column])));
            $select = array_unique(array_merge($payloadColumns, $referenceColumns));
            if ($select === []) {
                continue;
            }
            foreach ($queryForSource($source)->select($select)->cursor() as $row) {
                $found = [];
                foreach ($payloadColumns as $column) {
                    $this->collectReferences($row->{$column}, $found);
                }
                foreach ($referenceColumns as $column) {
                    if ($this->isDirectPayloadReference($row->{$column})) {
                        $found[] = $row->{$column};
                    }
                }
                foreach ($found as $uri) {
                    if (isset($candidates[$uri])) {
                        $retained[$uri] = true;
                    }
                }
                if (count($retained) === count($candidates)) {
                    return $retained;
                }
            }
        }

        return $retained;
    }

    private function retainedQueryForRunCleanup(
        string $table,
        array $deletedRunIds = [],
        array $releasedInboundStreamItemIds = [],
        array $releasedDurableStreamItemIds = [],
    ): mixed {
        $query = $this->referenceTableQuery($table);
        if ($table === 'workflow_runs') {
            return $query->whereNull('details_pruned_at')->whereNotIn('id', $deletedRunIds);
        }
        if ($deletedRunIds !== []) {
            foreach ($this->runCleanupColumns($table) as $column) {
                if ($this->hasTableColumn($table, $column)) {
                    $query->where(fn ($query) => $query->whereNotIn($column, $deletedRunIds)->orWhereNull($column));
                }
            }
        }
        $releasedIds = match ($table) {
            'workflow_inbound_stream_items' => $releasedInboundStreamItemIds,
            'workflow_durable_stream_items' => $releasedDurableStreamItemIds,
            default => [],
        };
        if ($releasedIds !== []) {
            $query->whereNotIn('id', $releasedIds);
        }

        return $query;
    }

    /** @return list<string> */
    private function runCleanupColumns(string $table): array
    {
        return match ($table) {
            'workflow_runs', 'workflow_run_summaries' => ['id'],
            'workflow_commands', 'workflow_signal_records', 'workflow_updates' => ['workflow_run_id', 'requested_workflow_run_id', 'resolved_workflow_run_id'],
            'workflow_child_calls' => ['parent_workflow_run_id'],
            'activity_executions', 'workflow_history_events', 'workflow_memos', 'workflow_tasks',
            'workflow_messages', 'workflow_durable_streams', 'workflow_durable_stream_items',
            'workflow_run_timer_entries', 'workflow_run_waits', 'workflow_run_lineage_entries',
            'workflow_run_timeline_entries', 'workflow_search_attributes' => ['workflow_run_id'],
            default => [],
        };
    }

    private function referenceTableQuery(string $table): mixed
    {
        if ($table === 'workflow_service_call_dispatches') {
            // Dispatch snapshots are target-owned through their service call,
            // including cross-namespace calls. Orphans remain fenced until the
            // scheduler's ordinary repair removes them.
            return DB::query()->fromSub(DB::table($table.' as dispatch')
                ->leftJoin('workflow_service_calls as call', 'call.id', '=', 'dispatch.service_call_id')
                ->select('dispatch.*', 'call.namespace'), 'payload_source');
        }

        return DB::table($table);
    }

    /** @return list<string> */
    private function schedulePayloadColumns(): array
    {
        return ['spec', 'action', 'memo', 'search_attributes', 'visibility_labels', 'recent_actions', 'buffered_actions'];
    }

    /**
     * @param  array<string, list<string>>  $scope
     */
    private function queryForScope(string $table, array $scope): mixed
    {
        $scope = $this->existingScope($table, $scope);

        if ($scope === []) {
            return null;
        }

        return $this->referenceTableQuery($table)
            ->where(function ($query) use ($scope): void {
                foreach ($scope as $column => $values) {
                    $query->orWhereIn($column, $values);
                }
            });
    }

    /**
     * @param  array<string, list<string>>  $scope
     */
    private function retainedQueryForScope(string $table, array $scope): mixed
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $scope = $this->existingScope($table, $scope);

        if ($scope === []) {
            return $this->referenceTableQuery($table);
        }

        return $this->referenceTableQuery($table)
            ->where(function ($query) use ($scope): void {
                foreach ($scope as $column => $values) {
                    $query->where(function ($query) use ($column, $values): void {
                        $query->whereNotIn($column, $values)
                            ->orWhereNull($column);
                    });
                }
            });
    }

    /**
     * @param  array<string, list<string>>  $scope
     * @return array<string, list<string>>
     */
    private function existingScope(string $table, array $scope): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $existing = [];

        foreach ($scope as $column => $values) {
            $values = $this->normalizedStrings($values);

            if ($values !== [] && $this->hasTableColumn($table, $column)) {
                $existing[$column] = $values;
            }
        }

        return $existing;
    }

    private function collectPayloadTableColumn($query, string $table, string $column, array &$uris): void
    {
        if (! $this->hasTableColumn($table, $column)) {
            return;
        }
        foreach ($query->select([$column])->cursor() as $row) {
            $this->collectReferences($row->{$column}, $uris);
        }
    }

    private function collectReferenceTableColumn($query, string $table, string $column, array &$uris): void
    {
        if (! $this->hasTableColumn($table, $column)) {
            return;
        }
        foreach ($query->select([$column])->cursor() as $row) {
            if ($this->isDirectPayloadReference($row->{$column})) {
                $uris[] = $row->{$column};
            }
        }
    }

    /**
     * @return list<string>
     */
    private function serviceCallPayloadColumns(): array
    {
        return [
            'deadline_policy',
            'idempotency_policy',
            'cancellation_policy',
            'retry_policy',
            'boundary_policy',
            'metadata',
            'outcome_metadata',
            'caller_principal_claims',
        ];
    }

    /**
     * @return list<string>
     */
    private function serviceCallReferenceColumns(): array
    {
        return [
            'input_payload_reference',
            'output_payload_reference',
            'failure_payload_reference',
        ];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function normalizedStrings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => (string) $value, $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @param  list<array{namespace: string, uri: string}>  $references
     * @return list<array{namespace: string, uri: string}>
     */
    private function uniqueOwnedReferences(array $references): array
    {
        $unique = [];

        foreach ($references as $reference) {
            $namespace = strtolower(trim($reference['namespace']));
            $uri = trim($reference['uri']);

            if ($namespace === '' || $uri === '') {
                continue;
            }

            $unique[$namespace."\n".$uri] = [
                'namespace' => $namespace,
                'uri' => $uri,
            ];
        }

        return array_values($unique);
    }

    private function ownerNamespaceColumn(string $table, ?string $column): ?string
    {
        if ($column === null || ! $this->hasTableColumn($table, $column)) {
            return null;
        }

        return $column;
    }

    private function ownerNamespaceForRow(mixed $row, string $defaultNamespace, ?string $ownerNamespaceColumn): string
    {
        if ($ownerNamespaceColumn === null || ! isset($row->{$ownerNamespaceColumn})) {
            return strtolower($defaultNamespace);
        }

        $namespace = strtolower(trim((string) $row->{$ownerNamespaceColumn}));

        return $namespace !== '' ? $namespace : strtolower($defaultNamespace);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $uris
     */
    private function collectPayloadColumn($query, string $column, array &$uris): void
    {
        if (! $this->hasColumn($query, $column)) {
            return;
        }

        foreach ($query->select([$column])->cursor() as $row) {
            $this->collectReferences($row->{$column}, $uris);
        }
    }

    /** @param Builder<Model> $query */
    private function hasColumn($query, string $column): bool
    {
        return $this->hasTableColumn($query->getModel()->getTable(), $column);
    }

    private function hasTableColumn(string $table, string $column): bool
    {
        if ($table === 'workflow_service_call_dispatches' && $column === 'namespace') {
            return Schema::hasTable($table) && Schema::hasColumn('workflow_service_calls', 'namespace');
        }

        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    /**
     * @param  array<int, string>  $uris
     */
    private function collectReferences(mixed $value, array &$uris): void
    {
        if (is_string($value) && ExternalPayloads::isStoredReference($value)) {
            $this->collectReferences(ExternalPayloads::storedEnvelope($value), $uris);

            return;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->collectReferences($decoded, $uris);
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        if ($this->isExternalPayloadReference($value)) {
            $uris[] = (string) $value['uri'];

            return;
        }

        foreach ($value as $child) {
            $this->collectReferences($child, $uris);
        }
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isExternalPayloadReference(array $value): bool
    {
        if (($value['schema'] ?? null) !== ExternalPayloadReference::SCHEMA) {
            return false;
        }

        return is_string($value['uri'] ?? null)
            && $value['uri'] !== ''
            && is_string($value['sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/i', $value['sha256']) === 1
            && is_int($value['size_bytes'] ?? null)
            && $value['size_bytes'] >= 0
            && is_string($value['codec'] ?? null)
            && $value['codec'] !== '';
    }

    private function isDirectPayloadReference(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:\/\//', $value) === 1;
    }
}
