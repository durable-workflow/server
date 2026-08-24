<?php

namespace App\Support;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use App\Models\WorkflowInboundStreamItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

class ExternalPayloadRetentionCleanup
{
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
     * @param  list<string>  $runIds
     * @param  list<string>  $instanceIds
     * @return array{found: int, deleted: int, blocked: bool, reason: string|null}
     */
    public function deleteForNamespaceCleanup(string $namespace, array $runIds, array $instanceIds): array
    {
        $runIds = $this->normalizedStrings($runIds);
        $instanceIds = $this->normalizedStrings($instanceIds);

        return $this->deleteReferencesForOwningNamespaces(
            $this->referencesForNamespaceCleanup(strtolower($namespace), $runIds, $instanceIds),
            fn (string $uri): bool => $this->isReferencedByRetainedNamespaceRow(
                $uri,
                strtolower($namespace),
                $runIds,
                $instanceIds,
            ),
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

        foreach ($deletable as $reference) {
            try {
                $drivers[$reference['namespace']]->delete($reference['uri']);
                $deleted++;
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

        foreach ($deletable as $uri) {
            try {
                $driver->delete($uri);
                $deleted++;
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

        foreach ($this->namespaceCleanupReferenceSources($namespace, $runIds, $instanceIds) as $source) {
            $query = $this->queryForScope($source['table'], $source['scope']);

            if ($query === null) {
                continue;
            }

            foreach ($source['payload_columns'] as $column) {
                $this->collectPayloadTableColumnReferences(
                    clone $query,
                    $source['table'],
                    $column,
                    $references,
                    $namespace,
                    $source['owner_namespace_column'],
                );
            }

            foreach ($source['reference_columns'] as $column) {
                $this->collectReferenceTableColumnReferences(
                    clone $query,
                    $source['table'],
                    $column,
                    $references,
                    $namespace,
                    $source['owner_namespace_column'],
                );
            }
        }

        return $this->uniqueOwnedReferences($references);
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<string>  $instanceIds
     */
    private function isReferencedByRetainedNamespaceRow(
        string $uri,
        string $namespace,
        array $runIds,
        array $instanceIds,
    ): bool {
        foreach ($this->namespaceCleanupReferenceSources($namespace, $runIds, $instanceIds) as $source) {
            $query = $this->retainedQueryForScope($source['table'], $source['scope']);

            if ($query === null) {
                continue;
            }

            foreach ($source['payload_columns'] as $column) {
                if ($this->payloadTableColumnReferencesUri(clone $query, $source['table'], $column, $uri)) {
                    return true;
                }
            }

            foreach ($source['reference_columns'] as $column) {
                if ($this->referenceTableColumnReferencesUri(clone $query, $source['table'], $column, $uri)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $runIds
     * @param  list<string>  $instanceIds
     * @return list<array{table: string, scope: array<string, list<string>>, payload_columns: list<string>, reference_columns: list<string>, owner_namespace_column: string|null}>
     */
    private function namespaceCleanupReferenceSources(string $namespace, array $runIds, array $instanceIds): array
    {
        $runOrInstanceScope = $this->runOrInstanceScope($runIds, $instanceIds);

        return [
            $this->namespaceSource('workflow_runs', ['arguments', 'output', 'memo', 'search_attributes', 'visibility_labels'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_run_summaries', ['visibility_labels'], [], [
                'id' => $runIds,
                'workflow_instance_id' => $instanceIds,
            ]),
            $this->namespaceSource('activity_executions', ['arguments', 'result', 'exception'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_commands', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_history_events', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_memos', ['value'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_tasks', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_messages', ['metadata'], ['payload_reference'], [
                'workflow_instance_id' => $instanceIds,
                'workflow_run_id' => $runIds,
            ]),
            $this->namespaceSource('workflow_child_calls', ['arguments', 'metadata'], [
                'result_payload_reference',
            ], [
                'parent_workflow_instance_id' => $instanceIds,
                'parent_workflow_run_id' => $runIds,
            ]),
            $this->namespaceSource('workflow_service_calls', $this->serviceCallPayloadColumns(), $this->serviceCallReferenceColumns(), [
                'namespace' => [$namespace],
                'target_namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_schedule_history_events', ['payload'], [], [
                'namespace' => [$namespace],
            ]),
            $this->namespaceSource('workflow_schedules', [
                'spec',
                'action',
                'memo',
                'search_attributes',
                'visibility_labels',
                'recent_actions',
                'buffered_actions',
            ], [], [
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
            $this->namespaceSource('workflow_signal_records', ['arguments'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_run_timeline_entries', ['payload'], [], $runOrInstanceScope),
            $this->namespaceSource('workflow_updates', ['arguments', 'result'], [], [
                'workflow_instance_id' => $instanceIds,
                'workflow_run_id' => $runIds,
            ]),
            $this->namespaceSource('workflow_search_attributes', ['value_string', 'value_keyword'], [], $runOrInstanceScope),
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
        $namespace = strtolower($namespace);

        $run = WorkflowRun::query()->find($runId);
        if ($run instanceof WorkflowRun) {
            $this->collectReferences($run->arguments, $uris);
            $this->collectReferences($run->output, $uris);
            $this->collectReferences($run->memo, $uris);
            $this->collectReferences($run->search_attributes, $uris);
            $this->collectReferences($run->visibility_labels, $uris);
        }

        $this->collectPayloadColumn(WorkflowRunSummary::query()->where('id', $runId), 'visibility_labels', $uris);
        $this->collectPayloadColumn(ActivityExecution::query()->where('workflow_run_id', $runId), 'arguments', $uris);
        $this->collectPayloadColumn(ActivityExecution::query()->where('workflow_run_id', $runId), 'result', $uris);
        $this->collectPayloadColumn(ActivityExecution::query()->where('workflow_run_id', $runId), 'exception', $uris);
        $this->collectPayloadColumn($this->anyRunReference(WorkflowCommand::query(), $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowHistoryEvent::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowMemo::query()->where('workflow_run_id', $runId), 'value', $uris);
        $this->collectPayloadColumn(WorkflowTask::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $messages = $this->anyColumnReference(WorkflowMessage::query(), [
            'workflow_run_id',
            'source_workflow_run_id',
            'target_workflow_run_id',
        ], $runId);
        $this->collectPayloadColumn(clone $messages, 'metadata', $uris);
        $this->collectReferenceColumn(clone $messages, 'payload_reference', $uris);
        $childCalls = $this->anyColumnReference(WorkflowChildCall::query(), [
            'parent_workflow_run_id',
            'resolved_child_run_id',
        ], $runId);
        $this->collectPayloadColumn(clone $childCalls, 'arguments', $uris);
        $this->collectPayloadColumn(clone $childCalls, 'metadata', $uris);
        $this->collectReferenceColumn(clone $childCalls, 'result_payload_reference', $uris);
        $serviceCalls = $this->serviceCallsForRunRetention($namespace, $runId);
        foreach ($this->serviceCallPayloadColumns() as $column) {
            $this->collectPayloadColumn(clone $serviceCalls, $column, $uris);
        }
        foreach ($this->serviceCallReferenceColumns() as $column) {
            $this->collectReferenceColumn(clone $serviceCalls, $column, $uris);
        }
        $this->collectPayloadColumn(WorkflowScheduleHistoryEvent::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowDurableStream::query()->where('workflow_run_id', $runId), 'metadata', $uris);
        $this->collectPayloadColumn(WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectReferenceColumn(
            WorkflowDurableStreamItem::query()->where('workflow_run_id', $runId),
            'payload_reference',
            $uris,
        );
        $this->collectPayloadColumn(WorkflowRunTimerEntry::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowRunWait::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowRunLineageEntry::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn($this->anyRunReference(WorkflowSignal::query(), $runId), 'arguments', $uris);
        $this->collectPayloadColumn(WorkflowTimelineEntry::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn($this->anyRunReference(WorkflowUpdate::query(), $runId), 'arguments', $uris);
        $this->collectPayloadColumn($this->anyRunReference(WorkflowUpdate::query(), $runId), 'result', $uris);

        foreach (['value_string', 'value_keyword'] as $column) {
            $this->collectPayloadColumn(WorkflowSearchAttribute::query()->where('workflow_run_id', $runId), $column, $uris);
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
    ): bool {
        $runColumns = [
            'arguments',
            'output',
            'memo',
            'search_attributes',
            'visibility_labels',
        ];
        $runColumns = array_values(array_filter(
            $runColumns,
            static fn (string $column): bool => Schema::hasTable('workflow_runs') && Schema::hasColumn('workflow_runs', $column),
        ));

        if ($runColumns !== []) {
            foreach (WorkflowRun::query()
                ->whereIn('id', $this->retainedRunIdsQuery($deletedRunIds))
                ->select($runColumns)
                ->cursor() as $run) {
                foreach ($runColumns as $column) {
                    if ($this->valueReferencesUri($run->{$column}, $uri)) {
                        return true;
                    }
                }
            }
        }

        foreach ($this->retainedPayloadColumns(
            $deletedRunIds,
            $releasedInboundStreamItemIds,
        ) as [$query, $column]) {
            if ($this->payloadColumnReferencesUri($query, $column, $uri)) {
                return true;
            }
        }

        foreach ($this->retainedReferenceColumns($deletedRunIds) as [$query, $column]) {
            if ($this->referenceColumnReferencesUri($query, $column, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $deletedRunIds
     * @return Builder<WorkflowRunSummary>
     */
    private function retainedRunIdsQuery(array $deletedRunIds): Builder
    {
        return WorkflowRunSummary::query()
            ->select('id')
            ->whereNotIn('id', $deletedRunIds);
    }

    /**
     * @param  list<string>  $deletedRunIds
     * @return list<array{0: Builder<Model>, 1: string}>
     */
    private function retainedPayloadColumns(array $deletedRunIds, array $releasedInboundStreamItemIds): array
    {
        $retainedInboundStreamItems = WorkflowInboundStreamItem::query();
        if ($releasedInboundStreamItemIds !== []) {
            $retainedInboundStreamItems->whereNotIn('id', $releasedInboundStreamItemIds);
        }

        return [
            [WorkflowRunSummary::query()->whereIn('id', $this->retainedRunIdsQuery($deletedRunIds)), 'visibility_labels'],
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'arguments'],
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'result'],
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'exception'],
            [$this->anyRetainedRunReference(WorkflowCommand::query(), $deletedRunIds), 'payload'],
            [WorkflowHistoryEvent::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [WorkflowMemo::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'value'],
            [WorkflowTask::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [$this->anyRetainedColumnReference(WorkflowMessage::query(), [
                'workflow_run_id',
                'source_workflow_run_id',
                'target_workflow_run_id',
            ], $deletedRunIds), 'metadata'],
            [$this->anyRetainedColumnReference(WorkflowChildCall::query(), [
                'parent_workflow_run_id',
                'resolved_child_run_id',
            ], $deletedRunIds), 'arguments'],
            [$this->anyRetainedColumnReference(WorkflowChildCall::query(), [
                'parent_workflow_run_id',
                'resolved_child_run_id',
            ], $deletedRunIds), 'metadata'],
            [WorkflowServiceCall::query(), 'deadline_policy'],
            [WorkflowServiceCall::query(), 'idempotency_policy'],
            [WorkflowServiceCall::query(), 'cancellation_policy'],
            [WorkflowServiceCall::query(), 'retry_policy'],
            [WorkflowServiceCall::query(), 'boundary_policy'],
            [WorkflowServiceCall::query(), 'metadata'],
            [WorkflowServiceCall::query(), 'outcome_metadata'],
            [WorkflowServiceCall::query(), 'caller_principal_claims'],
            [WorkflowScheduleHistoryEvent::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [WorkflowDurableStream::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'metadata'],
            [WorkflowDurableStreamItem::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [$retainedInboundStreamItems, 'payload_blob'],
            [WorkflowRunTimerEntry::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [WorkflowRunWait::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [WorkflowRunLineageEntry::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [$this->anyRetainedRunReference(WorkflowSignal::query(), $deletedRunIds), 'arguments'],
            [WorkflowTimelineEntry::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [$this->anyRetainedRunReference(WorkflowUpdate::query(), $deletedRunIds), 'arguments'],
            [$this->anyRetainedRunReference(WorkflowUpdate::query(), $deletedRunIds), 'result'],
            [WorkflowSearchAttribute::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'value_string'],
            [WorkflowSearchAttribute::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'value_keyword'],
        ];
    }

    /**
     * @param  list<string>  $deletedRunIds
     * @return list<array{0: Builder<Model>, 1: string}>
     */
    private function retainedReferenceColumns(array $deletedRunIds): array
    {
        $columns = [
            [
                WorkflowDurableStreamItem::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)),
                'payload_reference',
            ],
            [
                $this->anyRetainedColumnReference(WorkflowMessage::query(), [
                    'workflow_run_id',
                    'source_workflow_run_id',
                    'target_workflow_run_id',
                ], $deletedRunIds),
                'payload_reference',
            ],
            [
                $this->anyRetainedColumnReference(WorkflowChildCall::query(), [
                    'parent_workflow_run_id',
                    'resolved_child_run_id',
                ], $deletedRunIds),
                'result_payload_reference',
            ],
        ];

        foreach ($this->serviceCallReferenceColumns() as $column) {
            $columns[] = [
                WorkflowServiceCall::query(),
                $column,
            ];
        }

        return $columns;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $deletedRunIds
     * @return Builder<Model>
     */
    private function anyRetainedRunReference($query, array $deletedRunIds)
    {
        return $this->anyRetainedColumnReference($query, [
            'workflow_run_id',
            'requested_workflow_run_id',
            'resolved_workflow_run_id',
        ], $deletedRunIds);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     * @return Builder<Model>
     */
    private function anyColumnReference($query, array $columns, string $runId)
    {
        $columns = $this->existingColumns($query, $columns);

        if ($columns === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(static function (Builder $query) use ($columns, $runId): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $query->where($column, $runId);

                    continue;
                }

                $query->orWhere($column, $runId);
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     * @param  list<string>  $deletedRunIds
     * @return Builder<Model>
     */
    private function anyRetainedColumnReference($query, array $columns, array $deletedRunIds)
    {
        $columns = $this->existingColumns($query, $columns);

        if ($columns === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(static function (Builder $query) use ($columns, $deletedRunIds): void {
            foreach ($columns as $index => $column) {
                $retainedRunIds = WorkflowRunSummary::query()
                    ->select('id')
                    ->whereNotIn('id', $deletedRunIds);

                if ($index === 0) {
                    $query->whereIn($column, $retainedRunIds);

                    continue;
                }

                $query->orWhereIn($column, $retainedRunIds);
            }
        });
    }

    /**
     * @return Builder<Model>
     */
    private function serviceCallsForRunRetention(string $namespace, string $runId)
    {
        $query = WorkflowServiceCall::query();
        $runColumns = $this->existingColumns($query, [
            'caller_workflow_run_id',
            'linked_workflow_run_id',
        ]);
        $namespaceColumns = $this->existingColumns($query, [
            'namespace',
            'target_namespace',
        ]);

        if ($runColumns === [] || $namespaceColumns === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(static function (Builder $query) use ($runColumns, $runId): void {
                foreach ($runColumns as $index => $column) {
                    if ($index === 0) {
                        $query->where($column, $runId);

                        continue;
                    }

                    $query->orWhere($column, $runId);
                }
            })
            ->where(static function (Builder $query) use ($namespaceColumns, $namespace): void {
                foreach ($namespaceColumns as $index => $column) {
                    if ($index === 0) {
                        $query->where($column, $namespace);

                        continue;
                    }

                    $query->orWhere($column, $namespace);
                }
            });
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function anyRunReference($query, string $runId)
    {
        return $this->anyColumnReference($query, [
            'workflow_run_id',
            'requested_workflow_run_id',
            'resolved_workflow_run_id',
        ], $runId);
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

        return DB::table($table)
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
            return DB::table($table);
        }

        return DB::table($table)
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

            if ($values !== [] && Schema::hasColumn($table, $column)) {
                $existing[$column] = $values;
            }
        }

        return $existing;
    }

    /**
     * @param  array<int, string>  $uris
     */
    private function collectPayloadTableColumn($query, string $table, string $column, array &$uris): void
    {
        if (! $this->hasTableColumn($table, $column)) {
            return;
        }

        foreach ($query->select([$column])->cursor() as $row) {
            $this->collectReferences($row->{$column}, $uris);
        }
    }

    /**
     * @param  array<int, string>  $uris
     */
    private function collectReferenceTableColumn($query, string $table, string $column, array &$uris): void
    {
        if (! $this->hasTableColumn($table, $column)) {
            return;
        }

        foreach ($query->select([$column])->cursor() as $row) {
            $uri = $row->{$column};

            if ($this->isDirectPayloadReference($uri)) {
                $uris[] = $uri;
            }
        }
    }

    /**
     * @param  array<int, array{namespace: string, uri: string}>  $references
     */
    private function collectPayloadTableColumnReferences(
        $query,
        string $table,
        string $column,
        array &$references,
        string $defaultNamespace,
        ?string $ownerNamespaceColumn,
    ): void {
        if (! $this->hasTableColumn($table, $column)) {
            return;
        }

        $ownerNamespaceColumn = $this->ownerNamespaceColumn($table, $ownerNamespaceColumn);
        $select = array_values(array_unique(array_filter([$column, $ownerNamespaceColumn])));

        foreach ($query->select($select)->cursor() as $row) {
            $namespace = $this->ownerNamespaceForRow($row, $defaultNamespace, $ownerNamespaceColumn);
            $uris = [];
            $this->collectReferences($row->{$column}, $uris);

            foreach ($uris as $uri) {
                $references[] = [
                    'namespace' => $namespace,
                    'uri' => $uri,
                ];
            }
        }
    }

    /**
     * @param  array<int, array{namespace: string, uri: string}>  $references
     */
    private function collectReferenceTableColumnReferences(
        $query,
        string $table,
        string $column,
        array &$references,
        string $defaultNamespace,
        ?string $ownerNamespaceColumn,
    ): void {
        if (! $this->hasTableColumn($table, $column)) {
            return;
        }

        $ownerNamespaceColumn = $this->ownerNamespaceColumn($table, $ownerNamespaceColumn);
        $select = array_values(array_unique(array_filter([$column, $ownerNamespaceColumn])));

        foreach ($query->select($select)->cursor() as $row) {
            $uri = $row->{$column};

            if ($this->isDirectPayloadReference($uri)) {
                $references[] = [
                    'namespace' => $this->ownerNamespaceForRow($row, $defaultNamespace, $ownerNamespaceColumn),
                    'uri' => $uri,
                ];
            }
        }
    }

    private function payloadTableColumnReferencesUri($query, string $table, string $column, string $uri): bool
    {
        if (! $this->hasTableColumn($table, $column)) {
            return false;
        }

        foreach ($query->select([$column])->cursor() as $row) {
            if ($this->valueReferencesUri($row->{$column}, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function referenceTableColumnReferencesUri($query, string $table, string $column, string $uri): bool
    {
        if (! $this->hasTableColumn($table, $column)) {
            return false;
        }

        return $query->where($column, $uri)->exists();
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

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $uris
     */
    private function collectReferenceColumn($query, string $column, array &$uris): void
    {
        if (! $this->hasColumn($query, $column)) {
            return;
        }

        foreach ($query->select([$column])->cursor() as $row) {
            $uri = $row->{$column};

            if ($this->isDirectPayloadReference($uri)) {
                $uris[] = $uri;
            }
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function payloadColumnReferencesUri($query, string $column, string $uri): bool
    {
        if (! $this->hasColumn($query, $column)) {
            return false;
        }

        foreach ($query->select([$column])->cursor() as $row) {
            if ($this->valueReferencesUri($row->{$column}, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function referenceColumnReferencesUri($query, string $column, string $uri): bool
    {
        if (! $this->hasColumn($query, $column)) {
            return false;
        }

        return $query->where($column, $uri)->exists();
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function existingColumns($query, array $columns): array
    {
        $table = $query->getModel()->getTable();

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasTable($table) && Schema::hasColumn($table, $column),
        ));
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function hasColumn($query, string $column): bool
    {
        $table = $query->getModel()->getTable();

        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function hasTableColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function valueReferencesUri(mixed $value, string $uri): bool
    {
        $uris = [];
        $this->collectReferences($value, $uris);

        return in_array($uri, $uris, true);
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
