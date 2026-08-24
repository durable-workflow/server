<?php

namespace App\Http\Controllers\Api;

use App\Support\AvroPayloadEnvelopeResolver;
use App\Support\ControlPlaneProtocol;
use App\Support\MessageStreamService;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\NamespaceWorkflowScope;
use App\Support\PayloadCodecContract;
use App\Support\WorkflowCommandContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;

final class MessageStreamController
{
    public function __construct(
        private readonly MessageStreamService $streams,
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
        private readonly WorkflowCommandContextFactory $commandContexts,
    ) {}

    public function append(Request $request, string $workflowId, string $streamName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $this->validateStreamName($streamName);
        $namespace = (string) $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'accepted' => false,
                'workflow_id' => $workflowId,
                'stream_name' => $streamName,
                'outcome' => 'rejected',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'message_id' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'input' => ['nullable', 'array'],
            'request_id' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            $this->streams->recordMalformed(
                $namespace,
                $workflowId,
                $streamName,
                is_string($request->input('message_id')) ? $request->input('message_id') : null,
            );
            throw new ValidationException($validator);
        }
        $validated = $validator->validated();

        $storage = $this->externalPayloadStorage->driverFor($namespace);
        try {
            $envelope = AvroPayloadEnvelopeResolver::resolve($validated['input'] ?? null, 'input', $storage);
        } catch (ValidationException $exception) {
            $this->streams->recordMalformed(
                $namespace,
                $workflowId,
                $streamName,
                is_string($validated['message_id'] ?? null) ? $validated['message_id'] : null,
            );
            throw $exception;
        }
        $payloadCodec = is_string($envelope['codec'] ?? null)
            ? PayloadCodecContract::canonicalize($envelope['codec'])
            : PayloadCodecContract::CODEC;
        $payloadBlob = $this->storedPayloadEnvelope(
            $validated['input'] ?? null,
            $envelope,
            $payloadCodec,
        );
        $messageId = (string) $validated['message_id'];

        $result = $this->streams->append(
            $namespace,
            $workflowId,
            $streamName,
            $messageId,
            $payloadCodec,
            $payloadBlob,
            hash('sha256', $payloadCodec."\0".$payloadBlob),
            $this->commandContexts->make(
                $request,
                workflowId: $workflowId,
                commandName: 'message_stream_append',
                metadata: array_filter([
                    'stream_name' => $streamName,
                    'message_id' => $messageId,
                    'request_id' => $validated['request_id'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            ),
        );

        $status = (int) ($result['status'] ?? 500);
        unset($result['status']);

        return ControlPlaneProtocol::jsonForRequest($request, $result, $status);
    }

    public function index(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $streams = $this->streams->diagnostics($namespace, $workflowId);

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'count' => count($streams),
            'streams' => $streams,
        ]);
    }

    public function show(Request $request, string $workflowId, string $streamName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $this->validateStreamName($streamName);
        $namespace = (string) $request->attributes->get('namespace');
        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $streams = $this->streams->diagnostics($namespace, $workflowId, $streamName);
        if ($streams === []) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'workflow_id' => $workflowId,
                'stream_name' => $streamName,
                'reason' => 'message_stream_not_found',
            ], 404);
        }

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'stream' => $streams[0],
        ]);
    }

    private function validateStreamName(string $streamName): void
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $streamName)) {
            throw ValidationException::withMessages([
                'stream_name' => ['The stream name must contain 1-128 letters, numbers, periods, underscores, colons, or hyphens.'],
            ]);
        }
    }

    /**
     * Preserve a caller-supplied external reference after the resolver has
     * verified its codec, size, digest, and readability. Inline envelopes
     * retain their exact Avro bytes.
     *
     * @param  array<string, mixed>  $resolved
     */
    private function storedPayloadEnvelope(mixed $input, array $resolved, string $codec): string
    {
        if (is_array($input)) {
            $keys = array_keys($input);
            sort($keys);

            if ($keys === ['codec', 'external_storage'] && is_array($input['external_storage'])) {
                return ExternalPayloads::encodeStoredEnvelope([
                    'codec' => $codec,
                    'external_storage' => ExternalPayloadReference::fromArray($input['external_storage'])->toArray(),
                ]);
            }
        }

        return is_string($resolved['blob'] ?? null)
            ? $resolved['blob']
            : Serializer::serializeWithCodec($codec, []);
    }
}
