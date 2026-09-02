<?php

namespace App\Http\Controllers\Api;

use App\Services\PrometheusMetricsSummary;
use App\Support\ActivityTimeoutGuard;
use App\Support\ActivityTimeoutScanner;
use App\Support\ControlPlaneProtocol;
use App\Support\HistoryRetentionEnforcer;
use App\Support\NamespaceCapacityEvidence;
use App\Support\NamespaceDurableStateQuota;
use App\Support\NamespaceRequestAdmission;
use App\Support\ProjectionDriftMetrics;
use App\Support\RuntimeExternalPayloadCleanup;
use App\Support\RuntimeExternalPayloadCleanupMetrics;
use App\Support\RuntimeExternalPayloadQuota;
use App\Support\TaskQueueBuildIdRolloutSnapshot;
use App\Support\WorkerSessionRegistry;
use App\Support\WorkflowTaskFailureMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\OperatorDashboardSummary;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;

class SystemController
{
    public function __construct(
        private readonly TaskQueueBuildIdRolloutSnapshot $buildIdRollouts,
        private readonly MatchingRole $matchingRole,
        private readonly NamespaceCapacityEvidence $capacityEvidence,
        private readonly NamespaceRequestAdmission $namespaceRequestAdmission,
        private readonly NamespaceDurableStateQuota $namespaceDurableStateQuota,
        private readonly RuntimeExternalPayloadQuota $externalPayloadQuota,
        private readonly WorkerSessionRegistry $workerSessions,
    ) {}

