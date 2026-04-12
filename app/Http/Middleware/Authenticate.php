<?php

namespace App\Http\Middleware;

use Closure;
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

        abort(500, "Unknown auth driver: {$driver}");
    }

    protected function authenticateToken(Request $request, Closure $next): Response
    {
        $token = config('server.auth.token');

        if (! $token) {
            return $next($request);
        }

        $provided = $request->bearerToken();

        if (! $provided || ! hash_equals($token, $provided)) {
            abort(401, 'Invalid or missing authentication token.');
        }

        return $next($request);
    }

    protected function authenticateSignature(Request $request, Closure $next): Response
    {
        $key = config('server.auth.signature_key');

        if (! $key) {
            return $next($request);
        }

        $signature = $request->header('X-Signature');

        if (! $signature) {
            abort(401, 'Missing request signature.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $key);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid request signature.');
        }

        return $next($request);
    }
}
