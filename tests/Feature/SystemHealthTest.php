<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->createNamespace('other');
        WorkerCompatibilityFleet::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WorkerCompatibilityFleet::clear();

        parent::tearDown();
    }

    public function test_system_health_returns_full_snapshot_for_requested_namespace(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');

        $this->createRunSummaryWithReadyTask(namespace: 'default', availableSecondsAgo: 1);
        $this->createRunSummaryWithReadyTask(namespace: 'other', availableSecondsAgo: 10);

        $defaultResponse = $this->getJson(
            '/api/system/health',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $defaultResponse->assertOk()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('retention_mode', 'bounded')
            ->assertJsonPath('retention_days', 30)
            ->assertJsonPath('health.status', 'ok')
            ->assertJsonPath('health.healthy', true)
            ->assertJsonPath('health.operator_metrics.runs.total', 1)
            ->assertJsonPath('health.operator_metrics.tasks.ready_due', 1)
            ->assertJsonPath('health.operator_metrics.tasks.oldest_ready_due_at', now()->subSecond()->toJSON())
            ->assertJsonStructure([
                'namespace',
                'health' => [
                    'generated_at',
                    'status',
                    'healthy',
                    'checks',
                    'categories',
                    'routing_drains',
                    'operator_metrics',
                    'structural_limits',
                ],
            ]);

        $otherResponse = $this->getJson(
            '/api/system/health',
            $this->controlPlaneHeadersWithWorkerProtocol('other'),
        );

        $otherResponse->assertOk()
            ->assertJsonPath('namespace', 'other')
            ->assertJsonPath('health.status', 'ok')
            ->assertJsonPath('health.operator_metrics.runs.total', 1)
            ->assertJsonPath('health.operator_metrics.tasks.ready_due', 1)
            ->assertJsonPath('health.operator_metrics.tasks.oldest_ready_due_at', now()->subSeconds(10)->toJSON())
            ->assertJsonPath('health.routing_drains.queues_with_drains', 0);
    }

    public function test_system_health_exposes_forever_namespace_retention(): void
    {
        WorkflowNamespace::query()
            ->where('name', 'other')
            ->update([
                'retention_mode' => WorkflowNamespace::RETENTION_MODE_FOREVER,
                'retention_days' => null,
            ]);

        $this->getJson(
            '/api/system/health',
            $this->controlPlaneHeadersWithWorkerProtocol('other'),
        )
            ->assertOk()
            ->assertJsonPath('namespace', 'other')
            ->assertJsonPath('retention_mode', 'forever')
            ->assertJsonPath('retention_days', null);
    }

    public function test_system_health_limits_routing_drains_to_requested_namespace(): void
    {
        WorkerRegistration::query()->create([
            'worker_id' => 'worker-default',
            'namespace' => 'default',
            'task_queue' => 'orders',
            'runtime' => 'php',
            'build_id' => 'build-draining',
            'last_heartbeat_at' => now(),
            'status' => 'draining',
        ]);
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'orders',
            'build_id' => WorkerBuildIdRollout::buildIdKey('build-draining'),
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
            'drained_at' => now()->subMinute(),
        ]);
        WorkerBuildIdRollout::query()->create([
            'namespace' => 'other',
            'task_queue' => 'payments',
            'build_id' => WorkerBuildIdRollout::buildIdKey('build-ghost'),
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
            'drained_at' => now()->subMinutes(5),
        ]);

        $this->getJson('/api/system/health', $this->controlPlaneHeadersWithWorkerProtocol())
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('health.routing_drains.queues_with_drains', 1)
            ->assertJsonPath('health.routing_drains.draining_build_id_count', 1)
            ->assertJsonPath('health.routing_drains.queues.0.namespace', 'default')
            ->assertJsonPath('health.routing_drains.queues.0.task_queue', 'orders')
            ->assertJsonPath('health.routing_drains.queues.0.build_ids.0.build_id', 'build-draining');

        $this->getJson('/api/system/health', $this->controlPlaneHeadersWithWorkerProtocol('other'))
            ->assertOk()
            ->assertJsonPath('namespace', 'other')
            ->assertJsonPath('health.routing_drains.queues_with_drains', 1)
            ->assertJsonPath('health.routing_drains.draining_build_id_count', 1)
            ->assertJsonPath('health.routing_drains.queues.0.namespace', 'other')
            ->assertJsonPath('health.routing_drains.queues.0.task_queue', 'payments')
            ->assertJsonPath('health.routing_drains.queues.0.build_ids.0.build_id', 'build-ghost');
    }

    public function test_system_health_returns_service_unavailable_when_health_snapshot_errors(): void
    {
        config([
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.compatibility.namespace' => 'default',
            'workflows.v2.fleet.validation_mode' => 'fail',
        ]);

        WorkerCompatibilityFleet::recordForNamespace(
            'default',
            ['build-b'],
            'database',
            'default',
            'worker-b',
        );

        $response = $this->getJson(
            '/api/system/health',
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertStatus(503)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('health.status', 'error')
            ->assertJsonPath('health.healthy', false);

        $checkNames = collect($response->json('health.checks', []))
            ->filter(static fn (mixed $check): bool => is_array($check))
            ->mapWithKeys(static fn (array $check): array => [
                (string) ($check['name'] ?? 'unknown') => (string) ($check['status'] ?? 'unknown'),
            ])
            ->all();

        $this->assertSame('error', $checkNames['worker_compatibility'] ?? null);
    }

    public function test_system_health_requires_control_plane_version_header(): void
    {
        $this->getJson('/api/system/health', [
            'X-Namespace' => 'default',
        ])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'missing_control_plane_version');
    }

    private function createRunSummaryWithReadyTask(string $namespace, int $availableSecondsAgo): void
    {
        $instanceId = 'wf-'.Str::lower(Str::random(12));
        $runId = (string) Str::ulid();
        $workflowType = sprintf('tests.health.%s', $namespace);

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'namespace' => $namespace,
            'workflow_class' => 'Tests\\Fixtures\\HealthWorkflow',
            'workflow_type' => $workflowType,
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\Fixtures\\HealthWorkflow',
            'workflow_type' => $workflowType,
            'status' => 'running',
            'namespace' => $namespace,
            'started_at' => now()->subMinutes(10),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'Tests\\Fixtures\\HealthWorkflow',
            'workflow_type' => $workflowType,
            'status' => 'running',
            'status_bucket' => 'running',
            'namespace' => $namespace,
            'started_at' => now()->subMinutes(10),
            'liveness_state' => 'running',
            'projection_schema_version' => RunSummaryProjector::SCHEMA_VERSION,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now(),
        ]);

        WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => $namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'queue' => 'default',
            'available_at' => now()->subSeconds($availableSecondsAgo),
        ]);
    }
}
