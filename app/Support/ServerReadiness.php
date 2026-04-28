<?php

namespace App\Support;

use App\Contracts\AuthProvider;
use App\Models\WorkflowNamespace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Support\HealthCheck;

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
}
