<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\TaskQueueAdmission;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\StandaloneWorkerVisibility;

class TaskQueueController
{
    public function __construct(
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly TaskQueueAdmission $admission,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $snapshot = StandaloneWorkerVisibility::queueSnapshot(
            $namespace,
            WorkerRegistration::class,
            now(),
            $this->workerStaleAfterSeconds(),
        );

        $payload = [
            'namespace' => $snapshot->namespace,
            'task_queues' => array_map(function ($detail) use ($namespace): array {
                $summary = $detail->toSummaryArray();
                $summary['pollers'] = $detail->pollers();
                $summary = $this->withAdmission($namespace, $summary);
                unset($summary['pollers']);

                return $summary;
            }, $snapshot->taskQueues()),
        ];

        return ControlPlaneProtocol::json($payload);
    }

    public function show(Request $request, string $taskQueue): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json(
            $this->withAdmission($namespace, StandaloneWorkerVisibility::queueDetail(
                $namespace,
                $taskQueue,
                WorkerRegistration::class,
                now(),
                $this->workerStaleAfterSeconds(),
            )->toArray()),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withAdmission(string $namespace, array $payload): array
    {
        $taskQueue = is_string($payload['name'] ?? null) && trim($payload['name']) !== ''
            ? trim($payload['name'])
            : 'default';
        $pollers = $payload['pollers'] ?? [];
        $stats = $payload['stats'] ?? [];

        $payload['admission'] = [
            'workflow_tasks' => $this->taskAdmission(
                $namespace,
                $taskQueue,
                TaskQueueAdmission::WORKFLOW_TASKS,
                'worker_registration.max_concurrent_workflow_tasks',
                is_array($pollers) ? $pollers : [],
                'max_concurrent_workflow_tasks',
                (int) data_get($stats, 'workflow_tasks.leased_count', 0),
                (int) data_get($stats, 'workflow_tasks.ready_count', 0),
            ),
            'activity_tasks' => $this->taskAdmission(
                $namespace,
                $taskQueue,
                TaskQueueAdmission::ACTIVITY_TASKS,
                'worker_registration.max_concurrent_activity_tasks',
                is_array($pollers) ? $pollers : [],
                'max_concurrent_activity_tasks',
                (int) data_get($stats, 'activity_tasks.leased_count', 0),
                (int) data_get($stats, 'activity_tasks.ready_count', 0),
            ),
            'query_tasks' => $this->queryTasks->queueAdmission($namespace, $taskQueue),
        ];

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $pollers
     * @return array{
     *     budget_source: string,
     *     server_budget_source: string,
     *     active_worker_count: int,
     *     configured_slot_count: int,
     *     leased_count: int,
     *     ready_count: int,
     *     available_slot_count: int,
     *     server_max_active_leases_per_queue: int|null,
     *     server_active_lease_count: int,
     *     server_remaining_active_lease_capacity: int|null,
     *     server_max_dispatches_per_minute: int|null,
     *     server_dispatch_count_this_minute: int,
     *     server_remaining_dispatch_capacity: int|null,
     *     server_lock_required: bool,
     *     server_lock_supported: bool,
     *     status: string
     * }
     */
    private function taskAdmission(
        string $namespace,
        string $taskQueue,
        string $taskKind,
        string $budgetSource,
        array $pollers,
        string $slotField,
        int $leasedCount,
        int $readyCount,
    ): array {
        $activePollers = array_values(array_filter(
            $pollers,
            static fn (array $poller): bool => ($poller['is_stale'] ?? false) !== true
                && (($poller['status'] ?? 'active') === 'active'),
        ));
        $slotCounts = array_map(
            static fn (array $poller): int => max(0, (int) ($poller[$slotField] ?? 0)),
            $activePollers,
        );
        $configuredSlots = array_sum($slotCounts);
        $activeWorkerCount = count($activePollers);
        $serverBudget = $this->admission->budget($namespace, $taskQueue, $taskKind);

        return [
            'budget_source' => $budgetSource,
            'server_budget_source' => $serverBudget['budget_source'],
            'active_worker_count' => $activeWorkerCount,
            'configured_slot_count' => $configuredSlots,
            'leased_count' => max(0, $leasedCount),
            'ready_count' => max(0, $readyCount),
            'available_slot_count' => max(0, $configuredSlots - max(0, $leasedCount)),
            'server_max_active_leases_per_queue' => $serverBudget['max_active_leases_per_queue'],
            'server_active_lease_count' => $serverBudget['active_lease_count'],
            'server_remaining_active_lease_capacity' => $serverBudget['remaining_active_lease_capacity'],
            'server_max_dispatches_per_minute' => $serverBudget['max_dispatches_per_minute'],
            'server_dispatch_count_this_minute' => $serverBudget['dispatch_count_this_minute'],
            'server_remaining_dispatch_capacity' => $serverBudget['remaining_dispatch_capacity'],
            'server_lock_required' => $serverBudget['lock_required'],
            'server_lock_supported' => $serverBudget['lock_supported'],
            'status' => $this->taskAdmissionStatus($activeWorkerCount, $configuredSlots, $leasedCount, $serverBudget),
        ];
    }

    /**
     * @param  array<string, mixed>  $serverBudget
     */
    private function taskAdmissionStatus(int $activeWorkerCount, int $configuredSlots, int $leasedCount, array $serverBudget): string
    {
        if (($serverBudget['status'] ?? null) === 'unavailable') {
            return 'unavailable';
        }

        if (($serverBudget['status'] ?? null) === 'throttled') {
            return 'throttled';
        }

        if ($activeWorkerCount === 0) {
            return 'no_active_workers';
        }

        if ($configuredSlots <= 0) {
            return 'no_slots';
        }

        return $leasedCount >= $configuredSlots ? 'saturated' : 'accepting';
    }

    private function workerStaleAfterSeconds(): int
    {
        $configured = config('server.workers.stale_after_seconds');
        $pollingTimeout = config('server.polling.timeout');

        return StandaloneWorkerVisibility::staleAfterSeconds(
            is_numeric($configured) ? (int) $configured : null,
            is_numeric($pollingTimeout) ? (int) $pollingTimeout : null,
        );
    }
}
