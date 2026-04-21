<?php

namespace Tests\Fixtures;

use App\Auth\AuthException;
use App\Auth\Principal;
use App\Contracts\AuthProvider;
use Illuminate\Http\Request;

final class HeaderAuthProvider implements AuthProvider
{
    public function authenticate(Request $request): Principal
    {
        $subject = $request->header('X-Test-Subject');

        if (! is_string($subject) || trim($subject) === '') {
            throw AuthException::unauthenticated('Missing test principal.');
        }

        $roles = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->header('X-Test-Roles', 'operator'))),
            static fn (string $role): bool => $role !== '',
        ));

        $tenant = $request->header('X-Test-Tenant');
        $traceId = $request->header('X-Test-Trace');

        return new Principal(
            subject: trim($subject),
            roles: $roles,
            method: 'test-header',
            tenant: is_string($tenant) && trim($tenant) !== '' ? trim($tenant) : null,
            claims: array_filter([
                'trace_id' => is_string($traceId) && trim($traceId) !== '' ? trim($traceId) : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function authorize(Principal $principal, string $action, array $resource = []): bool
    {
        $allowedRoles = $resource['allowed_roles'] ?? [];

        if (! is_array($allowedRoles)) {
            return false;
        }

        foreach ($allowedRoles as $role) {
            if (is_string($role) && $principal->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
