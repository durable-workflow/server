<?php

namespace Tests\Feature;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\AwaitApprovalWorkflow;
use Tests\TestCase;

class ControlPlaneErrorContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    /**
     * @return array<string, array{method: string, path: string, body: array<string, mixed>, reason: string}>
     */
    public static function controlPlaneErrorProvider(): array
    {
        return [
            'workflows.show_missing' => [
                'method' => 'get',
                'path' => '/api/workflows/ghost-workflow',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.runs_missing' => [
                'method' => 'get',
                'path' => '/api/workflows/ghost-workflow/runs',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.show_run_missing' => [
                'method' => 'get',
                'path' => '/api/workflows/ghost-workflow/runs/run-missing',
                'body' => [],
                'reason' => 'run_not_found',
            ],
            'workflows.signal_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/signal/approve',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.query_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/query/status',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.update_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/update/approve',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.cancel_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/cancel',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.terminate_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/terminate',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.repair_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/repair',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.archive_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/archive',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.run_signal_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/runs/run-missing/signal/approve',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.run_query_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/runs/run-missing/query/status',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.run_update_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/runs/run-missing/update/approve',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.run_cancel_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/runs/run-missing/cancel',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.run_terminate_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/runs/run-missing/terminate',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.run_repair_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/runs/run-missing/repair',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'workflows.run_archive_missing' => [
                'method' => 'post',
                'path' => '/api/workflows/ghost-workflow/runs/run-missing/archive',
                'body' => [],
                'reason' => 'instance_not_found',
            ],
            'namespaces.show_missing' => [
                'method' => 'get',
                'path' => '/api/namespaces/ghost',
                'body' => [],
                'reason' => 'namespace_not_found',
            ],
            'namespaces.update_missing' => [
                'method' => 'put',
                'path' => '/api/namespaces/ghost',
                'body' => ['description' => 'missing namespace'],
                'reason' => 'namespace_not_found',
            ],
            'namespaces.destroy_missing' => [
                'method' => 'delete',
                'path' => '/api/namespaces/ghost',
                'body' => [],
                'reason' => 'namespace_not_found',
            ],
            'history.show_missing' => [
                'method' => 'get',
                'path' => '/api/workflows/wf-missing/runs/run-missing/history',
                'body' => [],
                'reason' => 'run_not_found',
            ],
            'history.export_missing' => [
                'method' => 'get',
                'path' => '/api/workflows/wf-missing/runs/run-missing/history/export',
                'body' => [],
                'reason' => 'run_not_found',
            ],
            'schedules.show_missing' => [
                'method' => 'get',
                'path' => '/api/schedules/missing-schedule',
                'body' => [],
                'reason' => 'schedule_not_found',
            ],
            'schedules.update_missing' => [
                'method' => 'put',
                'path' => '/api/schedules/missing-schedule',
                'body' => ['note' => 'missing schedule'],
                'reason' => 'schedule_not_found',
            ],
            'schedules.destroy_missing' => [
                'method' => 'delete',
                'path' => '/api/schedules/missing-schedule',
                'body' => [],
                'reason' => 'schedule_not_found',
            ],
            'schedules.pause_missing' => [
                'method' => 'post',
                'path' => '/api/schedules/missing-schedule/pause',
                'body' => [],
                'reason' => 'schedule_not_found',
            ],
            'schedules.resume_missing' => [
                'method' => 'post',
                'path' => '/api/schedules/missing-schedule/resume',
                'body' => [],
                'reason' => 'schedule_not_found',
            ],
            'schedules.trigger_missing' => [
                'method' => 'post',
                'path' => '/api/schedules/missing-schedule/trigger',
                'body' => [],
                'reason' => 'schedule_not_found',
            ],
            'schedules.backfill_missing' => [
                'method' => 'post',
                'path' => '/api/schedules/missing-schedule/backfill',
                'body' => [
                    'start_time' => '2026-04-18T10:00:00Z',
                    'end_time' => '2026-04-18T11:00:00Z',
                ],
                'reason' => 'schedule_not_found',
            ],
            'workers.show_missing' => [
                'method' => 'get',
                'path' => '/api/workers/missing-worker',
                'body' => [],
                'reason' => 'worker_not_found',
            ],
            'workers.destroy_missing' => [
                'method' => 'delete',
                'path' => '/api/workers/missing-worker',
                'body' => [],
                'reason' => 'worker_not_found',
            ],
            'search-attributes.destroy_missing' => [
                'method' => 'delete',
                'path' => '/api/search-attributes/MissingAttribute',
                'body' => [],
                'reason' => 'attribute_not_found',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('controlPlaneErrorProvider')]
    public function test_control_plane_errors_are_machine_readable_and_versioned(
        string $method,
        string $path,
        array $body,
        string $reason,
    ): void {
        $response = $this->sendJson($method, $path, $body, $this->controlPlaneHeadersWithWorkerProtocol());

        $response->assertNotFound()
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities')
            ->assertJsonPath('reason', $reason)
            ->assertJsonPath('message', static fn (mixed $message): bool => is_string($message) && $message !== '');
    }

    public function test_namespace_duplicate_errors_are_machine_readable_and_versioned(): void
    {
        $response = $this->postJson(
            '/api/namespaces',
            ['name' => 'Default'],
            $this->controlPlaneHeadersWithWorkerProtocol(),
        );

        $response->assertStatus(409)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities')
            ->assertJsonPath('reason', 'namespace_already_exists')
            ->assertJsonPath('namespace', 'default');
    }

    public function test_workflow_start_draining_queue_errors_are_machine_readable_and_versioned(): void
    {
        $this->configureWorkflowTypes([
            'tests.await-approval-workflow' => AwaitApprovalWorkflow::class,
        ]);

        WorkerRegistration::query()->create([
            'worker_id' => 'draining-worker',
            'namespace' => 'default',
            'task_queue' => 'drain-queue',
            'runtime' => 'php',
            'sdk_version' => '1.0.0',
            'build_id' => 'build-draining',
            'supported_workflow_types' => ['tests.await-approval-workflow'],
            'workflow_definition_fingerprints' => [],
            'supported_activity_types' => [],
            'max_concurrent_workflow_tasks' => 100,
            'max_concurrent_activity_tasks' => 100,
            'last_heartbeat_at' => now(),
            'status' => 'draining',
        ]);

        WorkerBuildIdRollout::query()->create([
            'namespace' => 'default',
            'task_queue' => 'drain-queue',
            'build_id' => 'build-draining',
            'drain_intent' => 'draining',
            'drained_at' => now(),
        ]);

        $response = $this->postJson('/api/workflows', [
            'workflow_id' => 'wf-drain-error',
            'workflow_type' => 'tests.await-approval-workflow',
            'task_queue' => 'drain-queue',
        ], $this->controlPlaneHeadersWithWorkerProtocol());

        $response->assertStatus(409)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonMissingPath('protocol_version')
            ->assertJsonMissingPath('server_capabilities')
            ->assertJsonPath('reason', 'task_queue_draining')
            ->assertJsonPath('task_queue', 'drain-queue')
            ->assertJsonPath('routing_status', 'draining')
            ->assertJsonPath('draining_build_ids.0', 'build-draining')
            ->assertJsonPath(
                'message',
                'Task queue [drain-queue] is draining and cannot accept new workflow starts until an active worker cohort is available.',
            );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function sendJson(string $method, string $path, array $body, array $headers): TestResponse
    {
        return match ($method) {
            'delete' => $this->deleteJson($path, $body, $headers),
            'get' => $this->getJson($path, $headers),
            'post' => $this->postJson($path, $body, $headers),
            'put' => $this->putJson($path, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }
}
