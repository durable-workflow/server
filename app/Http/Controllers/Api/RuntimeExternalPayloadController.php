<?php

namespace App\Http\Controllers\Api;

use App\Support\RuntimeExternalPayloadAudit;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadReference;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeExternalPayloadUploadBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RuntimeExternalPayloadController
{
    public function __construct(
        private readonly RuntimeExternalPayloadRegistry $registry,
        private readonly RuntimeExternalPayloadAudit $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $contentType = strtolower(trim(strtok((string) $request->header('Content-Type'), ';') ?: ''));
        if ($contentType !== 'application/octet-stream') {
            throw new RuntimeExternalPayloadException(
                'external_payload_unsupported',
                415,
                false,
                'Runtime external payload uploads require application/octet-stream.',
            );
        }

        $declaredSize = $this->declaredSize($request);
        $maxBytes = max(1, (int) config('server.external_payload_transport.max_payload_bytes'));
        if ($declaredSize > $maxBytes) {
            throw new RuntimeExternalPayloadException(
                'external_payload_oversized',
                413,
                false,
                'Declared external payload size exceeds the runtime transport limit.',
            );
        }

        $data = RuntimeExternalPayloadUploadBody::read($request, $maxBytes);
        $observedSize = strlen($data);
        if ($observedSize > $maxBytes) {
            throw new RuntimeExternalPayloadException(
                'external_payload_oversized',
                413,
                false,
                'External payload upload exceeds the runtime transport limit.',
            );
        }

        if ($observedSize !== $declaredSize) {
            throw new RuntimeExternalPayloadException(
                'external_payload_integrity_mismatch',
                422,
                false,
                'Declared external payload size does not match the uploaded bytes.',
            );
        }

        $namespace = (string) $request->attributes->get('namespace', config('server.default_namespace'));
        $reference = $this->registry->upload(
            $namespace,
            $data,
            (string) $request->header('X-Durable-Workflow-Payload-Codec'),
            strtolower((string) $request->header('X-Durable-Workflow-Payload-SHA256')),
        );

        $this->audit->record($request, 'external_payload.uploaded', [
            'reference_identity_sha256' => hash('sha256', $reference['reference_id']),
            'payload_sha256' => $reference['sha256'],
            'size_bytes' => $reference['size_bytes'],
        ]);

        return response()->json([
            'schema' => 'durable-workflow.v2.runtime-external-payload-upload.v1',
            'transport_version' => RuntimeExternalPayloadReference::TRANSPORT_VERSION,
            'reference' => $reference,
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $referenceId): Response
    {
        $namespace = (string) $request->attributes->get('namespace', config('server.default_namespace'));
        $result = $this->registry->fetch($namespace, [
            'schema' => RuntimeExternalPayloadReference::SCHEMA,
            'reference_id' => $referenceId,
            'codec' => (string) $request->header('X-Durable-Workflow-Payload-Codec'),
            'size_bytes' => $this->declaredSize($request),
            'sha256' => strtolower((string) $request->header('X-Durable-Workflow-Payload-SHA256')),
        ]);
        $reference = $result['reference'];

        $this->audit->record($request, 'external_payload.fetched', [
            'reference_identity_sha256' => hash('sha256', $referenceId),
            'payload_sha256' => $reference['sha256'],
            'size_bytes' => $reference['size_bytes'],
        ]);

        return response($result['data'], 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $reference['size_bytes'],
            'X-Durable-Workflow-Payload-Codec' => $reference['codec'],
            'X-Durable-Workflow-Payload-Size' => (string) $reference['size_bytes'],
            'X-Durable-Workflow-Payload-SHA256' => $reference['sha256'],
            'ETag' => '"sha256:'.$reference['sha256'].'"',
            'Cache-Control' => 'private, max-age=60, immutable',
        ]);
    }

    private function declaredSize(Request $request): int
    {
        $value = $request->header('X-Durable-Workflow-Payload-Size');
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            throw new RuntimeExternalPayloadException(
                'external_payload_unsupported',
                422,
                false,
                'X-Durable-Workflow-Payload-Size must declare a non-negative integer.',
            );
        }

        return (int) $value;
    }
}
