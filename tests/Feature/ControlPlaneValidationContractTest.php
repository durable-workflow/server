<?php

namespace Tests\Feature;

use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;

class ControlPlaneValidationContractTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    /**
     * @return array<string, array{method: string, path: string, body: array<string, mixed>, errorField: string, controlOperation: string|null}>
     */
    public static function validationEndpointProvider(): array
    {
        return [
            'workflow.start' => [
                'method' => 'post',
                'path' => '/api/workflows',
                'body' => [],
                'errorField' => 'workflow_type',
                'controlOperation' => 'start',
            ],
            'workflow.list' => [
                'method' => 'get',
                'path' => '/api/workflows?page_size=0',
                'body' => [],
                'errorField' => 'page_size',
                'controlOperation' => 'list',
            ],
            'history.show' => [
                'method' => 'get',
                'path' => '/api/workflows/wf-validation/runs/run-validation/history?page_size=0',
                'body' => [],
                'errorField' => 'page_size',
                'controlOperation' => null,
            ],
            'namespaces.store' => [
                'method' => 'post',
                'path' => '/api/namespaces',
                'body' => [],
                'errorField' => 'name',
                'controlOperation' => null,
            ],
            'namespaces.update' => [
                'method' => 'put',
                'path' => '/api/namespaces/default',
                'body' => ['retention_days' => 0],
                'errorField' => 'retention_days',
                'controlOperation' => null,
            ],
            'schedules.store' => [
                'method' => 'post',
                'path' => '/api/schedules',
                'body' => [],
                'errorField' => 'spec',
                'controlOperation' => null,
            ],
            'search-attributes.store' => [
                'method' => 'post',
                'path' => '/api/search-attributes',
                'body' => [],
                'errorField' => 'name',
                'controlOperation' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('validationEndpointProvider')]
    public function test_validation_errors_use_the_control_plane_error_contract(
        string $method,
        string $path,
        array $body,
        string $errorField,
        ?string $controlOperation,
    ): void {
        $response = $this->sendJson($method, $path, $body, $this->apiHeaders());

        $response->assertStatus(422)
            ->assertHeader(ControlPlaneProtocol::HEADER, ControlPlaneProtocol::VERSION)
            ->assertHeaderMissing(WorkerProtocol::HEADER)
            ->assertJsonPath(
                "errors.{$errorField}.0",
                static fn (mixed $message): bool => is_string($message) && $message !== '',
            )
            ->assertJsonPath(
                "validation_errors.{$errorField}.0",
                static fn (mixed $message): bool => is_string($message) && $message !== '',
            );

        if ($controlOperation === null) {
            $response->assertJsonMissingPath('control_plane');

            return;
        }

        $response->assertJsonPath('control_plane.schema', 'durable-workflow.v2.control-plane-response')
            ->assertJsonPath('control_plane.version', 1)
            ->assertJsonPath('control_plane.operation', $controlOperation)
            ->assertJsonPath('control_plane.contract.schema', 'durable-workflow.v2.control-plane-response.contract')
            ->assertJsonPath('control_plane.validation_errors.'.$errorField.'.0', fn (mixed $message): bool => is_string($message) && $message !== '');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function sendJson(string $method, string $path, array $body, array $headers): TestResponse
    {
        return match ($method) {
            'get' => $this->getJson($path, $headers),
            'post' => $this->postJson($path, $body, $headers),
            'put' => $this->putJson($path, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }
}
