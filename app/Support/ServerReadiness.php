<?php

namespace App\Support;

use App\Models\WorkflowNamespace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ServerReadiness
{
    private const REQUIRED_TABLES = [
        'migrations',
        'workflow_namespaces',
        'workflow_instances',
        'workflow_runs',
        'workflow_tasks',
        'workflow_history_events',
        'workflow_worker_registrations',
        'search_attribute_definitions',
    ];

    public function __construct(
        private readonly ServerPollingCache $cache,
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

        return [
            'ready' => collect($checks)->every(
                static fn (array $check): bool => ($check['status'] ?? null) === 'ok',
            ),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
            $missing = array_values(array_filter(
                self::REQUIRED_TABLES,
                static fn (string $table): bool => ! Schema::hasTable($table),
            ));
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'message' => $exception->getMessage(),
            ];
        }

        if ($missing !== []) {
            return [
                'status' => 'missing',
                'missing_tables' => $missing,
                'remediation' => 'Run server-bootstrap before routing workers or SDKs to this server.',
            ];
        }

        return ['status' => 'ok'];
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

            return $token || $roleTokens !== []
                ? ['status' => 'ok', 'driver' => $driver]
                : [
                    'status' => 'missing',
                    'driver' => $driver,
                    'remediation' => 'Set DW_AUTH_TOKEN or role-scoped DW_WORKER_TOKEN/DW_OPERATOR_TOKEN/DW_ADMIN_TOKEN values.',
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
}
