<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceRequestAdmission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceNamespaceRequestAdmission
{
    public function __construct(
        private readonly NamespaceRequestAdmission $admission,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $namespace = (string) $request->attributes->get('namespace');

        return $this->admission->execute(
            $namespace,
            static fn (): Response => $next($request),
            static function (array $rejection) use ($request): Response {
                $reason = (string) $rejection['reason'];
                $response = ControlPlaneProtocol::jsonForRequest($request, [
                    'message' => match ($reason) {
                        'namespace_request_rate_exhausted' => 'The namespace request-rate budget is exhausted.',
                        'namespace_request_concurrency_exhausted' => 'The namespace concurrent-request budget is exhausted.',
                        default => 'Namespace request admission is temporarily unavailable.',
                    },
                    'reason' => $reason,
                    'retryable' => true,
                    'namespace' => $rejection['namespace'],
                    'limit' => $rejection['limit'],
                    'retry_after_seconds' => $rejection['retry_after_seconds'],
                ], (int) $rejection['status']);

                return $response->header('Retry-After', (string) $rejection['retry_after_seconds']);
            },
        );
    }
}
