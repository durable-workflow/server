<?php

namespace App\Support;

use App\Models\WorkflowNamespace;
use Illuminate\Support\Facades\Cache;
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
        $retentionDays = self::retentionDays($namespace);
        $cutoff = now()->subDays($retentionDays);

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

    public static function retentionDays(string $namespace): int
    {
        $ns = WorkflowNamespace::query()->where('name', $namespace)->first();

        return $ns?->retention_days ?? (int) config('server.history.retention_days', 30);
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
     * @return array{pruned: bool, reason: string|null, history_events_deleted: int, tasks_deleted: int, deleted: array<string, int>}
     */
    public static function pruneRun(string $namespace, string $runId): array
    {
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

        Log::info('retention_prune_run', [
            'namespace' => $namespace,
            'run_id' => $runId,
            'workflow_instance_id' => $summary->workflow_instance_id,
            'workflow_type' => $summary->workflow_type,
            'status' => $summary->status,
            'closed_at' => $summary->closed_at?->toIso8601String(),
        ]);

        $report = WorkflowRunRetentionCleanup::pruneRun($runId);

        return [
            'pruned' => true,
            'reason' => null,
            'history_events_deleted' => $report['history_events_deleted'] ?? 0,
            'tasks_deleted' => $report['tasks_deleted'] ?? 0,
            'deleted' => $report,
        ];
    }

    /**
     * @return array{pruned: false, reason: string, history_events_deleted: 0, tasks_deleted: 0, deleted: array<string, int>}
     */
    private static function skippedRetentionResult(string $reason): array
    {
        return [
            'pruned' => false,
            'reason' => $reason,
            'history_events_deleted' => 0,
            'tasks_deleted' => 0,
            'deleted' => [],
        ];
    }
}
