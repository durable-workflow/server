<?php

namespace App\Support;

use App\Auth\AuthException;
use App\Auth\ConfiguredAuthProvider;
use App\Contracts\AuthProvider;
use App\Models\WorkflowNamespace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\ReadinessContract;
use Workflow\V2\Support\WaterlineEngineSource;

final class ServerReadiness
{
    public function __construct(
        private readonly ServerPollingCache $cache,
        private readonly MigrationAdoption $migrationAdoption,
    ) {}

    /**
     * @return array{ready: bool, checks: array<string, array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'migrations' => $this->migrationCheck(),
            'default_namespace' => $this->defaultNamespaceCheck(),
            'cache' => $this->cacheCheck(),
            'auth' => $this->authCheck(),
        ];
        $checks['workflow_v2'] = $this->workflowStatus($checks);

        return [
            'ready' => collect($checks)->every(
                static fn (array $check): bool => self::statusAllowsReady($check['status'] ?? null),
            ),
            'checks' => $checks,
        ];
    }

    private static function statusAllowsReady(mixed $status): bool
    {
        return in_array($status, ['ok', 'warning'], true);
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $checks
     * @return array<string, mixed>
     */
    public function workflowStatus(?array $checks = null): array
    {
        $checks ??= [
            'database' => $this->databaseCheck(),
            'migrations' => $this->migrationCheck(),
        ];

        return $this->normalizeWorkflowCheck($this->workflowCheck($checks));
    }

