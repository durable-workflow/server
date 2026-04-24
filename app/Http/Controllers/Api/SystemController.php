<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\HistoryRetentionEnforcer;
use App\Support\ProjectionDriftMetrics;
use App\Support\WorkflowTaskFailureMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\OperatorMetrics;
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

    public function operatorMetrics(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $snapshot = OperatorMetrics::snapshot(null, $namespace);

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'operator_metrics' => $snapshot,
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

        $retentionDays = HistoryRetentionEnforcer::retentionDays($namespace);
        $cutoff = now()->subDays($retentionDays);
        $expiredRunIds = HistoryRetentionEnforcer::expiredRunIds($namespace, $limit);

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

        $report = HistoryRetentionEnforcer::runPass($namespace, $limit, $runIds);

        $hasFailures = $report['failed'] > 0;

        return ControlPlaneProtocol::json($report, $hasFailures ? 207 : 200);
    }
}
