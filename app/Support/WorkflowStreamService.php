<?php

namespace App\Support;

use App\Models\WorkflowDurableStream;
use App\Models\WorkflowDurableStreamItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Workflow\V2\Models\WorkflowRun;

/**
 * Server-side state machine for durable workflow streams.
 *
 * Holds the transactional rules around opening a stream, appending an
 * ordered batch of items with idempotency-key collapse, applying the
 * pending-items backpressure cap, recording a clean close, and reading
 * a paginated, offset-keyed window for a subscriber.
 *
 * Contract: every appended item is durably persisted before the
 * service returns. Consumers reconnect by passing `from = <last_seen
 * offset + 1>`; that's how a slow or crashed consumer resumes
 * without missing items, and why duplicate offsets are physically
 * impossible (DB-unique on (stream_id, offset)).
 */
final class WorkflowStreamService
{
    private const STREAM_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/';

    public const DEFAULT_MAX_PENDING_ITEMS = 10000;

    public const DEFAULT_MAX_ITEMS_PER_APPEND = 500;

    public const DEFAULT_MAX_ITEMS_PER_SUBSCRIBE = 100;

    public const SUBSCRIBE_MAX_ITEMS = 500;

    public const SUBSCRIBE_MAX_WAIT_SECONDS = 60;

    public const DEFAULT_CLOSED_RETENTION_SECONDS = 3600;

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{stream: WorkflowDurableStream, items: list<WorkflowDurableStreamItem>, accepted: int, deduped: int}
     */
    public function append(
        WorkflowRun $run,
        string $namespace,
        string $streamName,
        array $items,
        int $maxPendingItems = self::DEFAULT_MAX_PENDING_ITEMS,
    ): array {
        $this->assertValidStreamName($streamName);

        if ($items === []) {
            throw new InvalidArgumentException('append requires at least one item');
        }

        if (count($items) > self::DEFAULT_MAX_ITEMS_PER_APPEND) {
            throw new InvalidArgumentException(sprintf(
                'append accepts at most %d items per request',
                self::DEFAULT_MAX_ITEMS_PER_APPEND,
            ));
        }

        $result = DB::transaction(function () use ($run, $namespace, $streamName, $items, $maxPendingItems) {
            $stream = $this->lockStream($run, $namespace, $streamName, openIfMissing: true);

            if ($stream === null) {
                throw new RuntimeException('Stream lookup must return a row when openIfMissing is true');
            }

            if ($stream->status === WorkflowDurableStream::STATUS_CLOSED) {
                throw new StreamClosedException($stream);
            }

            if ($stream->status === WorkflowDurableStream::STATUS_ERRORED) {
                throw new StreamErroredException($stream);
            }

            $now = Carbon::now();
            $accepted = [];
            $deduped = 0;
            $offset = (int) $stream->last_offset;
            $streamFull = false;

            foreach ($items as $item) {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('each item must be an associative array');
                }

                $idempotencyKey = isset($item['idempotency_key']) ? (string) $item['idempotency_key'] : null;
                $idempotencyKey = ($idempotencyKey === '' ? null : $idempotencyKey);

                if ($idempotencyKey !== null) {
                    /** @var WorkflowDurableStreamItem|null $existing */
                    $existing = WorkflowDurableStreamItem::query()
                        ->where('stream_id', $stream->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        $accepted[] = $existing;
                        $deduped++;

                        continue;
                    }
                }

                if ($stream->pending_items >= $maxPendingItems) {
                    $streamFull = true;

                    break;
                }

                $offset++;

                $created = WorkflowDurableStreamItem::query()->create([
                    'stream_id' => $stream->id,
                    'namespace' => $namespace,
                    'workflow_run_id' => $run->id,
                    'stream_name' => $streamName,
                    'offset' => $offset,
                    'idempotency_key' => $idempotencyKey,
                    'origin' => isset($item['origin']) ? (string) $item['origin'] : 'workflow_command',
                    'origin_reference' => isset($item['origin_reference']) ? (string) $item['origin_reference'] : null,
                    'payload' => $item['payload'] ?? null,
                    'payload_reference' => isset($item['payload_reference']) ? (string) $item['payload_reference'] : null,
                    'payload_codec' => isset($item['payload_codec']) ? (string) $item['payload_codec'] : null,
                    'item_type' => isset($item['item_type']) ? (string) $item['item_type'] : null,
                    'content_type' => isset($item['content_type']) ? (string) $item['content_type'] : null,
                    'emitted_at' => $now,
                ]);

                $stream->last_offset = $offset;
                $stream->total_items = (int) $stream->total_items + 1;
                $stream->pending_items = (int) $stream->pending_items + 1;
                $stream->last_appended_at = $now;

                $accepted[] = $created;
            }

            $stream->save();

            return [
                'stream' => $stream->refresh(),
                'items' => $accepted,
                'accepted' => count($accepted) - $deduped,
                'deduped' => $deduped,
                'stream_full' => $streamFull,
            ];
        });

        if (($result['stream_full'] ?? false) === true) {
            throw new StreamFullException($result['stream'], $maxPendingItems);
        }

        unset($result['stream_full']);

