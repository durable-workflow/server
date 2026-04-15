<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RemoveServerHeader
{
    /**
     * Remove X-Powered-By and other server implementation headers.
     *
     * The server is language-agnostic — polyglot SDKs should not see implementation details.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
