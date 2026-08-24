<?php

namespace App\Support;

use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class MessageStreamRetentionCleanup
{
    /**
     * Build the stream portion of a run-retention pass.
     *
     * Active and continued instances keep their stream, cursor checkpoint,
     * pending items, and identity rows. A consumed item becomes a bounded
     * identity tombstone only after a retained successor checkpoint proves
     * that its payload is no longer needed for replay. A terminal instance
     * keeps all stream state until its final retained run expires, when the
     * complete instance-scoped stream is removed.
     *
     * @return array{
     *     namespace: string,
     *     run_id: string,
     *     workflow_instance_id: string,
     *     stream_ids: list<int>,
     *     releasable_item_ids: list<int>,
     *     delete_instance_state: bool
     * }
     */
    public function planForRun(string $namespace, string $runId, string $workflowInstanceId): array
    {
        $streams = WorkflowInboundStream::query()
            ->where('namespace', $namespace)
            ->where('workflow_instance_id', $workflowInstanceId)
            ->get(['id', 'cursor_position', 'cursor_checkpoint_run_id']);
        $streamIds = $streams->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($streamIds === []) {
            return $this->emptyPlan($namespace, $runId, $workflowInstanceId);
        }

        $instance = WorkflowInstance::query()
            ->whereKey($workflowInstanceId)
            ->where('namespace', $namespace)
            ->first();
        $currentRunId = $instance instanceof WorkflowInstance && is_string($instance->current_run_id)
            ? $instance->current_run_id
            : null;
        $currentRun = $currentRunId !== null ? WorkflowRun::query()->find($currentRunId) : null;
        $retainedRunIds = WorkflowRunSummary::query()
            ->where('namespace', $namespace)
            ->where('workflow_instance_id', $workflowInstanceId)
            ->whereKeyNot($runId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $finalTerminalRun = $currentRun instanceof WorkflowRun
            && $currentRun->status->isTerminal()
            && $retainedRunIds === [];

        if ($finalTerminalRun) {
            return [
                'namespace' => $namespace,
                'run_id' => $runId,
                'workflow_instance_id' => $workflowInstanceId,
                'stream_ids' => $streamIds,
                'releasable_item_ids' => WorkflowInboundStreamItem::query()
                    ->whereIn('stream_id', $streamIds)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all(),
                'delete_instance_state' => true,
            ];
        }

        $retainedReplayRunIds = array_values(array_unique(array_filter([
            ...$retainedRunIds,
            $currentRun instanceof WorkflowRun && $currentRunId !== $runId ? $currentRunId : null,
        ], static fn (mixed $id): bool => is_string($id) && $id !== '')));
        $checkpointedStreams = $streams
            ->filter(static fn (WorkflowInboundStream $stream): bool => in_array(
                (string) $stream->cursor_checkpoint_run_id,
                $retainedReplayRunIds,
                true,
            ))
            ->keyBy(static fn (WorkflowInboundStream $stream): int => (int) $stream->id);

        $releasableItemIds = WorkflowInboundStreamItem::query()
            ->whereIn('stream_id', $checkpointedStreams->keys()->all())
            ->where('consumed_run_id', $runId)
            ->whereNotNull('consumed_at')
            ->whereNull('payload_released_at')
            ->get(['id', 'stream_id', 'position'])
            ->filter(static function (WorkflowInboundStreamItem $item) use ($checkpointedStreams): bool {
                $stream = $checkpointedStreams->get((int) $item->stream_id);

                return $stream instanceof WorkflowInboundStream
                    && (int) $item->position <= (int) $stream->cursor_position;
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'namespace' => $namespace,
            'run_id' => $runId,
            'workflow_instance_id' => $workflowInstanceId,
            'stream_ids' => $streamIds,
            'releasable_item_ids' => $releasableItemIds,
            'delete_instance_state' => false,
        ];
    }

    /**
     * @param  array{run_id: string, stream_ids: list<int>, releasable_item_ids: list<int>, delete_instance_state: bool}  $plan
     */
    public function markBlocked(array $plan, string $reason): void
    {
        if ($plan['stream_ids'] === []) {
            return;
        }

        WorkflowInboundStream::query()
            ->whereIn('id', $plan['stream_ids'])
            ->update([
                'cleanup_blocked_at' => now(),
                'cleanup_blocked_reason' => substr($reason, 0, 64),
                'cleanup_blocked_run_id' => $plan['run_id'],
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array{run_id: string, stream_ids: list<int>, releasable_item_ids: list<int>, delete_instance_state: bool}  $plan
     * @return array{message_streams_deleted: int, message_stream_items_deleted: int, message_stream_items_compacted: int}
     */
    public function apply(array $plan): array
    {
        if ($plan['stream_ids'] === []) {
            return $this->emptyReport();
        }

        return DB::transaction(function () use ($plan): array {
            if ($plan['delete_instance_state']) {
                $itemsDeleted = WorkflowInboundStreamItem::query()
                    ->whereIn('stream_id', $plan['stream_ids'])
                    ->count();
                $streamsDeleted = WorkflowInboundStream::query()
                    ->whereIn('id', $plan['stream_ids'])
                    ->delete();

                return [
                    'message_streams_deleted' => $streamsDeleted,
                    'message_stream_items_deleted' => $itemsDeleted,
                    'message_stream_items_compacted' => 0,
                ];
            }

            $itemsCompacted = $plan['releasable_item_ids'] === []
                ? 0
                : WorkflowInboundStreamItem::query()
                    ->whereIn('id', $plan['releasable_item_ids'])
                    ->whereNotNull('consumed_at')
                    ->whereNull('payload_released_at')
                    ->update([
                        'payload_blob' => '',
                        'payload_released_at' => now(),
                        'updated_at' => now(),
                    ]);

            WorkflowInboundStream::query()
                ->whereIn('id', $plan['stream_ids'])
                ->where('cleanup_blocked_run_id', $plan['run_id'])
                ->update([
                    'cleanup_blocked_at' => null,
                    'cleanup_blocked_reason' => null,
                    'cleanup_blocked_run_id' => null,
                    'updated_at' => now(),
                ]);

            return [
                'message_streams_deleted' => 0,
                'message_stream_items_deleted' => 0,
                'message_stream_items_compacted' => $itemsCompacted,
            ];
        });
    }

    /**
     * @return array{namespace: string, run_id: string, workflow_instance_id: string, stream_ids: list<int>, releasable_item_ids: list<int>, delete_instance_state: false}
     */
    private function emptyPlan(string $namespace, string $runId, string $workflowInstanceId): array
    {
        return [
            'namespace' => $namespace,
            'run_id' => $runId,
            'workflow_instance_id' => $workflowInstanceId,
            'stream_ids' => [],
            'releasable_item_ids' => [],
            'delete_instance_state' => false,
        ];
    }

    /** @return array{message_streams_deleted: 0, message_stream_items_deleted: 0, message_stream_items_compacted: 0} */
    private function emptyReport(): array
    {
        return [
            'message_streams_deleted' => 0,
            'message_stream_items_deleted' => 0,
            'message_stream_items_compacted' => 0,
        ];
    }
}
