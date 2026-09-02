<?php

namespace Tests\Feature;

use App\Support\ServerPollingCache;
use App\Support\ServiceCallAdmission;
use App\Support\SharedServiceBoundaryPolicy;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Support\ServiceBoundaryRequest;
use Workflow\V2\Support\ServiceCallPrincipal;

class SharedServiceBoundaryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'server.service_boundary.shared_admission' => [
                'max_requests_per_minute_per_namespace' => null,
                'max_in_flight_per_namespace' => null,
                'hard_max_requests_per_minute' => null,
                'hard_max_in_flight' => null,
                'namespace_overrides' => [],
                'concurrency_lease_ttl_seconds' => 86400,
                'retry_after_seconds' => 1,
            ],
        ]);

        Cache::store('array')->flush();
    }

    public function test_rate_budget_is_shared_across_policy_instances(): void
    {
        $rules = [
            'rate_limit' => [
                'requests_per_minute' => 1,
                'retry_after_seconds' => 7,
            ],
        ];
        $request = $this->request();

        $first = $this->policy($rules)->evaluate($request);
        $second = $this->policy($rules)->evaluate($request);

        $this->assertTrue($first->isAllowed());
        $this->assertSame(ServiceCallOutcome::RejectedThrottled, $second->outcome);
        $this->assertSame('rate_limit_exceeded', $second->reason);
        $this->assertSame(7, $second->retryAfterSeconds);
        $this->assertSame('boundary', $second->metadata['admission_scope']);
    }

    public function test_namespace_rate_budget_spans_distinct_service_boundaries(): void
    {
        config(['server.service_boundary.shared_admission.max_requests_per_minute_per_namespace' => 1]);

        $first = $this->policy()->evaluate($this->request(operation: 'create'));
        $second = $this->policy()->evaluate($this->request(operation: 'refund'));

        $this->assertTrue($first->isAllowed());
        $this->assertSame(ServiceCallOutcome::RejectedThrottled, $second->outcome);
        $this->assertSame('caller_namespace', $second->metadata['admission_scope']);
        $this->assertSame(1, $second->metadata['requests_per_minute']);
    }

    public function test_concurrency_budget_is_shared_and_release_frees_capacity(): void
    {
        $rules = [
            'concurrency' => [
                'max_in_flight' => 1,
                'retry_after_seconds' => 3,
                'sync_only' => true,
            ],
        ];
        $request = $this->request(mode: ServiceCallOperationMode::Sync);
        $firstPolicy = $this->policy($rules);

        $first = $firstPolicy->evaluate($request);
        $second = $this->policy($rules)->evaluate($request);

        $this->assertTrue($first->isAllowed());
        $this->assertSame(ServiceCallOutcome::RejectedConcurrencyLimited, $second->outcome);
        $this->assertSame(3, $second->retryAfterSeconds);

        $firstPolicy->release($request);

        $this->assertTrue($this->policy($rules)->evaluate($request)->isAllowed());
    }

    public function test_releasing_the_same_reservation_twice_does_not_free_another_call(): void
    {
        $rules = ['concurrency' => ['max_in_flight' => 2]];
        $policy = $this->policy($rules);
        $firstRequest = $this->request();
        $secondRequest = $this->request();

        $this->assertTrue($policy->evaluate($firstRequest)->isAllowed());
        $this->assertTrue($policy->evaluate($secondRequest)->isAllowed());

        $policy->release($firstRequest);
        $policy->release($firstRequest);

        $this->assertTrue($policy->evaluate($this->request())->isAllowed());
        $this->assertSame(
            ServiceCallOutcome::RejectedConcurrencyLimited,
            $policy->evaluate($this->request())->outcome,
        );
    }

    public function test_sync_only_rate_budget_does_not_throttle_async_calls(): void
    {
        $rules = [
            'rate_limit' => [
                'requests_per_minute' => 1,
                'sync_only' => true,
            ],
        ];
        $request = $this->request(mode: ServiceCallOperationMode::Async);

        $this->assertTrue($this->policy($rules)->evaluate($request)->isAllowed());
        $this->assertTrue($this->policy($rules)->evaluate($request)->isAllowed());
    }

    public function test_hard_ceiling_caps_namespace_override(): void
    {
        config([
            'server.service_boundary.shared_admission.max_requests_per_minute_per_namespace' => 10,
            'server.service_boundary.shared_admission.hard_max_requests_per_minute' => 1,
            'server.service_boundary.shared_admission.namespace_overrides' => [
                ' ANALYTICS ' => ['max_requests_per_minute' => 50],
            ],
        ]);

        $this->assertTrue($this->policy()->evaluate($this->request(operation: 'create'))->isAllowed());

        $rejected = $this->policy()->evaluate($this->request(operation: 'refund'));
        $this->assertSame(ServiceCallOutcome::RejectedThrottled, $rejected->outcome);
        $this->assertSame(1, $rejected->metadata['requests_per_minute']);
    }

    public function test_invalid_override_fails_closed(): void
    {
        config(['server.service_boundary.shared_admission.namespace_overrides' => [
            'analytics' => ['unexpected' => 1],
        ]]);

        $decision = $this->policy()->evaluate($this->request());

        $this->assertSame(ServiceCallOutcome::RejectedThrottled, $decision->outcome);
        $this->assertSame('service_boundary_admission_unavailable', $decision->reason);
    }

    public function test_authorization_and_circuit_rules_still_run_before_shared_admission(): void
    {
        config(['server.service_boundary.shared_admission.max_requests_per_minute_per_namespace' => 10]);

        $namespaceDenied = $this->policy([
            'namespaces' => ['deny_callers' => ['analytics']],
        ])->evaluate($this->request());
        $circuitDenied = $this->policy([
            'circuit_break' => ['open' => true],
        ])->evaluate($this->request());

        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $namespaceDenied->outcome);
        $this->assertSame('caller_namespace_denied', $namespaceDenied->reason);
        $this->assertSame(ServiceCallOutcome::RejectedCircuitOpen, $circuitDenied->outcome);
        $this->assertSame('circuit_open', $circuitDenied->reason);
    }

    public function test_unlimited_policy_does_not_require_shared_cache(): void
    {
        $decision = $this->policy(cache: $this->unavailableCache())->evaluate($this->request());

        $this->assertTrue($decision->isAllowed());
    }

    public function test_configured_policy_fails_closed_when_shared_cache_is_unavailable(): void
    {
        $decision = $this->policy(
            rules: ['rate_limit' => ['requests_per_minute' => 1]],
            cache: $this->unavailableCache(),
        )->evaluate($this->request());

        $this->assertSame(ServiceCallOutcome::RejectedThrottled, $decision->outcome);
        $this->assertSame('service_boundary_admission_unavailable', $decision->reason);

        $admission = new ServiceCallAdmission(
            $decision,
            new WorkflowServiceCall,
            $this->request(),
        );
        $this->assertSame(503, $admission->httpStatus());
    }

    public function test_replay_authorization_does_not_consume_rate_budget(): void
    {
        $rules = ['rate_limit' => ['requests_per_minute' => 1]];
        $request = $this->request();
        $policy = $this->policy($rules);

        $this->assertTrue($policy->authorizeReplay($request)->isAllowed());
        $this->assertTrue($policy->evaluate($request)->isAllowed());
        $this->assertSame(
            ServiceCallOutcome::RejectedThrottled,
            $this->policy($rules)->evaluate($request)->outcome,
        );
    }

    /** @param array<string, mixed> $rules */
    private function policy(
        array $rules = [],
        ?ServerPollingCache $cache = null,
    ): SharedServiceBoundaryPolicy {
        return new SharedServiceBoundaryPolicy($cache ?? $this->sharedCache(), $rules);
    }

    private function sharedCache(): ServerPollingCache
    {
        return new ServerPollingCache(
            $this->app->make(CacheFactory::class),
            new Filesystem,
        );
    }

    private function unavailableCache(): ServerPollingCache
    {
        $factory = Mockery::mock(CacheFactory::class);
        $factory->shouldReceive('store')->andThrow(new RuntimeException('cache unavailable'));

        return new ServerPollingCache($factory, new Filesystem);
    }

    private function request(
        string $operation = 'create',
        ServiceCallOperationMode $mode = ServiceCallOperationMode::Sync,
    ): ServiceBoundaryRequest {
        return new ServiceBoundaryRequest(
            principal: ServiceCallPrincipal::system('test-worker'),
            callerNamespace: 'analytics',
            targetNamespace: 'finance',
            endpointName: 'billing',
            serviceName: 'invoicing',
            operationName: $operation,
            operationMode: $mode,
        );
    }
}
