<?php

namespace Tests\Feature;

use App\Auth\Principal;
use App\Support\ServerPollingCache;
use App\Support\ServiceCallBoundary;
use App\Support\SharedServiceBoundaryPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\ServiceBoundaryAuditRecorder;

class ServiceCallAdmissionRecoveryTest extends TestCase
{
    use DatabaseMigrations;
    use ServerTestHelpers;

    private const PATH = '/api/service-endpoints/billing/services/invoices/operations/create/execute';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        $this->createNamespace('other');
        config([
            'cache.default' => 'array',
            'server.service_boundary.shared_admission.max_requests_per_minute_per_namespace' => null,
            'server.service_boundary.shared_admission.max_in_flight_per_namespace' => null,
        ]);
        Cache::store('array')->flush();
        $policy = new SharedServiceBoundaryPolicy(app(ServerPollingCache::class), [
            'rate_limit' => ['requests_per_minute' => 1],
        ]);
        $this->app->instance(ServiceBoundaryPolicy::class, $policy);
        $this->app->instance(ServiceCallBoundary::class, new ServiceCallBoundary($policy, new ServiceBoundaryAuditRecorder));
    }

    public function test_canonical_replay_precedes_quota_and_foreign_caller_cannot_create_a_loser(): void
    {
        $operation = $this->seedOperation('activity_execution');
        $boundary = app(ServiceCallBoundary::class);
        $admit = fn (string $key, string $caller = 'default') => $boundary->admitFor(
            principal: Principal::role('operator', 'none'), callerNamespace: $caller,
            operation: $operation, endpointName: 'billing', serviceName: 'invoices', idempotencyKey: $key,
        );
        $first = $admit('same-key');
        $original = $first->call->fresh()->getRawOriginal();
        $replay = $admit('same-key');
        $this->assertSame($first->call->id, $replay->call->id);
        $this->assertTrue($replay->replayed);
        $this->assertSame($original, $first->call->fresh()->getRawOriginal());
        try {
            $admit('same-key', 'other');
            $this->fail('A foreign caller must not reuse the canonical call.');
        } catch (ServiceOperationAuthorizationException) {
            $this->assertDatabaseCount('workflow_service_calls', 1);
        }
        $this->assertSame($original, $first->call->fresh()->getRawOriginal());
        $this->assertSame('rejected_throttled', $admit('different-key')->decision->outcome->value);
    }

    #[DataProvider('callerRaceCases')]
    public function test_a_winner_inserted_after_http_prelookup_is_authorized_without_an_extra_audit(string $kind, bool $foreign): void
    {
        $operation = $this->seedOperation($kind);
        $boundary = app(ServiceCallBoundary::class);
        $interleaved = false;
        $winner = null;
        DB::connection()->beforeStartingTransaction(function () use (&$interleaved, &$winner, $boundary, $operation, $foreign): void {
            if ($interleaved) {
                return;
            }
            $interleaved = true;
            $winner = $boundary->admitFor(
                principal: Principal::role('operator', 'none'), callerNamespace: $foreign ? 'other' : 'default',
                operation: $operation, endpointName: 'billing', serviceName: 'invoices', idempotencyKey: 'same-key',
            )->call;
        });
        $response = $this->withHeaders($this->apiHeaders())->postJson(self::PATH, ['idempotency_key' => 'same-key']);
        $this->assertTrue($interleaved);
        if ($foreign) {
            $response->assertForbidden()->assertJsonPath('reason', 'service_operation_forbidden');
            $this->assertArrayNotHasKey('activity_execution_id', $winner->fresh()->metadata ?? []);
            $this->assertArrayNotHasKey('carrier_request_id', $winner->fresh()->metadata ?? []);
        } else {
            $response->assertOk()->assertJsonPath('service_call_id', $winner->id)->assertJsonPath('idempotent_replay', true);
            $field = $kind === 'activity_execution' ? 'activity_execution_id' : 'carrier_request_id';
            $this->assertNotEmpty($winner->fresh()->metadata[$field] ?? null);
        }
        $this->assertDatabaseCount('workflow_service_calls', 1);
        $this->assertSame(! $foreign && $kind === 'activity_execution' ? 1 : 0,
            WorkflowServiceCall::query()->whereNotNull('metadata->activity_execution_id')->count());
        $this->assertSame(! $foreign && $kind !== 'activity_execution' ? 1 : 0,
            WorkflowServiceCall::query()->whereNotNull('metadata->carrier_request_id')->count());
    }

    #[DataProvider('committedFailureCases')]
    public function test_postcommit_failures_return_the_durable_identity_and_only_keyed_lock_retries_are_safe(string $kind, string $stage, bool $keyed): void
    {
        $this->seedOperation($kind);
        $real = app(ServiceControlPlane::class);
        $armed = true;
        $refreshArmed = false;
        $fail = $stage === 'after_handler_error'
            ? static fn () => throw new RuntimeException('fixture response failure')
            : static fn () => throw new QueryException('sqlite', 'fixture writer', [], new PDOException('database is locked'));
        if ($stage === 'audit_commit') {
            WorkflowServiceCall::created(static function () use (&$armed, $fail): void {
                if ($armed) {
                    DB::afterCommit($fail);
                }
            });
        }
        $plane = Mockery::mock(ServiceControlPlane::class);
        $plane->shouldReceive('execute')->andReturnUsing(function (...$arguments) use ($real, &$armed, &$refreshArmed, $stage, $fail) {
            if ($armed && $stage === 'before_handler') {
                $fail();
            }
            $result = $real->execute(...$arguments);
            if ($armed && in_array($stage, ['after_handler', 'after_handler_error'], true)) {
                $fail();
            }
            $refreshArmed = $armed && $stage === 'response_refresh';

            return $result;
        });
        $plane->shouldReceive('describeCall')->andReturnUsing(fn (...$arguments) => $real->describeCall(...$arguments));
        $this->app->instance(ServiceControlPlane::class, $plane);
        WorkflowServiceCall::retrieved(static function () use (&$refreshArmed, $fail): void {
            if ($refreshArmed) {
                $refreshArmed = false;
                $fail();
            }
        });
        $body = $keyed ? ['idempotency_key' => 'same-key'] : [];
        $response = $this->withHeaders($this->apiHeaders())->postJson(self::PATH, $body);
        $armed = false;
        $call = WorkflowServiceCall::query()->sole();
        $retryable = $keyed && $stage !== 'after_handler_error';
        $response->assertStatus(503)->assertJsonPath('service_call_id', $call->id)
            ->assertJsonPath('namespace', 'default')->assertJsonPath('retryable', $retryable)
            ->assertJsonPath('reason', $retryable ? 'backend_lock_pressure' : 'service_operation_outcome_unknown');
        if ($retryable) {
            $response->assertHeader('Retry-After', '1');
        } else {
            $response->assertHeaderMissing('Retry-After');
        }
        $issued = in_array($stage, ['after_handler', 'after_handler_error', 'response_refresh'], true);
        $this->assertSame($issued && $kind === 'activity_execution' ? 1 : 0,
            WorkflowServiceCall::query()->whereNotNull('metadata->activity_execution_id')->count());
        $this->assertSame($issued && $kind !== 'activity_execution' ? 1 : 0,
            WorkflowServiceCall::query()->whereNotNull('metadata->carrier_request_id')->count());
        $issuedField = $kind === 'activity_execution' ? 'activity_execution_id' : 'carrier_request_id';
        $issuedId = $call->metadata[$issuedField] ?? null;
        if ($keyed) {
            $this->withHeaders($this->apiHeaders())->postJson(self::PATH, $body)
                ->assertOk()->assertJsonPath('service_call_id', $call->id)->assertJsonPath('idempotent_replay', true);
            $this->assertDatabaseCount('workflow_service_calls', 1);
            $this->assertSame($kind === 'activity_execution' ? 1 : 0,
                WorkflowServiceCall::query()->whereNotNull('metadata->activity_execution_id')->count());
            $this->assertNotEmpty($call->fresh()->metadata[$issuedField] ?? null);
            if ($issuedId !== null) {
                $this->assertSame($issuedId, $call->fresh()->metadata[$issuedField]);
            }
        }
        $this->withHeaders($this->apiHeaders())->postJson(self::PATH, ['idempotency_key' => 'different-key'])->assertStatus(429);
    }

    public static function callerRaceCases(): iterable
    {
        foreach (['activity_execution', 'invocable_carrier_request'] as $kind) {
            foreach ([false, true] as $foreign) {
                yield $kind.'-'.(int) $foreign => [$kind, $foreign];
            }
        }
    }

    public static function committedFailureCases(): iterable
    {
        foreach (['activity_execution', 'invocable_carrier_request'] as $kind) {
            foreach (['audit_commit', 'before_handler', 'after_handler', 'after_handler_error', 'response_refresh'] as $stage) {
                foreach ([false, true] as $keyed) {
                    yield $kind.'-'.$stage.'-'.(int) $keyed => [$kind, $stage, $keyed];
                }
            }
        }
    }

    private function seedOperation(string $kind): WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => 'default', 'endpoint_name' => 'billing']);
        $service = WorkflowService::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id, 'service_name' => 'invoices',
        ]);

        return WorkflowServiceOperation::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id, 'operation_name' => 'create',
            'operation_mode' => 'async', 'handler_binding_kind' => $kind, 'handler_target_reference' => 'fixture-target',
        ]);
    }
}
