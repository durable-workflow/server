<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\NamespaceWorkflowScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRunSummary;

class HistoryController
{
    public function __construct(
        private readonly LongPoller $longPoller,
        private readonly LongPollSignalStore $signals,
    ) {}

    /**
     * Get the event history for a specific workflow run.
     */
    public function show(Request $request, string $workflowId, string $runId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'wait_new_event' => ['nullable', 'boolean'],
            'next_page_token' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $run = NamespaceWorkflowScope::run($namespace, $workflowId, $runId);

        if (! $run) {
            return $this->runNotFound($workflowId, $runId);
        }

        $pageSize = $validated['page_size'] ?? 100;
        $afterSequence = $this->decodePageToken($validated['next_page_token'] ?? null);
        $waitNewEvent = (bool) ($validated['wait_new_event'] ?? false);

        $events = $waitNewEvent
            ? $this->longPoller->until(
                fn () => $this->loadEvents($run->id, $afterSequence, $pageSize),
                static fn ($events): bool => $events->isNotEmpty(),
                wakeChannels: [$this->signals->historyRunChannel($run->id)],
                nextProbeAt: fn (): ?\DateTimeInterface => $this->nextHistoryProbeAt($run->id),
            )
            : $this->loadEvents($run->id, $afterSequence, $pageSize);

        $hasMore = $events->count() > $pageSize;
        $page = $hasMore ? $events->slice(0, $pageSize)->values() : $events->values();
        $lastSequence = $page->last()?->sequence;

        return ControlPlaneProtocol::json([
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'events' => $page->map(static fn (WorkflowHistoryEvent $event) => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type?->value ?? $event->event_type,
                'timestamp' => $event->recorded_at?->toJSON(),
                'payload' => $event->payload ?? [],
            ])->all(),
            'next_page_token' => $hasMore && $lastSequence !== null
                ? self::encodePageToken((int) $lastSequence)
                : null,
        ]);
    }

    /**
     * Export a closed run's history as a replay bundle.
     *
     * Returns a versioned history bundle suitable for offline debugging,
     * warehouse ingestion, or replay validation against a target build.
     */
    public function export(Request $request, string $workflowId, string $runId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $run = NamespaceWorkflowScope::run($namespace, $workflowId, $runId);

        if (! $run) {
            return $this->runNotFound($workflowId, $runId);
        }

        /** @var OperatorObservabilityRepository $repository */
        $repository = app(OperatorObservabilityRepository::class);

        return ControlPlaneProtocol::json($repository->runHistoryExport($run->fresh()));
    }

    private function loadEvents(string $runId, ?int $afterSequence, int $pageSize)
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->when(
                $afterSequence !== null,
                static fn ($query) => $query->where('sequence', '>', $afterSequence),
            )
            ->orderBy('sequence')
            ->limit($pageSize + 1)
            ->get();
    }

    private function decodePageToken(?string $token): ?int
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $decoded = base64_decode($token, true);

        if (! is_string($decoded) || ! ctype_digit($decoded)) {
            return null;
        }

        return (int) $decoded;
    }

    private static function encodePageToken(int $sequence): string
    {
        return base64_encode((string) $sequence);
    }

    private function nextHistoryProbeAt(string $runId): ?\DateTimeInterface
    {
        /** @var WorkflowRunSummary|null $summary */
        $summary = WorkflowRunSummary::query()->find($runId);

        if (! $summary instanceof WorkflowRunSummary) {
            return null;
        }

        $now = now();
        $hints = array_values(array_filter([
            $summary->next_task_at,
            $summary->next_task_lease_expires_at,
            $summary->wait_deadline_at,
        ], static fn (mixed $value): bool => $value instanceof \DateTimeInterface && $value > $now));

        if ($hints === []) {
            return null;
        }

        usort(
            $hints,
            static fn (\DateTimeInterface $left, \DateTimeInterface $right): int => (float) $left->format('U.u')
                <=> (float) $right->format('U.u'),
        );

        return $hints[0];
    }

    private function runNotFound(string $workflowId, string $runId): JsonResponse
    {
        return ControlPlaneProtocol::json([
            'message' => 'Workflow run not found.',
            'reason' => 'run_not_found',
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ], 404);
    }
}
