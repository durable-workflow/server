<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NamespaceResolver
{
    public function handle(Request $request, Closure $next): Response
    {
        $namespace = $request->header(
            'X-Namespace',
            $request->query('namespace', config('server.default_namespace'))
        );

        $request->attributes->set('namespace', strtolower((string) $namespace));

        return $next($request);
    }
}
