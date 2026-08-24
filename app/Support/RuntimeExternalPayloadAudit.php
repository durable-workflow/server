<?php

namespace App\Support;

use App\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RuntimeExternalPayloadAudit
{
    /** @param array<string, int|string|bool|null> $context */
    public function record(Request $request, string $event, array $context = []): void
    {
        $principal = Authenticate::principal($request);
        $namespace = (string) $request->attributes->get('namespace', '');
        if ($namespace === '') {
            $namespace = strtolower((string) $request->header(
                'X-Namespace',
                config('server.default_namespace'),
            ));
        }

        Log::info('Runtime external payload audit event.', array_filter([
            'event' => $event,
            'namespace' => $namespace,
            'role' => $request->attributes->get(Authenticate::ATTRIBUTE_ROLE),
            'principal_subject_sha256' => $principal !== null
                ? hash('sha256', $principal->subject())
                : null,
        ] + $context, static fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