    private function databaseCheck(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok'];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function migrationCheck(): array
    {
        try {
            $inspection = $this->migrationAdoption->inspect();
            $contract = ReadinessContract::definition();
            $operatorSurface = WaterlineEngineSource::status();
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => $exception->getMessage(),
            ];
        }

        $operatorSurfaceMissingTables = $this->operatorSurfaceMissingTables($operatorSurface['required_tables'] ?? []);
        $blockingMissingTables = $this->migrationMissingTables($inspection['blocking_migrations'] ?? []);
        $missingTables = array_values(array_unique(array_merge(
            $blockingMissingTables,
            $operatorSurfaceMissingTables,
            ($inspection['repository_exists'] ?? false) ? [] : ['migrations'],
        )));
        $check = [
            'repository_exists' => (bool) ($inspection['repository_exists'] ?? false),
            'pending_migrations' => is_array($inspection['pending_migrations'] ?? null)
                ? array_values($inspection['pending_migrations'])
                : [],
            'adoptable_migrations' => $this->stringList($inspection['adoptable_migrations'] ?? []),
            'blocking_migrations' => is_array($inspection['blocking_migrations'] ?? null)
                ? array_values($inspection['blocking_migrations'])
                : [],
            'missing_tables' => $missingTables,
            'operator_surface' => [
                'authority' => $contract['surfaces']['boot_install']['authority'] ?? WaterlineEngineSource::class.'::status',
                'readiness_key' => $contract['surfaces']['boot_install']['readiness_key'] ?? 'v2_operator_surface_available',
                'available' => (bool) ($operatorSurface['v2_operator_surface_available'] ?? false),
                'required_tables' => is_array($operatorSurface['required_tables'] ?? null)
                    ? array_values($operatorSurface['required_tables'])
                    : [],
                'issues' => is_array($operatorSurface['issues'] ?? null)
                    ? array_values($operatorSurface['issues'])
                    : [],
            ],
            'readiness_contract' => [
                'version' => is_int($contract['version'] ?? null) ? $contract['version'] : null,
                'release_state' => is_string($contract['release_state'] ?? null) ? $contract['release_state'] : null,
            ],
        ];

        if ($missingTables !== []) {
            return $check + [
                'status' => 'missing',
                'remediation' => 'Run server-bootstrap before routing workers or SDKs to this server.',
            ];
        }

        if (($inspection['blocking_migrations'] ?? []) !== []) {
            return $check + [
                'status' => 'pending',
                'remediation' => 'Run server-bootstrap before routing workers or SDKs to this server.',
            ];
        }

        if (($inspection['adoptable_migrations'] ?? []) !== []) {
            return $check + [
                'status' => 'warning',
                'remediation' => 'Run server-bootstrap to adopt existing workflow tables into migration history before the next migrate pass.',
            ];
        }

        return $check + ['status' => 'ok'];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultNamespaceCheck(): array
    {
        try {
            if (! Schema::hasTable('workflow_namespaces')) {
                return [
                    'status' => 'missing',
                    'namespace' => (string) config('server.default_namespace', 'default'),
                    'remediation' => 'Run server-bootstrap to migrate and seed the default namespace.',
                ];
            }

            $namespace = (string) config('server.default_namespace', 'default');

            if (! WorkflowNamespace::query()->where('name', $namespace)->exists()) {
                return [
                    'status' => 'missing',
                    'namespace' => $namespace,
                    'remediation' => 'Run server-bootstrap to seed the default namespace.',
                ];
            }

            return [
                'status' => 'ok',
                'namespace' => $namespace,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cacheCheck(): array
    {
        try {
            $key = 'server:readiness:'.bin2hex(random_bytes(8));
            $value = bin2hex(random_bytes(8));
            $store = $this->cache->store();
            $store->put($key, $value, 10);
            $read = $store->get($key);
            $store->forget($key);

            if ($read !== $value) {
                return [
                    'status' => 'unavailable',
                    'store' => (string) config('cache.default'),
                    'message' => 'Cache store did not round-trip the readiness probe value.',
                ];
            }

            return [
                'status' => 'ok',
                'store' => (string) config('cache.default'),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'store' => (string) config('cache.default'),
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function authCheck(): array
    {
        $provider = config('server.auth.provider');

        if (is_string($provider) && trim($provider) !== '') {
            $provider = trim($provider);

            try {
                $instance = app()->make($provider);
            } catch (\Throwable $exception) {
                return [
                    'status' => 'invalid',
                    'driver' => 'custom',
                    'provider' => $provider,
                    'message' => $exception->getMessage(),
                    'remediation' => 'Set DW_AUTH_PROVIDER to a Laravel-resolvable class implementing App\Contracts\AuthProvider.',
                ];
            }

            if (! $instance instanceof AuthProvider) {
                return [
                    'status' => 'invalid',
                    'driver' => 'custom',
                    'provider' => $provider,
                    'remediation' => 'Set DW_AUTH_PROVIDER to a Laravel-resolvable class implementing App\Contracts\AuthProvider.',
                ];
            }

            return [
                'status' => 'ok',
                'driver' => 'custom',
                'provider' => $provider,
            ];
        }

        $driver = (string) config('server.auth.driver', 'token');

        if ($driver === 'none') {
            return [
                'status' => 'ok',
                'driver' => $driver,
            ];
        }

        if ($driver === 'token') {
            $token = config('server.auth.token');
            $roleTokens = array_filter((array) config('server.auth.role_tokens', []));
            $backwardCompatible = (bool) config('server.auth.backward_compatible', true);

            try {
                $principalTokens = ConfiguredAuthProvider::parsePrincipalTokens(
                    config('server.auth.principal_tokens'),
                );
            } catch (AuthException $exception) {
                return [
                    'status' => 'invalid',
                    'driver' => $driver,
                    'message' => $exception->getMessage(),
                    'remediation' => 'Set DW_PRINCIPAL_TOKENS to valid JSON named-principal token entries, or clear it and configure DW_AUTH_TOKEN or role-scoped DW_WORKER_TOKEN/DW_OPERATOR_TOKEN/DW_ADMIN_TOKEN values.',
                ];
            }

            $hasLegacyToken = is_string($token) && $token !== '';
            $hasRoleTokens = $roleTokens !== [];
            $hasPrincipalTokens = $principalTokens !== [];

            return ($backwardCompatible && $hasLegacyToken) || $hasRoleTokens || $hasPrincipalTokens
                ? ['status' => 'ok', 'driver' => $driver]
                : [
                    'status' => 'missing',
                    'driver' => $driver,
                    'remediation' => 'Set DW_AUTH_TOKEN, DW_PRINCIPAL_TOKENS, or role-scoped DW_WORKER_TOKEN/DW_OPERATOR_TOKEN/DW_ADMIN_TOKEN values.',
                ];
        }

        if ($driver === 'signature') {
            $key = config('server.auth.signature_key');
            $roleKeys = array_filter((array) config('server.auth.role_signature_keys', []));

            return $key || $roleKeys !== []
                ? ['status' => 'ok', 'driver' => $driver]
                : [
                    'status' => 'missing',
                    'driver' => $driver,
                    'remediation' => 'Set DW_SIGNATURE_KEY or role-scoped DW_WORKER_SIGNATURE_KEY/DW_OPERATOR_SIGNATURE_KEY/DW_ADMIN_SIGNATURE_KEY values.',
                ];
        }

        return [
            'status' => 'invalid',
            'driver' => $driver,
            'remediation' => 'Set DW_AUTH_DRIVER to none, token, or signature.',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function workflowCheck(array $checks): array
    {
        $blockedBy = [];

        foreach (['database', 'migrations'] as $key) {
            if (! self::statusAllowsReady($checks[$key]['status'] ?? null)) {
                $blockedBy[] = $key;
            }
        }

        if ($blockedBy !== []) {
            return [
                'status' => 'blocked',
                'blocked_by' => $blockedBy,
                'remediation' => 'Restore database connectivity and migrate the workflow tables before relying on workflow v2 rollout-safety health.',
            ];
        }

        try {
            $snapshot = HealthCheck::snapshot();
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => $exception->getMessage(),
            ];
        }

        $status = (string) ($snapshot['status'] ?? 'error');
        $checksList = [];
        $warningChecks = [];
        $errorChecks = [];
        $fleetValidationMode = config('workflows.v2.fleet.validation_mode', 'warn');

        foreach (is_array($snapshot['checks'] ?? null) ? $snapshot['checks'] : [] as $check) {
            if (! is_array($check)) {
                continue;
            }

            $entry = [
                'name' => is_string($check['name'] ?? null) ? $check['name'] : 'unknown',
                'status' => is_string($check['status'] ?? null) ? $check['status'] : 'unknown',
                'category' => is_string($check['category'] ?? null) ? $check['category'] : null,
                'message' => is_string($check['message'] ?? null) ? $check['message'] : null,
            ];
            if (
                $fleetValidationMode === 'fail'
                && $entry['name'] === 'worker_compatibility'
                && $entry['status'] === 'warning'
            ) {
                $entry['status'] = 'error';
            }
            $checksList[] = $entry;

            if ($entry['status'] === 'warning') {
                $warningChecks[] = $entry['name'];
            }

            if ($entry['status'] === 'error') {
                $errorChecks[] = $entry['name'];
            }
        }

        if ($errorChecks !== []) {
            $status = 'error';
        } elseif ($warningChecks !== []) {
            $status = 'warning';
        } else {
            $status = 'ok';
        }

        return [
            'status' => in_array($status, ['ok', 'warning'], true) ? $status : 'error',
            'generated_at' => is_string($snapshot['generated_at'] ?? null) ? $snapshot['generated_at'] : null,
            'http_status' => $status === 'error' ? 503 : 200,
            'categories' => is_array($snapshot['categories'] ?? null) ? $snapshot['categories'] : [],
            'warning_checks' => $warningChecks,
            'error_checks' => $errorChecks,
            'checks' => $checksList,
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function normalizeWorkflowCheck(array $check): array
    {
        $status = is_string($check['status'] ?? null) ? $check['status'] : 'error';

        $normalized = [
            'status' => $status,
            'generated_at' => is_string($check['generated_at'] ?? null) ? $check['generated_at'] : null,
            'http_status' => is_int($check['http_status'] ?? null)
                ? $check['http_status']
                : (self::statusAllowsReady($status) ? 200 : 503),
            'categories' => is_array($check['categories'] ?? null) ? $check['categories'] : [],
            'warning_checks' => $this->stringList($check['warning_checks'] ?? []),
            'error_checks' => $this->stringList($check['error_checks'] ?? []),
            'checks' => is_array($check['checks'] ?? null) ? array_values($check['checks']) : [],
        ];

        foreach (['blocked_by', 'message', 'remediation'] as $key) {
            if (array_key_exists($key, $check)) {
                $normalized[$key] = $check[$key];
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $value
     * @return list<string>
     */
    private function migrationMissingTables(array $value): array
    {
        $tables = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($this->stringList($entry['missing_tables'] ?? []) as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return list<string>
     */
    private function operatorSurfaceMissingTables(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $missing = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['available'] ?? false) === true) {
                continue;
            }

            $table = $entry['table'] ?? null;

            if (is_string($table) && $table !== '') {
                $missing[] = $table;
            }
        }

        return array_values(array_unique($missing));
    }
}
