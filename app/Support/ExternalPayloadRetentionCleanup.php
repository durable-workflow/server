<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\ExternalPayloadReference;

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
            $this->collectReferences($run->memo, $uris);
            $this->collectReferences($run->search_attributes, $uris);
            $this->collectReferences($run->visibility_labels, $uris);
        }

        $this->collectPayloadColumn(WorkflowHistoryEvent::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowTask::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowMessage::query()->where('workflow_run_id', $runId), 'metadata', $uris);
        $this->collectPayloadColumn(WorkflowRunTimerEntry::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowRunWait::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowRunLineageEntry::query()->where('workflow_run_id', $runId), 'payload', $uris);
        $this->collectPayloadColumn(WorkflowTimelineEntry::query()->where('workflow_run_id', $runId), 'payload', $uris);

        return array_values(array_unique($uris));
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
     * @param  array<int, string>  $uris
     */
    private function collectReferences(mixed $value, array &$uris): void
    {
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

        try {
            ExternalPayloadReference::fromArray($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
