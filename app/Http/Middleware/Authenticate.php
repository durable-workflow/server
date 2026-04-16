<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    public const ATTRIBUTE_ROLE = 'auth.role';

    public const ATTRIBUTE_METHOD = 'auth.method';

    public const ATTRIBUTE_LEGACY_FULL_ACCESS = 'auth.legacy_full_access';

    private const ROLE_WORKER = 'worker';

    private const ROLE_OPERATOR = 'operator';

    private const ROLE_ADMIN = 'admin';

    private const ROLES = [
        self::ROLE_WORKER,
        self::ROLE_OPERATOR,
        self::ROLE_ADMIN,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $driver = config('server.auth.driver');

        if ($driver === 'none') {
            return $this->authorize($request, $next, self::ROLE_ADMIN, 'none', legacyFullAccess: true);
        }

        if ($driver === 'token') {
            return $this->authenticateToken($request, $next);
        }

        if ($driver === 'signature') {
            return $this->authenticateSignature($request, $next);
        }

        return self::error($request, 500, 'server_error', "Unknown auth driver: {$driver}");
    }

    protected function authenticateToken(Request $request, Closure $next): Response
    {
        $token = config('server.auth.token');
        $roleTokens = $this->configuredRoleSecrets('server.auth.role_tokens');
        $hasRoleTokens = $roleTokens !== [];
        $hasLegacyToken = is_string($token) && $token !== '';
        $backwardCompatible = (bool) config('server.auth.backward_compatible', true);

        if (! $hasRoleTokens && (! $backwardCompatible || ! $hasLegacyToken)) {
            return self::error($request, 500, 'server_error', 'Auth driver is set to "token" but WORKFLOW_SERVER_AUTH_TOKEN is not configured.');
        }

        $provided = $request->bearerToken();

        if (! $provided) {
            return self::error($request, 401, 'unauthorized', 'Invalid or missing authentication token.');
        }

        foreach ($roleTokens as $role => $secret) {
            if (hash_equals($secret, $provided)) {
                return $this->authorize($request, $next, $role, 'token');
            }
        }

        if ($backwardCompatible && $hasLegacyToken && hash_equals($token, $provided)) {
            return $this->authorize(
                $request,
                $next,
                self::ROLE_ADMIN,
                'token',
                legacyFullAccess: ! $hasRoleTokens,
            );
        }

        return self::error($request, 401, 'unauthorized', 'Invalid or missing authentication token.');
    }

    protected function authenticateSignature(Request $request, Closure $next): Response
    {
        $key = config('server.auth.signature_key');
        $roleKeys = $this->configuredRoleSecrets('server.auth.role_signature_keys');
        $hasRoleKeys = $roleKeys !== [];
        $hasLegacyKey = is_string($key) && $key !== '';
        $backwardCompatible = (bool) config('server.auth.backward_compatible', true);

        if (! $hasRoleKeys && (! $backwardCompatible || ! $hasLegacyKey)) {
            return self::error($request, 500, 'server_error', 'Auth driver is set to "signature" but WORKFLOW_SERVER_SIGNATURE_KEY is not configured.');
        }

        $signature = $request->header('X-Signature');

        if (! $signature) {
            return self::error($request, 401, 'unauthorized', 'Missing request signature.');
        }

        $body = $request->getContent();

        foreach ($roleKeys as $role => $secret) {
            $expected = hash_hmac('sha256', $body, $secret);

            if (hash_equals($expected, $signature)) {
                return $this->authorize($request, $next, $role, 'signature');
            }
        }

        if ($backwardCompatible && $hasLegacyKey) {
            $expected = hash_hmac('sha256', $body, $key);

            if (hash_equals($expected, $signature)) {
                return $this->authorize(
                    $request,
                    $next,
                    self::ROLE_ADMIN,
                    'signature',
                    legacyFullAccess: ! $hasRoleKeys,
                );
            }
        }

        return self::error($request, 401, 'unauthorized', 'Invalid request signature.');
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

    private function authorize(
        Request $request,
        Closure $next,
        string $role,
        string $method,
        bool $legacyFullAccess = false,
    ): Response {
        $request->attributes->set(self::ATTRIBUTE_ROLE, $role);
        $request->attributes->set(self::ATTRIBUTE_METHOD, $method);
        $request->attributes->set(self::ATTRIBUTE_LEGACY_FULL_ACCESS, $legacyFullAccess);

        return $next($request);
    }

    private static function error(Request $request, int $status, string $reason, string $message): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, [
            'reason' => $reason,
            'message' => $message,
        ], $status);
    }
}
