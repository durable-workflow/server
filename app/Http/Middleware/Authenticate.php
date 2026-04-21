<?php

namespace App\Http\Middleware;

use App\Auth\AuthException;
use App\Auth\Principal;
use App\Contracts\AuthProvider;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    public const ATTRIBUTE_PRINCIPAL = 'auth.principal';

    public const ATTRIBUTE_ROLE = 'auth.role';

    public const ATTRIBUTE_METHOD = 'auth.method';

    public const ATTRIBUTE_LEGACY_FULL_ACCESS = 'auth.legacy_full_access';

    public function __construct(
        private readonly AuthProvider $authProvider,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $principal = $this->authProvider->authenticate($request);
        } catch (AuthException $exception) {
            return self::error($request, $exception->status(), $exception->reason(), $exception->getMessage());
        }

        $this->setPrincipalAttributes($request, $principal);

        return $next($request);
    }

    public static function principal(Request $request): ?Principal
    {
        $principal = $request->attributes->get(self::ATTRIBUTE_PRINCIPAL);

        return $principal instanceof Principal
            ? $principal
            : null;
    }

    private function setPrincipalAttributes(Request $request, Principal $principal): void
    {
        $request->attributes->set(self::ATTRIBUTE_PRINCIPAL, $principal);
        $request->attributes->set(self::ATTRIBUTE_ROLE, $principal->primaryRole());
        $request->attributes->set(self::ATTRIBUTE_METHOD, $principal->method());
        $request->attributes->set(self::ATTRIBUTE_LEGACY_FULL_ACCESS, $principal->legacyFullAccess());
    }

    private static function error(Request $request, int $status, string $reason, string $message): JsonResponse
    {
        if (WorkerProtocol::isWorkerPlaneRequest($request)) {
            return WorkerProtocol::json([
                'reason' => $reason,
                'message' => $message,
            ], $status);
        }

        return ControlPlaneProtocol::jsonForRequest($request, [
            'reason' => $reason,
            'message' => $message,
        ], $status);
    }
}
