<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TraceCarrier;

/** Attach observations only to runs created by this successful fenced completion. */
final class WorkflowObservabilityTransitions
{
    public static function businessCommands(array $commands): array
    {
        foreach ($commands as $index => $command) {
            if (is_array($command) && in_array($command['type'] ?? null, ['start_child_workflow', 'continue_as_new'], true)) {
                unset($commands[$index]['observability_context']);
            }
        }

        return $commands;
    }

    public static function apply(Request $request, WorkflowTask $original, array $createdRunIds): void
    {
        if ($original->status !== TaskStatus::Leased || $original->lease_expires_at === null
            || ! $original->lease_expires_at->isFuture() || ! WorkflowObservability::runtimeAvailable()) {
            return;
        }
        try {
            // Savepoints isolate optional writes from the already successful business operation.
            $original->getConnection()->transaction(function () use ($request, $original, $createdRunIds): void {
                $parent = WorkflowRun::query()->whereKey($original->workflow_run_id)->where('namespace', $original->namespace)->first();
                if ($parent === null) {
                    return;
                }
                $links = WorkflowLink::query()->where('parent_workflow_run_id', $parent->id)
                    ->where('parent_workflow_instance_id', $parent->workflow_instance_id)
                    ->whereIn('child_workflow_run_id', $createdRunIds)
                    ->whereIn('link_type', ['child_workflow', 'continue_as_new'])->orderBy('sequence')->get();
                $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->id)
                    ->where('workflow_task_id', $original->id)
                    ->whereIn('event_type', ['ChildWorkflowScheduled', 'WorkflowContinuedAsNew'])->get();
                $inherited = TraceCarrier::normalize($parent->getAttribute('observability_context'), WorkflowObservability::baggageAllowlist());
                $producers = WorkflowObservability::enabled() ? self::producers($request) : [];

                foreach (['child_workflow', 'continue_as_new'] as $kind) {
                    $selected = $links->where('link_type', $kind)->values();
                    $contexts = $producers[$kind] ?? [];
                    foreach ($selected as $index => $link) {
                        $expectedEvent = $kind === 'child_workflow' ? 'ChildWorkflowScheduled' : 'WorkflowContinuedAsNew';
                        $event = $events->first(static fn (WorkflowHistoryEvent $event): bool => $event->event_type->value === $expectedEvent
                            && ($event->payload['workflow_link_id'] ?? null) === $link->id);
                        if ($event === null) {
                            continue;
                        }
                        $context = count($contexts) === $selected->count() ? ($contexts[$index] ?? null) : null;
                        self::writeOrigin($link, $parent, $context ?? $inherited);
                    }
                }
            });
        } catch (Throwable) {
            // Missing metadata or unavailable observation storage cannot replace completion.
        }
    }

    private static function producers(Request $request): array
    {
        $result = ['child_workflow' => [], 'continue_as_new' => []];
        $commands = $request->input('commands');
        if (! is_array($commands)) {
            return $result;
        }
        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }
            $kind = match ($command['type'] ?? null) {
                'start_child_workflow' => 'child_workflow',
                'continue_as_new' => 'continue_as_new',
                default => null,
            };
            if ($kind !== null) {
                $result[$kind][] = WorkflowObservability::normalize($command['observability_context'] ?? null);
            }
        }

        return $result;
    }

    private static function writeOrigin(WorkflowLink $link, WorkflowRun $parent, ?array $context): void
    {
        if ($context === null) {
            return;
        }
        $child = WorkflowRun::query()->whereKey($link->child_workflow_run_id)
            ->where('workflow_instance_id', $link->child_workflow_instance_id)->where('namespace', $parent->namespace)->first();
        if ($child === null || ! $child->getConnection()->getSchemaBuilder()->hasColumn($child->getTable(), 'observability_context')) {
            return;
        }
        // A raw update touches only the optional column, not timestamps or application mutators.
        $child->getConnection()->table($child->getTable())->where('id', $child->id)->where('namespace', $parent->namespace)
            ->update(['observability_context' => json_encode($context, JSON_THROW_ON_ERROR)]);
    }
}
