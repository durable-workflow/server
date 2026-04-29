<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ServerTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\HeaderAuthProvider;
use Tests\TestCase;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

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
}
