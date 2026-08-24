<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use App\Support\RuntimeExternalPayloadAudit;
use App\Support\RuntimeExternalPayloadUploadBody;
use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePayloadLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        $externalPayloadUpload = $this->isExternalPayloadUpload($request);
        $maxBytes = $externalPayloadUpload
            ? (int) config('server.external_payload_transport.max_payload_bytes', 64 * 1024 * 1024)
            : (int) config('server.limits.max_payload_bytes', 2 * 1024 * 1024);

        $contentLength = $request->header('Content-Length');

        if ($contentLength !== null && (int) $contentLength > $maxBytes) {
            return $this->tooLarge($request, $maxBytes);
        }

        $body = $externalPayloadUpload
            ? RuntimeExternalPayloadUploadBody::read($request, $maxBytes)
            : $request->getContent();
        $bodySize = strlen($body);

        if ($bodySize > $maxBytes) {
            return $this->tooLarge($request, $maxBytes);
        }

        if ($this->methodCanHaveBody($request)
            && $this->hasBody($contentLength, $bodySize)) {
            if ($externalPayloadUpload && $this->usesOctetStreamMediaType($request)) {
                return $next($request);
            }

            if (! $this->usesJsonMediaType($request)) {
                return $this->unsupportedMediaType($request);
            }

            if (! $this->hasValidJsonBody($body)) {
                return $this->malformedJson($request);
            }
        }

        return $next($request);
    }

    private function tooLarge(Request $request, int $maxBytes): JsonResponse
    {
        if ($this->isExternalPayloadUpload($request)) {
            app(RuntimeExternalPayloadAudit::class)->record($request, 'external_payload.rejected', [
                'reason' => 'external_payload_oversized',
                'retryable' => false,
                'status' => 413,
            ]);

            return ControlPlaneProtocol::jsonForRequest($request, [
                'schema' => 'durable-workflow.v2.runtime-external-payload-error.v1',
                'message' => sprintf('External payload exceeds the maximum allowed size of %d bytes.', $maxBytes),
                'reason' => 'external_payload_oversized',
                'retryable' => false,
                'status' => 413,
                'limit' => $maxBytes,
            ], 413);
        }

        $payload = [
            'message' => sprintf(
                'Request payload exceeds the maximum allowed size of %d bytes.',
                $maxBytes,
            ),
            'reason' => 'payload_too_large',
            'limit' => $maxBytes,
        ];

        if (WorkerProtocol::isWorkerPlaneRequest($request)) {
            return WorkerProtocol::json($payload, 413);
        }

        return ControlPlaneProtocol::jsonForRequest($request, $payload, 413);
    }

    private function methodCanHaveBody(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function hasBody(?string $contentLength, int $bodySize): bool
    {
        if (is_numeric($contentLength)) {
            return (int) $contentLength > 0;
        }

        return $bodySize > 0;
    }

    private function usesJsonMediaType(Request $request): bool
    {
        $contentType = $request->headers->get('Content-Type');

        if (! is_string($contentType) || trim($contentType) === '') {
            return false;
        }

        $mediaType = strtolower(trim(strtok($contentType, ';') ?: ''));

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    private function usesOctetStreamMediaType(Request $request): bool
    {
        $contentType = $request->headers->get('Content-Type');

        return is_string($contentType)
            && strtolower(trim(strtok($contentType, ';') ?: '')) === 'application/octet-stream';
    }

    private function isExternalPayloadUpload(Request $request): bool
    {
        return $request->isMethod('POST') && $request->is('api/external-payloads/v1');
    }

    private function unsupportedMediaType(Request $request): JsonResponse
    {
        if ($this->isExternalPayloadUpload($request)) {
            app(RuntimeExternalPayloadAudit::class)->record($request, 'external_payload.rejected', [
                'reason' => 'external_payload_unsupported',
                'retryable' => false,
                'status' => 415,
            ]);

            return ControlPlaneProtocol::jsonForRequest($request, [
                'schema' => 'durable-workflow.v2.runtime-external-payload-error.v1',
                'message' => 'Runtime external payload uploads require application/octet-stream.',
                'reason' => 'external_payload_unsupported',
                'retryable' => false,
                'status' => 415,
                'accepted_content_types' => ['application/octet-stream'],
            ], 415);
        }

        $payload = [
            'message' => 'Request bodies must use a JSON media type.',
            'reason' => 'unsupported_media_type',
            'accepted_content_types' => ['application/json', 'application/*+json'],
        ];

        if (WorkerProtocol::isWorkerPlaneRequest($request)) {
            return WorkerProtocol::json($payload, 415);
        }

        return ControlPlaneProtocol::jsonForRequest($request, $payload, 415);
    }

    private function hasValidJsonBody(string $body): bool
    {
        json_decode($body);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function malformedJson(Request $request): JsonResponse
    {
        $payload = [
            'message' => 'Request bodies must contain valid JSON.',
            'reason' => 'malformed_json',
            'json_error' => json_last_error_msg(),
        ];

        if (WorkerProtocol::isWorkerPlaneRequest($request)) {
            return WorkerProtocol::json($payload, 400);
        }

        return ControlPlaneProtocol::jsonForRequest($request, $payload, 400);
    }
}
