<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\TaskCompatibility;
use Workflow\V2\Support\TaskRepairPolicy;
use Workflow\V2\Support\WorkerCompatibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class WorkflowRunDiagnostics
{
    private const TASK_LIMIT = 25;

    private const FAILURE_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    public function forRun(string $namespace, WorkflowRun $run): array
    {
        $summary = $this->summary($run);
        $taskRows = collect($this->pendingWorkflowTaskRows($run, $summary));
        $pendingWorkflowTasks = $taskRows
            ->take(self::TASK_LIMIT)
            ->map(fn (array $task): array => $this->task($task))
            ->values()
            ->all();
        $pendingActivities = $this->pendingActivities($run);
        $taskQueue = is_string($run->queue) && $run->queue !== ''
            ? $this->taskQueue($namespace, $run->queue)
            : null;
        $lastEvent = $this->lastEvent($run);
        $nextScheduledEvent = $this->nextScheduledEvent($summary, $taskRows->all());
        $recentFailures = $this->recentFailures($run);

        $payload = [
            'generated_at' => now()->toJSON(),
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'namespace' => $namespace,
            'diagnostic_status' => $this->diagnosticStatus($run, $summary, $pendingWorkflowTasks, $pendingActivities, $taskQueue),
            'execution' => $this->execution($run, $summary, $lastEvent, $nextScheduledEvent),
            'pending_workflow_tasks' => $pendingWorkflowTasks,
            'pending_activities' => $pendingActivities,
            'task_queue' => $taskQueue,
            'recent_failures' => $recentFailures,
            'compatibility' => $this->compatibility($namespace, $run, $summary, $taskQueue),
        ];

        $payload['findings'] = $this->findings($payload);

        return $payload;
    }

    private function summary(WorkflowRun $run): ?WorkflowRunSummary
    {
        if ($run->relationLoaded('summary') && $run->summary instanceof WorkflowRunSummary) {
            return $run->summary;
        }

        return WorkflowRunSummary::query()->find($run->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingWorkflowTaskRows(WorkflowRun $run, ?WorkflowRunSummary $summary): array
    {
        $tasks = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->orderBy('available_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::TASK_LIMIT)
            ->get();

        $rows = $tasks
            ->map(fn (WorkflowTask $task): array => $this->workflowTaskRow($task, $run))
            ->values()
            ->all();

        if (count($rows) >= self::TASK_LIMIT) {
            return $rows;
        }

        $summaryRow = $this->summaryMissingWorkflowTaskRow($run, $summary, array_column($rows, 'id'));

        if ($summaryRow !== null) {
            $rows[] = $summaryRow;
        }

        return array_slice($rows, 0, self::TASK_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowTaskRow(WorkflowTask $task, WorkflowRun $run): array
    {
        $compatibility = TaskCompatibility::resolve($task, $run);

        return [
            'id' => $task->id,
            'type' => $this->enumValue($task->task_type),
            'status' => $this->enumValue($task->status),
            'transport_state' => $this->taskTransportState($task),
            'task_missing' => false,
            'synthetic' => false,
            'expected_task_id' => null,
            'summary' => $this->workflowTaskSummary($task),
            'compatibility' => $compatibility,
            'compatibility_supported' => WorkerCompatibility::supports($compatibility),
            'compatibility_reason' => WorkerCompatibility::mismatchReason($compatibility),
            'compatibility_supported_in_fleet' => TaskCompatibility::supportedInFleet($task, $run),
            'compatibility_fleet_reason' => TaskCompatibility::fleetMismatchReason($task, $run),
            'dispatch_failed' => TaskRepairPolicy::dispatchFailed($task),
            'dispatch_overdue' => TaskRepairPolicy::dispatchOverdue($task),
            'claim_failed' => TaskRepairPolicy::claimFailed($task),
            'is_open' => true,
            'available_at' => $task->available_at,
            'last_dispatch_attempt_at' => $task->last_dispatch_attempt_at,
            'leased_at' => $task->leased_at,
            'last_dispatched_at' => $task->last_dispatched_at,
            'last_dispatch_error' => $task->last_dispatch_error,
            'last_claim_failed_at' => $task->last_claim_failed_at,
            'last_claim_error' => $task->last_claim_error,
            'repair_available_at' => $task->repair_available_at,
            'repair_backoff_seconds' => TaskRepairPolicy::failureBackoffSeconds($task),
            'lease_expired' => TaskRepairPolicy::leaseExpired($task),
            'lease_owner' => $task->lease_owner,
            'lease_expires_at' => $task->lease_expires_at,
            'attempt_count' => $task->attempt_count,
            'repair_count' => $task->repair_count,
            'last_error' => $task->last_error,
            'connection' => $task->connection,
            'queue' => $task->queue,
            'workflow_wait_kind' => $this->stringValue($task->payload['workflow_wait_kind'] ?? null),
            'workflow_open_wait_id' => $this->stringValue($task->payload['open_wait_id'] ?? null),
            'workflow_resume_source_kind' => $this->stringValue($task->payload['resume_source_kind'] ?? null),
            'workflow_resume_source_id' => $this->stringValue($task->payload['resume_source_id'] ?? null),
            'replay_blocked' => ($task->payload['replay_blocked'] ?? false) === true,
            'replay_blocked_reason' => $this->stringValue($task->payload['replay_blocked_reason'] ?? null),
            'created_at' => $task->created_at,
            'updated_at' => $task->updated_at,
        ];
    }

    /**
     * @param  list<string|null>  $loadedTaskIds
     * @return array<string, mixed>|null
     */
    private function summaryMissingWorkflowTaskRow(
        WorkflowRun $run,
        ?WorkflowRunSummary $summary,
        array $loadedTaskIds,
    ): ?array {
        if (! $summary instanceof WorkflowRunSummary) {
            return null;
        }

        $taskId = $this->stringValue($summary->next_task_id);
        $taskType = $this->stringValue($summary->next_task_type);
        $taskStatus = $this->stringValue($summary->next_task_status);

        if (
            $taskId === null
            || $taskType !== TaskType::Workflow->value
            || ! in_array($taskStatus, [TaskStatus::Ready->value, TaskStatus::Leased->value], true)
            || in_array($taskId, $loadedTaskIds, true)
        ) {
            return null;
        }

        $taskExists = WorkflowTask::query()
            ->whereKey($taskId)
            ->where('workflow_run_id', $run->id)
            ->exists();

        if ($taskExists) {
            return null;
        }

        $compatibility = $this->stringValue($summary->compatibility) ?? $this->stringValue($run->compatibility);
        $queue = $this->stringValue($summary->queue) ?? $this->stringValue($run->queue);
        $connection = $this->stringValue($summary->connection) ?? $this->stringValue($run->connection);

        return [
            'id' => sprintf('missing:workflow:%s', $taskId),
            'type' => TaskType::Workflow->value,
            'status' => 'missing',
            'transport_state' => 'missing',
            'task_missing' => true,
            'synthetic' => true,
            'expected_task_id' => $taskId,
            'summary' => 'Workflow task referenced by the run summary is missing.',
            'compatibility' => $compatibility,
            'compatibility_supported' => WorkerCompatibility::supports($compatibility),
            'compatibility_reason' => WorkerCompatibility::mismatchReason($compatibility),
            'compatibility_supported_in_fleet' => WorkerCompatibilityFleet::supports($compatibility, $connection, $queue),
            'compatibility_fleet_reason' => WorkerCompatibilityFleet::mismatchReason($compatibility, $connection, $queue),
            'dispatch_failed' => false,
            'dispatch_overdue' => false,
            'claim_failed' => false,
            'is_open' => false,
            'available_at' => $summary->next_task_at,
            'lease_expires_at' => $summary->next_task_lease_expires_at,
            'lease_expired' => false,
            'lease_owner' => null,
            'attempt_count' => 0,
            'repair_count' => 0,
            'connection' => $connection,
            'queue' => $queue,
            'workflow_wait_kind' => $this->stringValue($summary->wait_kind),
            'workflow_open_wait_id' => $this->stringValue($summary->open_wait_id),
            'workflow_resume_source_kind' => $this->stringValue($summary->resume_source_kind),
            'workflow_resume_source_id' => $this->stringValue($summary->resume_source_id),
            'replay_blocked' => false,
        ];
    }

    private function taskTransportState(WorkflowTask $task): string
    {
        $status = $task->status instanceof TaskStatus
            ? $task->status
            : (is_string($task->status) ? TaskStatus::tryFrom($task->status) : null);

        if ($status === TaskStatus::Leased) {
            return TaskRepairPolicy::leaseExpired($task) ? 'lease_expired' : 'leased';
        }

        if ($status !== TaskStatus::Ready) {
            return $status?->value ?? 'unknown';
        }

        if ($task->available_at !== null && $task->available_at->isFuture()) {
            return TaskRepairPolicy::dispatchFailed($task) ? 'dispatch_failed' : 'scheduled';
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            return TaskRepairPolicy::dispatchFailedNeedsRedispatch($task) ? 'dispatch_failed' : 'repair_backoff';
        }

        if (TaskRepairPolicy::claimFailed($task)) {
            return TaskRepairPolicy::claimFailedNeedsRedispatch($task) ? 'claim_failed' : 'repair_backoff';
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            return 'dispatch_overdue';
        }

        return 'ready';
    }

    private function workflowTaskSummary(WorkflowTask $task): string
    {
        $status = $task->status instanceof TaskStatus
            ? $task->status
            : (is_string($task->status) ? TaskStatus::tryFrom($task->status) : null);

        if (($task->payload['replay_blocked'] ?? false) === true) {
            return 'Workflow replay blocked.';
        }

        if (TaskRepairPolicy::leaseExpired($task)) {
            return 'Workflow task lease expired; waiting for recovery.';
        }

        if (TaskRepairPolicy::dispatchFailed($task)) {
            return 'Workflow task dispatch failed; waiting for recovery.';
        }

        if (TaskRepairPolicy::claimFailed($task)) {
            return 'Workflow task claim failed; worker backend capability is unsupported.';
        }

        if (TaskRepairPolicy::dispatchOverdue($task)) {
            return 'Workflow task is ready but dispatch is overdue.';
        }

        return match ($status) {
            TaskStatus::Ready => match ($this->stringValue($task->payload['workflow_wait_kind'] ?? null)) {
                'update' => 'Workflow task ready to apply accepted update.',
                'signal' => $this->stringValue($task->payload['resume_source_kind'] ?? null) === 'timer'
                    ? 'Workflow task ready to apply signal timeout.'
                    : 'Workflow task ready to apply accepted signal.',
                'condition' => 'Workflow task ready to apply condition timeout.',
                default => 'Workflow task ready to resume the selected run.',
            },
            TaskStatus::Leased => match ($this->stringValue($task->payload['workflow_wait_kind'] ?? null)) {
                'update' => 'Workflow task leased to apply accepted update.',
                'signal' => $this->stringValue($task->payload['resume_source_kind'] ?? null) === 'timer'
                    ? 'Workflow task leased to apply signal timeout.'
                    : 'Workflow task leased to apply accepted signal.',
                'condition' => 'Workflow task leased to apply condition timeout.',
                default => 'Workflow task leased to a worker.',
            },
            default => 'Workflow task is not pending.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function execution(
        WorkflowRun $run,
        ?WorkflowRunSummary $summary,
        ?array $lastEvent,
        ?array $nextScheduledEvent,
    ): array {
        return $this->compact([
            'status' => $run->status?->value,
            'status_bucket' => $summary?->status_bucket,
            'is_terminal' => $run->status?->isTerminal() ?? false,
            'workflow_type' => $run->workflow_type,
            'task_queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'run_number' => (int) $run->run_number,
            'started_at' => $this->timestamp($run->started_at),
            'closed_at' => $this->timestamp($run->closed_at),
            'last_progress_at' => $this->timestamp($run->last_progress_at),
            'wait_kind' => $summary?->wait_kind,
            'wait_reason' => $summary?->wait_reason,
            'liveness_state' => $summary?->liveness_state,
            'liveness_reason' => $summary?->liveness_reason,
            'repair_attention' => $summary === null ? null : (bool) $summary->repair_attention,
            'task_problem' => $summary === null ? null : (bool) $summary->task_problem,
            'task_problem_badge' => $summary?->task_problem_badge,
            'last_event' => $lastEvent,
            'next_scheduled_event' => $nextScheduledEvent,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>|null
     */
    private function nextScheduledEvent(?WorkflowRunSummary $summary, array $tasks): ?array
    {
        if ($summary instanceof WorkflowRunSummary && $summary->next_task_id !== null) {
            return $this->compact([
                'source' => 'run_summary',
                'task_id' => $summary->next_task_id,
                'task_type' => $summary->next_task_type,
                'task_status' => $summary->next_task_status,
                'available_at' => $this->timestamp($summary->next_task_at),
                'lease_expires_at' => $this->timestamp($summary->next_task_lease_expires_at),
                'wait_deadline_at' => $this->timestamp($summary->wait_deadline_at),
            ]);
        }

        foreach ($tasks as $task) {
            $availableAt = $this->timestamp($task['available_at'] ?? null)
                ?? $this->timestamp($task['lease_expires_at'] ?? null)
                ?? $this->timestamp($task['timer_fire_at'] ?? null);

            if ($availableAt === null) {
                continue;
            }

            return $this->compact([
                'source' => 'open_task',
                'task_id' => $task['id'] ?? null,
                'task_type' => $task['type'] ?? null,
                'task_status' => $task['status'] ?? null,
                'available_at' => $availableAt,
            ]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastEvent(WorkflowRun $run): ?array
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderByDesc('sequence')
            ->first();

        if (! $event instanceof WorkflowHistoryEvent) {
            return null;
        }

        return [
            'sequence' => (int) $event->sequence,
            'event_type' => $event->event_type?->value ?? $event->event_type,
            'timestamp' => $this->timestamp($event->recorded_at),
            'payload' => $event->payload ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function task(array $task): array
    {
        return $this->compact([
            'task_id' => $task['id'] ?? null,
            'task_type' => $task['type'] ?? null,
            'status' => $task['status'] ?? null,
            'transport_state' => $task['transport_state'] ?? null,
            'summary' => $task['summary'] ?? null,
            'queue' => $task['queue'] ?? null,
            'compatibility' => $task['compatibility'] ?? null,
            'compatibility_supported' => $task['compatibility_supported'] ?? null,
            'compatibility_reason' => $task['compatibility_reason'] ?? null,
            'compatibility_supported_in_fleet' => $task['compatibility_supported_in_fleet'] ?? null,
            'compatibility_fleet_reason' => $task['compatibility_fleet_reason'] ?? null,
            'available_at' => $this->timestamp($task['available_at'] ?? null),
            'leased_at' => $this->timestamp($task['leased_at'] ?? null),
            'lease_owner' => $task['lease_owner'] ?? null,
            'lease_expires_at' => $this->timestamp($task['lease_expires_at'] ?? null),
            'lease_expired' => $task['lease_expired'] ?? null,
            'attempt_count' => $task['attempt_count'] ?? null,
            'repair_count' => $task['repair_count'] ?? null,
            'dispatch_failed' => $task['dispatch_failed'] ?? null,
            'dispatch_overdue' => $task['dispatch_overdue'] ?? null,
            'claim_failed' => $task['claim_failed'] ?? null,
            'last_error' => $task['last_error'] ?? null,
            'last_dispatch_error' => $task['last_dispatch_error'] ?? null,
            'last_claim_error' => $task['last_claim_error'] ?? null,
            'repair_available_at' => $this->timestamp($task['repair_available_at'] ?? null),
            'workflow_wait_kind' => $task['workflow_wait_kind'] ?? null,
            'workflow_open_wait_id' => $task['workflow_open_wait_id'] ?? null,
            'workflow_resume_source_kind' => $task['workflow_resume_source_kind'] ?? null,
            'workflow_resume_source_id' => $task['workflow_resume_source_id'] ?? null,
            'replay_blocked' => $task['replay_blocked'] ?? null,
            'replay_blocked_reason' => $task['replay_blocked_reason'] ?? null,
            'task_missing' => $task['task_missing'] ?? null,
            'expected_task_id' => $task['expected_task_id'] ?? null,
            'synthetic' => $task['synthetic'] ?? null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingActivities(WorkflowRun $run): array
    {
        return ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
            ->orderBy('started_at')
            ->orderBy('sequence')
            ->orderBy('id')
            ->take(self::TASK_LIMIT)
            ->get()
            ->map(function (ActivityExecution $execution): array {
                $attempt = $this->currentAttempt($execution);

                return $this->compact([
                    'activity_execution_id' => $execution->id,
                    'activity_type' => $execution->activity_type,
                    'activity_class' => $execution->activity_class,
                    'status' => $execution->status?->value,
                    'queue' => $execution->queue,
                    'attempt_count' => (int) $execution->attempt_count,
                    'current_attempt_id' => $execution->current_attempt_id,
                    'started_at' => $this->timestamp($execution->started_at),
                    'last_heartbeat_at' => $this->timestamp($execution->last_heartbeat_at),
                    'schedule_deadline_at' => $this->timestamp($execution->schedule_deadline_at),
                    'close_deadline_at' => $this->timestamp($execution->close_deadline_at),
                    'schedule_to_close_deadline_at' => $this->timestamp($execution->schedule_to_close_deadline_at),
                    'heartbeat_deadline_at' => $this->timestamp($execution->heartbeat_deadline_at),
                    'current_attempt' => $attempt,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentAttempt(ActivityExecution $execution): ?array
    {
        $attempt = null;

        if (is_string($execution->current_attempt_id) && $execution->current_attempt_id !== '') {
            /** @var ActivityAttempt|null $attempt */
            $attempt = ActivityAttempt::query()
                ->where('workflow_run_id', $execution->workflow_run_id)
                ->whereKey($execution->current_attempt_id)
                ->first();
        }

        if (! $attempt instanceof ActivityAttempt) {
            /** @var ActivityAttempt|null $attempt */
            $attempt = ActivityAttempt::query()
                ->where('workflow_run_id', $execution->workflow_run_id)
                ->where('activity_execution_id', $execution->id)
                ->orderByDesc('attempt_number')
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();
        }

        if (! $attempt instanceof ActivityAttempt) {
            return null;
        }

        $leaseExpiresAt = $this->carbon($attempt->lease_expires_at);

        return $this->compact([
            'activity_attempt_id' => $attempt->id,
            'attempt_number' => (int) $attempt->attempt_number,
            'status' => $attempt->status?->value,
            'lease_owner' => $attempt->lease_owner,
            'started_at' => $this->timestamp($attempt->started_at),
            'last_heartbeat_at' => $this->timestamp($attempt->last_heartbeat_at),
            'lease_expires_at' => $this->timestamp($attempt->lease_expires_at),
            'lease_expired' => $attempt->status === ActivityAttemptStatus::Running
                ? ($leaseExpiresAt?->lte(now()) ?? false)
                : false,
            'closed_at' => $this->timestamp($attempt->closed_at),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function taskQueue(string $namespace, string $queue): ?array
    {
        return StandaloneWorkerVisibility::queueDetail(
            $namespace,
            $queue,
            WorkerRegistration::class,
            now(),
            $this->workerStaleAfterSeconds(),
        )->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentFailures(WorkflowRun $run): array
    {
        return WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->latest('created_at')
            ->limit(self::FAILURE_LIMIT)
            ->get()
            ->map(fn (WorkflowFailure $failure): array => $this->compact([
                'failure_id' => $failure->id,
                'source_kind' => $failure->source_kind,
                'source_id' => $failure->source_id,
                'propagation_kind' => $failure->propagation_kind,
                'failure_category' => $this->enumValue($failure->failure_category),
                'exception_class' => $failure->exception_class,
                'message' => $failure->message,
                'non_retryable' => (bool) $failure->non_retryable,
                'handled' => (bool) $failure->handled,
                'created_at' => $this->timestamp($failure->created_at),
            ]))
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $taskQueue
     * @return array<string, mixed>
     */
    private function compatibility(
        string $namespace,
        WorkflowRun $run,
        ?WorkflowRunSummary $summary,
        ?array $taskQueue,
    ): array {
        return $this->compact([
            'run' => [
                'compatibility' => $run->compatibility,
                'summary_compatibility' => $summary?->compatibility,
                'task_queue' => $run->queue,
            ],
            'task_queue_pollers' => is_array($taskQueue) ? ($taskQueue['pollers'] ?? []) : [],
            'namespace_worker_fleet' => StandaloneWorkerVisibility::fleetSummary($namespace),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $pendingWorkflowTasks
     * @param  list<array<string, mixed>>  $pendingActivities
     * @param  array<string, mixed>|null  $taskQueue
     */
    private function diagnosticStatus(
        WorkflowRun $run,
        ?WorkflowRunSummary $summary,
        array $pendingWorkflowTasks,
        array $pendingActivities,
        ?array $taskQueue,
    ): string {
        if ($run->status?->isTerminal() === true) {
            return 'terminal';
        }

        if (($summary?->task_problem ?? false) || ($summary?->repair_attention ?? false)) {
            return 'needs_attention';
        }

        if ($pendingWorkflowTasks !== [] || $pendingActivities !== []) {
            return 'pending_work';
        }

        if (is_string($summary?->wait_kind) && $summary->wait_kind !== '') {
            return 'waiting';
        }

        if ((int) data_get($taskQueue, 'stats.approximate_backlog_count', 0) > 0) {
            return 'queue_backlog';
        }

        return 'running';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function findings(array $payload): array
    {
        $findings = [];
        $pendingTasks = $payload['pending_workflow_tasks'] ?? [];
        $pendingActivities = $payload['pending_activities'] ?? [];
        $activePollers = (int) data_get($payload, 'task_queue.stats.pollers.active_count', 0);
        $expiredWorkflowLeases = (int) data_get($payload, 'task_queue.stats.workflow_tasks.expired_lease_count', 0);
        $expiredActivityLeases = (int) data_get($payload, 'task_queue.stats.activity_tasks.expired_lease_count', 0);

        if (($pendingTasks !== [] || $pendingActivities !== []) && $activePollers === 0) {
            $findings[] = [
                'severity' => 'warning',
                'code' => 'no_active_pollers',
                'message' => 'The run has pending work but no active pollers on its task queue.',
            ];
        }

        if ($expiredWorkflowLeases + $expiredActivityLeases > 0) {
            $findings[] = [
                'severity' => 'warning',
                'code' => 'expired_leases',
                'message' => 'The task queue has expired workflow or activity leases.',
            ];
        }

        if ((bool) data_get($payload, 'execution.task_problem', false)) {
            $findings[] = [
                'severity' => 'warning',
                'code' => 'task_problem',
                'message' => 'The run summary has a task problem flag.',
            ];
        }

        if ((bool) data_get($payload, 'execution.repair_attention', false)) {
            $findings[] = [
                'severity' => 'warning',
                'code' => 'repair_attention',
                'message' => 'The run needs repair attention.',
            ];
        }

        foreach ($pendingTasks as $task) {
            if (($task['replay_blocked'] ?? false) === true) {
                $findings[] = [
                    'severity' => 'error',
                    'code' => 'workflow_replay_blocked',
                    'message' => 'A pending workflow task is replay-blocked.',
                ];

                break;
            }
        }

        return $findings;
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

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function timestamp(mixed $value): ?string
    {
        return $this->carbon($value)?->toJSON();
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function carbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
