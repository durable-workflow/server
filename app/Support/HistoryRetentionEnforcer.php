<?php

namespace App\Support;

use App\Models\WorkflowNamespace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;

class HistoryRetentionEnforcer
{
    private const INLINE_CACHE_PREFIX = 'server:history-retention-inline:';

    private const INLINE_THROTTLE_SECONDS = 60;

    private const INLINE_LIMIT = 1;

    /**
     * @return list<string>
     */
    public static function expiredRunIds(string $namespace, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $policy = self::retentionPolicy($namespace);

        if ($policy['retention_mode'] === WorkflowNamespace::RETENTION_MODE_FOREVER) {
            return [];
        }

        $cutoff = $policy['cutoff'];

        return NamespaceWorkflowScope::runSummaryQuery($namespace)
            ->whereIn('workflow_run_summaries.status_bucket', ['completed', 'failed'])
            ->whereNotNull('workflow_run_summaries.closed_at')
            ->whereNull('workflow_run_summaries.archived_at')
            ->where('workflow_run_summaries.closed_at', '<', $cutoff)
            ->orderBy('workflow_run_summaries.closed_at')
            ->limit($limit)
            ->pluck('workflow_run_summaries.id')
            ->all();
    }

    /**
     * @return array{retention_mode: string, retention_days: int|null, cutoff: Carbon|null}
     */
    public static function retentionPolicy(string $namespace): array
    {
        $ns = WorkflowNamespace::query()->where('name', $namespace)->first();
        $mode = $ns?->retention_mode ?? WorkflowNamespace::RETENTION_MODE_BOUNDED;
        $days = $mode === WorkflowNamespace::RETENTION_MODE_FOREVER
            ? null
            : ($ns?->retention_days ?? (int) config('server.history.retention_days', 30));

        return [
            'retention_mode' => $mode,
            'retention_days' => $days,
            'cutoff' => $days === null ? null : now()->subDays($days),
        ];
    }

    public static function retentionMode(string $namespace): string
    {
        return self::retentionPolicy($namespace)['retention_mode'];
    }

    public static function retentionDays(string $namespace): ?int
    {
        return self::retentionPolicy($namespace)['retention_days'];
    }

    /**
     * @param  list<string>  $runIds
     * @return array{processed: int, pruned: int, skipped: int, failed: int, results: list<array<string, mixed>>}
     */
    public static function runPass(string $namespace, int $limit = 100, array $runIds = []): array
    {
        $runIds = $runIds === []
            ? self::expiredRunIds($namespace, $limit)
            : array_values($runIds);

        $results = [];
        $pruned = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($runIds as $runId) {
            try {
                $result = self::pruneRun($namespace, $runId);

                if ($result['pruned']) {
                    $pruned++;
                    $results[] = [
                        'run_id' => $runId,
                        'outcome' => 'pruned',
                        'history_events_deleted' => $result['history_events_deleted'],
                        'tasks_deleted' => $result['tasks_deleted'],
                        'external_payloads_deleted' => $result['external_payloads_deleted'],
                        'deleted' => $result['deleted'],
                    ];
                } else {
                    $skipped++;
                    $results[] = [
                        'run_id' => $runId,
                        'outcome' => 'skipped',
                        'reason' => $result['reason'],
                    ];
                }
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'run_id' => $runId,
                    'outcome' => 'error',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'processed' => count($runIds),
            'pruned' => $pruned,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Run a tiny retention pass from ordinary worker traffic.
     *
     * This is intentionally bounded and throttled. The explicit API/console
     * pass remains available for operators, but active deployments no longer
     * depend on a separate scheduler for all retention progress.
     *
     * @return array{throttled: bool, processed: int, pruned: int, skipped: int, failed: int}
     */
    public static function runInlinePass(string $namespace): array
    {
        if (self::retentionMode($namespace) === WorkflowNamespace::RETENTION_MODE_FOREVER) {
            return [
                'throttled' => false,
                'processed' => 0,
                'pruned' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }

        $key = self::INLINE_CACHE_PREFIX.sha1($namespace);

        if (! Cache::add($key, '1', now()->addSeconds(self::INLINE_THROTTLE_SECONDS))) {
            return [
                'throttled' => true,
                'processed' => 0,
                'pruned' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }

        $report = self::runPass($namespace, self::INLINE_LIMIT);

        return [
            'throttled' => false,
            'processed' => $report['processed'],
            'pruned' => $report['pruned'],
            'skipped' => $report['skipped'],
            'failed' => $report['failed'],
        ];
    }

    /**
     * @return array{pruned: bool, reason: string|null, history_events_deleted: int, tasks_deleted: int, external_payloads_deleted: int, deleted: array<string, int>}
     */
    public static function pruneRun(string $namespace, string $runId): array
    {
        if (self::retentionMode($namespace) === WorkflowNamespace::RETENTION_MODE_FOREVER) {
            return self::skippedRetentionResult('namespace_retention_forever');
        }

        $summary = WorkflowRunSummary::query()
            ->where('id', $runId)
            ->where('namespace', $namespace)
            ->first();

        if (! $summary) {
            return self::skippedRetentionResult('run_not_found');
        }

        $status = is_string($summary->status) ? RunStatus::tryFrom($summary->status) : null;

        if ($status === null || ! $status->isTerminal()) {
            return self::skippedRetentionResult('run_not_terminal');
        }

        if ($summary->archived_at !== null) {
            return self::skippedRetentionResult('run_archived');
        }

        $messageStreams = app(MessageStreamRetentionCleanup::class);
        $streamPlan = $messageStreams->planForRun(
            $namespace,
            $runId,
            (string) $summary->workflow_instance_id,
        );

        try {
            $externalPayloads = app(ExternalPayloadRetentionCleanup::class)->deleteForRun(
                $namespace,
                $runId,
                $streamPlan['releasable_item_ids'],
            );
        } catch (\Throwable $exception) {
            $messageStreams->markBlocked($streamPlan, 'external_payload_cleanup_failed');

            throw $exception;
        }

        if ($externalPayloads['blocked']) {
            $reason = $externalPayloads['reason'] ?? 'external_payload_cleanup_blocked';
            $messageStreams->markBlocked($streamPlan, $reason);

            return self::skippedRetentionResult($reason);
        }

        Log::info('retention_prune_run', [
            'namespace' => $namespace,
            'run_id' => $runId,
            'workflow_instance_id' => $summary->workflow_instance_id,
            'workflow_type' => $summary->workflow_type,
            'status' => $summary->status,
            'closed_at' => $summary->closed_at?->toIso8601String(),
        ]);

        $report = DB::transaction(static function () use ($runId, $messageStreams, $streamPlan): array {
            return array_merge(
                WorkflowRunRetentionCleanup::pruneRun($runId),
                $messageStreams->apply($streamPlan),
            );
        });

        return [
            'pruned' => true,
            'reason' => null,
            'history_events_deleted' => $report['history_events_deleted'] ?? 0,
            'tasks_deleted' => $report['tasks_deleted'] ?? 0,
            'external_payloads_deleted' => $externalPayloads['deleted'],
            'deleted' => $report,
        ];
    }

    /**
     * @return array{pruned: false, reason: string, history_events_deleted: 0, tasks_deleted: 0, external_payloads_deleted: 0, deleted: array<string, int>}
     */
    private static function skippedRetentionResult(string $reason): array
    {
        return [
            'pruned' => false,
            'reason' => $reason,
            'history_events_deleted' => 0,
            'tasks_deleted' => 0,
            'external_payloads_deleted' => 0,
            'deleted' => [],
        ];
    }
}
