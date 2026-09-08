<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RuntimeExternalPayload;
use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;

final class WorkerServiceOperationTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->registerWorker('nexus-worker', 'nexus', supportedWorkflowTypes: ['tests.nexus-caller', 'tests.nexus-target']);
    }

    public function test_worker_command_dispatches_a_service_operation_and_persists_its_outcome(): void
    {
        $this->seedCatalog();
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();

        $this->complete($task, $this->command())->assertOk();

        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame('started', $call->status);
        $this->assertSame('billing', $call->endpoint_name);
        $this->assertSame('invoicing', $call->service_name);
        $this->assertSame('createinvoice', $call->operation_name);
        $this->assertSame($task['run_id'], $call->caller_workflow_run_id);
        $this->assertSame('default', $call->caller_namespace);
        $this->assertSame('nexus-test-principal', $call->caller_principal_subject);
        $this->assertSame(['worker'], $call->caller_principal_roles);
        $this->assertSame('default', $call->caller_principal_tenant);
        $target = WorkflowRun::query()->findOrFail($call->linked_workflow_run_id);
        $this->assertSame([['invoice' => 42]], Avro::unserialize($target->arguments));
        $this->assertDatabaseHas('workflow_tasks', ['workflow_run_id' => $target->id, 'status' => 'ready']);
        $this->assertDatabaseHas('workflow_history_events', ['workflow_run_id' => $task['run_id'], 'event_type' => 'ServiceCallStarted']);
        $this->assertSame('completed', WorkflowTask::query()->findOrFail($task['task_id'])->status->value);

        $this->registerWorker('invoice-worker', 'invoices', supportedWorkflowTypes: ['tests.nexus-target']);
        $targetTask = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'invoice-worker', 'task_queue' => 'invoices',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');
        $this->assertSame($target->id, $targetTask['run_id']);
        $this->complete($targetTask, [
            'type' => 'complete_workflow', 'result' => Avro::envelope(['invoice' => 42, 'accepted' => true]),
        ])->assertOk();
        $this->assertSame('completed', $target->fresh()->status->value);
        $this->assertSame(['invoice' => 42, 'accepted' => true], Avro::unserialize($target->fresh()->output));
    }

    public function test_supported_options_reach_the_service_control_plane(): void
    {
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();
        $options = [
            'namespace' => 'default', 'caller_namespace' => 'default', 'idempotency_key' => 'invoice-42',
            'mode_override' => 'async', 'wait_for' => 'accepted', 'wait_timeout_seconds' => '0',
            'target_workflow_instance_id' => 'invoice-42', 'target_workflow_run_id' => 'target-run',
            'connection' => 'database', 'queue' => 'invoices', 'business_key' => 'business-42',
            'labels' => ['department' => 'finance'], 'memo' => ['display' => 'Invoice 42'],
            'search_attributes' => [], 'duplicate_start_policy' => 'return_existing_active',
            'metadata' => ['trace' => 'trace-42'], 'request_payload_reference' => 'request-42',
        ];
        $this->mock(ServiceControlPlane::class)->shouldReceive('execute')->once()
            ->withArgs(function (string $endpoint, string $service, string $operation, array $actual) use ($options, $task): bool {
                $this->assertSame(['billing', 'invoicing', 'createinvoice'], [$endpoint, $service, $operation]);
                foreach ($options as $name => $value) {
                    if ($name === 'metadata') {
                        $this->assertSame($value['trace'], $actual['metadata']['trace']);
                    } else {
                        $this->assertSame($name === 'wait_timeout_seconds' ? 0 : $value, $actual[$name], $name);
                    }
                }
                $this->assertSame([['invoice' => 42]], $actual['arguments']);
                $this->assertSame('avro', $actual['payload_codec']);
                $this->assertSame('nexus-test-principal', $actual['principal_subject']);
                $this->assertSame(['worker'], $actual['principal_roles']);
                $this->assertSame($task['run_id'], $actual['caller_workflow_run_id']);

                return true;
            })->andReturn(['accepted' => true, 'service_call_id' => 'call-42', 'status' => 'started']);

        $this->complete($task, $this->command($options))->assertOk();
    }

    #[DataProvider('runtimePayloadCases')]
    public function test_runtime_payload_reference_is_resolved_before_service_command_admission(string $case): void
    {
        $this->seedCatalog();
        $task = $this->leaseWorkflow();
        $directory = storage_path('framework/testing/service-runtime-payload-'.bin2hex(random_bytes(5)));
        $namespace = $case === 'foreign' ? 'other' : 'default';
        if ($namespace === 'other') {
            $this->createNamespace($namespace);
        }
        WorkflowNamespace::query()->where('name', $namespace)->update(['external_payload_storage' => [
            'driver' => 'local', 'enabled' => true, 'threshold_bytes' => 1024,
            'config' => ['uri' => 'file://'.$directory],
        ]]);
        $blob = Avro::serialize([['invoice' => 42]]);
        try {
            $reference = $this->call('POST', '/api/external-payloads/v1', [], [], [], [
                'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_NAMESPACE' => $namespace,
                'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_CODEC' => 'avro',
                'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SIZE' => (string) strlen($blob),
                'HTTP_X_DURABLE_WORKFLOW_PAYLOAD_SHA256' => hash('sha256', $blob),
            ], $blob)->assertCreated()->json('reference');
            $this->authenticateWorker();
            if ($case === 'tampered') {
                $reference['sha256'] = str_repeat('0', 64);
            }
            $historyCount = WorkflowHistoryEvent::query()->count();
            $response = $this->complete($task, $this->command([
                'request_payload' => ['codec' => 'avro', 'external_payload' => $reference],
            ]));
            if ($case !== 'valid') {
                $response->assertStatus($case === 'foreign' ? 404 : 422)
                    ->assertJsonPath('reason', $case === 'foreign' ? 'external_payload_not_found' : 'external_payload_integrity_mismatch');
                $this->assertDatabaseCount('workflow_service_calls', 0);
                $this->assertSame($historyCount, WorkflowHistoryEvent::query()->count());
                $this->assertSame('leased', WorkflowTask::query()->findOrFail($task['task_id'])->status->value);
                $this->assertNull(RuntimeExternalPayload::query()->findOrFail($reference['reference_id'])->retained_at);

                return;
            }
            $response->assertOk();
            $call = WorkflowServiceCall::query()->sole();
            $target = WorkflowRun::query()->findOrFail($call->linked_workflow_run_id);
            $this->assertSame([['invoice' => 42]], Avro::unserialize($target->arguments));
            $this->assertNotNull(RuntimeExternalPayload::query()->findOrFail($reference['reference_id'])->retained_at);
            $this->assertDatabaseHas('workflow_history_events', [
                'workflow_run_id' => $task['run_id'], 'event_type' => 'ServiceCallStarted',
            ]);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public static function runtimePayloadCases(): array
    {
        return [['valid'], ['foreign'], ['tampered']];
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_operation_commands_do_not_commit_history_or_consume_the_lease(array $options, string $field): void
    {
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();
        $history = WorkflowHistoryEvent::query()->count();

        $this->complete($task, $this->command($options))->assertUnprocessable()->assertJsonValidationErrors('commands.0.'.$field);

        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
        $this->assertSame('leased', WorkflowTask::query()->findOrFail($task['task_id'])->status->value);
        $this->assertDatabaseCount('workflow_service_calls', 0);
    }

    public static function invalidOptions(): array
    {
        $cases = [
            [['endpoint_name' => ''], 'endpoint_name'],
            [['service_name' => []], 'service_name'],
            [['operation_name' => 42], 'operation_name'],
            [['mode_override' => 'parallel'], 'mode_override'],
            [['wait_for' => 'forever'], 'wait_for'],
            [['wait_timeout_seconds' => -1], 'wait_timeout_seconds'],
            [['labels' => 'not-an-array'], 'labels'],
            [['memo' => 'not-an-array'], 'memo'],
            [['metadata' => 'not-an-array'], 'metadata'],
            [['request_payload' => 'not-avro'], 'request_payload'],
            [['request_payload' => ['codec' => 'avro', 'blob' => 'not-avro']], 'request_payload.blob'],
            [['duplicate_start_policy' => 'replace_anything'], 'duplicate_start_policy'],
            [['caller_namespace' => 'other-tenant'], 'caller_namespace'],
        ];
        foreach (['principal_subject' => 'admin', 'principal_method' => 'system', 'principal_roles' => ['admin'],
            'principal_tenant' => 'other-tenant', 'principal_claims' => ['admin' => true]] as $field => $value) {
            $cases[] = [[$field => $value], $field];
        }

        return $cases;
    }

    public function test_a_namespace_scoped_worker_cannot_target_a_different_namespace(): void
    {
        $this->createNamespace('other-tenant');
        $this->seedCatalog('other-tenant');
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();
        $history = WorkflowHistoryEvent::query()->count();

        $this->complete($task, $this->command(['namespace' => 'other-tenant']))->assertForbidden();
        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseCount('workflow_service_calls', 0);
    }

    public function test_an_unscoped_worker_can_use_an_authorized_cross_namespace_operation(): void
    {
        $this->createNamespace('other-tenant');
        $this->seedCatalog('other-tenant', ['caller_namespaces' => ['allow' => ['default']], 'required_roles' => ['worker']]);
        $task = $this->leaseWorkflow();
        $this->authenticateWorker(null);

        $this->complete($task, $this->command(['namespace' => 'other-tenant']))->assertOk();
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame('other-tenant', $call->target_namespace);
        $this->assertSame('default', $call->caller_namespace);
        $this->assertSame('started', $call->status);
        $this->assertSame('other-tenant', WorkflowRun::query()->findOrFail($call->linked_workflow_run_id)->namespace);
    }

    public function test_catalog_role_policy_receives_the_authenticated_worker_identity(): void
    {
        $this->seedCatalog(policy: ['required_roles' => ['admin']]);
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();

        $this->complete($task, $this->command())->assertOk();
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame('rejected_forbidden', $call->outcome->value);
        $this->assertNull($call->linked_workflow_run_id);
        $this->assertDatabaseHas('workflow_history_events', ['workflow_run_id' => $task['run_id'], 'event_type' => 'ServiceCallFailed']);
    }

    public function test_stale_task_attempt_cannot_dispatch_a_service_operation(): void
    {
        $this->seedCatalog();
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();
        $task['workflow_task_attempt']++;

        $this->complete($task, $this->command())->assertConflict();
        $this->assertDatabaseCount('workflow_service_calls', 0);
    }

    #[DataProvider('replaySelectors')]
    public function test_reusing_an_owned_service_call_does_not_dispatch_a_second_target(string $selector): void
    {
        $this->seedCatalog();
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();
        $this->complete($task, $this->command(['idempotency_key' => 'invoice-42']))->assertOk();
        $call = WorkflowServiceCall::query()->sole();
        $task = $this->pollWorkflow();

        $this->complete($task, $this->command([$selector => $selector === 'service_call_id' ? $call->id : 'invoice-42']))->assertOk();

        $this->assertDatabaseCount('workflow_service_calls', 1);
        $this->assertDatabaseCount('workflow_runs', 2);
    }

    #[DataProvider('replaySelectors')]
    public function test_service_call_replays_cannot_reuse_another_caller_namespace(string $selector): void
    {
        $this->seedCatalog();
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();
        $this->complete($task, $this->command(['idempotency_key' => 'invoice-42']))->assertOk();
        $call = WorkflowServiceCall::query()->sole();
        $call->update(['caller_namespace' => 'other-tenant']);
        $task = $this->pollWorkflow();
        $history = WorkflowHistoryEvent::query()->count();

        $this->complete($task, $this->command([$selector => $selector === 'service_call_id' ? $call->id : 'invoice-42']))->assertForbidden();

        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
        $this->assertSame('leased', WorkflowTask::query()->findOrFail($task['task_id'])->status->value);
        $this->assertDatabaseCount('workflow_service_calls', 1);
    }

    #[DataProvider('replaySelectors')]
    public function test_service_call_replays_are_authorized_against_current_catalog_policy(string $selector): void
    {
        $operation = $this->seedCatalog();
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();
        $this->complete($task, $this->command(['idempotency_key' => 'invoice-42']))->assertOk();
        $call = WorkflowServiceCall::query()->sole();
        $operation->update(['boundary_policy' => ['required_roles' => ['admin']]]);
        $task = $this->pollWorkflow();
        $history = WorkflowHistoryEvent::query()->count();

        $this->complete($task, $this->command([$selector => $selector === 'service_call_id' ? $call->id : 'invoice-42']))->assertForbidden();

        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
        $this->assertDatabaseCount('workflow_runs', 2);
    }

    public static function replaySelectors(): array
    {
        return [['service_call_id'], ['idempotency_key']];
    }

    #[DataProvider('replaySelectors')]
    public function test_service_call_replays_cannot_reuse_another_workflow_run(string $selector): void
    {
        $this->seedCatalog();
        $task = $this->leaseWorkflow();
        $this->authenticateWorker();
        $this->complete($task, $this->command(['idempotency_key' => 'invoice-42']))->assertOk();
        $call = WorkflowServiceCall::query()->sole();
        $call->update(['caller_workflow_run_id' => $call->linked_workflow_run_id]);
        $task = $this->pollWorkflow();
        $history = WorkflowHistoryEvent::query()->count();

        $this->complete($task, $this->command([$selector => $selector === 'service_call_id' ? $call->id : 'invoice-42']))->assertForbidden();

        $this->assertSame($history, WorkflowHistoryEvent::query()->count());
        $this->assertSame('leased', WorkflowTask::query()->findOrFail($task['task_id'])->status->value);
        $this->assertDatabaseCount('workflow_service_calls', 1);
    }

    private function authenticateWorker(?string $tenant = 'default'): void
    {
        config([
            'server.auth.driver' => 'token', 'server.auth.backward_compatible' => false,
            'server.auth.principal_tokens' => json_encode([[
                'token' => 'nexus-test-token', 'subject' => 'nexus-test-principal',
                'roles' => ['worker'], 'tenant' => $tenant,
            ]]),
        ]);
        $this->withHeader('Authorization', 'Bearer nexus-test-token');
    }

    private function command(array $options = []): array
    {
        return $options + [
            'type' => 'start_service_operation',
            'endpoint_name' => 'billing',
            'service_name' => 'invoicing',
            'operation_name' => 'createinvoice',
            'request_payload' => Avro::envelope([['invoice' => 42]]),
        ];
    }

    private function complete(array $task, array $command): TestResponse
    {
        return $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [$command],
        ], $this->workerHeaders());
    }

    private function leaseWorkflow(): array
    {
        $this->postJson('/api/workflows', [
            'workflow_id' => 'nexus-caller', 'workflow_type' => 'tests.nexus-caller',
            'task_queue' => 'nexus', 'input' => [],
        ], $this->apiHeaders())->assertCreated();

        return $this->pollWorkflow();
    }

    private function pollWorkflow(): array
    {
        return $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'nexus-worker', 'task_queue' => 'nexus',
        ], $this->workerHeaders())->assertOk()->assertJsonPath('poll_status', 'leased')->json('task');
    }

    private function seedCatalog(string $namespace = 'default', array $policy = []): WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => $namespace, 'endpoint_name' => 'billing']);
        $service = WorkflowService::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id, 'namespace' => $namespace, 'service_name' => 'invoicing',
        ]);

        return WorkflowServiceOperation::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id, 'workflow_service_id' => $service->id,
            'namespace' => $namespace, 'operation_name' => 'createinvoice', 'operation_mode' => 'async',
            'handler_binding_kind' => 'start_workflow', 'handler_target_reference' => 'tests.nexus-target',
            'handler_binding' => ['workflow_type' => 'tests.nexus-target', 'queue' => 'invoices'], 'boundary_policy' => $policy,
        ]);
    }
}
