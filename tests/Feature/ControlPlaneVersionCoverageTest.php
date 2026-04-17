<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins coverage for #301 — every non-health API controller must reject
 * requests that arrive without the control-plane version header or with
 * an unsupported version. Previously, 6 controllers silently accepted
 * any/no version header; this test guards against regression.
 */
class ControlPlaneVersionCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->firstOrCreate(
            ['name' => 'default'],
            ['description' => 'default namespace', 'retention_days' => 30, 'status' => 'active'],
        );
    }

    /**
     * @return array<string, array{method: string, path: string, body?: array<string, mixed>}>
     */
    public static function controlPlaneEndpointProvider(): array
    {
        return [
            // ScheduleController
            'schedules.index' => ['method' => 'get', 'path' => '/api/schedules'],
            'schedules.store' => [
                'method' => 'post',
                'path' => '/api/schedules',
                'body' => ['schedule_id' => 'any', 'spec' => [], 'action' => []],
            ],
            'schedules.show' => ['method' => 'get', 'path' => '/api/schedules/some-id'],
            'schedules.update' => ['method' => 'put', 'path' => '/api/schedules/some-id'],
            'schedules.destroy' => ['method' => 'delete', 'path' => '/api/schedules/some-id'],
            'schedules.pause' => ['method' => 'post', 'path' => '/api/schedules/some-id/pause'],
            'schedules.resume' => ['method' => 'post', 'path' => '/api/schedules/some-id/resume'],
            'schedules.trigger' => ['method' => 'post', 'path' => '/api/schedules/some-id/trigger'],
            'schedules.backfill' => ['method' => 'post', 'path' => '/api/schedules/some-id/backfill'],

            // SearchAttributeController
            'search-attributes.index' => ['method' => 'get', 'path' => '/api/search-attributes'],
            'search-attributes.store' => ['method' => 'post', 'path' => '/api/search-attributes'],
            'search-attributes.destroy' => ['method' => 'delete', 'path' => '/api/search-attributes/Some'],

            // TaskQueueController
            'task-queues.index' => ['method' => 'get', 'path' => '/api/task-queues'],
            'task-queues.show' => ['method' => 'get', 'path' => '/api/task-queues/default'],

            // WorkerManagementController
            'workers.index' => ['method' => 'get', 'path' => '/api/workers'],
            'workers.show' => ['method' => 'get', 'path' => '/api/workers/abc'],
            'workers.destroy' => ['method' => 'delete', 'path' => '/api/workers/abc'],

            // NamespaceController
            'namespaces.index' => ['method' => 'get', 'path' => '/api/namespaces'],
            'namespaces.store' => [
                'method' => 'post',
                'path' => '/api/namespaces',
                'body' => ['name' => 'new'],
            ],
            'namespaces.show' => ['method' => 'get', 'path' => '/api/namespaces/default'],
            'namespaces.update' => ['method' => 'put', 'path' => '/api/namespaces/default'],

            // SystemController
            'system.repair_status' => ['method' => 'get', 'path' => '/api/system/repair'],
            'system.repair_pass' => ['method' => 'post', 'path' => '/api/system/repair/pass'],
            'system.activity_timeout_status' => ['method' => 'get', 'path' => '/api/system/activity-timeouts'],
            'system.activity_timeout_enforce' => ['method' => 'post', 'path' => '/api/system/activity-timeouts/pass'],
            'system.retention_status' => ['method' => 'get', 'path' => '/api/system/retention'],
            'system.retention_enforce' => ['method' => 'post', 'path' => '/api/system/retention/pass'],
        ];
    }

    /**
     * @dataProvider controlPlaneEndpointProvider
     *
     * @param  array<string, mixed>  $body
     */
    public function test_endpoint_rejects_missing_version_header(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'default',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('reason', 'missing_control_plane_version');
        $response->assertJsonPath('supported_version', ControlPlaneProtocol::VERSION);
        $response->assertJsonPath('requested_version', null);
        $response->assertJsonStructure(['remediation']);
    }

    /**
     * @dataProvider controlPlaneEndpointProvider
     *
     * @param  array<string, mixed>  $body
     */
    public function test_endpoint_rejects_unsupported_version_header(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '999',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('reason', 'unsupported_control_plane_version');
        $response->assertJsonPath('supported_version', ControlPlaneProtocol::VERSION);
        $response->assertJsonPath('requested_version', '999');
        $response->assertJsonStructure(['remediation']);
    }

    /**
     * Response-side contract: rejection payloads themselves must advertise the
     * supported control-plane version so clients can key retry/upgrade logic
     * off a single header regardless of whether the request was accepted.
     *
     * @dataProvider controlPlaneEndpointProvider
     *
     * @param  array<string, mixed>  $body
     */
    public function test_endpoint_rejection_response_carries_version_header(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'default',
        ]);

        $response->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION);
    }

    /**
     * @return array<string, array{method: string, path: string, expected: int, body?: array<string, mixed>}>
     */
    public static function controlPlaneSuccessEndpointProvider(): array
    {
        return [
            // 200-series responses produced without workflow fixtures. These
            // cover the "successful response" path — the one that used to
            // return through response()->json(...) and silently omit the
            // control-plane version header.
            'namespaces.index' => ['method' => 'get', 'path' => '/api/namespaces', 'expected' => 200],
            'namespaces.show' => ['method' => 'get', 'path' => '/api/namespaces/default', 'expected' => 200],
            'schedules.index' => ['method' => 'get', 'path' => '/api/schedules', 'expected' => 200],
            'search-attributes.index' => ['method' => 'get', 'path' => '/api/search-attributes', 'expected' => 200],
            'workers.index' => ['method' => 'get', 'path' => '/api/workers', 'expected' => 200],
            // 404-style responses from controllers that previously returned
            // through response()->json(...) on the not-found path.
            'schedules.show_missing' => ['method' => 'get', 'path' => '/api/schedules/does-not-exist', 'expected' => 404],
            'workers.show_missing' => ['method' => 'get', 'path' => '/api/workers/does-not-exist', 'expected' => 404],
        ];
    }

    /**
     * @dataProvider controlPlaneSuccessEndpointProvider
     *
     * @param  array<string, mixed>  $body
     */
    public function test_endpoint_successful_response_carries_version_header(string $method, string $path, int $expected, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => ControlPlaneProtocol::VERSION,
        ]);

        $response->assertStatus($expected);
        $response->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function sendJson(string $method, string $path, array $body, array $headers): \Illuminate\Testing\TestResponse
    {
        return match ($method) {
            'get' => $this->getJson($path, $headers),
            'delete' => $this->deleteJson($path, $body, $headers),
            'post' => $this->postJson($path, $body, $headers),
            'put' => $this->putJson($path, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }
}
