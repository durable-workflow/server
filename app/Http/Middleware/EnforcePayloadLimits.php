<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePayloadLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('server.limits.max_payload_bytes', 2 * 1024 * 1024);

        $contentLength = $request->header('Content-Length');

        if ($contentLength !== null && (int) $contentLength > $maxBytes) {
            return response()->json([
                'message' => sprintf(
                    'Request payload exceeds the maximum allowed size of %d bytes.',
                    $maxBytes,
                ),
                'reason' => 'payload_too_large',
                'limit' => $maxBytes,
            ], 413);
        }

        $bodySize = strlen($request->getContent());

        if ($bodySize > $maxBytes) {
            return response()->json([
                'message' => sprintf(
                    'Request payload exceeds the maximum allowed size of %d bytes.',
                    $maxBytes,
                ),
                'reason' => 'payload_too_large',
                'limit' => $maxBytes,
            ], 413);
        }

        return $next($request);
    }
}
