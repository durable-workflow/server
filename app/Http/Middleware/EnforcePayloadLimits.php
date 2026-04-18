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
}
