<?php

namespace App\Support;

use App\Auth\AuthException;
use App\Auth\ConfiguredAuthProvider;
use App\Contracts\AuthProvider;
use App\Models\WorkflowNamespace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Support\BackendCapabilities;
use Workflow\V2\Support\ReadinessContract;
use Workflow\V2\Support\WaterlineEngineSource;
use Workflow\V2\Support\WorkerCompatibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class ServerReadiness
{
    public function __construct(
        private readonly ServerPollingCache $cache,
        private readonly MigrationAdoption $migrationAdoption,
        private readonly BoundedRedisReadinessProbe $redisReadiness,
        private readonly DatabaseWorkerCompatibilityReadiness $databaseWorkerCompatibility,
    ) {}

    /**
     * @return array{ready: bool, checks: array<string, array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'migrations' => $this->migrationCheck(),
            'queue' => $this->queueCheck(),
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
            'queue' => $this->queueCheck(),
        ];

        return $this->normalizeWorkflowCheck($this->workflowCheck($checks));
    }

    /**
     * Runtime-serving routes only need to fail closed while durable storage
     * has not been bootstrapped. Keep this admission check independent from
     * workflow history, task backlog, and operator-metrics cardinality.
     *
     * @return array<string, mixed>
     */
    public function bootstrapStatus(): array
    {
        return $this->normalizeWorkflowCheck($this->bootstrapCheck([
            'database' => $this->databaseCheck(),
            'migrations' => $this->migrationCheck(),
            'queue' => $this->queueCheck(),
        ]));
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
    private function queueCheck(): array
    {
        $connection = config('queue.default');

        if (! is_string($connection) || trim($connection) === '') {
            return [
                'status' => 'invalid',
                'message' => 'The default queue connection is not configured.',
                'remediation' => 'Set QUEUE_CONNECTION to a configured Laravel queue connection.',
            ];
        }

        $connection = trim($connection);
        $driver = config("queue.connections.{$connection}.driver");

        if (! is_string($driver) || trim($driver) === '') {
            return [
                'status' => 'invalid',
                'connection' => $connection,
                'message' => 'The default queue connection does not resolve to a configured driver.',
                'remediation' => 'Set QUEUE_CONNECTION to a configured Laravel queue connection.',
            ];
        }

        $driver = trim($driver);
        if ($driver !== 'database') {
            return [
                'status' => 'ok',
                'connection' => $connection,
                'driver' => $driver,
            ];
        }

        $table = config("queue.connections.{$connection}.table", 'jobs');
        $databaseConnection = config("queue.connections.{$connection}.connection");
        $databaseConnection = is_string($databaseConnection) && trim($databaseConnection) !== ''
            ? trim($databaseConnection)
            : null;

        if (! is_string($table) || trim($table) === '') {
            return [
                'status' => 'invalid',
                'connection' => $connection,
                'driver' => $driver,
                'message' => 'The database queue table is not configured.',
                'remediation' => 'Configure a database queue table and run server-bootstrap before serving workflow traffic.',
            ];
        }

        $table = trim($table);
        $check = [
            'connection' => $connection,
            'driver' => $driver,
            'database_connection' => $databaseConnection ?? (string) config('database.default'),
            'table' => $table,
        ];

        try {
            if (! Schema::connection($databaseConnection)->hasTable($table)) {
                return $check + [
                    'status' => 'missing',
                    'remediation' => 'Run server-bootstrap to create the configured database queue table.',
                ];
            }

            return $check + ['status' => 'ok'];
        } catch (\Throwable $exception) {
            return $check + [
                'status' => 'unavailable',
                'message' => $exception->getMessage(),
            ];
        }
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
            $read = $this->redisCacheIsConfigured()
                ? $this->redisReadiness->roundTrip($key, $value, 10)
                : $this->cacheRoundTrip($key, $value);

            if ($read !== $value) {
                return [
                    'status' => $this->cacheFailureStatus(),
                    'store' => (string) config('cache.default'),
                    'message' => 'Cache store did not round-trip the readiness probe value.',
                ] + $this->cacheFailureDetails();
            }

            return [
                'status' => 'ok',
                'store' => (string) config('cache.default'),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => $this->cacheFailureStatus(),
                'store' => (string) config('cache.default'),
                'message' => $this->redisCacheIsConfigured()
                    ? RedisReadinessProcess::FAILURE_MESSAGE
                    : $exception->getMessage(),
            ] + $this->cacheFailureDetails();
        }
    }

    private function cacheFailureStatus(): string
    {
        // Redis wake signals and admission locks accelerate multi-node polling,
        // but workflow history, task rows, leases, and schedule deduplication
        // remain database-backed. Keep Redis-only loss in the load-balancer
        // rotation while making the degraded acceleration layer explicit.
        return (string) config('cache.default') === 'redis' ? 'warning' : 'unavailable';
    }

    private function redisCacheIsConfigured(): bool
    {
        return (string) config('cache.default') === 'redis';
    }

    private function cacheRoundTrip(string $key, string $value): mixed
    {
        $store = $this->cache->store();
        $store->put($key, $value, 10);
        $read = $store->get($key);
        $store->forget($key);

        return $read;
    }

    /**
     * @return array<string, string>
     */
    private function cacheFailureDetails(): array
    {
        if ((string) config('cache.default') !== 'redis') {
            return [];
        }

        return [
            'correctness_substrate' => 'database',
            'degraded_capability' => 'long_poll_wake_acceleration',
        ];
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
        $bootstrap = $this->bootstrapCheck($checks);
        if (($bootstrap['status'] ?? null) === 'blocked') {
            return $bootstrap;
        }

        try {
            $snapshot = $this->boundedWorkflowSnapshot(
                $this->redisAccelerationIsDegraded($checks['cache'] ?? []),
            );
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
     * @param  array<string, array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    private function bootstrapCheck(array $checks): array
    {
        $blockedBy = [];

        foreach (['database', 'migrations', 'queue'] as $key) {
            if (! self::statusAllowsReady($checks[$key]['status'] ?? null)) {
                $blockedBy[] = $key;
            }
        }

        if ($blockedBy !== []) {
            return [
                'status' => 'blocked',
                'blocked_by' => $blockedBy,
                'remediation' => 'Restore database connectivity and run server-bootstrap to migrate workflow and configured queue storage before serving workflow v2 traffic.',
            ];
        }

        return [
            'status' => 'ok',
            'generated_at' => now()->toJSON(),
            'http_status' => 200,
            'categories' => [],
            'warning_checks' => [],
            'error_checks' => [],
            'checks' => [],
        ];
    }

    /**
     * Build only the fixed-cost checks that can make public readiness fail.
     * Detailed rollout-safety diagnostics remain available from the system
     * health and operator-metrics endpoints.
     *
     * @return array<string, mixed>
     */
    private function boundedWorkflowSnapshot(bool $databaseOnlyFleet): array
    {
        $backend = BackendCapabilities::snapshot();
        $backendSeverity = is_string($backend['severity'] ?? null)
            ? $backend['severity']
            : (($backend['supported'] ?? false) === true ? 'ok' : 'error');
        $backendStatus = match ($backendSeverity) {
            'error' => 'error',
            'warning' => 'warning',
            default => 'ok',
        };
        $backendCheck = [
            'name' => 'backend_capabilities',
            'status' => $backendStatus,
            'category' => 'correctness',
            'message' => match ($backendStatus) {
                'error' => 'One or more configured v2 backend capabilities are unsupported.',
                'warning' => 'One or more configured v2 backend capabilities are degraded but non-blocking.',
                default => 'The configured database, queue, cache, and codec backends satisfy the v2 capability contract.',
            },
        ];

        $required = WorkerCompatibility::current();
        $namespace = WorkerCompatibilityFleet::scopeNamespace();
        $fleetSupportsRequired = $this->fleetSupportsRequired(
            $namespace,
            $required,
            $databaseOnlyFleet,
        );

        $validationMode = strtolower(trim((string) config('workflows.v2.fleet.validation_mode', 'warn'))) === 'fail'
            ? 'fail'
            : 'warn';
        $workerStatus = $fleetSupportsRequired ? 'ok' : ($validationMode === 'fail' ? 'error' : 'warning');
        $workerCheck = [
            'name' => 'worker_compatibility',
            'status' => $workerStatus,
            'category' => 'correctness',
            'message' => match ($workerStatus) {
                'error' => 'No active worker heartbeat advertises the current v2 compatibility marker; fleet validation mode is fail-closed.',
                'warning' => 'No active worker heartbeat advertises the current v2 compatibility marker.',
                default => $required === null
                    ? 'No current v2 compatibility marker is required.'
                    : 'At least one active worker heartbeat advertises the current v2 compatibility marker.',
            },
        ];

        $checks = [$backendCheck, $workerCheck];
        $statuses = array_column($checks, 'status');
        $status = in_array('error', $statuses, true)
            ? 'error'
            : (in_array('warning', $statuses, true) ? 'warning' : 'ok');

        return [
            'generated_at' => now()->toJSON(),
            'status' => $status,
            'categories' => [
                'correctness' => [
                    'status' => $status,
                    'check_count' => count($checks),
                ],
                'acceleration' => [
                    'status' => 'ok',
                    'check_count' => 0,
                ],
            ],
            'checks' => $checks,
        ];
    }

    /**
     * Once Redis has failed its bounded readiness probe, the package fleet
     * merger's legacy cache fallback is no longer safe to call in the same
     * request. Required compatibility still fails closed against live,
     * durable database heartbeats rather than being treated as an empty fleet.
     */
    private function fleetSupportsRequired(?string $namespace, ?string $required, bool $databaseOnly): bool
    {
        if ($required === null) {
            return true;
        }

        if ($databaseOnly) {
            return $this->databaseWorkerCompatibility->supportsRequired($namespace, $required);
        }

        $fleet = $namespace === null
            ? WorkerCompatibilityFleet::details($required)
            : WorkerCompatibilityFleet::detailsForNamespace($namespace, $required);

        foreach ($fleet as $worker) {
            $workerId = is_string($worker['worker_id'] ?? null) ? $worker['worker_id'] : null;

            if ($workerId !== null && $workerId !== '' && ($worker['supports_required'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $cacheCheck */
    private function redisAccelerationIsDegraded(array $cacheCheck): bool
    {
        return ($cacheCheck['status'] ?? null) === 'warning'
            && ($cacheCheck['store'] ?? null) === 'redis'
            && ($cacheCheck['correctness_substrate'] ?? null) === 'database';
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
