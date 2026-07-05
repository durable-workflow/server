<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

class NexusOperationsHistoryTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->createNamespace('billing');
        $this->createNamespace('finance');
    }

    public function test_cluster_info_publishes_the_nexus_contract_manifest(): void
    {
        $response = $this->getJson('/api/cluster/info');

        $response->assertOk()
            ->assertJsonPath('capabilities.nexus', true)
            ->assertJsonPath('nexus_contract.schema', 'durable-workflow.v2.nexus.contract')
            ->assertJsonPath('nexus_contract.version', 2)
            ->assertJsonPath('nexus_contract.parity_target.name', 'Nexus')
            ->assertJsonPath('nexus_contract.cluster_info_key', 'nexus_contract')
            ->assertJsonPath('nexus_contract.capability_flag', 'nexus')
            ->assertJsonPath(
                'nexus_contract.host_runner_contract.runner_path',
                'scripts/conformance/nexus-published-artifacts.sh',
            )
            ->assertJsonPath(
                'nexus_contract.host_runner_contract.must_record_runner_blocked_false_for_product_evidence',
                true,
            )
            ->assertJsonPath(
                'nexus_contract.underlying_execution_contract',
                'durable-workflow.v2.service-execution.contract',
            )
            ->assertJsonPath(
                'nexus_contract.namespace_acl_enforcement.admission_gate',
                'App\\Support\\ServiceCallBoundary',
            )
            ->assertJsonPath(
                'nexus_contract.multi_namespace_caller_pattern.per_caller_registration_required',
                false,
            );

        $responseFields = $response->json('nexus_contract.caller_history_surface.response_fields');
        $this->assertIsArray($responseFields);

        foreach (['outcome_metadata', 'service_error_type', 'caller_observed_error_type', 'typed_error_message'] as $field) {
            $this->assertContains($field, $responseFields);
        }
    }

    public function test_caller_history_for_workflow_returns_only_calls_made_from_that_workflow(): void
    {
        [$endpoint, $service, $operation] = $this->createCatalog('billing', 'invoicing', 'createinvoice');

        $callerInstanceA = 'finance-invoice-orchestrator';
        $callerRunA = (string) Str::ulid();

        $callA1 = $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'finance',
            callerInstanceId: $callerInstanceA,
            callerRunId: $callerRunA,
            outcome: 'completed',
            status: 'completed',
        );

        $callA2 = $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'finance',
            callerInstanceId: $callerInstanceA,
            callerRunId: $callerRunA,
            outcome: 'rejected_forbidden',
            status: 'failed',
        );

        // A call from a different caller workflow in the same caller
        // namespace must not leak into the first workflow's history
        // surface.
        $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'finance',
            callerInstanceId: 'finance-other-orchestrator',
            callerRunId: (string) Str::ulid(),
            outcome: 'completed',
            status: 'completed',
        );

        $response = $this->withHeaders($this->apiHeaders('finance'))
            ->getJson(sprintf('/api/workflows/%s/nexus-operations', $callerInstanceA));

        $response->assertOk()
            ->assertJsonPath('workflow_id', $callerInstanceA)
            ->assertJsonPath('caller_namespace', 'finance')
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'nexus_operations');

        $ids = collect($response->json('nexus_operations'))->pluck('service_call_id')->all();
        $this->assertContains($callA1->id, $ids);
        $this->assertContains($callA2->id, $ids);

        $first = collect($response->json('nexus_operations'))
            ->firstWhere('service_call_id', $callA2->id);
        $this->assertSame('rejected_forbidden', $first['outcome']);
        $this->assertSame('failed', $first['status']);
        $this->assertSame('billing', $first['target_namespace']);
        $this->assertSame('finance', $first['caller_namespace']);
        $this->assertSame('createinvoice', $first['operation_name']);
    }

    public function test_caller_history_for_run_filters_to_a_single_run_id(): void
    {
        [$endpoint, $service, $operation] = $this->createCatalog('billing', 'invoicing', 'createinvoice');

        $instanceId = 'finance-invoice-orchestrator';
        $runOne = (string) Str::ulid();
        $runTwo = (string) Str::ulid();

        $runOneCall = $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'finance',
            callerInstanceId: $instanceId,
            callerRunId: $runOne,
            outcome: 'completed',
            status: 'completed',
        );

        $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'finance',
            callerInstanceId: $instanceId,
            callerRunId: $runTwo,
            outcome: 'completed',
            status: 'completed',
        );

        $response = $this->withHeaders($this->apiHeaders('finance'))
            ->getJson(sprintf(
                '/api/workflows/%s/runs/%s/nexus-operations',
                $instanceId,
                $runOne,
            ));

        $response->assertOk()
            ->assertJsonPath('workflow_id', $instanceId)
            ->assertJsonPath('workflow_run_id', $runOne)
            ->assertJsonPath('count', 1)
            ->assertJsonCount(1, 'nexus_operations')
            ->assertJsonPath('nexus_operations.0.service_call_id', $runOneCall->id);
    }

    public function test_caller_history_is_scoped_to_caller_namespace(): void
    {
        [$endpoint, $service, $operation] = $this->createCatalog('billing', 'invoicing', 'createinvoice');

        $sharedInstanceId = 'shared-instance-id';

        $financeCall = $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'finance',
            callerInstanceId: $sharedInstanceId,
            callerRunId: (string) Str::ulid(),
            outcome: 'completed',
            status: 'completed',
        );

        $defaultCall = $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'default',
            callerInstanceId: $sharedInstanceId,
            callerRunId: (string) Str::ulid(),
            outcome: 'completed',
            status: 'completed',
        );

        $financeResponse = $this->withHeaders($this->apiHeaders('finance'))
            ->getJson(sprintf('/api/workflows/%s/nexus-operations', $sharedInstanceId));

        $financeResponse->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('nexus_operations.0.service_call_id', $financeCall->id);

        $defaultResponse = $this->withHeaders($this->apiHeaders('default'))
            ->getJson(sprintf('/api/workflows/%s/nexus-operations', $sharedInstanceId));

        $defaultResponse->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('nexus_operations.0.service_call_id', $defaultCall->id);
    }

    public function test_a_single_nexus_service_serves_callers_from_multiple_caller_namespaces(): void
    {
        [$endpoint, $service, $operation] = $this->createCatalog('billing', 'invoicing', 'createinvoice');

        $callerNamespaces = ['default', 'finance'];

        foreach ($callerNamespaces as $i => $namespace) {
            $this->recordCall(
                namespace: 'billing',
                endpoint: $endpoint,
                service: $service,
                operation: $operation,
                callerNamespace: $namespace,
                callerInstanceId: 'caller-'.$namespace,
                callerRunId: (string) Str::ulid(),
                outcome: 'completed',
                status: 'completed',
            );
        }

        // All callers hit the same registered service / operation rows; the
        // catalog has exactly one operation row regardless of how many
        // caller namespaces invoke it.
        $this->assertSame(
            1,
            WorkflowServiceOperation::query()
                ->where('namespace', 'billing')
                ->where('operation_name', 'createinvoice')
                ->count(),
        );

        // Each caller is recorded independently with its own caller_namespace.
        foreach ($callerNamespaces as $namespace) {
            $this->assertSame(
                1,
                WorkflowServiceCall::query()
                    ->where('caller_namespace', $namespace)
                    ->where('caller_workflow_instance_id', 'caller-'.$namespace)
                    ->count(),
            );
        }

        // And the boundary's audit trail has each caller's row addressable
        // through the caller-history surface in the caller's own namespace.
        foreach ($callerNamespaces as $namespace) {
            $response = $this->withHeaders($this->apiHeaders($namespace))
                ->getJson(sprintf('/api/workflows/caller-%s/nexus-operations', $namespace));

            $response->assertOk()
                ->assertJsonPath('caller_namespace', $namespace)
                ->assertJsonPath('count', 1)
                ->assertJsonPath('nexus_operations.0.target_namespace', 'billing');
        }
    }

    public function test_caller_history_exposes_retry_attempt_visibility(): void
    {
        [$endpoint, $service, $operation] = $this->createCatalog('billing', 'invoicing', 'createinvoice');

        $call = $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'finance',
            callerInstanceId: 'finance-retry-orchestrator',
            callerRunId: (string) Str::ulid(),
            outcome: 'completed',
            status: 'completed',
            retryPolicy: [
                'max_attempts' => 3,
                'backoff_seconds' => [1, 2],
            ],
            attempts: [
                [
                    'attempt' => 1,
                    'status' => 'failed',
                    'outcome' => 'handler_failed',
                    'failure_type' => 'TransientGreetingFailure',
                    'retry_scheduled' => true,
                    'scheduled_backoff_seconds' => 1,
                ],
                [
                    'attempt' => 2,
                    'status' => 'failed',
                    'outcome' => 'handler_failed',
                    'failure_type' => 'TransientGreetingFailure',
                    'retry_scheduled' => true,
                    'scheduled_backoff_seconds' => 2,
                ],
                [
                    'attempt' => 3,
                    'status' => 'completed',
                    'outcome' => 'completed',
                    'retry_scheduled' => false,
                ],
            ],
        );

        $response = $this->withHeaders($this->apiHeaders('finance'))
            ->getJson('/api/workflows/finance-retry-orchestrator/nexus-operations');

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('nexus_operations.0.service_call_id', $call->id)
            ->assertJsonPath('nexus_operations.0.retry_policy.max_attempts', 3)
            ->assertJsonPath('nexus_operations.0.retry_attempt_count', 3)
            ->assertJsonPath('nexus_operations.0.service_call_attempts.0.attempt', 1)
            ->assertJsonPath('nexus_operations.0.service_call_attempts.0.failure_type', 'TransientGreetingFailure')
            ->assertJsonPath('nexus_operations.0.service_call_attempts.1.scheduled_backoff_seconds', 2)
            ->assertJsonPath('nexus_operations.0.service_call_attempts.2.outcome', 'completed');
    }

    public function test_caller_history_and_service_detail_expose_typed_worker_errors(): void
    {
        [$endpoint, $service, $operation] = $this->createCatalog('billing', 'invoicing', 'createinvoice');

        $call = $this->recordCall(
            namespace: 'billing',
            endpoint: $endpoint,
            service: $service,
            operation: $operation,
            callerNamespace: 'finance',
            callerInstanceId: 'finance-typed-error-orchestrator',
            callerRunId: (string) Str::ulid(),
            outcome: 'handler_failed',
            status: 'failed',
            attempts: [
                [
                    'attempt' => 1,
                    'status' => 'failed',
                    'outcome' => 'handler_failed',
                    'failure_type' => 'SharedGreeterUnavailable',
                    'failure_message' => 'shared greeter is permanently unavailable',
                    'retry_scheduled' => false,
                ],
            ],
            outcomeMetadata: [
                'failure_reason' => 'handler_failure',
                'service_error_type' => 'SharedGreeterUnavailable',
                'caller_observed_error_type' => 'SharedGreeterUnavailable',
                'typed_error_message' => 'shared greeter is permanently unavailable',
            ],
            failureMessage: 'shared greeter is permanently unavailable',
        );

        $history = $this->withHeaders($this->apiHeaders('finance'))
            ->getJson('/api/workflows/finance-typed-error-orchestrator/nexus-operations');

        $history->assertOk()
            ->assertJsonPath('nexus_operations.0.service_call_id', $call->id)
            ->assertJsonPath('nexus_operations.0.outcome_metadata.service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('nexus_operations.0.service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('nexus_operations.0.caller_observed_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('nexus_operations.0.typed_error_message', 'shared greeter is permanently unavailable');

        $detail = $this->withHeaders($this->apiHeaders('billing'))
            ->getJson(sprintf(
                '/api/service-endpoints/billing/services/invoicing/operations/createinvoice/service-calls/%s',
                $call->id,
            ));

        $detail->assertOk()
            ->assertJsonPath('service_call_id', $call->id)
            ->assertJsonPath('outcome_metadata.service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('service_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('caller_observed_error_type', 'SharedGreeterUnavailable')
            ->assertJsonPath('typed_error_message', 'shared greeter is permanently unavailable');
    }

    public function test_caller_history_limit_is_capped_by_configured_maximum(): void
    {
        config()->set('server.limits.max_nexus_operations_per_caller', 5);

        [$endpoint, $service, $operation] = $this->createCatalog('billing', 'invoicing', 'createinvoice');

        $instance = 'finance-invoice-orchestrator';
        $runId = (string) Str::ulid();

        for ($i = 0; $i < 10; $i++) {
            $this->recordCall(
                namespace: 'billing',
                endpoint: $endpoint,
                service: $service,
                operation: $operation,
                callerNamespace: 'finance',
                callerInstanceId: $instance,
                callerRunId: $runId,
                outcome: 'completed',
                status: 'completed',
            );
        }

        $response = $this->withHeaders($this->apiHeaders('finance'))
            ->getJson(sprintf(
                '/api/workflows/%s/runs/%s/nexus-operations?limit=50',
                $instance,
                $runId,
            ));

        $response->assertOk()
            ->assertJsonPath('limit', 5)
            ->assertJsonPath('count', 5)
            ->assertJsonCount(5, 'nexus_operations');
    }

    /**
     * @return array{0: WorkflowServiceEndpoint, 1: WorkflowService, 2: WorkflowServiceOperation}
     */
    private function createCatalog(string $namespace, string $serviceName, string $operationName): array
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => $namespace,
            'endpoint_name' => 'billing',
        ]);

        $service = WorkflowService::query()->create([
            'namespace' => $namespace,
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => $serviceName,
        ]);

        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => $namespace,
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => $operationName,
            'operation_mode' => 'async',
            'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => 'workflows.invoice.create',
        ]);

        return [$endpoint, $service, $operation];
    }

    private function recordCall(
        string $namespace,
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
        string $callerNamespace,
        string $callerInstanceId,
        string $callerRunId,
        string $outcome,
        string $status,
        array $retryPolicy = [],
        array $attempts = [],
        array $outcomeMetadata = [],
        ?string $failureMessage = null,
    ): WorkflowServiceCall {
        return WorkflowServiceCall::query()->create([
            'namespace' => $namespace,
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'operation_name' => $operation->operation_name,
            'caller_namespace' => $callerNamespace,
            'caller_workflow_instance_id' => $callerInstanceId,
            'caller_workflow_run_id' => $callerRunId,
            'target_namespace' => $namespace,
            'status' => $status,
            'outcome' => $outcome,
            'operation_mode' => 'async',
            'resolved_binding_kind' => 'workflow_run',
            'resolved_target_reference' => 'workflows.invoice.create',
            'retry_policy' => $retryPolicy === [] ? null : $retryPolicy,
            'metadata' => $attempts === [] ? null : [
                'service_call_attempts' => $attempts,
                'retry_attempt_count' => count($attempts),
            ],
            'outcome_metadata' => $outcomeMetadata === [] ? null : $outcomeMetadata,
            'failure_message' => $failureMessage,
            'caller_principal_subject' => 'svc:'.$callerNamespace,
            'caller_principal_method' => 'token',
            'accepted_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null,
            'failed_at' => $status === 'failed' ? now() : null,
        ]);
    }
}
