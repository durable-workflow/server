<?php

namespace App\Support;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reclaims the items of closed and errored workflow streams past their
 * retention (Server #774).
 *
 * A stream past `closed_at` plus its retention already reads as not found;
 * this pass removes its items. The stream row stays, with no items, until its
 * run is pruned, so its name can never be opened again inside the run.
 *
 * Discovery reads one namespace's due streams in deadline order from the
 * (namespace, reclaim_at, id) index, at most `$streamLimit` of them. A pass
 * deletes at most `$itemBudget` items, in batches of at most ITEM_BATCH. Each
 * batch first settles its items' external payloads through
 * ExternalPayloadRetentionCleanup, with run retention's shared-reference,
 * backup-hold, blocked-driver and failure handling, and deletes the items
 * only after that succeeds. A blocked or failed batch leaves its items for
 * the next pass. Once a stream has no items its `reclaim_at` is cleared, so
 * discovery no longer reads it.
 */
final class WorkflowStreamRetention
{
    public const ITEM_BATCH = 100;

    public const INLINE_STREAM_LIMIT = 10;

    public const INLINE_ITEM_BUDGET = 500;

    private const INLINE_CACHE_PREFIX = 'server:stream-retention-inline:';

    private const INLINE_THROTTLE_SECONDS = 60;

    /**
     * @return array{streams_reclaimed: int, items_removed: int, budget_exhausted: bool, blocked_reason: string|null}
     */
    public static function pruneExpired(string $namespace, int $streamLimit, int $itemBudget, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $report = ['streams_reclaimed' => 0, 'items_removed' => 0, 'budget_exhausted' => false, 'blocked_reason' => null];

        foreach (self::dueStreams($namespace, max(1, $streamLimit), $now) as $stream) {
            $step = self::reclaimStream($namespace, $stream, max(1, $itemBudget) - $report['items_removed'], $now);
            $report['items_removed'] += $step['deleted'];

            if ($step['reclaimed']) {
                $report['streams_reclaimed']++;
            }

            if ($step['blocked_reason'] !== null || $step['budget_exhausted']) {
                $report['blocked_reason'] = $step['blocked_reason'];
                $report['budget_exhausted'] = $step['budget_exhausted'];

                break;
            }
        }

        return $report;
    }

    /**
     * A small pass from ordinary worker traffic, at most once a minute per
     * namespace, so expired streams are reclaimed without a scheduler.
     *
     * @return array{streams_reclaimed: int, items_removed: int, budget_exhausted: bool, blocked_reason: string|null}|null
     */
    public static function runInlinePass(string $namespace): ?array
    {
        if (! Cache::add(self::INLINE_CACHE_PREFIX.hash('sha256', $namespace), '1', now()->addSeconds(self::INLINE_THROTTLE_SECONDS))) {
            return null;
        }

        return self::pruneExpired($namespace, self::INLINE_STREAM_LIMIT, self::INLINE_ITEM_BUDGET);
    }

    /**
     * The namespace's due streams, oldest deadline first.
     *
     * @return list<WorkflowDurableStream>
     */
    public static function dueStreams(string $namespace, int $limit, Carbon $now): array
    {
        return self::dueStreamsQuery($namespace, $limit, $now)->get()->all();
    }

    /**
     * The predicate and order match the (namespace, reclaim_at, id) index, so
     * the read is an index range scan that stops at the limit on every
     * engine.
     *
     * @return Builder<WorkflowDurableStream>
     */
    public static function dueStreamsQuery(string $namespace, int $limit, Carbon $now): Builder
    {
        return WorkflowDurableStream::query()
            ->select(['id', 'workflow_run_id'])
            ->where('namespace', $namespace)
            ->where('reclaim_at', '<=', $now)
            ->orderBy('reclaim_at')
            ->orderBy('id')
            ->limit($limit);
    }

    /**
     * @return array{deleted: int, reclaimed: bool, budget_exhausted: bool, blocked_reason: string|null}
     */
    private static function reclaimStream(string $namespace, WorkflowDurableStream $stream, int $budget, Carbon $now): array
    {
        $deleted = 0;

        while ($deleted < $budget) {
            $ids = WorkflowDurableStreamItem::query()
                ->where('stream_id', $stream->id)
                ->orderBy('offset')
                ->limit(min(self::ITEM_BATCH, $budget - $deleted))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids !== []) {
                // Failures propagate before any item is deleted.
                $payloads = app(ExternalPayloadRetentionCleanup::class)->deleteForDurableStreamItems($namespace, $ids);

                if ($payloads['blocked']) {
                    return [
                        'deleted' => $deleted,
                        'reclaimed' => false,
                        'budget_exhausted' => false,
                        'blocked_reason' => $payloads['reason'] ?? 'external_payload_cleanup_blocked',
                    ];
                }
            }

            $step = DB::transaction(static fn (): array => self::deleteBatch($stream, $ids, $now));
            $deleted += $step['deleted'];

            if ($step['done'] || $step['deleted'] === 0) {
                return ['deleted' => $deleted, 'reclaimed' => $step['reclaimed'], 'budget_exhausted' => false, 'blocked_reason' => null];
            }
        }

        return ['deleted' => $deleted, 'reclaimed' => false, 'budget_exhausted' => true, 'blocked_reason' => null];
    }

    /**
     * One transaction: under the run and stream locks, delete the batch's
     * items, and clear the stream's deadline once none are left.
     *
     * @param  list<int>  $ids
     * @return array{deleted: int, done: bool, reclaimed: bool}
     */
    private static function deleteBatch(WorkflowDurableStream $due, array $ids, Carbon $now): array
    {
        WorkflowRunStreamLock::acquire((string) $due->workflow_run_id);
        $stream = WorkflowDurableStream::query()->whereKey($due->id)->lockForUpdate()->first();

        if ($stream === null || $stream->reclaim_at === null || ! WorkflowStreamService::isExpired($stream, $now)) {
            return ['deleted' => 0, 'done' => true, 'reclaimed' => false];
        }

        $deleted = $ids === []
            ? 0
            : WorkflowDurableStreamItem::query()->where('stream_id', $stream->id)->whereKey($ids)->delete();

        if (WorkflowDurableStreamItem::query()->where('stream_id', $stream->id)->exists()) {
            return ['deleted' => $deleted, 'done' => false, 'reclaimed' => false];
        }

        // The terminal row keeps the stream's identity until its run is
        // pruned; without a deadline discovery no longer reads it.
        $stream->forceFill(['reclaim_at' => null])->save();

        return ['deleted' => $deleted, 'done' => true, 'reclaimed' => true];
    }
}
