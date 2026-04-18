<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
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
            'workers.show_missing' => [
                'method' => 'get',
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
