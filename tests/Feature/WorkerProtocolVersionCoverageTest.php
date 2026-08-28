<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use App\Support\SourceRelease;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins version and namespace-error ordering for every worker-plane endpoint.
 * Worker clients should always receive the worker protocol envelope, even
 * when a bad request also includes control-plane headers or an unknown
 * namespace.
 */
class WorkerProtocolVersionCoverageTest extends TestCase
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
    public static function workerEndpointProvider(): array
    {
        return [
            'worker.register' => ['method' => 'post', 'path' => '/api/worker/register'],
            'worker.deregister' => ['method' => 'delete', 'path' => '/api/worker/registrations/worker-1'],
            'worker.heartbeat' => ['method' => 'post', 'path' => '/api/worker/heartbeat'],
            'workflow-tasks.poll' => ['method' => 'post', 'path' => '/api/worker/workflow-tasks/poll'],
            'workflow-tasks.history' => ['method' => 'post', 'path' => '/api/worker/workflow-tasks/task-1/history'],
            'workflow-tasks.heartbeat' => ['method' => 'post', 'path' => '/api/worker/workflow-tasks/task-1/heartbeat'],
            'workflow-tasks.complete' => ['method' => 'post', 'path' => '/api/worker/workflow-tasks/task-1/complete'],
            'workflow-tasks.fail' => ['method' => 'post', 'path' => '/api/worker/workflow-tasks/task-1/fail'],
            'query-tasks.poll' => ['method' => 'post', 'path' => '/api/worker/query-tasks/poll'],
            'query-tasks.complete' => ['method' => 'post', 'path' => '/api/worker/query-tasks/task-1/complete'],
            'query-tasks.fail' => ['method' => 'post', 'path' => '/api/worker/query-tasks/task-1/fail'],
            'activity-tasks.poll' => ['method' => 'post', 'path' => '/api/worker/activity-tasks/poll'],
            'activity-tasks.complete' => ['method' => 'post', 'path' => '/api/worker/activity-tasks/task-1/complete'],
            'activity-tasks.fail' => ['method' => 'post', 'path' => '/api/worker/activity-tasks/task-1/fail'],
            'activity-tasks.heartbeat' => ['method' => 'post', 'path' => '/api/worker/activity-tasks/task-1/heartbeat'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('workerEndpointProvider')]
    public function test_endpoint_rejects_missing_protocol_version_header(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ]);

        $response->assertStatus(400)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing('X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'missing_protocol_version')
            ->assertJsonPath('supported_version', WorkerProtocol::VERSION)
            ->assertJsonPath('requested_version', null)
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonStructure(['remediation'])
            ->assertJsonMissingPath('control_plane');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('workerEndpointProvider')]
    public function test_endpoint_rejects_unsupported_protocol_version_header(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => '999',
        ]);

        $response->assertStatus(400)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing('X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'unsupported_protocol_version')
            ->assertJsonPath('supported_version', WorkerProtocol::VERSION)
            ->assertJsonPath('requested_version', '999')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonStructure(['remediation'])
            ->assertJsonMissingPath('control_plane');
    }

    public function test_default_source_release_accepts_the_current_protocol_and_rejects_the_next_minor(): void
    {
        $this->getJson('/api/cluster/info')
            ->assertOk()
            ->assertJsonPath('version', SourceRelease::version())
            ->assertJsonPath('worker_protocol.version', WorkerProtocol::VERSION);

        $registration = [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'current-protocol-worker',
            'task_queue' => 'protocol-current',
            'runtime' => 'php',
            'sdk_version' => 'durable-workflow/workflow current',
            'supported_workflow_types' => ['CurrentProtocolWorkflow'],
            'supported_activity_types' => ['CurrentProtocolActivity'],
            'capabilities' => ['query_tasks'],
        ];

        $this->postJson('/api/worker/register', $registration, [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ])->assertCreated()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('registered', true);

        $newerVersion = self::nextMinor(WorkerProtocol::VERSION);
        $registration['worker_id'] = 'newer-protocol-worker';

        $this->postJson('/api/worker/register', $registration, [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => $newerVersion,
        ])->assertStatus(400)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'unsupported_protocol_version')
            ->assertJsonPath('supported_version', WorkerProtocol::VERSION)
            ->assertJsonPath('requested_version', $newerVersion);
    }

    public function test_worker_registration_accepts_protocol_1_7_workers(): void
    {
        $response = $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'php-protocol-17-worker',
            'task_queue' => 'polyglot-current',
            'runtime' => 'php',
            'sdk_version' => 'durable-workflow/workflow 2.0.0-alpha.147',
            'supported_workflow_types' => ['PolyglotWorkflow'],
            'supported_activity_types' => ['PolyglotActivity'],
            'capabilities' => ['query_tasks'],
        ], [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => '1.7',
        ]);

        $response->assertCreated()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('worker_id', 'php-protocol-17-worker')
            ->assertJsonPath('registered', true)
            ->assertJsonPath('server_capabilities.query_tasks', true);
    }

    public function test_worker_registration_accepts_previous_protocol_1_10_workers(): void
    {
        $response = $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'php-protocol-110-worker',
            'task_queue' => 'polyglot-current',
            'runtime' => 'php',
            'sdk_version' => 'durable-workflow/workflow 2.0.0-alpha.200',
            'supported_workflow_types' => ['PolyglotWorkflow'],
            'supported_activity_types' => ['PolyglotActivity'],
            'capabilities' => ['query_tasks'],
        ], [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => '1.10',
        ]);

        $response->assertCreated()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('worker_id', 'php-protocol-110-worker')
            ->assertJsonPath('registered', true)
            ->assertJsonPath('server_capabilities.query_tasks', true);
    }

    public function test_worker_registration_accepts_current_protocol_1_15_workers(): void
    {
        $response = $this->postJson('/api/worker/register', [
            'capability_manifest' => $this->portableWorkerAffinityRefusalManifest(),
            'worker_id' => 'php-protocol-115-worker',
            'task_queue' => 'signals-queries-current',
            'runtime' => 'php',
            'sdk_version' => 'durable-workflow/workflow 2.0.0-alpha.250',
            'supported_workflow_types' => ['SignalQueryMirrorWorkflow'],
            'supported_activity_types' => ['SignalQueryMirrorActivity'],
            'capabilities' => ['query_tasks'],
        ], [
            'X-Namespace' => 'default',
            WorkerProtocol::HEADER => '1.15',
        ]);

        $response->assertCreated()
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('worker_id', 'php-protocol-115-worker')
            ->assertJsonPath('registered', true)
            ->assertJsonPath('server_capabilities.query_tasks', true);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('workerEndpointProvider')]
    public function test_missing_protocol_version_header_beats_unknown_namespace(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'ghost-namespace',
        ]);

        $response->assertStatus(400)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'missing_protocol_version')
            ->assertJsonPath('supported_version', WorkerProtocol::VERSION);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('workerEndpointProvider')]
    public function test_namespace_errors_use_worker_protocol_contract(string $method, string $path, array $body = []): void
    {
        $response = $this->sendJson($method, $path, $body, [
            'X-Namespace' => 'ghost-namespace',
            WorkerProtocol::HEADER => WorkerProtocol::VERSION,
        ]);

        $response->assertStatus(404)
            ->assertHeader(WorkerProtocol::HEADER, WorkerProtocol::VERSION)
            ->assertHeaderMissing('X-Durable-Workflow-Control-Plane-Version')
            ->assertJsonPath('protocol_version', WorkerProtocol::VERSION)
            ->assertJsonPath('reason', 'namespace_not_found')
            ->assertJsonPath('namespace', 'ghost-namespace')
            ->assertJsonPath('server_capabilities.workflow_task_poll_request_idempotency', true)
            ->assertJsonMissingPath('control_plane');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function sendJson(string $method, string $path, array $body, array $headers): TestResponse
    {
        return match ($method) {
            'delete' => $this->deleteJson($path, $body, $headers),
            'post' => $this->postJson($path, $body, $headers),
            'put' => $this->putJson($path, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    private static function nextMinor(string $version): string
    {
        [$major, $minor] = array_map('intval', explode('.', $version, 2));

        return sprintf('%d.%d', $major, $minor + 1);
    }
}
