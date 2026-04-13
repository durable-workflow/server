<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
