<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowDurableStream;
use App\Support\ControlPlaneProtocol;
use App\Support\LegacyV1Projection;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\NamespaceWorkflowScope;
use App\Support\PayloadCodecContract;
use App\Support\RuntimeExternalPayloadException;
use App\Support\StreamClosedException;
use App\Support\StreamErroredException;
use App\Support\StreamFullException;
use App\Support\StreamNotFoundException;
use App\Support\WorkflowStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Workflow\V2\Models\WorkflowRun;

class WorkflowStreamController
{
    public function __construct(
        private readonly WorkflowStreamService $streams,
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
    ) {}

    public function index(Request $request, string $workflowId, string $runId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $run = $this->locateRun($namespace, $workflowId, $runId);

        if ($run === null) {
            return $this->notFound($request);
        }

        $streams = $this->streams->listForRun($namespace, $runId);

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'workflow_run_id' => $runId,
            'count' => $streams->count(),
            'streams' => $streams
                ->map(fn (WorkflowDurableStream $stream) => $this->formatStream($stream))
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, string $workflowId, string $runId, string $streamName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $run = $this->locateRun($namespace, $workflowId, $runId);

        if ($run === null) {
            return $this->notFound($request);
        }

        try {
            $stream = $this->streams->describe($run, $namespace, $streamName);
        } catch (StreamNotFoundException) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Stream not found.',
                'reason' => 'stream_not_found',
            ], 404);
        } catch (InvalidArgumentException $e) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => $e->getMessage(),
                'reason' => 'invalid_stream_name',
            ], 400);
        }

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'workflow_run_id' => $runId,
            'stream' => $this->formatStream($stream),
        ]);
    }

    public function items(Request $request, string $workflowId, string $runId, string $streamName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $run = $this->locateRun($namespace, $workflowId, $runId);

        if ($run === null) {
            return $this->notFound($request);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'integer', 'min:0'],
            'max_items' => ['nullable', 'integer', 'min:1', 'max:'.WorkflowStreamService::SUBSCRIBE_MAX_ITEMS],
            'wait_seconds' => ['nullable', 'integer', 'min:0', 'max:'.WorkflowStreamService::SUBSCRIBE_MAX_WAIT_SECONDS],
        ]);

        $fromOffset = (int) ($validated['from'] ?? 0);
        $maxItems = (int) ($validated['max_items'] ?? WorkflowStreamService::DEFAULT_MAX_ITEMS_PER_SUBSCRIBE);
        $waitSeconds = (int) ($validated['wait_seconds'] ?? 0);

        try {
            $result = $this->readWithWait($run, $namespace, $streamName, $fromOffset, $maxItems, $waitSeconds);
        } catch (StreamNotFoundException) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Stream not found.',
                'reason' => 'stream_not_found',
            ], 404);
        } catch (InvalidArgumentException $e) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => $e->getMessage(),
                'reason' => 'invalid_stream_name',
            ], 400);
        }

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'workflow_run_id' => $runId,
            'stream' => $this->formatStream($result['stream']),
            'items' => $result['items']->map(fn ($item) => [
                'offset' => (int) $item->offset,
                'idempotency_key' => $item->idempotency_key,
                'origin' => $item->origin,
                'origin_reference' => $item->origin_reference,
                'item_type' => $item->item_type,
                'content_type' => $item->content_type,
                'payload' => $item->payload,
                'payload_reference' => $item->payload_reference,
                'payload_codec' => $item->payload_codec,
                'emitted_at' => $item->emitted_at?->toJSON(),
            ])->values()->all(),
            'next_offset' => $result['next_offset'],
            'terminal' => $result['terminal'],
        ]);
    }

    public function append(Request $request, string $workflowId, string $runId, string $streamName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $run = $this->locateRun($namespace, $workflowId, $runId);

        if ($run === null) {
            return $this->notFound($request);
        }

        if (LegacyV1Projection::isProjectedRun($run)) {
            return $this->projectedRunReadOnly($request, $workflowId, $runId);
        }

        $payload = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.WorkflowStreamService::DEFAULT_MAX_ITEMS_PER_APPEND],
            'items.*' => ['array'],
            'items.*.payload' => ['nullable'],
            'items.*.payload_reference' => ['nullable', 'string', 'max:191'],
            'items.*.payload_codec' => ['nullable', 'string', 'max:64'],
            'items.*.idempotency_key' => ['nullable', 'string', 'max:191'],
            'items.*.origin' => ['nullable', 'string', 'max:64'],
            'items.*.origin_reference' => ['nullable', 'string', 'max:191'],
            'items.*.item_type' => ['nullable', 'string', 'max:64'],
            'items.*.content_type' => ['nullable', 'string', 'max:191'],
            'max_pending_items' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach ($payload['items'] as $index => $item) {
            if (array_key_exists('payload_codec', $item)) {
                try {
                    $payload['items'][$index]['payload_codec'] = PayloadCodecContract::canonicalize(
                        $item['payload_codec'],
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        "items.{$index}.payload_codec" => [$exception->getMessage()],
                    ]);
                }
            }

            $reference = $item['payload_reference'] ?? null;
            if (is_string($reference) && $reference !== '') {
                $driver = $this->externalPayloadStorage->driverFor($namespace);
                if ($driver === null) {
                    throw new RuntimeExternalPayloadException(
                        'external_payload_unavailable',
                        503,
                        true,
                        'External payload storage is unavailable for this namespace.',
                    );
                }

                $driver->get($reference);
            }
        }

        $maxPending = (int) ($payload['max_pending_items']
            ?? WorkflowStreamService::DEFAULT_MAX_PENDING_ITEMS);

        try {
            $result = $this->streams->append(
                $run,
                $namespace,
                $streamName,
                $payload['items'],
                $maxPending,
            );
        } catch (StreamClosedException) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Stream is closed.',
                'reason' => 'stream_closed',
            ], 409);
        } catch (StreamErroredException) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Stream is errored.',
                'reason' => 'stream_errored',
            ], 409);
        } catch (StreamFullException $e) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Stream pending-items cap reached; consumer is draining too slowly.',
                'reason' => 'stream_full',
                'pending_items' => (int) $e->stream->pending_items,
                'max_pending_items' => $e->maxPendingItems,
            ], 429);
        } catch (InvalidArgumentException $e) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => $e->getMessage(),
                'reason' => 'invalid_request',
            ], 400);
        }

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'workflow_run_id' => $runId,
            'stream' => $this->formatStream($result['stream']),
            'accepted_offsets' => array_map(
                static fn ($item) => (int) $item->offset,
                $result['items'],
            ),
            'accepted' => $result['accepted'],
            'deduped' => $result['deduped'],
        ]);
    }

    public function close(Request $request, string $workflowId, string $runId, string $streamName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $run = $this->locateRun($namespace, $workflowId, $runId);

        if ($run === null) {
            return $this->notFound($request);
        }

        if (LegacyV1Projection::isProjectedRun($run)) {
            return $this->projectedRunReadOnly($request, $workflowId, $runId);
        }

        $payload = $request->validate([
            'error_reason' => ['nullable', 'string', 'max:191'],
            'retention_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $stream = $this->streams->close(
                $run,
                $namespace,
                $streamName,
                $payload['error_reason'] ?? null,
                isset($payload['retention_seconds']) ? (int) $payload['retention_seconds'] : null,
            );
        } catch (StreamNotFoundException) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Stream not found.',
                'reason' => 'stream_not_found',
            ], 404);
        } catch (InvalidArgumentException $e) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => $e->getMessage(),
                'reason' => 'invalid_stream_name',
            ], 400);
        }

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'workflow_run_id' => $runId,
            'stream' => $this->formatStream($stream),
        ]);
    }

    private function projectedRunReadOnly(Request $request, string $workflowId, string $runId): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, [
            'message' => 'Durable streams cannot mutate a read-only v1 projection.',
            'reason' => 'v1_projection_read_only',
            'remediation' => 'Continue execution and stream production on the source v1 application.',
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'execution_owner' => 'v1',
        ], 409);
    }

    private function notFound(Request $request): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, [
            'message' => 'Workflow run not found.',
            'reason' => 'instance_not_found',
        ], 404);
    }

    /**
     * @return array{stream: WorkflowDurableStream, items: \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkflowDurableStreamItem>, next_offset: int, terminal: bool}
     */
    private function readWithWait(
        WorkflowRun $run,
        string $namespace,
        string $streamName,
        int $fromOffset,
        int $maxItems,
        int $waitSeconds,
    ): array {
        $result = $this->streams->read($run, $namespace, $streamName, $fromOffset, $maxItems);

        if ($waitSeconds <= 0 || $result['items']->isNotEmpty() || $result['terminal']) {
            return $result;
        }

        // Simple bounded long-poll: re-check every 250ms up to wait_seconds.
        $deadline = microtime(true) + $waitSeconds;

        while (microtime(true) < $deadline) {
            usleep(250 * 1000);

            $result = $this->streams->read($run, $namespace, $streamName, $fromOffset, $maxItems);

            if ($result['items']->isNotEmpty() || $result['terminal']) {
                break;
            }
        }

        return $result;
    }

    private function locateRun(string $namespace, string $workflowId, string $runId): ?WorkflowRun
    {
        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return null;
        }

        return NamespaceWorkflowScope::run($namespace, $workflowId, $runId);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatStream(WorkflowDurableStream $stream): array
    {
        return [
            'stream_name' => $stream->stream_name,
            'status' => $stream->status,
            'last_offset' => (int) $stream->last_offset,
            'total_items' => (int) $stream->total_items,
            'pending_items' => (int) $stream->pending_items,
            'opened_at' => $stream->opened_at?->toJSON(),
            'last_appended_at' => $stream->last_appended_at?->toJSON(),
            'closed_at' => $stream->closed_at?->toJSON(),
            'error_reason' => $stream->error_reason,
            'retention_seconds' => $stream->retention_seconds === null ? null : (int) $stream->retention_seconds,
        ];
    }
}
