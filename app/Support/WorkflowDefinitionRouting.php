<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Generator;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskCompatibility;

final class WorkflowDefinitionRouting
{
    public const ENTRY_VALIDATION_CAPABILITY = 'workflow_entry_arguments_validation';

    /** @return array<string, mixed> */
    public function forWorker(
        WorkflowRun $run,
        WorkerRegistration $worker,
        ?string $taskQueue = null,
        ?string $requiredCompatibility = null,
    ): array {
        $decision = $this->context($run, $taskQueue, $requiredCompatibility);
        $recorded = $decision['recorded_fingerprint'];
        $advertised = self::stringValue($worker->workflow_definition_fingerprints[$run->workflow_type] ?? null);
        $decision['advertised_fingerprint'] = $advertised;

        $immediate = match (true) {
            ! $decision['worker_pinned'] => ['allowed' => true, 'status' => 'not_worker_pinned'],
            ! $decision['pinning_enabled'] => ['allowed' => true, 'status' => 'pinning_disabled'],
            $recorded === null => ['allowed' => false, 'status' => 'recorded_definition_missing'],
            $advertised === null => ['allowed' => false, 'status' => 'worker_definition_missing'],
            hash_equals($recorded, $advertised) => ['allowed' => true, 'status' => 'matching_definition'],
            default => null,
        };
        if ($immediate !== null) {
            return [...$decision, ...$immediate];
        }

        foreach ($this->eligibleWorkers($run, $decision['task_queue'], $decision['required_build_id']) as $peer) {
            if (self::stringValue($peer->workflow_definition_fingerprints[$run->workflow_type] ?? null) === $recorded) {
                return [...$decision, 'allowed' => false, 'status' => 'matching_definition_preferred',
                    'preferred_worker_id' => $peer->worker_id];
            }
        }

        if (! in_array(self::ENTRY_VALIDATION_CAPABILITY, $worker->capabilities ?? [], true)) {
            return [...$decision, 'allowed' => false, 'status' => 'entry_validation_unavailable'];
        }

        // Match the embedded resolver: prefer the recorded source if it is
        // available, otherwise let the current definition validate replay.
        // This never changes the run's original fingerprint or build pin.
        return [...$decision, 'allowed' => true, 'status' => 'replay_required'];
    }

