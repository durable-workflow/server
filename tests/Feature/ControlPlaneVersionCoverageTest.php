<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
            // WorkflowController + HistoryController
            'workflows.index' => ['method' => 'get', 'path' => '/api/workflows'],
            'workflows.start' => [
                'method' => 'post',
                'path' => '/api/workflows',
                'body' => ['workflow_type' => 'AnyWorkflow'],
            ],
            'workflows.show' => ['method' => 'get', 'path' => '/api/workflows/wf-any'],
            'workflows.debug' => ['method' => 'get', 'path' => '/api/workflows/wf-any/debug'],
            'workflows.runs' => ['method' => 'get', 'path' => '/api/workflows/wf-any/runs'],
            'workflows.show_run' => ['method' => 'get', 'path' => '/api/workflows/wf-any/runs/run-any'],
            'workflows.debug_run' => ['method' => 'get', 'path' => '/api/workflows/wf-any/runs/run-any/debug'],
            'workflows.signal' => ['method' => 'post', 'path' => '/api/workflows/wf-any/signal/advance'],
            'workflows.query' => ['method' => 'post', 'path' => '/api/workflows/wf-any/query/currentState'],
            'workflows.update' => ['method' => 'post', 'path' => '/api/workflows/wf-any/update/approve'],
            'workflows.cancel' => ['method' => 'post', 'path' => '/api/workflows/wf-any/cancel'],
            'workflows.terminate' => ['method' => 'post', 'path' => '/api/workflows/wf-any/terminate'],
            'workflows.repair' => ['method' => 'post', 'path' => '/api/workflows/wf-any/repair'],
            'workflows.archive' => ['method' => 'post', 'path' => '/api/workflows/wf-any/archive'],
            'workflows.signal_run' => ['method' => 'post', 'path' => '/api/workflows/wf-any/runs/run-any/signal/advance'],
            'workflows.query_run' => ['method' => 'post', 'path' => '/api/workflows/wf-any/runs/run-any/query/currentState'],
            'workflows.update_run' => ['method' => 'post', 'path' => '/api/workflows/wf-any/runs/run-any/update/approve'],
            'workflows.cancel_run' => ['method' => 'post', 'path' => '/api/workflows/wf-any/runs/run-any/cancel'],
            'workflows.terminate_run' => ['method' => 'post', 'path' => '/api/workflows/wf-any/runs/run-any/terminate'],
            'history.show' => ['method' => 'get', 'path' => '/api/workflows/wf-any/runs/run-any/history'],
            'history.export' => ['method' => 'get', 'path' => '/api/workflows/wf-any/runs/run-any/history/export'],

            // BridgeAdapterController
            'bridge-adapters.webhook' => [
                'method' => 'post',
                'path' => '/api/bridge-adapters/webhook/github',
                'body' => [
                    'action' => 'start_workflow',
                    'idempotency_key' => 'provider-event-1',
                    'target' => ['workflow_type' => 'AnyWorkflow'],
                ],
            ],

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
            'task-queues.build_ids' => ['method' => 'get', 'path' => '/api/task-queues/default/build-ids'],

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
            'system.metrics' => ['method' => 'get', 'path' => '/api/system/metrics'],
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
            'workflows.index' => ['method' => 'get', 'path' => '/api/workflows', 'expected' => 200],
            'workflows.show_missing' => ['method' => 'get', 'path' => '/api/workflows/does-not-exist', 'expected' => 404],
            'workflows.debug_missing' => ['method' => 'get', 'path' => '/api/workflows/does-not-exist/debug', 'expected' => 404],
            'workflows.runs_missing' => ['method' => 'get', 'path' => '/api/workflows/does-not-exist/runs', 'expected' => 404],
            'workflows.show_run_missing' => ['method' => 'get', 'path' => '/api/workflows/does-not-exist/runs/run-missing', 'expected' => 404],
            'workflows.debug_run_missing' => ['method' => 'get', 'path' => '/api/workflows/does-not-exist/runs/run-missing/debug', 'expected' => 404],
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
     * TD-S050: on namespace-validated routes, a missing or unsupported
     * control-plane version header must win over a namespace_not_found
     * error when both conditions apply. The version-check middleware runs
     * ahead of NamespaceResolver, so clients learn about the protocol skew
     * first and can upgrade without chasing a red-herring 404.
     *
     * @return array<string, array{method: string, path: string, body?: array<string, mixed>}>
     */
    public static function namespaceValidatedEndpointProvider(): array
    {
        $all = self::controlPlaneEndpointProvider();

        // NamespaceResolver exempts the /api/namespaces/* endpoints — those
        // do not validate the target namespace's existence, so the ordering
        // bug never surfaces there. Everything else is namespace-validated.
        return array_filter($all, static fn (string $key): bool => ! str_starts_with($key, 'namespaces.'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @dataProvider namespaceValidatedEndpointProvider
     *
     * @param  array<string, mixed>  $body
     */
    public function test_missing_version_header_beats_unknown_namespace(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'ghost-namespace',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('reason', 'missing_control_plane_version');
        $response->assertJsonPath('supported_version', ControlPlaneProtocol::VERSION);
    }

    /**
     * @dataProvider namespaceValidatedEndpointProvider
     *
     * @param  array<string, mixed>  $body
     */
    public function test_unsupported_version_header_beats_unknown_namespace(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'ghost-namespace',
            'X-Durable-Workflow-Control-Plane-Version' => '999',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('reason', 'unsupported_control_plane_version');
        $response->assertJsonPath('requested_version', '999');
        $response->assertJsonPath('supported_version', ControlPlaneProtocol::VERSION);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function sendJson(string $method, string $path, array $body, array $headers): TestResponse
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
