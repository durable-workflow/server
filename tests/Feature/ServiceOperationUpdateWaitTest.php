<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

/**
 * Server #775: a service operation bound to a workflow update hands its
 * wait_timeout_seconds to the update admission, so both service-operation
 * ingresses refuse a wait above the update ceiling before the operation runs.
 * Other bindings keep the service operation's own wait contract.
 */
final class ServiceOperationUpdateWaitTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'wf-update-target',
            'workflow_type' => 'tests.interactive-command-workflow',
        ])->assertCreated();
        $this->runReadyWorkflowTask((string) $start->json('run_id'));
    }

    public function test_the_execute_route_bounds_an_update_bound_operations_wait(): void
    {
        $this->seedOperation('update_workflow');
        $route = '/api/service-endpoints/billing/services/approvals/operations/approve/execute';
        $body = fn (int $seconds): array => [
            'arguments' => [true, 'service'],
            'mode_override' => 'async',
            'wait_for' => 'completed',
            'wait_timeout_seconds' => $seconds,
        ];

        $this->withHeaders($this->apiHeaders())->postJson($route, $body(61))
            ->assertStatus(422)
            ->assertJsonPath('errors.wait_timeout_seconds.0', 'The wait timeout seconds field must not be greater than 60.');
        $this->assertNothingAdmitted();

        $this->withHeaders($this->apiHeaders())->postJson($route, [...$body(60), 'wait_for' => 'accepted'])
            ->assertSuccessful()
            ->assertJsonPath('accepted', true);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        $this->assertSame(1, $this->targetUpdates());
    }

    public function test_the_workflow_task_command_bounds_an_update_bound_operations_wait(): void
    {
        $this->seedOperation('update_workflow');
        $this->registerWorker('caller-worker', 'callers', supportedWorkflowTypes: ['tests.service-caller']);
        $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'wf-service-caller', 'workflow_type' => 'tests.service-caller',
            'task_queue' => 'callers', 'input' => [],
        ])->assertCreated();
        $task = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'caller-worker', 'task_queue' => 'callers',
        ], $this->workerHeaders())->assertOk()->json('task');
        $this->authenticateWorker();

        $refused = $this->complete($task, 61)->assertStatus(422);
        $this->assertSame(['The commands.0.wait_timeout_seconds field must not be greater than 60.'],
            $refused->json('errors')['commands.0.wait_timeout_seconds'] ?? null);
        $this->assertNothingAdmitted();

        $this->complete($task, 60)->assertOk();
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        // The update runs when the scheduled effect does, after commit.
        $this->assertSame(1, $this->runScheduledServiceCalls());
        $this->assertSame(1, $this->targetUpdates());
    }

    public function test_every_binding_takes_the_service_operation_wait_ceiling(): void
    {
        // Workflow 2.14 bounds every service-operation wait at 60 seconds, so
        // a start binding is refused above it before anything is admitted.
        $this->seedOperation('start_workflow');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/approvals/operations/approve/execute', [
                'arguments' => Avro::envelope(['approved' => true, 'source' => 'service']),
                'mode_override' => 'async',
                'wait_for' => 'accepted',
                'wait_timeout_seconds' => 61,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.wait_timeout_seconds.0', 'The wait timeout seconds field must not be greater than 60.');
        $this->assertSame(0, WorkflowServiceCall::query()->count());

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/approvals/operations/approve/execute', [
                'arguments' => Avro::envelope(['approved' => true, 'source' => 'service']),
                'mode_override' => 'async',
                'wait_for' => 'accepted',
                'wait_timeout_seconds' => 60,
            ])
            ->assertSuccessful();
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    public function test_a_binding_changed_after_the_check_still_cannot_carry_a_longer_wait(): void
    {
        // The catalog says start_workflow when the route checks, and a
        // concurrent change makes it a workflow update before the service
        // control plane resolves it. The update admission refuses the wait.
        $this->seedOperation('start_workflow');
        $this->changeBindingBeforeExecution();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/approvals/operations/approve/execute', [
                'arguments' => [true, 'service'],
                'mode_override' => 'async',
                'wait_for' => 'completed',
                'wait_timeout_seconds' => 61,
            ]);
        $this->assertSame(0, $this->targetUpdates());

        // At the ceiling the same race admits the update.
        WorkflowServiceOperation::query()->get()->each(fn (WorkflowServiceOperation $operation) => $operation->forceFill([
            'handler_binding_kind' => 'start_workflow', 'handler_target_reference' => 'tests.interactive-command-workflow',
        ])->save());
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/service-endpoints/billing/services/approvals/operations/approve/execute', [
                'arguments' => [true, 'service'],
                'mode_override' => 'async',
                'wait_for' => 'accepted',
                'wait_timeout_seconds' => 60,
            ])
            ->assertSuccessful();
        $this->assertSame(1, $this->targetUpdates());
        WorkflowCommand::query()->where('workflow_instance_id', 'wf-update-target')->where('command_type', 'update')->delete();

        $this->registerWorker('caller-worker', 'callers', supportedWorkflowTypes: ['tests.service-caller']);
        $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'wf-service-caller', 'workflow_type' => 'tests.service-caller',
            'task_queue' => 'callers', 'input' => [],
        ])->assertCreated();
        $task = $this->postJson('/api/worker/workflow-tasks/poll', [
            'worker_id' => 'caller-worker', 'task_queue' => 'callers',
        ], $this->workerHeaders())->assertOk()->json('task');
        WorkflowServiceOperation::query()->get()->each(fn (WorkflowServiceOperation $operation) => $operation->forceFill([
            'handler_binding_kind' => 'start_workflow', 'handler_target_reference' => 'tests.interactive-command-workflow',
        ])->save());
        $this->authenticateWorker();
        $updateEvents = fn (): int => WorkflowHistoryEvent::query()->whereIn('event_type', ['UpdateAccepted', 'UpdateRejected'])->count();
        $before = $updateEvents();
        $this->complete($task, 61);

        $this->assertSame(0, $this->targetUpdates());
        $this->assertSame($before, $updateEvents());

    }

    /**
     * Rebinds the service control plane so each execution first turns the
     * catalog operation into a workflow update binding, as a concurrent
     * catalog change would after the server's own check.
     */
    private function changeBindingBeforeExecution(): void
    {
        $inner = $this->app->make(ServiceControlPlane::class);
        $this->app->instance(ServiceControlPlane::class, new class($inner) implements ServiceControlPlane
        {
            public function __construct(private readonly ServiceControlPlane $inner) {}

            public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array
            {
                WorkflowServiceOperation::query()->get()->each(fn (WorkflowServiceOperation $operation) => $operation->forceFill([
                    'handler_binding_kind' => 'update_workflow',
                    'handler_target_reference' => 'approve',
                    'handler_binding' => ['workflow_instance_id' => 'wf-update-target', 'update_name' => 'approve'],
                ])->save());

                return $this->inner->execute($endpointName, $serviceName, $operationName, $options);
            }

            public function describeCall(string $serviceCallId, array $options = []): array
            {
                return $this->inner->describeCall($serviceCallId, $options);
            }

            public function cancelCall(string $serviceCallId, array $options = []): array
            {
                return $this->inner->cancelCall($serviceCallId, $options);
            }
        });
    }

    private function assertNothingAdmitted(): void
    {
        $this->assertSame(0, WorkflowServiceCall::query()->count());
        $this->assertSame(0, $this->targetUpdates());
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->whereIn('event_type', ['UpdateAccepted', 'UpdateRejected', 'ServiceCallStarted'])
            ->count());
    }

    private function targetUpdates(): int
    {
        return WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-update-target')
            ->where('command_type', 'update')
            ->count();
    }

    private function seedOperation(string $binding): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => 'default', 'endpoint_name' => 'billing']);
        $service = WorkflowService::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id, 'namespace' => 'default', 'service_name' => 'approvals',
        ]);
        WorkflowServiceOperation::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id, 'workflow_service_id' => $service->id,
            'namespace' => 'default', 'operation_name' => 'approve', 'operation_mode' => 'async',
            'handler_binding_kind' => $binding,
            'handler_target_reference' => $binding === 'update_workflow' ? 'approve' : 'tests.interactive-command-workflow',
            'handler_binding' => $binding === 'update_workflow'
                ? ['workflow_instance_id' => 'wf-update-target', 'update_name' => 'approve']
                : ['workflow_type' => 'tests.interactive-command-workflow', 'queue' => 'targets'],
        ]);
    }

    private function complete(array $task, int $seconds)
    {
        return $this->postJson('/api/worker/workflow-tasks/'.$task['task_id'].'/complete', [
            'lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'],
            'commands' => [[
                'type' => 'start_service_operation',
                'endpoint_name' => 'billing',
                'service_name' => 'approvals',
                'operation_name' => 'approve',
                'request_payload' => Avro::envelope(['approved' => true, 'source' => 'service']),
                'mode_override' => 'async',
                'wait_for' => 'accepted',
                'wait_timeout_seconds' => $seconds,
            ]],
        ], $this->workerHeaders());
    }

    private function authenticateWorker(): void
    {
        config([
            'server.auth.driver' => 'token', 'server.auth.backward_compatible' => false,
            'server.auth.principal_tokens' => json_encode([[
                'token' => 'update-wait-token', 'subject' => 'update-wait-principal',
                'roles' => ['worker'], 'tenant' => 'default',
            ]]),
        ]);
        $this->withHeader('Authorization', 'Bearer update-wait-token');
    }
}
