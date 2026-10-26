<?php

namespace App\Support;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reclaims closed and errored workflow streams past their retention
 * (Server #774).
 *
 * An expired stream already reads as not found; this pass removes its rows.
 * Each pass is bounded: it considers at most `$streamLimit` expired streams
 * of one namespace, oldest close first, and deletes at most `$itemBudget`
 * items in transactions of at most ITEM_BATCH items. A stream row goes in
 * the transaction that finds it has no items left. Work left over is taken
 * up by the next pass, which finds the same oldest streams again.
 */
final class WorkflowStreamRetention
{
    public const ITEM_BATCH = 500;

    public const INLINE_STREAM_LIMIT = 10;

    public const INLINE_ITEM_BUDGET = 1000;

    private const INLINE_CACHE_PREFIX = 'server:stream-retention-inline:';

    private const INLINE_THROTTLE_SECONDS = 60;

    /**
     * @return array{streams_removed: int, items_removed: int, budget_exhausted: bool}
     */
    public static function pruneExpired(string $namespace, int $streamLimit, int $itemBudget, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $streamLimit = max(1, $streamLimit);
        $itemBudget = max(1, $itemBudget);

        $candidates = WorkflowDurableStream::query()
            ->where('namespace', $namespace)
            ->whereIn('status', [WorkflowDurableStream::STATUS_CLOSED, WorkflowDurableStream::STATUS_ERRORED])
            ->whereNotNull('closed_at')
            ->whereRaw(self::expiredSql(), [$now->copy()->utc()->format('Y-m-d H:i:s')])
            ->orderBy('closed_at')
            ->orderBy('id')
            ->limit($streamLimit)
            ->pluck('id');

        $streams = 0;
        $items = 0;

        foreach ($candidates as $streamId) {
            while (true) {
                if ($items >= $itemBudget) {
                    return ['streams_removed' => $streams, 'items_removed' => $items, 'budget_exhausted' => true];
                }

                $take = min(self::ITEM_BATCH, $itemBudget - $items);
                $step = DB::transaction(static function () use ($streamId, $take, $now): array {
                    $stream = WorkflowDurableStream::query()->whereKey($streamId)->lockForUpdate()->first();

                    if ($stream === null || ! WorkflowStreamService::isExpired($stream, $now)) {
                        return ['deleted' => 0, 'done' => true, 'removed' => false];
                    }

                    $ids = WorkflowDurableStreamItem::query()
                        ->where('stream_id', $streamId)
                        ->orderBy('offset')
                        ->limit($take)
                        ->pluck('id')
                        ->all();

                    if ($ids !== []) {
                        WorkflowDurableStreamItem::query()->whereKey($ids)->delete();
                    }

                    if (count($ids) < $take) {
                        $stream->delete();

                        return ['deleted' => count($ids), 'done' => true, 'removed' => true];
                    }

                    return ['deleted' => count($ids), 'done' => false, 'removed' => false];
                });

                $items += $step['deleted'];

                if ($step['removed']) {
                    $streams++;
                }

                if ($step['done']) {
                    break;
                }
            }
        }

        return ['streams_removed' => $streams, 'items_removed' => $items, 'budget_exhausted' => false];
    }

    /**
     * A small pass from ordinary worker traffic, at most once a minute per
     * namespace, so expired streams are reclaimed without a scheduler.
     *
     * @return array{streams_removed: int, items_removed: int, budget_exhausted: bool}|null
     */
    public static function runInlinePass(string $namespace): ?array
    {
        if (! Cache::add(self::INLINE_CACHE_PREFIX.sha1($namespace), '1', now()->addSeconds(self::INLINE_THROTTLE_SECONDS))) {
            return null;
        }

        return self::pruneExpired($namespace, self::INLINE_STREAM_LIMIT, self::INLINE_ITEM_BUDGET);
    }

    /**
     * `closed_at` plus the stream's retention (3600 seconds when none was
     * recorded) is no later than the bound UTC instant.
     */
    private static function expiredSql(): string
    {
        $retention = 'COALESCE(retention_seconds, '.WorkflowStreamService::DEFAULT_CLOSED_RETENTION_SECONDS.')';

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "closed_at <= CAST(? AS timestamp) - ({$retention} * INTERVAL '1 second')",
            'mysql', 'mariadb' => "closed_at <= DATE_SUB(CAST(? AS DATETIME(6)), INTERVAL {$retention} SECOND)",
            default => "closed_at <= datetime(?, '-' || {$retention} || ' seconds')",
        };
    }
}
