<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSearchAttribute;
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
        $references = $this->referencesForRun($runId);

        if ($references === []) {
            return [
                'found' => 0,
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

        foreach ($references as $uri) {
            if ($this->isReferencedByRetainedRun($uri, $runId)) {
                continue;
            }

            try {
                $driver->delete($uri);
                $deleted++;
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
     * @return list<string>
     */
    private function referencesForRun(string $runId): array
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

        $this->collectPayloadColumn(ActivityExecution::query()->where('workflow_run_id', $runId), 'arguments', $uris);
        $this->collectPayloadColumn(ActivityExecution::query()->where('workflow_run_id', $runId), 'result', $uris);
        $this->collectPayloadColumn(ActivityExecution::query()->where('workflow_run_id', $runId), 'exception', $uris);
        $this->collectPayloadColumn($this->anyRunReference(WorkflowCommand::query(), $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowHistoryEvent::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowMemo::query()->where('workflow_run_id', $runId), 'value', $uris);
        $this->collectPayloadColumn(WorkflowTask::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowMessage::query()->where('workflow_run_id', $runId), 'metadata', $uris);
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

    private function isReferencedByRetainedRun(string $uri, string $runId): bool
    {
        $runColumns = [
            'arguments',
            'output',
            'memo',
            'search_attributes',
            'visibility_labels',
        ];

        foreach (WorkflowRun::query()
            ->whereIn('id', $this->retainedRunIdsQuery($runId))
            ->select($runColumns)
            ->cursor() as $run) {
            foreach ($runColumns as $column) {
                if ($this->valueReferencesUri($run->{$column}, $uri)) {
                    return true;
                }
            }
        }

        foreach ($this->retainedPayloadColumns($runId) as [$query, $column]) {
            if ($this->payloadColumnReferencesUri($query, $column, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Builder<WorkflowRunSummary>
     */
    private function retainedRunIdsQuery(string $runId): Builder
    {
        return WorkflowRunSummary::query()
            ->select('id')
            ->where('id', '<>', $runId);
    }

    /**
     * @return list<array{0: Builder<Model>, 1: string}>
     */
    private function retainedPayloadColumns(string $runId): array
    {
        return [
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'arguments'],
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'result'],
            [ActivityExecution::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'exception'],
            [$this->anyRetainedRunReference(WorkflowCommand::query(), $runId), 'payload'],
            [WorkflowHistoryEvent::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'payload'],
            [WorkflowMemo::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'value'],
            [WorkflowTask::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'payload'],
            [WorkflowMessage::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'metadata'],
            [WorkflowRunTimerEntry::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'payload'],
            [WorkflowRunWait::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'payload'],
            [WorkflowRunLineageEntry::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'payload'],
            [$this->anyRetainedRunReference(WorkflowSignal::query(), $runId), 'arguments'],
            [WorkflowTimelineEntry::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'payload'],
            [$this->anyRetainedRunReference(WorkflowUpdate::query(), $runId), 'arguments'],
            [$this->anyRetainedRunReference(WorkflowUpdate::query(), $runId), 'result'],
            [WorkflowSearchAttribute::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'value_string'],
            [WorkflowSearchAttribute::query()->whereIn('workflow_run_id', $this->retainedRunIdsQuery($runId)), 'value_keyword'],
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function anyRetainedRunReference($query, string $runId)
    {
        return $query->where(static function (Builder $query) use ($runId): void {
            $retainedRunIds = WorkflowRunSummary::query()
                ->select('id')
                ->where('id', '<>', $runId);
            $requestedRunIds = clone $retainedRunIds;
            $resolvedRunIds = clone $retainedRunIds;

            $query
                ->whereIn('workflow_run_id', $retainedRunIds)
                ->orWhereIn('requested_workflow_run_id', $requestedRunIds)
                ->orWhereIn('resolved_workflow_run_id', $resolvedRunIds);
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function anyRunReference($query, string $runId)
    {
        return $query->where(static function (Builder $query) use ($runId): void {
            $query
                ->where('workflow_run_id', $runId)
                ->orWhere('requested_workflow_run_id', $runId)
                ->orWhere('resolved_workflow_run_id', $runId);
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<int, string>  $uris
     */
    private function collectPayloadColumn($query, string $column, array &$uris): void
    {
        foreach ($query->select([$column])->cursor() as $row) {
            $this->collectReferences($row->{$column}, $uris);
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function payloadColumnReferencesUri($query, string $column, string $uri): bool
    {
        foreach ($query->select([$column])->cursor() as $row) {
            if ($this->valueReferencesUri($row->{$column}, $uri)) {
                return true;
            }
        }

        return false;
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
