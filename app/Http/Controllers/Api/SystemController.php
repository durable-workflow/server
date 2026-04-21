<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceWorkflowScope;
use App\Support\ProjectionDriftMetrics;
use App\Support\WorkflowTaskFailureMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\TaskWatchdog;

class SystemController
{
    public function repairPass(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'run_ids' => ['nullable', 'array', 'max:100'],
            'run_ids.*' => ['string', 'min:1', 'max:128'],
            'instance_id' => ['nullable', 'string', 'min:1', 'max:128'],
        ]);

        $runIds = array_values(array_map(
            static fn (string $v): string => trim($v),
            $validated['run_ids'] ?? [],
        ));

        $instanceId = isset($validated['instance_id']) && is_string($validated['instance_id'])
            ? trim($validated['instance_id'])
            : null;

        $report = TaskWatchdog::runPass(
            runIds: $runIds,
            instanceId: $instanceId,
        );
        $report = array_replace([
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'command_contract_failures' => [],
            'existing_task_failures' => [],
            'missing_run_failures' => [],
        ], $report);

        $hasFailures = $report['existing_task_failures'] !== []
            || $report['missing_run_failures'] !== []
            || $report['command_contract_failures'] !== [];

        return ControlPlaneProtocol::json($report, $hasFailures ? 207 : 200);
    }

    public function repairStatus(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        return ControlPlaneProtocol::json([
            'policy' => TaskRepairPolicy::snapshot(),
            'candidates' => TaskRepairCandidates::snapshot(),
        ]);
    }

    public function metrics(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $workflowTaskFailures = WorkflowTaskFailureMetrics::snapshot($namespace);
        $projectionDrift = ProjectionDriftMetrics::snapshot();

        return ControlPlaneProtocol::json([
            'generated_at' => now()->toJSON(),
            'namespace' => $namespace,
            'metrics' => [
                WorkflowTaskFailureMetrics::METRIC_NAME => $workflowTaskFailures,
                ProjectionDriftMetrics::METRIC_NAME => $projectionDrift,
            ],
            'cardinality' => [
                'metric_label_sets' => [
                    WorkflowTaskFailureMetrics::METRIC_NAME => $workflowTaskFailures['label_cardinality_policy'],
                    ProjectionDriftMetrics::METRIC_NAME => $projectionDrift['label_cardinality_policy'],
                ],
            ],
        ]);
    }

    public function activityTimeoutStatus(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $limit = min(100, (int) ($validated['limit'] ?? 100));
        $expiredIds = ActivityTimeoutEnforcer::expiredExecutionIds($limit);

        return ControlPlaneProtocol::json([
            'expired_count' => count($expiredIds),
            'expired_execution_ids' => $expiredIds,
            'scan_limit' => $limit,
            'scan_pressure' => count($expiredIds) >= $limit,
        ]);
    }

    public function activityTimeoutEnforcePass(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1'],
            'execution_ids' => ['nullable', 'array', 'max:100'],
            'execution_ids.*' => ['string', 'min:1', 'max:128'],
        ]);

        $limit = min(100, (int) ($validated['limit'] ?? 100));

        $executionIds = array_values(array_map(
            static fn (string $v): string => trim($v),
            $validated['execution_ids'] ?? [],
        ));

        if ($executionIds === []) {
            $executionIds = ActivityTimeoutEnforcer::expiredExecutionIds($limit);
        }

        if ($executionIds === []) {
            return ControlPlaneProtocol::json([
                'processed' => 0,
                'enforced' => 0,
                'skipped' => 0,
                'failed' => 0,
                'results' => [],
            ]);
        }

        $results = [];
        $enforced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($executionIds as $executionId) {
            $result = ActivityTimeoutEnforcer::enforce($executionId);

            if ($result['enforced']) {
                $enforced++;
                $results[] = [
                    'execution_id' => $executionId,
                    'outcome' => 'enforced',
                    'has_retry' => $result['next_task'] !== null,
                ];
            } elseif ($result['reason'] !== null && str_contains($result['reason'], 'Exception')) {
                $failed++;
                $results[] = [
                    'execution_id' => $executionId,
                    'outcome' => 'error',
                    'reason' => $result['reason'],
                ];
            } else {
                $skipped++;
                $results[] = [
                    'execution_id' => $executionId,
                    'outcome' => 'skipped',
                    'reason' => $result['reason'],
                ];
            }
        }

        $hasFailures = $failed > 0;

        return ControlPlaneProtocol::json([
            'processed' => count($executionIds),
            'enforced' => $enforced,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
        ], $hasFailures ? 207 : 200);
    }

    public function retentionStatus(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);
        $limit = min(100, (int) ($validated['limit'] ?? 100));

        $ns = WorkflowNamespace::query()->where('name', $namespace)->first();
        $retentionDays = $ns?->retention_days ?? (int) config('server.history.retention_days', 30);
        $cutoff = now()->subDays($retentionDays);

        $expiredRunIds = NamespaceWorkflowScope::runSummaryQuery($namespace)
            ->whereIn('workflow_run_summaries.status_bucket', ['completed', 'failed'])
            ->whereNotNull('workflow_run_summaries.closed_at')
            ->whereNull('workflow_run_summaries.archived_at')
            ->where('workflow_run_summaries.closed_at', '<', $cutoff)
            ->orderBy('workflow_run_summaries.closed_at')
            ->limit($limit)
            ->pluck('workflow_run_summaries.id')
            ->all();

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toIso8601String(),
            'expired_run_count' => count($expiredRunIds),
            'expired_run_ids' => $expiredRunIds,
            'scan_limit' => $limit,
            'scan_pressure' => count($expiredRunIds) >= $limit,
        ]);
    }

    public function retentionEnforcePass(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1'],
            'run_ids' => ['nullable', 'array', 'max:100'],
            'run_ids.*' => ['string', 'min:1', 'max:128'],
        ]);
        $limit = min(100, (int) ($validated['limit'] ?? 100));

        $runIds = array_values(array_map(
            static fn (string $v): string => trim($v),
            $validated['run_ids'] ?? [],
        ));

        if ($runIds === []) {
            $ns = WorkflowNamespace::query()->where('name', $namespace)->first();
            $retentionDays = $ns?->retention_days ?? (int) config('server.history.retention_days', 30);
            $cutoff = now()->subDays($retentionDays);

            $runIds = NamespaceWorkflowScope::runSummaryQuery($namespace)
                ->whereIn('workflow_run_summaries.status_bucket', ['completed', 'failed'])
                ->whereNotNull('workflow_run_summaries.closed_at')
                ->whereNull('workflow_run_summaries.archived_at')
                ->where('workflow_run_summaries.closed_at', '<', $cutoff)
                ->orderBy('workflow_run_summaries.closed_at')
                ->limit($limit)
                ->pluck('workflow_run_summaries.id')
                ->all();
        }

        if ($runIds === []) {
            return ControlPlaneProtocol::json([
                'processed' => 0,
                'pruned' => 0,
                'skipped' => 0,
                'failed' => 0,
                'results' => [],
            ]);
        }

        $results = [];
        $pruned = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($runIds as $runId) {
            try {
                $result = $this->pruneRun($namespace, $runId);

                if ($result['pruned']) {
                    $pruned++;
                    $results[] = [
                        'run_id' => $runId,
                        'outcome' => 'pruned',
                        'history_events_deleted' => $result['history_events_deleted'],
                        'tasks_deleted' => $result['tasks_deleted'],
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

        $hasFailures = $failed > 0;

        return ControlPlaneProtocol::json([
            'processed' => count($runIds),
            'pruned' => $pruned,
            'skipped' => $skipped,
            'failed' => $failed,
            'results' => $results,
        ], $hasFailures ? 207 : 200);
    }

    /**
     * @return array{pruned: bool, reason: string|null, history_events_deleted: int, tasks_deleted: int}
     */
    private function pruneRun(string $namespace, string $runId): array
    {
        $summary = WorkflowRunSummary::query()
            ->where('id', $runId)
            ->where('namespace', $namespace)
            ->first();

        if (! $summary) {
            return ['pruned' => false, 'reason' => 'run_not_found', 'history_events_deleted' => 0, 'tasks_deleted' => 0];
        }

        $status = is_string($summary->status) ? RunStatus::tryFrom($summary->status) : null;

        if ($status === null || ! $status->isTerminal()) {
            return ['pruned' => false, 'reason' => 'run_not_terminal', 'history_events_deleted' => 0, 'tasks_deleted' => 0];
        }

        if ($summary->archived_at !== null) {
            return ['pruned' => false, 'reason' => 'run_archived', 'history_events_deleted' => 0, 'tasks_deleted' => 0];
        }

        // Audit log before deletion
        Log::info('retention_prune_run', [
            'namespace' => $namespace,
            'run_id' => $runId,
            'workflow_instance_id' => $summary->workflow_instance_id,
            'workflow_type' => $summary->workflow_type,
            'status' => $summary->status,
            'closed_at' => $summary->closed_at?->toIso8601String(),
        ]);

        // Delete related records first
        ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        WorkflowCommand::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        WorkflowFailure::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        WorkflowTimelineEntry::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        WorkflowRunWait::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        WorkflowRunLineageEntry::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        WorkflowUpdate::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        $historyDeleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        $tasksDeleted = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->delete();

        WorkflowRunSummary::query()
            ->where('id', $runId)
            ->delete();

        return [
            'pruned' => true,
            'reason' => null,
            'history_events_deleted' => $historyDeleted,
            'tasks_deleted' => $tasksDeleted,
        ];
    }
}
