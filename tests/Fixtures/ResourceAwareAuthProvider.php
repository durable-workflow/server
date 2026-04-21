<?php

namespace Tests\Fixtures;

use App\Auth\AuthException;
use App\Auth\Principal;
use App\Contracts\AuthProvider;
use Illuminate\Http\Request;

final class ResourceAwareAuthProvider implements AuthProvider
{
    /**
     * @var array<string, mixed>
     */
    public static array $lastResource = [];

    public static ?string $lastAction = null;

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

        return new Principal(
            subject: trim($subject),
            roles: $roles,
            method: 'test-resource',
            claims: array_filter([
                'allowed_namespace' => $this->header($request, 'X-Test-Allow-Namespace'),
                'denied_operation_family' => $this->header($request, 'X-Test-Deny-Operation-Family'),
                'denied_operation_name' => $this->header($request, 'X-Test-Deny-Operation-Name'),
                'denied_task_queue' => $this->header($request, 'X-Test-Deny-Task-Queue'),
                'denied_workflow_id' => $this->header($request, 'X-Test-Deny-Workflow-Id'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    public function authorize(Principal $principal, string $action, array $resource = []): bool
    {
        self::$lastAction = $action;
        self::$lastResource = $resource;

        if (! $this->hasAllowedRole($principal, $resource)) {
            return false;
        }

        $claims = $principal->claims();

        if (
            isset($claims['allowed_namespace'])
            && ($resource['requested_namespace'] ?? $resource['namespace'] ?? null) !== $claims['allowed_namespace']
        ) {
            return false;
        }

        if ($this->deniedResourceMatches($claims, $resource)) {
            return false;
        }

        return true;
    }

    public static function reset(): void
    {
        self::$lastAction = null;
        self::$lastResource = [];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $resource
     */
    private function deniedResourceMatches(array $claims, array $resource): bool
    {
        $configured = false;

        foreach ([
            'denied_operation_family' => 'operation_family',
            'denied_operation_name' => 'operation_name',
            'denied_task_queue' => 'task_queue',
            'denied_workflow_id' => 'workflow_id',
        ] as $claim => $field) {
            if (! isset($claims[$claim])) {
                continue;
            }

            $configured = true;

            if (($resource[$field] ?? null) !== $claims[$claim]) {
                return false;
            }
        }

        return $configured;
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function hasAllowedRole(Principal $principal, array $resource): bool
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

    private function header(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
