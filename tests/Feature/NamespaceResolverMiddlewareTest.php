<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Workflow\V2\Models\WorkflowSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NamespaceResolverMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'default'],
            ['description' => 'Default namespace', 'retention_days' => 30, 'status' => 'active'],
        );

        WorkflowNamespace::query()->updateOrCreate(
            ['name' => 'production'],
            ['description' => 'Production namespace', 'retention_days' => 90, 'status' => 'active'],
        );
    }

    // ── X-Namespace header ──────────────────────────────────────────

    public function test_x_namespace_header_sets_namespace(): void
    {
        $this->createScheduleInNamespace('sched-prod', 'production');
        $this->createScheduleInNamespace('sched-default', 'default');

        $response = $this->withHeaders(['X-Namespace' => 'production'])
            ->getJson('/api/schedules');

        $response->assertOk();
        $schedules = $response->json('schedules');
        $this->assertCount(1, $schedules);
        $this->assertEquals('sched-prod', $schedules[0]['schedule_id']);
    }

    public function test_x_namespace_header_is_normalized_to_lowercase(): void
    {
        $this->createScheduleInNamespace('sched-default', 'default');

        // 'Default' is normalized to 'default' by the middleware
        $response = $this->withHeaders(['X-Namespace' => 'Default'])
            ->getJson('/api/schedules');

        $response->assertOk();
        $this->assertCount(1, $response->json('schedules'));
        $this->assertEquals('sched-default', $response->json('schedules.0.schedule_id'));
    }

    // ── Query parameter fallback ────────────────────────────────────

    public function test_namespace_query_parameter_works_when_no_header(): void
    {
        $this->createScheduleInNamespace('sched-prod', 'production');
        $this->createScheduleInNamespace('sched-default', 'default');

        $response = $this->getJson('/api/schedules?namespace=production');

        $response->assertOk();
        $schedules = $response->json('schedules');
        $this->assertCount(1, $schedules);
        $this->assertEquals('sched-prod', $schedules[0]['schedule_id']);
    }

    // ── Header takes precedence over query parameter ────────────────

    public function test_header_takes_precedence_over_query_parameter(): void
    {
        $this->createScheduleInNamespace('sched-prod', 'production');
        $this->createScheduleInNamespace('sched-default', 'default');

        // Header says 'default', query says 'production' — header wins
        $response = $this->withHeaders(['X-Namespace' => 'default'])
            ->getJson('/api/schedules?namespace=production');

        $response->assertOk();
        $schedules = $response->json('schedules');
        $this->assertCount(1, $schedules);
        $this->assertEquals('sched-default', $schedules[0]['schedule_id']);
    }

    // ── Config default fallback ─────────────────────────────────────

    public function test_falls_back_to_config_default_when_no_header_or_query(): void
    {
        config(['server.default_namespace' => 'default']);

        $this->createScheduleInNamespace('sched-default', 'default');
        $this->createScheduleInNamespace('sched-prod', 'production');

        // No X-Namespace header, no ?namespace= query
        $response = $this->getJson('/api/schedules');

        $response->assertOk();
        $schedules = $response->json('schedules');
        $this->assertCount(1, $schedules);
        $this->assertEquals('sched-default', $schedules[0]['schedule_id']);
    }

    public function test_custom_config_default_is_used(): void
    {
        config(['server.default_namespace' => 'production']);

        $this->createScheduleInNamespace('sched-default', 'default');
        $this->createScheduleInNamespace('sched-prod', 'production');

        $response = $this->getJson('/api/schedules');

        $response->assertOk();
        $schedules = $response->json('schedules');
        $this->assertCount(1, $schedules);
        $this->assertEquals('sched-prod', $schedules[0]['schedule_id']);
    }

    // ── Namespace isolation across operations ───────────────────────

    public function test_namespace_resolves_for_create_operations(): void
    {
        $response = $this->withHeaders(['X-Namespace' => 'production'])
            ->postJson('/api/schedules', [
                'schedule_id' => 'created-in-prod',
                'spec' => ['cron_expressions' => ['0 * * * *']],
                'action' => ['workflow_type' => 'TestWorkflow'],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('workflow_schedules', [
            'schedule_id' => 'created-in-prod',
            'namespace' => 'production',
        ]);
    }

    public function test_namespace_resolves_for_namespace_list(): void
    {
        // Namespace CRUD endpoints don't filter by resolved namespace,
        // but the resolver still sets the attribute for consistency
        $response = $this->withHeaders(['X-Namespace' => 'production'])
            ->getJson('/api/namespaces');

        $response->assertOk();
        // Namespace list returns all namespaces regardless of resolved namespace
        $this->assertGreaterThanOrEqual(2, count($response->json('namespaces')));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createScheduleInNamespace(string $scheduleId, string $namespace): void
    {
        WorkflowSchedule::create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'TestWorkflow'],
        ]);
    }
}
