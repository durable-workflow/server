<?php

namespace App\Http\Middleware;

use App\Contracts\AuthProvider;
use App\Support\ControlPlaneProtocol;
use App\Support\RouteAuthorizationResource;
use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function __construct(
        private readonly AuthProvider $authProvider,
        private readonly RouteAuthorizationResource $resourceBuilder,
    ) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = $this->allowedRoles($roles);

        if ($allowedRoles === []) {
            return self::error($request, 500, 'server_error', 'Route role requirement is not configured.');
        }

        $principal = Authenticate::principal($request);

        if ($principal === null) {
            return self::error($request, 401, 'unauthorized', 'Missing authenticated principal.');
        }

        if ($this->authProvider->authorize($principal, 'server.route.access', $this->resourceBuilder->make($request, $allowedRoles))) {
            return $next($request);
        }

        $role = $principal->primaryRole();

        return self::error($request, 403, 'forbidden', 'Authenticated role is not allowed to access this endpoint.', [
            'role' => is_string($role) && $role !== '' ? $role : null,
            'allowed_roles' => $allowedRoles,
        ]);
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function allowedRoles(array $roles): array
    {
        $allowed = [];

        foreach ($roles as $role) {
            foreach (explode(',', $role) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $allowed[] = $part;
                }
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function error(Request $request, int $status, string $reason, string $message, array $extra = []): JsonResponse
    {
        if (WorkerProtocol::isWorkerPlaneRequest($request)) {
            return WorkerProtocol::json(array_filter([
                'reason' => $reason,
                'message' => $message,
            ] + $extra, static fn (mixed $value): bool => $value !== null), $status);
        }

        return ControlPlaneProtocol::jsonForRequest($request, array_filter([
            'reason' => $reason,
            'message' => $message,
        ] + $extra, static fn (mixed $value): bool => $value !== null), $status);
    }
}
