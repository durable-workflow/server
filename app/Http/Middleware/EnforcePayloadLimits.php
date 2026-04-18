<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePayloadLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('server.limits.max_payload_bytes', 2 * 1024 * 1024);

        $contentLength = $request->header('Content-Length');

        if ($contentLength !== null && (int) $contentLength > $maxBytes) {
            return $this->tooLarge($request, $maxBytes);
        }

        $bodySize = strlen($request->getContent());

        if ($bodySize > $maxBytes) {
            return $this->tooLarge($request, $maxBytes);
        }

        if ($this->methodCanHaveBody($request)
            && $this->hasBody($contentLength, $bodySize)
            && ! $this->usesJsonMediaType($request)) {
            return $this->unsupportedMediaType($request);
        }

        return $next($request);
    }

    private function tooLarge(Request $request, int $maxBytes): JsonResponse
    {
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

    private function unsupportedMediaType(Request $request): JsonResponse
    {
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
}
