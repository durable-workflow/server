<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\InteractiveCommandWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowCommand;

class BridgeAdapterControllerTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->configureWorkflowTypes([
            'tests.interactive-command-workflow' => InteractiveCommandWorkflow::class,
        ]);
    }

    public function test_webhook_bridge_starts_workflow_and_dedupes_by_provider_event(): void
    {
        Queue::fake();

        $payload = [
            'action' => 'start_workflow',
            'idempotency_key' => 'stripe-event-1001',
            'target' => [
                'workflow_type' => 'tests.interactive-command-workflow',
                'task_queue' => 'external-workflows',
                'business_key' => 'invoice-1001',
            ],
            'correlation' => [
                'provider' => 'stripe',
                'event_type' => 'invoice.paid',
            ],
        ];

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/stripe', $payload);

        $start->assertStatus(202)
            ->assertJsonPath('schema', 'durable-workflow.v2.bridge-adapter-outcome.contract')
            ->assertJsonPath('version', 1)
            ->assertJsonPath('adapter', 'stripe')
            ->assertJsonPath('action', 'start_workflow')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('idempotency_key', 'stripe-event-1001')
            ->assertJsonPath('target.workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('target.task_queue', 'external-workflows')
            ->assertJsonPath('target.business_key', 'invoice-1001')
            ->assertJsonPath('workflow_type', 'tests.interactive-command-workflow')
            ->assertJsonPath('control_plane_outcome', 'started_new')
            ->assertJsonMissingPath('raw_payload');

        $workflowId = (string) $start->json('workflow_id');

        $this->assertStringStartsWith('bridge-stripe-', $workflowId);

        $duplicate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/stripe', $payload);

        $duplicate->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'duplicate')
            ->assertJsonPath('reason', 'duplicate_start')
            ->assertJsonPath('workflow_id', $workflowId)
            ->assertJsonPath('control_plane_outcome', 'returned_existing_active');
    }

    public function test_webhook_bridge_signals_workflow_with_idempotency_context(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-signal',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $this->runReadyWorkflowTask((string) $start->json('run_id'));

        $signal = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/shopify', [
                'action' => 'signal_workflow',
                'idempotency_key' => 'shopify-event-2002',
                'target' => [
                    'workflow_id' => 'wf-bridge-signal',
                    'signal_name' => 'advance',
                ],
                'input' => ['Ada'],
            ]);

        $signal->assertStatus(202)
            ->assertJsonPath('adapter', 'shopify')
            ->assertJsonPath('action', 'signal_workflow')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('workflow_id', 'wf-bridge-signal')
            ->assertJsonPath('target.signal_name', 'advance');

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-signal')
            ->where('command_type', 'signal')
            ->firstOrFail();

        $context = $command->context ?? [];

        $this->assertSame('shopify', $context['server']['metadata']['adapter'] ?? null);
        $this->assertSame('signal_workflow', $context['server']['metadata']['action'] ?? null);
        $this->assertSame('shopify-event-2002', $context['server']['metadata']['idempotency_key'] ?? null);
        $this->assertSame('shopify-event-2002', $context['server']['metadata']['request_id'] ?? null);

        $duplicate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/shopify', [
                'action' => 'signal_workflow',
                'idempotency_key' => 'shopify-event-2002',
                'target' => [
                    'workflow_id' => 'wf-bridge-signal',
                    'signal_name' => 'advance',
                ],
                'input' => ['Grace'],
            ]);

        $duplicate->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'duplicate')
            ->assertJsonPath('control_plane_outcome', 'deduped_existing_command')
            ->assertJsonPath('workflow_id', 'wf-bridge-signal');

        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-signal')
            ->where('command_type', 'signal')
            ->count());
    }

    public function test_webhook_bridge_dedupes_update_commands_by_provider_event(): void
    {
        Queue::fake();

        $start = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/workflows', [
                'workflow_id' => 'wf-bridge-update',
                'workflow_type' => 'tests.interactive-command-workflow',
            ]);

        $start->assertCreated();
        $this->runReadyWorkflowTask((string) $start->json('run_id'));

        $payload = [
            'action' => 'update_workflow',
            'idempotency_key' => 'pagerduty-event-3003',
            'target' => [
                'workflow_id' => 'wf-bridge-update',
                'update_name' => 'approve',
            ],
            'input' => [true, 'pagerduty'],
        ];

        $update = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/pagerduty', $payload);

        $update->assertStatus(202)
            ->assertJsonPath('adapter', 'pagerduty')
            ->assertJsonPath('action', 'update_workflow')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('workflow_id', 'wf-bridge-update')
            ->assertJsonPath('target.update_name', 'approve');

        $duplicate = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/pagerduty', [
                ...$payload,
                'input' => [false, 'duplicate'],
            ]);

        $duplicate->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('outcome', 'duplicate')
            ->assertJsonPath('control_plane_outcome', 'deduped_existing_command')
            ->assertJsonPath('workflow_id', 'wf-bridge-update');

        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-update')
            ->where('command_type', 'update')
            ->firstOrFail();

        $context = $command->context ?? [];

        $this->assertSame('pagerduty-event-3003', $context['server']['metadata']['idempotency_key'] ?? null);
        $this->assertSame('pagerduty-event-3003', $context['server']['metadata']['request_id'] ?? null);
        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'wf-bridge-update')
            ->where('command_type', 'update')
            ->count());
    }

    public function test_webhook_bridge_uses_named_rejections(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/github', [
                'action' => 'signal_workflow',
                'idempotency_key' => 'github-event-3003',
                'target' => [
                    'workflow_id' => 'wf-missing',
                    'signal_name' => 'advance',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'unknown_target');

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/bridge-adapters/webhook/github', [
                'action' => 'not_supported',
                'idempotency_key' => 'github-event-3004',
                'target' => ['workflow_id' => 'wf-missing'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('reason', 'unsupported_action')
            ->assertJsonPath('action', 'not_supported');
    }
}
