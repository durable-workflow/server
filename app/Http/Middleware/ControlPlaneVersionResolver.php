<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the X-Durable-Workflow-Control-Plane-Version contract on
 * version-gated control-plane routes, running before NamespaceResolver so a
 * missing or unsupported version header wins over namespace_not_found when
 * both conditions apply (TD-S050).
 */
class ControlPlaneVersionResolver
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        return $next($request);
    }
}
