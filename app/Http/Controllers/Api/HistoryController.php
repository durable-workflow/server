<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\LongPoller;
use App\Support\LongPollSignalStore;
use App\Support\LegacyV1Projection;
use App\Support\NamespaceWorkflowScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class HistoryController
{
    public function __construct(
        private readonly LongPoller $longPoller,
        private readonly LongPollSignalStore $signals,
        private readonly ExternalPayloadEnvelopeService $payloadEnvelopes,
    ) {}

    /**
     * Get the event history for a specific workflow run.
     */
    public function show(Request $request, string $workflowId, string $runId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        $validated = $request->validate([
            'wait_new_event' => ['nullable', 'boolean'],
            'next_page_token' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $run = NamespaceWorkflowScope::run($namespace, $workflowId, $runId);

        if (! $run) {
            return $this->runNotFound($request, $workflowId, $runId);
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

        $payload = [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'compatibility' => $run->compatibility,
            'compatibility_status' => $this->compatibilityStatus($namespace, $run),
            'compatibility_supported_in_fleet' => $this->compatibilitySupportedInFleet($namespace, $run),
            'compatibility_fleet_reason' => $this->compatibilityFleetReason($namespace, $run),
            'events' => $page->map(fn (WorkflowHistoryEvent $event) => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type?->value ?? $event->event_type,
                'timestamp' => $event->recorded_at?->toJSON(),
                'principal' => self::eventPrincipal($event),
                'payload' => $this->eventPayload($namespace, $run, $event),
            ])->all(),
            'next_page_token' => $hasMore && $lastSequence !== null
                ? self::encodePageToken((int) $lastSequence)
                : null,
        ];

        if ($migrationProjection = LegacyV1Projection::metadataForRun($run)) {
            $payload['migration_projection'] = $migrationProjection;
        }

        return ControlPlaneProtocol::jsonForRequest($request, $payload);
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

        $namespace = (string) $request->attributes->get('namespace');

        $run = NamespaceWorkflowScope::run($namespace, $workflowId, $runId);

        if (! $run) {
            return $this->runNotFound($request, $workflowId, $runId);
        }

        /** @var OperatorObservabilityRepository $repository */
        $repository = app(OperatorObservabilityRepository::class);

        $fresh = $run->fresh() ?? $run;
        $bundle = $repository->runHistoryExport($fresh);

        return ControlPlaneProtocol::json(LegacyV1Projection::decorateHistoryExport($bundle, $fresh));
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

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(string $namespace, WorkflowRun $run, WorkflowHistoryEvent $event): array
    {
        $payload = is_array($event->payload)
            ? $this->payloadEnvelopes->historyPayload(
                $namespace,
                $event->payload,
                $run->payload_codec,
                $event->event_type?->value ?? $event->event_type,
            )
            : [];

        $eventType = $event->event_type?->value ?? $event->event_type;

        if ($eventType === HistoryEventType::WorkflowStarted->value && $run->compatibility !== null) {
            $payload['compatibility'] ??= $run->compatibility;
        }

        return $payload;
    }

    private function compatibilityStatus(string $namespace, WorkflowRun $run): string
    {
        if ($run->compatibility === null || $this->compatibilitySupportedInFleet($namespace, $run)) {
            return 'compatible';
        }

        return 'no_compatible_worker';
    }

    private function compatibilitySupportedInFleet(string $namespace, WorkflowRun $run): bool
    {
        if ($run->compatibility === null) {
            return true;
        }

        foreach (WorkerCompatibilityFleet::detailsForNamespace($namespace, $run->compatibility, $run->connection, $run->queue) as $worker) {
            if (($worker['supports_required'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function compatibilityFleetReason(string $namespace, WorkflowRun $run): ?string
    {
        if ($run->compatibility === null || $this->compatibilitySupportedInFleet($namespace, $run)) {
            return null;
        }

        $advertised = [];
        foreach (WorkerCompatibilityFleet::detailsForNamespace($namespace, $run->compatibility, $run->connection, $run->queue) as $worker) {
            foreach (($worker['supported'] ?? []) as $marker) {
                if (is_string($marker) && trim($marker) !== '') {
                    $advertised[trim($marker)] = true;
                }
            }
        }
        ksort($advertised);

        $suffix = $advertised === []
            ? ''
            : ' Active workers there advertise ['.implode(', ', array_keys($advertised)).'].';

        return sprintf(
            'No active worker heartbeat for task queue [%s] advertises compatibility [%s].%s',
            $run->queue ?? 'default',
            $run->compatibility,
            $suffix,
        );
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

    /**
     * Surface the server-derived principal recorded on the underlying
     * command at the top of the event response so audit clients can
     * read the actor for each mutation without walking the payload tree.
     *
     * @return array<string, string>|null
     */
    private static function eventPrincipal(WorkflowHistoryEvent $event): ?array
    {
        $payload = $event->payload ?? [];
        $command = is_array($payload['command'] ?? null) ? $payload['command'] : null;

        if ($command === null) {
            return null;
        }

        $principal = array_filter([
            'type' => is_string($command['principal_type'] ?? null) ? $command['principal_type'] : null,
            'id' => is_string($command['principal_id'] ?? null) ? $command['principal_id'] : null,
            'label' => is_string($command['principal_label'] ?? null) ? $command['principal_label'] : null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        return $principal === [] ? null : $principal;
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

    private function runNotFound(Request $request, string $workflowId, string $runId): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, [
            'message' => 'Workflow run not found.',
            'reason' => 'run_not_found',
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ], 404);
    }
}
