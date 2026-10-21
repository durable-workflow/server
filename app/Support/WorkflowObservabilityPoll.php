<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskOutcomeCarrier;
use Workflow\V2\Support\TaskTraceCarrier;

/** Optional observations after the poller's original claim and history fences. */
final class WorkflowObservabilityPoll
{
    public static function filterOptionalDelivered(mixed $wire): ?array
    {
        return is_array($wire) ? self::filterDelivered($wire) : null;
    }

    /** Reapply current privacy settings to a validated cached poll response. */
    public static function filterDelivered(array $wire): array
    {
        $origin = $wire['observability_context'] ?? null;
        $dispatch = $wire['dispatch_observability_context'] ?? null;
        $links = $wire['observability_links'] ?? null;
        unset($wire['observability_context'], $wire['dispatch_observability_context'], $wire['observability_links']);
        if (! WorkflowObservability::enabled()) {
            return $wire;
        }
        try {
            $taskId = $wire['task_id'] ?? $wire['query_task_id'] ?? null;
            if (! self::identity($taskId) || ! is_string($wire['lease_expires_at'] ?? null)
                || ! Carbon::parse($wire['lease_expires_at'])->isFuture()) {
                return $wire;
            }
            $origin = WorkflowObservability::normalize($origin);
            if ($origin !== null) {
                $wire['observability_context'] = $origin;
            }
            $context = TaskTraceCarrier::fromJob($dispatch, $taskId);
            if ($context !== null) {
                $wire['dispatch_observability_context'] = array_replace($dispatch, ['context' => $context]);
            } elseif ($dispatch !== null) {
                WorkflowObservability::diagnostic('discarded');
            }
            $validatedLinks = self::normalizeLinks($links, $taskId, $wire['run_id'] ?? null, $wire['namespace'] ?? null);
            if ($validatedLinks !== null) {
                $wire['observability_links'] = $validatedLinks;
            } elseif ($links !== null) {
                WorkflowObservability::diagnostic('discarded');
            }
        } catch (Throwable) {
            // Retain the business response with only the observations already validated.
        }

        return $wire;
    }

