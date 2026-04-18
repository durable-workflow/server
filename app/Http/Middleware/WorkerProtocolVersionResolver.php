<?php

namespace App\Http\Middleware;

use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkerProtocolVersionResolver
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        return $next($request);
    }
}
