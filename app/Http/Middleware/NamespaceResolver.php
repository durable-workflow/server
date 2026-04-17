<?php

namespace App\Http\Middleware;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
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

        $namespace = strtolower((string) $namespace);

        $request->attributes->set('namespace', $namespace);

        if (! $this->requiresNamespaceValidation($request)) {
            return $next($request);
        }

        if (! WorkflowNamespace::query()->where('name', $namespace)->exists()) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => "Namespace '{$namespace}' does not exist.",
                'reason' => 'namespace_not_found',
                'namespace' => $namespace,
                'remediation' => 'Register the namespace via POST /api/namespaces, or send an X-Namespace header naming an existing namespace.',
            ], 404);
        }

        return $next($request);
    }

    /**
     * Requests that operate on the namespace resource itself, cluster-wide
     * introspection, or server health do not require a pre-existing namespace.
     */
    private function requiresNamespaceValidation(Request $request): bool
    {
        $path = trim($request->path(), '/');

        if ($path === 'api/health' || $path === 'api/cluster/info') {
            return false;
        }

        if ($path === 'api/namespaces' || str_starts_with($path, 'api/namespaces/')) {
            return false;
        }

        return true;
    }
}
