<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\TaskWatchdog;

class SystemController
{
    public function repairPass(Request $request): JsonResponse
    {
        $runIds = is_array($request->input('run_ids'))
            ? array_values(array_filter(array_map(
                static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
                $request->input('run_ids'),
            )))
            : [];

        $instanceId = is_string($request->input('instance_id')) && trim($request->input('instance_id')) !== ''
            ? trim($request->input('instance_id'))
            : null;

        $report = TaskWatchdog::runPass(
            runIds: $runIds,
            instanceId: $instanceId,
        );

        $hasFailures = $report['existing_task_failures'] !== []
            || $report['missing_run_failures'] !== []
            || $report['command_contract_failures'] !== [];

        return ControlPlaneProtocol::json($report, $hasFailures ? 207 : 200);
    }

    public function repairStatus(): JsonResponse
    {
        return ControlPlaneProtocol::json([
            'policy' => TaskRepairPolicy::snapshot(),
            'candidates' => TaskRepairCandidates::snapshot(),
        ]);
    }

    public function activityTimeoutStatus(): JsonResponse
    {
        $limit = 100;
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
        $limit = min(100, max(1, (int) ($request->input('limit') ?? 100)));

        $executionIds = is_array($request->input('execution_ids'))
            ? array_values(array_filter(array_map(
                static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
                $request->input('execution_ids'),
            )))
            : [];

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
}
