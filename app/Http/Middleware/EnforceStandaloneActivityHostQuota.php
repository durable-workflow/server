<?php

namespace App\Http\Middleware;

use App\Support\NamespaceDurableStateQuota;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class EnforceStandaloneActivityHostQuota
{
    public function __construct(
        private readonly NamespaceDurableStateQuota $quota,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $namespace = $request->attributes->get('namespace');

        if (! is_string($namespace) || trim($namespace) === '') {
            throw new InvalidArgumentException('Standalone activity quota admission requires a namespace.');
        }

        return $this->quota->mutate(
            $namespace,
            [
                NamespaceDurableStateQuota::WORKFLOW_INSTANCES,
                NamespaceDurableStateQuota::WORKFLOW_RUNS,
                NamespaceDurableStateQuota::OPEN_WORKFLOW_RUNS,
            ],
            fn (): Response => $next($request),
        );
    }
}
