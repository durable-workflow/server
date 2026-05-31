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
        $principalTokens = $this->configuredPrincipalTokens();
        $hasPrincipalTokens = $principalTokens !== [];
        $hasRoleTokens = $roleTokens !== [];
        $hasLegacyToken = is_string($token) && $token !== '';
        $backwardCompatible = (bool) config('server.auth.backward_compatible', true);

        if (! $hasPrincipalTokens && ! $hasRoleTokens && (! $backwardCompatible || ! $hasLegacyToken)) {
            throw AuthException::configuration('Auth driver is set to "token" but DW_AUTH_TOKEN or DW_PRINCIPAL_TOKENS is not configured.');
        }

        $provided = $request->bearerToken();

        if (! $provided) {
            throw AuthException::unauthenticated('Invalid or missing authentication token.');
        }

        foreach ($principalTokens as $principalToken) {
            if (hash_equals($principalToken['token'], $provided)) {
                return new Principal(
                    subject: $principalToken['subject'],
                    roles: $principalToken['roles'],
                    method: 'token',
                    tenant: $principalToken['tenant'],
                    claims: $principalToken['claims'],
                );
            }
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
                legacyFullAccess: ! ($hasRoleTokens || $hasPrincipalTokens),
                subject: 'legacy-token',
                claims: [
                    'legacy_credential' => true,
                ],
            );
        }

        throw AuthException::unauthenticated('Invalid or missing authentication token.');
    }

    /**
     * @return list<array{token: string, subject: string, roles: list<string>, tenant: ?string, claims: array<string, mixed>}>
     */
    public static function parsePrincipalTokens(mixed $configured): array
    {
        if ($configured === null || $configured === '') {
            return [];
        }

        if (is_string($configured)) {
            $decoded = json_decode($configured, true);

            if (! is_array($decoded)) {
                throw AuthException::configuration('DW_PRINCIPAL_TOKENS must be valid JSON.');
            }

            $configured = $decoded;
        }

        if (! is_array($configured)) {
            throw AuthException::configuration('DW_PRINCIPAL_TOKENS must decode to a JSON object or array.');
        }

        $entries = array_is_list($configured)
            ? $configured
            : self::principalTokenEntriesFromMap($configured);

        $tokens = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw AuthException::configuration("DW_PRINCIPAL_TOKENS entry {$index} must be an object.");
            }

            $token = self::requiredString($entry, 'token', "DW_PRINCIPAL_TOKENS entry {$index}");
            $subject = self::requiredString($entry, 'subject', "DW_PRINCIPAL_TOKENS entry {$index}");
            $roles = self::roles($entry['roles'] ?? ($entry['role'] ?? null), "DW_PRINCIPAL_TOKENS entry {$index}");
            $tenant = self::optionalString($entry['tenant'] ?? null);
            $claims = array_key_exists('claims', $entry)
                ? self::claims($entry['claims'], "DW_PRINCIPAL_TOKENS entry {$index}")
                : [];
            $label = self::optionalString($entry['label'] ?? null);

            if ($label !== null && ! array_key_exists('display_name', $claims)) {
                $claims['display_name'] = $label;
            }

            $tokens[] = [
                'token' => $token,
                'subject' => $subject,
                'roles' => $roles,
                'tenant' => $tenant,
                'claims' => $claims,
            ];
        }

        return $tokens;
    }

    /**
     * @return list<array{token: string, subject: string, roles: list<string>, tenant: ?string, claims: array<string, mixed>}>
     */
    private function configuredPrincipalTokens(): array
    {
        return self::parsePrincipalTokens(config('server.auth.principal_tokens'));
    }

    /**
     * @param  array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private static function principalTokenEntriesFromMap(array $map): array
    {
        $entries = [];

        $position = 0;

        foreach ($map as $token => $entry) {
            if (! is_string($token) || trim($token) === '') {
                throw AuthException::configuration('DW_PRINCIPAL_TOKENS object keys must be non-empty token strings.');
            }

            if (! is_array($entry)) {
                throw AuthException::configuration("DW_PRINCIPAL_TOKENS map entry {$position} must be an object.");
            }

            $entry['token'] = $token;
            $entries[] = $entry;
            $position++;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function requiredString(array $entry, string $field, string $context): string
    {
        $value = $entry[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw AuthException::configuration("{$context} must define a non-empty {$field} string.");
        }

        return trim($value);
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @return list<string>
     */
    private static function roles(mixed $value, string $context): array
    {
        if (is_string($value)) {
            $roles = preg_split('/[\s,]+/', $value) ?: [];
        } elseif (is_array($value)) {
            $roles = array_values(array_filter($value, static fn (mixed $role): bool => is_string($role)));
        } else {
            $roles = [];
        }

        $normalized = [];

        foreach ($roles as $role) {
            $role = trim((string) $role);

            if ($role === '') {
                continue;
            }

            if (! in_array($role, self::ROLES, true)) {
                throw AuthException::configuration("{$context} has unsupported role {$role}.");
            }

            $normalized[] = $role;
        }

        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            throw AuthException::configuration("{$context} must define at least one role.");
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function claims(mixed $value, string $context): array
    {
        if (! is_array($value)) {
            throw AuthException::configuration("{$context} claims must be an object.");
        }

        if ($value !== [] && array_is_list($value)) {
            throw AuthException::configuration("{$context} claims must be a JSON object, not a list.");
        }

        return $value;
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
