<?php

namespace App\Support;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    public function deleteForRun(string $namespace, string $runId): array
    {
        return $this->deleteForRuns($namespace, [$runId]);
    }

    /**
     * @param  list<string>  $runIds
     * @return array{found: int, deleted: int, blocked: bool, reason: string|null}
     */
    public function deleteForRuns(string $namespace, array $runIds, bool $includeNamespaceOwnedReferences = false): array
    {
        $runIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $runId): string => (string) $runId, $runIds),
            static fn (string $runId): bool => $runId !== '',
        )));

        $references = $this->referencesForRuns($namespace, $runIds, $includeNamespaceOwnedReferences);

        if ($references === []) {
            return [
                'found' => 0,
                'deleted' => 0,
                'blocked' => false,
                'reason' => null,
            ];
        }

        try {
            $driver = app(NamespaceExternalPayloadStorage::class)->driverFor($namespace);
        } catch (\Throwable) {
            $driver = null;
        }

        if ($driver === null) {
            return [
                'found' => count($references),
                'deleted' => 0,
                'blocked' => true,
                'reason' => 'external_payload_storage_driver_unavailable',
            ];
        }

        $deleted = 0;

        foreach ($references as $uri) {
            if ($this->isReferencedByRetainedRun($uri, $namespace, $runIds)) {
                continue;
            }

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
    private function referencesForRuns(string $namespace, array $runIds, bool $includeNamespaceOwnedReferences): array
    {
        $uris = [];

        foreach ($runIds as $runId) {
            foreach ($this->referencesForRun($namespace, $runId) as $uri) {
                $uris[] = $uri;
            }
        }

        if ($includeNamespaceOwnedReferences) {
            foreach ($this->referencesForNamespaceOwnedRows($namespace) as $uri) {
                $uris[] = $uri;
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @return list<string>
     */
    private function referencesForRun(string $namespace, string $runId): array
    {
        $uris = [];

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
        $this->collectPayloadColumn(WorkflowMessage::query()->where('workflow_run_id', $runId), 'metadata', $uris);
        $this->collectPayloadColumn(WorkflowChildCall::query()->where('parent_workflow_run_id', $runId), 'arguments', $uris);
        $this->collectPayloadColumn(WorkflowChildCall::query()->where('parent_workflow_run_id', $runId), 'metadata', $uris);

        foreach ($this->serviceCallPayloadColumns() as $column) {
            $this->collectPayloadColumn($this->ownedServiceCallRunReference($namespace, $runId), $column, $uris);
        }
        foreach ($this->serviceCallReferenceColumns() as $column) {
            $this->collectExternalUriReferenceColumn($this->ownedServiceCallRunReference($namespace, $runId), $column, $uris);
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
     * @return list<string>
     */
    private function referencesForNamespaceOwnedRows(string $namespace): array
    {
        $uris = [];

        foreach ($this->serviceCallPayloadColumns() as $column) {
            $this->collectPayloadColumn($this->ownedServiceCallQuery($namespace), $column, $uris);
        }
        foreach ($this->serviceCallReferenceColumns() as $column) {
            $this->collectExternalUriReferenceColumn($this->ownedServiceCallQuery($namespace), $column, $uris);
        }

        return array_values(array_unique($uris));
    }

    /**
     * @param  list<string>  $deletedRunIds
     */
    private function isReferencedByRetainedRun(string $uri, string $namespace, array $deletedRunIds): bool
    {
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

        foreach ($this->retainedPayloadColumns($namespace, $deletedRunIds) as [$query, $column]) {
            if ($this->payloadColumnReferencesUri($query, $column, $uri)) {
                return true;
            }
        }

        foreach ($this->retainedReferenceColumns($namespace, $deletedRunIds) as [$query, $column]) {
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
    private function retainedPayloadColumns(string $namespace, array $deletedRunIds): array
    {
        $columns = [
            [WorkflowRunSummary::query()->whereIn('id', $this->retainedRunIdsQuery($deletedRunIds)), 'visibility_labels'],
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'arguments'],
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'result'],
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'exception'],
            [$this->anyRetainedRunReference(WorkflowCommand::query(), $deletedRunIds), 'payload'],
            [WorkflowHistoryEvent::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [WorkflowMemo::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'value'],
            [WorkflowTask::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [WorkflowMessage::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'metadata'],
            [WorkflowChildCall::query()->whereIn('parent_workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'arguments'],
            [WorkflowChildCall::query()->whereIn('parent_workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'metadata'],
            [WorkflowScheduleHistoryEvent::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
            [WorkflowDurableStream::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'metadata'],
            [WorkflowDurableStreamItem::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)), 'payload'],
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

        foreach ($this->serviceCallPayloadColumns() as $column) {
            $columns[] = [$this->retainedServiceCallQuery($namespace), $column];
        }

        return $columns;
    }

    /**
     * @param  list<string>  $deletedRunIds
     * @return list<array{0: Builder<Model>, 1: string}>
     */
    private function retainedReferenceColumns(string $namespace, array $deletedRunIds): array
    {
        $columns = [
            [
                WorkflowDurableStreamItem::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($deletedRunIds)),
                'payload_reference',
            ],
        ];

        foreach ($this->serviceCallReferenceColumns() as $column) {
            $columns[] = [$this->retainedServiceCallQuery($namespace), $column];
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
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function anyRunReference($query, string $runId)
    {
        return $this->anyColumnReference($query, [
            'workflow_run_id',
        ], $runId);
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
     * @return Builder<WorkflowServiceCall>
     */
    private function ownedServiceCallRunReference(string $namespace, string $runId): Builder
    {
        return $this->anyColumnReference($this->ownedServiceCallQuery($namespace), [
            'caller_workflow_run_id',
            'linked_workflow_run_id',
        ], $runId);
    }

    /**
     * @return Builder<WorkflowServiceCall>
     */
    private function ownedServiceCallQuery(string $namespace): Builder
    {
        $query = WorkflowServiceCall::query();
        $columns = $this->existingColumns($query, ['namespace', 'target_namespace']);

        if ($columns === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(static function (Builder $query) use ($columns, $namespace): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $query->where($column, $namespace);

                    continue;
                }

                $query->orWhere($column, $namespace);
            }
        });
    }

    /**
     * @return Builder<WorkflowServiceCall>
     */
    private function retainedServiceCallQuery(string $namespace): Builder
    {
        $query = WorkflowServiceCall::query();
        $columns = $this->existingColumns($query, ['namespace', 'target_namespace']);

        if ($columns === []) {
            return $query;
        }

        return $query->where(static function (Builder $query) use ($columns, $namespace): void {
            foreach ($columns as $column) {
                $query->where(static function (Builder $query) use ($column, $namespace): void {
                    $query->where($column, '<>', $namespace)
                        ->orWhereNull($column);
                });
            }
        });
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

            if (is_string($uri) && $uri !== '') {
                $uris[] = $uri;
            }
        }
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $uris
     */
    private function collectExternalUriReferenceColumn($query, string $column, array &$uris): void
    {
        if (! $this->hasColumn($query, $column)) {
            return;
        }

        foreach ($query->select([$column])->cursor() as $row) {
            $uri = $row->{$column};

            if (is_string($uri) && $this->isExternalPayloadUri($uri)) {
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

    private function valueReferencesUri(mixed $value, string $uri): bool
    {
        $uris = [];
        $this->collectReferences($value, $uris);

        return in_array($uri, $uris, true);
    }

    private function isExternalPayloadUri(string $value): bool
    {
        return $value !== '' && is_string(parse_url($value, PHP_URL_SCHEME));
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
}