    public static function forWorkflowClaim(WorkflowRun $run, array $wire): array
    {
        if (! WorkflowObservability::enabled()) {
            return [];
        }
        try {
            if (! self::identity($wire['task_id'] ?? null)) {
                return [];
            }
            $task = NamespaceWorkflowScope::task((string) $run->namespace, $wire['task_id']);

            return $task !== null ? self::forClaim($run, $task, $wire, 'workflow') : [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function forActivityClaim(string $namespace, array $claim): array
    {
        if (! WorkflowObservability::enabled()) {
            return [];
        }
        try {
            $taskId = $claim['task_id'] ?? null;
            $instanceId = $claim['workflow_instance_id'] ?? null;
            $runId = $claim['workflow_run_id'] ?? null;
            if (! self::identity($taskId) || ! self::identity($instanceId) || ! self::identity($runId)) {
                return [];
            }
            $task = NamespaceWorkflowScope::task($namespace, $taskId);
            $run = NamespaceWorkflowScope::run($namespace, $instanceId, $runId);

            return $task !== null && $run !== null ? self::forClaim($run, $task, $claim, 'activity') : [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function forQuery(WorkflowRun $run, array $original): array
    {
        if (! WorkflowObservability::enabled()) {
            return [];
        }
        try {
            if (! self::identity($run->id) || ! self::identity($run->namespace) || ! self::identity($run->workflow_instance_id)
                || ($original['status'] ?? null) !== 'leased' || ($original['namespace'] ?? null) !== $run->namespace
                || ($original['run_id'] ?? null) !== $run->id || ($original['workflow_id'] ?? null) !== $run->workflow_instance_id
                || ! self::identity($original['query_task_id'] ?? null) || ! self::identity($original['lease_owner'] ?? null)
                || ! is_int($original['attempt_count'] ?? null) || $original['attempt_count'] < 1
                || ! is_string($original['query_admitted_at'] ?? null) || Carbon::parse($original['query_admitted_at'])->getTimestamp() <= 0
                || ! is_string($original['query_deadline_at'] ?? null) || ! Carbon::parse($original['query_deadline_at'])->isFuture()
                || ! is_string($original['lease_expires_at'] ?? null) || ! Carbon::parse($original['lease_expires_at'])->isFuture()) {
                return [];
            }
            $fields = ['namespace' => $run->namespace];
            $origin = WorkflowObservability::normalize($run->getAttribute('observability_context'));
            if ($origin !== null) {
                $fields['observability_context'] = $origin;
            }

            return $fields;
        } catch (Throwable) {
            return [];
        }
    }

    public static function forClaim(WorkflowRun $run, WorkflowTask $task, array $wire, string $kind): array
    {
        if (! WorkflowObservability::enabled()) {
            return [];
        }
        try {
            if (! self::matchesOriginalClaim($run, $task, $wire, $kind)) {
                return [];
            }
            $fields = ['namespace' => $run->namespace];
            $origin = WorkflowObservability::normalize($run->getAttribute('observability_context'));
            if ($origin !== null) {
                $fields['observability_context'] = $origin;
            }
            $dispatch = DispatchObservability::forTask($task);
            if ($dispatch !== null) {
                $fields['dispatch_observability_context'] = $dispatch;
            }
            if ($kind === 'workflow') {
                $links = self::links($run, $task);
                if ($links['links'] !== []) {
                    $fields['observability_links'] = $links;
                }
            }

            return $fields;
        } catch (Throwable) {
            return [];
        }
    }

    private static function matchesOriginalClaim(WorkflowRun $run, WorkflowTask $task, array $wire, string $kind): bool
    {
        $shape = match ($kind) {
            'workflow' => ['run_id', 'workflow_id', 'workflow_task_attempt', 'task_queue', TaskType::Workflow],
            'activity' => ['workflow_run_id', 'workflow_instance_id', 'attempt_number', 'queue', TaskType::Activity],
            default => null,
        };
        if ($shape === null) {
            return false;
        }
        [$runKey, $instanceKey, $attemptKey, $queueKey, $type] = $shape;

        return self::identity($task->id) && self::identity($run->id) && self::identity($run->namespace)
            && ($wire['task_id'] ?? null) === $task->id && ($wire[$runKey] ?? null) === $run->id
            && ($wire[$instanceKey] ?? null) === $run->workflow_instance_id
            && $task->workflow_run_id === $run->id && $task->namespace === $run->namespace
            && $task->task_type === $type && $task->status === TaskStatus::Leased
            && is_int($wire[$attemptKey] ?? null) && $wire[$attemptKey] > 0
            && $wire[$attemptKey] === (int) $task->attempt_count
            && self::identity($wire['lease_owner'] ?? null) && $wire['lease_owner'] === $task->lease_owner
            && ($wire[$queueKey] ?? null) === $task->queue
            && is_string($wire['lease_expires_at'] ?? null)
            && Carbon::parse($wire['lease_expires_at'])->isFuture()
            && $task->lease_expires_at !== null && $task->lease_expires_at->isFuture()
            && Carbon::parse($wire['lease_expires_at'])->equalTo($task->lease_expires_at)
            && ($kind !== 'activity' || self::matchesActivityAttempt($task, $wire));
    }

    private static function matchesActivityAttempt(WorkflowTask $task, array $wire): bool
    {
        if (! self::identity($wire['activity_attempt_id'] ?? null) || ! self::identity($wire['activity_execution_id'] ?? null)) {
            return false;
        }
        $attempt = ActivityAttempt::query()->whereKey($wire['activity_attempt_id'])
            ->where('workflow_task_id', $task->id)->where('activity_execution_id', $wire['activity_execution_id'])
            ->where('lease_owner', $wire['lease_owner'])->where('attempt_number', $wire['attempt_number'])
            ->where('lease_expires_at', '>', now())->first();

        return $attempt !== null;
    }

    private static function links(WorkflowRun $run, WorkflowTask $task): array
    {
        $envelope = ['schema' => 'durable-workflow.trace-links', 'version' => 1,
            'task_id' => $task->id, 'run_id' => $run->id, 'namespace' => $run->namespace, 'links' => []];
        $commandId = is_array($task->payload) ? ($task->payload['workflow_command_id'] ?? null) : null;
        if (self::identity($commandId)) {
            $command = WorkflowCommand::query()->whereKey($commandId)
                ->where('workflow_run_id', $run->id)->where('workflow_instance_id', $run->workflow_instance_id)
                ->whereNotNull('accepted_at')->whereNull('rejected_at')->first();
            if ($command !== null) {
                self::append($envelope, ['kind' => 'command', 'command_id' => $command->id],
                    $command->getAttribute('observability_context'));
            }
        }
        foreach (TaskOutcomeCarrier::forRun($run, $task) as $outcome) {
            $entry = isset($outcome['child_run_id']) ? self::childOutcome($outcome, $run) : self::taskOutcome($outcome);
            if ($entry !== null) {
                self::append($envelope, $entry, $outcome['context'] ?? null);
            }
        }

        return $envelope;
    }

    private static function taskOutcome(array $outcome): ?array
    {
        $entry = ['kind' => 'task_outcome', 'task_id' => $outcome['task_id'] ?? null,
            'event_id' => $outcome['event_id'] ?? null, 'sequence' => $outcome['sequence'] ?? null,
            'event_type' => $outcome['event_type'] ?? null];
        foreach (['activity_id' => 'activity_execution_id', 'activity_attempt_id' => 'activity_attempt_id', 'timer_id' => 'timer_id'] as $source => $target) {
            if (array_key_exists($source, $outcome)) {
                $entry[$target] = $outcome[$source];
            }
        }

        return self::validEntry($entry) ? $entry : null;
    }

    private static function childOutcome(array $outcome, WorkflowRun $run): ?array
    {
        if (($outcome['parent_run_id'] ?? null) !== $run->id || ($outcome['parent_namespace'] ?? null) !== $run->namespace
            || ($outcome['child_namespace'] ?? null) !== $run->namespace) {
            return null;
        }
        $entry = ['kind' => 'child_outcome', 'workflow_link_id' => $outcome['link_id'] ?? null,
            'child_run_id' => $outcome['child_run_id'] ?? null, 'child_task_id' => $outcome['task_id'] ?? null,
            'event_id' => $outcome['event_id'] ?? null, 'sequence' => $outcome['sequence'] ?? null,
            'event_type' => $outcome['event_type'] ?? null];

        return self::validEntry($entry) ? $entry : null;
    }

    private static function append(array &$envelope, array $entry, mixed $value): void
    {
        if (count($envelope['links']) >= 32) {
            WorkflowObservability::diagnostic('filtered');

            return;
        }
        if (! self::validEntry($entry)) {
            WorkflowObservability::diagnostic('discarded');

            return;
        }
        $context = WorkflowObservability::normalize($value, allowBaggage: false);
        if ($context === null) {
            return;
        }
        $next = $envelope;
        $next['links'][] = $entry + ['context' => $context];
        if (strlen(json_encode($next, JSON_THROW_ON_ERROR)) <= 4096) {
            $envelope = $next;
        } else {
            WorkflowObservability::diagnostic('filtered');
        }
    }

    private static function validEntry(array $entry): bool
    {
        $shape = match ($entry['kind'] ?? null) {
            'command' => [['command_id'], []],
            'task_outcome' => [['task_id', 'event_id', 'sequence', 'event_type'], ['activity_execution_id', 'activity_attempt_id', 'timer_id']],
            'child_outcome' => [['workflow_link_id', 'child_run_id', 'event_id', 'sequence', 'event_type'], ['child_task_id']],
            default => null,
        };
        if ($shape === null) {
            return false;
        }
        [$required, $optional] = $shape;
        if (array_diff($required, array_keys($entry)) !== []
            || array_diff(array_keys($entry), [...$required, ...$optional, 'kind', 'context']) !== []) {
            return false;
        }
        foreach ($entry as $key => $value) {
            if (! self::validEntryField($key, $value)) {
                return false;
            }
        }

        return true;
    }

    private static function validEntryField(string $key, mixed $value): bool
    {
        return match ($key) {
            'context' => true,
            'sequence' => is_int($value) && $value > 0,
            'child_task_id' => $value === null || self::identity($value),
            default => self::identity($value),
        };
    }

    private static function normalizeLinks(mixed $value, string $taskId, mixed $runId, mixed $namespace): ?array
    {
        if (! is_array($value) || count($value) !== 6 || ! self::identity($runId) || ! self::identity($namespace)
            || ($value['schema'] ?? null) !== 'durable-workflow.trace-links' || ($value['version'] ?? null) !== 1
            || ($value['task_id'] ?? null) !== $taskId || ($value['run_id'] ?? null) !== $runId
            || ($value['namespace'] ?? null) !== $namespace
            || array_diff(array_keys($value), ['schema', 'version', 'task_id', 'run_id', 'namespace', 'links']) !== []
            || ! is_array($value['links'] ?? null) || ! array_is_list($value['links']) || count($value['links']) > 32
            || strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 4096) {
            return null;
        }
        foreach ($value['links'] as $index => $entry) {
            if (! is_array($entry) || ! self::validEntry($entry) || ! is_array($entry['context']['carrier'] ?? null)
                || array_diff(array_keys($entry['context']['carrier']), ['traceparent', 'tracestate']) !== []) {
                return null;
            }
            $context = WorkflowObservability::normalize($entry['context'], allowBaggage: false);
            if ($context === null) {
                return null;
            }
            $value['links'][$index]['context'] = $context;
        }

        return $value;
    }

    private static function identity(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 512
            && preg_match('//u', $value) === 1 && ! preg_match('/[\x00-\x1f\x7f]/', $value);
    }
}
