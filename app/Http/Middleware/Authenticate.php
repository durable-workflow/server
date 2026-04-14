<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $driver = config('server.auth.driver');

        if ($driver === 'none') {
            return $next($request);
        }

        if ($driver === 'token') {
            return $this->authenticateToken($request, $next);
        }

        if ($driver === 'signature') {
            return $this->authenticateSignature($request, $next);
        }

        return self::error(500, 'server_error', "Unknown auth driver: {$driver}");
    }

    protected function authenticateToken(Request $request, Closure $next): Response
    {
        $token = config('server.auth.token');

        if (! is_string($token) || $token === '') {
            return self::error(500, 'server_error', 'Auth driver is set to "token" but WORKFLOW_SERVER_AUTH_TOKEN is not configured.');
        }

        $provided = $request->bearerToken();

        if (! $provided || ! hash_equals($token, $provided)) {
            return self::error(401, 'unauthorized', 'Invalid or missing authentication token.');
        }

        return $next($request);
    }

    protected function authenticateSignature(Request $request, Closure $next): Response
    {
        $key = config('server.auth.signature_key');

        if (! is_string($key) || $key === '') {
            return self::error(500, 'server_error', 'Auth driver is set to "signature" but WORKFLOW_SERVER_SIGNATURE_KEY is not configured.');
        }

        $signature = $request->header('X-Signature');

        if (! $signature) {
            return self::error(401, 'unauthorized', 'Missing request signature.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $key);

        if (! hash_equals($expected, $signature)) {
            return self::error(401, 'unauthorized', 'Invalid request signature.');
        }

        return $next($request);
    }

    private static function error(int $status, string $error, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $error, 'message' => $message], $status);
    }
}
