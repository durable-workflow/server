<?php

namespace Tests\Feature;

use App\Auth\Principal;
use App\Support\ServiceCallAdmission;
use App\Support\ServiceCallBoundary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;

/**
 * End-to-end coverage for the server's cross-namespace service-call
 * boundary. The boundary is the gate that every dispatch surface goes
 * through *before* any handler runs.
 */
class ServiceCallBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('finance');
        $this->createNamespace('analytics');
    }

    public function test_admit_persists_the_audit_row_for_an_accepted_call(): void
    {
        $operation = $this->seedOperation(operationMode: 'sync');

        /** @var ServiceCallBoundary $boundary */
        $boundary = $this->app->make(ServiceCallBoundary::class);

        $admission = $boundary->admitFor(
            principal: Principal::role('worker', 'token'),
            callerNamespace: 'analytics',
            operation: $operation,
            endpointName: 'billing',
            serviceName: 'invoicing',
            callerWorkflowInstanceId: 'caller-wf',
            idempotencyKey: 'invoice-1',
        );

        $this->assertTrue($admission->accepted());
        $this->assertSame(ServiceCallOutcome::Accepted, $admission->decision->outcome);
        $this->assertSame('accepted', $admission->call->status);
        $this->assertSame('analytics', $admission->call->caller_namespace);
        $this->assertSame('finance', $admission->call->target_namespace);
        $this->assertSame(ServiceCallBindingKind::WorkflowUpdate->value, $admission->call->resolved_binding_kind);
        $this->assertSame('worker', $admission->call->caller_principal_roles[0]);
        $this->assertNotNull($admission->call->accepted_at);
        $this->assertNull($admission->call->failed_at);

        $this->assertDatabaseHas('workflow_service_calls', [
            'id' => $admission->call->id,
            'outcome' => 'accepted',
            'outcome_category' => 'accepted',
            'caller_principal_subject' => 'role:worker',
        ]);
    }

    public function test_admit_fails_closed_when_handler_binding_kind_is_unknown(): void
    {
        $operation = $this->seedOperation();
        $operation->update(['handler_binding_kind' => 'unsupported_handler']);

        /** @var ServiceCallBoundary $boundary */
        $boundary = $this->app->make(ServiceCallBoundary::class);

        $admission = $boundary->admitFor(
            principal: Principal::role('worker', 'token'),
            callerNamespace: 'analytics',
            operation: $operation,
            endpointName: 'billing',
            serviceName: 'invoicing',
        );

        $this->assertTrue($admission->rejected());
        $this->assertSame(ServiceCallOutcome::RejectedNotFound, $admission->decision->outcome);
        $this->assertSame('unknown_binding_kind', $admission->decision->reason);
        $this->assertSame('failed', $admission->call->status);
        $this->assertSame('unresolved', $admission->call->resolved_binding_kind);
        $this->assertNull($admission->call->resolved_target_reference);
        $this->assertSame('handler_binding_kind', $admission->call->outcome_metadata['resolution_failed_at']);
        $this->assertNull($admission->call->accepted_at);
    }

    public function test_admit_blocks_call_when_caller_namespace_is_denied(): void
    {
        $this->bindStrictNamespacePolicy(['untrusted']);

        $operation = $this->seedOperation();

        /** @var ServiceCallBoundary $boundary */
        $boundary = $this->app->make(ServiceCallBoundary::class);

        $admission = $boundary->admitFor(
            principal: Principal::role('worker', 'token'),
            callerNamespace: 'untrusted',
            operation: $operation,
            endpointName: 'billing',
            serviceName: 'invoicing',
        );

        $this->assertTrue($admission->rejected());
        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $admission->decision->outcome);
        $this->assertSame(403, $admission->httpStatus());
        $this->assertSame('failed', $admission->call->status);
        $this->assertSame('caller_namespace_denied', $admission->call->outcome_reason);
        $this->assertNotNull($admission->call->failed_at);
        $this->assertNull($admission->call->accepted_at);

        $this->assertDatabaseHas('workflow_service_calls', [
            'id' => $admission->call->id,
            'outcome' => 'rejected_forbidden',
            'outcome_category' => 'rejected',
            'caller_namespace' => 'untrusted',
        ]);
    }

    public function test_admit_returns_429_with_retry_when_rate_limit_blocks_dispatch(): void
    {
        $this->bindRateLimitPolicy(perMinute: 1, retryAfterSeconds: 5);

        $operation = $this->seedOperation();

        /** @var ServiceCallBoundary $boundary */
        $boundary = $this->app->make(ServiceCallBoundary::class);

        $first = $this->admit($boundary, $operation);
        $second = $this->admit($boundary, $operation);

        $this->assertTrue($first->accepted());
        $this->assertTrue($second->rejected());
        $this->assertSame(ServiceCallOutcome::RejectedThrottled, $second->decision->outcome);
        $this->assertSame(429, $second->httpStatus());
        $this->assertSame(5, $second->call->retry_after_seconds);
        $this->assertSame(1, $second->call->outcome_metadata['requests_per_minute']);
    }

    public function test_admit_returns_429_when_concurrency_budget_is_exhausted_for_sync_calls(): void
    {
        $this->bindConcurrencyPolicy(maxInFlight: 1, retryAfterSeconds: 2);

        $operation = $this->seedOperation(operationMode: 'sync');

        /** @var ServiceCallBoundary $boundary */
        $boundary = $this->app->make(ServiceCallBoundary::class);

        $first = $this->admit($boundary, $operation);
        $second = $this->admit($boundary, $operation);

        $this->assertTrue($first->accepted());
        $this->assertTrue($second->rejected());
        $this->assertSame(ServiceCallOutcome::RejectedConcurrencyLimited, $second->decision->outcome);
        $this->assertSame(429, $second->httpStatus());
        $this->assertSame(2, $second->call->retry_after_seconds);

        // Once the in-flight call completes, the budget should free up.
        $boundary->release($first->request);
        $third = $this->admit($boundary, $operation);
        $this->assertTrue($third->accepted());
    }

    public function test_admit_records_the_authenticated_principal_for_audit(): void
    {
        $operation = $this->seedOperation();

        /** @var ServiceCallBoundary $boundary */
        $boundary = $this->app->make(ServiceCallBoundary::class);

        $admission = $boundary->admitFor(
            principal: new Principal(
                subject: 'pat:abc123',
                roles: ['operator'],
                method: 'token',
                tenant: 'acme',
                claims: ['scope' => 'service.invoke'],
            ),
            callerNamespace: 'analytics',
            operation: $operation,
            endpointName: 'billing',
            serviceName: 'invoicing',
        );

        $this->assertTrue($admission->accepted());
        $this->assertSame('pat:abc123', $admission->call->caller_principal_subject);
        $this->assertSame('token', $admission->call->caller_principal_method);
        $this->assertSame(['operator'], $admission->call->caller_principal_roles);
        $this->assertSame('acme', $admission->call->caller_principal_tenant);
        $this->assertSame('service.invoke', $admission->call->caller_principal_claims['scope']);
    }

    private function seedOperation(string $operationMode = 'async'): WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'finance',
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => 'finance',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoicing',
        ]);

        return WorkflowServiceOperation::query()->create([
            'namespace' => 'finance',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'create',
            'operation_mode' => $operationMode,
            'handler_binding_kind' => 'update_workflow',
            'handler_target_reference' => 'updates.invoice.create',
        ]);
    }

    private function admit(ServiceCallBoundary $boundary, WorkflowServiceOperation $operation): ServiceCallAdmission
    {
        return $boundary->admitFor(
            principal: Principal::role('worker', 'token'),
            callerNamespace: 'analytics',
            operation: $operation,
            endpointName: 'billing',
            serviceName: 'invoicing',
        );
    }

    /**
     * @param list<string> $denyCallers
     */
    private function bindStrictNamespacePolicy(array $denyCallers): void
    {
        $this->app->forgetInstance(ServiceBoundaryPolicy::class);
        $this->app->instance(ServiceBoundaryPolicy::class, new DefaultServiceBoundaryPolicy([
            'namespaces' => ['deny_callers' => $denyCallers],
        ]));
        $this->app->forgetInstance(ServiceCallBoundary::class);
    }

    private function bindRateLimitPolicy(int $perMinute, int $retryAfterSeconds): void
    {
        $this->app->forgetInstance(ServiceBoundaryPolicy::class);
        $this->app->instance(ServiceBoundaryPolicy::class, new DefaultServiceBoundaryPolicy([
            'rate_limit' => [
                'requests_per_minute' => $perMinute,
                'retry_after_seconds' => $retryAfterSeconds,
            ],
        ]));
        $this->app->forgetInstance(ServiceCallBoundary::class);
    }

    private function bindConcurrencyPolicy(int $maxInFlight, int $retryAfterSeconds): void
    {
        $this->app->forgetInstance(ServiceBoundaryPolicy::class);
        $this->app->instance(ServiceBoundaryPolicy::class, new DefaultServiceBoundaryPolicy([
            'concurrency' => [
                'max_in_flight' => $maxInFlight,
                'retry_after_seconds' => $retryAfterSeconds,
                'sync_only' => true,
            ],
        ]));
        $this->app->forgetInstance(ServiceCallBoundary::class);
    }
}
