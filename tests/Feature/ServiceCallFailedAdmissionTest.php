<?php

namespace Tests\Feature;

use App\Auth\Principal;
use App\Models\WorkflowNamespace;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\RuntimeExternalPayloadWriteClaims;
use App\Support\ServerPollingCache;
use App\Support\ServiceCallAdmissionOutcomeUnknown;
use App\Support\ServiceCallBoundary;
use App\Support\SharedServiceBoundaryPolicy;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\ServiceBoundaryAuditRecorder;
use Workflow\V2\Support\ServiceBoundaryRequest;
use Workflow\V2\Support\ServiceCallPrincipal;

class ServiceCallFailedAdmissionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_audit_metadata_commit_leaves_payload_claims_for_the_actual_reference_writer(): void
    {
        $registry = Mockery::mock(RuntimeExternalPayloadRegistry::class);
        $registry->shouldReceive('claimVerified')->once()->with('finance', 'fixture://prepared', 1, hash('sha256', 'x'))
            ->andReturnUsing(function (): void {
                $this->assertSame(1, DB::connection()->transactionLevel());
                $this->assertTrue(WorkflowNamespace::query()->where('name', 'reference-writer')->exists());
            });
        $this->app->instance(RuntimeExternalPayloadRegistry::class, $registry);
        $claims = app(RuntimeExternalPayloadWriteClaims::class);
        $claims->transport(function () use ($claims): void {
            $claims->verified('finance', 'fixture://prepared', 1, hash('sha256', 'x'));
            $boundary = new ServiceCallBoundary(
                new SharedServiceBoundaryPolicy(app(ServerPollingCache::class), []), new ServiceBoundaryAuditRecorder,
            );
            $admission = $boundary->admit(new ServiceBoundaryRequest(
                principal: new ServiceCallPrincipal('fixture', 'token'), callerNamespace: 'finance', targetNamespace: 'finance',
                endpointName: 'billing', serviceName: 'invoicing', operationName: 'create', operationMode: ServiceCallOperationMode::Sync,
                resolvedBindingKind: 'activity_execution', resolvedTargetReference: 'fixture-activity',
            ));
            $this->assertTrue($admission->accepted());
            DB::transaction(fn () => WorkflowNamespace::query()->create(['name' => 'reference-writer', 'retention_days' => 30]));
        });
    }

    #[DataProvider('rateScopes')]
    public function test_failed_audit_refunds_only_its_uncommitted_admission(string $scope, bool $convenience, string $failurePoint): void
    {
        Carbon::setTestNow('2026-10-27T01:00:05Z');
        try {
            config([
                'cache.default' => 'array',
                'server.service_boundary.shared_admission.max_requests_per_minute_per_namespace' => $scope === 'boundary' ? null : 1,
                'server.service_boundary.shared_admission.max_in_flight_per_namespace' => 1,
            ]);
            Cache::store('array')->flush();
            $rules = ['concurrency' => ['max_in_flight' => 1]];
            if ($scope !== 'namespace') {
                $rules['rate_limit'] = ['requests_per_minute' => 1];
            }
            $boundary = new ServiceCallBoundary(
                new SharedServiceBoundaryPolicy(app(ServerPollingCache::class), $rules),
                new ServiceBoundaryAuditRecorder,
            );
            $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => 'finance', 'endpoint_name' => 'billing']);
            $service = WorkflowService::query()->create([
                'namespace' => 'finance', 'workflow_service_endpoint_id' => $endpoint->id, 'service_name' => 'invoicing',
            ]);
            $operation = WorkflowServiceOperation::query()->create([
                'namespace' => 'finance', 'workflow_service_endpoint_id' => $endpoint->id,
                'workflow_service_id' => $service->id, 'operation_name' => 'create',
                'operation_mode' => 'sync', 'handler_binding_kind' => 'activity_execution',
                'handler_target_reference' => 'fixture-activity',
            ]);
            $admit = fn () => $convenience ? $boundary->admitFor(
                principal: Principal::role('worker', 'token'), callerNamespace: 'analytics',
                operation: $operation, endpointName: 'billing', serviceName: 'invoicing',
            ) : $boundary->admit(new ServiceBoundaryRequest(
                principal: new ServiceCallPrincipal('role:worker', 'token', ['worker']),
                callerNamespace: 'analytics', targetNamespace: 'finance', endpointName: 'billing',
                serviceName: 'invoicing', operationName: 'create', operationMode: ServiceCallOperationMode::Sync,
                resolvedBindingKind: 'activity_execution', resolvedTargetReference: 'fixture-activity',
            ));
            $locked = true;
            $event = $failurePoint === 'creating' ? 'creating' : 'created';
            WorkflowServiceCall::{$event}(static function () use (&$locked, $failurePoint): void {
                if ($locked && ! in_array($failurePoint, ['outer', 'committing'], true)) {
                    $failure = static fn () => throw new QueryException('sqlite', 'insert into workflow_service_calls', [], new PDOException('database is locked'));
                    if ($failurePoint === 'committed') {
                        DB::afterCommit($failure);
                    } else {
                        $failure();
                    }
                }
            });
            if ($failurePoint === 'committing') {
                Event::listen(TransactionCommitting::class, static function () use (&$locked): void {
                    if ($locked) {
                        throw new QueryException('sqlite', 'commit audit', [], new PDOException('database is locked'));
                    }
                });
            }
            try {
                if ($failurePoint === 'outer') {
                    DB::transaction(function () use ($admit, $boundary): void {
                        $admission = $admit();
                        $boundary->release($admission->request);
                        throw new QueryException('sqlite', 'outer writer', [], new PDOException('database is locked'));
                    });
                } else {
                    $admit();
                }
                $this->fail('The injected audit failure must propagate.');
            } catch (ServiceCallAdmissionOutcomeUnknown $exception) {
                $this->assertSame('committed', $failurePoint);
                $this->assertSame(WorkflowServiceCall::query()->sole()->id, $exception->serviceCallId);
                $this->assertSame('finance', $exception->namespace);
                $this->assertFalse($exception->keyed);
                $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
            } catch (QueryException $exception) {
                $this->assertNotSame('committed', $failurePoint);
                $this->assertStringContainsString('database is locked', $exception->getMessage());
            } finally {
                $locked = false;
            }
            if ($failurePoint === 'committed') {
                $this->assertDatabaseCount('workflow_service_calls', 1);
                $this->assertSame(ServiceCallOutcome::RejectedThrottled, $admit()->decision->outcome);
                $this->assertDatabaseCount('workflow_service_calls', 2);

                return;
            }
            $this->assertDatabaseCount('workflow_service_calls', 0);
            $committed = $admit();
            $this->assertTrue($committed->accepted());
            $boundary->release($committed->request);
            $this->assertSame(ServiceCallOutcome::RejectedThrottled, $admit()->decision->outcome);
            $this->assertDatabaseCount('workflow_service_calls', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function rateScopes(): iterable
    {
        foreach (['boundary', 'namespace', 'both'] as $scope) {
            foreach ([false, true] as $convenience) {
                foreach (['creating', 'created', 'committed', 'outer', 'committing'] as $failurePoint) {
                    yield $scope.'-'.(int) $convenience.'-'.$failurePoint => [$scope, $convenience, $failurePoint];
                }
            }
        }
    }
}
