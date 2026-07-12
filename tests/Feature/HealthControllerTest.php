<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ServerTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\HeaderAuthProvider;
use Tests\TestCase;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    public function test_health_check_returns_serving_when_database_is_available(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'serving')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('topology.schema', ServerTopology::SCHEMA)
            ->assertJsonPath('topology.version', ServerTopology::VERSION)
            ->assertJsonPath('topology.current_shape', 'standalone_server')
            ->assertJsonPath('topology.current_process_class', 'server_http_node')
            ->assertJsonPath('topology.execution_mode', 'remote_worker_protocol')
            ->assertJsonPath('topology.matching_role.shape', 'in_worker')
            ->assertJsonStructure([
                'status',
                'timestamp',
                'checks' => ['database'],
                'topology' => [
                    'schema',
                    'version',
                    'current_shape',
                    'current_process_class',
                    'current_roles',
                    'execution_mode',
                    'matching_role' => [
                        'queue_wake_enabled',
                        'shape',
                        'wake_owner',
                        'task_dispatch_mode',
                        'partition_primitives',
                        'backpressure_model',
                    ],
                ],
            ]);
    }

    public function test_health_check_returns_degraded_when_database_is_unavailable(): void
    {
        $originalDefault = config('database.default');

        config(['database.default' => 'broken']);
        config(['database.connections.broken' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '19999',
            'database' => 'nonexistent',
            'username' => 'nobody',
            'password' => 'wrong',
        ]]);

        try {
            $response = $this->getJson('/api/health');

            $response->assertStatus(503)
                ->assertJsonPath('status', 'degraded')
                ->assertJsonPath('checks.database', 'unavailable');
        } finally {
            config(['database.default' => $originalDefault]);
            DB::purge('broken');
        }
    }

    public function test_health_check_does_not_require_authentication(): void
    {
        config(['server.auth.driver' => 'token', 'server.auth.token' => 'secret']);

        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'serving');
    }

    public function test_health_check_timestamp_is_iso8601(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();

        $timestamp = $response->json('timestamp');
        $this->assertIsString($timestamp);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp));
    }

    public function test_readiness_check_returns_ready_when_bootstrap_state_is_available(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.migrations.status', 'ok')
            ->assertJsonPath('checks.migrations.operator_surface.available', true)
            ->assertJsonPath('checks.migrations.readiness_contract.version', 1)
            ->assertJsonPath('checks.default_namespace.status', 'ok')
            ->assertJsonPath('checks.default_namespace.namespace', 'default')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonPath('checks.auth.status', 'ok')
            ->assertJsonPath('checks.workflow_v2.status', 'ok')
            ->assertJsonPath('checks.workflow_v2.http_status', 200)
            ->assertJsonPath('topology.schema', ServerTopology::SCHEMA)
            ->assertJsonPath('topology.version', ServerTopology::VERSION)
            ->assertJsonPath('topology.current_shape', 'standalone_server')
            ->assertJsonPath('topology.current_process_class', 'server_http_node')
            ->assertJsonPath('topology.execution_mode', 'remote_worker_protocol')
            ->assertJsonPath('topology.matching_role.task_dispatch_mode', 'poll');
    }

    public function test_public_health_endpoints_publish_topology_for_split_execution_nodes(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'server.topology.shape' => 'split_control_execution',
            'server.topology.process_class' => 'execution_node',
        ]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('topology.schema', ServerTopology::SCHEMA)
            ->assertJsonPath('topology.current_shape', 'split_control_execution')
            ->assertJsonPath('topology.current_process_class', 'execution_node')
            ->assertJsonPath('topology.current_roles.0', 'execution_plane')
            ->assertJsonPath('topology.execution_mode', 'remote_worker_protocol')
            ->assertJsonPath('topology.matching_role.backpressure_model', 'lease_ownership');

        $this->getJson('/api/ready')
            ->assertOk()
            ->assertJsonPath('topology.schema', ServerTopology::SCHEMA)
            ->assertJsonPath('topology.current_shape', 'split_control_execution')
            ->assertJsonPath('topology.current_process_class', 'execution_node')
            ->assertJsonPath('topology.current_roles.0', 'execution_plane')
            ->assertJsonPath('topology.matching_role.shape', 'in_worker');
    }

    public function test_readiness_check_warns_when_existing_create_table_migration_only_needs_adoption(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        DB::table('migrations')
            ->where('migration', '2026_04_16_000180_create_workflow_schedule_history_events_table')
            ->delete();

        $response = $this->getJson('/api/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.migrations.status', 'warning')
            ->assertJsonPath(
                'checks.migrations.adoptable_migrations.0',
                '2026_04_16_000180_create_workflow_schedule_history_events_table',
            )
            ->assertJsonPath('checks.workflow_v2.status', 'ok');
    }

    public function test_readiness_check_blocks_pending_rollout_safety_migration_records(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        DB::table('migrations')
            ->where('migration', '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations')
            ->delete();

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.migrations.status', 'pending')
            ->assertJsonPath(
                'checks.migrations.blocking_migrations.0.migration',
                '2026_04_21_000300_add_workflow_definition_fingerprints_to_worker_registrations',
            )
            ->assertJsonPath('checks.workflow_v2.status', 'blocked')
            ->assertJsonPath('checks.workflow_v2.blocked_by.0', 'migrations');
    }

    public function test_readiness_check_blocks_when_v2_operator_surface_tables_are_missing(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        Schema::drop('workflow_run_summaries');

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.migrations.status', 'missing')
            ->assertJsonPath('checks.migrations.operator_surface.available', false)
            ->assertJsonPath('checks.migrations.missing_tables.0', 'workflow_run_summaries')
            ->assertJsonPath('checks.workflow_v2.status', 'blocked');
    }

    public function test_readiness_check_reports_missing_default_namespace_before_bootstrap_seed(): void
    {
        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.default_namespace.status', 'missing')
            ->assertJsonPath('checks.default_namespace.namespace', 'default')
            ->assertJsonPath('checks.default_namespace.remediation', 'Run server-bootstrap to seed the default namespace.');
    }

    public function test_readiness_check_reports_unusable_database_cache_store(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config(['cache.default' => 'database']);
        app('cache')->forgetDriver('database');

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.cache.status', 'unavailable')
            ->assertJsonPath('checks.cache.store', 'database');

        $this->assertStringContainsString(
            'no such table: cache',
            (string) $response->json('checks.cache.message'),
        );
    }

    public function test_readiness_keeps_redis_only_acceleration_loss_non_blocking(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'missing-readiness-connection'],
        ]);
        app('cache')->forgetDriver('redis');

        $response = $this->getJson('/api/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.cache.status', 'warning')
            ->assertJsonPath('checks.cache.store', 'redis')
            ->assertJsonPath('checks.cache.correctness_substrate', 'database')
            ->assertJsonPath('checks.cache.degraded_capability', 'long_poll_wake_acceleration');
    }

    public function test_readiness_check_reports_missing_auth_credential(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [],
        ]);

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.auth.status', 'missing')
            ->assertJsonPath('checks.auth.driver', 'token');
    }

    public function test_readiness_check_treats_empty_principal_token_map_as_missing_auth_credential(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [],
            'server.auth.principal_tokens' => '{}',
        ]);

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.auth.status', 'missing')
            ->assertJsonPath('checks.auth.driver', 'token');
    }

    public function test_readiness_check_rejects_malformed_principal_token_config(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [],
            'server.auth.principal_tokens' => '{"alice":',
        ]);

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.auth.status', 'invalid')
            ->assertJsonPath('checks.auth.driver', 'token')
            ->assertJsonPath('checks.auth.message', 'DW_PRINCIPAL_TOKENS must be valid JSON.');
    }

    public function test_readiness_check_accepts_custom_auth_provider_without_builtin_credentials(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'server.auth.provider' => HeaderAuthProvider::class,
            'server.auth.driver' => 'token',
            'server.auth.token' => null,
            'server.auth.role_tokens' => [],
        ]);

        $response = $this->getJson('/api/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.auth.status', 'ok')
            ->assertJsonPath('checks.auth.driver', 'custom')
            ->assertJsonPath('checks.auth.provider', HeaderAuthProvider::class);
    }

    public function test_readiness_check_reports_invalid_custom_auth_provider(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'server.auth.provider' => \stdClass::class,
        ]);

        $response = $this->getJson('/api/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.auth.status', 'invalid')
            ->assertJsonPath('checks.auth.driver', 'custom')
            ->assertJsonPath('checks.auth.provider', \stdClass::class)
            ->assertJsonPath('checks.auth.remediation', 'Set DW_AUTH_PROVIDER to a Laravel-resolvable class implementing App\Contracts\AuthProvider.');
    }

    public function test_readiness_check_stays_ready_when_workflow_health_only_warns(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.compatibility.namespace' => 'default',
            'workflows.v2.fleet.validation_mode' => 'warn',
        ]);
        WorkerCompatibilityFleet::clear();

        try {
            WorkerCompatibilityFleet::recordForNamespace(
                'default',
                ['build-b'],
                'database',
                'default',
                'worker-b',
            );

            $response = $this->getJson('/api/ready');

            $response->assertOk()
                ->assertJsonPath('status', 'ready')
                ->assertJsonPath('checks.workflow_v2.status', 'warning')
                ->assertJsonPath('checks.workflow_v2.http_status', 200);

            $this->assertContains(
                'worker_compatibility',
                $response->json('checks.workflow_v2.warning_checks', []),
            );
            $this->assertSame([], $response->json('checks.workflow_v2.error_checks', []));
        } finally {
            WorkerCompatibilityFleet::clear();
        }
    }

    public function test_readiness_check_fails_closed_when_workflow_health_errors(): void
    {
        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        config([
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.compatibility.namespace' => 'default',
            'workflows.v2.fleet.validation_mode' => 'fail',
        ]);
        WorkerCompatibilityFleet::clear();

        try {
            WorkerCompatibilityFleet::recordForNamespace(
                'default',
                ['build-b'],
                'database',
                'default',
                'worker-b',
            );

            $response = $this->getJson('/api/ready');

            $response->assertStatus(503)
                ->assertJsonPath('status', 'not_ready')
                ->assertJsonPath('checks.workflow_v2.status', 'error')
                ->assertJsonPath('checks.workflow_v2.http_status', 503);

            $this->assertContains(
                'worker_compatibility',
                $response->json('checks.workflow_v2.error_checks', []),
            );
        } finally {
            WorkerCompatibilityFleet::clear();
        }
    }

    public function test_public_health_and_runtime_admission_stay_bounded_with_growing_history_and_ready_tasks(): void
    {
        $this->createNamespace('default');
        $this->seedGrowthCardinality(1000);
        $this->registerWorker(
            'health-growth-worker',
            'health-growth-live',
            supportedWorkflowTypes: ['tests.health-growth'],
        );

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $maxProbeLatencySeconds = 0.0;
        $availableResponses = 0;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            foreach (['/api/health', '/api/ready', '/api/cluster/info'] as $path) {
                $startedAt = hrtime(true);
                $response = $this->getJson($path);
                $maxProbeLatencySeconds = max(
                    $maxProbeLatencySeconds,
                    (hrtime(true) - $startedAt) / 1_000_000_000,
                );

                $response->assertOk();
                $availableResponses++;
            }
        }

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'health-growth-live-start',
                'workflow_type' => 'tests.health-growth',
                'task_queue' => 'health-growth-live',
                'input' => [],
            ])
            ->assertCreated();
        $availableResponses++;

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows?per_page=10')
            ->assertOk();
        $availableResponses++;

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/worker/workflow-tasks/poll', [
                'worker_id' => 'health-growth-worker',
                'task_queue' => 'health-growth-live',
                'poll_request_id' => 'health-growth-poll',
            ])
            ->assertOk();
        $availableResponses++;

        $allRunsCommandContractScans = array_values(array_filter(
            $queries,
            static fn (string $query): bool => str_contains($query, 'workflow_runs')
                && str_contains($query, 'workflow_history_events')
                && str_contains($query, 'exists'),
        ));

        $this->assertSame(18, $availableResponses);
        $this->assertLessThan(
            3.0,
            $maxProbeLatencySeconds,
            sprintf(
                'Public health/readiness/discovery exceeded the three-second probe budget: %.3fs',
                $maxProbeLatencySeconds,
            ),
        );
        $this->assertSame(
            [],
            $allRunsCommandContractScans,
            'Public probes and bootstrap admission must not page through every run with a WorkflowStarted event.',
        );
    }

    private function seedGrowthCardinality(int $count): void
    {
        $now = now();

        foreach (array_chunk(range(1, $count), 200) as $indexes) {
            $runs = [];
            $historyEvents = [];
            $tasks = [];

            foreach ($indexes as $index) {
                $runId = '01'.str_pad((string) $index, 24, '0', STR_PAD_LEFT);

                $runs[] = [
                    'id' => $runId,
                    'workflow_instance_id' => 'health-growth-'.$index,
                    'run_number' => 1,
                    'workflow_class' => 'Tests\\Fixtures\\HealthGrowthWorkflow',
                    'workflow_type' => 'tests.health-growth.seeded',
                    'namespace' => 'default',
                    'status' => 'running',
                    'queue' => 'health-growth-seeded',
                    'last_history_sequence' => 1,
                    'started_at' => $now,
                    'last_progress_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $historyEvents[] = [
                    'id' => '02'.str_pad((string) $index, 24, '0', STR_PAD_LEFT),
                    'workflow_run_id' => $runId,
                    'sequence' => 1,
                    'event_type' => 'WorkflowStarted',
                    'payload' => '{}',
                    'recorded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $tasks[] = [
                    'id' => '03'.str_pad((string) $index, 24, '0', STR_PAD_LEFT),
                    'workflow_run_id' => $runId,
                    'namespace' => 'default',
                    'task_type' => 'workflow',
                    'status' => 'ready',
                    'queue' => 'health-growth-seeded',
                    'available_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('workflow_runs')->insert($runs);
            DB::table('workflow_history_events')->insert($historyEvents);
            DB::table('workflow_tasks')->insert($tasks);
        }
    }
}
