<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use App\Support\ServerReadiness;
use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkflowBootstrapReady
{
    public function __construct(
        private readonly ServerReadiness $readiness,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->readiness->bootstrapStatus();
        $blockedBy = is_array($status['blocked_by'] ?? null)
            ? array_values(array_filter($status['blocked_by'], static fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];

        if (($status['status'] ?? null) !== 'blocked' || $blockedBy === []) {
            return $next($request);
        }

        return self::error(
            $request,
            503,
            'workflow_v2_blocked',
            'This node is not ready to serve workflow v2 traffic until bootstrap blockers are cleared.',
            array_filter([
                'blocked_by' => $blockedBy,
                'remediation' => is_string($status['remediation'] ?? null) ? $status['remediation'] : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
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
