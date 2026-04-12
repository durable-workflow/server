<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompressResponse
{
    /**
     * Minimum response body size (bytes) before compression is attempted.
     * Compressing tiny payloads adds CPU cost for negligible bandwidth savings.
     */
    private const MIN_COMPRESS_BYTES = 1024;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || strlen($content) < self::MIN_COMPRESS_BYTES) {
            return $response;
        }

        $encoding = $this->preferredEncoding($request);

        if ($encoding === null) {
            return $response;
        }

        $compressed = $this->compress($content, $encoding);

        if ($compressed === null) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', $encoding);
        $response->headers->set('Vary', 'Accept-Encoding');
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if (! config('server.compression.enabled', true)) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        if (! $response instanceof JsonResponse && ! $this->isJsonContentType($response)) {
            return false;
        }

        return true;
    }

    private function preferredEncoding(Request $request): ?string
    {
        $accept = $request->headers->get('Accept-Encoding', '');

        if (! is_string($accept)) {
            return null;
        }

        $accept = strtolower($accept);

        if (str_contains($accept, 'gzip')) {
            return 'gzip';
        }

        if (str_contains($accept, 'deflate')) {
            return 'deflate';
        }

        return null;
    }

    private function compress(string $content, string $encoding): ?string
    {
        if ($encoding === 'gzip') {
            $result = gzencode($content, 6);

            return is_string($result) ? $result : null;
        }

        if ($encoding === 'deflate') {
            $result = gzdeflate($content, 6);

            return is_string($result) ? $result : null;
        }

        return null;
    }

    private function isJsonContentType(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return is_string($contentType) && str_contains($contentType, 'application/json');
    }
}
