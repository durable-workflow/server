<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use App\Support\ServerTopology;
use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTopologyRoles
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $requiredRoles = $this->requiredRoles($roles);

        if ($requiredRoles === []) {
            return self::error($request, 500, 'server_error', 'Route topology requirement is not configured.');
        }

        $currentNode = ServerTopology::currentNode();
        $currentRoles = $currentNode['roles'];
        $missingRoles = array_values(array_diff($requiredRoles, $currentRoles));

        if ($missingRoles === []) {
            return $next($request);
        }

        return self::error(
            $request,
            503,
            'topology_role_unavailable',
            'This node does not host the topology roles required for this endpoint.',
            [
                'current_shape' => $currentNode['shape'],
                'current_process_class' => $currentNode['process_class'],
                'current_roles' => $currentRoles,
                'required_roles' => $requiredRoles,
                'missing_roles' => $missingRoles,
            ],
        );
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function requiredRoles(array $roles): array
    {
        $required = [];

        foreach ($roles as $role) {
            foreach (explode(',', $role) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $required[] = $part;
                }
            }
        }

        return array_values(array_unique($required));
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