    public function repairPass(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'run_ids' => ['nullable', 'array', 'max:100'],
            'run_ids.*' => ['string', 'min:1', 'max:128'],
            'instance_id' => ['nullable', 'string', 'min:1', 'max:128'],
            'connection' => ['nullable', 'string', 'max:128'],
            'queue' => ['nullable', 'string', 'max:128'],
            'respect_throttle' => ['nullable', 'boolean'],
        ]);

        $runIds = array_values(array_map(
            static fn (string $v): string => trim($v),
            $validated['run_ids'] ?? [],
        ));

        $instanceId = $this->trimmedString($validated['instance_id'] ?? null);
        $connection = $this->trimmedString($validated['connection'] ?? null);
        $queue = $this->trimmedString($validated['queue'] ?? null);
        $respectThrottle = (bool) ($validated['respect_throttle'] ?? false);

        $report = $this->matchingRole->runPass(
            connection: $connection,
            queue: $queue,
            respectThrottle: $respectThrottle,
            runIds: $runIds,
            instanceId: $instanceId,
        );
        $report = array_replace([
            'connection' => $connection,
            'queue' => $queue,
            'run_ids' => $runIds,
            'instance_id' => $instanceId,
            'respect_throttle' => $respectThrottle,
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
        $requestAdmission = $this->namespaceRequestAdmission->metrics($namespace);
        $durableStateQuota = $this->namespaceDurableStateQuota->metrics($namespace);
        $externalPayloadQuota = $this->externalPayloadQuota->metrics($namespace);

        return ControlPlaneProtocol::json([
            'generated_at' => now()->toJSON(),
            'namespace' => $namespace,
            'metrics' => [
                WorkflowTaskFailureMetrics::METRIC_NAME => $workflowTaskFailures,
                ProjectionDriftMetrics::METRIC_NAME => $projectionDrift,
                NamespaceRequestAdmission::METRIC_NAME => $requestAdmission,
                NamespaceDurableStateQuota::METRIC_NAME => $durableStateQuota,
                RuntimeExternalPayloadQuota::METRIC_NAME => $externalPayloadQuota,
            ],
            'cardinality' => [
                'metric_label_sets' => [
                    WorkflowTaskFailureMetrics::METRIC_NAME => $workflowTaskFailures['label_cardinality_policy'],
                    ProjectionDriftMetrics::METRIC_NAME => $projectionDrift['label_cardinality_policy'],
                    NamespaceRequestAdmission::METRIC_NAME => $requestAdmission['label_cardinality_policy'],
                    NamespaceDurableStateQuota::METRIC_NAME => $durableStateQuota['label_cardinality_policy'],
                    RuntimeExternalPayloadQuota::METRIC_NAME => $externalPayloadQuota['label_cardinality_policy'],
                ],
            ],
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $snapshot = HealthCheck::snapshot(null, $namespace);
        $snapshot['routing_drains'] = $this->buildIdRollouts->routingDrains($namespace);
        $retention = HistoryRetentionEnforcer::retentionPolicy($namespace);

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'retention_mode' => $retention['retention_mode'],
            'retention_days' => $retention['retention_days'],
            'health' => $snapshot,
        ], HealthCheck::httpStatus($snapshot));
    }

    public function operatorMetrics(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $snapshot = OperatorMetrics::snapshot(null, $namespace);
        $snapshot['worker_sessions'] = $this->workerSessions->metrics($namespace);
        $snapshot['capacity_evidence'] = $this->capacityEvidence->snapshot($namespace);
        $snapshot['runtime_external_payload_cleanup'] = RuntimeExternalPayloadCleanupMetrics::snapshot($namespace);

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'operator_metrics' => $snapshot,
        ]);
    }

    public function operatorDashboard(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $dashboard = OperatorDashboardSummary::snapshot(null, $namespace);
        $dashboard['operator_metrics']['worker_sessions'] = $this->workerSessions->metrics($namespace);
        $dashboard['operator_metrics']['runtime_external_payload_cleanup'] = RuntimeExternalPayloadCleanupMetrics::snapshot($namespace);

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'dashboard' => $dashboard,
        ]);
    }

    public function prometheusMetrics(Request $request, PrometheusMetricsSummary $summary): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json($summary->snapshot($namespace));
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
        $expiredIds = ActivityTimeoutScanner::expiredExecutionIds($limit);

        return ControlPlaneProtocol::json([
            'expired_count' => count($expiredIds),
            'expired_execution_ids' => $expiredIds,
            'scan_limit' => $limit,
            'scan_pressure' => count($expiredIds) >= $limit,
        ]);
    }

    public function externalPayloadCleanupStatus(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'cleanup' => RuntimeExternalPayloadCleanupMetrics::snapshot($namespace),
        ]);
    }

    public function externalPayloadCleanupPass(
        Request $request,
        RuntimeExternalPayloadCleanup $cleanup,
    ): JsonResponse {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.RuntimeExternalPayloadCleanup::MAX_BATCH_SIZE],
        ]);
        $namespace = (string) $request->attributes->get('namespace');
        $report = $cleanup->runPass(
            $namespace,
            (int) ($validated['limit'] ?? RuntimeExternalPayloadCleanup::DEFAULT_BATCH_SIZE),
        );

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'cleanup' => $report,
        ], $report['blocked'] > 0 ? 207 : 200);
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
            $executionIds = ActivityTimeoutScanner::expiredExecutionIds($limit);
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
            $result = ActivityTimeoutGuard::enforce($executionId);

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

        $retention = HistoryRetentionEnforcer::retentionPolicy($namespace);
        $expiredRunIds = HistoryRetentionEnforcer::expiredRunIds($namespace, $limit);

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'retention_mode' => $retention['retention_mode'],
            'retention_days' => $retention['retention_days'],
            'cutoff' => $retention['cutoff']?->toIso8601String(),
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
        $retention = HistoryRetentionEnforcer::retentionPolicy($namespace);

        $hasFailures = $report['failed'] > 0;

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'retention_mode' => $retention['retention_mode'],
            'retention_days' => $retention['retention_days'],
            'cutoff' => $retention['cutoff']?->toIso8601String(),
        ] + $report, $hasFailures ? 207 : 200);
    }

    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
