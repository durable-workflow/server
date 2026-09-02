<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceNamespaceRequestAdmission;
use App\Models\WorkflowNamespace;
use App\Support\NamespaceRequestAdmission;
use App\Support\ServerPollingCache;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NamespaceRequestAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowNamespace::query()->create([
            'name' => 'default',
            'description' => 'Default namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);
    }

    public function test_control_plane_rate_limit_is_namespace_scoped_and_retryable(): void
    {
        config(['server.namespace_admission.max_requests_per_minute' => 1]);
        WorkflowNamespace::query()->create([
            'name' => 'tenant-b',
            'description' => 'Control namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);

        $this->getJson('/api/workflows', $this->headers('default'))->assertOk();

        $this->getJson('/api/workflows', $this->headers('default'))
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('reason', 'namespace_request_rate_exhausted')
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('limit', 1);

        $this->getJson('/api/workflows', $this->headers('tenant-b'))->assertOk();
    }

    public function test_namespace_override_cannot_exceed_the_hard_ceiling(): void
    {
        config([
            'server.namespace_admission.max_requests_per_minute' => 10,
            'server.namespace_admission.hard_max_requests_per_minute' => 1,
            'server.namespace_admission.overrides' => [
                'default' => ['max_requests_per_minute' => 100],
            ],
        ]);

        $this->getJson('/api/workflows', $this->headers())->assertOk();
        $this->getJson('/api/workflows', $this->headers())
            ->assertStatus(429)
            ->assertJsonPath('limit', 1);
    }

    public function test_concurrency_limit_rejects_nested_work_and_releases_after_completion(): void
    {
        config(['server.namespace_admission.max_concurrent_requests' => 1]);
        $admission = app(NamespaceRequestAdmission::class);

        $rejection = $admission->execute(
            'default',
            fn (): array => $admission->execute(
                'default',
                fn (): array => ['accepted' => true],
                fn (array $decision): array => $decision,
            ),
            fn (array $decision): array => $decision,
        );

        $this->assertSame('namespace_request_concurrency_exhausted', $rejection['reason']);
        $this->assertSame(429, $rejection['status']);

        $accepted = $admission->execute(
            'default',
            fn (): string => 'accepted',
            fn (array $decision): string => $decision['reason'],
        );

        $this->assertSame('accepted', $accepted);
    }

    public function test_concurrency_slot_is_released_when_the_request_throws(): void
    {
        config(['server.namespace_admission.max_concurrent_requests' => 1]);
        $admission = app(NamespaceRequestAdmission::class);

        try {
            $admission->execute(
                'default',
                static fn (): never => throw new RuntimeException('request failed'),
                fn (array $decision): string => $decision['reason'],
            );
            $this->fail('Expected the request exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('request failed', $exception->getMessage());
        }

        $this->assertSame('accepted', $admission->execute(
            'default',
            fn (): string => 'accepted',
            fn (array $decision): string => $decision['reason'],
        ));
    }

    public function test_configured_admission_fails_closed_when_shared_cache_is_unavailable(): void
    {
        config(['server.namespace_admission.max_requests_per_minute' => 10]);
        $admission = new NamespaceRequestAdmission($this->unavailableCache());

        $decision = $admission->execute(
            'default',
            fn (): array => ['accepted' => true],
            fn (array $rejection): array => $rejection,
        );

        $this->assertSame('namespace_request_admission_unavailable', $decision['reason']);
        $this->assertSame(503, $decision['status']);
        $this->assertTrue($decision['retryable']);
    }

    public function test_explicit_unlimited_default_does_not_require_shared_cache(): void
    {
        config([
            'server.namespace_admission.max_requests_per_minute' => null,
            'server.namespace_admission.max_concurrent_requests' => null,
            'server.namespace_admission.hard_max_requests_per_minute' => null,
            'server.namespace_admission.hard_max_concurrent_requests' => null,
        ]);
        $admission = new NamespaceRequestAdmission($this->unavailableCache());

        $this->assertSame('accepted', $admission->execute(
            'default',
            fn (): string => 'accepted',
            fn (array $decision): string => $decision['reason'],
        ));
    }

    public function test_invalid_configured_limit_fails_closed_instead_of_disabling_admission(): void
    {
        foreach ([0, -1, 'invalid', 1.5] as $invalidLimit) {
            config(['server.namespace_admission.max_requests_per_minute' => $invalidLimit]);

            $this->getJson('/api/workflows', $this->headers())
                ->assertStatus(503)
                ->assertHeader('Retry-After', '1')
                ->assertJsonPath('reason', 'namespace_request_admission_unavailable')
                ->assertJsonPath('retryable', true);
        }

        $this->getJson('/api/system/metrics', $this->headers())
            ->assertOk()
            ->assertJsonPath(
                'metrics.'.NamespaceRequestAdmission::METRIC_NAME.'.configuration_status',
                'invalid',
            );
    }

    public function test_metrics_report_fixed_reason_rejections_for_the_requested_namespace(): void
    {
        config(['server.namespace_admission.max_requests_per_minute' => 1]);

        $this->getJson('/api/workflows', $this->headers())->assertOk();
        $this->getJson('/api/workflows', $this->headers())->assertStatus(429);

        $this->getJson('/api/system/metrics', $this->headers())
            ->assertOk()
            ->assertJsonPath(
                'metrics.'.NamespaceRequestAdmission::METRIC_NAME.'.rejections_this_minute',
                1,
            )
            ->assertJsonPath(
                'metrics.'.NamespaceRequestAdmission::METRIC_NAME.'.rejections_by_reason.namespace_request_rate_exhausted',
                1,
            );
    }

    public function test_route_boundary_admits_customer_control_plane_but_not_operator_or_worker_recovery_surfaces(): void
    {
        foreach ([
            ['GET', 'api/workflows'],
            ['POST', 'api/schedules'],
            ['POST', 'api/service-endpoints/{endpointName}/services/{serviceName}/operations/{operationName}/execute'],
        ] as [$method, $uri]) {
            $this->assertContains(
                EnforceNamespaceRequestAdmission::class,
                $this->routeMiddleware($method, $uri),
                "Expected {$method} {$uri} to enforce namespace request admission.",
            );
        }

        foreach ([
            ['GET', 'api/cluster/info'],
            ['GET', 'api/system/metrics'],
            ['POST', 'api/system/repair/pass'],
            ['POST', 'api/worker/workflow-tasks/poll'],
            ['POST', 'api/external-payloads/v1'],
        ] as [$method, $uri]) {
            $this->assertNotContains(
                EnforceNamespaceRequestAdmission::class,
                $this->routeMiddleware($method, $uri),
                "Expected {$method} {$uri} to remain outside namespace request admission.",
            );
        }
    }

    /** @return array<string, string> */
    private function headers(string $namespace = 'default'): array
    {
        return [
            'X-Durable-Workflow-Control-Plane-Version' => '2',
            'X-Namespace' => $namespace,
        ];
    }

    private function unavailableCache(): ServerPollingCache
    {
        $factory = Mockery::mock(CacheFactory::class);
        $factory->shouldReceive('store')->andThrow(new RuntimeException('cache unavailable'));

        return new ServerPollingCache($factory, new Filesystem);
    }

    /** @return array<int, string> */
    private function routeMiddleware(string $method, string $uri): array
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            static fn ($route): bool => in_array($method, $route->methods(), true)
                && $route->uri() === $uri,
        );

        $this->assertNotNull($route, "Route {$method} {$uri} was not registered.");

        return $route->gatherMiddleware();
    }
}
