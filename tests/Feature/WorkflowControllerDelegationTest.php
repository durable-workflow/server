<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

class WorkflowControllerDelegationTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    public function test_describe_endpoint_passes_namespace_to_the_gateway(): void
    {
        $start = app(WorkflowControlPlane::class)->start('remote-workflow-type', 'wf-describe-delegate', [
            'connection' => 'redis',
            'queue' => 'default',
            'namespace' => 'default',
        ]);

        $this->assertNotNull($start['workflow_run_id']);
        $runId = (string) $start['workflow_run_id'];

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock) use ($run): void {
            $mock->shouldReceive('describe')
                ->once()
                ->withArgs(static function (string $workflowId, array $options): bool {
                    return $workflowId === 'wf-describe-delegate'
                        && ($options['namespace'] ?? null) === 'default';
                })
                ->andReturn([
                    'found' => true,
                    'workflow_instance_id' => 'wf-describe-delegate',
                    'workflow_type' => 'remote-workflow-type',
                    'workflow_class' => 'remote-workflow-type',
                    'namespace' => 'default',
                    'business_key' => null,
                    'execution_timeout_seconds' => null,
                    'run' => [
                        'workflow_run_id' => $run->id,
                        'run_number' => 1,
                        'is_current_run' => true,
                        'status' => $run->status->value,
                        'status_bucket' => $run->status->statusBucket()->value,
                        'closed_reason' => null,
                        'compatibility' => $run->compatibility,
                        'connection' => $run->connection,
                        'queue' => $run->queue,
                        'run_timeout_seconds' => null,
                        'execution_deadline_at' => null,
                        'run_deadline_at' => null,
                        'started_at' => $run->started_at?->toJSON(),
                        'closed_at' => null,
                        'last_progress_at' => $run->last_progress_at?->toJSON(),
                        'wait_kind' => null,
                        'wait_reason' => null,
                    ],
                    'run_count' => 1,
                    'actions' => [
                        'can_signal' => true,
                        'can_query' => false,
                        'can_update' => false,
                        'can_cancel' => true,
                        'can_terminate' => true,
                        'can_repair' => true,
                        'can_archive' => false,
                    ],
                    'reason' => null,
                ]);
        });

        $this->withHeaders($this->apiHeaders())
            ->getJson('/api/workflows/wf-describe-delegate')
            ->assertOk()
            ->assertJsonPath('workflow_id', 'wf-describe-delegate')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('run_id', $run->id);
    }

    public function test_control_plane_command_endpoints_delegate_to_the_gateway(): void
    {
        WorkflowInstance::query()->create([
            'id' => 'wf-delegate',
            'workflow_class' => 'Tests\\Fixtures\\InteractiveCommandWorkflow',
            'workflow_type' => 'tests.interactive-command-workflow',
            'namespace' => 'default',
            'run_count' => 0,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('signal')
                ->once()
                ->withArgs(function (
                    string $workflowId,
                    string $signalName,
                    array $options,
                ): bool {
                    $commandContext = $options['command_context'] ?? null;
                    $attributes = $commandContext->attributes();
                    $context = $attributes['context'] ?? [];

                    return $workflowId === 'wf-delegate'
                        && $signalName === 'advance'
                        && ($attributes['source'] ?? null) === 'control_plane'
                        && (($context['request']['path'] ?? null) === '/api/workflows/wf-delegate/signal/advance')
                        && (($context['server']['command'] ?? null) === 'signal')
                        && (($context['server']['metadata']['request_id'] ?? null) === 'signal-request-1')
                        && (($context['server']['metadata']['signal_name'] ?? null) === 'advance')
                        && ($options['namespace'] ?? null) === 'default'
                        && ($options['arguments'] ?? null) === ['Ada']
                        && ($options['strict_configured_type_validation'] ?? null) === true;
                })
                ->andReturn([
                    'accepted' => true,
                    'workflow_instance_id' => 'wf-delegate',
                    'workflow_command_id' => 'pkg-signal-command-id',
                    'command_status' => 'accepted',
                    'command_source' => 'control_plane',
                    'outcome' => 'signal_received',
                    'command_reason' => 'accepted_for_delivery',
                    'status' => 202,
                ]);

            $mock->shouldReceive('query')
                ->once()
                ->withArgs(function (
                    string $workflowId,
                    string $queryName,
                    array $options,
                ): bool {
                    $commandContext = $options['command_context'] ?? null;
                    $attributes = $commandContext->attributes();
                    $context = $attributes['context'] ?? [];

                    return $workflowId === 'wf-delegate'
                        && $queryName === 'currentState'
                        && ($options['arguments'] ?? null) === ['field' => 'value']
                        && ($attributes['source'] ?? null) === 'control_plane'
                        && (($context['request']['path'] ?? null) === '/api/workflows/wf-delegate/query/currentState')
                        && (($context['server']['command'] ?? null) === 'query')
                        && (($context['server']['metadata']['query_name'] ?? null) === 'currentState')
                        && ($options['namespace'] ?? null) === 'default'
                        && ($options['strict_configured_type_validation'] ?? null) === true;
                })
                ->andReturn([
                    'success' => true,
                    'workflow_instance_id' => 'wf-delegate',
                    'target_scope' => 'instance',
                    'result' => ['stage' => 'waiting'],
                    'status' => 200,
                ]);

            $mock->shouldReceive('update')
                ->once()
                ->withArgs(function (
                    string $workflowId,
                    string $updateName,
                    array $options,
                ): bool {
                    $commandContext = $options['command_context'] ?? null;
                    $attributes = $commandContext->attributes();
                    $context = $attributes['context'] ?? [];

                    return $workflowId === 'wf-delegate'
                        && $updateName === 'approve'
                        && ($attributes['source'] ?? null) === 'control_plane'
                        && (($context['request']['path'] ?? null) === '/api/workflows/wf-delegate/update/approve')
                        && (($context['server']['command'] ?? null) === 'update')
                        && (($context['server']['metadata']['request_id'] ?? null) === 'update-request-1')
                        && (($context['server']['metadata']['update_name'] ?? null) === 'approve')
                        && (($context['server']['metadata']['wait_for'] ?? null) === 'completed')
                        && ($options['namespace'] ?? null) === 'default'
                        && ($options['arguments'] ?? null) === [true]
                        && ($options['wait_for'] ?? null) === 'completed'
                        && ($options['strict_configured_type_validation'] ?? null) === true;
                })
                ->andReturn([
                    'accepted' => true,
                    'workflow_instance_id' => 'wf-delegate',
                    'command_status' => 'accepted',
                    'update_status' => 'completed',
                    'update_id' => 'pkg-update-id',
                    'outcome' => 'update_completed',
                    'command_reason' => 'accepted_for_completion_wait',
                    'status' => 200,
                ]);

            $mock->shouldReceive('cancel')
                ->once()
                ->withArgs(function (
                    string $workflowId,
                    array $options,
                ): bool {
                    $commandContext = $options['command_context'] ?? null;
                    $attributes = $commandContext->attributes();
                    $context = $attributes['context'] ?? [];

                    return $workflowId === 'wf-delegate'
                        && ($attributes['source'] ?? null) === 'control_plane'
                        && (($context['request']['path'] ?? null) === '/api/workflows/wf-delegate/cancel')
                        && (($context['server']['command'] ?? null) === 'cancel')
                        && (($context['server']['metadata']['request_id'] ?? null) === 'cancel-request-1')
                        && (($context['server']['metadata']['reason'] ?? null) === 'operator cancel')
                        && ($options['namespace'] ?? null) === 'default'
                        && ($options['reason'] ?? null) === 'operator cancel'
                        && ($options['strict_configured_type_validation'] ?? null) === true;
                })
                ->andReturn([
                    'accepted' => true,
                    'workflow_instance_id' => 'wf-delegate',
                    'workflow_command_id' => 'pkg-cancel-command-id',
                    'command_status' => 'accepted',
                    'outcome' => 'cancelled',
                    'command_reason' => 'cancel_requested',
                    'status' => 200,
                ]);

            $mock->shouldReceive('terminate')
                ->once()
                ->withArgs(function (
                    string $workflowId,
                    array $options,
                ): bool {
                    $commandContext = $options['command_context'] ?? null;
                    $attributes = $commandContext->attributes();
                    $context = $attributes['context'] ?? [];

                    return $workflowId === 'wf-delegate'
                        && ($attributes['source'] ?? null) === 'control_plane'
                        && (($context['request']['path'] ?? null) === '/api/workflows/wf-delegate/terminate')
                        && (($context['server']['command'] ?? null) === 'terminate')
                        && (($context['server']['metadata']['request_id'] ?? null) === 'terminate-request-1')
                        && (($context['server']['metadata']['reason'] ?? null) === 'operator terminate')
                        && ($options['namespace'] ?? null) === 'default'
                        && ($options['reason'] ?? null) === 'operator terminate'
                        && ($options['strict_configured_type_validation'] ?? null) === true;
                })
                ->andReturn([
                    'accepted' => true,
                    'workflow_instance_id' => 'wf-delegate',
                    'workflow_command_id' => 'pkg-terminate-command-id',
                    'command_status' => 'accepted',
                    'outcome' => 'terminated',
                    'command_reason' => 'terminate_requested',
                    'status' => 200,
                ]);

            $mock->shouldReceive('describe')->never();
        });

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-delegate/signal/advance', [
                'input' => ['Ada'],
                'request_id' => 'signal-request-1',
            ]);

        $signal->assertStatus(202)
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-delegate')
            ->assertJsonPath('signal_name', 'advance')
            ->assertJsonPath('outcome', 'signal_received')
            ->assertJsonPath('control_plane.schema', 'durable-workflow.v2.control-plane-response')
            ->assertJsonPath('control_plane.version', 1)
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'advance')
            ->assertJsonPath('control_plane.workflow_id', 'wf-delegate')
            ->assertJsonPath('reason', 'accepted_for_delivery');
        $signal->assertJsonMissingPath('accepted');
        $signal->assertJsonMissingPath('workflow_instance_id');
        $signal->assertJsonMissingPath('workflow_command_id');
        $signal->assertJsonMissingPath('command_reason');

        $query = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-delegate/query/currentState', [
                'input' => ['field' => 'value'],
            ]);

        $query->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-delegate')
            ->assertJsonPath('query_name', 'currentState')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'currentState')
            ->assertJsonPath('control_plane.result.stage', 'waiting')
            ->assertJsonPath('result.stage', 'waiting');
        $query->assertJsonMissingPath('success');
        $query->assertJsonMissingPath('workflow_instance_id');

        $update = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-delegate/update/approve', [
                'input' => [true],
                'request_id' => 'update-request-1',
                'wait_for' => 'completed',
            ]);

        $update->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-delegate')
            ->assertJsonPath('update_name', 'approve')
            ->assertJsonPath('control_plane.operation', 'update')
            ->assertJsonPath('control_plane.operation_name', 'approve')
            ->assertJsonPath('control_plane.wait_for', 'completed')
            ->assertJsonPath('update_status', 'completed')
            ->assertJsonPath('wait_for', 'completed')
            ->assertJsonPath('outcome', 'update_completed')
            ->assertJsonPath('reason', 'accepted_for_completion_wait');
        $update->assertJsonMissingPath('accepted');
        $update->assertJsonMissingPath('workflow_instance_id');
        $update->assertJsonMissingPath('command_reason');

        $cancel = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-delegate/cancel', [
                'reason' => 'operator cancel',
                'request_id' => 'cancel-request-1',
            ]);

        $cancel->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-delegate')
            ->assertJsonPath('control_plane.operation', 'cancel')
            ->assertJsonPath('control_plane.workflow_id', 'wf-delegate')
            ->assertJsonPath('outcome', 'cancelled')
            ->assertJsonPath('reason', 'cancel_requested');
        $cancel->assertJsonMissingPath('accepted');
        $cancel->assertJsonMissingPath('workflow_instance_id');
        $cancel->assertJsonMissingPath('workflow_command_id');
        $cancel->assertJsonMissingPath('command_reason');

        $terminate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-delegate/terminate', [
                'reason' => 'operator terminate',
                'request_id' => 'terminate-request-1',
            ]);

        $terminate->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-delegate')
            ->assertJsonPath('control_plane.operation', 'terminate')
            ->assertJsonPath('control_plane.workflow_id', 'wf-delegate')
            ->assertJsonPath('outcome', 'terminated')
            ->assertJsonPath('reason', 'terminate_requested');
        $terminate->assertJsonMissingPath('accepted');
        $terminate->assertJsonMissingPath('workflow_instance_id');
        $terminate->assertJsonMissingPath('workflow_command_id');
        $terminate->assertJsonMissingPath('command_reason');
    }

    public function test_cancel_noop_success_keeps_gateway_status(): void
    {
        WorkflowInstance::query()->create([
            'id' => 'wf-delegate-cancel-noop',
            'workflow_class' => 'Tests\\Fixtures\\InteractiveCommandWorkflow',
            'workflow_type' => 'tests.interactive-command-workflow',
            'namespace' => 'default',
            'run_count' => 0,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $mock->shouldReceive('cancel')
                ->once()
                ->withArgs(function (
                    string $workflowId,
                    array $options,
                ): bool {
                    $commandContext = $options['command_context'] ?? null;
                    $attributes = $commandContext->attributes();
                    $context = $attributes['context'] ?? [];

                    return $workflowId === 'wf-delegate-cancel-noop'
                        && ($attributes['source'] ?? null) === 'control_plane'
                        && (($context['request']['path'] ?? null) === '/api/workflows/wf-delegate-cancel-noop/cancel')
                        && (($context['server']['command'] ?? null) === 'cancel')
                        && ($options['namespace'] ?? null) === 'default'
                        && ($options['strict_configured_type_validation'] ?? null) === true;
                })
                ->andReturn([
                    'accepted' => true,
                    'workflow_instance_id' => 'wf-delegate-cancel-noop',
                    'command_status' => 'accepted',
                    'outcome' => 'cancel_not_needed',
                    'command_reason' => 'already_terminal',
                    'status' => 200,
                ]);
        });

        $cancel = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-delegate-cancel-noop/cancel');

        $cancel->assertOk()
            ->assertHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->assertJsonPath('workflow_id', 'wf-delegate-cancel-noop')
            ->assertJsonPath('control_plane.operation', 'cancel')
            ->assertJsonPath('outcome', 'cancel_not_needed')
            ->assertJsonPath('reason', 'already_terminal');
        $cancel->assertJsonMissingPath('accepted');
        $cancel->assertJsonMissingPath('workflow_command_id');
        $cancel->assertJsonMissingPath('command_reason');
    }

    public function test_control_plane_command_endpoints_return_not_found_when_the_gateway_cannot_load_the_workflow(): void
    {
        WorkflowInstance::query()->create([
            'id' => 'wf-missing',
            'workflow_class' => 'Tests\\Fixtures\\InteractiveCommandWorkflow',
            'workflow_type' => 'tests.interactive-command-workflow',
            'namespace' => 'default',
            'run_count' => 0,
        ]);

        $this->mock(WorkflowControlPlane::class, function (MockInterface $mock): void {
            $notFound = [
                'reason' => 'instance_not_found',
                'status' => 404,
            ];

            $mock->shouldReceive('signal')->once()->andReturn($notFound);
            $mock->shouldReceive('query')->once()->andReturn($notFound);
            $mock->shouldReceive('update')->once()->andReturn($notFound);
            $mock->shouldReceive('cancel')->once()->andReturn($notFound);
            $mock->shouldReceive('terminate')->once()->andReturn($notFound);
            $mock->shouldReceive('describe')->never();
        });

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-missing/signal/advance', [])
            ->assertNotFound()
            ->assertJsonPath('message', 'Workflow not found.')
            ->assertJsonPath('control_plane.operation', 'signal')
            ->assertJsonPath('control_plane.operation_name', 'advance')
            ->assertJsonPath('control_plane.workflow_id', 'wf-missing');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-missing/query/currentState', [])
            ->assertNotFound()
            ->assertJsonPath('message', 'Workflow not found.')
            ->assertJsonPath('control_plane.operation', 'query')
            ->assertJsonPath('control_plane.operation_name', 'currentState')
            ->assertJsonPath('control_plane.workflow_id', 'wf-missing');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-missing/update/approve', [])
            ->assertNotFound()
            ->assertJsonPath('message', 'Workflow not found.')
            ->assertJsonPath('control_plane.operation', 'update')
            ->assertJsonPath('control_plane.operation_name', 'approve')
            ->assertJsonPath('control_plane.workflow_id', 'wf-missing');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-missing/cancel', [])
            ->assertNotFound()
            ->assertJsonPath('message', 'Workflow not found.')
            ->assertJsonPath('control_plane.operation', 'cancel')
            ->assertJsonPath('control_plane.workflow_id', 'wf-missing');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows/wf-missing/terminate', [])
            ->assertNotFound()
            ->assertJsonPath('message', 'Workflow not found.')
            ->assertJsonPath('control_plane.operation', 'terminate')
            ->assertJsonPath('control_plane.workflow_id', 'wf-missing');
    }

    private function apiHeaders(): array
    {
        return [
            'X-Namespace' => 'default',
            'X-Durable-Workflow-Control-Plane-Version' => '2',
        ];
    }
}
