<?php

namespace App\Support;

use App\Auth\Principal;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\ServiceBoundaryAuditRecorder;
use Workflow\V2\Support\ServiceBoundaryRequest;
use Workflow\V2\Support\ServiceCallPrincipal;

/**
 * Server-side admission gate for cross-namespace service calls.
 *
 * Wraps the workflow package's {@see ServiceBoundaryPolicy} with the
 * server's principal model and persists the resulting boundary
 * decision into the durable `workflow_service_calls` audit table. The
 * gate runs *before* handler dispatch — every dispatch surface
 * (sync HTTP, worker bridge, future SDK transport) is expected to call
 * {@see admit()} first and only proceed if {@see ServiceCallAdmission::accepted()}
 * is true.
 *
 * The audit row is written for both accepted and rejected admissions.
 * Operators querying for a caller's recent activity see the same row
 * shape regardless of outcome; only the durable outcome fields differ.
 *
 * Concurrency tracking uses the policy's in-process counter; the
 * default implementation `release()`s the counter when a handler
 * reports back. Operators that need cross-process counters can bind a
 * custom policy.
 *
 * Privacy boundary: the gate never inspects payload material. Payload
 * privacy stays under the existing codec / data-converter trust
 * boundaries.
 */
final class ServiceCallBoundary
{
    public function __construct(
        private readonly ServiceBoundaryPolicy $policy,
        private readonly ServiceBoundaryAuditRecorder $recorder,
    ) {
    }

    /**
     * Evaluate a service-call admission request, persist the audit row,
     * and return the typed admission result.
     */
    public function admit(ServiceBoundaryRequest $request): ServiceCallAdmission
    {
        $decision = $this->policy->evaluate($request);
        $call = $this->recorder->record($request, $decision);

        return new ServiceCallAdmission($decision, $call, $request);
    }

    /**
     * Convenience entry-point that builds the {@see ServiceBoundaryRequest}
     * from a server-side {@see Principal} plus the resolved catalog
     * record. This is what HTTP and worker dispatch surfaces call.
     */
    public function admitFor(
        Principal $principal,
        ?string $callerNamespace,
        WorkflowServiceOperation $operation,
        string $endpointName,
        string $serviceName,
        ?string $callerWorkflowInstanceId = null,
        ?string $callerWorkflowRunId = null,
        ?string $linkedWorkflowInstanceId = null,
        ?string $linkedWorkflowRunId = null,
        ?string $linkedWorkflowUpdateId = null,
        ?string $idempotencyKey = null,
        ?string $operationModeOverride = null,
        array $endpointBoundaryPolicy = [],
        array $serviceBoundaryPolicy = [],
        array $operationBoundaryPolicy = [],
        ?array $deadlinePolicy = null,
        ?array $idempotencyPolicy = null,
        ?array $cancellationPolicy = null,
        ?array $retryPolicy = null,
    ): ServiceCallAdmission {
        $request = new ServiceBoundaryRequest(
            principal: self::principalFromAuth($principal),
            callerNamespace: $callerNamespace,
            targetNamespace: (string) $operation->namespace,
            endpointName: $endpointName,
            serviceName: $serviceName,
            operationName: (string) $operation->operation_name,
            operationMode: ServiceCallOperationMode::tryFromCatalog($operationModeOverride)
                ?? ServiceCallOperationMode::tryFromCatalog($operation->operation_mode)
                ?? ServiceCallOperationMode::Async,
            resolvedBindingKind: (string) $operation->handler_binding_kind,
            resolvedTargetReference: $operation->handler_target_reference,
            callerWorkflowInstanceId: $callerWorkflowInstanceId,
            callerWorkflowRunId: $callerWorkflowRunId,
            linkedWorkflowInstanceId: $linkedWorkflowInstanceId,
            linkedWorkflowRunId: $linkedWorkflowRunId,
            linkedWorkflowUpdateId: $linkedWorkflowUpdateId,
            idempotencyKey: $idempotencyKey,
            endpointBoundaryPolicy: $endpointBoundaryPolicy,
            serviceBoundaryPolicy: $serviceBoundaryPolicy,
            operationBoundaryPolicy: $operationBoundaryPolicy,
            deadlinePolicy: $deadlinePolicy,
            idempotencyPolicy: $idempotencyPolicy,
            cancellationPolicy: $cancellationPolicy,
            retryPolicy: $retryPolicy,
        );

        return $this->admit($request);
    }

    /**
     * Release a previously admitted call from the policy's in-flight
     * counters. Dispatch surfaces call this once a handler reports
     * back so concurrency budget does not leak.
     */
    public function release(ServiceBoundaryRequest $request): void
    {
        if ($this->policy instanceof DefaultServiceBoundaryPolicy) {
            $this->policy->release($request);
        }
    }

    private static function principalFromAuth(Principal $principal): ServiceCallPrincipal
    {
        return new ServiceCallPrincipal(
            subject: $principal->subject(),
            method: $principal->method(),
            roles: $principal->roles(),
            tenant: $principal->tenant(),
            claims: $principal->claims(),
        );
    }
}