    /** @return array<string, mixed> */
    public function inspect(WorkflowRun $run, ?string $taskQueue = null, ?string $requiredCompatibility = null): array
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)
            ->where(static fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()->format('Y-m-d H:i:s.u')))
            ->when($taskQueue !== null, static fn ($query) => $query->where('queue', $taskQueue))
            ->orderBy('id')->first();
        if ($task instanceof WorkflowTask) {
            $taskQueue ??= $task->queue;
            $requiredCompatibility ??= TaskCompatibility::resolve($task, $run);
        }
        $context = $this->context($run, $taskQueue, $requiredCompatibility);
        $matching = $changed = $missing = $unsafeReplacement = 0;
        $examples = [];

        foreach ($this->eligibleWorkers($run, (string) $context['task_queue'], $context['required_build_id']) as $worker) {
            $fingerprint = self::stringValue($worker->workflow_definition_fingerprints[$run->workflow_type] ?? null);
            if ($fingerprint === null) {
                $missing++;
            } elseif ($fingerprint === $context['recorded_fingerprint']) {
                $matching++;
            } else {
                $changed++;
                if (! in_array(self::ENTRY_VALIDATION_CAPABILITY, $worker->capabilities ?? [], true)) {
                    $unsafeReplacement++;
                }
            }
            if (count($examples) < 10) {
                $examples[] = ['worker_id' => $worker->worker_id, 'fingerprint' => $fingerprint];
            }
        }

        $eligible = $matching + $changed + $missing;
        $status = match (true) {
            ! $context['worker_pinned'] => 'not_worker_pinned',
            ! $context['pinning_enabled'] => 'pinning_disabled',
            $context['recorded_fingerprint'] === null => 'recorded_definition_missing',
            $matching > 0 => 'matching_definition_available',
            $changed > $unsafeReplacement => 'replay_required',
            $unsafeReplacement > 0 => 'entry_validation_unavailable',
            $missing > 0 => 'worker_definition_missing',
            default => 'no_eligible_worker',
        };
        $runnable = ! $run->status->isTerminal() && $task instanceof WorkflowTask;
        $blocked = $runnable && $context['worker_pinned'] && $context['pinning_enabled']
            && in_array($status, ['recorded_definition_missing', 'worker_definition_missing', 'no_eligible_worker', 'entry_validation_unavailable'], true);
        $resolution = match (true) {
            $blocked && $status === 'entry_validation_unavailable' => 'Upgrade the replacement worker SDK to an implementation advertising workflow_entry_arguments_validation, or restore the recorded definition. The current replacement cannot safely refuse incompatible entry input.',
            $blocked => 'Register a fresh worker for the original namespace, task queue, workflow type, build and replay capabilities with its definition fingerprint. Do not rewrite the run history.',
            $status === 'replay_required' => 'Current workers may replay the original history. Use versioned branches for command changes; incompatible replay still refuses.',
            default => 'Recorded build, namespace, type and replay-capability guards remain in force.',
        };

        return [...$context, 'status' => $status, 'blocked' => $blocked, 'runnable_task' => $runnable,
            'eligible_worker_count' => $eligible, 'matching_worker_count' => $matching,
            'changed_worker_count' => $changed, 'missing_fingerprint_count' => $missing,
            'entry_validation_unavailable_count' => $unsafeReplacement,
            'workers' => $examples, 'workers_truncated' => $eligible > count($examples),
            'resolution' => $resolution,
        ];
    }

    /** Bounded examples of due ready runs, never a repair or a payload read. */
    public function forQueue(string $namespace, string $taskQueue): array
    {
        $runs = WorkflowRun::query()->where('namespace', $namespace)->where('queue', $taskQueue)
            ->whereIn('id', WorkflowTask::query()->select('workflow_run_id')
                ->where('task_type', TaskType::Workflow->value)->where('status', TaskStatus::Ready->value)
                ->where('queue', $taskQueue)
                ->where(static fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()->format('Y-m-d H:i:s.u'))))
            ->orderBy('id')->limit(11)->get();
        $examples = [];
        foreach ($runs->take(10) as $run) {
            if (! $run->status->isTerminal()) {
                $examples[] = ['workflow_id' => $run->workflow_instance_id, 'run_id' => $run->id,
                    ...$this->inspect($run, $taskQueue)];
            }
        }

        return ['sample_limit' => 10, 'has_more_ready_runs' => $runs->count() > 10,
            'blocked_sample_count' => count(array_filter($examples, static fn (array $row): bool => $row['blocked'])),
            'runs' => $examples];
    }

    /** @return array<string, mixed> */
    private function context(WorkflowRun $run, ?string $taskQueue, ?string $requiredCompatibility): array
    {
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', 'WorkflowStarted')->orderBy('sequence')->first();
        $recorded = self::stringValue($started?->payload['workflow_definition_fingerprint'] ?? null);

        return [
            'pinning_enabled' => (bool) config('workflows.v2.compatibility.pin_to_recorded_fingerprint', true),
            'worker_pinned' => ($started?->payload['workflow_definition_fingerprint_source'] ?? null) === 'worker',
            'recorded_fingerprint' => $recorded,
            'workflow_type' => $run->workflow_type,
            'task_queue' => $taskQueue ?? $run->queue,
            'required_build_id' => $requiredCompatibility ?? self::stringValue($run->compatibility),
        ];
    }

    /** @return Generator<int, WorkerRegistration> */
    private function eligibleWorkers(
        WorkflowRun $run,
        string $taskQueue,
        ?string $requiredCompatibility,
    ): Generator {
        $workers = WorkerRegistration::query()->where('namespace', $run->namespace)
            ->where('task_queue', $taskQueue)->where('status', 'active');
        if ($requiredCompatibility !== null) {
            $workers->where('build_id', $requiredCompatibility);
        }

        foreach ($workers->cursor(['namespace', 'worker_id', 'task_queue', 'build_id', 'status', 'last_heartbeat_at',
            'workflow_definition_fingerprints', 'supported_workflow_types', 'max_concurrent_workflow_tasks',
            'capabilities', 'process_metrics']) as $worker) {
            if (! in_array($run->workflow_type, $worker->supported_workflow_types ?? [], true)
                || ($worker->max_concurrent_workflow_tasks !== null && (int) $worker->max_concurrent_workflow_tasks < 1)) {
                continue;
            }
            if (WorkflowReplayEligibility::allows($worker, $run, WorkflowReplayEligibility::recordedProtocol($worker))) {
                yield $worker;
            }
        }
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
