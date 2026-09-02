<?php

namespace App\Support;

use App\Auth\Principal;
use Throwable;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\ServiceBoundaryAuditRecorder;
use Workflow\V2\Support\ServiceBoundaryDecision;
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
 * The server's default policy coordinates configured rate and concurrency
 * budgets through shared cache. `release()` frees a concurrency reservation
 * when a handler reports back.
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
    ) {}

    /**
     * Evaluate a service-call admission request, persist the audit row,
     * and return the typed admission result.
     */
    public function admit(ServiceBoundaryRequest $request): ServiceCallAdmission
    {
        $decision = $this->policy->evaluate($request);

        try {
            $call = $this->recorder->record($request, $decision);
        } catch (Throwable $throwable) {
            if ($decision->isAllowed()) {
                $this->release($request);
            }

            throw $throwable;
        }

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
        $request = $this->requestFor(
            principal: $principal,
            callerNamespace: $callerNamespace,
            operation: $operation,
            endpointName: $endpointName,
            serviceName: $serviceName,
            callerWorkflowInstanceId: $callerWorkflowInstanceId,
            callerWorkflowRunId: $callerWorkflowRunId,
            linkedWorkflowInstanceId: $linkedWorkflowInstanceId,
            linkedWorkflowRunId: $linkedWorkflowRunId,
            linkedWorkflowUpdateId: $linkedWorkflowUpdateId,
            idempotencyKey: $idempotencyKey,
            operationModeOverride: $operationModeOverride,
            endpointBoundaryPolicy: $endpointBoundaryPolicy,
            serviceBoundaryPolicy: $serviceBoundaryPolicy,
            operationBoundaryPolicy: $operationBoundaryPolicy,
            deadlinePolicy: $deadlinePolicy,
            idempotencyPolicy: $idempotencyPolicy,
            cancellationPolicy: $cancellationPolicy,
            retryPolicy: $retryPolicy,
        );
        $decision = $this->decisionFor($request, $operation);
        try {
            $call = $this->recorder->record($request, $decision);
        } catch (Throwable $throwable) {
            if ($decision->isAllowed()) {
                $this->release($request);
            }

            throw $throwable;
        }

        return new ServiceCallAdmission($decision, $call, $request);
    }

    /**
     * Authorize an idempotent replay before returning or dispatching an
     * existing call. Replays that pass policy reuse the original audit row;
     * denied replays are recorded so authorization failures keep the same
     * observable 403/audit path as a fresh call.
     */
    public function replayRejectionFor(
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
    ): ?ServiceCallAdmission {
        $request = $this->requestFor(
            principal: $principal,
            callerNamespace: $callerNamespace,
            operation: $operation,
            endpointName: $endpointName,
            serviceName: $serviceName,
            callerWorkflowInstanceId: $callerWorkflowInstanceId,
            callerWorkflowRunId: $callerWorkflowRunId,
            linkedWorkflowInstanceId: $linkedWorkflowInstanceId,
            linkedWorkflowRunId: $linkedWorkflowRunId,
            linkedWorkflowUpdateId: $linkedWorkflowUpdateId,
            idempotencyKey: $idempotencyKey,
            operationModeOverride: $operationModeOverride,
            endpointBoundaryPolicy: $endpointBoundaryPolicy,
            serviceBoundaryPolicy: $serviceBoundaryPolicy,
            operationBoundaryPolicy: $operationBoundaryPolicy,
            deadlinePolicy: $deadlinePolicy,
            idempotencyPolicy: $idempotencyPolicy,
            cancellationPolicy: $cancellationPolicy,
            retryPolicy: $retryPolicy,
        );
        $trackedAdmission = false;
        $decision = $this->decisionForReplay($request, $operation, $trackedAdmission);

        if ($decision->isAllowed()) {
            if ($trackedAdmission) {
                $this->release($request);
            }

            return null;
        }

        $call = $this->recorder->record($request, $decision);

        return new ServiceCallAdmission($decision, $call, $request);
    }

    /**
     * Release a previously admitted call from the policy's in-flight
     * counters. Dispatch surfaces call this once a handler reports
     * back so concurrency budget does not leak.
     */
    public function release(ServiceBoundaryRequest $request): void
    {
        if (is_callable([$this->policy, 'release'])) {
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

    /**
     * @param  array<string, mixed>  $endpointBoundaryPolicy
     * @param  array<string, mixed>  $serviceBoundaryPolicy
     * @param  array<string, mixed>  $operationBoundaryPolicy
     * @param  array<string, mixed>|null  $deadlinePolicy
     * @param  array<string, mixed>|null  $idempotencyPolicy
     * @param  array<string, mixed>|null  $cancellationPolicy
     * @param  array<string, mixed>|null  $retryPolicy
     */
    private function requestFor(
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
    ): ServiceBoundaryRequest {
        $resolvedBindingKind = self::resolvedBindingKind($operation);

        return new ServiceBoundaryRequest(
            principal: self::principalFromAuth($principal),
            callerNamespace: $callerNamespace,
            targetNamespace: (string) $operation->namespace,
            endpointName: $endpointName,
            serviceName: $serviceName,
            operationName: (string) $operation->operation_name,
            operationMode: ServiceCallOperationMode::tryFromCatalog($operationModeOverride)
                ?? ServiceCallOperationMode::tryFromCatalog($operation->operation_mode)
                ?? ServiceCallOperationMode::Async,
            resolvedBindingKind: $resolvedBindingKind,
            resolvedTargetReference: $resolvedBindingKind === null ? null : $operation->handler_target_reference,
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
    }

    private function decisionFor(
        ServiceBoundaryRequest $request,
        WorkflowServiceOperation $operation,
    ): ServiceBoundaryDecision {
        if ($request->resolvedBindingKind === null) {
            return new ServiceBoundaryDecision(
                outcome: ServiceCallOutcome::RejectedNotFound,
                reason: 'unknown_binding_kind',
                message: sprintf(
                    'Service operation [%s] has unknown handler binding kind [%s].',
                    $operation->operation_name,
                    $operation->handler_binding_kind,
                ),
                policyName: 'default',
                metadata: [
                    'failure_reason' => 'resolution_failure',
                    'resolution_failed_at' => 'handler_binding_kind',
                    'handler_binding_kind' => (string) $operation->handler_binding_kind,
                ],
            );
        }

        return $this->policy->evaluate($request);
    }

    private function decisionForReplay(
        ServiceBoundaryRequest $request,
        WorkflowServiceOperation $operation,
        bool &$trackedAdmission,
    ): ServiceBoundaryDecision {
        $trackedAdmission = false;

        if ($request->resolvedBindingKind === null) {
            return $this->decisionFor($request, $operation);
        }

        if (is_callable([$this->policy, 'authorizeReplay'])) {
            $decision = $this->policy->authorizeReplay($request);

            if ($decision instanceof ServiceBoundaryDecision) {
                return $decision;
            }
        }

        if ($this->policy instanceof DefaultServiceBoundaryPolicy) {
            return (new DefaultServiceBoundaryPolicy(
                self::withoutAdmissionControls($this->defaultPolicyRules()),
            ))->evaluate(self::requestWithoutAdmissionControls($request));
        }

        $trackedAdmission = true;

        return $this->policy->evaluate($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPolicyRules(): array
    {
        $property = (new \ReflectionObject($this->policy))->getProperty('rules');
        $property->setAccessible(true);
        $rules = $property->getValue($this->policy);

        return is_array($rules) ? $rules : [];
    }

    private static function requestWithoutAdmissionControls(ServiceBoundaryRequest $request): ServiceBoundaryRequest
    {
        return new ServiceBoundaryRequest(
            principal: $request->principal,
            callerNamespace: $request->callerNamespace,
            targetNamespace: $request->targetNamespace,
            endpointName: $request->endpointName,
            serviceName: $request->serviceName,
            operationName: $request->operationName,
            operationMode: $request->operationMode,
            resolvedBindingKind: $request->resolvedBindingKind,
            resolvedTargetReference: $request->resolvedTargetReference,
            callerWorkflowInstanceId: $request->callerWorkflowInstanceId,
            callerWorkflowRunId: $request->callerWorkflowRunId,
            linkedWorkflowInstanceId: $request->linkedWorkflowInstanceId,
            linkedWorkflowRunId: $request->linkedWorkflowRunId,
            linkedWorkflowUpdateId: $request->linkedWorkflowUpdateId,
            idempotencyKey: $request->idempotencyKey,
            context: $request->context,
            endpointBoundaryPolicy: self::withoutAdmissionControls($request->endpointBoundaryPolicy),
            serviceBoundaryPolicy: self::withoutAdmissionControls($request->serviceBoundaryPolicy),
            operationBoundaryPolicy: self::withoutAdmissionControls($request->operationBoundaryPolicy),
            deadlinePolicy: $request->deadlinePolicy,
            idempotencyPolicy: $request->idempotencyPolicy,
            cancellationPolicy: $request->cancellationPolicy,
            retryPolicy: $request->retryPolicy,
        );
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private static function withoutAdmissionControls(array $policy): array
    {
        foreach (['rate_limit', 'concurrency', 'concurrency_limit', 'circuit_break'] as $key) {
            unset($policy[$key]);
        }

        foreach ($policy as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $policy[$key] = self::withoutAdmissionControls($value);
            }
        }

        return $policy;
    }

    private static function resolvedBindingKind(WorkflowServiceOperation $operation): ?string
    {
        return match (strtolower(trim((string) $operation->handler_binding_kind))) {
            ServiceCallBindingKind::WorkflowRun->value,
            'start_workflow',
            'workflow_class' => ServiceCallBindingKind::WorkflowRun->value,
            ServiceCallBindingKind::WorkflowUpdate->value,
            'update_workflow' => ServiceCallBindingKind::WorkflowUpdate->value,
            ServiceCallBindingKind::WorkflowSignal->value,
            'signal_workflow' => ServiceCallBindingKind::WorkflowSignal->value,
            ServiceCallBindingKind::WorkflowQuery->value,
            'query_workflow' => ServiceCallBindingKind::WorkflowQuery->value,
            ServiceCallBindingKind::ActivityExecution->value => ServiceCallBindingKind::ActivityExecution->value,
            ServiceCallBindingKind::InvocableCarrierRequest->value,
            'invocable_http' => ServiceCallBindingKind::InvocableCarrierRequest->value,
            default => null,
        };
    }
}
