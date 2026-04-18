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

        $accepted = $this->acceptedEncodings($accept);

        if (($accepted['gzip'] ?? 0.0) > 0.0 && ($accepted['gzip'] ?? 0.0) >= ($accepted['deflate'] ?? 0.0)) {
            return 'gzip';
        }

        if (($accepted['deflate'] ?? 0.0) > 0.0) {
            return 'deflate';
        }

        return null;
    }

    /**
     * @return array{gzip?: float, deflate?: float}
     */
    private function acceptedEncodings(string $accept): array
    {
        $accepted = [];
        $wildcardQ = null;

        foreach (explode(',', strtolower($accept)) as $part) {
            $segments = array_map('trim', explode(';', $part));
            $encoding = array_shift($segments);

            if (! is_string($encoding) || $encoding === '') {
                continue;
            }

            $q = 1.0;

            foreach ($segments as $segment) {
                if (! str_starts_with($segment, 'q=')) {
                    continue;
                }

                $value = substr($segment, 2);
                $q = is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.0;
            }

            if ($encoding === '*') {
                $wildcardQ = $q;

                continue;
            }

            if ($encoding === 'gzip' || $encoding === 'deflate') {
                $accepted[$encoding] = max($accepted[$encoding] ?? 0.0, $q);
            }
        }

        if ($wildcardQ !== null) {
            $accepted['gzip'] ??= $wildcardQ;
            $accepted['deflate'] ??= $wildcardQ;
        }

        return $accepted;
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