        return $result;
    }

    /**
     * @return array{stream: WorkflowDurableStream, items: Collection<int, WorkflowDurableStreamItem>, next_offset: int, terminal: bool}
     */
    public function read(
        WorkflowRun $run,
        string $namespace,
        string $streamName,
        int $fromOffset,
        int $maxItems,
    ): array {
        $this->assertValidStreamName($streamName);

        if ($fromOffset < 0) {
            throw new InvalidArgumentException('from offset must be >= 0');
        }

        $maxItems = max(1, min($maxItems, self::SUBSCRIBE_MAX_ITEMS));

        $stream = $this->locateStream($run, $namespace, $streamName);

        if ($stream === null) {
            throw new StreamNotFoundException($run, $streamName);
        }

        /** @var Collection<int, WorkflowDurableStreamItem> $items */
        $items = WorkflowDurableStreamItem::query()
            ->where('stream_id', $stream->id)
            ->where('offset', '>=', $fromOffset)
            ->orderBy('offset')
            ->limit($maxItems)
            ->get();

        $nextOffset = $items->isEmpty()
            ? $fromOffset
            : ((int) $items->last()->offset) + 1;

        // Advisory pending-items decrement: we only deduct from pending
        // when the read window has reached the head of the durable
        // queue (consumer has caught up). This avoids decrementing
        // twice on overlapping subscribers.
        $caughtUp = $items->isNotEmpty()
            && ((int) $items->last()->offset) === (int) $stream->last_offset;

        if ($caughtUp && (int) $stream->pending_items > 0) {
            $remaining = max(
                0,
                ((int) $stream->last_offset + 1) - $nextOffset,
            );

            $stream->pending_items = $remaining;
            $stream->save();
            $stream->refresh();
        }

        return [
            'stream' => $stream,
            'items' => $items,
            'next_offset' => $nextOffset,
            'terminal' => $stream->isTerminal() && $nextOffset > (int) $stream->last_offset,
        ];
    }

    public function close(
        WorkflowRun $run,
        string $namespace,
        string $streamName,
        ?string $errorReason = null,
        ?int $retentionSeconds = null,
    ): WorkflowDurableStream {
        $this->assertValidStreamName($streamName);

        return DB::transaction(function () use ($run, $namespace, $streamName, $errorReason, $retentionSeconds) {
            $stream = $this->lockStream($run, $namespace, $streamName, openIfMissing: false);

            if ($stream === null) {
                throw new StreamNotFoundException($run, $streamName);
            }

            if ($stream->isTerminal()) {
                return $stream;
            }

            $stream->status = $errorReason === null
                ? WorkflowDurableStream::STATUS_CLOSED
                : WorkflowDurableStream::STATUS_ERRORED;
            $stream->error_reason = $errorReason;
            $stream->closed_at = Carbon::now();

            if ($retentionSeconds !== null && $retentionSeconds > 0) {
                $stream->retention_seconds = $retentionSeconds;
            } elseif ($stream->retention_seconds === null) {
                $stream->retention_seconds = self::DEFAULT_CLOSED_RETENTION_SECONDS;
            }

            $stream->save();

            return $stream->refresh();
        });
    }

    /**
     * @return Collection<int, WorkflowDurableStream>
     */
    public function listForRun(string $namespace, string $workflowRunId): Collection
    {
        return WorkflowDurableStream::query()
            ->where('namespace', $namespace)
            ->where('workflow_run_id', $workflowRunId)
            ->orderBy('stream_name')
            ->get();
    }

    public function describe(
        WorkflowRun $run,
        string $namespace,
        string $streamName,
    ): WorkflowDurableStream {
        $this->assertValidStreamName($streamName);

        $stream = $this->locateStream($run, $namespace, $streamName);

        if ($stream === null) {
            throw new StreamNotFoundException($run, $streamName);
        }

        return $stream;
    }

    private function locateStream(
        WorkflowRun $run,
        string $namespace,
        string $streamName,
    ): ?WorkflowDurableStream {
        return WorkflowDurableStream::query()
            ->where('namespace', $namespace)
            ->where('workflow_run_id', $run->id)
            ->where('stream_name', $streamName)
            ->first();
    }

    private function lockStream(
        WorkflowRun $run,
        string $namespace,
        string $streamName,
        bool $openIfMissing,
    ): ?WorkflowDurableStream {
        $stream = WorkflowDurableStream::query()
            ->where('namespace', $namespace)
            ->where('workflow_run_id', $run->id)
            ->where('stream_name', $streamName)
            ->lockForUpdate()
            ->first();

        if ($stream !== null) {
            return $stream;
        }

        if (! $openIfMissing) {
            return null;
        }

        return WorkflowDurableStream::query()->create([
            'namespace' => $namespace,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'stream_name' => $streamName,
            'status' => WorkflowDurableStream::STATUS_OPEN,
            'last_offset' => -1,
            'total_items' => 0,
            'pending_items' => 0,
            'opened_at' => Carbon::now(),
        ]);
    }

    private function assertValidStreamName(string $streamName): void
    {
        if (! preg_match(self::STREAM_NAME_PATTERN, $streamName)) {
            throw new InvalidArgumentException('invalid stream name');
        }
    }
}
