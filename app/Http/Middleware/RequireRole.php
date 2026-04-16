<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = $this->allowedRoles($roles);

        if ($allowedRoles === []) {
            return self::error($request, 500, 'server_error', 'Route role requirement is not configured.');
        }

        if ((string) config('server.auth.driver', 'none') === 'none') {
            return $next($request);
        }

        if ($request->attributes->get(Authenticate::ATTRIBUTE_LEGACY_FULL_ACCESS) === true) {
            return $next($request);
        }

        $role = $request->attributes->get(Authenticate::ATTRIBUTE_ROLE);

        if (is_string($role) && in_array($role, $allowedRoles, true)) {
            return $next($request);
        }

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
        return ControlPlaneProtocol::jsonForRequest($request, array_filter([
            'reason' => $reason,
            'message' => $message,
        ] + $extra, static fn (mixed $value): bool => $value !== null), $status);
    }
}
