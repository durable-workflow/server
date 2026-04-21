<?php

namespace App\Auth;

use App\Contracts\AuthProvider;
use Illuminate\Http\Request;

final class ConfiguredAuthProvider implements AuthProvider
{
    private const ROLE_WORKER = 'worker';

    private const ROLE_OPERATOR = 'operator';

    private const ROLE_ADMIN = 'admin';

    private const ROLES = [
        self::ROLE_WORKER,
        self::ROLE_OPERATOR,
        self::ROLE_ADMIN,
    ];

    public function authenticate(Request $request): Principal
    {
        $driver = (string) config('server.auth.driver', 'none');

        return match ($driver) {
            'none' => Principal::role(self::ROLE_ADMIN, 'none', legacyFullAccess: true, subject: 'anonymous'),
            'token' => $this->authenticateToken($request),
            'signature' => $this->authenticateSignature($request),
            default => throw AuthException::configuration("Unknown auth driver: {$driver}"),
        };
    }

    public function authorize(Principal $principal, string $action, array $resource = []): bool
    {
        if ($principal->legacyFullAccess()) {
            return true;
        }

        $allowedRoles = $resource['allowed_roles'] ?? [];

        if (! is_array($allowedRoles) || $allowedRoles === []) {
            return false;
        }

        foreach ($allowedRoles as $role) {
            if (is_string($role) && $principal->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function authenticateToken(Request $request): Principal
    {
        $token = config('server.auth.token');
        $roleTokens = $this->configuredRoleSecrets('server.auth.role_tokens');
        $hasRoleTokens = $roleTokens !== [];
        $hasLegacyToken = is_string($token) && $token !== '';
        $backwardCompatible = (bool) config('server.auth.backward_compatible', true);

        if (! $hasRoleTokens && (! $backwardCompatible || ! $hasLegacyToken)) {
            throw AuthException::configuration('Auth driver is set to "token" but DW_AUTH_TOKEN is not configured.');
        }

        $provided = $request->bearerToken();

        if (! $provided) {
            throw AuthException::unauthenticated('Invalid or missing authentication token.');
        }

        foreach ($roleTokens as $role => $secret) {
            if (hash_equals($secret, $provided)) {
                return Principal::role($role, 'token');
            }
        }

        if ($backwardCompatible && $hasLegacyToken && hash_equals($token, $provided)) {
            return Principal::role(
                self::ROLE_ADMIN,
                'token',
                legacyFullAccess: ! $hasRoleTokens,
                subject: 'legacy-token',
                claims: [
                    'legacy_credential' => true,
                ],
            );
        }

        throw AuthException::unauthenticated('Invalid or missing authentication token.');
    }

    private function authenticateSignature(Request $request): Principal
    {
        $key = config('server.auth.signature_key');
        $roleKeys = $this->configuredRoleSecrets('server.auth.role_signature_keys');
        $hasRoleKeys = $roleKeys !== [];
        $hasLegacyKey = is_string($key) && $key !== '';
        $backwardCompatible = (bool) config('server.auth.backward_compatible', true);

        if (! $hasRoleKeys && (! $backwardCompatible || ! $hasLegacyKey)) {
            throw AuthException::configuration('Auth driver is set to "signature" but DW_SIGNATURE_KEY is not configured.');
        }

        $signature = $request->header('X-Signature');

        if (! $signature) {
            throw AuthException::unauthenticated('Missing request signature.');
        }

        $body = $request->getContent();

        foreach ($roleKeys as $role => $secret) {
            $expected = hash_hmac('sha256', $body, $secret);

            if (hash_equals($expected, $signature)) {
                return Principal::role($role, 'signature');
            }
        }

        if ($backwardCompatible && $hasLegacyKey) {
            $expected = hash_hmac('sha256', $body, $key);

            if (hash_equals($expected, $signature)) {
                return Principal::role(
                    self::ROLE_ADMIN,
                    'signature',
                    legacyFullAccess: ! $hasRoleKeys,
                    subject: 'legacy-signature',
                    claims: [
                        'legacy_credential' => true,
                    ],
                );
            }
        }

        throw AuthException::unauthenticated('Invalid request signature.');
    }

    /**
     * @return array<string, string>
     */
    private function configuredRoleSecrets(string $configKey): array
    {
        $configured = config($configKey, []);

        if (! is_array($configured)) {
            return [];
        }

        $secrets = [];

        foreach (self::ROLES as $role) {
            $secret = $configured[$role] ?? null;

            if (is_string($secret) && $secret !== '') {
                $secrets[$role] = $secret;
            }
        }

        return $secrets;
    }
}
